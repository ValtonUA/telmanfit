-- TelmanFit PG integration tests: module 01 - meal_plans / meal_plan_versions / meal_items.
-- ONE transaction, ends with ROLLBACK: nothing is persisted.
BEGIN;

CREATE TEMP TABLE _r (seq serial, id text, status text, detail text) ON COMMIT DROP;
CREATE TEMP TABLE _ids (k text PRIMARY KEY, val integer) ON COMMIT DROP;
CREATE FUNCTION pg_temp.rec(p_id text, p_status text, p_detail text) RETURNS void
LANGUAGE sql AS $h$ INSERT INTO _r(id,status,detail) VALUES (p_id,p_status,p_detail) $h$;

CREATE FUNCTION pg_temp.build_plan(p_plan integer, p_days integer, p_meals boolean, p_items boolean)
RETURNS integer LANGUAGE plpgsql AS $h$
DECLARE v integer; d integer; dd integer; m integer; mt integer;
BEGIN
  INSERT INTO meal_plan_versions (meal_plan_id) VALUES (p_plan) RETURNING id INTO v;
  SELECT id INTO mt FROM meal_types WHERE code='breakfast';
  FOR d IN 1..p_days LOOP
    INSERT INTO meal_plan_days (meal_plan_version_id,day_number) VALUES (v,d) RETURNING id INTO dd;
    IF p_meals THEN
      INSERT INTO meals (meal_plan_day_id,meal_type_id) VALUES (dd,mt) RETURNING id INTO m;
      IF p_items THEN
        INSERT INTO meal_items (meal_id,food_id,quantity,uom_id,resolved_quantity_grams)
        SELECT m,(SELECT val FROM _ids WHERE k='food_i'),100,u.id,100 FROM uoms u WHERE u.code='g';
      END IF;
    END IF;
  END LOOP;
  RETURN v;
END $h$;

CREATE FUNCTION pg_temp.publish_plan(p_version integer, p_number integer) RETURNS void LANGUAGE plpgsql AS $h$
BEGIN UPDATE meal_plan_versions SET status='published',version_number=p_number,published_at=now() WHERE id=p_version; END $h$;

INSERT INTO uoms (code,label,kind) VALUES
 ('g','gram','mass'),('serving','serving','serving'),('piece','piece','count'),('ml','milliliter','volume');
INSERT INTO meal_types (code,title,sort_order) VALUES ('breakfast','Breakfast',1),('lunch','Lunch',2);

WITH f AS (INSERT INTO foods (food_type) VALUES ('ingredient') RETURNING id)
INSERT INTO _ids(k,val) SELECT 'food_i', id FROM f;
INSERT INTO ingredients (food_id,food_type,name,kcal_per_100g,protein_per_100g,fat_per_100g,carbs_per_100g)
SELECT val,'ingredient','Rice',130.00,2.70,0.30,28.00 FROM _ids WHERE k='food_i';
INSERT INTO _ids(k,val) SELECT 'ing_i', id FROM ingredients WHERE food_id=(SELECT val FROM _ids WHERE k='food_i');
INSERT INTO ingredient_uoms (ingredient_id,uom_id,grams_per_uom,is_default)
SELECT (SELECT val FROM _ids WHERE k='ing_i'),id,120.00,true FROM uoms WHERE code='piece';

CREATE FUNCTION pg_temp.published_recipe_food() RETURNS integer LANGUAGE plpgsql AS $h$
DECLARE vr integer; vv integer; f integer;
BEGIN
  INSERT INTO recipes (title) VALUES ('Plan helper recipe') RETURNING id INTO vr;
  INSERT INTO recipe_versions (recipe_id,title) VALUES (vr,'v1') RETURNING id INTO vv;
  INSERT INTO recipe_ingredients (recipe_version_id,ingredient_id,quantity,uom_id,quantity_grams)
  SELECT vv,(SELECT val FROM _ids WHERE k='ing_i'),1.00,u.id,120.00 FROM uoms u WHERE u.code='piece';
  INSERT INTO foods (food_type) VALUES ('recipe_version') RETURNING id INTO f;
  UPDATE recipe_versions SET status='published',food_id=f,version_number=1,published_at=now(),
    kcal_per_serving=200,protein_per_serving=15,fat_per_serving=5,carbs_per_serving=20 WHERE id=vv;
  RETURN f;
