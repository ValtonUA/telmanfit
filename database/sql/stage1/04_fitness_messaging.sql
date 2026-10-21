-- Незмінний знімок Stage 1 із docs/04_fitness_messaging.sql; адаптації G3/R1 описані в docs/stage-1.md.
-- PostgreSQL 15+ | Fitness Equipment + Messaging & Automation | DDL draft (aligned with schema audit v0.3)
-- Depends on modules 01 nutrition, 02 users & clients, 03 progress tracking.
-- Business tables 33-37; Laravel scheduler/queue implementation lives outside this schema.

CREATE TABLE fitness_equipment (
    id smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name varchar(120) NOT NULL UNIQUE,
    description text,
    is_active boolean NOT NULL DEFAULT true
);

CREATE TABLE client_fitness_equipment (
    client_id integer NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    fitness_equipment_id smallint NOT NULL REFERENCES fitness_equipment(id) ON DELETE RESTRICT,
    quantity smallint NOT NULL DEFAULT 1 CHECK (quantity > 0),
    note text,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (client_id, fitness_equipment_id)
);
CREATE INDEX client_fitness_equipment_equipment_idx
    ON client_fitness_equipment(fitness_equipment_id);

-- Template stores Telegram text and application-parsed button/substitution tokens.
-- Template versioning is intentionally out of scope; outbound_messages stores rendered text.
CREATE TABLE message_templates (
    id smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code varchar(100) NOT NULL UNIQUE,
    title varchar(150) NOT NULL,
    body text NOT NULL CHECK (length(btrim(body)) > 0),
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE outbound_messages (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_id integer NOT NULL REFERENCES clients(id) ON DELETE RESTRICT,
    message_template_id smallint REFERENCES message_templates(id) ON DELETE RESTRICT,
    rendered_text text NOT NULL CHECK (length(btrim(rendered_text)) > 0),
    -- Buttons/payload after rendering belong to this immutable delivery snapshot.
    rendered_markup jsonb CHECK (
       rendered_markup IS NULL OR jsonb_typeof(rendered_markup) IN ('object', 'array')
    ),
    status varchar(12) NOT NULL DEFAULT 'pending'
       CHECK (status IN ('pending','sending','sent','failed','cancelled')),
    scheduled_at timestamptz NOT NULL,
    sent_at timestamptz,
    attempt_count smallint NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    last_error text,
    provider_message_id varchar(120),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'sent' OR sent_at IS NOT NULL),
    CHECK (status = 'sent' OR sent_at IS NULL)
);
CREATE INDEX outbound_messages_pending_due_idx
    ON outbound_messages(scheduled_at, id) WHERE status = 'pending';
CREATE INDEX outbound_messages_client_created_idx
    ON outbound_messages(client_id, created_at DESC);
CREATE INDEX outbound_messages_status_scheduled_idx
    ON outbound_messages(status, scheduled_at);

CREATE TABLE client_events (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_id integer NOT NULL REFERENCES clients(id) ON DELETE RESTRICT,
    event_type varchar(80) NOT NULL,
    event_data jsonb NOT NULL DEFAULT '{}'::jsonb
       CHECK (jsonb_typeof(event_data) = 'object'),
    actor_user_id bigint REFERENCES users(id) ON DELETE RESTRICT,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX client_events_client_occurred_idx
    ON client_events(client_id, occurred_at DESC, id DESC);
CREATE INDEX client_events_type_occurred_idx
    ON client_events(event_type, occurred_at DESC);


-- Application/service invariants (NOT guaranteed by this DDL):
-- * Token substitution, Telegram button payload and escaping validation.
-- * Permission checks; coaching must be active for automated coaching messages.
-- * Scheduler idempotency and single-claim delivery using row locks / SKIP LOCKED.
-- * Telegram API retries, rate limits, deduplication and provider-response handling.
-- * Sensitive event_data redaction, retention rules, access control.
-- * Automatic timestamps and status transitions from the service, not DB triggers.
