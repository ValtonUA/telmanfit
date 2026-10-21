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
