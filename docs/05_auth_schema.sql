-- AUTH-2: застосовувати після Laravel Stage 1 (G3/R1 BIGINT users + sessions).
-- Не застосовувати безпосередньо після історичного docs/02: там інший тип users.
-- Транзакцією керує викликач. Політика email/backfill та P3/P5/P6 не активуються.

-- 00_users
-- Лише additive-поля: без нормалізації, backfill чи підтвердження email.
ALTER TABLE users
 ADD COLUMN email_normalized varchar(254) COLLATE "C",
 ADD COLUMN auth_version bigint NOT NULL DEFAULT 0,
 ADD CONSTRAINT users_email_normalized_unique UNIQUE(email_normalized),
 ADD CONSTRAINT users_email_normalized_shape CHECK
  (email_normalized IS NULL OR (email IS NOT NULL AND octet_length(email_normalized) BETWEEN 3 AND 254 AND email_normalized = btrim(email_normalized))),
 ADD CONSTRAINT users_auth_version_nonnegative CHECK(auth_version >= 0);
CREATE FUNCTION auth2_guard_user_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.auth_version < OLD.auth_version THEN
  RAISE EXCEPTION 'auth_version cannot decrease' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth2_user_version BEFORE UPDATE ON users
 FOR EACH ROW EXECUTE FUNCTION auth2_guard_user_version();

-- 01_client_invitations
CREATE TABLE client_invitations (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 token_hash text NOT NULL UNIQUE CHECK(token_hash ~ '^[0-9a-f]{64}$'),
 created_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 coach_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 target_client_id integer REFERENCES clients(id) ON DELETE RESTRICT,
 created_at timestamptz NOT NULL DEFAULT now(),
 -- NULL: без автоматичного завершення до окремого рішення P3.
 expires_at timestamptz,
 revoked_at timestamptz,
 revoked_by_user_id bigint REFERENCES users(id) ON DELETE RESTRICT,
 consumed_at timestamptz,
 consumed_by_user_id bigint REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(expires_at IS NULL OR expires_at > created_at),
 CHECK((consumed_at IS NULL) = (consumed_by_user_id IS NULL)),
 CHECK((revoked_at IS NULL) = (revoked_by_user_id IS NULL)),
 CHECK(consumed_at IS NULL OR (revoked_at IS NULL AND consumed_at >= created_at AND (expires_at IS NULL OR consumed_at < expires_at))),
 CHECK(revoked_at IS NULL OR revoked_at >= created_at)
);
CREATE UNIQUE INDEX auth2_invitation_target_slot ON client_invitations(target_client_id)
 WHERE consumed_at IS NULL AND revoked_at IS NULL;
CREATE INDEX auth2_invitation_coach ON client_invitations(coach_user_id);
CREATE INDEX auth2_invitation_creator ON client_invitations(created_by_user_id);
CREATE INDEX auth2_invitation_consumer ON client_invitations(consumed_by_user_id);
CREATE INDEX auth2_invitation_revoker ON client_invitations(revoked_by_user_id);
CREATE INDEX auth2_invitation_expiry ON client_invitations(expires_at) WHERE consumed_at IS NULL AND revoked_at IS NULL;
CREATE FUNCTION auth2_guard_invitation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.consumed_at IS NOT NULL OR OLD.revoked_at IS NOT NULL OR
 (NEW.token_hash, NEW.created_by_user_id, NEW.coach_user_id, NEW.target_client_id, NEW.created_at, NEW.expires_at)
 IS DISTINCT FROM (OLD.token_hash, OLD.created_by_user_id, OLD.coach_user_id, OLD.target_client_id, OLD.created_at, OLD.expires_at) THEN
  RAISE EXCEPTION 'Invitation identity/terminal state is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth2_invitation_transition BEFORE UPDATE ON client_invitations
 FOR EACH ROW EXECUTE FUNCTION auth2_guard_invitation();

