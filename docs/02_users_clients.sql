-- PostgreSQL 15+ | Users & Client Management | DDL draft (aligned with schema audit v0.3) | 2026-10-20
-- Apply AFTER 01_nutrition_schema.sql.
-- Laravel framework authentication tables may add their own columns in migrations.
BEGIN;
CREATE TABLE users (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 name text NOT NULL,
 email text UNIQUE,
 password text,
 telegram_user_id bigint UNIQUE, -- external Telegram ID: bigint despite compact local PK
 role text NOT NULL DEFAULT 'client' CHECK(role IN ('admin','coach','client')),
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE clients (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 user_id integer UNIQUE REFERENCES users(id) ON DELETE RESTRICT,
 coach_user_id integer NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','active','paused','finished')),
 coaching_started_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK(status <> 'active' OR coaching_started_at IS NOT NULL)
);
CREATE TABLE client_onboardings (
 id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 client_id integer NOT NULL UNIQUE REFERENCES clients(id) ON DELETE CASCADE,
 answers jsonb NOT NULL DEFAULT '{}'::jsonb,
 completed_at timestamptz,
 updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE client_nutrition_profiles (
 client_id integer PRIMARY KEY REFERENCES clients(id) ON DELETE CASCADE,
 goal text,
 preferences text,
 restrictions text,
 updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE client_activity_profiles (
 client_id integer PRIMARY KEY REFERENCES clients(id) ON DELETE CASCADE,
 steps_target integer CHECK(steps_target>=0),
 physical_limitations text,
 preferred_training_days jsonb NOT NULL DEFAULT '[]'::jsonb,
 updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE client_settings (
 client_id integer PRIMARY KEY REFERENCES clients(id) ON DELETE CASCADE,
 timezone text NOT NULL DEFAULT 'Europe/Kyiv',
 reminder_settings jsonb NOT NULL DEFAULT '{}'::jsonb,
 report_settings jsonb NOT NULL DEFAULT '{}'::jsonb,
 updated_at timestamptz NOT NULL DEFAULT now()
);
-- Attach previously deferred cross-module foreign keys.
ALTER TABLE recipes ADD CONSTRAINT fk_recipes_owner_client FOREIGN KEY(owner_client_id) REFERENCES clients(id) ON DELETE RESTRICT;
ALTER TABLE meal_plans ADD CONSTRAINT fk_meal_plans_owner_client FOREIGN KEY(owner_client_id) REFERENCES clients(id) ON DELETE RESTRICT;
ALTER TABLE client_meal_plan_assignments ADD CONSTRAINT fk_plan_assignments_client FOREIGN KEY(client_id) REFERENCES clients(id) ON DELETE RESTRICT;
ALTER TABLE client_meal_plan_assignments ADD CONSTRAINT fk_plan_assignments_author FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT;
CREATE INDEX clients_coach_idx ON clients(coach_user_id);
COMMIT;
-- Laravel authorization MUST ensure coach_user_id refers to an authorized coach (role is not FK-enforced).
-- Telegram initData verification is application-side; no unverified ID-to-user binding.
-- Profile columns are provisional until onboarding data contract is finalized.
