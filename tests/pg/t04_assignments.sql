-- TelmanFit PG integration tests: client_meal_plan_assignments (EXCLUDE daterange) + module 02/03/04 basics.
-- ONE transaction, ends with ROLLBACK: nothing is persisted.
BEGIN;

CREATE TEMP TABLE _r (seq serial, id text, status text, detail text) ON COMMIT DROP;
CREATE TEMP TABLE _ids (k text PRIMARY KEY, val integer) ON COMMIT DROP;
CREATE FUNCTION pg_temp.rec(p_id text, p_status text, p_detail text) RETURNS void
LANGUAGE sql AS $h$ INSERT INTO _r(id,status,detail) VALUES (p_id,p_status,p_detail) $h$;

INSERT INTO uoms (code,label,kind) VALUES
 ('g','gram','mass'),('serving','serving','serving'),('piece','piece','count'),('ml','milliliter','volume');
INSERT INTO meal_types (code,title,sort_order) VALUES ('breakfast','Breakfast',1);

WITH f AS (INSERT INTO foods (food_type) VALUES ('ingredient') RETURNING id)
INSERT INTO _ids(k,val) SELECT 'food_i', id FROM f;
INSERT INTO ingredients (food_id,food_type,name,kcal_per_100g,protein_per_100g,fat_per_100g,carbs_per_100g)
SELECT val,'ingredient','Rice',130.00,2.70,0.30,28.00 FROM _ids WHERE k='food_i';

CREATE FUNCTION pg_temp.build_plan_version(p_publish boolean, p_number integer) RETURNS integer
LANGUAGE plpgsql AS $h$
DECLARE vp integer; vver integer; dd integer; mm integer; mt integer; i integer;
BEGIN
  INSERT INTO meal_plans (title) VALUES ('Assignable plan') RETURNING id INTO vp;
  INSERT INTO meal_plan_versions (meal_plan_id) VALUES (vp) RETURNING id INTO vver;
  SELECT id INTO mt FROM meal_types WHERE code='breakfast';
  FOR i IN 1..7 LOOP
    INSERT INTO meal_plan_days (meal_plan_version_id,day_number) VALUES (vver,i) RETURNING id INTO dd;
    INSERT INTO meals (meal_plan_day_id,meal_type_id) VALUES (dd,mt) RETURNING id INTO mm;
    INSERT INTO meal_items (meal_id,food_id,quantity,uom_id,resolved_quantity_grams)
    SELECT mm,(SELECT val FROM _ids WHERE k='food_i'),100,u.id,100 FROM uoms u WHERE u.code='g';
  END LOOP;
  IF p_publish THEN
    UPDATE meal_plan_versions SET status='published',version_number=p_number,published_at=now() WHERE id=vver;
  END IF;
  RETURN vver;
END $h$;

WITH u AS (INSERT INTO users (name,role) VALUES ('Coach One','coach') RETURNING id)
INSERT INTO _ids(k,val) SELECT 'coach', id FROM u;
WITH u AS (INSERT INTO users (name,role) VALUES ('Client One','client') RETURNING id)
INSERT INTO _ids(k,val) SELECT 'user1', id FROM u;
WITH u AS (INSERT INTO users (name,role) VALUES ('Client Two','client') RETURNING id)
INSERT INTO _ids(k,val) SELECT 'user2', id FROM u;
WITH c AS (INSERT INTO clients (user_id,coach_user_id)
  VALUES ((SELECT val FROM _ids WHERE k='user1'),(SELECT val FROM _ids WHERE k='coach')) RETURNING id) INSERT INTO _ids(k,val) SELECT 'client1', c.id FROM c;
WITH c AS (INSERT INTO clients (user_id,coach_user_id)
  VALUES ((SELECT val FROM _ids WHERE k='user2'),(SELECT val FROM _ids WHERE k='coach')) RETURNING id) INSERT INTO _ids(k,val) SELECT 'client2', c.id FROM c;

INSERT INTO _ids(k,val) SELECT 'ver_pub', pg_temp.build_plan_version(true,1);
INSERT INTO _ids(k,val) SELECT 'ver_draft', pg_temp.build_plan_version(false,1);
-- T4.1 positive: assign a published plan version to client1
DO $t$ BEGIN BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  INSERT INTO client_meal_plan_assignments (client_id,meal_plan_version_id,start_date,end_date,created_by)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),(SELECT val FROM _ids WHERE k='ver_pub'),DATE '2026-01-01',DATE '2026-01-31',(SELECT val FROM _ids WHERE k='coach'));
  PERFORM pg_temp.rec('T4.1','PASS','published version assigned to client1 for 2026-01-01..2026-01-31');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T4.1','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T4.2 overlapping dates for the same client must be rejected (EXCLUDE ... USING gist)
