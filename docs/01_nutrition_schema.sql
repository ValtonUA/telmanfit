-- PostgreSQL 15+  |  Nutrition schema audited v0.3  |  2026-10-20
-- The database is assumed empty; Laravel framework tables are created separately.
-- IDs are smallint/integer GENERATED ALWAYS AS IDENTITY; timestamps are timestamptz.
BEGIN;
-- Requires btree_gist (install with a privileged migration on the target server).
CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE TABLE ingredient_categories (
 id smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 name text NOT NULL UNIQUE
);
CREATE TABLE recipe_categories (
 id smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 name text NOT NULL UNIQUE
);
CREATE TABLE uoms (
 id smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 code text NOT NULL UNIQUE,
 label text NOT NULL,
 kind text NOT NULL CHECK (kind IN ('mass','volume','count','serving','other'))
);
CREATE TABLE foods (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 food_type text NOT NULL CHECK (food_type IN ('ingredient','recipe_version')),
 UNIQUE (id, food_type)
);
CREATE TABLE ingredients (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 food_id integer NOT NULL UNIQUE,
 food_type text NOT NULL DEFAULT 'ingredient' CHECK (food_type='ingredient'),
 name text NOT NULL,
 ingredient_category_id smallint REFERENCES ingredient_categories(id) ON DELETE RESTRICT,
 kcal_per_100g numeric(7,2) NOT NULL CHECK (kcal_per_100g>=0),
 protein_per_100g numeric(7,2) NOT NULL CHECK (protein_per_100g>=0),
 fat_per_100g numeric(7,2) NOT NULL CHECK (fat_per_100g>=0),
 carbs_per_100g numeric(7,2) NOT NULL CHECK (carbs_per_100g>=0),
 FOREIGN KEY (food_id,food_type) REFERENCES foods(id,food_type) ON DELETE RESTRICT
);
-- gram is an implicit base unit available for every ingredient; rows below are its additional units.
CREATE TABLE ingredient_uoms (
 ingredient_id integer NOT NULL REFERENCES ingredients(id) ON DELETE RESTRICT,
 uom_id smallint NOT NULL REFERENCES uoms(id) ON DELETE RESTRICT,
 grams_per_uom numeric(7,2) NOT NULL CHECK (grams_per_uom>0),
 is_default boolean NOT NULL DEFAULT false,
 PRIMARY KEY(ingredient_id,uom_id)
);
CREATE UNIQUE INDEX ingredient_uoms_one_default ON ingredient_uoms(ingredient_id) WHERE is_default;
CREATE TABLE recipes (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 title text NOT NULL,
 recipe_category_id smallint REFERENCES recipe_categories(id) ON DELETE RESTRICT,
 parent_recipe_id integer REFERENCES recipes(id) ON DELETE RESTRICT,
 owner_client_id integer, -- FK to clients added in application-wide migration
 created_at timestamptz NOT NULL DEFAULT now(),
 CHECK(parent_recipe_id IS DISTINCT FROM id)
);
CREATE TABLE recipe_versions (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 recipe_id integer NOT NULL REFERENCES recipes(id) ON DELETE RESTRICT,
 version_number smallint,
 status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published')),
 food_id integer UNIQUE,
 food_type text NOT NULL DEFAULT 'recipe_version' CHECK(food_type='recipe_version'),
 title text NOT NULL,
 servings_count numeric(7,2) NOT NULL DEFAULT 1 CHECK(servings_count>0),
 kcal_per_serving numeric(7,2),
 protein_per_serving numeric(7,2),
 fat_per_serving numeric(7,2),
 carbs_per_serving numeric(7,2),
 published_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(recipe_id,version_number),
 UNIQUE(id,recipe_id),
 FOREIGN KEY (food_id,food_type) REFERENCES foods(id,food_type) ON DELETE RESTRICT,
 CHECK ((status = 'draft' AND food_id IS NULL AND published_at IS NULL) OR (status = 'published' AND
   food_id IS NOT NULL AND version_number IS NOT NULL AND published_at IS NOT NULL AND
   kcal_per_serving IS NOT NULL AND protein_per_serving IS NOT NULL AND
   fat_per_serving IS NOT NULL AND carbs_per_serving IS NOT NULL)),
 CHECK (kcal_per_serving IS NULL OR kcal_per_serving>=0),
 CHECK (protein_per_serving IS NULL OR protein_per_serving>=0),
 CHECK (fat_per_serving IS NULL OR fat_per_serving>=0),
 CHECK (carbs_per_serving IS NULL OR carbs_per_serving>=0)
);
CREATE TABLE recipe_components (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 recipe_version_id integer NOT NULL REFERENCES recipe_versions(id) ON DELETE RESTRICT,
 title text NOT NULL,
 sort_order smallint NOT NULL DEFAULT 0,
 UNIQUE(id,recipe_version_id)
);
CREATE TABLE recipe_ingredients (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 recipe_version_id integer NOT NULL REFERENCES recipe_versions(id) ON DELETE RESTRICT,
 ingredient_id integer NOT NULL REFERENCES ingredients(id) ON DELETE RESTRICT,
 component_id integer,
 quantity numeric(7,2) NOT NULL CHECK(quantity>0),
 uom_id smallint NOT NULL REFERENCES uoms(id) ON DELETE RESTRICT,
 quantity_grams numeric(7,2) NOT NULL CHECK(quantity_grams>0),
 sort_order smallint NOT NULL DEFAULT 0,
 FOREIGN KEY(component_id,recipe_version_id)
  REFERENCES recipe_components(id,recipe_version_id) ON DELETE RESTRICT
);
CREATE TABLE recipe_directions (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 recipe_version_id integer NOT NULL REFERENCES recipe_versions(id) ON DELETE RESTRICT,
 direction_number smallint NOT NULL CHECK(direction_number>0),
 direction_text text NOT NULL,
 image_url text,
 UNIQUE(recipe_version_id,direction_number)
);
CREATE TABLE kitchen_equipment_categories (
 id smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 name text NOT NULL UNIQUE
);
CREATE TABLE kitchen_equipment (
 id smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 name text NOT NULL UNIQUE,
 kitchen_equipment_category_id smallint REFERENCES kitchen_equipment_categories(id) ON DELETE RESTRICT
);
CREATE TABLE recipe_kitchen_equipment (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 recipe_version_id integer NOT NULL REFERENCES recipe_versions(id) ON DELETE RESTRICT,
 kitchen_equipment_id smallint NOT NULL REFERENCES kitchen_equipment(id) ON DELETE RESTRICT,
 min_value numeric(7,2) CHECK(min_value>0),
 uom_id smallint REFERENCES uoms(id) ON DELETE RESTRICT,
 scaling_mode text NOT NULL DEFAULT 'fixed' CHECK(scaling_mode IN ('fixed','proportional')),
 tip text,
 UNIQUE(recipe_version_id,kitchen_equipment_id)
);

