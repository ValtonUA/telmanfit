-- AUTH-6B/B3: новий additive recovery, без identities, ключів чи activation.
CREATE TABLE auth_recovery_principals (
 id uuid PRIMARY KEY,
 linked_user_id bigint UNIQUE REFERENCES users(id) ON DELETE RESTRICT,
 authority text NOT NULL CHECK (authority IN ('admin_approver','reviewer','trustee','operator')),
 identity_record_digest text NOT NULL CHECK (identity_record_digest ~ '^[0-9a-f]{64}$'),
 created_at timestamptz NOT NULL,
 revoked_at timestamptz CHECK (revoked_at >= created_at)
);
CREATE TABLE auth_recovery_keys (
 id uuid PRIMARY KEY,
 principal_id uuid NOT NULL REFERENCES auth_recovery_principals(id) ON DELETE RESTRICT,
 public_key bytea NOT NULL CHECK (octet_length(public_key)=32),
 fingerprint text NOT NULL UNIQUE CHECK (fingerprint ~ '^[0-9a-f]{64}$' AND fingerprint=encode(sha256(public_key),'hex')),
 registry_version bigint NOT NULL CHECK (registry_version>0),
 valid_from timestamptz NOT NULL,
 valid_until timestamptz NOT NULL CHECK (valid_until>valid_from),
 revoked_at timestamptz CHECK (revoked_at>=valid_from),
 UNIQUE (id,principal_id)
);
CREATE INDEX auth6b_key_principal ON auth_recovery_keys(principal_id);
CREATE TABLE auth_recovery_cases (
 id uuid PRIMARY KEY,
 target_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 target_principal_id uuid NOT NULL REFERENCES auth_recovery_principals(id) ON DELETE RESTRICT,
 initiator_principal_id uuid NOT NULL REFERENCES auth_recovery_principals(id) ON DELETE RESTRICT,
 operator_principal_id uuid REFERENCES auth_recovery_principals(id) ON DELETE RESTRICT,
 target_role text NOT NULL CHECK (target_role IN ('client','admin')),
 action text NOT NULL CHECK (action IN ('replace_mfa','replace_primary_mfa')),
 replace_admin_password boolean NOT NULL DEFAULT false,
 expected_auth_version bigint NOT NULL CHECK (expected_auth_version>=0),
 registry_version bigint NOT NULL CHECK (registry_version>0),
 policy_version integer NOT NULL CHECK (policy_version=1),
 deployment_id text NOT NULL CHECK (deployment_id ~ '^[a-zA-Z0-9_.-]{1,64}$'),
 restore_epoch uuid NOT NULL,
 intent_nonce uuid NOT NULL UNIQUE,
 intent_digest text NOT NULL UNIQUE CHECK (intent_digest ~ '^[0-9a-f]{64}$'),
 replacement_email_ciphertext text,
 replacement_email_mac text CHECK (replacement_email_mac ~ '^[0-9a-f]{64}$'),
 email_key_version text,
 created_at timestamptz NOT NULL,
 expires_at timestamptz NOT NULL,
 state text NOT NULL DEFAULT 'collecting' CHECK (state IN ('collecting','hold','ready','enrolling','completed','rejected','cancelled','expired')),
 hold_until timestamptz,
 closed_at timestamptz,
 reason_code text NOT NULL CHECK (reason_code IN ('lost_mfa','lost_primary','lost_all')),
 CHECK (target_principal_id<>initiator_principal_id AND target_principal_id IS DISTINCT FROM operator_principal_id),
 CHECK (NOT replace_admin_password OR target_role='admin'),
 CHECK ((action='replace_primary_mfa')=(replacement_email_ciphertext IS NOT NULL)),
 CHECK ((replacement_email_ciphertext IS NULL)=(replacement_email_mac IS NULL)),
 CHECK ((replacement_email_ciphertext IS NULL)=(email_key_version IS NULL)),
 CHECK (expires_at>created_at AND expires_at<=created_at+interval '72 hours'),
 CHECK (hold_until IS NULL OR (hold_until>=created_at+interval '24 hours' AND hold_until<expires_at)),
 CHECK (state NOT IN ('hold','ready','enrolling','completed') OR hold_until IS NOT NULL),
 CHECK ((state IN ('completed','rejected','cancelled','expired'))=(closed_at IS NOT NULL)),
 CHECK (closed_at>=created_at)
);
CREATE UNIQUE INDEX auth6b_case_active ON auth_recovery_cases(target_user_id) WHERE state IN ('collecting','hold','ready','enrolling');
CREATE INDEX auth6b_case_user ON auth_recovery_cases(target_user_id);
CREATE INDEX auth6b_case_expiry ON auth_recovery_cases(state,expires_at);
CREATE INDEX auth6b_case_target ON auth_recovery_cases(target_principal_id);
CREATE INDEX auth6b_case_initiator ON auth_recovery_cases(initiator_principal_id);
CREATE INDEX auth6b_case_operator ON auth_recovery_cases(operator_principal_id);
CREATE TABLE auth_recovery_approvals (
 case_id uuid NOT NULL REFERENCES auth_recovery_cases(id) ON DELETE RESTRICT,
 principal_id uuid NOT NULL REFERENCES auth_recovery_principals(id) ON DELETE RESTRICT,
 key_id uuid NOT NULL,
 intent_digest text NOT NULL CHECK (intent_digest ~ '^[0-9a-f]{64}$'),
 signature bytea NOT NULL CHECK (octet_length(signature)=64),
 evidence_digest text NOT NULL CHECK (evidence_digest ~ '^[0-9a-f]{64}$'),
 verification_receipt_id uuid NOT NULL UNIQUE,
 verified_at timestamptz NOT NULL,
 expires_at timestamptz NOT NULL,
 PRIMARY KEY (case_id,principal_id),
 FOREIGN KEY (key_id,principal_id) REFERENCES auth_recovery_keys(id,principal_id) ON DELETE RESTRICT,
 CHECK (expires_at>verified_at AND expires_at<=verified_at+interval '48 hours')
);
CREATE INDEX auth6b_approval_key ON auth_recovery_approvals(key_id,principal_id);
CREATE TABLE auth_recovery_grants (
 id uuid PRIMARY KEY,
 case_id uuid NOT NULL UNIQUE REFERENCES auth_recovery_cases(id) ON DELETE RESTRICT,
 generation integer NOT NULL CHECK (generation=1),
 token_hash text NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
 auth_version bigint NOT NULL CHECK (auth_version>=0),
 issued_at timestamptz NOT NULL,
 expires_at timestamptz NOT NULL,
 exchanged_at timestamptz,
 revoked_at timestamptz,
 binding_hash text CHECK (binding_hash ~ '^[0-9a-f]{64}$'),
 enrollment_flow_id bigint UNIQUE REFERENCES auth_flows(id) ON DELETE RESTRICT,
 CHECK (expires_at>issued_at AND expires_at<=issued_at+interval '15 minutes'),
 CHECK ((exchanged_at IS NULL)=(binding_hash IS NULL)),
 CHECK ((exchanged_at IS NULL)=(enrollment_flow_id IS NULL)),
 CHECK (exchanged_at IS NULL OR (revoked_at IS NULL AND exchanged_at>=issued_at AND exchanged_at<expires_at)),
 CHECK (revoked_at>=issued_at)
);
CREATE TABLE auth_recovery_gates (
 user_id bigint PRIMARY KEY REFERENCES users(id) ON DELETE RESTRICT,
 case_id uuid NOT NULL UNIQUE REFERENCES auth_recovery_cases(id) ON DELETE RESTRICT,
 blocked_at timestamptz NOT NULL,
 released_at timestamptz CHECK (released_at>=blocked_at)
);
CREATE TABLE auth_recovery_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 case_id uuid NOT NULL REFERENCES auth_recovery_cases(id) ON DELETE RESTRICT,
 event_type text NOT NULL CHECK (event_type IN ('created','approved','ready','quarantined','exchanged','primary_sent','primary_verified','enrollment_started','completed','rejected','cancelled','expired','denied')),
 actor_principal_id uuid REFERENCES auth_recovery_principals(id) ON DELETE RESTRICT,
 request_id uuid NOT NULL,
 occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 reason_code text CHECK (reason_code IN ('lost_mfa','lost_primary','lost_all','policy','invalid_proof')),
 UNIQUE (case_id,request_id,event_type)
);
CREATE INDEX auth6b_event_case ON auth_recovery_events(case_id,occurred_at,id);
CREATE INDEX auth6b_event_actor ON auth_recovery_events(actor_principal_id);

