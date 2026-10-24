-- Forward-only Stage 3. SQL 01–04 та історичні рядки не переписуються.
ALTER TABLE recipe_versions
 ADD COLUMN editor_contract text NOT NULL DEFAULT 'legacy_v1' CHECK(editor_contract IN ('legacy_v1','batch_v1')),
 ADD COLUMN lock_version bigint NOT NULL DEFAULT 0 CHECK(lock_version>=0),
 ADD COLUMN draft_pending_rows jsonb NOT NULL DEFAULT '[]',
 ADD COLUMN publication_review jsonb,
 ADD COLUMN source_recipe_version_id integer REFERENCES recipe_versions(id) ON DELETE RESTRICT,
 ADD CHECK(source_recipe_version_id IS DISTINCT FROM id),
 ADD CHECK(publication_review IS NULL OR jsonb_typeof(publication_review)='object');
ALTER TABLE recipe_ingredients ADD COLUMN ingredient_snapshot jsonb,
 ADD UNIQUE(id,recipe_version_id);
ALTER TABLE recipe_kitchen_equipment ADD UNIQUE(id,recipe_version_id);
CREATE INDEX recipe_source_version_idx ON recipe_versions(source_recipe_version_id);

CREATE FUNCTION recipe_batch_pending_valid(p jsonb) RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE r jsonb; state jsonb; pair jsonb; unfinished boolean; ids text[] := '{}'; k text;
BEGIN
 IF jsonb_typeof(p) IS DISTINCT FROM 'array' OR pg_column_size(p)>65536 THEN RETURN false; END IF;
 IF jsonb_array_length(p)>1000 THEN RETURN false; END IF;
 FOR r IN SELECT value FROM jsonb_array_elements(p) LOOP
  IF jsonb_typeof(r) IS DISTINCT FROM 'object' OR NOT (r ? 'id')
    OR jsonb_typeof(r->'id') IS DISTINCT FROM 'string' OR (r->>'id') !~ '^pending:[a-zA-Z0-9-]{1,64}$' OR r->>'id'=ANY(ids) THEN RETURN false; END IF;
  ids := array_append(ids,r->>'id');
  unfinished:=false;
  FOR k IN SELECT jsonb_object_keys(r) LOOP
   IF k NOT IN ('id','ingredient_id','quantity','uom_id','component_id','note','overrides') THEN RETURN false; END IF;
   IF k NOT IN ('id','overrides') AND (jsonb_typeof(r->k) NOT IN ('string','null') OR length(r->>k)>500) THEN RETURN false; END IF;
  END LOOP;
  IF r ? 'overrides' THEN
   state:=r->'overrides';
   IF jsonb_typeof(state) IS DISTINCT FROM 'object' OR pg_column_size(state)>20000 THEN RETURN false; END IF;
   FOR k,pair IN SELECT key,value FROM jsonb_each(state) LOOP
    IF k NOT IN ('week','half_week') OR jsonb_typeof(pair) IS DISTINCT FROM 'object'
     OR pair->>'mode' IS DISTINCT FROM 'manual' OR (pair - ARRAY['mode','quantity','uom_id','input_snapshot','manual_context','review_ack_hash'])<>'{}' THEN RETURN false; END IF;
    IF pair ? 'quantity' AND (jsonb_typeof(pair->'quantity') NOT IN ('string','null') OR length(pair->>'quantity')>500) THEN RETURN false; END IF;
    IF pair ? 'uom_id' AND (jsonb_typeof(pair->'uom_id') NOT IN ('string','null') OR length(pair->>'uom_id')>500) THEN RETURN false; END IF;
    IF pair ? 'input_snapshot' AND (jsonb_typeof(pair->'input_snapshot') IS DISTINCT FROM 'object' OR pg_column_size(pair->'input_snapshot')>8192
     OR pair->'input_snapshot'->>'schema_version' IS DISTINCT FROM '1') THEN RETURN false; END IF;
    IF coalesce(pair->>'quantity','') !~ '^\d{1,5}\.\d{2}$' OR coalesce(pair->>'uom_id','') !~ '^[1-9]\d{0,4}$' OR NOT (pair ? 'input_snapshot') THEN
     unfinished:=true;
    ELSIF (pair->>'quantity')::numeric<=0 THEN unfinished:=true;
    END IF;
    IF pair ? 'manual_context' AND jsonb_typeof(pair->'manual_context') NOT IN ('object','null') THEN RETURN false; END IF;
    IF pair ? 'review_ack_hash' AND jsonb_typeof(pair->'review_ack_hash') NOT IN ('string','null') THEN RETURN false; END IF;
   END LOOP;
  END IF;
  -- Завершені рядки нормалізуються, а не дублюються у JSON.
  IF coalesce(r->>'ingredient_id','') ~ '^[1-9]\d*$' AND coalesce(r->>'uom_id','') ~ '^[1-9]\d*$'
    AND coalesce(r->>'quantity','') ~ '^\d{1,5}(\.\d{1,2})?$' THEN
   IF (r->>'quantity')::numeric>0 AND NOT unfinished THEN RETURN false; END IF;
  END IF;
 END LOOP;
 RETURN true;
