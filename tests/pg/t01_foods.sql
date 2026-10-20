-- TelmanFit PG integration tests: module 01 - foods <-> typed children
-- Read-only for the DB: the whole script runs in ONE transaction and ends with ROLLBACK.
BEGIN;

CREATE TEMP TABLE _r (seq serial, id text, status text, detail text) ON COMMIT DROP;
CREATE TEMP TABLE _ids (k text PRIMARY KEY, val integer) ON COMMIT DROP;

INSERT INTO uoms (code,label,kind) VALUES
 ('g','gram','mass'),('serving','serving','serving'),('piece','piece','count'),('ml','milliliter','volume');

WITH f AS (INSERT INTO foods (food_type) VALUES ('ingredient') RETURNING id)
INSERT INTO _ids(k,val) SELECT 'food_i', id FROM f;

INSERT INTO ingredients (food_id, food_type, name, kcal_per_100g, protein_per_100g, fat_per_100g, carbs_per_100g)
SELECT val,'ingredient','Chicken breast',165.00,31.00,3.60,0.00 FROM _ids WHERE k='food_i';

INSERT INTO _ids(k,val) SELECT 'ing_i', id FROM ingredients WHERE food_id=(SELECT val FROM _ids WHERE k='food_i');

INSERT INTO ingredient_uoms (ingredient_id,uom_id,grams_per_uom,is_default)
SELECT (SELECT val FROM _ids WHERE k='ing_i'), id, 120.00, true FROM uoms WHERE code='piece';

DO $t$ BEGIN
  IF (SELECT count(*) FROM ingredients WHERE food_id=(SELECT val FROM _ids WHERE k='food_i')) = 1
     AND (SELECT count(*) FROM ingredient_uoms WHERE ingredient_id=(SELECT val FROM _ids WHERE k='ing_i')) = 1 THEN
    INSERT INTO _r(id,status,detail) VALUES ('T1.0','PASS','fixture: foods+ingredients+ingredient_uoms(piece=120.00)');
  ELSE
    INSERT INTO _r(id,status,detail) VALUES ('T1.0','FAIL','fixture incomplete');
  END IF;
END $t$;

-- T1.1 deferred constraint trigger: foods row must get exactly one typed child
DO $t$ BEGIN
  BEGIN
    EXECUTE 'SET CONSTRAINTS ALL IMMEDIATE';
    INSERT INTO foods (food_type) VALUES ('ingredient');
    INSERT INTO _r(id,status,detail) VALUES ('T1.1','FAIL','foods row without typed child accepted');
  EXCEPTION WHEN OTHERS THEN
    INSERT INTO _r(id,status,detail) VALUES ('T1.1','PASS',format('%s :: %s',SQLSTATE,SQLERRM));
  END;
END $t$;

-- T1.2 wrong supertype: ingredients row pointing at a foods row of type recipe_version
DO $t$ DECLARE f integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    INSERT INTO foods (food_type) VALUES ('recipe_version') RETURNING id INTO f;
    INSERT INTO ingredients (food_id,food_type,name,kcal_per_100g,protein_per_100g,fat_per_100g,carbs_per_100g)
    VALUES (f,'ingredient','bad child',1,1,1,1);
    INSERT INTO _r(id,status,detail) VALUES ('T1.2','FAIL','food_type/child mismatch accepted ((food_id,food_type) FK bypassed)');
  EXCEPTION WHEN OTHERS THEN
    INSERT INTO _r(id,status,detail) VALUES ('T1.2','PASS',format('%s :: %s',SQLSTATE,SQLERRM));
  END;
END $t$;

-- T1.3 cannot re-point a typed child to another foods row
DO $t$ BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    UPDATE ingredients SET food_id=(SELECT val FROM _ids WHERE k='food_i')+100000
    WHERE id=(SELECT val FROM _ids WHERE k='ing_i');
    INSERT INTO _r(id,status,detail) VALUES ('T1.3','FAIL','typed-child food_id change accepted');
  EXCEPTION WHEN OTHERS THEN
    INSERT INTO _r(id,status,detail) VALUES ('T1.3','PASS',format('%s :: %s',SQLSTATE,SQLERRM));
  END;
END $t$;

-- T1.4 cannot delete a typed child while its foods parent exists
DO $t$ BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    DELETE FROM ingredients WHERE id=(SELECT val FROM _ids WHERE k='ing_i');
    INSERT INTO _r(id,status,detail) VALUES ('T1.4','FAIL','typed child deleted, orphan foods row left');
  EXCEPTION WHEN OTHERS THEN
    INSERT INTO _r(id,status,detail) VALUES ('T1.4','PASS',format('%s :: %s',SQLSTATE,SQLERRM));
  END;
END $t$;

-- T1.5 cannot delete foods parent while typed child exists (FK RESTRICT)
DO $t$ BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    DELETE FROM foods WHERE id=(SELECT val FROM _ids WHERE k='food_i');
    INSERT INTO _r(id,status,detail) VALUES ('T1.5','FAIL','foods parent deleted with child present');
  EXCEPTION WHEN OTHERS THEN
    INSERT INTO _r(id,status,detail) VALUES ('T1.5','PASS',format('%s :: %s',SQLSTATE,SQLERRM));
  END;
END $t$;

-- T1.6 partial unique index ingredient_uoms_one_default
DO $t$ DECLARE v integer; BEGIN
  BEGIN
    INSERT INTO ingredient_uoms (ingredient_id,uom_id,grams_per_uom,is_default)
    SELECT (SELECT val FROM _ids WHERE k='ing_i'), id, 100.00, true FROM uoms WHERE code='serving';
    INSERT INTO _r(id,status,detail) VALUES ('T1.6','FAIL','two is_default ingredient_uoms rows accepted');
  EXCEPTION WHEN OTHERS THEN
    INSERT INTO _r(id,status,detail) VALUES ('T1.6','PASS',format('%s :: %s',SQLSTATE,SQLERRM));
  END;
END $t$;

-- T1.7 negative nutrition values rejected
DO $t$ DECLARE f integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    INSERT INTO foods (food_type) VALUES ('ingredient') RETURNING id INTO f;
    INSERT INTO ingredients (food_id,food_type,name,kcal_per_100g,protein_per_100g,fat_per_100g,carbs_per_100g)
    VALUES (f,'ingredient','negative kcal',-1,1,1,1);
    INSERT INTO _r(id,status,detail) VALUES ('T1.7','FAIL','negative kcal_per_100g accepted');
  EXCEPTION WHEN OTHERS THEN
    INSERT INTO _r(id,status,detail) VALUES ('T1.7','PASS',format('%s :: %s',SQLSTATE,SQLERRM));
  END;
END $t$;

-- T1.8 grams_per_uom must be > 0
DO $t$ BEGIN
  BEGIN
    INSERT INTO ingredient_uoms (ingredient_id,uom_id,grams_per_uom)
    SELECT (SELECT val FROM _ids WHERE k='ing_i'), id, 0 FROM uoms WHERE code='ml';
    INSERT INTO _r(id,status,detail) VALUES ('T1.8','FAIL','grams_per_uom = 0 accepted');
  EXCEPTION WHEN OTHERS THEN
    INSERT INTO _r(id,status,detail) VALUES ('T1.8','PASS',format('%s :: %s',SQLSTATE,SQLERRM));
  END;
END $t$;

\echo '--- t01 results ---'
SELECT seq, id, status, detail FROM _r ORDER BY seq;
SELECT status, count(*) AS n FROM _r GROUP BY status ORDER BY 1;
ROLLBACK;