CREATE FUNCTION auth6b_immutable() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
 RAISE EXCEPTION 'Recovery history is immutable' USING ERRCODE='23514';
END $$;
CREATE FUNCTION auth6b_registry_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
 -- Зміни registry серіалізуються раніше за user locks. Enrollment/custody — D3.
 PERFORM pg_advisory_xact_lock(6060303);
 IF TG_OP='UPDATE' AND (OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL OR
  (to_jsonb(NEW)-'revoked_at') IS DISTINCT FROM (to_jsonb(OLD)-'revoked_at')) THEN
  RAISE EXCEPTION 'Registry supports only irreversible revocation' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth6b_principal_write BEFORE INSERT OR UPDATE ON auth_recovery_principals FOR EACH ROW EXECUTE FUNCTION auth6b_registry_guard();
CREATE TRIGGER auth6b_key_write BEFORE INSERT OR UPDATE ON auth_recovery_keys FOR EACH ROW EXECUTE FUNCTION auth6b_registry_guard();

CREATE FUNCTION auth6b_case_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.state<>'collecting' OR NEW.hold_until IS NOT NULL OR NEW.closed_at IS NOT NULL OR
   EXISTS(SELECT FROM public.auth_recovery_principals WHERE id IN (NEW.initiator_principal_id,NEW.operator_principal_id) AND revoked_at IS NOT NULL) OR
   NOT EXISTS (SELECT FROM public.auth_recovery_principals p JOIN public.users u ON u.id=p.linked_user_id
    WHERE p.id=NEW.target_principal_id AND u.id=NEW.target_user_id AND u.role=NEW.target_role
     AND u.auth_version=NEW.expected_auth_version AND p.revoked_at IS NULL) THEN
   RAISE EXCEPTION 'Invalid recovery identity' USING ERRCODE='23514';
  END IF;
 ELSE
  IF (to_jsonb(NEW)-ARRAY['state','hold_until','closed_at']) IS DISTINCT FROM
     (to_jsonb(OLD)-ARRAY['state','hold_until','closed_at']) OR
   OLD.state IN ('completed','rejected','cancelled','expired') OR
   (OLD.hold_until IS NOT NULL AND NEW.hold_until IS DISTINCT FROM OLD.hold_until) OR
   NOT ((OLD.state='collecting' AND NEW.state='hold') OR (OLD.state='hold' AND NEW.state='ready') OR
        (OLD.state='ready' AND NEW.state='enrolling') OR (OLD.state='enrolling' AND NEW.state='completed') OR
        NEW.state IN ('rejected','cancelled','expired')) THEN
   RAISE EXCEPTION 'Invalid recovery transition' USING ERRCODE='23514';
  END IF;
  IF NEW.state IN ('ready','enrolling','completed') THEN
   PERFORM public.auth6b_check_approvals(NEW.id);
   IF NEW.hold_until>clock_timestamp() THEN RAISE EXCEPTION 'Recovery hold' USING ERRCODE='23514'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;