END $$;
ALTER TABLE recipe_versions ADD CHECK(recipe_batch_pending_valid(draft_pending_rows)),
 ADD CHECK(status='draft' OR draft_pending_rows='[]'),
 ADD CHECK(editor_contract='batch_v1' OR (draft_pending_rows='[]' AND publication_review IS NULL));

CREATE FUNCTION recipe_batch_snapshot_valid(s jsonb) RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE k text;
BEGIN
 IF s IS NULL OR jsonb_typeof(s) IS DISTINCT FROM 'object' OR pg_column_size(s)>8192
  OR s->>'schema_version' IS DISTINCT FROM '1' OR jsonb_typeof(s->'name') IS DISTINCT FROM 'string'
  OR length(s->>'name')>500 THEN RETURN false; END IF;
 FOREACH k IN ARRAY ARRAY['kcal','protein','fat','carbs'] LOOP
  IF jsonb_typeof(s->k) IS DISTINCT FROM 'string' OR (s->>k) !~ '^\d{1,5}\.\d{2}$' THEN RETURN false; END IF;
 END LOOP;
 RETURN true;
END $$;
ALTER TABLE recipe_ingredients ADD CHECK(ingredient_snapshot IS NULL OR recipe_batch_snapshot_valid(ingredient_snapshot));