-- 02_auth_flows
CREATE TABLE auth_flows (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 public_id uuid NOT NULL UNIQUE,
 browser_binding_hash text NOT NULL CHECK(browser_binding_hash ~ '^[0-9a-f]{64}$'),
 audience text NOT NULL CHECK(audience IN ('client','admin')),
 purpose text NOT NULL CHECK(purpose IN ('register','login','reauth','email_change','link_email','mfa_enroll','mfa_recovery')),
 user_id bigint REFERENCES users(id) ON DELETE RESTRICT,
 invitation_id bigint REFERENCES client_invitations(id) ON DELETE RESTRICT,
 auth_version_at_start bigint CHECK(auth_version_at_start >= 0),
 state text NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','primary_verified','completed','invalidated')),
 generation integer NOT NULL DEFAULT 1 CHECK(generation > 0),
 primary_verified_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 expires_at timestamptz NOT NULL,
 consumed_at timestamptz,
 invalidated_at timestamptz,
 capability_hash text UNIQUE CHECK(capability_hash ~ '^[0-9a-f]{64}$'),
 action text CHECK(action ~ '^[a-z][a-z0-9_.]{0,63}$'),
 session_binding_hash text CHECK(session_binding_hash ~ '^[0-9a-f]{64}$'),
 CHECK((user_id IS NULL) = (auth_version_at_start IS NULL)),
 CHECK(purpose <> 'register' OR (invitation_id IS NOT NULL AND audience='client')),
 CHECK(purpose = 'register' OR user_id IS NOT NULL),
 CHECK(purpose <> 'reauth' OR (action IS NOT NULL AND session_binding_hash IS NOT NULL)),
 CHECK(expires_at > created_at),
 CHECK((state='completed') = (consumed_at IS NOT NULL)),
 CHECK((state='invalidated') = (invalidated_at IS NOT NULL)),
 CHECK(state NOT IN ('primary_verified','completed') OR primary_verified_at IS NOT NULL),
 CHECK(primary_verified_at IS NULL OR (primary_verified_at >= created_at AND primary_verified_at < expires_at)),
 CHECK(consumed_at IS NULL OR (consumed_at >= primary_verified_at AND consumed_at < expires_at)),
 CHECK(invalidated_at IS NULL OR invalidated_at >= created_at)
);
CREATE UNIQUE INDEX auth2_flow_active_slot ON auth_flows(browser_binding_hash,audience,purpose)
 WHERE state IN ('pending','primary_verified');
CREATE INDEX auth2_flow_user ON auth_flows(user_id);
CREATE INDEX auth2_flow_invitation ON auth_flows(invitation_id);
CREATE INDEX auth2_flow_expiry ON auth_flows(expires_at) WHERE state IN ('pending','primary_verified');
CREATE FUNCTION auth2_guard_flow() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.state IN ('completed','invalidated') OR NEW.generation < OLD.generation OR
 (OLD.state='primary_verified' AND NEW.state='pending') OR
 (NEW.generation > OLD.generation AND OLD.state <> 'pending') OR
 (OLD.primary_verified_at IS NOT NULL AND NEW.primary_verified_at IS DISTINCT FROM OLD.primary_verified_at) OR
 (NEW.public_id,NEW.browser_binding_hash,NEW.audience,NEW.purpose,NEW.user_id,NEW.invitation_id,NEW.auth_version_at_start,NEW.created_at,NEW.expires_at,NEW.action,NEW.session_binding_hash)
 IS DISTINCT FROM
 (OLD.public_id,OLD.browser_binding_hash,OLD.audience,OLD.purpose,OLD.user_id,OLD.invitation_id,OLD.auth_version_at_start,OLD.created_at,OLD.expires_at,OLD.action,OLD.session_binding_hash) THEN
  RAISE EXCEPTION 'Invalid flow transition' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth2_flow_transition BEFORE UPDATE ON auth_flows FOR EACH ROW EXECUTE FUNCTION auth2_guard_flow();