-- Виклик під shared registry → sorted users → case. Сигнатуру перевіряє окремий verifier.
CREATE FUNCTION auth6b_check_approvals(cid uuid) RETURNS void LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
DECLARE c public.auth_recovery_cases; n integer;
BEGIN
 SELECT * INTO STRICT c FROM public.auth_recovery_cases WHERE id=cid;
 SELECT count(*) INTO n FROM public.auth_recovery_approvals a
 JOIN public.auth_recovery_principals p ON p.id=a.principal_id
 JOIN public.auth_recovery_keys k ON k.id=a.key_id AND k.principal_id=p.id
 LEFT JOIN public.users u ON u.id=p.linked_user_id
 WHERE a.case_id=cid AND a.intent_digest=c.intent_digest AND a.expires_at>clock_timestamp()
 AND p.revoked_at IS NULL AND k.revoked_at IS NULL AND k.valid_from<=clock_timestamp() AND k.valid_until>clock_timestamp()
 AND k.registry_version=c.registry_version
 AND p.id<>c.target_principal_id AND p.id<>c.initiator_principal_id AND p.id IS DISTINCT FROM c.operator_principal_id
 AND p.linked_user_id IS DISTINCT FROM c.target_user_id
 AND ((c.target_role='client' AND (p.authority='reviewer' OR (p.authority='admin_approver' AND u.role='admin')))
   OR (c.target_role='admin' AND (p.authority='trustee' OR (p.authority='admin_approver' AND u.role='admin'))));
 IF c.expires_at<=clock_timestamp() OR n<>2 OR EXISTS(SELECT FROM public.auth_recovery_principals WHERE id IN (c.target_principal_id,c.initiator_principal_id,c.operator_principal_id) AND revoked_at IS NOT NULL) OR
  (c.target_role='client' AND NOT EXISTS (SELECT FROM public.auth_recovery_approvals a JOIN public.auth_recovery_principals p ON p.id=a.principal_id WHERE a.case_id=cid AND p.authority='admin_approver')) THEN
  RAISE EXCEPTION 'Recovery requires two live independent approvals' USING ERRCODE='23514';
 END IF;
