-- D4: лише security intent; PII очищується атомарно з terminal audit.
CREATE TABLE auth_security_notice_outbox (
 id uuid PRIMARY KEY,
 request_id uuid NOT NULL,
 subject_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 case_reference uuid,
 notice_type text NOT NULL CHECK (notice_type IN ('email_changed','recovery_started','recovery_approved','recovery_cancelled','recovery_completed','credentials_revoked','mfa_changed')),
 contract_version smallint NOT NULL CHECK (contract_version=1),
 recipient_slot text NOT NULL CHECK (recipient_slot IN ('old','current','independent')),
 recipient_ciphertext text,
 recipient_key_version text CHECK (recipient_key_version ~ '^[a-zA-Z0-9_.-]{1,64}$'),
 created_at timestamptz NOT NULL,
 expires_at timestamptz NOT NULL,
 next_attempt_at timestamptz NOT NULL,
 attempts integer NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 20),
 lease_token uuid,
 lease_until timestamptz,
 state text NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','leased','sent','expired','dead')),
 finished_at timestamptz,
 last_error_code text CHECK (last_error_code IN ('transport','timeout','key','queue','policy')),
 UNIQUE (request_id,notice_type,recipient_slot),
 CHECK (expires_at>created_at AND expires_at<=created_at+interval '24 hours'),
 CHECK (next_attempt_at>=created_at),
 CHECK ((recipient_ciphertext IS NULL)=(recipient_key_version IS NULL)),
 CHECK ((lease_token IS NULL)=(lease_until IS NULL)),
 CHECK ((state='leased')=(lease_token IS NOT NULL)),
 CHECK ((state IN ('sent','expired','dead'))=(finished_at IS NOT NULL)),
 CHECK ((state IN ('sent','expired','dead'))=(recipient_ciphertext IS NULL)),
 CHECK (finished_at IS NULL OR finished_at>=created_at),
 CHECK (lease_until IS NULL OR lease_until>created_at),
 CHECK (recipient_slot<>'old' OR notice_type IN ('email_changed','recovery_completed')),
 CHECK (notice_type NOT LIKE 'recovery_%' OR case_reference IS NOT NULL)
);
CREATE INDEX auth_notice_due ON auth_security_notice_outbox(next_attempt_at,id) WHERE state='pending';
CREATE INDEX auth_notice_lease ON auth_security_notice_outbox(lease_until,id) WHERE state='leased';
CREATE INDEX auth_notice_subject ON auth_security_notice_outbox(subject_user_id);
CREATE INDEX auth_notice_correlation ON auth_security_notice_outbox(request_id);
CREATE INDEX auth_notice_finished ON auth_security_notice_outbox(finished_at,id) WHERE finished_at IS NOT NULL;

CREATE FUNCTION auth_notice_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
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
REVOKE ALL ON FUNCTION auth_notice_guard() FROM PUBLIC;
CREATE TRIGGER auth_notice_guard BEFORE INSERT OR UPDATE OR DELETE ON auth_security_notice_outbox FOR EACH ROW EXECUTE FUNCTION auth_notice_guard();
CREATE TRIGGER auth_notice_no_truncate BEFORE TRUNCATE ON auth_security_notice_outbox FOR EACH STATEMENT EXECUTE FUNCTION auth_notice_guard();

CREATE FUNCTION auth_notice_audit() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE event text;
BEGIN
 event:=CASE WHEN TG_OP='INSERT' THEN 'queued' WHEN NEW.state='pending' THEN 'failed' WHEN NEW.state='leased' THEN 'claimed' ELSE NEW.state END;
 INSERT INTO public.auth_audit_events(subject_user_id,event_type,outcome,request_id,case_reference,reason_code)
 VALUES(NEW.subject_user_id,'notice.'||event,CASE WHEN event IN ('failed','dead','expired') THEN 'error' ELSE 'success' END,NEW.request_id,NEW.case_reference,NEW.last_error_code);
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION auth_notice_audit() FROM PUBLIC;
CREATE TRIGGER auth_notice_audit AFTER INSERT OR UPDATE ON auth_security_notice_outbox FOR EACH ROW EXECUTE FUNCTION auth_notice_audit();

