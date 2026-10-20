-- TelmanFit PG integration tests: modules 03 (progress) and 04 (messaging) - compact.
-- ONE transaction, ends with ROLLBACK: nothing is persisted.
BEGIN;

CREATE TEMP TABLE _r (seq serial, id text, status text, detail text) ON COMMIT DROP;
CREATE TEMP TABLE _ids (k text PRIMARY KEY, val integer) ON COMMIT DROP;
CREATE FUNCTION pg_temp.rec(p_id text, p_status text, p_detail text) RETURNS void
LANGUAGE sql AS $h$ INSERT INTO _r(id,status,detail) VALUES (p_id,p_status,p_detail) $h$;

WITH u AS (INSERT INTO users (name,role) VALUES ('Coach One','coach') RETURNING id)
INSERT INTO _ids(k,val) SELECT 'coach', id FROM u;
WITH u AS (INSERT INTO users (name,role) VALUES ('Client One','client') RETURNING id)
INSERT INTO _ids(k,val) SELECT 'user1', id FROM u;
WITH c AS (INSERT INTO clients (user_id,coach_user_id)
  VALUES ((SELECT val FROM _ids WHERE k='user1'),(SELECT val FROM _ids WHERE k='coach')) RETURNING id) INSERT INTO _ids(k,val) SELECT 'client1', c.id FROM c;
WITH m AS (INSERT INTO metrics (code,name,unit_label,min_value,max_value)
  VALUES ('weight','Weight','kg',10.00,200.00) RETURNING id) INSERT INTO _ids(k,val) SELECT 'metric_w', m.id FROM m;

-- T5.1 positive: weekly report starting on Monday
DO $t$ BEGIN BEGIN
  INSERT INTO client_weekly_reports (client_id,week_start_date)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),DATE '2026-01-05');
  PERFORM pg_temp.rec('T5.1','PASS','weekly report accepted for Monday 2026-01-05');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.1','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T5.2 report starting on Tuesday must be rejected (EXTRACT(ISODOW)=1)
DO $t$ BEGIN BEGIN
  INSERT INTO client_weekly_reports (client_id,week_start_date)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),DATE '2026-01-06');
  PERFORM pg_temp.rec('T5.2','FAIL','weekly report accepted for Tuesday 2026-01-06');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.2','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T5.3 duplicate week for the same client must be rejected
DO $t$ BEGIN BEGIN
  INSERT INTO client_weekly_reports (client_id,week_start_date)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),DATE '2026-01-05');
  PERFORM pg_temp.rec('T5.3','FAIL','duplicate (client_id, week_start_date) accepted');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.3','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T5.4 status='submitted' without submitted_at must be rejected
DO $t$ BEGIN BEGIN
  INSERT INTO client_weekly_reports (client_id,week_start_date,status)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),DATE '2026-01-12','submitted');
  PERFORM pg_temp.rec('T5.4','FAIL','submitted report accepted without submitted_at');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.4','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T5.5 draft with submitted_at set must be rejected
DO $t$ BEGIN BEGIN
  INSERT INTO client_weekly_reports (client_id,week_start_date,status,submitted_at)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),DATE '2026-01-19','draft',now());
  PERFORM pg_temp.rec('T5.5','FAIL','draft report accepted with submitted_at');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.5','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;
-- T5.6 positive: metric row linked to a submitted weekly report of the same client
DO $t$ DECLARE rep integer; BEGIN
  BEGIN
    INSERT INTO client_weekly_reports (client_id,week_start_date,status,submitted_at)
    VALUES ((SELECT val FROM _ids WHERE k='client1'),DATE '2026-01-12','submitted',now()) RETURNING id INTO rep;
    INSERT INTO _ids(k,val) SELECT 'report1', rep;
    INSERT INTO client_metrics (client_id,metric_id,value,measured_at,source,weekly_report_id)
    VALUES ((SELECT val FROM _ids WHERE k='client1'),(SELECT val FROM _ids WHERE k='metric_w'),80.50,now(),'weekly_report',rep);
    PERFORM pg_temp.rec('T5.6','PASS','client_metrics linked to submitted weekly report of the same client');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.6','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T5.7 source='weekly_report' without weekly_report_id must be rejected
DO $t$ BEGIN BEGIN
  INSERT INTO client_metrics (client_id,metric_id,value,measured_at,source)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),(SELECT val FROM _ids WHERE k='metric_w'),80.00,now(),'weekly_report');
  PERFORM pg_temp.rec('T5.7','FAIL','source=weekly_report accepted without weekly_report_id');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.7','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T5.8 metric pointing at another client's report must be rejected (FK (weekly_report_id, client_id))
DO $t$ DECLARE u2 integer; c2 integer; rep2 integer; BEGIN
  BEGIN
    INSERT INTO users (name,role) VALUES ('Client Two','client') RETURNING id INTO u2;
    INSERT INTO clients (user_id,coach_user_id) VALUES (u2,(SELECT val FROM _ids WHERE k='coach')) RETURNING id INTO c2;
    INSERT INTO client_weekly_reports (client_id,week_start_date,status,submitted_at)
    VALUES (c2,DATE '2026-01-12','submitted',now()) RETURNING id INTO rep2;
    INSERT INTO client_metrics (client_id,metric_id,value,measured_at,source,weekly_report_id)
    VALUES ((SELECT val FROM _ids WHERE k='client1'),(SELECT val FROM _ids WHERE k='metric_w'),80.00,now(),'weekly_report',rep2);
    PERFORM pg_temp.rec('T5.8','FAIL','metric of client A accepted with report of client B');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.8','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T5.9 architectural decision (GAP-3 closed): [min_value, max_value] is an application-level rule
