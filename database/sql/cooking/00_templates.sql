-- T5.7b v1: лише наявний повний тиждень, без DEC-5M та backfill.
CREATE TABLE cooking_templates (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 title text NOT NULL CHECK(length(btrim(title)) BETWEEN 1 AND 500),
 created_by bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT
);
CREATE TABLE cooking_template_versions (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 template_id integer NOT NULL REFERENCES cooking_templates(id) ON DELETE RESTRICT,
 version_number integer NOT NULL CHECK(version_number>0),
 previous_version_id integer REFERENCES cooking_template_versions(id) ON DELETE RESTRICT,
 status text NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published')),
 source_plan_version_id integer NOT NULL REFERENCES meal_plan_versions(id) ON DELETE RESTRICT,
 source_week integer NOT NULL CHECK(source_week BETWEEN 1 AND 4681),
 mode smallint NOT NULL CHECK(mode IN (7,2,1)),
 revision bigint NOT NULL DEFAULT 0 CHECK(revision>=0),
 menu_pattern jsonb,
 menu_fingerprint text,
 reviewed_revision bigint,
 reviewed_by bigint REFERENCES users(id) ON DELETE RESTRICT,
 reviewed_at timestamptz,
 published_at timestamptz,
 UNIQUE(template_id,version_number),
 CHECK(previous_version_id IS DISTINCT FROM id),
 CHECK((reviewed_revision IS NULL AND reviewed_by IS NULL AND reviewed_at IS NULL)
  OR (reviewed_revision IS NOT NULL AND reviewed_revision=revision AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)),
 CHECK((status='draft' AND published_at IS NULL) OR
  (status='published' AND published_at IS NOT NULL AND menu_pattern IS NOT NULL AND reviewed_revision IS NOT NULL))
);
CREATE UNIQUE INDEX cooking_one_draft ON cooking_template_versions(template_id) WHERE status='draft';
CREATE INDEX cooking_pattern_lookup ON cooking_template_versions(menu_fingerprint,mode) WHERE status='published';
CREATE TABLE cooking_sessions (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 version_id integer NOT NULL REFERENCES cooking_template_versions(id) ON DELETE RESTRICT,
 position smallint NOT NULL CHECK(position>0),
 day smallint NOT NULL CHECK(day BETWEEN 1 AND 7),
 introduction text NOT NULL DEFAULT '',
 equipment text NOT NULL DEFAULT '',
 estimated_seconds integer NOT NULL CHECK(estimated_seconds>0),
 content jsonb NOT NULL,
 UNIQUE(version_id,position), UNIQUE(version_id,day), UNIQUE(id,version_id)
);
CREATE TABLE cooking_outputs (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 version_id integer NOT NULL,
 session_id integer NOT NULL,
 recipe_version_id integer NOT NULL REFERENCES recipe_versions(id) ON DELETE RESTRICT,
 format_id integer,
 portion_divisor numeric(7,2) NOT NULL CHECK(portion_divisor>0 AND portion_divisor<>'NaN'),
 FOREIGN KEY(session_id,version_id) REFERENCES cooking_sessions(id,version_id) ON DELETE RESTRICT,
 FOREIGN KEY(format_id,recipe_version_id) REFERENCES recipe_batch_formats(id,recipe_version_id) ON DELETE RESTRICT,
 UNIQUE(id,version_id)
);
CREATE INDEX cooking_output_recipe ON cooking_outputs(recipe_version_id);
CREATE TABLE cooking_allocations (
 version_id integer NOT NULL REFERENCES cooking_template_versions(id) ON DELETE RESTRICT,
 day smallint NOT NULL CHECK(day BETWEEN 1 AND 7),
 meal_position integer NOT NULL CHECK(meal_position>0),
 item_position integer NOT NULL CHECK(item_position>0),
 output_id integer NOT NULL,
 portions numeric(7,2) NOT NULL CHECK(portions>0 AND portions<>'NaN'),
 PRIMARY KEY(version_id,day,meal_position,item_position),
 FOREIGN KEY(output_id,version_id) REFERENCES cooking_outputs(id,version_id) ON DELETE RESTRICT
);
CREATE INDEX cooking_allocation_output ON cooking_allocations(output_id);

