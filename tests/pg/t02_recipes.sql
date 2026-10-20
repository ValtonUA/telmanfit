-- TelmanFit PG integration tests: module 01 - recipe_versions lifecycle.
-- ONE transaction, ends with ROLLBACK: nothing is persisted.
BEGIN;

CREATE TEMP TABLE _r (seq serial, id text, status text, detail text) ON COMMIT DROP;
CREATE TEMP TABLE _ids (k text PRIMARY KEY, val integer) ON COMMIT DROP;
CREATE FUNCTION pg_temp.rec(p_id text, p_status text, p_detail text) RETURNS void
LANGUAGE sql AS $h$ INSERT INTO _r(id,status,detail) VALUES (p_id,p_status,p_detail) $h$;
CREATE FUNCTION pg_temp.new_draft(p_recipe integer, p_title text) RETURNS integer LANGUAGE plpgsql AS $h$
DECLARE v integer; BEGIN INSERT INTO recipe_versions (recipe_id,title) VALUES (p_recipe,p_title) RETURNING id INTO v; RETURN v; END $h$;

INSERT INTO uoms (code,label,kind) VALUES
 ('g','gram','mass'),('serving','serving','serving'),('piece','piece','count'),('ml','milliliter','volume');

WITH f AS (INSERT INTO foods (food_type) VALUES ('ingredient') RETURNING id)
INSERT INTO _ids(k,val) SELECT 'food_i', id FROM f;
INSERT INTO ingredients (food_id,food_type,name,kcal_per_100g,protein_per_100g,fat_per_100g,carbs_per_100g)
SELECT val,'ingredient','Chicken breast',165.00,31.00,3.60,0.00 FROM _ids WHERE k='food_i';
INSERT INTO _ids(k,val) SELECT 'ing_i', id FROM ingredients WHERE food_id=(SELECT val FROM _ids WHERE k='food_i');
INSERT INTO ingredient_uoms (ingredient_id,uom_id,grams_per_uom,is_default)
SELECT (SELECT val FROM _ids WHERE k='ing_i'), id, 120.00, true FROM uoms WHERE code='piece';

WITH r AS (INSERT INTO recipes (title) VALUES ('Recipe A') RETURNING id) INSERT INTO _ids(k,val) SELECT 'recipe_a', r.id FROM r;
WITH r AS (INSERT INTO recipes (title) VALUES ('Recipe B') RETURNING id) INSERT INTO _ids(k,val) SELECT 'recipe_b', r.id FROM r;
WITH r AS (INSERT INTO recipes (title) VALUES ('Recipe C') RETURNING id) INSERT INTO _ids(k,val) SELECT 'recipe_c', r.id FROM r;

INSERT INTO _ids(k,val) SELECT 'ver1', pg_temp.new_draft((SELECT val FROM _ids WHERE k='recipe_a'),'A v1');
INSERT INTO recipe_components (recipe_version_id,title,sort_order) VALUES ((SELECT val FROM _ids WHERE k='ver1'),'Main',1);
INSERT INTO _ids(k,val) SELECT 'comp1', c.id FROM recipe_components c WHERE c.recipe_version_id=(SELECT val FROM _ids WHERE k='ver1');
INSERT INTO recipe_ingredients (recipe_version_id,ingredient_id,component_id,quantity,uom_id,quantity_grams)
SELECT (SELECT val FROM _ids WHERE k='ver1'),(SELECT val FROM _ids WHERE k='ing_i'),(SELECT val FROM _ids WHERE k='comp1'),0.25,u.id,30.00
FROM uoms u WHERE u.code='piece';
INSERT INTO recipe_directions (recipe_version_id,direction_number,direction_text)
SELECT (SELECT val FROM _ids WHERE k='ver1'),1,'Mix and cook';

DO $t$ BEGIN
  IF (SELECT count(*) FROM recipe_versions WHERE id=(SELECT val FROM _ids WHERE k='ver1') AND status='draft' AND food_id IS NULL)=1
     AND (SELECT count(*) FROM recipe_ingredients WHERE recipe_version_id=(SELECT val FROM _ids WHERE k='ver1'))=1
  THEN PERFORM pg_temp.rec('T2.1','PASS','draft fixture ok: status=draft, food_id NULL, 0.25 piece (=30.00 g)');
  ELSE PERFORM pg_temp.rec('T2.1','FAIL','draft fixture unexpected'); END IF;
END $t$;
-- T2.2 positive: publish draft via validated UPDATE
DO $t$ DECLARE f integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    INSERT INTO foods (food_type) VALUES ('recipe_version') RETURNING id INTO f;
    UPDATE recipe_versions SET status='published',food_id=f,version_number=1,published_at=now(),
      kcal_per_serving=250,protein_per_serving=20,fat_per_serving=8,carbs_per_serving=30
    WHERE id=(SELECT val FROM _ids WHERE k='ver1');
    INSERT INTO _ids(k,val) SELECT 'food_r1', f;
    PERFORM pg_temp.rec('T2.2','PASS','draft published via UPDATE (foods recipe_version row + macros)');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.2','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T2.3 direct INSERT with status='published' must fail
