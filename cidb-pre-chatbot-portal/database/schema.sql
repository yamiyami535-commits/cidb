CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE portal_users (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    username varchar(190) NOT NULL UNIQUE,
    email varchar(254) UNIQUE,
    password_hash varchar(255) NOT NULL,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT ck_portal_users_username_nonempty CHECK (length(trim(username)) > 0)
);

CREATE TABLE portal_requests (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id uuid NOT NULL REFERENCES portal_users(id) ON DELETE RESTRICT,
    submission_key uuid NOT NULL,
    applicant_name varchar(200) NOT NULL,
    id_number varchar(40) NOT NULL,
    applicant_email varchar(254) NOT NULL,
    crim varchar(120) NOT NULL,
    rpa_request_payload jsonb NOT NULL,
    status varchar(24) NOT NULL DEFAULT 'processing',
    rpa_http_status smallint,
    rpa_reference_id varchar(190),
    rpa_response jsonb,
    rpa_response_text text,
    rpa_display_message text,
    error_code varchar(80),
    error_detail text,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    completed_at timestamptz,
    CONSTRAINT uq_portal_requests_submission UNIQUE (user_id, submission_key),
    CONSTRAINT ck_portal_requests_status CHECK (status IN ('processing', 'pending', 'success', 'failed')),
    CONSTRAINT ck_portal_requests_http_status CHECK (rpa_http_status IS NULL OR rpa_http_status BETWEEN 100 AND 599),
    CONSTRAINT ck_portal_requests_applicant_name CHECK (length(trim(applicant_name)) > 0),
    CONSTRAINT ck_portal_requests_id_number CHECK (length(trim(id_number)) > 0),
    CONSTRAINT ck_portal_requests_applicant_email CHECK (length(trim(applicant_email)) > 0),
    CONSTRAINT ck_portal_requests_crim CHECK (length(trim(crim)) > 0)
);

CREATE INDEX ix_portal_requests_user_created ON portal_requests (user_id, created_at DESC);
CREATE INDEX ix_portal_requests_status_created ON portal_requests (status, created_at DESC);
CREATE INDEX ix_portal_requests_rpa_reference ON portal_requests (rpa_reference_id) WHERE rpa_reference_id IS NOT NULL;

-- psql entry point: keep fresh databases aligned with the additive migrations.
\ir migrations/20260925_email_reader.sql
\ir migrations/20261019_ocr_verifications.sql
\ir migrations/20261020_ticket_workflow.sql
\ir migrations/20261021_ticket_attachment_lookup.sql
\ir migrations/20261022_clog_open_status.sql
\ir migrations/20261023_ticket_conversation_foundation.sql
\ir migrations/20261027_ocr_manual_review.sql