END $$;
CREATE TRIGGER auth6b_case_write BEFORE INSERT OR UPDATE ON auth_recovery_cases FOR EACH ROW EXECUTE FUNCTION auth6b_case_guard();

CREATE FUNCTION auth6b_approval_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
DECLARE c public.auth_recovery_cases; p public.auth_recovery_principals; k public.auth_recovery_keys;
BEGIN
 SELECT * INTO STRICT c FROM public.auth_recovery_cases WHERE id=NEW.case_id FOR UPDATE;
 SELECT * INTO STRICT p FROM public.auth_recovery_principals WHERE id=NEW.principal_id;
 SELECT * INTO STRICT k FROM public.auth_recovery_keys WHERE id=NEW.key_id AND principal_id=p.id;
 IF c.state<>'collecting' OR c.expires_at<=clock_timestamp() OR NEW.intent_digest<>c.intent_digest OR
  NEW.verified_at>clock_timestamp() OR NEW.expires_at<=clock_timestamp() OR NEW.expires_at>c.expires_at OR
  p.id=c.target_principal_id OR p.id=c.initiator_principal_id OR p.id IS NOT DISTINCT FROM c.operator_principal_id OR
  p.linked_user_id IS NOT DISTINCT FROM c.target_user_id OR p.revoked_at IS NOT NULL OR k.revoked_at IS NOT NULL OR
  k.registry_version<>c.registry_version OR k.valid_from>clock_timestamp() OR k.valid_until<NEW.expires_at OR
  (SELECT count(*) FROM public.auth_recovery_approvals WHERE case_id=c.id)>=2 OR
  NOT ((c.target_role='client' AND (p.authority='reviewer' OR (p.authority='admin_approver' AND EXISTS(SELECT FROM public.users WHERE id=p.linked_user_id AND role='admin')))) OR
       (c.target_role='admin' AND (p.authority='trustee' OR (p.authority='admin_approver' AND EXISTS(SELECT FROM public.users WHERE id=p.linked_user_id AND role='admin'))))) THEN
  RAISE EXCEPTION 'Invalid recovery approval' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth6b_approval_write BEFORE INSERT ON auth_recovery_approvals FOR EACH ROW EXECUTE FUNCTION auth6b_approval_guard();
CREATE FUNCTION auth6b_grant_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
DECLARE c public.auth_recovery_cases; f public.auth_flows;
BEGIN
 IF TG_OP='UPDATE' AND NEW.revoked_at IS NOT NULL AND OLD.revoked_at IS NULL AND OLD.exchanged_at IS NULL AND
  (to_jsonb(NEW)-'revoked_at')=(to_jsonb(OLD)-'revoked_at') THEN
  RETURN NEW;
 END IF;
 SELECT * INTO STRICT c FROM public.auth_recovery_cases WHERE id=NEW.case_id;
 PERFORM public.auth6b_check_approvals(c.id);
 IF c.state NOT IN ('ready','enrolling') OR c.hold_until>clock_timestamp() OR
  NEW.auth_version<>c.expected_auth_version+1 OR NOT EXISTS(SELECT FROM public.users WHERE id=c.target_user_id AND auth_version=NEW.auth_version) OR
  NOT EXISTS(SELECT FROM public.auth_recovery_gates WHERE user_id=c.target_user_id AND case_id=c.id AND released_at IS NULL) THEN
  RAISE EXCEPTION 'Invalid grant context' USING ERRCODE='23514';
 END IF;
 IF TG_OP='UPDATE' THEN
  IF (to_jsonb(NEW)-ARRAY['exchanged_at','revoked_at','binding_hash','enrollment_flow_id']) IS DISTINCT FROM
   (to_jsonb(OLD)-ARRAY['exchanged_at','revoked_at','binding_hash','enrollment_flow_id']) OR
   OLD.exchanged_at IS NOT NULL OR OLD.revoked_at IS NOT NULL OR
   (NEW.exchanged_at IS NULL AND NEW.revoked_at IS NULL) THEN
   RAISE EXCEPTION 'Grant is single use' USING ERRCODE='23514';
  END IF;
  IF NEW.exchanged_at IS NOT NULL THEN
   SELECT * INTO STRICT f FROM public.auth_flows WHERE id=NEW.enrollment_flow_id;
   IF f.action<>'assisted_recovery' OR f.purpose<>'mfa_enroll' OR f.state<>'pending' OR
    f.user_id<>c.target_user_id OR f.auth_version_at_start<>NEW.auth_version OR
    f.browser_binding_hash<>NEW.binding_hash OR f.expires_at>NEW.expires_at OR
    f.expires_at>f.created_at+interval '10 minutes' THEN
    RAISE EXCEPTION 'Invalid restricted enrollment flow' USING ERRCODE='23514';
   END IF;
  END IF;
 ELSIF NEW.exchanged_at IS NOT NULL OR NEW.revoked_at IS NOT NULL THEN
  RAISE EXCEPTION 'Invalid new grant' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth6b_grant_write BEFORE INSERT OR UPDATE ON auth_recovery_grants FOR EACH ROW EXECUTE FUNCTION auth6b_grant_guard();
