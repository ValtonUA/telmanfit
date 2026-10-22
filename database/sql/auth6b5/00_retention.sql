-- AUTH-6B/B5. Additive; без production provisioning або зовнішніх credentials.
CREATE TABLE auth_security_runtime (
 id smallint PRIMARY KEY CHECK(id=1),
 restore_epoch uuid NOT NULL,
 last_reconciled_at timestamptz NOT NULL,
 state text NOT NULL CHECK(state IN ('ready','maintenance','recovery_required')),
 issuance_not_before timestamptz NOT NULL
);
-- Порожня таблиця означає fail-closed. Synthetic bootstrap виконує лише test harness.
CREATE TABLE auth_retention_holds (
 id uuid PRIMARY KEY,
 subject_user_id bigint REFERENCES users(id) ON DELETE RESTRICT,
 case_id uuid REFERENCES auth_recovery_cases(id) ON DELETE RESTRICT,
 audit_from timestamptz,
 audit_to timestamptz,
 reason_code text NOT NULL CHECK(reason_code IN ('legal','incident','dispute')),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 created_by_principal_id uuid NOT NULL REFERENCES auth_recovery_principals(id) ON DELETE RESTRICT,
 released_at timestamptz,
 released_by_principal_id uuid REFERENCES auth_recovery_principals(id) ON DELETE RESTRICT,
 CHECK(num_nonnulls(subject_user_id,case_id,audit_from)=1),
 CHECK((audit_from IS NULL)=(audit_to IS NULL)), CHECK(audit_to>audit_from),
 CHECK((released_at IS NULL)=(released_by_principal_id IS NULL)),
 CHECK(released_at>=created_at), CHECK(released_by_principal_id<>created_by_principal_id)
);
CREATE INDEX auth_retention_hold_user ON auth_retention_holds(subject_user_id) WHERE released_at IS NULL;
CREATE INDEX auth_retention_hold_case ON auth_retention_holds(case_id) WHERE released_at IS NULL;
CREATE INDEX auth_retention_hold_active ON auth_retention_holds(audit_from,audit_to) WHERE released_at IS NULL;
CREATE TABLE auth_retention_runs (
 id uuid PRIMARY KEY, mode text NOT NULL CHECK(mode IN ('dry_run','apply')),
 policy_version integer NOT NULL CHECK(policy_version=1), cutoff timestamptz NOT NULL,
 started_at timestamptz NOT NULL DEFAULT clock_timestamp(), finished_at timestamptz,
 state text NOT NULL CHECK(state IN ('running','completed','failed')),
 request_id uuid NOT NULL UNIQUE,
 reason_code text CHECK(reason_code IN ('archive','hold','conflict','unavailable')),
 CHECK((state<>'running')=(finished_at IS NOT NULL)), CHECK(finished_at>=started_at)
);
CREATE INDEX auth_retention_run_time ON auth_retention_runs(started_at);
CREATE TABLE auth_archive_manifests (
 id uuid PRIMARY KEY, entity_type text NOT NULL CHECK(entity_type='audit'),
 first_occurred_at timestamptz NOT NULL, last_occurred_at timestamptz NOT NULL,
 first_id bigint NOT NULL, last_id bigint NOT NULL,
 row_count bigint NOT NULL CHECK(row_count BETWEEN 1 AND 500),
 content_digest text NOT NULL CHECK(content_digest ~ '^[0-9a-f]{64}$'),
 object_ref uuid NOT NULL UNIQUE,
 exported_at timestamptz NOT NULL, verified_at timestamptz NOT NULL,
 retain_until timestamptz NOT NULL, request_id uuid NOT NULL UNIQUE,
 CHECK(last_occurred_at>=first_occurred_at AND last_id>=first_id),
 CHECK(exported_at>=last_occurred_at AND verified_at>=exported_at),
 CHECK(retain_until>=last_occurred_at+interval '365 days')
);
CREATE INDEX auth_archive_time ON auth_archive_manifests(entity_type,last_occurred_at);
CREATE TABLE auth_retention_batches (
 id uuid PRIMARY KEY, run_id uuid NOT NULL REFERENCES auth_retention_runs(id) ON DELETE RESTRICT,
 batch_no integer NOT NULL CHECK(batch_no>0), entity_type text NOT NULL CHECK(entity_type IN ('audit','challenges','flows','codes','invitations','pending_totp','outbox','failed_jobs')),
 candidate_count integer NOT NULL, deleted_count integer NOT NULL,
 selection_digest text NOT NULL CHECK(selection_digest ~ '^[0-9a-f]{64}$'),
 archive_manifest_id uuid REFERENCES auth_archive_manifests(id) ON DELETE RESTRICT,
 committed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(run_id,batch_no), UNIQUE(archive_manifest_id),
 CHECK(deleted_count>=0 AND deleted_count<=candidate_count AND candidate_count BETWEEN 0 AND 500)
);
CREATE FUNCTION auth_retention_history_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN RAISE EXCEPTION 'Retention history is immutable' USING ERRCODE='23514'; END $$;
CREATE TRIGGER auth_archive_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON auth_archive_manifests FOR EACH STATEMENT EXECUTE FUNCTION auth_retention_history_guard();
CREATE TRIGGER auth_batch_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON auth_retention_batches FOR EACH STATEMENT EXECUTE FUNCTION auth_retention_history_guard();
CREATE FUNCTION auth_retention_hold_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(6060505);
 IF TG_OP IN ('DELETE','TRUNCATE') THEN RAISE EXCEPTION 'Durable hold history'; END IF;
 IF TG_OP='UPDATE' AND (OLD.released_at IS NOT NULL OR NEW.released_at IS NULL OR
   (to_jsonb(NEW)-ARRAY['released_at','released_by_principal_id']) IS DISTINCT FROM
   (to_jsonb(OLD)-ARRAY['released_at','released_by_principal_id'])) THEN RAISE EXCEPTION 'Invalid hold release'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth_hold_guard BEFORE INSERT OR UPDATE OR DELETE ON auth_retention_holds FOR EACH ROW EXECUTE FUNCTION auth_retention_hold_guard();