END $h$;
WITH p AS (INSERT INTO meal_plans (title) VALUES ('Plan A') RETURNING id) INSERT INTO _ids(k,val) SELECT 'plan_a', p.id FROM p;

-- T3.1 positive: 7 contiguous days, every day has a meal, every meal has an item
DO $t$ DECLARE v integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := pg_temp.build_plan((SELECT val FROM _ids WHERE k='plan_a'),7,true,true);
    PERFORM pg_temp.publish_plan(v,1);
    IF (SELECT status FROM meal_plan_versions WHERE id=v)='published'
    THEN PERFORM pg_temp.rec('T3.1','PASS','7 days x 1 meal x 1 item published via UPDATE');
    ELSE PERFORM pg_temp.rec('T3.1','FAIL','publish status not applied'); END IF;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.1','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.2 direct INSERT of a published plan version must fail
DO $t$ DECLARE p integer; BEGIN
  BEGIN
    INSERT INTO meal_plans (title) VALUES ('Plan direct') RETURNING id INTO p;
    INSERT INTO meal_plan_versions (meal_plan_id,status,version_number,published_at) VALUES (p,'published',1,now());
    PERFORM pg_temp.rec('T3.2','FAIL','direct INSERT of published meal_plan_version accepted');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.2','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.3 6-day plan must not publish (contiguous 7-day week blocks)
DO $t$ DECLARE p integer; v integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    INSERT INTO meal_plans (title) VALUES ('Plan 6d') RETURNING id INTO p;
    v := pg_temp.build_plan(p,6,true,true);
    PERFORM pg_temp.publish_plan(v,1);
    PERFORM pg_temp.rec('T3.3','FAIL','6-day plan published');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.3','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.4 every day must contain at least one meal
DO $t$ DECLARE p integer; v integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    INSERT INTO meal_plans (title) VALUES ('Plan no meals') RETURNING id INTO p;
    v := pg_temp.build_plan(p,7,false,false);
    PERFORM pg_temp.publish_plan(v,1);
    PERFORM pg_temp.rec('T3.4','FAIL','plan with empty days published');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.4','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;
-- T3.5 every meal must contain at least one item
DO $t$ DECLARE p integer; v integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    INSERT INTO meal_plans (title) VALUES ('Plan no items') RETURNING id INTO p;
    v := pg_temp.build_plan(p,7,true,false);
    PERFORM pg_temp.publish_plan(v,1);
    PERFORM pg_temp.rec('T3.5','FAIL','plan with empty meals published');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.5','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.6 non-contiguous day numbering (1..6 plus 8) must not publish
DO $t$ DECLARE p integer; v integer; dd integer; m integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    INSERT INTO meal_plans (title) VALUES ('Plan gap') RETURNING id INTO p;
    v := pg_temp.build_plan(p,6,true,true);
    INSERT INTO meal_plan_days (meal_plan_version_id,day_number) VALUES (v,8) RETURNING id INTO dd;
    INSERT INTO meals (meal_plan_day_id,meal_type_id) SELECT dd,id FROM meal_types WHERE code='breakfast' RETURNING id INTO m;
    INSERT INTO meal_items (meal_id,food_id,quantity,uom_id,resolved_quantity_grams)
    SELECT m,(SELECT val FROM _ids WHERE k='food_i'),100,u.id,100 FROM uoms u WHERE u.code='g';
    PERFORM pg_temp.publish_plan(v,1);
    PERFORM pg_temp.rec('T3.6','FAIL','plan with day numbers 1..6,8 published');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.6','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.7 positive: 14 days (two 7-day week blocks) must publish
DO $t$ DECLARE p integer; v integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    INSERT INTO meal_plans (title) VALUES ('Plan 14d') RETURNING id INTO p;
    v := pg_temp.build_plan(p,14,true,true);
    PERFORM pg_temp.publish_plan(v,1);
    IF (SELECT status FROM meal_plan_versions WHERE id=v)='published'
    THEN PERFORM pg_temp.rec('T3.7','PASS','14 contiguous days published');
    ELSE PERFORM pg_temp.rec('T3.7','FAIL','publish status not applied'); END IF;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.7','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.8 meal item with a unit not permitted for the ingredient must be rejected
