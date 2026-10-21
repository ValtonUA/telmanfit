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
