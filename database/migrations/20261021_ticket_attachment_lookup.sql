BEGIN;
SET LOCAL lock_timeout = '5s';

ALTER TABLE portal_tickets
    ADD COLUMN IF NOT EXISTS clog_crm_id varchar(255),
    ADD COLUMN IF NOT EXISTS attachment_lookup_status varchar(32) NOT NULL DEFAULT 'not_checked',
    ADD COLUMN IF NOT EXISTS attachment_lookup_checked_at timestamptz,
    ADD COLUMN IF NOT EXISTS attachment_lookup_result jsonb NOT NULL DEFAULT '{}'::jsonb;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'portal_tickets'::regclass
          AND conname = 'ck_portal_tickets_attachment_lookup_status'
    ) THEN
        ALTER TABLE portal_tickets
            ADD CONSTRAINT ck_portal_tickets_attachment_lookup_status
            CHECK (attachment_lookup_status IN (
                'not_checked', 'crm_missing', 'found', 'no_files', 'no_matching_crm_email'
            ));
    END IF;
END $$;

UPDATE portal_tickets
SET clog_crm_id = source_ref
WHERE source_type = 'clog' AND clog_crm_id IS NULL;

UPDATE portal_tickets
SET clog_crm_id = NULLIF(source_data->'extraction'->'fields'->>'crm', '')
WHERE source_type = 'email' AND clog_crm_id IS NULL;

CREATE INDEX IF NOT EXISTS ix_portal_tickets_clog_crm
    ON portal_tickets (clog_crm_id) WHERE clog_crm_id IS NOT NULL;

DROP INDEX IF EXISTS uq_portal_ticket_documents_source_ref;
CREATE UNIQUE INDEX uq_portal_ticket_documents_source_ref
    ON portal_ticket_documents (ticket_id, source_ref);
CREATE INDEX IF NOT EXISTS ix_portal_ticket_documents_ticket_created
    ON portal_ticket_documents (ticket_id, created_at);

CREATE OR REPLACE VIEW portal_ticket_details AS
SELECT
    t.*,
    COALESCE(document_list.documents, '[]'::jsonb) AS documents,
    COALESCE(action_list.actions, '[]'::jsonb) AS actions,
    CASE WHEN u.id IS NULL THEN NULL ELSE jsonb_build_object(
        'id', u.id,
        'username', u.username,
        'role', u.role,
        'is_active', u.is_active
    ) END AS assigned_user,
    request_detail.details AS portal_request,
    email_detail.details AS email_intake
FROM portal_tickets t
LEFT JOIN portal_users u ON u.id = t.assigned_to
LEFT JOIN LATERAL (
    SELECT jsonb_agg(jsonb_build_object(
        'id', d.id,
        'source_ref', d.source_ref,
        'source_email_id', d.source_email_id,
        'crm_id', d.crm_id,
        'filename', d.original_filename,
        'mime_type', d.mime_type,
        'storage_key', d.storage_key,
        'retrieval_url', d.retrieval_url,
        'reference_status', d.reference_status,
        'reference_result', d.reference_result,
        'ocr_status', d.ocr_status,
        'ocr_result', d.ocr_result,
        'created_at', d.created_at,
        'updated_at', d.updated_at
    ) ORDER BY d.created_at, d.id) AS documents
    FROM portal_ticket_documents d
    WHERE d.ticket_id = t.id
) document_list ON true
LEFT JOIN LATERAL (
    SELECT jsonb_agg(jsonb_build_object(
        'id', q.id,
        'action_type', q.action_type,
        'state', q.state,
        'payload', q.payload,
        'response', q.response,
        'error_code', q.error_code,
        'attempts', q.attempts,
        'next_attempt_at', q.next_attempt_at,
        'created_at', q.created_at,
        'updated_at', q.updated_at
    ) ORDER BY q.created_at, q.id) AS actions
    FROM portal_rpa_action_queue q
    WHERE q.ticket_id = t.id
) action_list ON true
LEFT JOIN LATERAL (
    SELECT jsonb_build_object(
        'id', r.id,
        'request_source', r.request_source,
        'status', r.status,
        'rpa_http_status', r.rpa_http_status,
        'rpa_reference_id', r.rpa_reference_id,
        'rpa_response', r.rpa_response,
        'error_code', r.error_code,
        'error_detail', r.error_detail,
        'created_at', r.created_at,
        'updated_at', r.updated_at,
        'completed_at', r.completed_at
    ) AS details
    FROM portal_requests r
    WHERE r.id = t.portal_request_id
) request_detail ON true
LEFT JOIN LATERAL (
    SELECT jsonb_build_object(
        'stage', i.stage,
        'message_id', i.message_id,
        'sender', i.sender,
        'subject', i.subject,
        'location_area', i.location_area,
        'missing_fields', i.missing_fields,
        'raw_message', i.raw_message,
        'read_marked', i.read_marked,
        'last_error', i.last_error,
        'created_at', i.created_at,
        'updated_at', i.updated_at
    ) AS details
    FROM portal_email_intake i
    WHERE i.request_id = t.portal_request_id
    ORDER BY i.created_at DESC
    LIMIT 1
) email_detail ON true;

COMMIT;