-- Exact snapshots: immutable recipe descendants, жодних live conversions/каталожних назв.
CREATE FUNCTION cooking_recipe_snapshot(p integer) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('version',to_jsonb(v)-'created_at'-'published_at',
 'rows',(SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY r.id),'[]') FROM recipe_ingredients r WHERE r.recipe_version_id=v.id),
 'formats',(SELECT coalesce(jsonb_agg(to_jsonb(f) ORDER BY f.id),'[]') FROM recipe_batch_formats f WHERE f.recipe_version_id=v.id),
 'quantities',(SELECT coalesce(jsonb_agg(to_jsonb(q) ORDER BY q.format_id,q.recipe_ingredient_id),'[]') FROM recipe_batch_quantities q WHERE q.recipe_version_id=v.id))
 FROM recipe_versions v WHERE v.id=p AND v.status='published'
$$;

CREATE FUNCTION cooking_menu_pattern(p integer,w integer) RETURNS jsonb LANGUAGE plpgsql STABLE AS $$
DECLARE result jsonb;
BEGIN
 IF w NOT BETWEEN 1 AND 4681 OR NOT EXISTS(SELECT FROM meal_plan_versions WHERE id=p AND status='published')
  OR (SELECT count(*) FROM meal_plan_days WHERE meal_plan_version_id=p AND day_number BETWEEN (w-1)*7+1 AND w*7)<>7 THEN
  RAISE EXCEPTION 'Cooking requires an existing complete published week';
 END IF;
 WITH ordered_meals AS (
  SELECT m.*,d.day_number-(w-1)*7 AS day,
   row_number() OVER(PARTITION BY d.id ORDER BY m.sort_order,m.id) AS meal_position
  FROM meal_plan_days d JOIN meals m ON m.meal_plan_day_id=d.id
  WHERE d.meal_plan_version_id=p AND d.day_number BETWEEN (w-1)*7+1 AND w*7
 ), slots AS (
  SELECT m.day,m.meal_position,m.meal_type_id,m.sort_order AS meal_sort,m.title AS meal_title,
   row_number() OVER(PARTITION BY m.id ORDER BY i.sort_order,i.id) AS item_position,
   i.food_id,i.quantity,i.uom_id,i.resolved_quantity_grams,i.sort_order AS item_sort,
   v.id AS recipe_version_id,i.recipe_batch_format_id AS format_id,
   CASE WHEN v.editor_contract='legacy_v1' THEN v.servings_count ELSE i.recipe_portion_divisor END AS divisor,
   cooking_recipe_snapshot(v.id) AS recipe_snapshot
  FROM ordered_meals m JOIN meal_items i ON i.meal_id=m.id
  LEFT JOIN recipe_versions v ON v.food_id=i.food_id
 ) SELECT jsonb_build_object('schema_version',1,
  'meals',(SELECT coalesce(jsonb_agg(jsonb_build_object('day',day,'position',meal_position,'type',meal_type_id,'sort',sort_order,'title',title)
   ORDER BY day,meal_position),'[]') FROM ordered_meals),
  'items',coalesce(jsonb_agg(to_jsonb(slots) ORDER BY day,meal_position,item_position),'[]')) INTO result FROM slots;
 IF EXISTS(SELECT FROM jsonb_array_elements(result->'items') x WHERE x->>'recipe_version_id' IS NOT NULL AND x->'recipe_snapshot'='null') THEN
  RAISE EXCEPTION 'Missing frozen recipe snapshot';
 END IF;
 RETURN result;
END $$;

