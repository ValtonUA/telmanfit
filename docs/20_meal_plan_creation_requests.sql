-- UX-3: forward-only квитанції ідемпотентного створення раціону.
-- Застосування до наявної БД потребує окремого погодженого плану.
CREATE TABLE meal_plan_creation_requests (
    request_key uuid NOT NULL UNIQUE,
    admin_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    parameters_sha256 char(64) NOT NULL CHECK (parameters_sha256 ~ '^[0-9a-f]{64}$'),
    meal_plan_id integer NOT NULL UNIQUE REFERENCES meal_plans(id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (admin_id, request_key)
);