-- (Laravel API + React, inclusive bounds), NOT a PostgreSQL constraint. The DB must ACCEPT out-of-range
-- values; if a DB constraint appears later, this test fails and the decision must be revisited (BR-T-02).
DO $t$ BEGIN BEGIN
  INSERT INTO client_metrics (client_id,metric_id,value,measured_at,source)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),(SELECT val FROM _ids WHERE k='metric_w'),500.00,now(),'manual');
  IF (SELECT value FROM client_metrics WHERE client_id=(SELECT val FROM _ids WHERE k='client1')
        AND metric_id=(SELECT val FROM _ids WHERE k='metric_w') AND value=500.00) = 500.00 THEN
    PERFORM pg_temp.rec('T5.9','PASS','DB accepts 500.00 while metrics.max_value=200.00 - range is an app-level rule (Laravel API + React), BR-T-02');
  ELSE
    PERFORM pg_temp.rec('T5.9','FAIL','out-of-range value was not stored as inserted');
  END IF;
EXCEPTION WHEN OTHERS THEN
  PERFORM pg_temp.rec('T5.9','FAIL',format('DB rejected an out-of-range value - architectural decision changed? %s :: %s',SQLSTATE,SQLERRM));
END; END $t$;

-- T5.10 partial unique index client_metric_one_current_assignment
DO $t$ BEGIN
  BEGIN
    INSERT INTO client_metric_assignments (client_id,metric_id,created_by)
    VALUES ((SELECT val FROM _ids WHERE k='client1'),(SELECT val FROM _ids WHERE k='metric_w'),(SELECT val FROM _ids WHERE k='coach'));
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.10','FAIL',format('first current assignment blocked: %s :: %s',SQLSTATE,SQLERRM)); RETURN; END;
  BEGIN
    INSERT INTO client_metric_assignments (client_id,metric_id,created_by)
    VALUES ((SELECT val FROM _ids WHERE k='client1'),(SELECT val FROM _ids WHERE k='metric_w'),(SELECT val FROM _ids WHERE k='coach'));
    PERFORM pg_temp.rec('T5.10','FAIL','second current assignment accepted for the same (client, metric)');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.10','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T5.11 positive: after unassigned_at is set, a new assignment is allowed
DO $t$ BEGIN BEGIN
  UPDATE client_metric_assignments SET unassigned_at=now()
  WHERE client_id=(SELECT val FROM _ids WHERE k='client1') AND metric_id=(SELECT val FROM _ids WHERE k='metric_w') AND unassigned_at IS NULL;
  INSERT INTO client_metric_assignments (client_id,metric_id,created_by)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),(SELECT val FROM _ids WHERE k='metric_w'),(SELECT val FROM _ids WHERE k='coach'));
  PERFORM pg_temp.rec('T5.11','PASS','new assignment accepted after the previous one was unassigned');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.11','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T5.12 status='sent' without sent_at must be rejected
DO $t$ BEGIN BEGIN
  INSERT INTO outbound_messages (client_id,rendered_text,status,scheduled_at)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),'hello','sent',now());
  PERFORM pg_temp.rec('T5.12','FAIL','status=sent accepted with sent_at IS NULL');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.12','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T5.13 sent_at set while status='pending' must be rejected
DO $t$ BEGIN BEGIN
  INSERT INTO outbound_messages (client_id,rendered_text,status,scheduled_at,sent_at)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),'hello','pending',now(),now());
  PERFORM pg_temp.rec('T5.13','FAIL','sent_at accepted with status=pending');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.13','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T5.14 blank rendered_text must be rejected
DO $t$ BEGIN BEGIN
  INSERT INTO outbound_messages (client_id,rendered_text,status,scheduled_at)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),'   ','pending',now());
  PERFORM pg_temp.rec('T5.14','FAIL','blank rendered_text accepted');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.14','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T5.15 bounds edits in the metrics dictionary do not rewrite historical measurements (BR-T-02, decision rule 5)
DO $t$ DECLARE before_val numeric; after_val numeric; new_max numeric; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    SELECT value INTO before_val FROM client_metrics
     WHERE client_id=(SELECT val FROM _ids WHERE k='client1')
       AND metric_id=(SELECT val FROM _ids WHERE k='metric_w') AND value=500.00;
    UPDATE metrics SET min_value=0.00, max_value=300.00 WHERE id=(SELECT val FROM _ids WHERE k='metric_w');
    SELECT value INTO after_val FROM client_metrics
     WHERE client_id=(SELECT val FROM _ids WHERE k='client1')
       AND metric_id=(SELECT val FROM _ids WHERE k='metric_w') AND value=500.00;
    SELECT max_value INTO new_max FROM metrics WHERE id=(SELECT val FROM _ids WHERE k='metric_w');
    IF before_val = after_val AND new_max = 300.00 THEN
      PERFORM pg_temp.rec('T5.15','PASS',format('bounds changed to [0.00, 300.00] without touching history; measure %s kept',after_val));
    ELSE
      PERFORM pg_temp.rec('T5.15','FAIL',format('history or bounds mismatch (value %s -> %s, max_value %s)',before_val,after_val,new_max));
    END IF;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T5.15','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

\echo '--- t05 results (progress + messaging) ---'
SELECT seq, id, status, detail FROM _r ORDER BY seq;
SELECT status, count(*) AS n FROM _r GROUP BY status ORDER BY 1;
ROLLBACK;