CREATE TABLE recipe_batch_formats (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 recipe_version_id integer NOT NULL REFERENCES recipe_versions(id) ON DELETE RESTRICT,
 code text NOT NULL CHECK(code IN ('single','week','half_week')),
 policy_snapshot jsonb NOT NULL CHECK(jsonb_typeof(policy_snapshot)='object' AND policy_snapshot->>'schema_version' IS NOT DISTINCT FROM '1' AND pg_column_size(policy_snapshot)<=8192),
 kcal_total numeric(14,6) NOT NULL CHECK(kcal_total>=0 AND kcal_total<>'NaN'),
 protein_total numeric(14,6) NOT NULL CHECK(protein_total>=0 AND protein_total<>'NaN'),
 fat_total numeric(14,6) NOT NULL CHECK(fat_total>=0 AND fat_total<>'NaN'),
 carbs_total numeric(14,6) NOT NULL CHECK(carbs_total>=0 AND carbs_total<>'NaN'),
 calculation_hash text NOT NULL,
 UNIQUE(recipe_version_id,code), UNIQUE(id,recipe_version_id), UNIQUE(id,recipe_version_id,code)
);
CREATE TABLE recipe_batch_quantities (
 recipe_version_id integer NOT NULL,
 format_id integer NOT NULL,
 format_code text NOT NULL CHECK(format_code IN ('week','half_week')),
 recipe_ingredient_id integer NOT NULL,
 mode text NOT NULL CHECK(mode IN ('auto','manual')),
 quantity numeric(7,2) NOT NULL CHECK(quantity>0 AND quantity<>'NaN'),
 uom_id smallint NOT NULL REFERENCES uoms(id) ON DELETE RESTRICT,
 quantity_grams numeric(7,2) NOT NULL CHECK(quantity_grams>0 AND quantity_grams<>'NaN'),
 input_snapshot jsonb NOT NULL CHECK(jsonb_typeof(input_snapshot)='object' AND pg_column_size(input_snapshot)<=8192
   AND input_snapshot->>'schema_version' IS NOT DISTINCT FROM '1'),
 manual_context jsonb CHECK(manual_context IS NULL OR pg_column_size(manual_context)<=16384),
 review_ack_hash text,
 CHECK((mode='auto' AND manual_context IS NULL AND review_ack_hash IS NULL)
    OR (mode='manual' AND jsonb_typeof(manual_context)='object' AND manual_context IS NOT NULL)),
 PRIMARY KEY(format_id,recipe_ingredient_id),
 FOREIGN KEY(format_id,recipe_version_id,format_code) REFERENCES recipe_batch_formats(id,recipe_version_id,code) ON DELETE RESTRICT,
 FOREIGN KEY(recipe_ingredient_id,recipe_version_id) REFERENCES recipe_ingredients(id,recipe_version_id) ON DELETE RESTRICT
);
CREATE INDEX recipe_batch_quantity_row_idx ON recipe_batch_quantities(recipe_ingredient_id);
CREATE INDEX recipe_batch_quantity_version_idx ON recipe_batch_quantities(recipe_version_id);
CREATE INDEX recipe_batch_quantity_uom_idx ON recipe_batch_quantities(uom_id);
CREATE TABLE recipe_batch_equipment (
 recipe_version_id integer NOT NULL,
 format_id integer NOT NULL,
 recipe_kitchen_equipment_id integer NOT NULL,
 mode text NOT NULL CHECK(mode IN ('auto','manual')),
 min_value numeric(7,2) CHECK(min_value>0 AND min_value<>'NaN'),
 uom_id smallint REFERENCES uoms(id) ON DELETE RESTRICT,
 tip text,
 input_snapshot jsonb NOT NULL CHECK(jsonb_typeof(input_snapshot)='object' AND input_snapshot->>'schema_version' IS NOT DISTINCT FROM '1' AND pg_column_size(input_snapshot)<=8192),
 manual_context jsonb CHECK(manual_context IS NULL OR pg_column_size(manual_context)<=16384),
 review_ack_hash text,
 CHECK((mode='auto' AND manual_context IS NULL AND review_ack_hash IS NULL)
    OR (mode='manual' AND manual_context IS NOT NULL AND jsonb_typeof(manual_context)='object')),
 PRIMARY KEY(format_id,recipe_kitchen_equipment_id),
 FOREIGN KEY(format_id,recipe_version_id) REFERENCES recipe_batch_formats(id,recipe_version_id) ON DELETE RESTRICT,
 FOREIGN KEY(recipe_kitchen_equipment_id,recipe_version_id) REFERENCES recipe_kitchen_equipment(id,recipe_version_id) ON DELETE RESTRICT
);
CREATE INDEX recipe_batch_equipment_version_idx ON recipe_batch_equipment(recipe_version_id);
CREATE INDEX recipe_batch_equipment_row_idx ON recipe_batch_equipment(recipe_kitchen_equipment_id);
CREATE INDEX recipe_batch_equipment_uom_idx ON recipe_batch_equipment(uom_id);

