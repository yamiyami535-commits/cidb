-- Additive rollout: apply before deploying the history changes. No mailbox activation.
BEGIN;
SET LOCAL lock_timeout = '5s';

ALTER TABLE portal_requests ADD COLUMN IF NOT EXISTS request_source varchar(8) NOT NULL DEFAULT 'form';
ALTER TABLE portal_requests ALTER COLUMN user_id DROP NOT NULL;
ALTER TABLE portal_requests ALTER COLUMN applicant_name DROP NOT NULL;
ALTER TABLE portal_requests ALTER COLUMN id_number DROP NOT NULL;
ALTER TABLE portal_requests ALTER COLUMN applicant_email DROP NOT NULL;
ALTER TABLE portal_requests ALTER COLUMN crim DROP NOT NULL;
ALTER TABLE portal_requests ALTER COLUMN rpa_request_payload DROP NOT NULL;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='portal_requests'::regclass AND conname='ck_portal_requests_source') THEN
        ALTER TABLE portal_requests ADD CONSTRAINT ck_portal_requests_source CHECK (request_source IN ('form','email'));
        ALTER TABLE portal_requests ADD CONSTRAINT ck_portal_form_required CHECK (
            request_source='email' OR (user_id IS NOT NULL AND applicant_name IS NOT NULL AND id_number IS NOT NULL
            AND applicant_email IS NOT NULL AND rpa_request_payload IS NOT NULL));
    END IF;
END $$;

-- Grandfather historical null CRM values without allowing new form requests to omit CRM.
-- Unlike a NOT VALID CHECK, this trigger permits result updates on those historical rows.
CREATE OR REPLACE FUNCTION portal_request_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='UPDATE' AND NEW.request_source IS DISTINCT FROM OLD.request_source THEN
        RAISE EXCEPTION 'Request origin cannot change';
    END IF;
    IF NEW.request_source='form' AND NEW.crim IS NULL THEN
        IF TG_OP='INSERT' THEN RAISE EXCEPTION 'CRM is required for form requests';
        ELSIF OLD.crim IS NOT NULL THEN RAISE EXCEPTION 'CRM is required for form requests'; END IF;
    END IF;
    RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS portal_request_source_guard ON portal_requests;
CREATE TRIGGER portal_request_source_guard BEFORE INSERT OR UPDATE ON portal_requests
    FOR EACH ROW EXECUTE FUNCTION portal_request_source_guard();

CREATE TABLE IF NOT EXISTS portal_email_mailboxes (
    mailbox_key varchar(64) PRIMARY KEY,
    uid_validity bigint NOT NULL CHECK (uid_validity > 0),
    activation_uid bigint NOT NULL CHECK (activation_uid >= 0),
    last_uid bigint NOT NULL CHECK (last_uid >= activation_uid),
    activated_at timestamptz NOT NULL DEFAULT now(),
    last_checked_at timestamptz,
    last_error varchar(100)
);

CREATE TABLE IF NOT EXISTS portal_email_intake (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    mailbox_key varchar(64) NOT NULL REFERENCES portal_email_mailboxes(mailbox_key),
    uid_validity bigint NOT NULL,
    message_uid bigint NOT NULL CHECK (message_uid > 0),
    request_id uuid UNIQUE REFERENCES portal_requests(id),
    message_id text,
    sender text,
    subject text,
    location_area text,
    missing_fields jsonb NOT NULL DEFAULT '[]',
    stage varchar(32) NOT NULL DEFAULT 'discovered',
    next_attempt_at timestamptz NOT NULL DEFAULT now(),
    read_marked boolean NOT NULL DEFAULT false,
    last_error varchar(100),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (mailbox_key,uid_validity,message_uid),
    CHECK (stage IN ('discovered','ignored','ready','retry_due','dispatching','awaiting_result',
        'finished','missing_fields','extraction_attention','read_error','submission_uncertain','submission_failed'))
);

CREATE TABLE IF NOT EXISTS portal_email_attempts (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    intake_id uuid NOT NULL REFERENCES portal_email_intake(id),
    attempt_no smallint NOT NULL CHECK (attempt_no BETWEEN 1 AND 2),
    outcome varchar(24) NOT NULL DEFAULT 'dispatching',
    http_status smallint,
    response jsonb,
    response_text text,
    error_code varchar(100),
    started_at timestamptz NOT NULL DEFAULT now(),
    finished_at timestamptz,
    UNIQUE (intake_id,attempt_no),
    CHECK (outcome IN ('dispatching','accepted','success','failed','safe_failure','uncertain'))
);

CREATE TABLE IF NOT EXISTS portal_email_notifications (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    intake_id uuid NOT NULL UNIQUE REFERENCES portal_email_intake(id),
    recipient varchar(254) NOT NULL,
    state varchar(24) NOT NULL DEFAULT 'pending',
    attempts smallint NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 2),
    next_attempt_at timestamptz NOT NULL DEFAULT now(),
    last_error varchar(100),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (state IN ('pending','sending','accepted','failed','uncertain'))
);

CREATE INDEX IF NOT EXISTS ix_portal_requests_source_created ON portal_requests (request_source,created_at DESC);
CREATE INDEX IF NOT EXISTS ix_portal_email_work ON portal_email_intake (mailbox_key,stage,next_attempt_at);
CREATE INDEX IF NOT EXISTS ix_portal_email_notifications_due ON portal_email_notifications (state,next_attempt_at);
COMMIT;