-- JSON v1: IDs не залежать від позиції. Action.parts — plain text або explicit token.
CREATE FUNCTION cooking_content_valid(p jsonb) RETURNS boolean LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE s jsonb; b jsonb; a jsonb; ids text[]:='{}'; k text;
BEGIN
 IF p IS NULL OR jsonb_typeof(p) IS DISTINCT FROM 'object' OR p-'schema_version'-'stages'<>'{}'
  OR p->'schema_version' IS DISTINCT FROM '1'::jsonb OR jsonb_typeof(p->'stages') IS DISTINCT FROM 'array'
  OR jsonb_array_length(p->'stages')=0 OR pg_column_size(p)>1048576 THEN RETURN false; END IF;
 FOR s IN SELECT value FROM jsonb_array_elements(p->'stages') LOOP
  IF jsonb_typeof(s) IS DISTINCT FROM 'object' OR s-ARRAY['id','title','blocks']<>'{}'
   OR jsonb_typeof(s->'title') IS DISTINCT FROM 'string' OR length(btrim(s->>'title'))=0
   OR jsonb_typeof(s->'blocks') IS DISTINCT FROM 'array' OR jsonb_array_length(s->'blocks')=0 THEN RETURN false; END IF;
  IF jsonb_typeof(s->'id') IS DISTINCT FROM 'string' OR s->>'id' !~ '^[a-zA-Z0-9-]{1,64}$' OR s->>'id'=ANY(ids) THEN RETURN false; END IF;
  ids:=array_append(ids,s->>'id');
  FOR b IN SELECT value FROM jsonb_array_elements(s->'blocks') LOOP
   IF jsonb_typeof(b) IS DISTINCT FROM 'object' OR jsonb_typeof(b->'id') IS DISTINCT FROM 'string'
    OR b->>'id' !~ '^[a-zA-Z0-9-]{1,64}$' OR b->>'id'=ANY(ids) THEN RETURN false; END IF;
   ids:=array_append(ids,b->>'id');
   CASE b->>'type'
   WHEN 'action' THEN
    IF b-ARRAY['id','type','parts']<>'{}' OR jsonb_typeof(b->'parts') IS DISTINCT FROM 'array' OR jsonb_array_length(b->'parts')=0 THEN RETURN false; END IF;
    FOR a IN SELECT value FROM jsonb_array_elements(b->'parts') LOOP
     IF jsonb_typeof(a)='string' THEN
      IF a::text ~ '[<>]|\{\{' THEN RETURN false; END IF;
     ELSIF jsonb_typeof(a)='object' THEN
      IF a-ARRAY['metric','sources']<>'{}' OR coalesce(a->>'metric','') NOT IN ('quantity','grams')
       OR jsonb_typeof(a->'sources') IS DISTINCT FROM 'array' OR jsonb_array_length(a->'sources')=0 THEN RETURN false; END IF;
      -- Точні посилання перевіряються відносно нормалізованих outputs перед publish.
     ELSE RETURN false; END IF;
    END LOOP;
   WHEN 'hint' THEN
    IF b-ARRAY['id','type','text']<>'{}' OR jsonb_typeof(b->'text') IS DISTINCT FROM 'string' THEN RETURN false; END IF;
   WHEN 'image' THEN
    IF b-ARRAY['id','type','url','alt']<>'{}' OR jsonb_typeof(b->'url') IS DISTINCT FROM 'string'
     OR b->>'url' !~ '^https://[^/@?#: ]+/' OR jsonb_typeof(b->'alt') IS DISTINCT FROM 'string' THEN RETURN false; END IF;
   WHEN 'timer' THEN
    IF b-ARRAY['id','type','label','seconds','message']<>'{}' OR jsonb_typeof(b->'label') IS DISTINCT FROM 'string'
     OR jsonb_typeof(b->'seconds') IS DISTINCT FROM 'number' OR b->>'seconds' !~ '^[1-9][0-9]{0,5}$'
     OR (b->>'seconds')::integer>604800 OR jsonb_typeof(b->'message') IS DISTINCT FROM 'string' THEN RETURN false; END IF;
   ELSE RETURN false;
   END CASE;
   FOREACH k IN ARRAY ARRAY['title','text','label','message','alt'] LOOP
    IF b ? k AND b->>k ~ '[<>]|\{\{' THEN RETURN false; END IF;
   END LOOP;
  END LOOP;
  IF s->>'title' ~ '[<>]|\{\{' THEN RETURN false; END IF;
 END LOOP;
 RETURN true;
EXCEPTION WHEN OTHERS THEN RETURN false;
END $$;
ALTER TABLE cooking_sessions ADD CHECK(cooking_content_valid(content));

