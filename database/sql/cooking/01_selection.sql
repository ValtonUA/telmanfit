-- Погоджений explicit selection/replacement; publish ніколи не активує версію.
CREATE TABLE cooking_selections (
 template_id integer NOT NULL REFERENCES cooking_templates(id) ON DELETE RESTRICT,
 mode smallint NOT NULL CHECK(mode IN (1,2,7)),
 menu_fingerprint text NOT NULL CHECK(menu_fingerprint ~ '^[0-9a-f]{64}$'),
 version_id integer NOT NULL REFERENCES cooking_template_versions(id) ON DELETE RESTRICT,
 revision bigint NOT NULL CHECK(revision>0),
 PRIMARY KEY(template_id,mode,menu_fingerprint)
);

CREATE FUNCTION cooking_selection_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v cooking_template_versions; previous cooking_template_versions;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Cooking selection cannot be deleted'; END IF;
 PERFORM 1 FROM cooking_templates WHERE id=NEW.template_id FOR UPDATE;
 SELECT * INTO STRICT v FROM cooking_template_versions WHERE id=NEW.version_id;
 IF v.status<>'published' OR (v.template_id,v.mode,v.menu_fingerprint) IS DISTINCT FROM
  (NEW.template_id,NEW.mode,NEW.menu_fingerprint) THEN RAISE EXCEPTION 'Invalid cooking selection'; END IF;
 IF TG_OP='UPDATE' THEN
  IF (NEW.template_id,NEW.mode,NEW.menu_fingerprint) IS DISTINCT FROM (OLD.template_id,OLD.mode,OLD.menu_fingerprint)
   OR NEW.revision<>OLD.revision+1 THEN RAISE EXCEPTION 'Invalid cooking selection revision/key'; END IF;
  SELECT * INTO STRICT previous FROM cooking_template_versions WHERE id=OLD.version_id;
  IF previous.menu_pattern IS DISTINCT FROM v.menu_pattern THEN RAISE EXCEPTION 'Cooking pattern collision'; END IF;
 ELSIF NEW.revision<>1 THEN RAISE EXCEPTION 'Initial cooking selection revision must be 1'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER cooking_selection_guard BEFORE INSERT OR UPDATE OR DELETE ON cooking_selections
 FOR EACH ROW EXECUTE FUNCTION cooking_selection_guard();

CREATE FUNCTION cooking_select(p_actor bigint,p_version integer,p_expected bigint) RETURNS bigint LANGUAGE plpgsql AS $$
DECLARE v cooking_template_versions; s cooking_selections;
BEGIN
 IF p_expected IS NULL OR p_expected<0 THEN RAISE EXCEPTION 'Invalid selection revision' USING ERRCODE='40001'; END IF;
 IF NOT EXISTS(SELECT FROM users WHERE id=p_actor AND role='admin' FOR SHARE) THEN RAISE EXCEPTION 'Cooking selection requires admin'; END IF;
 SELECT * INTO STRICT v FROM cooking_template_versions WHERE id=p_version;
 PERFORM 1 FROM cooking_templates WHERE id=v.template_id FOR UPDATE;
 IF v.status<>'published' THEN RAISE EXCEPTION 'Select an exact published cooking version'; END IF;
 SELECT * INTO s FROM cooking_selections WHERE template_id=v.template_id AND mode=v.mode AND menu_fingerprint=v.menu_fingerprint;
 IF coalesce(s.revision,0)<>p_expected THEN RAISE EXCEPTION 'Stale cooking selection' USING ERRCODE='40001'; END IF;
 IF s.version_id IS NOT NULL AND (SELECT menu_pattern FROM cooking_template_versions WHERE id=s.version_id) IS DISTINCT FROM v.menu_pattern THEN
  RAISE EXCEPTION 'Cooking pattern collision';
 END IF;
 IF s.version_id IS NULL THEN
  INSERT INTO cooking_selections(template_id,mode,menu_fingerprint,version_id,revision)
   VALUES(v.template_id,v.mode,v.menu_fingerprint,v.id,1);
 ELSE
  UPDATE cooking_selections SET version_id=v.id,revision=p_expected+1
   WHERE template_id=v.template_id AND mode=v.mode AND menu_fingerprint=v.menu_fingerprint;
 END IF;
 RETURN p_expected+1;
END $$;