-- CAS primitive для майбутніх команд PR B; сам не змінює дані.
CREATE FUNCTION recipe_batch_assert_revision(p_id integer,p_expected bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE v recipe_versions;
BEGIN
 SELECT * INTO STRICT v FROM recipe_versions WHERE id=p_id FOR UPDATE;
 IF v.status<>'draft' OR v.editor_contract<>'batch_v1' OR v.lock_version<>p_expected THEN
  RAISE EXCEPTION 'Stale batch revision or non-editable version' USING ERRCODE='40001';
 END IF;
END $$;
CREATE FUNCTION recipe_batch_root_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' THEN
  IF OLD.status='published' THEN RAISE EXCEPTION 'Published recipe version immutable'; END IF;
  IF NEW.editor_contract<>OLD.editor_contract THEN RAISE EXCEPTION 'Explicit legacy conversion belongs to PR C'; END IF;
  IF NEW.editor_contract='batch_v1' AND NEW.lock_version<>OLD.lock_version+1 THEN
   RAISE EXCEPTION 'Batch update must advance revision exactly once' USING ERRCODE='40001';
  END IF;
 END IF;
 IF NEW.editor_contract='batch_v1' AND NEW.servings_count<>1 THEN RAISE EXCEPTION 'Batch A divisor is 1'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER batch_root_guard BEFORE INSERT OR UPDATE ON recipe_versions FOR EACH ROW EXECUTE FUNCTION recipe_batch_root_guard();

CREATE FUNCTION recipe_batch_detail_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_id integer;
BEGIN
 -- OLD та NEW блокуються в стабільному порядку, включно з reparent.
 FOR v_id IN SELECT DISTINCT x FROM unnest(ARRAY[
  CASE WHEN TG_OP<>'INSERT' THEN OLD.recipe_version_id END,
  CASE WHEN TG_OP<>'DELETE' THEN NEW.recipe_version_id END]) x WHERE x IS NOT NULL ORDER BY x LOOP
  PERFORM 1 FROM recipe_versions WHERE id=v_id FOR UPDATE;
  PERFORM assert_recipe_version_editable(v_id);
  UPDATE recipe_versions SET lock_version=lock_version+1,publication_review=NULL
   WHERE id=v_id AND editor_contract='batch_v1';
 END LOOP;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['recipe_batch_formats','recipe_batch_quantities','recipe_batch_equipment','recipe_ingredients','recipe_components','recipe_directions','recipe_kitchen_equipment'] LOOP
  EXECUTE format('CREATE TRIGGER batch_detail_guard BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION recipe_batch_detail_guard()',t);
 END LOOP;
END $$;
-- Закриває INSERT phantom default conversion під час publication/refresh.
CREATE FUNCTION recipe_batch_conversion_lock() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM 1 FROM ingredients WHERE id IN (
  CASE WHEN TG_OP<>'INSERT' THEN OLD.ingredient_id END,
  CASE WHEN TG_OP<>'DELETE' THEN NEW.ingredient_id END) ORDER BY id FOR UPDATE;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER batch_conversion_lock BEFORE INSERT OR UPDATE OR DELETE ON ingredient_uoms FOR EACH ROW EXECUTE FUNCTION recipe_batch_conversion_lock();

-- Fingerprint усього прийнятого довідникового контексту, включно з default/UOM labels.
CREATE FUNCTION recipe_batch_source_hash(p_ingredient integer) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT md5(jsonb_build_object('ingredient',to_jsonb(i),'conversions',
  (SELECT coalesce(jsonb_agg(jsonb_build_object('uom',to_jsonb(u),'conversion',to_jsonb(c)) ORDER BY u.id),'[]'::jsonb)
   FROM ingredient_uoms c JOIN uoms u ON u.id=c.uom_id WHERE c.ingredient_id=i.id),
  'gram',(SELECT to_jsonb(u) FROM uoms u WHERE code='g'))::text) FROM ingredients i WHERE i.id=p_ingredient
$$;
-- Snapshot dependency context: JSON equality, без процентних порогів.
CREATE FUNCTION recipe_batch_context(p_row integer,p_code text) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('a',r.quantity::text,'parent',
  CASE WHEN p_code='week' THEN r.quantity_grams::text ELSE
   (SELECT q.quantity_grams::text FROM recipe_batch_quantities q WHERE q.recipe_ingredient_id=r.id AND q.format_code='week') END,
  'ingredient',r.ingredient_snapshot,'input',q.input_snapshot,
  'policy',f.policy_snapshot,'parent_input',CASE WHEN p_code='half_week' THEN
   (SELECT jsonb_build_object('quantity',b.quantity::text,'uom',b.uom_id,'input',b.input_snapshot) FROM recipe_batch_quantities b WHERE b.recipe_ingredient_id=r.id AND b.format_code='week') ELSE NULL END)
 FROM recipe_ingredients r JOIN recipe_batch_quantities q ON q.recipe_ingredient_id=r.id AND q.format_code=p_code JOIN recipe_batch_formats f ON f.id=q.format_id WHERE r.id=p_row
$$;
CREATE FUNCTION recipe_batch_totals(p_version integer,p_code text) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('kcal',coalesce(sum(g*(s->>'kcal')::numeric/100),0)::numeric(14,6)::text,
  'protein',coalesce(sum(g*(s->>'protein')::numeric/100),0)::numeric(14,6)::text,
  'fat',coalesce(sum(g*(s->>'fat')::numeric/100),0)::numeric(14,6)::text,
  'carbs',coalesce(sum(g*(s->>'carbs')::numeric/100),0)::numeric(14,6)::text)
 FROM (SELECT r.ingredient_snapshot s,CASE WHEN p_code='single' THEN r.quantity_grams ELSE q.quantity_grams END g
  FROM recipe_ingredients r LEFT JOIN recipe_batch_quantities q ON q.recipe_ingredient_id=r.id AND q.format_code=p_code
  WHERE r.recipe_version_id=p_version) rows
$$;
CREATE FUNCTION recipe_batch_validate(p_id integer,p_publish boolean DEFAULT false) RETURNS void LANGUAGE plpgsql AS $$
DECLARE v recipe_versions; r record; q record; f record; e record; totals jsonb;
 factor numeric; target numeric; expected numeric; step numeric; rounding text; parent numeric;
BEGIN
 SELECT * INTO v FROM recipe_versions WHERE id=p_id;
 IF NOT FOUND THEN RETURN; END IF;
 IF v.editor_contract='legacy_v1' THEN
  IF EXISTS(SELECT 1 FROM recipe_batch_formats WHERE recipe_version_id=p_id) THEN RAISE EXCEPTION 'Legacy has batch headers'; END IF;
  RETURN;
 END IF;
 IF (SELECT count(*) FROM recipe_batch_formats WHERE recipe_version_id=p_id)<>3 THEN RAISE EXCEPTION 'Three batch headers required'; END IF;
 IF p_publish AND (v.draft_pending_rows<>'[]' OR NOT EXISTS(SELECT 1 FROM recipe_ingredients WHERE recipe_version_id=p_id)) THEN RAISE EXCEPTION 'Incomplete batch'; END IF;
 -- Однаковий порядок locks для довідникових UPDATE/DELETE та INSERT conversion.
 IF p_publish THEN
  PERFORM 1 FROM ingredients WHERE id IN (SELECT ingredient_id FROM recipe_ingredients WHERE recipe_version_id=p_id) ORDER BY id FOR SHARE;
  PERFORM 1 FROM ingredient_uoms WHERE ingredient_id IN (SELECT ingredient_id FROM recipe_ingredients WHERE recipe_version_id=p_id) ORDER BY ingredient_id,uom_id FOR SHARE;
  PERFORM 1 FROM uoms WHERE code='g' OR id IN (SELECT iu.uom_id FROM ingredient_uoms iu JOIN recipe_ingredients used_row ON used_row.ingredient_id=iu.ingredient_id WHERE used_row.recipe_version_id=p_id) ORDER BY id FOR SHARE;
 END IF;
 FOR r IN SELECT ri.*,i.name,i.kcal_per_100g,i.protein_per_100g,i.fat_per_100g,i.carbs_per_100g FROM recipe_ingredients ri JOIN ingredients i ON i.id=ri.ingredient_id WHERE ri.recipe_version_id=p_id LOOP
  IF NOT recipe_batch_snapshot_valid(r.ingredient_snapshot) OR (SELECT code FROM uoms WHERE id=r.uom_id)<>'g' OR r.quantity<>r.quantity_grams OR r.quantity='NaN' THEN RAISE EXCEPTION 'Invalid A snapshot/grams'; END IF;
  IF p_publish AND r.ingredient_snapshot IS DISTINCT FROM jsonb_build_object('schema_version',1,'name',r.name,'kcal',r.kcal_per_100g::text,'protein',r.protein_per_100g::text,'fat',r.fat_per_100g::text,'carbs',r.carbs_per_100g::text) THEN RAISE EXCEPTION 'Stale ingredient snapshot'; END IF;
  IF (SELECT count(*) FROM recipe_batch_quantities WHERE recipe_ingredient_id=r.id)<>2 THEN RAISE EXCEPTION 'A row needs B/C'; END IF;
  parent:=r.quantity_grams;
  FOR q IN SELECT bq.*,u.code,u.label FROM recipe_batch_quantities bq JOIN uoms u ON u.id=bq.uom_id WHERE bq.recipe_ingredient_id=r.id ORDER BY CASE format_code WHEN 'week' THEN 1 ELSE 2 END LOOP
   IF (q.input_snapshot->>'factor') IS NULL OR (q.input_snapshot->>'factor') !~ '^\d{1,5}\.\d{2}$' THEN RAISE EXCEPTION 'Invalid factor snapshot'; END IF;
   factor:=(q.input_snapshot->>'factor')::numeric;
   IF factor<=0 OR (q.code='g' AND factor<>1) OR q.input_snapshot->>'uom_label' IS NULL THEN RAISE EXCEPTION 'Invalid edible conversion'; END IF;
   IF p_publish THEN
    IF q.input_snapshot->>'source_hash' IS DISTINCT FROM recipe_batch_source_hash(r.ingredient_id) THEN RAISE EXCEPTION 'Stale source fingerprint'; END IF;
    PERFORM assert_ingredient_grams(r.ingredient_id,q.uom_id,q.quantity,q.quantity_grams);
    IF q.code<>'g' AND NOT EXISTS(SELECT 1 FROM ingredient_uoms WHERE ingredient_id=r.ingredient_id AND uom_id=q.uom_id AND grams_per_uom=factor) THEN RAISE EXCEPTION 'Stale conversion'; END IF;
    IF q.input_snapshot->>'uom_label'<>q.label THEN RAISE EXCEPTION 'Stale UOM label'; END IF;
   END IF;
   IF q.quantity_grams<>round(q.quantity*factor,2) THEN RAISE EXCEPTION 'Frozen grams mismatch'; END IF;
   target:=CASE WHEN q.format_code='week' THEN parent*7 ELSE parent/2 END;
   rounding:=q.input_snapshot->>'rounding';
   IF (q.input_snapshot->>'step') IS NULL OR (q.input_snapshot->>'step') !~ '^\d{1,5}\.\d{2}$' THEN RAISE EXCEPTION 'Invalid step'; END IF;
   step:=(q.input_snapshot->>'step')::numeric;
   IF step<=0 OR rounding IS NULL OR rounding NOT IN ('exact','ceil','nearest') THEN RAISE EXCEPTION 'Invalid rounding'; END IF;
   IF q.mode='auto' AND rounding='exact' AND (q.code<>'g' OR step<>0.01) THEN RAISE EXCEPTION 'Exact grams only'; END IF;
   IF q.mode='auto' AND rounding<>'exact' AND q.input_snapshot->'confirmed' IS DISTINCT FROM 'true'::jsonb THEN RAISE EXCEPTION 'Unconfirmed rounding'; END IF;
   expected:=CASE rounding WHEN 'exact' THEN round(target,2) WHEN 'ceil' THEN ceil(target/factor/step)*step ELSE round(target/factor/step)*step END;
   IF q.mode='auto' AND q.quantity<>expected THEN RAISE EXCEPTION 'Auto graph mismatch'; END IF;
   IF p_publish AND q.mode='manual' AND (q.manual_context IS DISTINCT FROM recipe_batch_context(r.id,q.format_code)
      OR q.review_ack_hash IS DISTINCT FROM md5(q.manual_context::text)) THEN RAISE EXCEPTION 'Manual needs review'; END IF;
   parent:=q.quantity_grams;
  END LOOP;
 END LOOP;
 FOR f IN SELECT * FROM recipe_batch_formats WHERE recipe_version_id=p_id LOOP
  totals:=recipe_batch_totals(p_id,f.code);
  IF jsonb_build_object('kcal',f.kcal_total::text,'protein',f.protein_total::text,'fat',f.fat_total::text,'carbs',f.carbs_total::text)<>totals
    OR f.calculation_hash<>md5(totals::text) THEN RAISE EXCEPTION 'Totals/hash mismatch'; END IF;
  FOR e IN SELECT re.*,be.mode,be.min_value resolved,be.uom_id resolved_uom,be.input_snapshot,be.manual_context,be.review_ack_hash FROM recipe_kitchen_equipment re LEFT JOIN recipe_batch_equipment be ON be.recipe_kitchen_equipment_id=re.id AND be.format_id=f.id WHERE re.recipe_version_id=p_id LOOP
   IF e.mode IS NULL THEN RAISE EXCEPTION 'Missing equipment snapshot'; END IF;
   IF e.mode='auto' AND (e.resolved IS DISTINCT FROM CASE WHEN e.scaling_mode='fixed' THEN e.min_value ELSE round(e.min_value*CASE f.code WHEN 'single' THEN 1 WHEN 'week' THEN 7 ELSE 3.5 END,2) END OR e.resolved_uom IS DISTINCT FROM e.uom_id) THEN RAISE EXCEPTION 'Auto equipment mismatch'; END IF;
   IF p_publish AND e.mode='manual' AND (e.manual_context IS DISTINCT FROM jsonb_build_object('base',e.min_value::text,'uom',e.uom_id,'scaling',e.scaling_mode,'format',f.code,'input',e.input_snapshot) OR e.review_ack_hash IS DISTINCT FROM md5(e.manual_context::text)) THEN RAISE EXCEPTION 'Equipment needs review'; END IF;
  END LOOP;
 END LOOP;
END $$;
CREATE FUNCTION recipe_batch_deferred_check() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='recipe_versions' THEN
  IF TG_OP<>'INSERT' THEN PERFORM recipe_batch_validate(OLD.id); END IF;
  IF TG_OP<>'DELETE' THEN PERFORM recipe_batch_validate(NEW.id); END IF;
 ELSE
  IF TG_OP<>'INSERT' THEN PERFORM recipe_batch_validate(OLD.recipe_version_id); END IF;
  IF TG_OP<>'DELETE' THEN PERFORM recipe_batch_validate(NEW.recipe_version_id); END IF;
 END IF;
 RETURN NULL;
END $$;
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['recipe_versions','recipe_ingredients','recipe_batch_formats','recipe_batch_quantities','recipe_kitchen_equipment','recipe_batch_equipment'] LOOP
  EXECUTE format('CREATE CONSTRAINT TRIGGER batch_aggregate_check AFTER INSERT OR UPDATE OR DELETE ON %I DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION recipe_batch_deferred_check()',t);
 END LOOP;
END $$;
CREATE FUNCTION recipe_batch_publication() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.editor_contract='batch_v1' AND NEW.status='published' AND OLD.status='draft' THEN
  IF NEW.publication_review IS NULL OR NEW.publication_review->'complete' IS DISTINCT FROM 'true'::jsonb
    OR NEW.publication_review->>'lock_version' IS DISTINCT FROM OLD.lock_version::text
    OR coalesce(NEW.publication_review->>'actor','')='' OR coalesce(NEW.publication_review->>'at','')='' THEN RAISE EXCEPTION 'Explicit publication review required'; END IF;
  -- BEFORE UPDATE ще читає OLD root. Перевірка snapshots/деталей виконується тут,
  -- а NEW pending та compatibility перевіряються окремо.
  PERFORM recipe_batch_validate(NEW.id,true);
  IF NEW.draft_pending_rows<>'[]' THEN RAISE EXCEPTION 'Pending rows cannot publish'; END IF;
  IF EXISTS(SELECT 1 FROM recipe_batch_formats f WHERE f.recipe_version_id=NEW.id AND f.code='single'
    AND (NEW.kcal_per_serving IS DISTINCT FROM round(f.kcal_total,2) OR NEW.protein_per_serving IS DISTINCT FROM round(f.protein_total,2)
     OR NEW.fat_per_serving IS DISTINCT FROM round(f.fat_total,2) OR NEW.carbs_per_serving IS DISTINCT FROM round(f.carbs_total,2))) THEN RAISE EXCEPTION 'A compatibility mismatch'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER batch_publication BEFORE UPDATE OF status ON recipe_versions FOR EACH ROW EXECUTE FUNCTION recipe_batch_publication();