CREATE FUNCTION cooking_token(p_session integer,t jsonb) RETURNS jsonb LANGUAGE plpgsql STABLE AS $$
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
  SELECT ri.ingredient_id,coalesce(q.quantity,ri.quantity) AS quantity,
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
   (r.uom<>first_row.uom OR r.context IS DISTINCT FROM first_row.context OR r.grams/r.quantity<>first_row.grams/first_row.quantity)) THEN
   RAISE EXCEPTION 'Incompatible frozen cooking sum';
  END IF;
  total:=total+CASE WHEN metric='grams' THEN r.grams ELSE r.quantity END;
  IF total>999999999.99 THEN RAISE EXCEPTION 'Cooking token overflow'; END IF;
  n:=n+1;
 END LOOP;
 RETURN jsonb_build_object('value',total::text,'uom_id',CASE WHEN metric='grams' THEN NULL ELSE first_row.uom END,'metric',metric,'sources',t->'sources');
END $$;

CREATE FUNCTION cooking_assert_version(p integer) RETURNS void LANGUAGE plpgsql AS $$
DECLARE v cooking_template_versions; pattern jsonb; slot jsonb; a cooking_allocations; o cooking_outputs; rv recipe_versions; f recipe_batch_formats;
 s cooking_sessions; stage jsonb; block jsonb; part jsonb; session_count integer;
BEGIN
 SELECT * INTO STRICT v FROM cooking_template_versions WHERE id=p;
 pattern:=cooking_menu_pattern(v.source_plan_version_id,v.source_week);
 IF v.menu_pattern IS DISTINCT FROM pattern THEN RAISE EXCEPTION 'Stale cooking menu pattern'; END IF;
 SELECT count(*) INTO session_count FROM cooking_sessions WHERE version_id=p;
 IF session_count<>v.mode OR NOT EXISTS(SELECT FROM cooking_outputs WHERE version_id=p) THEN RAISE EXCEPTION 'Cooking session coverage incomplete'; END IF;
 FOR o IN SELECT * FROM cooking_outputs WHERE version_id=p LOOP
  SELECT * INTO STRICT rv FROM recipe_versions WHERE id=o.recipe_version_id;
  IF rv.status<>'published' THEN RAISE EXCEPTION 'Output requires published recipe'; END IF;
  IF rv.editor_contract='legacy_v1' THEN
   IF o.format_id IS NOT NULL OR o.portion_divisor<>rv.servings_count THEN RAISE EXCEPTION 'Legacy output selector mismatch'; END IF;
  ELSE
   SELECT * INTO f FROM recipe_batch_formats WHERE id=o.format_id AND recipe_version_id=rv.id;
   IF NOT FOUND OR NOT ((f.code='single' AND o.portion_divisor=1) OR (f.code='week' AND o.portion_divisor=7)
    OR (f.code='half_week' AND o.portion_divisor IN (3,4))) THEN RAISE EXCEPTION 'Batch output selector mismatch'; END IF;
  END IF;
  IF NOT EXISTS(SELECT FROM cooking_allocations WHERE output_id=o.id) OR
   (SELECT sum(portions) FROM cooking_allocations WHERE output_id=o.id)>o.portion_divisor THEN RAISE EXCEPTION 'Cooking output overallocated or unused'; END IF;
 END LOOP;
 FOR slot IN SELECT value FROM jsonb_array_elements(pattern->'items') LOOP
  SELECT * INTO a FROM cooking_allocations WHERE version_id=p AND day=(slot->>'day')::integer
   AND meal_position=(slot->>'meal_position')::integer AND item_position=(slot->>'item_position')::integer;
  IF slot->>'recipe_version_id' IS NULL THEN
   IF FOUND THEN RAISE EXCEPTION 'Ingredient slot cannot allocate recipe output'; END IF;
  ELSE
   IF NOT FOUND THEN RAISE EXCEPTION 'Recipe slot not covered'; END IF;
   SELECT * INTO STRICT o FROM cooking_outputs WHERE id=a.output_id;
   IF o.recipe_version_id<>(slot->>'recipe_version_id')::integer OR o.format_id IS DISTINCT FROM (slot->>'format_id')::integer
    OR o.portion_divisor<>(slot->>'divisor')::numeric OR a.portions<>(slot->>'quantity')::numeric
    OR (SELECT day FROM cooking_sessions WHERE id=o.session_id)>a.day THEN RAISE EXCEPTION 'Allocation selector, portions or day mismatch'; END IF;
  END IF;
 END LOOP;
 IF (SELECT count(*) FROM cooking_allocations WHERE version_id=p)<>
  (SELECT count(*) FROM jsonb_array_elements(pattern->'items') x WHERE x->>'recipe_version_id' IS NOT NULL) THEN RAISE EXCEPTION 'Extraneous allocation'; END IF;
 FOR s IN SELECT * FROM cooking_sessions WHERE version_id=p LOOP
  IF NOT EXISTS(SELECT FROM cooking_outputs WHERE session_id=s.id) THEN RAISE EXCEPTION 'Empty cooking session'; END IF;
  FOR stage IN SELECT value FROM jsonb_array_elements(s.content->'stages') LOOP
   FOR block IN SELECT value FROM jsonb_array_elements(stage->'blocks') LOOP
    IF block->>'type'='action' THEN
     FOR part IN SELECT value FROM jsonb_array_elements(block->'parts') LOOP
      IF jsonb_typeof(part)='object' THEN PERFORM cooking_token(s.id,part); END IF;
     END LOOP;
    END IF;
   END LOOP;
  END LOOP;
 END LOOP;