-- CAS для aggregate edit/review/publish. Жодного автоматичного retry intent.
CREATE FUNCTION cooking_assert_revision(p integer,expected bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE v cooking_template_versions; parent integer;
BEGIN
 SELECT template_id INTO STRICT parent FROM cooking_template_versions WHERE id=p;
 PERFORM 1 FROM cooking_templates WHERE id=parent FOR UPDATE;
 SELECT * INTO STRICT v FROM cooking_template_versions WHERE id=p FOR UPDATE;
 IF expected IS NULL OR expected<0 OR v.status<>'draft' OR v.revision<>expected THEN RAISE EXCEPTION 'Stale cooking draft' USING ERRCODE='40001'; END IF;
END $$;

ALTER FUNCTION cooking_available(integer,integer,integer,integer,integer,date) RENAME TO cooking_compatible;
CREATE FUNCTION cooking_available(p_client integer,p_assignment integer,p_version integer,p_week integer,p_mode integer,p_today date)
 RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT FROM cooking_template_versions v JOIN cooking_selections s
  ON (s.template_id,s.mode,s.menu_fingerprint,s.version_id)=(v.template_id,v.mode,v.menu_fingerprint,v.id)
  WHERE v.id=p_version AND v.mode=p_mode AND v.status='published')
  AND cooking_compatible(p_client,p_assignment,p_version,p_week,p_mode,p_today)
$$;

-- Labels: B/C frozen snapshot; A/grams canonical; legacy quantity label недоступний.
CREATE OR REPLACE FUNCTION cooking_token(p_session integer,t jsonb) RETURNS jsonb LANGUAGE plpgsql STABLE AS $$
DECLARE ref jsonb; r record; first_row record; seen text[]:='{}'; pair text; total numeric:=0; metric text; n integer:=0;
BEGIN
 metric:=t->>'metric';
 IF t-ARRAY['metric','sources']<>'{}' OR coalesce(metric,'') NOT IN ('quantity','grams')
  OR jsonb_typeof(t->'sources') IS DISTINCT FROM 'array' OR jsonb_array_length(t->'sources')=0 THEN RAISE EXCEPTION 'Invalid cooking token'; END IF;
 FOR ref IN SELECT value FROM jsonb_array_elements(t->'sources') LOOP
  IF jsonb_typeof(ref) IS DISTINCT FROM 'object' OR ref-ARRAY['output','row']<>'{}'
   OR jsonb_typeof(ref->'output') IS DISTINCT FROM 'number' OR jsonb_typeof(ref->'row') IS DISTINCT FROM 'number'
   OR ref->>'output' !~ '^[1-9][0-9]{0,9}$' OR ref->>'row' !~ '^[1-9][0-9]{0,9}$' THEN RAISE EXCEPTION 'Invalid token source'; END IF;
  pair:=(ref->>'output')||':'||(ref->>'row');
  IF pair=ANY(seen) THEN RAISE EXCEPTION 'Duplicate cooking token source'; END IF;
  seen:=array_append(seen,pair);
  SELECT ri.ingredient_id,CASE WHEN f.code IN ('week','half_week') THEN q.input_snapshot->>'uom_label'
   WHEN v.editor_contract='batch_v1' THEN 'г' ELSE NULL END AS unit_label,coalesce(q.quantity,ri.quantity) AS quantity,
   coalesce(q.quantity_grams,ri.quantity_grams) AS grams,coalesce(q.uom_id,ri.uom_id) AS uom,
   CASE WHEN f.code IN ('week','half_week') THEN q.input_snapshot ELSE ri.ingredient_snapshot END AS context,
   CASE WHEN f.code IN ('week','half_week') THEN q.quantity IS NOT NULL ELSE true END AS complete
   INTO r FROM cooking_outputs o JOIN recipe_versions v ON v.id=o.recipe_version_id
   JOIN recipe_ingredients ri ON ri.recipe_version_id=v.id AND ri.id=(ref->>'row')::integer
   LEFT JOIN recipe_batch_formats f ON f.id=o.format_id
   LEFT JOIN recipe_batch_quantities q ON q.format_id=o.format_id AND q.recipe_ingredient_id=ri.id
   WHERE o.id=(ref->>'output')::integer AND o.session_id=p_session AND v.status='published';
  IF NOT FOUND OR NOT r.complete THEN RAISE EXCEPTION 'Unresolved cooking token'; END IF;
  IF n=0 THEN first_row:=r; END IF;
  IF r.ingredient_id<>first_row.ingredient_id OR (metric='quantity' AND
   (r.unit_label IS DISTINCT FROM first_row.unit_label OR r.uom<>first_row.uom OR r.context IS DISTINCT FROM first_row.context OR r.grams/r.quantity<>first_row.grams/first_row.quantity)) THEN
   RAISE EXCEPTION 'Incompatible frozen cooking sum';
  END IF;
  total:=total+CASE WHEN metric='grams' THEN r.grams ELSE r.quantity END;
  IF total>999999999.99 THEN RAISE EXCEPTION 'Cooking token overflow'; END IF;
  n:=n+1;
 END LOOP;
 RETURN jsonb_build_object('value',total::text,'uom_id',CASE WHEN metric='grams' THEN NULL ELSE first_row.uom END,'metric',metric,'unit_label',CASE WHEN metric='grams' THEN 'г' ELSE first_row.unit_label END,
  'label_source',CASE WHEN metric='grams' THEN 'canonical_grams' WHEN first_row.unit_label IS NULL THEN 'unavailable'
   WHEN first_row.context ? 'uom_label' THEN 'recipe_snapshot' ELSE 'canonical_grams' END,'sources',t->'sources');
END $$;