CREATE TRIGGER auth_hold_no_truncate BEFORE TRUNCATE ON auth_retention_holds FOR EACH STATEMENT EXECUTE FUNCTION auth_retention_hold_guard();
CREATE FUNCTION auth_retention_run_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
 IF TG_OP IN ('DELETE','TRUNCATE') THEN RAISE EXCEPTION 'Durable run history'; END IF;
 IF TG_OP='UPDATE' AND (OLD.state<>'running' OR NEW.state='running' OR
  (to_jsonb(NEW)-ARRAY['state','finished_at','reason_code']) IS DISTINCT FROM
  (to_jsonb(OLD)-ARRAY['state','finished_at','reason_code'])) THEN RAISE EXCEPTION 'Invalid run transition'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth_run_guard BEFORE UPDATE OR DELETE ON auth_retention_runs FOR EACH ROW EXECUTE FUNCTION auth_retention_run_guard();
CREATE TRIGGER auth_run_no_truncate BEFORE TRUNCATE ON auth_retention_runs FOR EACH STATEMENT EXECUTE FUNCTION auth_retention_run_guard();
-- Лише NOLOGIN owner спеціальної функції. Runtime не має membership цієї ролі.
CREATE OR REPLACE FUNCTION auth2_guard_audit() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
 IF TG_OP='DELETE' AND current_user='telmanfit_retention_owner' THEN RETURN OLD; END IF;
 RAISE EXCEPTION 'Security audit is append-only' USING ERRCODE='23514';
