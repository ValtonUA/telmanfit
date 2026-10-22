-- AUTH-6B/B6: additive D7. SQL 01–08 та історичні міграції незмінні.
CREATE FUNCTION public.auth6b6_policy(p_scope text)
RETURNS TABLE(cap integer, window_seconds integer, pause_seconds integer, failure boolean)
LANGUAGE sql IMMUTABLE STRICT SET search_path=pg_catalog,public AS $$
 SELECT v.cap,v.window_seconds,v.pause_seconds,v.failure FROM (VALUES
 ('otp_cooldown',1,60,0,false),
 ('otp_email_short',3,900,0,false),
 ('otp_email_day',10,86400,0,false),
 ('otp_email_all',15,86400,0,false),
 ('otp_ip_short',20,900,0,false),
 ('otp_ip_day',100,86400,0,false),
 ('otp_global',1000,3600,0,false),
 ('otp_verify_ip',10,60,0,false),
 ('otp_failure',5,900,900,true),
 ('invitation_ip',10,60,0,false),
 ('invitation_token',5,900,0,false),
 ('registration_ip',10,60,0,false),
 ('registration_flow',5,900,0,false),
 ('mfa_ip',20,900,0,false),
 ('mfa_flow',5,300,0,true),
 ('mfa_user',10,900,900,true),
 ('admin_email',5,900,900,false),
 ('admin_ip',20,900,900,false),
 ('stepup_ip',20,900,0,false),
 ('stepup_user',5,900,0,false),
 ('recovery_exchange_ip',10,60,0,false),
 ('recovery_exchange_case',5,900,0,false),
 ('recovery_totp_flow',5,900,0,true),
 ('recovery_totp_user',10,900,0,true),
 ('recovery_totp_ip',20,900,0,true)
 ) v(scope,cap,window_seconds,pause_seconds,failure) WHERE v.scope=p_scope
$$;

-- Та сама exact boundary primitive використовується engine та deterministic vectors.
CREATE FUNCTION public.auth6b6_events(p_events timestamptz[],p_now timestamptz,p_scope text)
RETURNS timestamptz[] LANGUAGE sql IMMUTABLE STRICT SET search_path=pg_catalog,public AS $$
 SELECT coalesce(array_agg(t ORDER BY t),'{}') FROM unnest(p_events) t
 WHERE t>p_now-make_interval(secs=>(SELECT window_seconds FROM public.auth6b6_policy(p_scope)))
$$;

CREATE TABLE public.auth_rate_budgets (
 scope text NOT NULL CHECK (scope IN ('otp_cooldown','otp_email_short','otp_email_day','otp_email_all','otp_ip_short','otp_ip_day','otp_global','otp_verify_ip','otp_failure','invitation_ip','invitation_token','registration_ip','registration_flow','mfa_ip','mfa_flow','mfa_user','admin_email','admin_ip','stepup_ip','stepup_user','recovery_exchange_ip','recovery_exchange_case','recovery_totp_flow','recovery_totp_user','recovery_totp_ip')),
 key_hash text NOT NULL CHECK (key_hash ~ '^[0-9a-f]{64}$'),
 events timestamptz[] NOT NULL DEFAULT '{}',
 blocked_until timestamptz,
 last_clock_at timestamptz NOT NULL CHECK (isfinite(last_clock_at)),
 gc_after timestamptz NOT NULL CHECK (isfinite(gc_after)),
 PRIMARY KEY(scope,key_hash),
 CHECK (cardinality(events)<=1000),
 CHECK (blocked_until IS NULL OR isfinite(blocked_until))
);
CREATE INDEX auth_rate_gc ON public.auth_rate_budgets(gc_after);

