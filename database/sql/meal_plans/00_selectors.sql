-- G4-I / Model I: лише additive DDL, без UPDATE/backfill історії.
-- recipe_batch_formats(id,recipe_version_id) уже UNIQUE у модулі 11.
ALTER TABLE recipe_versions ADD CONSTRAINT recipe_versions_id_food_unique UNIQUE(id,food_id);
ALTER TABLE meal_items
 ADD COLUMN recipe_version_id integer NULL,
 ADD COLUMN recipe_batch_format_id integer NULL,
 ADD COLUMN recipe_portion_divisor smallint NULL,
 ADD CONSTRAINT meal_items_recipe_selector_complete CHECK (
  num_nonnulls(recipe_version_id,recipe_batch_format_id,recipe_portion_divisor)=0 OR
  (num_nonnulls(recipe_version_id,recipe_batch_format_id,recipe_portion_divisor)=3
   AND recipe_portion_divisor IN (1,3,4,7))),
 ADD CONSTRAINT meal_items_recipe_food_fk FOREIGN KEY(recipe_version_id,food_id)
  REFERENCES recipe_versions(id,food_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
 ADD CONSTRAINT meal_items_recipe_format_fk FOREIGN KEY(recipe_batch_format_id,recipe_version_id)
  REFERENCES recipe_batch_formats(id,recipe_version_id) ON UPDATE RESTRICT ON DELETE RESTRICT;
CREATE INDEX meal_items_recipe_version_idx ON meal_items(recipe_version_id) WHERE recipe_version_id IS NOT NULL;
CREATE INDEX meal_items_recipe_format_idx ON meal_items(recipe_batch_format_id) WHERE recipe_batch_format_id IS NOT NULL;

CREATE FUNCTION assert_meal_item_selector(p meal_items) RETURNS void LANGUAGE plpgsql AS $$
DECLARE v recipe_versions; f recipe_batch_formats; selected integer;
BEGIN
 selected:=num_nonnulls(p.recipe_version_id,p.recipe_batch_format_id,p.recipe_portion_divisor);
 IF selected NOT IN (0,3) THEN RAISE EXCEPTION 'Recipe selector must be complete'; END IF;
 IF (SELECT food_type FROM foods WHERE id=p.food_id)='ingredient' THEN
  IF selected<>0 THEN RAISE EXCEPTION 'Ingredient requires NULL recipe selectors'; END IF;
  RETURN;
 END IF;
 -- Блокуємо root, як recipe writer/publication, перед читанням frozen format.
 -- Не беремо child locks: detail writer спочатку змінює child, потім блокує root.
 -- FOR SHARE перечитує root після конкурентної publication; published root і всі
 -- descendants уже незмінні під guards 01/11/13. Mutable latest тут немає.
 SELECT * INTO v FROM recipe_versions
  WHERE id=coalesce(p.recipe_version_id,(SELECT id FROM recipe_versions WHERE food_id=p.food_id)) FOR SHARE;
 IF NOT FOUND OR v.food_id IS DISTINCT FROM p.food_id OR v.status<>'published' THEN
  RAISE EXCEPTION 'Recipe selector requires exact published food/version';
 END IF;
 IF v.editor_contract='legacy_v1' THEN
  IF selected<>0 THEN RAISE EXCEPTION 'Legacy recipe requires NULL selectors'; END IF;
 ELSIF v.editor_contract='batch_v1' THEN
  IF selected<>3 THEN RAISE EXCEPTION 'Batch recipe requires explicit selectors'; END IF;
  SELECT * INTO f FROM recipe_batch_formats WHERE id=p.recipe_batch_format_id AND recipe_version_id=v.id;
  IF NOT FOUND THEN RAISE EXCEPTION 'Recipe format does not belong to version'; END IF;
  IF NOT ((f.code='single' AND p.recipe_portion_divisor=1)
   OR (f.code='week' AND p.recipe_portion_divisor=7)
   OR (f.code='half_week' AND p.recipe_portion_divisor IN (3,4))) THEN
   RAISE EXCEPTION 'Recipe format/divisor mismatch';
  END IF;
 ELSE RAISE EXCEPTION 'Unknown recipe editor contract';
 END IF;
END $$;

-- Розширюємо чинний measure trigger, не дублюємо і не послаблюємо його.
CREATE OR REPLACE FUNCTION guard_meal_item_measure() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM assert_meal_item_selector(NEW);
 PERFORM validate_food_measure(NEW.food_id,NEW.uom_id,NEW.resolved_quantity_grams);
 RETURN NEW;
END $$;