-- 03_auth_challenges
-- Контекст (user/invitation/purpose/binding) є тільки в батьківському flow.
CREATE TABLE auth_challenges (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 public_id uuid NOT NULL UNIQUE,
 flow_id bigint NOT NULL REFERENCES auth_flows(id) ON DELETE RESTRICT,
 generation integer NOT NULL CHECK(generation > 0),
 email_normalized varchar(254) COLLATE "C" NOT NULL CHECK(octet_length(email_normalized) BETWEEN 3 AND 254 AND email_normalized=btrim(email_normalized)),
 code_mac text NOT NULL CHECK(code_mac ~ '^[0-9a-f]{64}$'),
 key_version text NOT NULL CHECK(key_version ~ '^[a-zA-Z0-9_.-]{1,64}$'),
 attempts integer NOT NULL DEFAULT 0 CHECK(attempts >= 0),
 max_attempts integer NOT NULL CHECK(max_attempts > 0 AND attempts <= max_attempts),
 created_at timestamptz NOT NULL DEFAULT now(),
 expires_at timestamptz NOT NULL,
 consumed_at timestamptz,
 invalidated_at timestamptz,
 UNIQUE(flow_id,generation),
 CHECK(expires_at > created_at),
 CHECK(consumed_at IS NULL OR (invalidated_at IS NULL AND consumed_at >= created_at AND consumed_at < expires_at AND attempts < max_attempts)),
 CHECK(invalidated_at IS NULL OR invalidated_at >= created_at)
);
CREATE INDEX auth2_challenge_expiry ON auth_challenges(expires_at) WHERE consumed_at IS NULL AND invalidated_at IS NULL;
CREATE INDEX auth2_challenge_email ON auth_challenges(email_normalized,created_at);
CREATE FUNCTION auth2_guard_challenge() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.consumed_at IS NOT NULL OR OLD.invalidated_at IS NOT NULL OR NEW.attempts < OLD.attempts OR
 (NEW.public_id,NEW.flow_id,NEW.generation,NEW.email_normalized,NEW.code_mac,NEW.key_version,NEW.max_attempts,NEW.created_at,NEW.expires_at)
 IS DISTINCT FROM
 (OLD.public_id,OLD.flow_id,OLD.generation,OLD.email_normalized,OLD.code_mac,OLD.key_version,OLD.max_attempts,OLD.created_at,OLD.expires_at) THEN
  RAISE EXCEPTION 'Invalid challenge transition' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth2_challenge_transition BEFORE UPDATE ON auth_challenges FOR EACH ROW EXECUTE FUNCTION auth2_guard_challenge();

-- 04_user_totp_factors
CREATE TABLE user_totp_factors (
 user_id bigint PRIMARY KEY REFERENCES users(id) ON DELETE RESTRICT,
 secret_ciphertext text,
 key_version text,
 confirmed_at timestamptz,
 pending_secret_ciphertext text,
 pending_key_version text,
 pending_expires_at timestamptz,
 last_used_step bigint CHECK(last_used_step >= 0),
 recovery_batch_id uuid,
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK((secret_ciphertext IS NULL) = (key_version IS NULL)),
 CHECK((secret_ciphertext IS NULL) = (confirmed_at IS NULL)),
 CHECK(secret_ciphertext IS NULL OR length(secret_ciphertext) >= 80),
 CHECK(key_version IS NULL OR key_version ~ '^[a-zA-Z0-9_.-]{1,64}$'),
 CHECK((pending_secret_ciphertext IS NULL) = (pending_expires_at IS NULL)),
 CHECK((pending_secret_ciphertext IS NULL) = (pending_key_version IS NULL)),
 CHECK(pending_secret_ciphertext IS NULL OR (length(pending_secret_ciphertext) >= 80 AND pending_expires_at > created_at)),
 CHECK(pending_key_version IS NULL OR pending_key_version ~ '^[a-zA-Z0-9_.-]{1,64}$'),
 CHECK(secret_ciphertext IS NOT NULL OR pending_secret_ciphertext IS NOT NULL),
 CHECK(last_used_step IS NULL OR confirmed_at IS NOT NULL),
 CHECK(recovery_batch_id IS NULL OR confirmed_at IS NOT NULL),
 CHECK(confirmed_at IS NULL OR confirmed_at >= created_at),
 CHECK(updated_at >= created_at)
);
CREATE FUNCTION auth2_guard_totp_step() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.user_id <> OLD.user_id OR (OLD.last_used_step IS NOT NULL AND
 (NEW.last_used_step IS NULL OR NEW.last_used_step < OLD.last_used_step)) THEN
  RAISE EXCEPTION 'TOTP watermark cannot decrease' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth2_totp_step BEFORE UPDATE ON user_totp_factors FOR EACH ROW EXECUTE FUNCTION auth2_guard_totp_step();

-- 05_mfa_recovery_codes
CREATE TABLE mfa_recovery_codes (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 user_id bigint NOT NULL REFERENCES user_totp_factors(user_id) ON DELETE RESTRICT,
 selector uuid NOT NULL UNIQUE,
 code_hash text NOT NULL CHECK(code_hash ~ '^\$argon2id\$v=19\$m=[0-9]+,t=[0-9]+,p=[0-9]+\$[A-Za-z0-9+/]{16,}\$[A-Za-z0-9+/]{32,}$'),
 batch_id uuid NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 used_at timestamptz,
 revoked_at timestamptz,
 CHECK(used_at IS NULL OR (revoked_at IS NULL AND used_at >= created_at)),
 CHECK(revoked_at IS NULL OR revoked_at >= created_at)
);
CREATE INDEX auth2_recovery_user_batch ON mfa_recovery_codes(user_id,batch_id);
CREATE FUNCTION auth2_guard_recovery() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.used_at IS NOT NULL OR OLD.revoked_at IS NOT NULL OR
 (NEW.user_id,NEW.selector,NEW.code_hash,NEW.batch_id,NEW.created_at)
 IS DISTINCT FROM (OLD.user_id,OLD.selector,OLD.code_hash,OLD.batch_id,OLD.created_at) THEN
  RAISE EXCEPTION 'Recovery identity/terminal state is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth2_recovery_transition BEFORE UPDATE ON mfa_recovery_codes FOR EACH ROW EXECUTE FUNCTION auth2_guard_recovery();