CREATE FUNCTION public.auth6b6_validate() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
DECLARE p record; t timestamptz; previous timestamptz; horizon timestamptz;
BEGIN
 SELECT * INTO STRICT p FROM public.auth6b6_policy(NEW.scope);
 IF cardinality(NEW.events)>p.cap OR (cardinality(NEW.events)>0 AND (array_ndims(NEW.events)<>1 OR array_lower(NEW.events,1)<>1))
    OR NEW.last_clock_at>clock_timestamp() OR (TG_OP='UPDATE' AND (NEW.scope<>OLD.scope OR NEW.key_hash<>OLD.key_hash OR NEW.last_clock_at<OLD.last_clock_at)) THEN
   RAISE EXCEPTION 'rate_budget_invalid' USING ERRCODE='23514';
 END IF;
 FOREACH t IN ARRAY NEW.events LOOP
   IF t IS NULL OR NOT isfinite(t) OR t>NEW.last_clock_at OR (previous IS NOT NULL AND t<previous) THEN
     RAISE EXCEPTION 'rate_budget_invalid' USING ERRCODE='23514';
   END IF;
   previous:=t;
 END LOOP;
 horizon:=greatest(coalesce(previous,NEW.last_clock_at)+make_interval(secs=>p.window_seconds),NEW.blocked_until)+interval '1 second';
 IF NEW.gc_after<horizon OR (NEW.blocked_until IS NOT NULL AND (p.pause_seconds=0 OR NEW.blocked_until>NEW.last_clock_at+make_interval(secs=>p.pause_seconds))) THEN
   RAISE EXCEPTION 'rate_budget_invalid' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER auth_rate_validate BEFORE INSERT OR UPDATE ON public.auth_rate_budgets FOR EACH ROW EXECUTE FUNCTION public.auth6b6_validate();

-- Modes: admission consume, failure check/record. Обмеження policy визначає SQL.
CREATE FUNCTION public.auth6b6_group(p_scopes text[],p_keys text[],p_mode text)
RETURNS TABLE(allowed boolean,retry_after integer,lock_acquisition_ms double precision)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE k record; p record; r public.auth_rate_budgets%ROWTYPE; n timestamptz;
 lock_started timestamptz; lock_ms double precision;
 e timestamptz[]; wait_until timestamptz; denied boolean:=false; b timestamptz;