-- Всі claims/acks проходять ці bounded primitives; runtime не має UPDATE.
CREATE FUNCTION auth_notice_claim(batch_size integer) RETURNS SETOF auth_security_notice_outbox
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r public.auth_security_notice_outbox; t timestamptz;
BEGIN
 IF batch_size IS NULL OR batch_size NOT BETWEEN 1 AND 50 THEN RAISE EXCEPTION 'Invalid notice batch'; END IF;
 FOR r IN SELECT * FROM public.auth_security_notice_outbox
  WHERE (state='pending' AND (next_attempt_at<=clock_timestamp() OR expires_at<=clock_timestamp())) OR (state='leased' AND lease_until<=clock_timestamp())
  ORDER BY next_attempt_at,id LIMIT batch_size FOR UPDATE SKIP LOCKED LOOP
  t:=clock_timestamp();
  IF r.expires_at<=t OR r.attempts>=20 THEN
   UPDATE public.auth_security_notice_outbox SET state=CASE WHEN expires_at<=t THEN 'expired' ELSE 'dead' END,
    finished_at=t,recipient_ciphertext=NULL,recipient_key_version=NULL,lease_token=NULL,lease_until=NULL
    WHERE id=r.id;
  ELSE
   UPDATE public.auth_security_notice_outbox SET state='leased',attempts=attempts+1,lease_token=gen_random_uuid(),lease_until=t+interval '90 seconds'
    WHERE id=r.id RETURNING * INTO r;
   RETURN NEXT r;
  END IF;
 END LOOP;
END $$;
REVOKE ALL ON FUNCTION auth_notice_claim(integer) FROM PUBLIC;

CREATE FUNCTION auth_notice_ack(notice_id uuid, token uuid, result text) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r public.auth_security_notice_outbox; t timestamptz; delay_seconds integer; target text;
BEGIN
 IF result IS NULL OR result NOT IN ('sent','transport','timeout','key','policy','permanent') THEN RAISE EXCEPTION 'Invalid notice result'; END IF;
 SELECT * INTO r FROM public.auth_security_notice_outbox WHERE id=notice_id FOR UPDATE;
 t:=clock_timestamp();
 IF NOT FOUND OR token IS NULL OR r.state<>'leased' OR r.lease_token IS DISTINCT FROM token OR r.lease_until<=t THEN RETURN false; END IF;
 target:=CASE WHEN r.expires_at<=t THEN 'expired' WHEN result='sent' THEN 'sent'
  WHEN result IN ('key','policy','permanent') OR r.attempts>=20 THEN 'dead' ELSE 'pending' END;
 delay_seconds:=CASE r.attempts WHEN 1 THEN 30 WHEN 2 THEN 120 WHEN 3 THEN 600 WHEN 4 THEN 1800 ELSE 3600 END;
 UPDATE public.auth_security_notice_outbox SET state=target,lease_token=NULL,lease_until=NULL,
  next_attempt_at=CASE WHEN target='pending' THEN t+make_interval(secs=>delay_seconds+floor(random()*greatest(1,delay_seconds/10))::integer) ELSE next_attempt_at END,
  finished_at=CASE WHEN target='pending' THEN NULL ELSE t END,
  recipient_ciphertext=CASE WHEN target='pending' THEN recipient_ciphertext ELSE NULL END,
  recipient_key_version=CASE WHEN target='pending' THEN recipient_key_version ELSE NULL END,
  last_error_code=CASE WHEN result='sent' THEN NULL WHEN result='permanent' THEN 'transport' ELSE result END
 WHERE id=notice_id;
 RETURN true;
END $$;
REVOKE ALL ON FUNCTION auth_notice_ack(uuid,uuid,text) FROM PUBLIC;
REVOKE ALL ON auth_security_notice_outbox FROM PUBLIC;
