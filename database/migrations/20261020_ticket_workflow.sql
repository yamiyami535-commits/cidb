BEGIN;
SET LOCAL lock_timeout = '5s';

ALTER TABLE portal_users
    ADD COLUMN IF NOT EXISTS role varchar(24) NOT NULL DEFAULT 'agent';

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'portal_users'::regclass
          AND conname = 'ck_portal_users_role'
    ) THEN
        ALTER TABLE portal_users
            ADD CONSTRAINT ck_portal_users_role
            CHECK (role IN ('admin', 'manager', 'agent'));
    END IF;
END $$;

ALTER TABLE portal_requests
    ADD COLUMN IF NOT EXISTS customer_type varchar(16),
    ADD COLUMN IF NOT EXISTS contact_number varchar(40),
    ADD COLUMN IF NOT EXISTS language varchar(8),
    ADD COLUMN IF NOT EXISTS company_name varchar(200),
    ADD COLUMN IF NOT EXISTS company_no varchar(80);

ALTER TABLE portal_email_intake
    ADD COLUMN IF NOT EXISTS raw_message jsonb NOT NULL DEFAULT '{}'::jsonb;

CREATE TABLE IF NOT EXISTS portal_ticket_scenarios (
    scenario_key varchar(80) PRIMARY KEY,
    name varchar(160) NOT NULL,
    customer_type varchar(16) NOT NULL,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (customer_type IN ('individual', 'company', 'unknown')),
    CHECK (length(trim(name)) > 0)
);

INSERT INTO portal_ticket_scenarios (scenario_key, name, customer_type)
VALUES
    ('cancellation-classification-pending', 'Cancellation - classification pending', 'unknown'),
    ('cancellation-individual', 'Cancellation - individual', 'individual'),
    ('cancellation-company', 'Cancellation - company', 'company')
ON CONFLICT (scenario_key) DO NOTHING;

