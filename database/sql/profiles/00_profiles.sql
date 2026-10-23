-- Forward-only ревізія; legacy-значення залишаються доступними для звірки.
ALTER TABLE users ADD COLUMN first_name text, ADD COLUMN last_name text;
ALTER TABLE users ALTER COLUMN name DROP NOT NULL;
ALTER TABLE users ADD CONSTRAINT users_name_parts CHECK (
 (first_name IS NULL AND last_name IS NULL) OR
 (first_name IS NOT NULL AND last_name IS NOT NULL AND length(btrim(first_name)) > 0 AND length(btrim(last_name)) > 0)
);
-- Не вгадуємо складові історичного name. До ручного уточнення обидві NULL.

CREATE FUNCTION client_weekdays_valid(days text[]) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
 SELECT days IS NULL OR (
   coalesce(array_ndims(days), 1) = 1
   AND NOT EXISTS (SELECT FROM unnest(days) d WHERE d IS NULL OR d NOT IN
     ('monday','tuesday','wednesday','thursday','friday','saturday','sunday'))
   AND cardinality(days) = (SELECT count(DISTINCT d) FROM unnest(days) d)
 );
$$;
CREATE TABLE client_profiles (
 client_id integer PRIMARY KEY REFERENCES clients(id) ON DELETE CASCADE,
 birth_date date CHECK (birth_date IS NULL OR (isfinite(birth_date) AND birth_date <= CURRENT_DATE)),
 height_cm smallint CHECK (height_cm > 0),
 sex text CHECK (sex IN ('male','female')),
 goal text,
 difficulties text,
 priorities text,
 work_mode text CHECK (work_mode IN ('remote','office','hybrid')),
 updated_at timestamptz NOT NULL DEFAULT now()
);
INSERT INTO client_profiles (client_id, goal, updated_at)
 SELECT client_id, goal, updated_at FROM client_nutrition_profiles WHERE goal IS NOT NULL;
-- goal збережено як legacy_goal для контрольної звірки; нові записи лише у client_profiles.
ALTER TABLE client_nutrition_profiles RENAME COLUMN goal TO legacy_goal;
ALTER TABLE client_nutrition_profiles
 ADD COLUMN maintenance_calories smallint CHECK (maintenance_calories >= 0),
 ADD COLUMN target_calories smallint CHECK (target_calories >= 0),
 ADD COLUMN weekly_free_calories smallint CHECK (weekly_free_calories >= 0),
 ADD COLUMN kitchen_appliances text,
 ADD COLUMN preferred_cooking_days text[] CHECK (client_weekdays_valid(preferred_cooking_days));

ALTER TABLE client_activity_profiles RENAME COLUMN preferred_training_days TO legacy_preferred_training_days;
ALTER TABLE client_activity_profiles
 ADD COLUMN fitness_equipment text,
 ADD COLUMN preferred_training_days text[] CHECK (client_weekdays_valid(preferred_training_days));
-- Невідомі, не-масиви, NULL-елементи та повтори не виправляємо навмання.
UPDATE client_activity_profiles SET preferred_training_days = ARRAY(
 SELECT jsonb_array_elements_text(legacy_preferred_training_days)
) WHERE CASE WHEN jsonb_typeof(legacy_preferred_training_days) = 'array' THEN NOT EXISTS (
   SELECT FROM jsonb_array_elements(legacy_preferred_training_days) d
   WHERE jsonb_typeof(d) <> 'string' OR d #>> '{}' NOT IN
     ('monday','tuesday','wednesday','thursday','friday','saturday','sunday')
 ) AND jsonb_array_length(legacy_preferred_training_days) = (
   SELECT count(DISTINCT d) FROM jsonb_array_elements(legacy_preferred_training_days) d
 ) ELSE false END;
ALTER TABLE client_activity_profiles ALTER COLUMN legacy_preferred_training_days DROP DEFAULT;
ALTER TABLE client_activity_profiles ALTER COLUMN legacy_preferred_training_days DROP NOT NULL;

-- Невизначений JSON не трансформується в довільно обрані години/дні.
ALTER TABLE client_settings
 ADD COLUMN morning_message_time time,
 ADD COLUMN weekly_checkin_day text CHECK (weekly_checkin_day IN
 ('monday','tuesday','wednesday','thursday','friday','saturday','sunday'));
COMMENT ON COLUMN client_settings.morning_message_time IS 'Локальний час client_settings.timezone, не UTC; scheduler ще не реалізовано';

-- Немає repo-посилань на id; залишаємо самі значення та UNIQUE для сумісності.
-- Невідомий зовнішній FK на старий PK зупинить DDL транзакційно, без CASCADE.
ALTER TABLE client_onboardings ADD CONSTRAINT client_onboardings_legacy_id_unique UNIQUE (id);
ALTER TABLE client_onboardings DROP CONSTRAINT client_onboardings_pkey;
ALTER TABLE client_onboardings ADD PRIMARY KEY (client_id);
ALTER TABLE client_onboardings ALTER COLUMN answers DROP DEFAULT;
ALTER TABLE client_onboardings ALTER COLUMN answers TYPE json USING answers::json;
ALTER TABLE client_onboardings ALTER COLUMN answers SET DEFAULT '{}'::json;
CREATE FUNCTION client_onboarding_archive_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
   IF OLD.completed_at IS NOT NULL AND EXISTS (SELECT FROM clients WHERE id = OLD.client_id) THEN
     RAISE EXCEPTION 'Завершена анкета є незмінним архівом' USING ERRCODE = '23514';
   END IF;
   RETURN OLD;
 END IF;
 -- Після завершення заборонено будь-який UPDATE, включно з no-op та службовими полями.
 IF OLD.completed_at IS NOT NULL THEN
   RAISE EXCEPTION 'Завершена анкета є незмінним архівом' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER client_onboarding_archive_guard BEFORE UPDATE OR DELETE ON client_onboardings
 FOR EACH ROW EXECUTE FUNCTION client_onboarding_archive_guard();
