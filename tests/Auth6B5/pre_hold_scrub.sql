-- Історична b879668 cleanup-функція лише для regression upgrade.
CREATE OR REPLACE FUNCTION auth_retention_cleanup(rid uuid,batch integer,kind text) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public SET lock_timeout='2s' SET statement_timeout='10s' AS $$
DECLARE r public.auth_retention_runs; prior public.auth_retention_batches; row record; chosen text[]:=ARRAY[]::text[]; n integer:=0; k integer;
BEGIN
 IF current_setting('transaction_isolation')<>'read committed' THEN RAISE EXCEPTION 'Read committed retention required'; END IF;
 IF current_database()<>'telmanfit_stage1_test' OR session_user<>'telmanfit_test_runner' THEN RAISE EXCEPTION 'Synthetic retention only'; END IF;
 IF current_user<>'telmanfit_retention_owner' OR kind IS NULL OR kind NOT IN ('challenges','flows','codes','invitations','pending_totp','outbox','failed_jobs') OR batch IS NULL OR batch<1 THEN RAISE EXCEPTION 'Retention unavailable'; END IF;
 IF NOT EXISTS(SELECT FROM public.auth_security_runtime sr WHERE sr.id=1 AND sr.state='ready') THEN RAISE EXCEPTION 'Restore fence closed'; END IF;
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