CREATE FUNCTION auth6b_gate_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
DECLARE c public.auth_recovery_cases;
BEGIN
 SELECT * INTO STRICT c FROM public.auth_recovery_cases WHERE id=NEW.case_id;
 IF NEW.user_id<>c.target_user_id OR (TG_OP='UPDATE' AND NEW.user_id<>OLD.user_id) THEN
  RAISE EXCEPTION 'Invalid gate target' USING ERRCODE='23514';
 END IF;
 IF NEW.released_at IS NOT NULL THEN
  IF TG_OP<>'UPDATE' OR OLD.released_at IS NOT NULL OR NEW.case_id<>OLD.case_id OR NEW.blocked_at<>OLD.blocked_at OR
   c.state<>'completed' OR NOT EXISTS(SELECT FROM public.user_totp_factors WHERE user_id=NEW.user_id AND confirmed_at>=NEW.blocked_at AND disabled_at IS NULL) THEN
   RAISE EXCEPTION 'Gate requires completed recovery' USING ERRCODE='23514';
  END IF;
 ELSE
  IF c.state<>'ready' OR (TG_OP='UPDATE' AND EXISTS(SELECT FROM public.auth_recovery_cases WHERE id=OLD.case_id AND state IN ('collecting','hold','ready','enrolling'))) THEN
   RAISE EXCEPTION 'Invalid gate rebind' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth6b_gate_write BEFORE INSERT OR UPDATE ON auth_recovery_gates FOR EACH ROW EXECUTE FUNCTION auth6b_gate_guard();
CREATE TRIGGER auth_recovery_principals_immutable BEFORE DELETE ON auth_recovery_principals FOR EACH ROW EXECUTE FUNCTION auth6b_immutable();
CREATE TRIGGER auth_recovery_principals_no_truncate BEFORE TRUNCATE ON auth_recovery_principals FOR EACH STATEMENT EXECUTE FUNCTION auth6b_immutable();
REVOKE ALL ON auth_recovery_principals FROM PUBLIC;
CREATE TRIGGER auth_recovery_keys_immutable BEFORE DELETE ON auth_recovery_keys FOR EACH ROW EXECUTE FUNCTION auth6b_immutable();
CREATE TRIGGER auth_recovery_keys_no_truncate BEFORE TRUNCATE ON auth_recovery_keys FOR EACH STATEMENT EXECUTE FUNCTION auth6b_immutable();
REVOKE ALL ON auth_recovery_keys FROM PUBLIC;
CREATE TRIGGER auth_recovery_cases_immutable BEFORE DELETE ON auth_recovery_cases FOR EACH ROW EXECUTE FUNCTION auth6b_immutable();
CREATE TRIGGER auth_recovery_cases_no_truncate BEFORE TRUNCATE ON auth_recovery_cases FOR EACH STATEMENT EXECUTE FUNCTION auth6b_immutable();
REVOKE ALL ON auth_recovery_cases FROM PUBLIC;
CREATE TRIGGER auth_recovery_approvals_immutable BEFORE UPDATE OR DELETE ON auth_recovery_approvals FOR EACH ROW EXECUTE FUNCTION auth6b_immutable();
CREATE TRIGGER auth_recovery_approvals_no_truncate BEFORE TRUNCATE ON auth_recovery_approvals FOR EACH STATEMENT EXECUTE FUNCTION auth6b_immutable();
REVOKE ALL ON auth_recovery_approvals FROM PUBLIC;
CREATE TRIGGER auth_recovery_grants_immutable BEFORE DELETE ON auth_recovery_grants FOR EACH ROW EXECUTE FUNCTION auth6b_immutable();
CREATE TRIGGER auth_recovery_grants_no_truncate BEFORE TRUNCATE ON auth_recovery_grants FOR EACH STATEMENT EXECUTE FUNCTION auth6b_immutable();
REVOKE ALL ON auth_recovery_grants FROM PUBLIC;
CREATE TRIGGER auth_recovery_gates_immutable BEFORE DELETE ON auth_recovery_gates FOR EACH ROW EXECUTE FUNCTION auth6b_immutable();
CREATE TRIGGER auth_recovery_gates_no_truncate BEFORE TRUNCATE ON auth_recovery_gates FOR EACH STATEMENT EXECUTE FUNCTION auth6b_immutable();
REVOKE ALL ON auth_recovery_gates FROM PUBLIC;
CREATE TRIGGER auth_recovery_events_immutable BEFORE UPDATE OR DELETE ON auth_recovery_events FOR EACH ROW EXECUTE FUNCTION auth6b_immutable();
CREATE TRIGGER auth_recovery_events_no_truncate BEFORE TRUNCATE ON auth_recovery_events FOR EACH STATEMENT EXECUTE FUNCTION auth6b_immutable();
REVOKE ALL ON auth_recovery_events FROM PUBLIC;
-- Жодних production GRANT/role creation: див. окремий reviewed ACL template.
REVOKE ALL ON FUNCTION auth6b_immutable(), auth6b_registry_guard(), auth6b_case_guard(), auth6b_check_approvals(uuid), auth6b_approval_guard(), auth6b_grant_guard(), auth6b_gate_guard() FROM PUBLIC;