END $$;

CREATE FUNCTION cooking_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prev cooking_template_versions;
BEGIN
 IF TG_OP='DELETE' THEN
  IF OLD.status='published' THEN RAISE EXCEPTION 'Published cooking version immutable'; END IF;
  RETURN OLD;
 END IF;
 PERFORM 1 FROM cooking_templates WHERE id=NEW.template_id FOR UPDATE;
 IF TG_OP='UPDATE' THEN
  IF OLD.status='published' THEN RAISE EXCEPTION 'Published cooking version immutable'; END IF;
  IF (NEW.template_id,NEW.version_number,NEW.previous_version_id) IS DISTINCT FROM (OLD.template_id,OLD.version_number,OLD.previous_version_id) THEN RAISE EXCEPTION 'Cooking version lineage immutable'; END IF;
  IF (NEW.source_plan_version_id,NEW.source_week,NEW.mode) IS DISTINCT FROM (OLD.source_plan_version_id,OLD.source_week,OLD.mode) THEN
   NEW.revision:=OLD.revision+1; NEW.reviewed_revision:=NULL; NEW.reviewed_by:=NULL; NEW.reviewed_at:=NULL;
  END IF;
  IF NEW.revision<OLD.revision OR NEW.revision>OLD.revision+1 THEN RAISE EXCEPTION 'Invalid cooking revision'; END IF;
  IF NEW.revision<>OLD.revision THEN NEW.reviewed_revision:=NULL; NEW.reviewed_by:=NULL; NEW.reviewed_at:=NULL; END IF;
 ELSE
  IF NEW.status<>'draft' OR NEW.reviewed_by IS NOT NULL THEN RAISE EXCEPTION 'Create cooking draft first'; END IF;
  IF NEW.previous_version_id IS NULL THEN
   IF NEW.version_number<>1 OR EXISTS(SELECT FROM cooking_template_versions WHERE template_id=NEW.template_id) THEN RAISE EXCEPTION 'Invalid first cooking version'; END IF;
  ELSE
   SELECT * INTO STRICT prev FROM cooking_template_versions WHERE id=NEW.previous_version_id;
   IF prev.template_id<>NEW.template_id OR prev.status<>'published' OR NEW.version_number<>prev.version_number+1
    OR EXISTS(SELECT FROM cooking_template_versions WHERE template_id=NEW.template_id AND version_number>prev.version_number) THEN RAISE EXCEPTION 'Invalid cooking replacement'; END IF;
  END IF;
 END IF;
 NEW.menu_pattern:=cooking_menu_pattern(NEW.source_plan_version_id,NEW.source_week);
 NEW.menu_fingerprint:=encode(sha256(convert_to(NEW.menu_pattern::text,'UTF8')),'hex');
 IF NEW.reviewed_by IS NOT NULL AND NOT EXISTS(SELECT FROM users WHERE id=NEW.reviewed_by AND role='admin') THEN RAISE EXCEPTION 'Cooking review requires admin'; END IF;
 IF NEW.status='published' THEN
  IF NEW.reviewed_revision IS DISTINCT FROM NEW.revision OR NEW.reviewed_by IS NULL OR NEW.reviewed_at IS NULL THEN RAISE EXCEPTION 'Explicit current cooking review required'; END IF;
  -- BEFORE UPDATE бачить старий draft; dependencies ті самі, pattern перевірено вище.
  PERFORM cooking_assert_version(NEW.id);
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER cooking_version_guard BEFORE INSERT OR UPDATE OR DELETE ON cooking_template_versions FOR EACH ROW EXECUTE FUNCTION cooking_version_guard();