DO $t$ DECLARE f integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    INSERT INTO foods (food_type) VALUES ('recipe_version') RETURNING id INTO f;
    INSERT INTO recipe_versions (recipe_id,title,status,food_id,version_number,published_at,
      kcal_per_serving,protein_per_serving,fat_per_serving,carbs_per_serving)
    VALUES ((SELECT val FROM _ids WHERE k='recipe_b'),'direct','published',f,1,now(),1,1,1,1);
    PERFORM pg_temp.rec('T2.3','FAIL','direct INSERT of published recipe_version accepted');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.3','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T2.4 publish without ingredients must fail
DO $t$ DECLARE v integer; f integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := pg_temp.new_draft((SELECT val FROM _ids WHERE k='recipe_b'),'B empty');
    INSERT INTO foods (food_type) VALUES ('recipe_version') RETURNING id INTO f;
    UPDATE recipe_versions SET status='published',food_id=f,version_number=1,published_at=now(),
      kcal_per_serving=1,protein_per_serving=1,fat_per_serving=1,carbs_per_serving=1 WHERE id=v;
    PERFORM pg_temp.rec('T2.4','FAIL','published recipe without ingredients accepted');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.4','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T2.5 quantity_grams must reconcile with quantity * grams_per_uom
DO $t$ DECLARE v integer; f integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := pg_temp.new_draft((SELECT val FROM _ids WHERE k='recipe_b'),'B grams');
    INSERT INTO recipe_ingredients (recipe_version_id,ingredient_id,quantity,uom_id,quantity_grams)
    SELECT v,(SELECT val FROM _ids WHERE k='ing_i'),0.25,u.id,31.00 FROM uoms u WHERE u.code='piece';
    INSERT INTO foods (food_type) VALUES ('recipe_version') RETURNING id INTO f;
    UPDATE recipe_versions SET status='published',food_id=f,version_number=1,published_at=now(),
      kcal_per_serving=1,protein_per_serving=1,fat_per_serving=1,carbs_per_serving=1 WHERE id=v;
    PERFORM pg_temp.rec('T2.5','FAIL','published 0.25 piece = 31.00 g (expected 30.00)');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.5','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T2.6 unit not permitted for the ingredient: rejected on INSERT
DO $t$ DECLARE v integer; BEGIN
  BEGIN
    v := pg_temp.new_draft((SELECT val FROM _ids WHERE k='recipe_b'),'B ml');
    INSERT INTO recipe_ingredients (recipe_version_id,ingredient_id,quantity,uom_id,quantity_grams)
    SELECT v,(SELECT val FROM _ids WHERE k='ing_i'),10,u.id,10 FROM uoms u WHERE u.code='ml';
    PERFORM pg_temp.rec('T2.6','FAIL','recipe_ingredients accepted uom=ml without ingredient_uoms row');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.6','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T2.7 allowed unit removed after drafting -> still caught at publication
DO $t$ DECLARE v integer; f integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := pg_temp.new_draft((SELECT val FROM _ids WHERE k='recipe_b'),'B unit removed');
    INSERT INTO recipe_ingredients (recipe_version_id,ingredient_id,quantity,uom_id,quantity_grams)
    SELECT v,(SELECT val FROM _ids WHERE k='ing_i'),0.25,u.id,30.00 FROM uoms u WHERE u.code='piece';
    DELETE FROM ingredient_uoms WHERE ingredient_id=(SELECT val FROM _ids WHERE k='ing_i')
      AND uom_id=(SELECT id FROM uoms WHERE code='piece');
    INSERT INTO foods (food_type) VALUES ('recipe_version') RETURNING id INTO f;
    UPDATE recipe_versions SET status='published',food_id=f,version_number=1,published_at=now(),
      kcal_per_serving=1,protein_per_serving=1,fat_per_serving=1,carbs_per_serving=1 WHERE id=v;
    PERFORM pg_temp.rec('T2.7','FAIL','published although ingredient unit row was deleted after drafting');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.7','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T2.8 publishing without macros must fail (status CHECK)
DO $t$ DECLARE v integer; f integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := pg_temp.new_draft((SELECT val FROM _ids WHERE k='recipe_b'),'B no macros');
    INSERT INTO recipe_ingredients (recipe_version_id,ingredient_id,quantity,uom_id,quantity_grams)
    SELECT v,(SELECT val FROM _ids WHERE k='ing_i'),0.25,u.id,30.00 FROM uoms u WHERE u.code='piece';
    INSERT INTO foods (food_type) VALUES ('recipe_version') RETURNING id INTO f;
    UPDATE recipe_versions SET status='published',food_id=f,version_number=1,published_at=now() WHERE id=v;
    PERFORM pg_temp.rec('T2.8','FAIL','published without kcal/protein/fat/carbs per serving');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.8','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;