CREATE TABLE meal_plans (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 title text NOT NULL,
 parent_meal_plan_id integer REFERENCES meal_plans(id) ON DELETE RESTRICT,
 owner_client_id integer, -- FK to clients added in application-wide migration
 created_at timestamptz NOT NULL DEFAULT now(),
 CHECK(parent_meal_plan_id IS DISTINCT FROM id)
);
CREATE TABLE meal_plan_versions (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 meal_plan_id integer NOT NULL REFERENCES meal_plans(id) ON DELETE RESTRICT,
 version_number smallint,
 status text NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published')),
 published_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(meal_plan_id,version_number),
 CHECK ((status = 'draft' AND published_at IS NULL) OR (status = 'published' AND version_number IS NOT NULL AND published_at IS NOT NULL))
);
CREATE TABLE meal_plan_days (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 meal_plan_version_id integer NOT NULL REFERENCES meal_plan_versions(id) ON DELETE RESTRICT,
 day_number smallint NOT NULL CHECK(day_number>0),
 UNIQUE(meal_plan_version_id,day_number)
);
CREATE TABLE meal_types (
 id smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 code text NOT NULL UNIQUE,
 title text NOT NULL,
 sort_order smallint NOT NULL DEFAULT 0
);
CREATE TABLE meals (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 meal_plan_day_id integer NOT NULL REFERENCES meal_plan_days(id) ON DELETE RESTRICT,
 meal_type_id smallint NOT NULL REFERENCES meal_types(id) ON DELETE RESTRICT,
 title text,
 sort_order smallint NOT NULL DEFAULT 0,
 UNIQUE(meal_plan_day_id,meal_type_id)
);
CREATE TABLE meal_items (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 meal_id integer NOT NULL REFERENCES meals(id) ON DELETE RESTRICT,
 food_id integer NOT NULL REFERENCES foods(id) ON DELETE RESTRICT,
 quantity numeric(7,2) NOT NULL CHECK(quantity>0),
 uom_id smallint NOT NULL REFERENCES uoms(id) ON DELETE RESTRICT,
 resolved_quantity_grams numeric(7,2) CHECK(resolved_quantity_grams>0),
 sort_order smallint NOT NULL DEFAULT 0
);
-- Client profile table belongs to the Users & Client Management module.
-- Attach FK after clients table is created; keep this migration independently runnable.
CREATE TABLE client_meal_plan_assignments (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 client_id integer NOT NULL,
 meal_plan_version_id integer NOT NULL REFERENCES meal_plan_versions(id) ON DELETE RESTRICT,
 start_date date NOT NULL,
 end_date date,
 created_at timestamptz NOT NULL DEFAULT now(),
 created_by integer,
 replaced_by_assignment_id integer REFERENCES client_meal_plan_assignments(id) ON DELETE RESTRICT,
 CHECK(end_date IS NULL OR end_date>=start_date),
 CONSTRAINT no_overlapping_client_plan_dates
 EXCLUDE USING gist (client_id WITH =, daterange(start_date,end_date,'[]') WITH &&)
 DEFERRABLE INITIALLY IMMEDIATE
);