-- 06_auth_session_states
CREATE TABLE auth_session_states (
 session_id varchar(255) PRIMARY KEY REFERENCES sessions(id) ON DELETE CASCADE,
 user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 audience text NOT NULL CHECK(audience IN ('client','admin')),
 browser_binding_hash text NOT NULL CHECK(browser_binding_hash ~ '^[0-9a-f]{64}$'),
 auth_version bigint NOT NULL CHECK(auth_version >= 0),
 primary_method text NOT NULL CHECK(primary_method IN ('email_otp','password')),
 primary_verified_at timestamptz NOT NULL,
 mfa_verified_at timestamptz,
 mfa_method text CHECK(mfa_method IN ('totp','recovery_code')),
 reauthenticated_at timestamptz,
 absolute_expires_at timestamptz NOT NULL,
 revoked_at timestamptz,
 CHECK((mfa_verified_at IS NULL) = (mfa_method IS NULL)),
 CHECK(audience <> 'admin' OR (primary_method='password' AND mfa_verified_at IS NOT NULL)),
 CHECK(audience <> 'client' OR primary_method='email_otp'),
 CHECK(mfa_verified_at IS NULL OR mfa_verified_at >= primary_verified_at),
 CHECK(absolute_expires_at > primary_verified_at),
 CHECK(mfa_verified_at IS NULL OR mfa_verified_at < absolute_expires_at),
 CHECK(reauthenticated_at IS NULL OR (reauthenticated_at >= primary_verified_at AND reauthenticated_at < absolute_expires_at)),
 CHECK(revoked_at IS NULL OR revoked_at >= primary_verified_at)
);
CREATE INDEX auth2_session_user_audience ON auth_session_states(user_id,audience);
CREATE INDEX auth2_session_expiry ON auth_session_states(absolute_expires_at);
CREATE FUNCTION auth2_guard_session() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.revoked_at IS NOT NULL OR
 (NEW.session_id,NEW.user_id,NEW.audience,NEW.browser_binding_hash,NEW.auth_version,NEW.primary_method,NEW.primary_verified_at)
 IS DISTINCT FROM
 (OLD.session_id,OLD.user_id,OLD.audience,OLD.browser_binding_hash,OLD.auth_version,OLD.primary_method,OLD.primary_verified_at) OR
 NEW.absolute_expires_at > OLD.absolute_expires_at OR
 (OLD.mfa_verified_at IS NOT NULL AND (NEW.mfa_verified_at IS NULL OR NEW.mfa_verified_at < OLD.mfa_verified_at)) OR
 (OLD.reauthenticated_at IS NOT NULL AND (NEW.reauthenticated_at IS NULL OR NEW.reauthenticated_at < OLD.reauthenticated_at)) THEN
  RAISE EXCEPTION 'Invalid session assurance transition' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth2_session_transition BEFORE UPDATE ON auth_session_states FOR EACH ROW EXECUTE FUNCTION auth2_guard_session();