CREATE FUNCTION auth6b_lock_case(cid uuid) RETURNS auth_recovery_cases
 LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE c public.auth_recovery_cases;
BEGIN
 PERFORM pg_advisory_xact_lock_shared(6060303);
 PERFORM pg_advisory_xact_lock(4040404); -- B6 не реалізовано; чинний lock збережено.
 PERFORM u.id FROM public.users u WHERE u.id IN (
  SELECT target_user_id FROM public.auth_recovery_cases WHERE id=cid
  UNION SELECT p.linked_user_id FROM public.auth_recovery_principals p
 ) ORDER BY u.id FOR UPDATE;
 SELECT * INTO STRICT c FROM public.auth_recovery_cases WHERE id=cid FOR UPDATE;
 PERFORM 1 FROM public.auth_recovery_gates WHERE user_id=c.target_user_id FOR UPDATE;
 PERFORM 1 FROM public.auth_recovery_grants WHERE case_id=cid FOR UPDATE;
 IF NOT EXISTS(SELECT FROM public.users WHERE id=c.target_user_id AND role=c.target_role) THEN
  RAISE EXCEPTION 'Recovery role changed' USING ERRCODE='23514';
 END IF;
 RETURN c;
END $$;
CREATE FUNCTION auth6b_accept_approval(cid uuid) RETURNS void
 LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE c public.auth_recovery_cases; t timestamptz;
BEGIN
 c:=public.auth6b_lock_case(cid);
 IF c.state<>'collecting' OR c.expires_at<=clock_timestamp() THEN RAISE EXCEPTION 'Recovery not collecting' USING ERRCODE='23514'; END IF;
 IF (SELECT count(*) FROM public.auth_recovery_approvals WHERE case_id=cid)=2 THEN
  PERFORM public.auth6b_check_approvals(cid);
  t:=clock_timestamp()+interval '24 hours';
  IF EXISTS(SELECT FROM public.auth_recovery_approvals WHERE case_id=cid AND expires_at<=t) THEN
   RAISE EXCEPTION 'Insufficient approval lifetime' USING ERRCODE='23514';
  END IF;
  UPDATE public.auth_recovery_cases SET state='hold',hold_until=t WHERE id=cid;
 END IF;
END $$;
CREATE FUNCTION auth6b_quarantine(cid uuid, gid uuid, th text) RETURNS void
 LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE c public.auth_recovery_cases; t timestamptz; expiry timestamptz;