-- Only one active draft of a logical recipe or meal plan at a time.
CREATE UNIQUE INDEX recipe_one_draft ON recipe_versions(recipe_id) WHERE status='draft';
CREATE UNIQUE INDEX meal_plan_one_draft ON meal_plan_versions(meal_plan_id) WHERE status='draft';
CREATE INDEX recipe_ingredients_version_idx ON recipe_ingredients(recipe_version_id,sort_order);
CREATE INDEX recipe_directions_version_idx ON recipe_directions(recipe_version_id,direction_number);
CREATE INDEX meal_items_meal_idx ON meal_items(meal_id,sort_order);
CREATE INDEX meal_items_food_idx ON meal_items(food_id);
CREATE INDEX recipe_ingredients_ingredient_idx ON recipe_ingredients(ingredient_id);
CREATE INDEX recipe_versions_recipe_idx ON recipe_versions(recipe_id);
CREATE INDEX meal_plan_days_version_idx ON meal_plan_days(meal_plan_version_id);
CREATE INDEX meals_day_idx ON meals(meal_plan_day_id);
CREATE INDEX recipe_equipment_version_idx ON recipe_kitchen_equipment(recipe_version_id);
CREATE INDEX assignment_client_idx ON client_meal_plan_assignments(client_id);
CREATE INDEX assignment_version_idx ON client_meal_plan_assignments(meal_plan_version_id);

-- Immutable after publication, including all descendant detail rows.
CREATE FUNCTION assert_recipe_version_editable(p_id integer) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS (SELECT 1 FROM recipe_versions WHERE id=p_id AND status='published' FOR UPDATE) THEN
   RAISE EXCEPTION 'Published recipe version % is immutable',p_id;
 END IF;
END $$;
CREATE FUNCTION assert_plan_version_editable(p_id integer) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS (SELECT 1 FROM meal_plan_versions WHERE id=p_id AND status='published' FOR UPDATE) THEN
   RAISE EXCEPTION 'Published meal plan version % is immutable',p_id;
 END IF;
END $$;
CREATE FUNCTION guard_recipe_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' AND OLD.status='published' THEN RAISE EXCEPTION 'Published recipe version immutable'; END IF;
 IF TG_OP='UPDATE' AND OLD.status='published' THEN RAISE EXCEPTION 'Published recipe version immutable'; END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER recipe_version_guard BEFORE UPDATE OR DELETE ON recipe_versions FOR EACH ROW EXECUTE FUNCTION guard_recipe_version();
CREATE FUNCTION guard_plan_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' AND OLD.status='published' THEN RAISE EXCEPTION 'Published meal plan version immutable'; END IF;
 IF TG_OP='UPDATE' AND OLD.status='published' THEN RAISE EXCEPTION 'Published meal plan version immutable'; END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER plan_version_guard BEFORE UPDATE OR DELETE ON meal_plan_versions FOR EACH ROW EXECUTE FUNCTION guard_plan_version();