END $$;
CREATE FUNCTION auth_retention_purge_audit(rid uuid, batch integer, mid uuid, ids bigint[]) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public SET lock_timeout='2s' SET statement_timeout='5s' AS $$
DECLARE m public.auth_archive_manifests; r public.auth_retention_runs; n integer; d text; old public.auth_retention_batches;
BEGIN
 IF current_database()<>'telmanfit_stage1_test' OR session_user<>'telmanfit_test_runner' THEN RAISE EXCEPTION 'Synthetic retention only'; END IF;
 IF current_user<>'telmanfit_retention_owner' OR batch IS NULL OR batch<1 OR ids IS NULL OR
  cardinality(ids) NOT BETWEEN 1 AND 500 OR array_position(ids,NULL) IS NOT NULL OR
  cardinality(ids)<>(SELECT count(DISTINCT x) FROM unnest(ids) x) THEN RAISE EXCEPTION 'Retention unavailable'; END IF;
 IF NOT EXISTS(SELECT FROM public.auth_security_runtime WHERE id=1 AND state='ready') THEN RAISE EXCEPTION 'Restore fence closed'; END IF;
 PERFORM pg_advisory_xact_lock_shared(6060505);
 SELECT * INTO STRICT r FROM public.auth_retention_runs WHERE id=rid FOR UPDATE;
 SELECT * INTO old FROM public.auth_retention_batches WHERE run_id=rid AND batch_no=batch;
 IF FOUND THEN
  IF old.archive_manifest_id<>mid OR old.selection_digest<>encode(sha256(convert_to(array_to_string(ids,','),'UTF8')),'hex') THEN RAISE EXCEPTION 'Batch identity mismatch'; END IF;
  RETURN old.deleted_count;
 END IF;
 IF r.mode<>'apply' OR r.state<>'running' OR r.cutoff>clock_timestamp() THEN RAISE EXCEPTION 'Invalid retention run'; END IF;
 SELECT * INTO STRICT m FROM public.auth_archive_manifests WHERE id=mid;
 IF m.verified_at>clock_timestamp() OR m.verified_at<clock_timestamp()-interval '24 hours' OR m.retain_until<clock_timestamp() THEN RAISE EXCEPTION 'Archive receipt stale'; END IF;
 PERFORM 1 FROM public.auth_audit_events WHERE id=ANY(ids) ORDER BY id FOR UPDATE;
 SELECT count(*), encode(sha256(convert_to(coalesce(jsonb_agg(to_jsonb(a) ORDER BY a.id)::text,''),'UTF8')),'hex') INTO n,d
 FROM public.auth_audit_events a WHERE a.id=ANY(ids);
 IF n<>cardinality(ids) OR n<>m.row_count OR d<>m.content_digest OR
  (SELECT min(id) FROM public.auth_audit_events WHERE id=ANY(ids))<>m.first_id OR
  (SELECT max(id) FROM public.auth_audit_events WHERE id=ANY(ids))<>m.last_id OR
  (SELECT min(occurred_at) FROM public.auth_audit_events WHERE id=ANY(ids))<>m.first_occurred_at OR
  (SELECT max(occurred_at) FROM public.auth_audit_events WHERE id=ANY(ids))<>m.last_occurred_at OR
  EXISTS(SELECT FROM public.auth_audit_events WHERE id=ANY(ids) AND occurred_at>r.cutoff-interval '180 days') THEN RAISE EXCEPTION 'Archive content or age mismatch'; END IF;
 IF EXISTS(SELECT FROM public.auth_retention_holds h JOIN public.auth_audit_events a ON a.id=ANY(ids)
  WHERE h.released_at IS NULL AND (h.subject_user_id IN (a.subject_user_id,a.actor_user_id) OR
   h.case_id=a.case_reference OR (a.occurred_at>=h.audit_from AND a.occurred_at<h.audit_to) OR
   EXISTS(SELECT FROM public.auth_recovery_cases c WHERE c.id=h.case_id AND c.target_user_id IN (a.subject_user_id,a.actor_user_id)))) THEN RAISE EXCEPTION 'Legal hold'; END IF;
 DELETE FROM public.auth_audit_events WHERE id=ANY(ids);
 INSERT INTO public.auth_retention_batches(id,run_id,batch_no,entity_type,candidate_count,deleted_count,selection_digest,archive_manifest_id)
 VALUES(gen_random_uuid(),rid,batch,'audit',n,n,encode(sha256(convert_to(array_to_string(ids,','),'UTF8')),'hex'),mid);
 INSERT INTO public.auth_audit_events(event_type,outcome,request_id,reason_code) VALUES('retention.batch','success',r.request_id,'verified_archive');
 RETURN n;
