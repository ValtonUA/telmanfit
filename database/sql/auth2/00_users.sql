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