CREATE FUNCTION guard_recipe_detail() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP IN ('UPDATE','DELETE') THEN PERFORM assert_recipe_version_editable(OLD.recipe_version_id); END IF;
 IF TG_OP IN ('INSERT','UPDATE') THEN PERFORM assert_recipe_version_editable(NEW.recipe_version_id); END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER recipe_components_guard BEFORE INSERT OR UPDATE OR DELETE ON recipe_components FOR EACH ROW EXECUTE FUNCTION guard_recipe_detail();
CREATE TRIGGER recipe_ingredients_guard BEFORE INSERT OR UPDATE OR DELETE ON recipe_ingredients FOR EACH ROW EXECUTE FUNCTION guard_recipe_detail();
CREATE TRIGGER recipe_directions_guard BEFORE INSERT OR UPDATE OR DELETE ON recipe_directions FOR EACH ROW EXECUTE FUNCTION guard_recipe_detail();
CREATE TRIGGER recipe_equipment_guard BEFORE INSERT OR UPDATE OR DELETE ON recipe_kitchen_equipment FOR EACH ROW EXECUTE FUNCTION guard_recipe_detail();
CREATE FUNCTION guard_plan_detail() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_old integer; v_new integer;
BEGIN
 IF TG_TABLE_NAME='meal_plan_days' THEN
   IF TG_OP IN ('UPDATE','DELETE') THEN v_old:=OLD.meal_plan_version_id; END IF;
   IF TG_OP IN ('INSERT','UPDATE') THEN v_new:=NEW.meal_plan_version_id; END IF;
 ELSIF TG_TABLE_NAME='meals' THEN
   IF TG_OP IN ('UPDATE','DELETE') THEN SELECT meal_plan_version_id INTO v_old FROM meal_plan_days WHERE id=OLD.meal_plan_day_id; END IF;
   IF TG_OP IN ('INSERT','UPDATE') THEN SELECT meal_plan_version_id INTO v_new FROM meal_plan_days WHERE id=NEW.meal_plan_day_id; END IF;
 ELSE
   IF TG_OP IN ('UPDATE','DELETE') THEN SELECT d.meal_plan_version_id INTO v_old FROM meals m JOIN meal_plan_days d ON d.id=m.meal_plan_day_id WHERE m.id=OLD.meal_id; END IF;
   IF TG_OP IN ('INSERT','UPDATE') THEN SELECT d.meal_plan_version_id INTO v_new FROM meals m JOIN meal_plan_days d ON d.id=m.meal_plan_day_id WHERE m.id=NEW.meal_id; END IF;
 END IF;
 IF v_old IS NOT NULL THEN PERFORM assert_plan_version_editable(v_old); END IF;
 IF v_new IS NOT NULL THEN PERFORM assert_plan_version_editable(v_new); END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER meal_days_guard BEFORE INSERT OR UPDATE OR DELETE ON meal_plan_days FOR EACH ROW EXECUTE FUNCTION guard_plan_detail();
CREATE TRIGGER meals_guard BEFORE INSERT OR UPDATE OR DELETE ON meals FOR EACH ROW EXECUTE FUNCTION guard_plan_detail();
CREATE TRIGGER meal_items_guard BEFORE INSERT OR UPDATE OR DELETE ON meal_items FOR EACH ROW EXECUTE FUNCTION guard_plan_detail();


-- Force the two-phase publishing workflow: create draft first, then UPDATE to published.
CREATE FUNCTION reject_direct_published_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status='published' THEN
   RAISE EXCEPTION 'Create a draft first; publish through a validated UPDATE';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER recipe_version_insert_draft_only BEFORE INSERT ON recipe_versions
 FOR EACH ROW EXECUTE FUNCTION reject_direct_published_insert();
CREATE TRIGGER meal_plan_version_insert_draft_only BEFORE INSERT ON meal_plan_versions
 FOR EACH ROW EXECUTE FUNCTION reject_direct_published_insert();

