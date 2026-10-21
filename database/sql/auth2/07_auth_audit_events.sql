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
