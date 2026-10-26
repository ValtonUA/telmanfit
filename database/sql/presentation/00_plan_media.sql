-- UX-4E: презентаційні cover поза frozen snapshots; початок задається лише при створенні.
ALTER TABLE ingredients ADD COLUMN IF NOT EXISTS image_url text NULL;
ALTER TABLE recipes ADD COLUMN IF NOT EXISTS image_url text NULL;
ALTER TABLE meal_plans ADD COLUMN IF NOT EXISTS week_start_weekday smallint NOT NULL DEFAULT 1
 CHECK (week_start_weekday BETWEEN 1 AND 7);

CREATE OR REPLACE FUNCTION guard_plan_week_start() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.week_start_weekday IS DISTINCT FROM OLD.week_start_weekday THEN
  RAISE EXCEPTION 'Meal plan week start is immutable; select it when creating the plan';
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE TRIGGER plan_week_start_immutable BEFORE UPDATE OF week_start_weekday ON meal_plans
 FOR EACH ROW EXECUTE FUNCTION guard_plan_week_start();

-- Зберігаємо fingerprint до rollout і при зміні cover; решта залежностей незмінна.
DO $rollout$ BEGIN
 IF to_regprocedure('recipe_batch_source_hash(integer)') IS NOT NULL THEN
  EXECUTE $definition$
   CREATE OR REPLACE FUNCTION recipe_batch_source_hash(p_ingredient integer) RETURNS text LANGUAGE sql STABLE AS $body$
    SELECT md5(jsonb_build_object('ingredient',to_jsonb(i)-'image_url','conversions',
     (SELECT coalesce(jsonb_agg(jsonb_build_object('uom',to_jsonb(u),'conversion',to_jsonb(c)) ORDER BY u.id),'[]'::jsonb)
      FROM ingredient_uoms c JOIN uoms u ON u.id=c.uom_id WHERE c.ingredient_id=i.id),
     'gram',(SELECT to_jsonb(u) FROM uoms u WHERE code='g'))::text) FROM ingredients i WHERE i.id=p_ingredient
   $body$;
  $definition$;
 END IF;
END $rollout$;
