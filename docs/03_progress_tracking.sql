-- PostgreSQL 15+ | Progress Tracking | DDL draft (aligned with schema audit v0.3) | 2026-10-20
-- Apply after 02_users_clients.sql.
-- Five business tables; no new entities beyond Entity Catalog v2.
BEGIN;

CREATE TABLE metrics (
    id smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code varchar(60) NOT NULL UNIQUE,
    name varchar(120) NOT NULL,
    unit_label varchar(20),
    value_kind varchar(12) NOT NULL DEFAULT 'decimal'
        CHECK (value_kind IN ('decimal','integer')),
    min_value numeric(7,2),
    max_value numeric(7,2),
    sort_order smallint NOT NULL DEFAULT 0,
    is_enabled boolean NOT NULL DEFAULT true,
    CHECK (min_value IS NULL OR max_value IS NULL OR min_value <= max_value)
);

CREATE TABLE client_metric_assignments (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_id integer NOT NULL REFERENCES clients(id) ON DELETE RESTRICT,
    metric_id smallint NOT NULL REFERENCES metrics(id) ON DELETE RESTRICT,
    assigned_at timestamptz NOT NULL DEFAULT now(),
    unassigned_at timestamptz,
    created_by integer REFERENCES users(id) ON DELETE RESTRICT,
    CHECK (unassigned_at IS NULL OR unassigned_at >= assigned_at)
);
CREATE UNIQUE INDEX client_metric_one_current_assignment
    ON client_metric_assignments(client_id, metric_id) WHERE unassigned_at IS NULL;
CREATE INDEX client_metric_assignments_metric_idx ON client_metric_assignments(metric_id);

CREATE TABLE client_weekly_reports (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_id integer NOT NULL REFERENCES clients(id) ON DELETE RESTRICT,
    week_start_date date NOT NULL,
    status varchar(12) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'submitted')),
    answers jsonb NOT NULL DEFAULT '{}'::jsonb
        CHECK (jsonb_typeof(answers) = 'object'),
    submitted_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (client_id, week_start_date),
    UNIQUE (id, client_id),
    CHECK (EXTRACT(ISODOW FROM week_start_date) = 1),
    CHECK ((status = 'submitted') = (submitted_at IS NOT NULL))
);
-- Report periods run Monday-Sunday; a report may be created as a draft.

CREATE TABLE client_metrics (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_id integer NOT NULL REFERENCES clients(id) ON DELETE RESTRICT,
    metric_id smallint NOT NULL REFERENCES metrics(id) ON DELETE RESTRICT,
    value numeric(7,2) NOT NULL,
    measured_at timestamptz NOT NULL,
    recorded_at timestamptz NOT NULL DEFAULT now(),
    source varchar(16) NOT NULL DEFAULT 'manual'
        CHECK (source IN ('manual','weekly_report','coach')),
    weekly_report_id integer,
    note text,
    FOREIGN KEY (weekly_report_id,client_id)
      REFERENCES client_weekly_reports(id,client_id) ON DELETE RESTRICT,
    CHECK (source <> 'weekly_report' OR weekly_report_id IS NOT NULL)
);
CREATE INDEX client_metrics_client_time_idx ON client_metrics(client_id, measured_at DESC);
CREATE INDEX client_metrics_client_metric_time_idx ON client_metrics(client_id, metric_id, measured_at DESC);
CREATE INDEX client_metrics_report_idx ON client_metrics(weekly_report_id)
    WHERE weekly_report_id IS NOT NULL;

CREATE TABLE client_progress_photos (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_id integer NOT NULL REFERENCES clients(id) ON DELETE RESTRICT,
    taken_at timestamptz NOT NULL,
    storage_path text NOT NULL,
    view_label varchar(20) CHECK (view_label IN ('front','side','back','other')),
    note text,
    uploaded_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX client_progress_photos_client_time_idx
    ON client_progress_photos(client_id,taken_at DESC);

COMMIT;
-- Application-side contracts (NOT enforced by this DDL):
-- * weekly report collection only after coaching starts, subject to coaching status;
-- * metrics.value respects metrics.min_value/max_value and value_kind;
-- * source='weekly_report' measured_at belongs to week_start_date..+6 days;
-- * dates are mapped to client timezone for report boundaries;
-- * photo access control and retention, upload verification, safe private storage;
-- * clients may enter client_metrics independently of any weekly report.