BEGIN
 c:=public.auth6b_lock_case(cid); t:=clock_timestamp();
 PERFORM public.auth6b_check_approvals(cid);
 IF c.state<>'hold' OR c.hold_until>t OR EXISTS(SELECT FROM public.auth_recovery_grants WHERE case_id=cid) OR
  NOT EXISTS(SELECT FROM public.users WHERE id=c.target_user_id AND auth_version=c.expected_auth_version) THEN
  RAISE EXCEPTION 'Recovery not ready' USING ERRCODE='23514';
 END IF;
 SELECT least(t+interval '15 minutes',c.expires_at,min(expires_at)) INTO expiry FROM public.auth_recovery_approvals WHERE case_id=cid;
 IF expiry<=t+interval '10 minutes' THEN RAISE EXCEPTION 'Insufficient recovery lifetime' USING ERRCODE='23514'; END IF;
 UPDATE public.auth_recovery_cases SET state='ready' WHERE id=cid;
 PERFORM id FROM public.auth_flows WHERE user_id=c.target_user_id ORDER BY id FOR UPDATE;
 PERFORM a.id FROM public.auth_challenges a JOIN public.auth_flows f ON f.id=a.flow_id WHERE f.user_id=c.target_user_id ORDER BY a.id FOR UPDATE OF a;
 PERFORM 1 FROM public.user_totp_factors WHERE user_id=c.target_user_id FOR UPDATE;
 PERFORM id FROM public.mfa_recovery_codes WHERE user_id=c.target_user_id ORDER BY id FOR UPDATE;
 UPDATE public.users SET auth_version=auth_version+1 WHERE id=c.target_user_id;
 UPDATE public.auth_flows SET state='invalidated',invalidated_at=t WHERE user_id=c.target_user_id AND state IN ('pending','primary_verified');
 UPDATE public.auth_challenges SET invalidated_at=t WHERE flow_id IN (SELECT id FROM public.auth_flows WHERE user_id=c.target_user_id) AND consumed_at IS NULL AND invalidated_at IS NULL;
 UPDATE public.mfa_recovery_codes SET revoked_at=t WHERE user_id=c.target_user_id AND used_at IS NULL AND revoked_at IS NULL;
 UPDATE public.user_totp_factors SET secret_ciphertext=NULL,key_version=NULL,disabled_at=coalesce(disabled_at,t),recovery_batch_id=NULL,
  pending_secret_ciphertext=NULL,pending_key_version=NULL,pending_expires_at=NULL,updated_at=t WHERE user_id=c.target_user_id AND confirmed_at IS NOT NULL;
 DELETE FROM public.user_totp_factors WHERE user_id=c.target_user_id AND confirmed_at IS NULL;
 PERFORM session_id FROM public.auth_session_states WHERE user_id=c.target_user_id ORDER BY session_id FOR UPDATE;
 UPDATE public.auth_session_states SET revoked_at=t WHERE user_id=c.target_user_id AND revoked_at IS NULL;
 INSERT INTO public.auth_recovery_gates(user_id,case_id,blocked_at) VALUES(c.target_user_id,cid,t)
 ON CONFLICT(user_id) DO UPDATE SET case_id=excluded.case_id,blocked_at=excluded.blocked_at,released_at=NULL;
 INSERT INTO public.auth_recovery_grants(id,case_id,generation,token_hash,auth_version,issued_at,expires_at)
 VALUES(gid,cid,1,th,c.expected_auth_version+1,t,expiry);
END $$;
CREATE FUNCTION auth6b_exchange(cid uuid, th text, binding text, flow_uuid uuid) RETURNS bigint
 LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE c public.auth_recovery_cases; g public.auth_recovery_grants; t timestamptz; fid bigint;
BEGIN
 c:=public.auth6b_lock_case(cid); t:=clock_timestamp();
 PERFORM public.auth6b_check_approvals(cid);
 SELECT * INTO STRICT g FROM public.auth_recovery_grants WHERE case_id=cid;
 IF c.state<>'ready' OR g.token_hash<>th OR g.exchanged_at IS NOT NULL OR g.revoked_at IS NOT NULL OR g.expires_at<=t OR
  NOT EXISTS(SELECT FROM public.users WHERE id=c.target_user_id AND auth_version=g.auth_version) THEN
  RAISE EXCEPTION 'Recovery exchange denied' USING ERRCODE='23514';
 END IF;
 INSERT INTO public.auth_flows(public_id,browser_binding_hash,audience,purpose,action,user_id,auth_version_at_start,created_at,expires_at)
 VALUES(flow_uuid,binding,c.target_role,'mfa_enroll','assisted_recovery',c.target_user_id,g.auth_version,t,least(t+interval '10 minutes',g.expires_at)) RETURNING id INTO fid;
 UPDATE public.auth_recovery_grants SET exchanged_at=t,binding_hash=binding,enrollment_flow_id=fid WHERE id=g.id;
 UPDATE public.auth_recovery_cases SET state='enrolling' WHERE id=cid;
 RETURN fid;
END $$;
CREATE FUNCTION auth6b_complete(cid uuid) RETURNS void
 LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE c public.auth_recovery_cases; g public.auth_recovery_grants;
