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
