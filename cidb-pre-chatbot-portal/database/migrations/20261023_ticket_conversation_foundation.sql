BEGIN;
SET LOCAL lock_timeout = '5s';

ALTER TABLE cidb_interaction_data
    ADD COLUMN IF NOT EXISTS customer_type varchar(16),
    ADD COLUMN IF NOT EXISTS state varchar(120),
    ADD COLUMN IF NOT EXISTS language varchar(8),
    ADD COLUMN IF NOT EXISTS company_name varchar(200),
    ADD COLUMN IF NOT EXISTS company_no varchar(80);

ALTER TABLE portal_tickets
    ADD COLUMN IF NOT EXISTS interaction_id uuid REFERENCES cidb_interaction_data(id) ON DELETE SET NULL;

UPDATE portal_tickets t
SET interaction_id = i.id
FROM cidb_interaction_data i
WHERE t.interaction_id IS NULL
  AND t.clog_crm_id = i.crm_id;

CREATE INDEX IF NOT EXISTS ix_portal_tickets_interaction
    ON portal_tickets (interaction_id) WHERE interaction_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS cidb_email_threads (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    crm_id varchar(255) REFERENCES cidb_interaction_data(crm_id) ON DELETE SET NULL,
    thread_key varchar(320) NOT NULL UNIQUE,
    subject text,
    first_message_at timestamptz,
    last_message_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (length(trim(thread_key)) > 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_cidb_email_threads_crm
    ON cidb_email_threads (crm_id) WHERE crm_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS ix_cidb_email_threads_recent
    ON cidb_email_threads (last_message_at DESC, id);

CREATE TABLE IF NOT EXISTS cidb_email_messages (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    thread_id uuid NOT NULL REFERENCES cidb_email_threads(id) ON DELETE RESTRICT,
    source_system varchar(24) NOT NULL,
    source_message_id varchar(255) NOT NULL,
    parent_message_id uuid REFERENCES cidb_email_messages(id) ON DELETE SET NULL,
    source_parent_message_id varchar(255),
    rfc_message_id text,
    actor_user_id uuid REFERENCES portal_users(id) ON DELETE SET NULL,
    direction varchar(16) NOT NULL DEFAULT 'unknown',
    participant_name text,
    participant_address text,
    sender_name text,
    sender_address text,
    recipients jsonb NOT NULL DEFAULT '[]'::jsonb,
    cc_recipients jsonb NOT NULL DEFAULT '[]'::jsonb,
    subject text,
    body_text text,
    body_html text,
    source_email_type varchar(80),
    source_group_value varchar(80),
    source_timestamp text,
    sent_at timestamptz,
    delivery_status varchar(16) NOT NULL DEFAULT 'unknown',
    source_metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (source_system, source_message_id),
    CHECK (source_system IN ('clog', 'mailbox', 'agent')),
    CHECK (direction IN ('inbound', 'outbound', 'internal', 'unknown')),
    CHECK (delivery_status IN ('received', 'draft', 'queued', 'sent', 'failed', 'uncertain', 'unknown')),
    CHECK (jsonb_typeof(recipients) = 'array'),
    CHECK (jsonb_typeof(cc_recipients) = 'array'),
    CHECK (jsonb_typeof(source_metadata) = 'object')
);

CREATE INDEX IF NOT EXISTS ix_cidb_email_messages_thread_time
    ON cidb_email_messages (thread_id, sent_at, created_at);
CREATE INDEX IF NOT EXISTS ix_cidb_email_messages_parent
    ON cidb_email_messages (parent_message_id) WHERE parent_message_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS ix_cidb_email_messages_rfc_id
    ON cidb_email_messages (source_system, rfc_message_id) WHERE rfc_message_id IS NOT NULL;

ALTER TABLE portal_tickets
    ADD COLUMN IF NOT EXISTS email_thread_id uuid REFERENCES cidb_email_threads(id) ON DELETE SET NULL;

UPDATE portal_tickets t
SET email_thread_id = e.id
FROM cidb_email_threads e
WHERE t.email_thread_id IS NULL
  AND t.clog_crm_id = e.crm_id;

CREATE INDEX IF NOT EXISTS ix_portal_tickets_email_thread
    ON portal_tickets (email_thread_id) WHERE email_thread_id IS NOT NULL;

ALTER TABLE portal_ticket_documents
    ADD COLUMN IF NOT EXISTS email_message_id uuid REFERENCES cidb_email_messages(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS ix_portal_ticket_documents_email_message
    ON portal_ticket_documents (email_message_id) WHERE email_message_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS cidb_clog_data_records (
    source_data_id varchar(255) PRIMARY KEY,
    interaction_id uuid NOT NULL REFERENCES cidb_interaction_data(id) ON DELETE CASCADE,
    parent_id text,
    form_id text,
    form_name text,
    customer_type text,
    interaction_type text,
    language text,
    state_location text,
    contact_id text,
    source_payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    source_created_at text,
    source_updated_at text,
    synced_at timestamptz NOT NULL DEFAULT now(),
    CHECK (jsonb_typeof(source_payload) = 'object')
);

CREATE INDEX IF NOT EXISTS ix_cidb_clog_data_interaction
    ON cidb_clog_data_records (interaction_id, source_created_at);

CREATE TABLE IF NOT EXISTS cidb_clog_activity (
    source_activity_id varchar(255) PRIMARY KEY,
    interaction_id uuid NOT NULL REFERENCES cidb_interaction_data(id) ON DELETE CASCADE,
    source_data_id varchar(255),
    clog_user_id text,
    username text,
    activity text,
    viewed_at text,
    source_created_at text,
    source_payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    synced_at timestamptz NOT NULL DEFAULT now(),
    CHECK (jsonb_typeof(source_payload) = 'object')
);

CREATE INDEX IF NOT EXISTS ix_cidb_clog_activity_interaction
    ON cidb_clog_activity (interaction_id, source_created_at);

CREATE TABLE IF NOT EXISTS cidb_clog_ticketing_log (
    source_log_id varchar(255) PRIMARY KEY,
    interaction_id uuid NOT NULL REFERENCES cidb_interaction_data(id) ON DELETE CASCADE,
    source_data_id varchar(255),
    received_status text,
    send_status text,
    created_by text,
    source_created_at text,
    source_payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    synced_at timestamptz NOT NULL DEFAULT now(),
    CHECK (jsonb_typeof(source_payload) = 'object')
);

CREATE INDEX IF NOT EXISTS ix_cidb_clog_ticketing_interaction
    ON cidb_clog_ticketing_log (interaction_id, source_created_at);

CREATE TABLE IF NOT EXISTS portal_ticket_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    ticket_id uuid NOT NULL REFERENCES portal_tickets(id) ON DELETE RESTRICT,
    actor_user_id uuid REFERENCES portal_users(id) ON DELETE SET NULL,
    actor_type varchar(16) NOT NULL DEFAULT 'system',
    event_type varchar(80) NOT NULL,
    old_values jsonb,
    new_values jsonb,
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK (actor_type IN ('user', 'system', 'integration')),
    CHECK (jsonb_typeof(metadata) = 'object')
);

CREATE INDEX IF NOT EXISTS ix_portal_ticket_events_history
    ON portal_ticket_events (ticket_id, created_at DESC, id);

CREATE TABLE IF NOT EXISTS portal_ticket_ocr_jobs (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    document_id uuid NOT NULL REFERENCES portal_ticket_documents(id) ON DELETE RESTRICT,
    dedupe_key varchar(255) NOT NULL UNIQUE,
    state varchar(24) NOT NULL DEFAULT 'manual_review',
    correlation_id varchar(190) UNIQUE,
    request_payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    response_payload jsonb,
    external_reference varchar(255),
    error_code varchar(100),
    attempts smallint NOT NULL DEFAULT 0,
    next_attempt_at timestamptz NOT NULL DEFAULT now(),
    started_at timestamptz,
    completed_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (state IN ('queued', 'dispatching', 'succeeded', 'failed', 'uncertain', 'manual_review')),
    CHECK (attempts BETWEEN 0 AND 10),
    CHECK (jsonb_typeof(request_payload) = 'object')
);

CREATE INDEX IF NOT EXISTS ix_portal_ticket_ocr_jobs_due
    ON portal_ticket_ocr_jobs (state, next_attempt_at, created_at);

CREATE TABLE IF NOT EXISTS portal_ticket_message_outbox (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    ticket_id uuid NOT NULL REFERENCES portal_tickets(id) ON DELETE RESTRICT,
    thread_id uuid NOT NULL REFERENCES cidb_email_threads(id) ON DELETE RESTRICT,
    message_id uuid NOT NULL UNIQUE REFERENCES cidb_email_messages(id) ON DELETE RESTRICT,
    recipient_address text NOT NULL,
    state varchar(24) NOT NULL DEFAULT 'manual_review',
    dedupe_key varchar(255) NOT NULL UNIQUE,
    transport_reference varchar(255),
    response_payload jsonb,
    error_code varchar(100),
    attempts smallint NOT NULL DEFAULT 0,
    next_attempt_at timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (state IN ('queued', 'dispatching', 'succeeded', 'failed', 'uncertain', 'manual_review')),
    CHECK (attempts BETWEEN 0 AND 10)
);

CREATE INDEX IF NOT EXISTS ix_portal_ticket_message_outbox_due
    ON portal_ticket_message_outbox (state, next_attempt_at, created_at);

CREATE OR REPLACE FUNCTION portal_ticket_audit_changes() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    actor_id uuid;
BEGIN
    actor_id := NULLIF(current_setting('app.actor_user_id', true), '')::uuid;
    IF TG_OP = 'INSERT' THEN
        INSERT INTO portal_ticket_events
            (ticket_id, actor_user_id, actor_type, event_type, new_values, metadata)
        VALUES (
            NEW.id,
            actor_id,
            CASE WHEN actor_id IS NULL THEN 'system' ELSE 'user' END,
            'ticket.created',
            jsonb_build_object(
                'source_type', NEW.source_type,
                'workflow_status', NEW.workflow_status,
                'scenario_key', NEW.scenario_key
            ),
            jsonb_build_object('source_ref', NEW.source_ref)
        );
        RETURN NEW;
    END IF;

    IF OLD.workflow_status IS DISTINCT FROM NEW.workflow_status THEN
        INSERT INTO portal_ticket_events
            (ticket_id, actor_user_id, actor_type, event_type, old_values, new_values)
        VALUES (
            NEW.id, actor_id,
            CASE WHEN actor_id IS NULL THEN 'system' ELSE 'user' END,
            'ticket.workflow_status_changed',
            jsonb_build_object('workflow_status', OLD.workflow_status),
            jsonb_build_object('workflow_status', NEW.workflow_status)
        );
    END IF;
    IF OLD.assigned_to IS DISTINCT FROM NEW.assigned_to
       OR OLD.bucket_id IS DISTINCT FROM NEW.bucket_id
       OR OLD.scenario_key IS DISTINCT FROM NEW.scenario_key THEN
        INSERT INTO portal_ticket_events
            (ticket_id, actor_user_id, actor_type, event_type, old_values, new_values)
        VALUES (
            NEW.id, actor_id,
            CASE WHEN actor_id IS NULL THEN 'system' ELSE 'user' END,
            'ticket.assignment_changed',
            jsonb_build_object(
                'assigned_to', OLD.assigned_to,
                'bucket_id', OLD.bucket_id,
                'scenario_key', OLD.scenario_key
            ),
            jsonb_build_object(
                'assigned_to', NEW.assigned_to,
                'bucket_id', NEW.bucket_id,
                'scenario_key', NEW.scenario_key
            )
        );
    END IF;
    IF OLD.customer_type IS DISTINCT FROM NEW.customer_type
       OR OLD.missing_fields IS DISTINCT FROM NEW.missing_fields THEN
        INSERT INTO portal_ticket_events
            (ticket_id, actor_user_id, actor_type, event_type, old_values, new_values)
        VALUES (
            NEW.id, actor_id,
            CASE WHEN actor_id IS NULL THEN 'system' ELSE 'user' END,
            'ticket.classification_changed',
            jsonb_build_object(
                'customer_type', OLD.customer_type,
                'missing_fields', OLD.missing_fields
            ),
            jsonb_build_object(
                'customer_type', NEW.customer_type,
                'missing_fields', NEW.missing_fields
            )
        );
    END IF;
    RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS portal_ticket_audit_changes ON portal_tickets;
CREATE TRIGGER portal_ticket_audit_changes
AFTER INSERT OR UPDATE OF workflow_status, assigned_to, bucket_id, scenario_key, customer_type, missing_fields
ON portal_tickets
FOR EACH ROW EXECUTE FUNCTION portal_ticket_audit_changes();

INSERT INTO cidb_email_threads (crm_id, thread_key)
SELECT crm_id, 'crm:' || crm_id
FROM cidb_interaction_data
ON CONFLICT (thread_key) DO NOTHING;

INSERT INTO cidb_email_threads (crm_id, thread_key, subject, first_message_at, last_message_at)
SELECT
    NULLIF(t.clog_crm_id, ''),
    CASE WHEN NULLIF(t.clog_crm_id, '') IS NULL
        THEN 'ticket:' || t.id::text ELSE 'crm:' || t.clog_crm_id END,
    i.subject,
    i.created_at,
    i.created_at
FROM portal_tickets t
LEFT JOIN LATERAL (
    SELECT subject, created_at
    FROM portal_email_intake
    WHERE request_id = t.portal_request_id
    ORDER BY created_at
    LIMIT 1
) i ON true
WHERE t.source_type = 'email'
ON CONFLICT (thread_key) DO NOTHING;

INSERT INTO cidb_email_messages
    (thread_id, source_system, source_message_id, direction, participant_name, participant_address,
     subject, body_text, body_html, source_email_type, source_group_value, source_timestamp, source_metadata)
SELECT
    t.id,
    'clog',
    e.email->>'id',
    'unknown',
    e.email->>'name',
    e.email->>'email',
    e.email->>'subject',
    e.email->>'body_text',
    e.email->>'body_html',
    e.email->>'emailtype',
    e.email->>'lstinGroup',
    e.email->>'time',
    jsonb_strip_nulls(jsonb_build_object(
        'case_id', e.email->>'case_id',
        'case_status', e.email->>'case_status',
        'case_assigned', e.email->>'case_assigned',
        'email_tagging', e.email->>'email_tagging'
    ))
FROM cidb_interaction_data i
CROSS JOIN LATERAL (
    SELECT i.clog_raw_data->'email' AS email
) e
JOIN cidb_email_threads t ON t.crm_id = i.crm_id
WHERE jsonb_typeof(e.email) = 'object'
  AND NULLIF(e.email->>'id', '') IS NOT NULL
ON CONFLICT (source_system, source_message_id) DO NOTHING;

INSERT INTO cidb_email_messages
    (thread_id, source_system, source_message_id, source_parent_message_id, rfc_message_id,
     direction, sender_address, subject, body_text, body_html, sent_at, delivery_status, source_metadata)
SELECT
    th.id,
    'mailbox',
    'mailbox:' || i.mailbox_key || ':' || i.uid_validity::text || ':' || i.message_uid::text,
    NULLIF(i.raw_message->>'in_reply_to', ''),
    NULLIF(i.raw_message->>'message_id', ''),
    'inbound',
    NULLIF(i.sender, ''),
    i.subject,
    i.raw_message->>'text',
    i.raw_message->>'html',
    i.created_at,
    'received',
    jsonb_strip_nulls(jsonb_build_object(
        'mailbox_key', i.mailbox_key,
        'uid_validity', i.uid_validity,
        'message_uid', i.message_uid,
        'message_id', i.message_id
    ))
FROM portal_email_intake i
JOIN portal_tickets t ON t.portal_request_id = i.request_id
JOIN cidb_email_threads th
  ON th.thread_key = CASE WHEN NULLIF(t.clog_crm_id, '') IS NULL
      THEN 'ticket:' || t.id::text ELSE 'crm:' || t.clog_crm_id END
WHERE i.request_id IS NOT NULL
  AND jsonb_typeof(i.raw_message) = 'object'
  AND (i.raw_message ? 'text' OR i.raw_message ? 'html')
ON CONFLICT (source_system, source_message_id) DO NOTHING;

UPDATE portal_ticket_documents d
SET email_message_id = m.id
FROM cidb_email_messages m
WHERE d.email_message_id IS NULL
  AND m.source_system = 'clog'
  AND m.source_message_id = d.source_email_id;

UPDATE portal_tickets t
SET email_thread_id = th.id
FROM cidb_email_threads th
WHERE t.email_thread_id IS NULL
  AND th.thread_key = CASE WHEN NULLIF(t.clog_crm_id, '') IS NULL
      THEN 'ticket:' || t.id::text ELSE 'crm:' || t.clog_crm_id END;

UPDATE portal_ticket_documents d
SET email_message_id = m.id
FROM cidb_email_messages m
WHERE d.email_message_id IS NULL
  AND d.source_email_id = m.source_message_id
  AND m.source_system = 'clog';

UPDATE portal_email_intake
SET raw_message = raw_message - 'text' - 'html'
WHERE jsonb_typeof(raw_message) = 'object'
  AND (raw_message ? 'text' OR raw_message ? 'html');

UPDATE portal_tickets
SET source_data = jsonb_strip_nulls(jsonb_build_object(
    'subject', source_data->>'subject',
    'sender', source_data->>'sender',
    'message_id', source_data->>'message_id',
    'legacy_email_stage', source_data->>'legacy_email_stage'
))
WHERE source_type = 'email'
  AND source_data ? 'raw_message';

UPDATE portal_tickets
SET source_data = jsonb_strip_nulls(jsonb_build_object(
    'source_email_id', source_data->'email'->>'id',
    'source_timestamp', source_data->'email'->>'time',
    'email_type', source_data->'email'->>'emailtype',
    'case_id', source_data->'email'->>'case_id',
    'case_status', source_data->'email'->>'case_status'
))
WHERE source_type = 'clog' AND source_data ? 'email';

INSERT INTO cidb_clog_data_records
    (source_data_id, interaction_id, parent_id, form_id, form_name, customer_type,
     interaction_type, language, state_location, contact_id, source_payload,
     source_created_at, source_updated_at)
SELECT
    d.row_data->>'ID',
    i.id,
    d.row_data->>'Parent_ID',
    d.row_data->>'FormID',
    d.row_data->>'FormName',
    d.row_data->>'CustomerType',
    d.row_data->>'TypeOfInteraction',
    d.row_data->>'Language',
    d.row_data->>'State_Location',
    d.row_data->>'ContactID',
    d.row_data,
    d.row_data->>'CreatedDateTime',
    d.row_data->>'LastUpdatedDateTime'
FROM cidb_interaction_data i
CROSS JOIN LATERAL jsonb_array_elements(
    CASE WHEN jsonb_typeof(i.clog_raw_data->'data') = 'array'
        THEN i.clog_raw_data->'data' ELSE '[]'::jsonb END
) AS d(row_data)
WHERE NULLIF(d.row_data->>'ID', '') IS NOT NULL
ON CONFLICT (source_data_id) DO NOTHING;

INSERT INTO cidb_clog_activity
    (source_activity_id, interaction_id, source_data_id, clog_user_id, username, activity,
     viewed_at, source_created_at, source_payload)
SELECT
    a.row_data->>'ID',
    i.id,
    a.row_data->>'DataID',
    a.row_data->>'UserID',
    a.row_data->>'UserName',
    a.row_data->>'Activity',
    a.row_data->>'ViewDateTime',
    a.row_data->>'CreatedDateTime',
    a.row_data
FROM cidb_interaction_data i
CROSS JOIN LATERAL jsonb_array_elements(
    CASE WHEN jsonb_typeof(i.clog_raw_data->'activity') = 'array'
        THEN i.clog_raw_data->'activity' ELSE '[]'::jsonb END
) AS a(row_data)
WHERE NULLIF(a.row_data->>'ID', '') IS NOT NULL
ON CONFLICT (source_activity_id) DO NOTHING;

INSERT INTO cidb_clog_ticketing_log
    (source_log_id, interaction_id, source_data_id, received_status, send_status,
     created_by, source_created_at, source_payload)
SELECT
    l.row_data->>'ID',
    i.id,
    l.row_data->>'TicketID',
    l.row_data->>'ReceivedStatus',
    l.row_data->>'SendStatus',
    l.row_data->>'CreatedBy',
    l.row_data->>'CreatedDateTime',
    l.row_data
FROM cidb_interaction_data i
CROSS JOIN LATERAL jsonb_array_elements(
    CASE WHEN jsonb_typeof(i.clog_raw_data->'ticketing_log') = 'array'
        THEN i.clog_raw_data->'ticketing_log' ELSE '[]'::jsonb END
) AS l(row_data)
WHERE NULLIF(l.row_data->>'ID', '') IS NOT NULL
ON CONFLICT (source_log_id) DO NOTHING;

UPDATE cidb_email_threads th
SET first_message_at = message_times.first_message_at,
    last_message_at = message_times.last_message_at,
    updated_at = NOW()
FROM (
    SELECT
        thread_id,
        MIN(sent_at) AS first_message_at,
        MAX(sent_at) AS last_message_at
    FROM cidb_email_messages
    WHERE sent_at IS NOT NULL
    GROUP BY thread_id
) message_times
WHERE th.id = message_times.thread_id;

DROP VIEW IF EXISTS portal_ticket_details;
CREATE VIEW portal_ticket_details AS
SELECT
    t.*,
    COALESCE(document_list.documents, '[]'::jsonb) AS documents,
    COALESCE(action_list.actions, '[]'::jsonb) AS actions,
    CASE WHEN u.id IS NULL THEN NULL ELSE jsonb_build_object(
        'id', u.id, 'username', u.username, 'role', u.role, 'is_active', u.is_active
    ) END AS assigned_user,
    request_detail.details AS portal_request,
    email_detail.details AS email_intake,
    CASE WHEN interaction.id IS NULL THEN NULL ELSE jsonb_build_object(
        'id', interaction.id, 'crm_id', interaction.crm_id,
        'applicant_name', interaction.applicant_name, 'nric', interaction.nric,
        'email', interaction.email, 'phone', interaction.phone,
        'customer_type', interaction.customer_type, 'state', interaction.state,
        'language', interaction.language, 'company_name', interaction.company_name,
        'company_no', interaction.company_no, 'current_status', interaction.current_status,
        'clog_status', interaction.clog_status, 'outcome_code', interaction.outcome_code,
        'completed_at', interaction.completed_at
    ) END AS interaction_details,
    conversation.details AS email_thread,
    COALESCE(history.events, '[]'::jsonb) AS ticket_history
FROM portal_tickets t
LEFT JOIN portal_users u ON u.id = t.assigned_to
LEFT JOIN cidb_interaction_data interaction ON interaction.id = t.interaction_id
LEFT JOIN LATERAL (
    SELECT jsonb_agg(jsonb_build_object(
        'id', d.id, 'source_ref', d.source_ref, 'source_email_id', d.source_email_id,
        'email_message_id', d.email_message_id, 'crm_id', d.crm_id,
        'filename', d.original_filename, 'mime_type', d.mime_type,
        'storage_key', d.storage_key, 'retrieval_url', d.retrieval_url,
        'reference_status', d.reference_status, 'reference_result', d.reference_result,
        'ocr_status', d.ocr_status, 'ocr_result', d.ocr_result,
        'created_at', d.created_at, 'updated_at', d.updated_at
    ) ORDER BY d.created_at, d.id) AS documents
    FROM portal_ticket_documents d WHERE d.ticket_id = t.id
) document_list ON true
LEFT JOIN LATERAL (
    SELECT jsonb_agg(jsonb_build_object(
        'id', q.id, 'action_type', q.action_type, 'state', q.state, 'payload', q.payload,
        'response', q.response, 'error_code', q.error_code, 'attempts', q.attempts,
        'next_attempt_at', q.next_attempt_at, 'created_at', q.created_at, 'updated_at', q.updated_at
    ) ORDER BY q.created_at, q.id) AS actions
    FROM portal_rpa_action_queue q WHERE q.ticket_id = t.id
) action_list ON true
LEFT JOIN LATERAL (
    SELECT jsonb_build_object(
        'id', r.id, 'request_source', r.request_source, 'status', r.status,
        'rpa_http_status', r.rpa_http_status, 'rpa_reference_id', r.rpa_reference_id,
        'rpa_response', r.rpa_response, 'error_code', r.error_code, 'error_detail', r.error_detail,
        'created_at', r.created_at, 'updated_at', r.updated_at, 'completed_at', r.completed_at
    ) AS details
    FROM portal_requests r WHERE r.id = t.portal_request_id
) request_detail ON true
LEFT JOIN LATERAL (
    SELECT jsonb_build_object(
        'stage', i.stage, 'message_id', i.message_id, 'sender', i.sender, 'subject', i.subject,
        'location_area', i.location_area, 'missing_fields', i.missing_fields,
        'raw_message', i.raw_message, 'read_marked', i.read_marked, 'last_error', i.last_error,
        'created_at', i.created_at, 'updated_at', i.updated_at
    ) AS details
    FROM portal_email_intake i
    WHERE i.request_id = t.portal_request_id
    ORDER BY i.created_at DESC LIMIT 1
) email_detail ON true
LEFT JOIN LATERAL (
    SELECT jsonb_build_object(
        'id', th.id, 'crm_id', th.crm_id, 'subject', th.subject,
        'first_message_at', th.first_message_at, 'last_message_at', th.last_message_at,
        'messages', COALESCE((
            SELECT jsonb_agg(jsonb_build_object(
                'id', m.id, 'source_system', m.source_system, 'source_message_id', m.source_message_id,
                'parent_message_id', m.parent_message_id, 'source_parent_message_id', m.source_parent_message_id,
                'rfc_message_id', m.rfc_message_id, 'direction', m.direction,
                'delivery_status', m.delivery_status, 'actor_user_id', m.actor_user_id,
                'participant_name', m.participant_name, 'participant_address', m.participant_address,
                'sender_name', m.sender_name, 'sender_address', m.sender_address,
                'recipients', m.recipients, 'cc_recipients', m.cc_recipients,
                'subject', m.subject, 'body_text', m.body_text, 'body_html', m.body_html,
                'source_email_type', m.source_email_type, 'source_metadata', m.source_metadata,
                'sent_at', m.sent_at
            ) ORDER BY m.sent_at NULLS LAST, m.created_at, m.id)
            FROM cidb_email_messages m WHERE m.thread_id = th.id
        ), '[]'::jsonb)
    ) AS details
    FROM cidb_email_threads th
    WHERE th.id = COALESCE(t.email_thread_id, (
        SELECT candidate.id FROM cidb_email_threads candidate
        WHERE candidate.crm_id = COALESCE(interaction.crm_id, t.clog_crm_id)
        LIMIT 1
    ))
) conversation ON true
LEFT JOIN LATERAL (
    SELECT jsonb_agg(jsonb_build_object(
        'id', e.id, 'actor_user_id', e.actor_user_id,
        'actor_type', e.actor_type, 'event_type', e.event_type,
        'old_values', e.old_values, 'new_values', e.new_values,
        'metadata', e.metadata, 'created_at', e.created_at
    ) ORDER BY e.created_at, e.id) AS events
    FROM portal_ticket_events e WHERE e.ticket_id = t.id
) history ON true;

CREATE OR REPLACE VIEW portal_crm_details AS
SELECT
    i.id AS interaction_id, i.crm_id, i.applicant_name, i.nric, i.email, i.phone,
    i.customer_type, i.state, i.language, i.company_name, i.company_no,
    i.current_status, i.clog_status, i.outcome_code AS rpa_outcome_code, i.completed_at,
    COALESCE(thread_list.threads, '[]'::jsonb) AS email_threads,
    COALESCE(ticket_list.tickets, '[]'::jsonb) AS tickets,
    COALESCE(data_list.records, '[]'::jsonb) AS clog_records
FROM cidb_interaction_data i
LEFT JOIN LATERAL (
    SELECT jsonb_agg(jsonb_build_object(
        'id', th.id, 'thread_key', th.thread_key, 'subject', th.subject,
        'first_message_at', th.first_message_at, 'last_message_at', th.last_message_at,
        'message_count', (SELECT count(*) FROM cidb_email_messages m WHERE m.thread_id = th.id)
    ) ORDER BY th.last_message_at DESC NULLS LAST) AS threads
    FROM cidb_email_threads th WHERE th.crm_id = i.crm_id
) thread_list ON true
LEFT JOIN LATERAL (
    SELECT jsonb_agg(jsonb_build_object(
        'id', t.id, 'source_type', t.source_type, 'workflow_status', t.workflow_status,
        'scenario_key', t.scenario_key, 'bucket_id', t.bucket_id, 'assigned_to', t.assigned_to,
        'created_at', t.created_at, 'updated_at', t.updated_at
    ) ORDER BY t.created_at DESC) AS tickets
    FROM portal_tickets t WHERE t.interaction_id = i.id OR t.clog_crm_id = i.crm_id
) ticket_list ON true
LEFT JOIN LATERAL (
    SELECT jsonb_agg(jsonb_build_object(
        'source_data_id', d.source_data_id, 'parent_id', d.parent_id,
        'form_id', d.form_id, 'form_name', d.form_name, 'customer_type', d.customer_type,
        'interaction_type', d.interaction_type, 'language', d.language,
        'state_location', d.state_location, 'contact_id', d.contact_id,
        'source_created_at', d.source_created_at
    ) ORDER BY d.source_created_at) AS records
    FROM cidb_clog_data_records d WHERE d.interaction_id = i.id
) data_list ON true;

COMMIT;