BEGIN
 IF p_scopes IS NULL OR p_keys IS NULL OR p_mode NOT IN ('consume','check','record') OR p_mode IS NULL OR cardinality(p_scopes) NOT BETWEEN 1 AND 32
    OR cardinality(p_scopes)<>cardinality(p_keys) OR array_ndims(p_scopes)<>1 OR array_ndims(p_keys)<>1
    OR array_lower(p_scopes,1)<>1 OR array_lower(p_keys,1)<>1
    OR EXISTS(SELECT 1 FROM unnest(p_scopes,p_keys) x(s,h) WHERE s IS NULL OR h IS NULL OR h!~'^[0-9a-f]{64}$' OR NOT EXISTS(SELECT 1 FROM public.auth6b6_policy(s)))
    OR (SELECT count(*) FROM (SELECT DISTINCT s,h FROM unnest(p_scopes,p_keys) x(s,h)) z)<>cardinality(p_scopes) THEN
   RAISE EXCEPTION 'rate_budget_invalid' USING ERRCODE='22023';
 END IF;
 lock_started:=clock_timestamp();
 FOR k IN SELECT s,h FROM unnest(p_scopes,p_keys) x(s,h) ORDER BY s COLLATE "C",h COLLATE "C" LOOP
   SELECT * INTO STRICT p FROM public.auth6b6_policy(k.s);
   IF (p_mode='consume' AND p.failure) OR (p_mode IN ('check','record') AND NOT p.failure) THEN
     RAISE EXCEPTION 'rate_budget_mode_invalid' USING ERRCODE='22023';
   END IF;
   n:=clock_timestamp();
   INSERT INTO public.auth_rate_budgets(scope,key_hash,last_clock_at,gc_after)
     VALUES(k.s,k.h,n,n+make_interval(secs=>p.window_seconds)+interval '1 second') ON CONFLICT DO NOTHING;
   PERFORM 1 FROM public.auth_rate_budgets WHERE scope=k.s AND key_hash=k.h FOR UPDATE;
 END LOOP;
 n:=clock_timestamp();
 lock_ms:=extract(epoch FROM n-lock_started)*1000;
 FOR k IN SELECT s,h FROM unnest(p_scopes,p_keys) x(s,h) ORDER BY s COLLATE "C",h COLLATE "C" LOOP
   SELECT * INTO STRICT p FROM public.auth6b6_policy(k.s);
   SELECT * INTO STRICT r FROM public.auth_rate_budgets WHERE scope=k.s AND key_hash=k.h;
   IF n<r.last_clock_at THEN RAISE EXCEPTION 'rate_budget_clock_unavailable' USING ERRCODE='55000'; END IF;
   -- Malformed persisted rows не стають дозволом; write trigger також перевіряє array.
   UPDATE public.auth_rate_budgets SET events=events WHERE scope=k.s AND key_hash=k.h;
   UPDATE public.auth_rate_budgets SET last_clock_at=n,gc_after=greatest(gc_after,n+make_interval(secs=>p.window_seconds)+interval '1 second') WHERE scope=k.s AND key_hash=k.h;
   e:=public.auth6b6_events(r.events,n,k.s);
   IF r.blocked_until>n THEN denied:=true; wait_until:=greatest(wait_until,r.blocked_until); END IF;
   IF cardinality(e)>=p.cap THEN
     denied:=true; wait_until:=greatest(wait_until,e[cardinality(e)-p.cap+1]+make_interval(secs=>p.window_seconds));
   END IF;
 END LOOP;
 IF denied OR p_mode='check' THEN
   RETURN QUERY SELECT NOT denied,CASE WHEN denied THEN greatest(1,least(86400,ceil(extract(epoch FROM wait_until-n))::integer)) ELSE 0 END,lock_ms;
   RETURN;
 END IF;
 FOR k IN SELECT s,h FROM unnest(p_scopes,p_keys) x(s,h) ORDER BY s COLLATE "C",h COLLATE "C" LOOP
   SELECT * INTO STRICT p FROM public.auth6b6_policy(k.s);
   SELECT * INTO STRICT r FROM public.auth_rate_budgets WHERE scope=k.s AND key_hash=k.h;
   e:=public.auth6b6_events(r.events,n,k.s);
   e:=array_append(e,n);
   b:=CASE WHEN cardinality(e)=p.cap AND p.pause_seconds>0 THEN n+make_interval(secs=>p.pause_seconds) ELSE NULL END;
   UPDATE public.auth_rate_budgets SET events=e,blocked_until=b,last_clock_at=n,
     gc_after=greatest(n+make_interval(secs=>p.window_seconds),b)+interval '1 second'
     WHERE scope=k.s AND key_hash=k.h;
 END LOOP;
 RETURN QUERY SELECT true,0,lock_ms;
END $$;

CREATE FUNCTION public.auth6b6_cleanup(p_limit integer) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE r record; n timestamptz; total integer:=0;
BEGIN
 IF p_limit IS NULL OR p_limit NOT BETWEEN 1 AND 500 THEN RAISE EXCEPTION 'rate_budget_invalid' USING ERRCODE='22023'; END IF;
 FOR r IN SELECT scope,key_hash FROM public.auth_rate_budgets WHERE gc_after<clock_timestamp()
    ORDER BY scope COLLATE "C",key_hash COLLATE "C" LIMIT p_limit FOR UPDATE SKIP LOCKED LOOP
   n:=clock_timestamp();
   DELETE FROM public.auth_rate_budgets WHERE scope=r.scope AND key_hash=r.key_hash AND gc_after<n AND last_clock_at<=n;
   IF FOUND THEN total:=total+1; END IF;
 END LOOP;
 RETURN total;
END $$;
REVOKE ALL ON public.auth_rate_budgets FROM PUBLIC;
REVOKE ALL ON FUNCTION public.auth6b6_group(text[],text[],text),public.auth6b6_cleanup(integer),public.auth6b6_validate(),public.auth6b6_policy(text),public.auth6b6_events(timestamptz[],timestamptz,text) FROM PUBLIC;