END $$;
REVOKE ALL ON auth_security_runtime,auth_retention_holds,auth_retention_runs,auth_retention_batches,auth_archive_manifests FROM PUBLIC;
REVOKE ALL ON FUNCTION auth_retention_history_guard(),auth_retention_hold_guard(),auth_retention_run_guard(),auth_retention_purge_audit(uuid,integer,uuid,bigint[]) FROM PUBLIC;

CREATE OR REPLACE FUNCTION auth_notice_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
 IF TG_OP='DELETE' AND current_user='telmanfit_retention_owner' THEN RETURN OLD; END IF;
 IF TG_OP IN ('DELETE','TRUNCATE') THEN
  RAISE EXCEPTION 'Notice history requires B5 retention authority' USING ERRCODE='23514';
 END IF;
 IF TG_OP='INSERT' THEN
  IF NEW.state<>'pending' OR NEW.attempts<>0 OR NEW.last_error_code IS NOT NULL OR NEW.created_at>clock_timestamp() THEN
   RAISE EXCEPTION 'Invalid initial notice state' USING ERRCODE='23514';
  END IF;
 ELSE
  IF OLD.state IN ('sent','dead','expired') OR
   ROW(NEW.id,NEW.request_id,NEW.subject_user_id,NEW.case_reference,NEW.notice_type,NEW.contract_version,NEW.recipient_slot,NEW.created_at,NEW.expires_at)
   IS DISTINCT FROM ROW(OLD.id,OLD.request_id,OLD.subject_user_id,OLD.case_reference,OLD.notice_type,OLD.contract_version,OLD.recipient_slot,OLD.created_at,OLD.expires_at) OR
   (NEW.state NOT IN ('sent','dead','expired') AND ROW(NEW.recipient_ciphertext,NEW.recipient_key_version) IS DISTINCT FROM ROW(OLD.recipient_ciphertext,OLD.recipient_key_version)) THEN
   RAISE EXCEPTION 'Immutable notice identity or recipient' USING ERRCODE='23514';
  END IF;
  IF NEW.state='leased' THEN
   IF NEW.attempts<>OLD.attempts+1 OR NEW.lease_token IS NOT DISTINCT FROM OLD.lease_token
    OR NEW.lease_until<=clock_timestamp() OR NEW.lease_until>clock_timestamp()+interval '90 seconds'
    OR NEW.expires_at<=clock_timestamp() OR OLD.attempts>=20
    OR (OLD.state='leased' AND OLD.lease_until>clock_timestamp())
    OR (OLD.state='pending' AND OLD.next_attempt_at>clock_timestamp()) THEN
    RAISE EXCEPTION 'Invalid notice claim' USING ERRCODE='23514';
   END IF;
  ELSIF NEW.attempts<>OLD.attempts OR
    (NEW.state='pending' AND (OLD.state<>'leased' OR NEW.next_attempt_at<=clock_timestamp())) OR
    (NEW.state='sent' AND (OLD.state<>'leased' OR OLD.lease_until<=clock_timestamp() OR OLD.expires_at<=clock_timestamp())) OR
    (NEW.state='expired' AND OLD.expires_at>clock_timestamp()) OR
    (NEW.state='dead' AND OLD.attempts<20 AND coalesce(NEW.last_error_code,'') NOT IN ('key','policy','transport')) THEN
   RAISE EXCEPTION 'Invalid notice transition' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