-- CHECKS AT THE POINT OF PUBLICATION/ASSIGNMENT.
-- The Laravel publish service must use a single transaction and lock the version row.
-- Database triggers below additionally protect against bypassing the service.
CREATE FUNCTION validate_recipe_publication() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE bad integer;
BEGIN
 IF NEW.status <> 'published' OR OLD.status = 'published' THEN RETURN NEW; END IF;
 IF NOT EXISTS (SELECT 1 FROM foods f WHERE f.id=NEW.food_id AND f.food_type='recipe_version') THEN
   RAISE EXCEPTION 'Recipe version % has no corresponding foods row',NEW.id;
 END IF;
 IF NOT EXISTS (SELECT 1 FROM recipe_ingredients ri WHERE ri.recipe_version_id=NEW.id) THEN
   RAISE EXCEPTION 'Cannot publish recipe % without ingredients',NEW.id;
 END IF;
 SELECT ri.id INTO bad FROM recipe_ingredients ri
 LEFT JOIN ingredient_uoms iu ON iu.ingredient_id=ri.ingredient_id AND iu.uom_id=ri.uom_id
 JOIN uoms u ON u.id=ri.uom_id
 WHERE ri.recipe_version_id=NEW.id
   AND NOT (u.code='g' OR iu.ingredient_id IS NOT NULL)
 LIMIT 1;
 IF bad IS NOT NULL THEN RAISE EXCEPTION 'Invalid ingredient unit in recipe_ingredients %',bad; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER recipe_publish_check BEFORE UPDATE OF status ON recipe_versions
 FOR EACH ROW EXECUTE FUNCTION validate_recipe_publication();

CREATE FUNCTION validate_food_measure(p_food_id integer,p_uom_id smallint,p_grams numeric)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE v_type text; v_code text; v_ingredient_id integer;
BEGIN
 SELECT food_type INTO v_type FROM foods WHERE id=p_food_id;
 SELECT code INTO v_code FROM uoms WHERE id=p_uom_id;
 IF v_type='ingredient' THEN
   SELECT id INTO v_ingredient_id FROM ingredients WHERE food_id=p_food_id;
   IF v_ingredient_id IS NULL THEN RAISE EXCEPTION 'Food % has no ingredient',p_food_id; END IF;
   IF v_code <> 'g' AND NOT EXISTS
       (SELECT 1 FROM ingredient_uoms WHERE ingredient_id=v_ingredient_id AND uom_id=p_uom_id) THEN
     RAISE EXCEPTION 'Unit % is not permitted for ingredient %',p_uom_id,v_ingredient_id;
   END IF;
   IF p_grams IS NULL OR p_grams<=0 THEN RAISE EXCEPTION 'Ingredient food requires resolved grams'; END IF;
 ELSIF v_type='recipe_version' THEN
   IF NOT EXISTS (SELECT 1 FROM recipe_versions WHERE food_id=p_food_id AND status='published') THEN
     RAISE EXCEPTION 'Recipe food % has no published version',p_food_id;
   END IF;
   IF v_code <> 'serving' OR p_grams IS NOT NULL THEN
     RAISE EXCEPTION 'Recipe food requires serving unit and NULL resolved grams';
   END IF;
 ELSE RAISE EXCEPTION 'Unknown food %',p_food_id;
 END IF;
END $$;

CREATE FUNCTION guard_meal_item_measure() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM validate_food_measure(NEW.food_id,NEW.uom_id,NEW.resolved_quantity_grams);
 RETURN NEW;
END $$;
CREATE TRIGGER meal_item_measure_check BEFORE INSERT OR UPDATE ON meal_items
 FOR EACH ROW EXECUTE FUNCTION guard_meal_item_measure();

CREATE FUNCTION guard_recipe_ingredient_measure() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_code text;
BEGIN
 SELECT code INTO v_code FROM uoms WHERE id=NEW.uom_id;
 IF v_code <> 'g' AND NOT EXISTS
    (SELECT 1 FROM ingredient_uoms WHERE ingredient_id=NEW.ingredient_id AND uom_id=NEW.uom_id) THEN
   RAISE EXCEPTION 'Unit not allowed for recipe ingredient';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER recipe_ingredient_measure_check BEFORE INSERT OR UPDATE ON recipe_ingredients
 FOR EACH ROW EXECUTE FUNCTION guard_recipe_ingredient_measure();

