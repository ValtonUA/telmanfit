-- B2: без нових таблиць/полів; nullable значення та історичні SQL незмінні.
CREATE FUNCTION recipe_batch_equipment_auto(p_value numeric,p_uom smallint,p_scaling text,p_code text)
RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN p_value IS NULL THEN NULL
 WHEN (SELECT kind FROM uoms WHERE id=p_uom)='count' THEN ceil(p_value*CASE WHEN p_scaling='fixed' THEN 1 WHEN p_code='single' THEN 1 WHEN p_code='week' THEN 7 ELSE 3.5 END)
 ELSE round(p_value*CASE WHEN p_scaling='fixed' THEN 1 WHEN p_code='single' THEN 1 WHEN p_code='week' THEN 7 ELSE 3.5 END,2) END
$$;
CREATE FUNCTION recipe_batch_equipment_context(p_row integer,p_format integer) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('base',to_jsonb(re),'format',f.code,'mode',be.mode,
 'value',be.min_value::text,'uom',be.uom_id,'tip',be.tip,
 'catalog',(SELECT to_jsonb(k) FROM kitchen_equipment k WHERE id=re.kitchen_equipment_id),
 'unit',(SELECT to_jsonb(u) FROM uoms u WHERE id=be.uom_id),
 'composition',md5(jsonb_build_object('pending',v.draft_pending_rows,
 'a',(SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY r.id),'[]') FROM recipe_ingredients r WHERE r.recipe_version_id=v.id),
 'bc',(SELECT coalesce(jsonb_agg(jsonb_build_object('row',q.recipe_ingredient_id,'format',q.format_code,'quantity',q.quantity::text,'grams',q.quantity_grams::text,'uom',q.uom_id,'input',q.input_snapshot) ORDER BY q.recipe_ingredient_id,q.format_code),'[]') FROM recipe_batch_quantities q WHERE q.recipe_version_id=v.id))::text))
 FROM recipe_kitchen_equipment re JOIN recipe_versions v ON v.id=re.recipe_version_id
 JOIN recipe_batch_equipment be ON be.recipe_kitchen_equipment_id=re.id AND be.format_id=p_format
 JOIN recipe_batch_formats f ON f.id=be.format_id WHERE re.id=p_row
$$;
CREATE OR REPLACE FUNCTION recipe_batch_validate(p_id integer,p_publish boolean DEFAULT false) RETURNS void LANGUAGE plpgsql AS $$
DECLARE v recipe_versions; r record; q record; f record; e record; totals jsonb;
 factor numeric; target numeric; expected numeric; step numeric; rounding text; parent numeric; parent_uom smallint; parent_factor numeric; inherited_policy jsonb;
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
   IF q.format_code='half_week' AND q.mode='auto' THEN
    IF q.uom_id IS DISTINCT FROM parent_uom OR factor IS DISTINCT FROM parent_factor THEN RAISE EXCEPTION 'Auto C must inherit B UOM and factor'; END IF;
    IF q.input_snapshot ? 'auto_policies' AND jsonb_typeof(q.input_snapshot->'auto_policies') IS DISTINCT FROM 'object' THEN RAISE EXCEPTION 'Invalid accepted auto policies'; END IF;
    inherited_policy:=q.input_snapshot->'auto_policies'->q.code;
    IF inherited_policy IS NULL AND q.code='g' THEN inherited_policy:='{"rounding":"exact","step":"0.01"}'::jsonb; END IF;
    IF inherited_policy IS NULL OR jsonb_typeof(inherited_policy) IS DISTINCT FROM 'object' THEN RAISE EXCEPTION 'Auto C needs accepted policy for B UOM; explicit refresh required'; END IF;
    IF q.input_snapshot->'rounding' IS DISTINCT FROM inherited_policy->'rounding'
     OR q.input_snapshot->'step' IS DISTINCT FROM inherited_policy->'step'
     OR q.input_snapshot->'confirmed' IS DISTINCT FROM inherited_policy->'confirmed' THEN RAISE EXCEPTION 'Auto C inherited policy mismatch'; END IF;
   END IF;
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
   parent_uom:=q.uom_id;
   parent_factor:=factor;
  END LOOP;
 END LOOP;
 FOR f IN SELECT * FROM recipe_batch_formats WHERE recipe_version_id=p_id LOOP
  totals:=recipe_batch_totals(p_id,f.code);
  IF jsonb_build_object('kcal',f.kcal_total::text,'protein',f.protein_total::text,'fat',f.fat_total::text,'carbs',f.carbs_total::text)<>totals
    OR f.calculation_hash<>md5(totals::text) THEN RAISE EXCEPTION 'Totals/hash mismatch'; END IF;
  FOR e IN SELECT re.*,be.mode,be.min_value resolved,be.uom_id resolved_uom,be.input_snapshot,be.manual_context,be.review_ack_hash FROM recipe_kitchen_equipment re LEFT JOIN recipe_batch_equipment be ON be.recipe_kitchen_equipment_id=re.id AND be.format_id=f.id WHERE re.recipe_version_id=p_id LOOP
   IF e.mode IS NULL THEN RAISE EXCEPTION 'Missing equipment snapshot'; END IF;
   IF e.mode='auto' AND (e.resolved IS DISTINCT FROM recipe_batch_equipment_auto(e.min_value,e.uom_id,e.scaling_mode,f.code) OR e.resolved_uom IS DISTINCT FROM e.uom_id) THEN RAISE EXCEPTION 'Auto equipment mismatch'; END IF;
   IF p_publish AND e.input_snapshot->>'adequacy_ack' IS DISTINCT FROM md5(recipe_batch_equipment_context(e.id,f.id)::text) THEN RAISE EXCEPTION 'Equipment adequacy needs review'; END IF;
   IF p_publish AND e.mode='manual' AND (e.manual_context IS DISTINCT FROM recipe_batch_equipment_context(e.id,f.id) OR e.review_ack_hash IS DISTINCT FROM md5(e.manual_context::text)) THEN RAISE EXCEPTION 'Equipment needs review'; END IF;
  END LOOP;
 END LOOP;
END $$;