-- Внутрішній перелік кандидатів. Активні invitations, current batch і history не purge.
CREATE FUNCTION auth_retention_candidates(kind text, cutoff timestamptz)
RETURNS TABLE(id text,subject bigint,case_ref uuid,occurred timestamptz) LANGUAGE sql SET search_path=pg_catalog,public AS $$
 SELECT c.id::text,f.user_id,NULL::uuid,greatest(c.expires_at,c.consumed_at,c.invalidated_at) FROM public.auth_challenges c JOIN public.auth_flows f ON f.id=c.flow_id
 WHERE kind='challenges' AND greatest(c.expires_at,c.consumed_at,c.invalidated_at)<=cutoff-interval '24 hours'
 AND greatest(f.expires_at,f.consumed_at,f.invalidated_at)<=cutoff-interval '24 hours'
 AND NOT EXISTS(SELECT FROM public.auth_recovery_grants g WHERE g.enrollment_flow_id=f.id)
 UNION ALL
 SELECT f.id::text,f.user_id,NULL::uuid,greatest(f.expires_at,f.consumed_at,f.invalidated_at) FROM public.auth_flows f
 WHERE kind='flows' AND greatest(f.expires_at,f.consumed_at,f.invalidated_at)<=cutoff-interval '24 hours'
 AND NOT EXISTS(SELECT FROM public.auth_challenges c WHERE c.flow_id=f.id)
 AND NOT EXISTS(SELECT FROM public.auth_recovery_grants g WHERE g.enrollment_flow_id=f.id)
 UNION ALL
 SELECT c.id::text,c.user_id,NULL::uuid,coalesce(c.used_at,c.revoked_at) FROM public.mfa_recovery_codes c JOIN public.user_totp_factors f ON f.user_id=c.user_id
 WHERE kind='codes' AND coalesce(c.used_at,c.revoked_at)<=cutoff-interval '365 days' AND c.batch_id IS DISTINCT FROM f.recovery_batch_id
 UNION ALL
 SELECT i.id::text,coalesce(i.consumed_by_user_id,i.revoked_by_user_id),NULL::uuid,coalesce(i.consumed_at,i.revoked_at) FROM public.client_invitations i
 WHERE kind='invitations' AND coalesce(i.consumed_at,i.revoked_at)<=cutoff-interval '365 days'
 AND NOT EXISTS(SELECT FROM public.auth_flows f WHERE f.invitation_id=i.id)
 AND NOT EXISTS(SELECT FROM public.auth_retention_holds h WHERE h.released_at IS NULL AND h.subject_user_id IN (i.created_by_user_id,i.coach_user_id,i.consumed_by_user_id,i.revoked_by_user_id))
 UNION ALL
 SELECT f.user_id::text,f.user_id,NULL::uuid,f.pending_expires_at FROM public.user_totp_factors f
 WHERE kind='pending_totp' AND f.pending_expires_at<=cutoff AND
 (f.confirmed_at IS NOT NULL OR (f.last_used_step IS NULL AND NOT EXISTS(SELECT FROM public.mfa_recovery_codes c WHERE c.user_id=f.user_id)))
 UNION ALL
 SELECT o.id::text,o.subject_user_id,o.case_reference,o.finished_at FROM public.auth_security_notice_outbox o
 WHERE kind='outbox' AND o.state IN ('sent','dead','expired') AND o.finished_at<=cutoff-interval '30 days' AND o.recipient_ciphertext IS NULL
 UNION ALL
 SELECT j.id::text,NULL::bigint,NULL::uuid,j.failed_at::timestamptz FROM public.failed_jobs j
 WHERE kind='failed_jobs' AND j.failed_at<=cutoff-interval '24 hours'
 AND (j.payload<>'[redacted]' OR NOT EXISTS(SELECT FROM public.auth_retention_holds WHERE released_at IS NULL))
 -- Ciphertext не класифікується через deserialize. Всі failed payloads видаляються ≤24h;
 -- sanitized audit залишається за основною policy, raw failure metadata не зберігається.