DO $t$ DECLARE p integer; v integer; dd integer; m integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    INSERT INTO meal_plans (title) VALUES ('Plan ml') RETURNING id INTO p;
    v := pg_temp.build_plan(p,7,true,true);
    SELECT id INTO dd FROM meal_plan_days WHERE meal_plan_version_id=v AND day_number=1;
    SELECT id INTO m FROM meals WHERE meal_plan_day_id=dd;
    INSERT INTO meal_items (meal_id,food_id,quantity,uom_id,resolved_quantity_grams)
    SELECT m,(SELECT val FROM _ids WHERE k='food_i'),10,u.id,10 FROM uoms u WHERE u.code='ml';
    PERFORM pg_temp.rec('T3.8','FAIL','meal_items accepted uom=ml without ingredient_uoms row');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.8','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;
-- fixtures for meal_items measure tests (draft plan + published recipe food)
WITH p AS (INSERT INTO meal_plans (title) VALUES ('Plan items') RETURNING id) INSERT INTO _ids(k,val) SELECT 'plan_items', p.id FROM p;
DO $t$ DECLARE v integer; BEGIN
  v := pg_temp.build_plan((SELECT val FROM _ids WHERE k='plan_items'),7,true,true);
  INSERT INTO _ids(k,val) SELECT 'ver_items', v;
END $t$;
INSERT INTO _ids(k,val) SELECT 'food_rpub', pg_temp.published_recipe_food();

-- T3.9 ingredient food requires resolved grams
DO $t$ DECLARE m integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    SELECT me.id INTO m FROM meals me JOIN meal_plan_days d ON d.id=me.meal_plan_day_id
    WHERE d.meal_plan_version_id=(SELECT val FROM _ids WHERE k='ver_items') AND d.day_number=1;
    INSERT INTO meal_items (meal_id,food_id,quantity,uom_id,resolved_quantity_grams)
    SELECT m,(SELECT val FROM _ids WHERE k='food_i'),100,u.id,NULL FROM uoms u WHERE u.code='g';
    PERFORM pg_temp.rec('T3.9','FAIL','meal item for ingredient food accepted with NULL resolved grams');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.9','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.10 recipe food must use serving unit and NULL resolved grams
DO $t$ DECLARE m integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    SELECT me.id INTO m FROM meals me JOIN meal_plan_days d ON d.id=me.meal_plan_day_id
    WHERE d.meal_plan_version_id=(SELECT val FROM _ids WHERE k='ver_items') AND d.day_number=1;
    INSERT INTO meal_items (meal_id,food_id,quantity,uom_id,resolved_quantity_grams)
    SELECT m,(SELECT val FROM _ids WHERE k='food_rpub'),1,u.id,100 FROM uoms u WHERE u.code='g';
    PERFORM pg_temp.rec('T3.10','FAIL','meal item for recipe food accepted with uom=g and grams');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.10','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.11 recipe food without a published version must be rejected
DO $t$ DECLARE m integer; f integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    INSERT INTO foods (food_type) VALUES ('recipe_version') RETURNING id INTO f;
    SELECT me.id INTO m FROM meals me JOIN meal_plan_days d ON d.id=me.meal_plan_day_id
    WHERE d.meal_plan_version_id=(SELECT val FROM _ids WHERE k='ver_items') AND d.day_number=1;
    INSERT INTO meal_items (meal_id,food_id,quantity,uom_id,resolved_quantity_grams)
    SELECT m,f,1,u.id,NULL FROM uoms u WHERE u.code='serving';
    PERFORM pg_temp.rec('T3.11','FAIL','meal item accepted for recipe food without published version');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.11','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.12 positive: published recipe food with serving unit and NULL grams