-- T2.9 published recipe_version UPDATE must fail
DO $t$ BEGIN BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  UPDATE recipe_versions SET title='changed' WHERE id=(SELECT val FROM _ids WHERE k='ver1');
  PERFORM pg_temp.rec('T2.9','FAIL','published recipe_version UPDATE accepted');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.9','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T2.10 UPDATE of recipe_ingredients of published version must fail
DO $t$ BEGIN BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  UPDATE recipe_ingredients SET quantity=1 WHERE recipe_version_id=(SELECT val FROM _ids WHERE k='ver1');
  PERFORM pg_temp.rec('T2.10','FAIL','UPDATE of published recipe_ingredients accepted');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.10','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T2.11 INSERT into recipe_ingredients of published version must fail
DO $t$ BEGIN BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  INSERT INTO recipe_ingredients (recipe_version_id,ingredient_id,quantity,uom_id,quantity_grams)
  SELECT (SELECT val FROM _ids WHERE k='ver1'),(SELECT val FROM _ids WHERE k='ing_i'),1,u.id,1 FROM uoms u WHERE u.code='g';
  PERFORM pg_temp.rec('T2.11','FAIL','INSERT into published recipe_ingredients accepted');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.11','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T2.12 DELETE recipe_directions of published version must fail
DO $t$ BEGIN BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  DELETE FROM recipe_directions WHERE recipe_version_id=(SELECT val FROM _ids WHERE k='ver1');
  PERFORM pg_temp.rec('T2.12','FAIL','DELETE of published recipe_directions accepted');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.12','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;

-- T2.13 DELETE published recipe_version must fail
DO $t$ BEGIN BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  DELETE FROM recipe_versions WHERE id=(SELECT val FROM _ids WHERE k='ver1');
  PERFORM pg_temp.rec('T2.13','FAIL','DELETE of published recipe_version accepted');
EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.13','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END; END $t$;
-- T2.14 new version for the same recipe; old food_id row must stay unchanged
DO $t$ DECLARE v integer; f integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := pg_temp.new_draft((SELECT val FROM _ids WHERE k='recipe_a'),'A v2');
    INSERT INTO recipe_ingredients (recipe_version_id,ingredient_id,quantity,uom_id,quantity_grams)
    SELECT v,(SELECT val FROM _ids WHERE k='ing_i'),1.00,u.id,120.00 FROM uoms u WHERE u.code='piece';
    INSERT INTO foods (food_type) VALUES ('recipe_version') RETURNING id INTO f;
    UPDATE recipe_versions SET status='published',food_id=f,version_number=2,published_at=now(),
      kcal_per_serving=100,protein_per_serving=10,fat_per_serving=2,carbs_per_serving=12 WHERE id=v;
    IF (SELECT count(*) FROM recipe_versions WHERE id=(SELECT val FROM _ids WHERE k='ver1')
          AND status='published' AND food_id=(SELECT val FROM _ids WHERE k='food_r1') AND kcal_per_serving=250)=1
    THEN PERFORM pg_temp.rec('T2.14','PASS','v2 published; v1 row and its food_id unchanged');
    ELSE PERFORM pg_temp.rec('T2.14','FAIL','v1 changed after publishing v2'); END IF;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.14','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T2.15 composite FK (component_id, recipe_version_id): cross-version component must fail
DO $t$ DECLARE v1 integer; v2 integer; c1 integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v1 := pg_temp.new_draft((SELECT val FROM _ids WHERE k='recipe_b'),'B comp owner');
    INSERT INTO recipe_components (recipe_version_id,title) VALUES (v1,'Main');
    c1 := (SELECT id FROM recipe_components WHERE recipe_version_id=v1);
    v2 := pg_temp.new_draft((SELECT val FROM _ids WHERE k='recipe_c'),'C comp user');
    INSERT INTO recipe_ingredients (recipe_version_id,ingredient_id,component_id,quantity,uom_id,quantity_grams)
    SELECT v2,(SELECT val FROM _ids WHERE k='ing_i'),c1,1,u.id,1 FROM uoms u WHERE u.code='g';
    PERFORM pg_temp.rec('T2.15','FAIL','component belonging to another recipe version accepted');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.15','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T2.16 partial unique index recipe_one_draft
DO $t$ BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    INSERT INTO recipe_versions (recipe_id,title) VALUES ((SELECT val FROM _ids WHERE k='recipe_c'),'C draft 1');
    BEGIN
      INSERT INTO recipe_versions (recipe_id,title) VALUES ((SELECT val FROM _ids WHERE k='recipe_c'),'C draft 2');
      PERFORM pg_temp.rec('T2.16','FAIL','two drafts for one recipe accepted');
    EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.16','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T2.16','FAIL',format('first draft blocked: %s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

\echo '--- t02 results (recipe_versions lifecycle) ---'
SELECT seq, id, status, detail FROM _r ORDER BY seq;
SELECT status, count(*) AS n FROM _r GROUP BY status ORDER BY 1;
ROLLBACK;