DO $t$ BEGIN BEGIN
  EXECUTE 'SET CONSTRAINTS ALL IMMEDIATE';
  INSERT INTO client_meal_plan_assignments (client_id,meal_plan_version_id,start_date,end_date)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),(SELECT val FROM _ids WHERE k='ver_pub'),DATE '2026-01-15',DATE '2026-02-14');
  PERFORM pg_temp.rec('T4.2','FAIL','overlapping assignment accepted for the same client');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T4.2','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T4.3 positive: adjacent (non-overlapping) range for the same client must be accepted
DO $t$ BEGIN BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  INSERT INTO client_meal_plan_assignments (client_id,meal_plan_version_id,start_date,end_date)
  VALUES ((SELECT val FROM _ids WHERE k='client1'),(SELECT val FROM _ids WHERE k='ver_pub'),DATE '2026-02-01',DATE '2026-02-28');
  PERFORM pg_temp.rec('T4.3','PASS','adjacent range 2026-02-01..2026-02-28 accepted (daterange [] bounds)');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T4.3','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T4.4 positive: the same dates for ANOTHER client must be accepted
DO $t$ BEGIN BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  INSERT INTO client_meal_plan_assignments (client_id,meal_plan_version_id,start_date,end_date)
  VALUES ((SELECT val FROM _ids WHERE k='client2'),(SELECT val FROM _ids WHERE k='ver_pub'),DATE '2026-01-15',DATE '2026-02-14');
  PERFORM pg_temp.rec('T4.4','PASS','overlapping dates accepted for a different client (exclusion is per client_id)');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T4.4','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T4.5 end_date < start_date must be rejected
DO $t$ BEGIN BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  INSERT INTO client_meal_plan_assignments (client_id,meal_plan_version_id,start_date,end_date)
  VALUES ((SELECT val FROM _ids WHERE k='client2'),(SELECT val FROM _ids WHERE k='ver_pub'),DATE '2026-03-10',DATE '2026-03-01');
  PERFORM pg_temp.rec('T4.5','FAIL','end_date < start_date accepted');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T4.5','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;
-- T4.6 two open-ended assignments for one client must conflict (unbounded daterange)
DO $t$ BEGIN
  EXECUTE 'SET CONSTRAINTS ALL IMMEDIATE';
  BEGIN
    INSERT INTO client_meal_plan_assignments (client_id,meal_plan_version_id,start_date,end_date)
    VALUES ((SELECT val FROM _ids WHERE k='client2'),(SELECT val FROM _ids WHERE k='ver_pub'),DATE '2026-04-01',NULL);
  EXCEPTION WHEN OTHERS THEN
    PERFORM pg_temp.rec('T4.6','FAIL',format('first open-ended assignment rejected: %s :: %s',SQLSTATE,SQLERRM)); RETURN;
  END;
  BEGIN
    INSERT INTO client_meal_plan_assignments (client_id,meal_plan_version_id,start_date,end_date)
    VALUES ((SELECT val FROM _ids WHERE k='client2'),(SELECT val FROM _ids WHERE k='ver_pub'),DATE '2026-05-01',NULL);
    PERFORM pg_temp.rec('T4.6','FAIL','two open-ended assignments accepted for the same client');
  EXCEPTION WHEN OTHERS THEN
    PERFORM pg_temp.rec('T4.6','PASS',format('%s :: %s',SQLSTATE,SQLERRM));
  END;
END $t$;

-- T4.7 GAP probe: assignment of a DRAFT version
DO $t$ BEGIN BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  INSERT INTO client_meal_plan_assignments (client_id,meal_plan_version_id,start_date,end_date)
  VALUES ((SELECT val FROM _ids WHERE k='client2'),(SELECT val FROM _ids WHERE k='ver_draft'),DATE '2026-06-01',DATE '2026-06-30');
  PERFORM pg_temp.rec('T4.7','GAP','DB accepted assignment of a DRAFT meal_plan_version (published-only is a service rule, not DDL)');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T4.7','PASS',format('published-only enforced: %s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T4.8 DEFERRABLE INITIALLY IMMEDIATE: overlap detected when constraints are set immediate