DO $t$ DECLARE m integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    SELECT me.id INTO m FROM meals me JOIN meal_plan_days d ON d.id=me.meal_plan_day_id
    WHERE d.meal_plan_version_id=(SELECT val FROM _ids WHERE k='ver_items') AND d.day_number=2;
    INSERT INTO meal_items (meal_id,food_id,quantity,uom_id,resolved_quantity_grams)
    SELECT m,(SELECT val FROM _ids WHERE k='food_rpub'),2,u.id,NULL FROM uoms u WHERE u.code='serving';
    PERFORM pg_temp.rec('T3.12','PASS','meal item accepted: published recipe food, 2 serving, grams NULL');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.12','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;
-- T3.13 new day into an already published plan version must fail
DO $t$ DECLARE v integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    SELECT id INTO v FROM meal_plan_versions WHERE meal_plan_id=(SELECT val FROM _ids WHERE k='plan_a');
    INSERT INTO meal_plan_days (meal_plan_version_id,day_number) VALUES (v,15);
    PERFORM pg_temp.rec('T3.13','FAIL','new day inserted into published plan version');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.13','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.14 deleting items of a published plan version must fail
DO $t$ DECLARE v integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    SELECT id INTO v FROM meal_plan_versions WHERE meal_plan_id=(SELECT val FROM _ids WHERE k='plan_a');
    DELETE FROM meal_items WHERE meal_id IN (
      SELECT m.id FROM meals m JOIN meal_plan_days d ON d.id=m.meal_plan_day_id WHERE d.meal_plan_version_id=v);
    PERFORM pg_temp.rec('T3.14','FAIL','meal_items of published plan version deleted');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.14','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.15 partial unique index meal_plan_one_draft
DO $t$ DECLARE p integer; BEGIN
  BEGIN
    INSERT INTO meal_plans (title) VALUES ('Plan drafts') RETURNING id INTO p;
    INSERT INTO meal_plan_versions (meal_plan_id) VALUES (p);
    BEGIN
      INSERT INTO meal_plan_versions (meal_plan_id) VALUES (p);
      PERFORM pg_temp.rec('T3.15','FAIL','two drafts for one meal_plan accepted');
    EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.15','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.15','FAIL',format('first draft blocked: %s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- helpers for the gram-reconciliation probes below
CREATE FUNCTION pg_temp.build_7day_plan(p_title text) RETURNS integer LANGUAGE plpgsql AS $h$
DECLARE p integer;
BEGIN
  INSERT INTO meal_plans (title) VALUES (p_title) RETURNING id INTO p;
  RETURN pg_temp.build_plan(p,7,true,true);
END $h$;
CREATE FUNCTION pg_temp.set_item(p_version integer, p_day integer, p_quantity numeric, p_uom_code text, p_grams numeric)
RETURNS integer LANGUAGE plpgsql AS $h$
DECLARE mi integer;
BEGIN
  SELECT i.id INTO mi FROM meal_items i JOIN meals me ON me.id=i.meal_id JOIN meal_plan_days d ON d.id=me.meal_plan_day_id
   WHERE d.meal_plan_version_id=p_version AND d.day_number=p_day;
  IF mi IS NULL THEN RAISE EXCEPTION 'no meal item for version % day %',p_version,p_day; END IF;
  UPDATE meal_items SET quantity=p_quantity, uom_id=(SELECT id FROM uoms WHERE code=p_uom_code), resolved_quantity_grams=p_grams WHERE id=mi;
  RETURN mi;
END $h$;
CREATE FUNCTION pg_temp.add_ingredient(p_name text, p_piece_grams numeric) RETURNS integer LANGUAGE plpgsql AS $h$
DECLARE f integer; i integer;
BEGIN
  INSERT INTO foods (food_type) VALUES ('ingredient') RETURNING id INTO f;
  INSERT INTO ingredients (food_id,food_type,name,kcal_per_100g,protein_per_100g,fat_per_100g,carbs_per_100g)
  VALUES (f,'ingredient',p_name,100.00,1.00,1.00,1.00);
  SELECT id INTO i FROM ingredients WHERE food_id=f;
  INSERT INTO ingredient_uoms (ingredient_id,uom_id,grams_per_uom,is_default)
  SELECT i,id,p_piece_grams,true FROM uoms WHERE code='piece';
  RETURN f;