CREATE FUNCTION validate_plan_publication() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_total integer; v_distinct integer; v_min integer; v_max integer;
BEGIN
 IF NEW.status <> 'published' OR OLD.status = 'published' THEN RETURN NEW; END IF;
 SELECT count(*),count(DISTINCT day_number),min(day_number),max(day_number)
 INTO v_total,v_distinct,v_min,v_max FROM meal_plan_days WHERE meal_plan_version_id=NEW.id;
 IF v_total<7 OR v_total%7<>0 OR v_distinct<>v_total OR v_min<>1 OR v_max<>v_total THEN
   RAISE EXCEPTION 'Meal plan must have contiguous 7-day week blocks';
 END IF;
 IF EXISTS (SELECT 1 FROM meal_plan_days d WHERE d.meal_plan_version_id=NEW.id
   AND NOT EXISTS (SELECT 1 FROM meals m WHERE m.meal_plan_day_id=d.id)) THEN
   RAISE EXCEPTION 'Each day must contain at least one meal';
 END IF;
 IF EXISTS (SELECT 1 FROM meal_plan_days d JOIN meals m ON m.meal_plan_day_id=d.id
   WHERE d.meal_plan_version_id=NEW.id AND NOT EXISTS (SELECT 1 FROM meal_items i WHERE i.meal_id=m.id)) THEN
   RAISE EXCEPTION 'Each meal must contain at least one item';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER meal_plan_publish_check BEFORE UPDATE OF status ON meal_plan_versions
 FOR EACH ROW EXECUTE FUNCTION validate_plan_publication();

CREATE FUNCTION guard_assignment_published() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM meal_plan_versions
   WHERE id=NEW.meal_plan_version_id AND status='published' FOR SHARE) THEN
   RAISE EXCEPTION 'Only published meal plan versions can be assigned';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER assignment_published_check BEFORE INSERT OR UPDATE OF meal_plan_version_id
 ON client_meal_plan_assignments FOR EACH ROW EXECUTE FUNCTION guard_assignment_published();

-- A foods row must be consumed by exactly one typed child by transaction commit.
-- DEFERRABLE constraint trigger allows parent+child creation in a single transaction.
CREATE FUNCTION check_food_has_child() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE current_type text; v_count integer;
BEGIN
 SELECT food_type INTO current_type FROM foods WHERE id=NEW.id;
 IF NOT FOUND THEN RETURN NULL; END IF;
 IF current_type='ingredient' THEN
   SELECT count(*) INTO v_count FROM ingredients WHERE food_id=NEW.id;
 ELSE
   SELECT count(*) INTO v_count FROM recipe_versions WHERE food_id=NEW.id AND status='published';
 END IF;
 IF v_count<>1 THEN RAISE EXCEPTION 'Food % must have exactly one typed child',NEW.id; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER food_has_child_after_insert AFTER INSERT OR UPDATE ON foods
 DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_food_has_child();

-- Prevent deleting a typed child while leaving an orphan foods row.
CREATE FUNCTION prevent_food_child_detach() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN
   IF EXISTS (SELECT 1 FROM foods WHERE id=OLD.food_id) THEN
     RAISE EXCEPTION 'Delete its foods parent in the same supported archival workflow, not its typed child';
   END IF;
 ELSIF NEW.food_id IS DISTINCT FROM OLD.food_id THEN
   RAISE EXCEPTION 'Cannot change typed-child food_id';
 END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER ingredient_keep_food BEFORE DELETE OR UPDATE OF food_id ON ingredients
 FOR EACH ROW EXECUTE FUNCTION prevent_food_child_detach();
-- Published recipe_version rows are already protected by recipe_version_guard.

-- Immutability policy for shared labels/codes: never redefine uoms.code;
-- treat reference data as corrections only, not changes in meaning.
-- Verify gram resolutions while publishing, not on reads of historical versions.
-- The editor may store intermediate draft values; only published content must reconcile.
CREATE FUNCTION assert_ingredient_grams(
 p_ingredient_id integer, p_uom_id smallint,
 p_quantity numeric, p_resolved_grams numeric
) RETURNS void LANGUAGE plpgsql AS $$
DECLARE v_factor numeric; v_code text; v_expected numeric;
BEGIN
 SELECT code INTO v_code FROM uoms WHERE id=p_uom_id;
 IF v_code='g' THEN
   v_factor := 1;
 ELSE
   SELECT grams_per_uom INTO v_factor
   FROM ingredient_uoms WHERE ingredient_id=p_ingredient_id AND uom_id=p_uom_id;
 END IF;
 IF v_factor IS NULL THEN
   RAISE EXCEPTION 'Unit % is not allowed for ingredient %',p_uom_id,p_ingredient_id;
 END IF;
 v_expected := round(p_quantity*v_factor,2);
 IF v_expected > 99999.99 THEN
   RAISE EXCEPTION 'Resolved grams exceed NUMERIC(7,2)';
 END IF;
 IF p_resolved_grams IS DISTINCT FROM v_expected THEN
   RAISE EXCEPTION 'Expected % g; got % g for ingredient %',v_expected,p_resolved_grams,p_ingredient_id;
 END IF;