DO $t$ BEGIN
  BEGIN
    EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
    INSERT INTO client_meal_plan_assignments (client_id,meal_plan_version_id,start_date,end_date)
    VALUES ((SELECT val FROM _ids WHERE k='client2'),(SELECT val FROM _ids WHERE k='ver_pub'),DATE '2026-07-01',DATE '2026-07-31');
    INSERT INTO client_meal_plan_assignments (client_id,meal_plan_version_id,start_date,end_date)
    VALUES ((SELECT val FROM _ids WHERE k='client2'),(SELECT val FROM _ids WHERE k='ver_pub'),DATE '2026-07-15',DATE '2026-08-15');
    EXECUTE 'SET CONSTRAINTS ALL IMMEDIATE';
    PERFORM pg_temp.rec('T4.8','FAIL','deferred exclusion did not fire on SET CONSTRAINTS IMMEDIATE');
  EXCEPTION WHEN OTHERS THEN
    PERFORM pg_temp.rec('T4.8','PASS',format('deferred check fired: %s :: %s',SQLSTATE,SQLERRM));
  END;
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
END $t$;

-- T4.9 assignment for a nonexistent client must fail (FK RESTRICT)
DO $t$ BEGIN BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  INSERT INTO client_meal_plan_assignments (client_id,meal_plan_version_id,start_date,end_date)
  VALUES (999999,(SELECT val FROM _ids WHERE k='ver_pub'),DATE '2026-09-01',DATE '2026-09-30');
  PERFORM pg_temp.rec('T4.9','FAIL','assignment accepted for a nonexistent client');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T4.9','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T4.10 positive: replacement chain via replaced_by_assignment_id
DO $t$ DECLARE a1 integer; a2 integer; BEGIN
  BEGIN
    SELECT id INTO a1 FROM client_meal_plan_assignments
    WHERE client_id=(SELECT val FROM _ids WHERE k='client1') AND start_date=DATE '2026-01-01';
    SELECT id INTO a2 FROM client_meal_plan_assignments
    WHERE client_id=(SELECT val FROM _ids WHERE k='client1') AND start_date=DATE '2026-02-01';
    UPDATE client_meal_plan_assignments SET replaced_by_assignment_id=a2 WHERE id=a1;
    IF (SELECT replaced_by_assignment_id FROM client_meal_plan_assignments WHERE id=a1)=a2
    THEN PERFORM pg_temp.rec('T4.10','PASS','replacement chain linked (earlier assignment -> replaced_by_assignment_id)');
    ELSE PERFORM pg_temp.rec('T4.10','FAIL','replacement chain not stored'); END IF;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T4.10','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T4.11 self-reference in replaced_by_assignment_id must be rejected by the CHECK constraint (SQLSTATE 23514)
DO $t$ DECLARE a integer; BEGIN
  BEGIN
    SELECT id INTO a FROM client_meal_plan_assignments WHERE client_id=(SELECT val FROM _ids WHERE k='client2') ORDER BY id LIMIT 1;
    UPDATE client_meal_plan_assignments SET replaced_by_assignment_id=a WHERE id=a;
    PERFORM pg_temp.rec('T4.11','FAIL','DB accepted replaced_by_assignment_id = own id');
  EXCEPTION WHEN OTHERS THEN
    IF SQLSTATE = '23514' THEN
      PERFORM pg_temp.rec('T4.11','PASS',format('self-reference rejected by CHECK (23514) :: %s',SQLERRM));
    ELSE
      PERFORM pg_temp.rec('T4.11','FAIL',format('rejected for the wrong reason: %s :: %s',SQLSTATE,SQLERRM));
    END IF;
  END;
END $t$;

-- T4.12 NULL remains allowed after the self-reference attempt
DO $t$ DECLARE a integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    SELECT id INTO a FROM client_meal_plan_assignments
     WHERE client_id=(SELECT val FROM _ids WHERE k='client1') AND start_date=DATE '2026-01-01';
    UPDATE client_meal_plan_assignments SET replaced_by_assignment_id=NULL WHERE id=a;
    IF (SELECT replaced_by_assignment_id FROM client_meal_plan_assignments WHERE id=a) IS NULL THEN
      PERFORM pg_temp.rec('T4.12','PASS','replaced_by_assignment_id = NULL accepted (CHECK allows NULL)');
    ELSE
      PERFORM pg_temp.rec('T4.12','FAIL','NULL was not stored');
    END IF;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T4.12','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

\echo '--- t04 results (assignments) ---'
SELECT seq, id, status, detail FROM _r ORDER BY seq;
SELECT status, count(*) AS n FROM _r GROUP BY status ORDER BY 1;
ROLLBACK;