BEGIN
 c:=public.auth6b_lock_case(cid);
 PERFORM public.auth6b_check_approvals(cid);
 SELECT * INTO STRICT g FROM public.auth_recovery_grants WHERE case_id=cid;
 IF c.state<>'enrolling' OR g.expires_at<=clock_timestamp() OR NOT EXISTS(
  SELECT FROM public.auth_flows f JOIN public.user_totp_factors t ON t.user_id=f.user_id JOIN public.users u ON u.id=f.user_id
  WHERE f.id=g.enrollment_flow_id AND f.state='completed' AND f.action='assisted_recovery'
   AND f.expires_at>clock_timestamp() AND f.primary_verified_at>clock_timestamp()-interval '5 minutes'
   AND t.disabled_at IS NULL AND t.confirmed_at>=g.exchanged_at AND t.recovery_batch_id IS NOT NULL
   AND u.auth_version=g.auth_version+1) THEN
  RAISE EXCEPTION 'Recovery completion denied' USING ERRCODE='23514';
 END IF;
 UPDATE public.auth_recovery_cases SET state='completed',closed_at=clock_timestamp() WHERE id=cid;
 UPDATE public.auth_recovery_gates SET released_at=clock_timestamp() WHERE user_id=c.target_user_id AND case_id=cid;
END $$;
CREATE FUNCTION auth6b_close(cid uuid, terminal text) RETURNS void
 LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE c public.auth_recovery_cases; t timestamptz;
BEGIN
 c:=public.auth6b_lock_case(cid); t:=clock_timestamp();
 IF terminal NOT IN ('cancelled','rejected','expired') OR (terminal='expired' AND c.expires_at>t AND NOT EXISTS(SELECT FROM public.auth_recovery_grants WHERE case_id=cid AND expires_at<=t)) THEN
  RAISE EXCEPTION 'Invalid recovery close' USING ERRCODE='23514';
 END IF;
 -- Exchanged grants незмінні; authority втрачається через terminal case та flow invalidation.
 -- Unexchanged revoke до terminal transition, поки live approval guard ще можливий.
 -- Expired/revoked keys не повинні забороняти безпечне закриття: нові grants не видаються.
 PERFORM id FROM public.auth_flows WHERE user_id=c.target_user_id ORDER BY id FOR UPDATE;
 PERFORM 1 FROM public.user_totp_factors WHERE user_id=c.target_user_id FOR UPDATE;
 UPDATE public.auth_flows SET state='invalidated',invalidated_at=t WHERE id IN
  (SELECT enrollment_flow_id FROM public.auth_recovery_grants WHERE case_id=cid) AND state IN ('pending','primary_verified');
 UPDATE public.user_totp_factors SET pending_secret_ciphertext=NULL,pending_key_version=NULL,pending_expires_at=NULL,updated_at=t
  WHERE user_id=c.target_user_id AND confirmed_at IS NOT NULL;
 DELETE FROM public.user_totp_factors WHERE user_id=c.target_user_id AND confirmed_at IS NULL;
 UPDATE public.auth_recovery_grants SET revoked_at=t WHERE case_id=cid AND exchanged_at IS NULL AND revoked_at IS NULL;
 UPDATE public.auth_recovery_cases SET state=terminal,closed_at=t WHERE id=cid;
END $$;
-- Gate enforcement не залежить від feature flag; invalidation/revocation завжди дозволені.
CREATE FUNCTION auth6b_auth_gate_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
 IF EXISTS(SELECT FROM public.auth_recovery_gates WHERE user_id=NEW.user_id AND released_at IS NULL) THEN
  IF TG_TABLE_NAME='auth_flows' THEN
   IF NEW.state<>'invalidated' AND NEW.action IS DISTINCT FROM 'assisted_recovery' THEN
    RAISE EXCEPTION 'Authentication quarantined' USING ERRCODE='23514';
   END IF;
  ELSIF NEW.revoked_at IS NULL THEN
   RAISE EXCEPTION 'Session quarantined' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth6b_flow_gate BEFORE INSERT OR UPDATE ON auth_flows FOR EACH ROW EXECUTE FUNCTION auth6b_auth_gate_guard();
CREATE TRIGGER auth6b_session_gate BEFORE INSERT OR UPDATE ON auth_session_states FOR EACH ROW EXECUTE FUNCTION auth6b_auth_gate_guard();
REVOKE ALL ON FUNCTION auth6b_lock_case(uuid), auth6b_accept_approval(uuid), auth6b_quarantine(uuid,uuid,text), auth6b_exchange(uuid,text,text,uuid), auth6b_complete(uuid), auth6b_close(uuid,text), auth6b_auth_gate_guard() FROM PUBLIC;