END $h$;

-- T3.16 (was a GAP probe, reclassified): publishing a plan whose item grams do not match quantity x grams_per_uom must fail (BR-P-06)
DO $t$ DECLARE v integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := pg_temp.build_7day_plan('Plan T3.16');
    PERFORM pg_temp.set_item(v,1,0.25,'piece',10.00);
    PERFORM pg_temp.publish_plan(v,1);
    PERFORM pg_temp.rec('T3.16','FAIL','published 0.25 piece = 10.00 g instead of 30.00 g');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.16','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.17 positive: consistent 0.25 piece = 30.00 g publishes (kept for the freeze probe below)
DO $t$ DECLARE v integer; mi integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := pg_temp.build_7day_plan('Plan T3.17');
    mi := pg_temp.set_item(v,1,0.25,'piece',30.00);
    PERFORM pg_temp.publish_plan(v,1);
    IF (SELECT status FROM meal_plan_versions WHERE id=v)='published' THEN
      INSERT INTO _ids(k,val) SELECT 'ver_t317', v;
      INSERT INTO _ids(k,val) SELECT 'item_t317', mi;
      PERFORM pg_temp.rec('T3.17','PASS','0.25 piece = 30.00 g reconciled and published');
    ELSE PERFORM pg_temp.rec('T3.17','FAIL','publish status not applied'); END IF;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.17','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.18 unit 'g' must reconcile too: 100.50 g stored as 100.00 g must be rejected at publication
DO $t$ DECLARE v integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := pg_temp.build_7day_plan('Plan T3.18');
    PERFORM pg_temp.set_item(v,1,100.50,'g',100.00);
    PERFORM pg_temp.publish_plan(v,1);
    PERFORM pg_temp.rec('T3.18','FAIL','published 100.50 g with resolved_quantity_grams = 100.00 g');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.18','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.19 positive: unit 'g' with equal grams publishes (factor 1)
DO $t$ DECLARE v integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := pg_temp.build_7day_plan('Plan T3.19');
    PERFORM pg_temp.set_item(v,1,100.50,'g',100.50);
    PERFORM pg_temp.publish_plan(v,1);
    IF (SELECT status FROM meal_plan_versions WHERE id=v)='published' THEN
      PERFORM pg_temp.rec('T3.19','PASS','100.50 g with resolved_quantity_grams = 100.50 g published');
    ELSE PERFORM pg_temp.rec('T3.19','FAIL','publish status not applied'); END IF;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.19','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.20 rounding: 0.25 piece x 33.33 g = 8.3325 -> round to 8.33 and publish
DO $t$ DECLARE v integer; f integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    f := pg_temp.add_ingredient('Oats 33.33', 33.33);
    v := pg_temp.build_7day_plan('Plan T3.20');
    UPDATE meal_items SET food_id=f, quantity=0.25, uom_id=(SELECT id FROM uoms WHERE code='piece'), resolved_quantity_grams=8.33
     WHERE id=(SELECT i.id FROM meal_items i JOIN meals me ON me.id=i.meal_id JOIN meal_plan_days d ON d.id=me.meal_plan_day_id
                WHERE d.meal_plan_version_id=v AND d.day_number=1);
    PERFORM pg_temp.publish_plan(v,1);
    IF (SELECT status FROM meal_plan_versions WHERE id=v)='published' THEN
      PERFORM pg_temp.rec('T3.20','PASS','0.25 piece x 33.33 g rounds to 8.33 g and publishes');
    ELSE PERFORM pg_temp.rec('T3.20','FAIL','publish status not applied'); END IF;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.20','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.21 rounding: 8.34 g for 0.25 piece x 33.33 g must be rejected at publication