END $$;

-- Publication is a status UPDATE, so validations execute while the draft is being frozen.
CREATE OR REPLACE FUNCTION validate_recipe_publication() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record;
BEGIN
 IF NEW.status <> 'published' OR OLD.status = 'published' THEN RETURN NEW; END IF;
 IF NOT EXISTS (SELECT 1 FROM foods f WHERE f.id=NEW.food_id AND f.food_type='recipe_version') THEN
   RAISE EXCEPTION 'Recipe version % has no corresponding foods row',NEW.id;
 END IF;
 IF NOT EXISTS (SELECT 1 FROM recipe_ingredients ri WHERE ri.recipe_version_id=NEW.id) THEN
   RAISE EXCEPTION 'Cannot publish recipe % without ingredients',NEW.id;
 END IF;
 FOR r IN SELECT ingredient_id,uom_id,quantity,quantity_grams
          FROM recipe_ingredients WHERE recipe_version_id=NEW.id LOOP
   PERFORM assert_ingredient_grams(r.ingredient_id,r.uom_id,r.quantity,r.quantity_grams);
 END LOOP;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION validate_plan_publication() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_total integer; v_distinct integer; v_min integer; v_max integer; r record; v_ingredient_id integer;
BEGIN
 IF NEW.status <> 'published' OR OLD.status = 'published' THEN RETURN NEW; END IF;
 SELECT count(*),count(DISTINCT day_number),min(day_number),max(day_number)
 INTO v_total,v_distinct,v_min,v_max FROM meal_plan_days WHERE meal_plan_version_id=NEW.id;
 IF v_total<7 OR v_total%7<>0 OR v_distinct<>v_total OR v_min<>1 OR v_max<>v_total THEN
   RAISE EXCEPTION 'Meal plan must have contiguous 7-day week blocks';
 END IF;
 IF EXISTS (SELECT 1 FROM meal_plan_days d WHERE d.meal_plan_version_id=NEW.id
   AND NOT EXISTS (SELECT 1 FROM meals m WHERE m.meal_plan_day_id=d.id)) THEN
   RAISE EXCEPTION 'Each day must contain at least one meal';
 END IF;
 IF EXISTS (SELECT 1 FROM meal_plan_days d JOIN meals m ON m.meal_plan_day_id=d.id
   WHERE d.meal_plan_version_id=NEW.id AND NOT EXISTS (SELECT 1 FROM meal_items i WHERE i.meal_id=m.id)) THEN
   RAISE EXCEPTION 'Each meal must contain at least one item';
 END IF;
 FOR r IN SELECT i.* FROM meal_plan_days d
          JOIN meals m ON m.meal_plan_day_id=d.id
          JOIN meal_items i ON i.meal_id=m.id
          WHERE d.meal_plan_version_id=NEW.id LOOP
   PERFORM validate_food_measure(r.food_id,r.uom_id,r.resolved_quantity_grams);
   SELECT id INTO v_ingredient_id FROM ingredients WHERE food_id=r.food_id;
   IF FOUND THEN
     PERFORM assert_ingredient_grams(v_ingredient_id,r.uom_id,r.quantity,r.resolved_quantity_grams);
   END IF;
 END LOOP;
 RETURN NEW;
END $$;

-- Lock unit codes after creation. The codes 'g' and 'serving' have program semantics.
CREATE FUNCTION protect_uom_code() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.code IS DISTINCT FROM OLD.code THEN
   RAISE EXCEPTION 'uoms.code is immutable; create another UOM instead';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER uom_code_immutable BEFORE UPDATE OF code ON uoms
 FOR EACH ROW EXECUTE FUNCTION protect_uom_code();

-- Additional invariants in Laravel application services:
-- 1) use transactional publication and row locks; source calculations are rounded only at persistence;
-- 2) resolved_quantity_grams must match quantity * correct conversion at publication;
-- 3) validate recipe direction tokens, category completeness, and servings_count;
-- 4) prohibit removal/retyping of published foods and semantic mutation of lookup codes;
-- 5) log assignment date changes into client_events (see 04_fitness_messaging.sql).
-- PostgreSQL runtime execution/integration testing is still required.
COMMIT;
