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
