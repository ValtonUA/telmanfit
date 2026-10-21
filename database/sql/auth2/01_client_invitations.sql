CREATE TABLE client_invitations (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 token_hash text NOT NULL UNIQUE CHECK(token_hash ~ '^[0-9a-f]{64}$'),
 created_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 coach_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 target_client_id integer REFERENCES clients(id) ON DELETE RESTRICT,
 created_at timestamptz NOT NULL DEFAULT now(),
 expires_at timestamptz NOT NULL,
 revoked_at timestamptz,
 revoked_by_user_id bigint REFERENCES users(id) ON DELETE RESTRICT,
 consumed_at timestamptz,
 consumed_by_user_id bigint REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(expires_at > created_at),
 CHECK((consumed_at IS NULL) = (consumed_by_user_id IS NULL)),
 CHECK((revoked_at IS NULL) = (revoked_by_user_id IS NULL)),
 CHECK(consumed_at IS NULL OR (revoked_at IS NULL AND consumed_at >= created_at AND consumed_at < expires_at)),
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
