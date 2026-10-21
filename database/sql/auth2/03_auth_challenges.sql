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