-- 07_auth_audit_events
-- Без довільного metadata JSON: whitelist структурних полів не допускає payload із секретами.
CREATE TABLE auth_audit_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 occurred_at timestamptz NOT NULL DEFAULT now(),
 actor_user_id bigint REFERENCES users(id) ON DELETE RESTRICT,
 subject_user_id bigint REFERENCES users(id) ON DELETE RESTRICT,
 event_type text NOT NULL CHECK(event_type ~ '^[a-z][a-z0-9_.]{0,63}$'),
 outcome text NOT NULL CHECK(outcome IN ('success','denied','error')),
 request_id uuid NOT NULL,
 session_fingerprint text CHECK(session_fingerprint ~ '^[0-9a-f]{64}$'),
 ip_fingerprint text CHECK(ip_fingerprint ~ '^[0-9a-f]{64}$'),
 user_agent_fingerprint text CHECK(user_agent_fingerprint ~ '^[0-9a-f]{64}$'),
 reason_code text CHECK(reason_code ~ '^[a-z][a-z0-9_.]{0,63}$'),
 case_reference uuid
);
CREATE INDEX auth2_audit_time ON auth_audit_events(occurred_at,id);
CREATE INDEX auth2_audit_actor ON auth_audit_events(actor_user_id,occurred_at);
CREATE INDEX auth2_audit_subject ON auth_audit_events(subject_user_id,occurred_at);
CREATE INDEX auth2_audit_request ON auth_audit_events(request_id);
CREATE FUNCTION auth2_guard_audit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Security audit is append-only; retention requires a separately approved procedure' USING ERRCODE='23514';
END $$;
CREATE TRIGGER auth2_audit_immutable BEFORE UPDATE OR DELETE ON auth_audit_events FOR EACH ROW EXECUTE FUNCTION auth2_guard_audit();
CREATE TRIGGER auth2_audit_no_truncate BEFORE TRUNCATE ON auth_audit_events FOR EACH STATEMENT EXECUTE FUNCTION auth2_guard_audit();

-- AUTH-5: additive disabled state, погоджено користувачем 22.10.2026.
-- AUTH-5: окремо погоджено 22.10.2026. Історичні AUTH-2 DDL незмінні.
ALTER TABLE user_totp_factors ADD COLUMN disabled_at timestamptz;
ALTER TABLE user_totp_factors DROP CONSTRAINT user_totp_factors_check1;
ALTER TABLE user_totp_factors DROP CONSTRAINT user_totp_factors_check5;
ALTER TABLE user_totp_factors ADD CONSTRAINT auth5_factor_secret_state CHECK
 ((secret_ciphertext IS NULL) = (confirmed_at IS NULL OR disabled_at IS NOT NULL));
ALTER TABLE user_totp_factors ADD CONSTRAINT auth5_factor_material CHECK
 (secret_ciphertext IS NOT NULL OR pending_secret_ciphertext IS NOT NULL OR disabled_at IS NOT NULL);
ALTER TABLE user_totp_factors ADD CONSTRAINT auth5_factor_disabled_state CHECK
 (disabled_at IS NULL OR (confirmed_at IS NOT NULL AND disabled_at >= confirmed_at AND updated_at >= disabled_at
  AND secret_ciphertext IS NULL AND key_version IS NULL AND recovery_batch_id IS NULL));
CREATE FUNCTION auth5_guard_factor() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.disabled_at IS NULL AND NEW.disabled_at IS NOT NULL THEN
  IF OLD.confirmed_at IS NULL OR NEW.confirmed_at IS DISTINCT FROM OLD.confirmed_at OR
   NEW.last_used_step IS DISTINCT FROM OLD.last_used_step OR NEW.pending_secret_ciphertext IS NOT NULL OR
   EXISTS(SELECT FROM mfa_recovery_codes WHERE user_id=OLD.user_id AND used_at IS NULL AND revoked_at IS NULL) THEN
   RAISE EXCEPTION 'Factor disable must preserve history and revoke recovery codes' USING ERRCODE='23514';
  END IF;
 ELSIF OLD.disabled_at IS NOT NULL AND NEW.disabled_at IS NULL THEN
  IF OLD.pending_secret_ciphertext IS NULL OR NEW.secret_ciphertext IS DISTINCT FROM OLD.pending_secret_ciphertext OR
   NEW.key_version IS DISTINCT FROM OLD.pending_key_version OR NEW.pending_secret_ciphertext IS NOT NULL OR
   NEW.confirmed_at <= OLD.confirmed_at OR NEW.last_used_step IS NULL OR
   (OLD.last_used_step IS NOT NULL AND NEW.last_used_step <= OLD.last_used_step) THEN
   RAISE EXCEPTION 'Disabled factor requires new enrollment and a newer accepted step' USING ERRCODE='23514';
  END IF;
 ELSIF OLD.disabled_at IS NOT NULL AND (NEW.disabled_at IS DISTINCT FROM OLD.disabled_at OR
  NEW.last_used_step IS DISTINCT FROM OLD.last_used_step OR NEW.confirmed_at IS DISTINCT FROM OLD.confirmed_at) THEN
  RAISE EXCEPTION 'Disabled factor history is immutable' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth5_factor_transition BEFORE UPDATE ON user_totp_factors FOR EACH ROW EXECUTE FUNCTION auth5_guard_factor();
