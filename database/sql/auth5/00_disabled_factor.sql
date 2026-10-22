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