CREATE TABLE IF NOT EXISTS portal_ticket_buckets (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    scenario_key varchar(80) NOT NULL REFERENCES portal_ticket_scenarios(scenario_key) ON DELETE RESTRICT,
    bucket_key varchar(80) NOT NULL,
    name varchar(160) NOT NULL,
    is_default boolean NOT NULL DEFAULT false,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (scenario_key, bucket_key),
    CHECK (length(trim(bucket_key)) > 0),
    CHECK (length(trim(name)) > 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_portal_ticket_buckets_default
    ON portal_ticket_buckets (scenario_key) WHERE is_default;

INSERT INTO portal_ticket_buckets (scenario_key, bucket_key, name, is_default)
VALUES
    ('cancellation-classification-pending', 'unassigned', 'Unassigned', true),
    ('cancellation-individual', 'unassigned', 'Unassigned', true),
    ('cancellation-company', 'unassigned', 'Unassigned', true)
ON CONFLICT (scenario_key, bucket_key) DO NOTHING;

CREATE TABLE IF NOT EXISTS portal_tickets (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    source_type varchar(16) NOT NULL,
    source_ref varchar(255) NOT NULL,
    portal_request_id uuid UNIQUE REFERENCES portal_requests(id) ON DELETE RESTRICT,
    scenario_key varchar(80) NOT NULL DEFAULT 'cancellation-classification-pending'
        REFERENCES portal_ticket_scenarios(scenario_key) ON DELETE RESTRICT,
    bucket_id uuid REFERENCES portal_ticket_buckets(id) ON DELETE RESTRICT,
    assigned_to uuid REFERENCES portal_users(id) ON DELETE RESTRICT,
    customer_type varchar(16) NOT NULL DEFAULT 'unknown',
    workflow_status varchar(32) NOT NULL DEFAULT 'intake_pending',
    clog_status text,
    applicant_name varchar(200),
    applicant_email varchar(254),
    id_number varchar(40),
    contact_number varchar(40),
    state varchar(120),
    language varchar(8),
    company_name varchar(200),
    company_no varchar(80),
    missing_fields jsonb NOT NULL DEFAULT '[]'::jsonb,
    attachment_refs jsonb NOT NULL DEFAULT '[]'::jsonb,
    source_data jsonb NOT NULL DEFAULT '{}'::jsonb,
    ocr_result jsonb,
    execution_result jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (source_type, source_ref),
    CHECK (source_type IN ('clog', 'email', 'portal')),
    CHECK (customer_type IN ('individual', 'company', 'unknown')),
    CHECK (workflow_status IN (
        'intake_pending', 'needs_information', 'ready_for_ocr', 'ocr_processing',
        'ocr_rejected', 'ready_for_execution', 'execution_processing',
        'completed', 'failed', 'closed'
    )),
    CHECK (jsonb_typeof(missing_fields) = 'array'),
    CHECK (jsonb_typeof(attachment_refs) = 'array'),
    CHECK (jsonb_typeof(source_data) = 'object')
);

CREATE INDEX IF NOT EXISTS ix_portal_tickets_work
    ON portal_tickets (workflow_status, scenario_key, created_at);
CREATE INDEX IF NOT EXISTS ix_portal_tickets_assignment
    ON portal_tickets (assigned_to, bucket_id, workflow_status, updated_at DESC);
CREATE INDEX IF NOT EXISTS ix_portal_tickets_request
    ON portal_tickets (portal_request_id) WHERE portal_request_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS portal_ticket_documents (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    ticket_id uuid NOT NULL REFERENCES portal_tickets(id) ON DELETE RESTRICT,
    source_ref varchar(255),
    original_filename text NOT NULL,
    mime_type varchar(160),
    storage_key text,
    ocr_status varchar(24) NOT NULL DEFAULT 'pending',
    ocr_result jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (ocr_status IN ('pending', 'processing', 'valid', 'invalid', 'unreadable', 'error'))
);

ALTER TABLE portal_ticket_documents
    ADD COLUMN IF NOT EXISTS source_email_id varchar(255),
    ADD COLUMN IF NOT EXISTS crm_id varchar(255),
    ADD COLUMN IF NOT EXISTS retrieval_url text,
    ADD COLUMN IF NOT EXISTS reference_status varchar(16) NOT NULL DEFAULT 'ready',
    ADD COLUMN IF NOT EXISTS reference_result jsonb;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'portal_ticket_documents'::regclass
          AND conname = 'ck_portal_ticket_documents_reference_status'
    ) THEN
        ALTER TABLE portal_ticket_documents
            ADD CONSTRAINT ck_portal_ticket_documents_reference_status
            CHECK (reference_status IN ('ready', 'invalid'));
    END IF;
END $$;

DROP INDEX IF EXISTS uq_portal_ticket_documents_source_ref;
CREATE UNIQUE INDEX uq_portal_ticket_documents_source_ref
    ON portal_ticket_documents (ticket_id, source_ref);

CREATE INDEX IF NOT EXISTS ix_portal_ticket_documents_work
    ON portal_ticket_documents (reference_status, ocr_status, ticket_id);

CREATE TABLE IF NOT EXISTS portal_rpa_action_queue (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    ticket_id uuid NOT NULL REFERENCES portal_tickets(id) ON DELETE RESTRICT,
    action_type varchar(32) NOT NULL,
    dedupe_key varchar(255) NOT NULL UNIQUE,
    state varchar(24) NOT NULL DEFAULT 'queued',
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    response jsonb,
    error_code varchar(100),
    attempts smallint NOT NULL DEFAULT 0,
    next_attempt_at timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (action_type IN ('request_information', 'reject_after_ocr', 'execute_cancellation')),
    CHECK (state IN ('queued', 'dispatching', 'succeeded', 'failed', 'uncertain', 'manual_review')),
    CHECK (attempts BETWEEN 0 AND 10),
    CHECK (jsonb_typeof(payload) = 'object')
);

CREATE INDEX IF NOT EXISTS ix_portal_rpa_action_queue_due
    ON portal_rpa_action_queue (state, next_attempt_at);

INSERT INTO portal_tickets
    (source_type, source_ref, portal_request_id, scenario_key, bucket_id, customer_type,
     workflow_status, applicant_name, applicant_email, id_number, state, language, company_name,
     company_no, missing_fields, source_data)
SELECT
    'email',
    i.mailbox_key || ':' || i.uid_validity::text || ':' || i.message_uid::text,
    r.id,
    'cancellation-classification-pending',
    b.id,
    'unknown',
    CASE WHEN jsonb_array_length(i.missing_fields) > 0 THEN 'needs_information' ELSE 'intake_pending' END,
    r.applicant_name,
    r.applicant_email,
    r.id_number,
    i.location_area,
    r.language,
    r.company_name,
    r.company_no,
    i.missing_fields,
    jsonb_build_object(
        'subject', i.subject,
        'sender', i.sender,
        'message_id', i.message_id,
        'raw_message', i.raw_message,
        'legacy_email_stage', i.stage
    )
FROM portal_email_intake i
JOIN portal_requests r ON r.id = i.request_id
JOIN portal_ticket_buckets b
    ON b.scenario_key = 'cancellation-classification-pending'
   AND b.is_default = true
   AND b.is_active = true
ON CONFLICT (source_type, source_ref) DO NOTHING;

INSERT INTO portal_ticket_documents
    (ticket_id, source_ref, source_email_id, crm_id, original_filename, retrieval_url, reference_status, reference_result)
SELECT
    t.id,
    'clog:' || encode(digest(COALESCE(t.source_data->'email'->>'id', t.source_ref) || ':' || f.filename, 'sha256'), 'hex'),
    t.source_data->'email'->>'id',
    t.source_ref,
    f.filename,
    NULL,
    'invalid',
    '{"reason":"Existing ticket file references need a retrieval URL generated by Cron1."}'::jsonb
FROM portal_tickets t
CROSS JOIN LATERAL jsonb_array_elements_text(t.attachment_refs) AS f(filename)
WHERE t.source_type = 'clog'
ON CONFLICT (ticket_id, source_ref) DO NOTHING;

COMMIT;