$$;
CREATE FUNCTION auth_retention_cleanup(rid uuid,batch integer,kind text) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public SET lock_timeout='2s' SET statement_timeout='10s' AS $$
DECLARE r public.auth_retention_runs; prior public.auth_retention_batches; row record; chosen text[]:=ARRAY[]::text[]; n integer:=0; k integer;
BEGIN
 IF current_database()<>'telmanfit_stage1_test' OR session_user<>'telmanfit_test_runner' THEN RAISE EXCEPTION 'Synthetic retention only'; END IF;
 IF current_user<>'telmanfit_retention_owner' OR kind IS NULL OR kind NOT IN ('challenges','flows','codes','invitations','pending_totp','outbox','failed_jobs') OR batch IS NULL OR batch<1 THEN RAISE EXCEPTION 'Retention unavailable'; END IF;
 IF NOT EXISTS(SELECT FROM public.auth_security_runtime WHERE id=1 AND state='ready') THEN RAISE EXCEPTION 'Restore fence closed'; END IF;
 PERFORM pg_advisory_xact_lock_shared(6060505);
 PERFORM pg_advisory_xact_lock(4040404);
 SELECT * INTO STRICT r FROM public.auth_retention_runs WHERE id=rid FOR UPDATE;
 SELECT * INTO prior FROM public.auth_retention_batches WHERE run_id=rid AND batch_no=batch;
 IF FOUND THEN
  IF prior.entity_type<>kind THEN RAISE EXCEPTION 'Batch identity mismatch'; END IF;
  RETURN prior.deleted_count;
 END IF;
 IF r.mode<>'apply' OR r.state<>'running' OR r.cutoff>clock_timestamp() THEN RAISE EXCEPTION 'Invalid retention run'; END IF;
 FOR row IN SELECT * FROM public.auth_retention_candidates(kind,r.cutoff) candidate
  WHERE (kind='failed_jobs' OR NOT EXISTS(SELECT FROM public.auth_retention_holds h WHERE h.released_at IS NULL AND
   (candidate.subject IS NULL OR h.subject_user_id=candidate.subject OR h.case_id=candidate.case_ref OR
    (candidate.occurred>=h.audit_from AND candidate.occurred<h.audit_to) OR
    EXISTS(SELECT FROM public.auth_recovery_cases c WHERE c.id=h.case_id AND c.target_user_id=candidate.subject))))
  ORDER BY subject NULLS FIRST,id LIMIT 500 LOOP
  chosen:=array_append(chosen,row.id);
  -- Невідомий owner ciphertext failure → будь-який active hold зупиняє purge.
  IF EXISTS(SELECT FROM public.auth_retention_holds h WHERE h.released_at IS NULL AND
    (row.subject IS NULL OR h.subject_user_id=row.subject OR h.case_id=row.case_ref OR
     (row.occurred>=h.audit_from AND row.occurred<h.audit_to) OR
     EXISTS(SELECT FROM public.auth_recovery_cases c WHERE c.id=h.case_id AND c.target_user_id=row.subject))) THEN
   IF kind='failed_jobs' THEN
    UPDATE public.failed_jobs SET payload='[redacted]',exception='[redacted]',connection='redacted',queue='redacted' WHERE id=row.id::bigint;
    GET DIAGNOSTICS k=ROW_COUNT; n:=n+k;
   END IF;
   CONTINUE;
  END IF;
  IF row.subject IS NOT NULL THEN
   PERFORM 1 FROM public.users WHERE id=row.subject FOR UPDATE SKIP LOCKED;
   IF NOT FOUND THEN CONTINUE; END IF;
  END IF;
  -- Recheck під user lock; FK conflict відкочує лише цей item, жодного CASCADE.
  IF NOT EXISTS(SELECT FROM public.auth_retention_candidates(kind,r.cutoff) c WHERE c.id=row.id) THEN CONTINUE; END IF;
  BEGIN
   CASE kind
    WHEN 'challenges' THEN DELETE FROM public.auth_challenges WHERE id=row.id::bigint;
    WHEN 'flows' THEN DELETE FROM public.auth_flows WHERE id=row.id::bigint;
    WHEN 'codes' THEN DELETE FROM public.mfa_recovery_codes WHERE id=row.id::bigint;
    WHEN 'invitations' THEN DELETE FROM public.client_invitations WHERE id=row.id::bigint;
    WHEN 'pending_totp' THEN
     IF EXISTS(SELECT FROM public.user_totp_factors WHERE user_id=row.subject AND confirmed_at IS NOT NULL) THEN
      UPDATE public.user_totp_factors SET pending_secret_ciphertext=NULL,pending_key_version=NULL,pending_expires_at=NULL,updated_at=clock_timestamp() WHERE user_id=row.subject;
     ELSE DELETE FROM public.user_totp_factors WHERE user_id=row.subject AND confirmed_at IS NULL AND last_used_step IS NULL; END IF;
    WHEN 'outbox' THEN DELETE FROM public.auth_security_notice_outbox WHERE id=row.id::uuid;
    WHEN 'failed_jobs' THEN DELETE FROM public.failed_jobs WHERE id=row.id::bigint;
   END CASE;
   GET DIAGNOSTICS k=ROW_COUNT;
   n:=n+k;
  EXCEPTION WHEN foreign_key_violation OR lock_not_available THEN CONTINUE;
  END;
 END LOOP;
  INSERT INTO public.auth_retention_batches(id,run_id,batch_no,entity_type,candidate_count,deleted_count,selection_digest)
  VALUES(gen_random_uuid(),rid,batch,kind,cardinality(chosen),n,encode(sha256(convert_to(array_to_string(chosen,','),'UTF8')),'hex'));
  INSERT INTO public.auth_audit_events(event_type,outcome,request_id,reason_code) VALUES('retention.batch','success',r.request_id,kind);
 RETURN n;