DO $t$ DECLARE v integer; f integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    f := pg_temp.add_ingredient('Oats 33.33 b', 33.33);
    v := pg_temp.build_7day_plan('Plan T3.21');
    UPDATE meal_items SET food_id=f, quantity=0.25, uom_id=(SELECT id FROM uoms WHERE code='piece'), resolved_quantity_grams=8.34
     WHERE id=(SELECT i.id FROM meal_items i JOIN meals me ON me.id=i.meal_id JOIN meal_plan_days d ON d.id=me.meal_plan_day_id
                WHERE d.meal_plan_version_id=v AND d.day_number=1);
    PERFORM pg_temp.publish_plan(v,1);
    PERFORM pg_temp.rec('T3.21','FAIL','published 8.34 g for a rounded expectation of 8.33 g');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.21','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.22 the unit factor changed after the draft was created -> publication must fail (service must recalculate the draft)
DO $t$ DECLARE v integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := pg_temp.build_7day_plan('Plan T3.22');
    PERFORM pg_temp.set_item(v,1,0.25,'piece',30.00);
    UPDATE ingredient_uoms SET grams_per_uom=100.00
     WHERE ingredient_id=(SELECT val FROM _ids WHERE k='ing_i') AND uom_id=(SELECT id FROM uoms WHERE code='piece');
    PERFORM pg_temp.publish_plan(v,1);
    PERFORM pg_temp.rec('T3.22','FAIL','published after grams_per_uom was changed (expected rejection)');
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.22','PASS',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.23 published plans stay frozen: dictionary change does not rewrite published grams and the item is immutable
DO $t$ DECLARE v integer; mi integer; g1 numeric; g2 numeric; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := (SELECT val FROM _ids WHERE k='ver_t317');
    mi := (SELECT val FROM _ids WHERE k='item_t317');
    g1 := (SELECT resolved_quantity_grams FROM meal_items WHERE id=mi);
    UPDATE ingredient_uoms SET grams_per_uom=100.00
     WHERE ingredient_id=(SELECT val FROM _ids WHERE k='ing_i') AND uom_id=(SELECT id FROM uoms WHERE code='piece');
    g2 := (SELECT resolved_quantity_grams FROM meal_items WHERE id=mi);
    IF g1 IS DISTINCT FROM g2 THEN
      PERFORM pg_temp.rec('T3.23','FAIL',format('frozen grams changed from %s to %s',g1,g2));
      RETURN;
    END IF;
    BEGIN
      UPDATE meal_items SET resolved_quantity_grams=500.00 WHERE id=mi;
      PERFORM pg_temp.rec('T3.23','FAIL','published meal_items row was updated');
    EXCEPTION WHEN OTHERS THEN
      PERFORM pg_temp.rec('T3.23','PASS',format('published grams %s unchanged after dictionary update; item update blocked :: %s',g2,SQLERRM));
    END;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.23','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

-- T3.24 regression: a published plan may still contain a recipe item (serving, NULL grams)
DO $t$ DECLARE v integer; dd integer; m integer; BEGIN
  EXECUTE 'SET CONSTRAINTS ALL DEFERRED';
  BEGIN
    v := pg_temp.build_7day_plan('Plan T3.24');
    SELECT id INTO dd FROM meal_plan_days WHERE meal_plan_version_id=v AND day_number=1;
    SELECT id INTO m FROM meals WHERE meal_plan_day_id=dd;
    UPDATE meal_items SET food_id=(SELECT val FROM _ids WHERE k='food_rpub'), quantity=1,
           uom_id=(SELECT id FROM uoms WHERE code='serving'), resolved_quantity_grams=NULL
     WHERE meal_id=m;
    PERFORM pg_temp.publish_plan(v,1);
    IF (SELECT status FROM meal_plan_versions WHERE id=v)='published' THEN
      PERFORM pg_temp.rec('T3.24','PASS','plan with a published recipe item (serving, NULL grams) publishes');
    ELSE PERFORM pg_temp.rec('T3.24','FAIL','publish status not applied'); END IF;
  EXCEPTION WHEN OTHERS THEN PERFORM pg_temp.rec('T3.24','FAIL',format('%s :: %s',SQLSTATE,SQLERRM)); END;
END $t$;

\echo '--- t03 results (meal plans and meal items) ---'
SELECT seq, id, status, detail FROM _r ORDER BY seq;
SELECT status, count(*) AS n FROM _r GROUP BY status ORDER BY 1;
ROLLBACK;