CREATE FUNCTION cooking_detail_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE k integer; v cooking_template_versions;
BEGIN
 FOR k IN SELECT DISTINCT x FROM unnest(ARRAY[CASE WHEN TG_OP<>'INSERT' THEN OLD.version_id END,CASE WHEN TG_OP<>'DELETE' THEN NEW.version_id END]) x WHERE x IS NOT NULL ORDER BY x LOOP
  SELECT * INTO STRICT v FROM cooking_template_versions WHERE id=k FOR UPDATE;
  IF v.status<>'draft' THEN RAISE EXCEPTION 'Published cooking detail immutable'; END IF;
  UPDATE cooking_template_versions SET revision=revision+1,reviewed_revision=NULL,reviewed_by=NULL,reviewed_at=NULL WHERE id=k;
 END LOOP;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['cooking_sessions','cooking_outputs','cooking_allocations'] LOOP
  EXECUTE format('CREATE TRIGGER cooking_detail_guard BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION cooking_detail_guard()',t);
 END LOOP;
END $$;

CREATE VIEW cooking_output_balances AS
 SELECT o.id,o.version_id,o.portion_divisor AS cooked,coalesce(sum(a.portions),0) AS allocated,
 o.portion_divisor-coalesce(sum(a.portions),0) AS left_over
 FROM cooking_outputs o LEFT JOIN cooking_allocations a ON a.output_id=o.id GROUP BY o.id;

-- Доменний read guard, не authentication boundary. Caller передає ClientSession client,
-- server date, exact assignment/version у спільній транзакції REPEATABLE READ READ ONLY.
CREATE FUNCTION cooking_available(p_client integer,p_assignment integer,p_version integer,p_week integer,p_mode integer,p_today date)
 RETURNS boolean LANGUAGE plpgsql STABLE AS $$
DECLARE a client_meal_plan_assignments; v cooking_template_versions; origin date; first_day date; weekday integer;
BEGIN
 SELECT * INTO a FROM client_meal_plan_assignments WHERE id=p_assignment AND client_id=p_client
  AND start_date<=p_today AND (end_date IS NULL OR end_date>=p_today);
 IF NOT FOUND THEN RETURN false; END IF;
 SELECT * INTO v FROM cooking_template_versions WHERE id=p_version AND status='published' AND mode=p_mode;
 IF NOT FOUND THEN RETURN false; END IF;
 SELECT p.week_start_weekday INTO weekday FROM meal_plans p JOIN meal_plan_versions mv ON mv.meal_plan_id=p.id
  WHERE mv.id=a.meal_plan_version_id AND mv.status='published' AND (p.owner_client_id IS NULL OR p.owner_client_id=p_client);
 IF NOT FOUND THEN RETURN false; END IF;
 origin:=a.start_date-((extract(isodow FROM a.start_date)::integer-weekday+7)%7);
 first_day:=origin+(p_week-1)*7;
 IF first_day<a.start_date OR (a.end_date IS NOT NULL AND first_day+6>a.end_date) THEN RETURN false; END IF;
 IF v.menu_pattern IS DISTINCT FROM cooking_menu_pattern(a.meal_plan_version_id,p_week) THEN RETURN false; END IF;
 IF EXISTS(SELECT FROM jsonb_array_elements(v.menu_pattern->'items') x JOIN recipe_versions rv ON rv.id=(x->>'recipe_version_id')::integer
  JOIN recipes r ON r.id=rv.recipe_id WHERE r.owner_client_id IS NOT NULL AND r.owner_client_id<>p_client) THEN RETURN false; END IF;
 RETURN true;
EXCEPTION WHEN numeric_value_out_of_range OR datetime_field_overflow OR raise_exception THEN RETURN false;
END $$;