END $$;
REVOKE ALL ON FUNCTION auth_retention_candidates(text,timestamptz),auth_retention_cleanup(uuid,integer,text) FROM PUBLIC;
-- Завершення run не надає DELETE privilege і не змінює receipts.
CREATE FUNCTION auth_retention_finish(rid uuid,failed boolean) RETURNS void LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,public SET lock_timeout='2s' AS $$
BEGIN
 IF current_database()<>'telmanfit_stage1_test' OR session_user<>'telmanfit_test_runner' THEN RAISE EXCEPTION 'Synthetic retention only'; END IF;
 IF current_user<>'telmanfit_retention_owner' OR failed IS NULL THEN RAISE EXCEPTION 'Retention unavailable'; END IF;
 UPDATE public.auth_retention_runs SET state=CASE WHEN failed THEN 'failed' ELSE 'completed' END,
  finished_at=statement_timestamp(),reason_code=CASE WHEN failed THEN 'unavailable' ELSE NULL END WHERE id=rid AND state='running';
END $$;
REVOKE ALL ON FUNCTION auth_retention_finish(uuid,boolean) FROM PUBLIC;
CREATE FUNCTION auth_retention_run_audit() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
BEGIN
 INSERT INTO public.auth_audit_events(event_type,outcome,request_id,reason_code)
 VALUES('retention.run_'||NEW.state,CASE WHEN NEW.state='failed' THEN 'error' ELSE 'success' END,NEW.request_id,NEW.reason_code);
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION auth_retention_run_audit() FROM PUBLIC;
CREATE TRIGGER auth_retention_run_audit AFTER INSERT OR UPDATE ON auth_retention_runs FOR EACH ROW EXECUTE FUNCTION auth_retention_run_audit();
CREATE INDEX auth_retention_hold_creator ON auth_retention_holds(created_by_principal_id);
CREATE INDEX auth_retention_hold_releaser ON auth_retention_holds(released_by_principal_id);
