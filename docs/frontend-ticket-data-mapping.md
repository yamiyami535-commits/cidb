# Front-end ticket data mapping

This guide maps the PostgreSQL ticket foundation to the screens the portal can build. It describes the database views and tables defined by the migrations; it does not define existing HTTP API endpoints. The front end should call the authenticated portal backend, not connect directly to PostgreSQL.

The views and tables in this guide are available only after the ticket workflow migrations have been applied to the portal PostgreSQL database.

## Recommended screen flow

1. **Ticket queue:** show a compact, paginated list using `portal_tickets` plus the bucket and assignee names. Do not use the detail view for every queue row: it includes message bodies, document arrays, actions, and history.
2. **Ticket detail:** load one ticket from `portal_ticket_details` by ticket UUID.
3. **CRM search:** search for an exact CRM ID in `portal_crm_details`. Show the customer's linked ticket and thread summaries, then load an individual ticket for its full messages and audit history.
4. **Conversation:** show messages in timestamp order. Treat `parent_message_id` as optional; CLOG does not provide a confirmed parent-message key.
5. **Documents and actions:** show document reference/OCR states separately from RPA action states. Neither state is the ticket's workflow status.

## Data relationships

```text
cidb_interaction_data (one CRM/customer mirror row)
  ├── cidb_email_threads (conversation grouping by CRM ID)
  │     └── cidb_email_messages (individual CLOG, mailbox, or agent messages)
  ├── cidb_clog_data_records (source interaction/form detail)
  ├── cidb_clog_activity (CLOG activity rows)
  └── cidb_clog_ticketing_log (CLOG ticketing/status log rows)

portal_tickets (one operational work item)
  ├── interaction_id ───────────────> cidb_interaction_data
  ├── email_thread_id ──────────────> cidb_email_threads
  ├── portal_ticket_documents (attachment metadata and OCR result)
  │     └── portal_ticket_ocr_jobs (future OCR dispatch/result tracking)
  ├── portal_rpa_action_queue (future RPA action/result tracking)
  ├── portal_ticket_message_outbox (future outbound email tracking)
  └── portal_ticket_events (ticket audit/history)
```

An interaction/customer is not the same thing as a ticket. A CRM ID can be associated with more than one ticket. Threads currently group messages by CRM ID when one is available; this is a useful display grouping, not proof that CLOG's source emails are all one true reply chain.

## Data sources and display mapping

| Portal display | Database field | Meaning / display guidance |
|---|---|---|
| Ticket number / internal identity | `id` | UUID. Use as the stable ticket key in portal routes and actions. |
| Intake source | `source_type` | `clog`, `email`, or `portal`. Display as a source badge. |
| Source reference | `source_ref` | Source-system identifier; useful for support/debugging, usually not the main title. |
| CRM ID | `clog_crm_id`, or `interaction_details.crm_id` | Link to the customer/CRM search result. Can be null for email with no extracted CRM ID. |
| Customer name / email / ID | `applicant_name`, `applicant_email`, `id_number` | Ticket snapshot fields. `interaction_details` contains normalized CRM mirror values when linked. Apply privacy rules for NRIC/ID display. |
| Customer classification | `customer_type` | `individual`, `company`, or `unknown`. Do not infer a value from missing data. |
| Contact details | `contact_number`, `state`, `language`, `company_name`, `company_no` | Ticket-level captured details. Some fields may be empty until verified. |
| Work status | `workflow_status` | Portal-owned workflow, explained below. Use this for operational queue/status badges. |
| CLOG source status | `clog_status` | CLOG's source status, e.g. `Open` or `Closed`. It is not the portal workflow state. |
| Portal interaction status | `interaction_details.current_status` | Portal-owned CRM interaction status. `Offered` should only mean an information request was confirmed sent; current workers do not send that message. |
| Scenario | `scenario_key` | Workflow/classification category, e.g. `cancellation-classification-pending`. |
| Queue / team | `bucket_id` | Bucket UUID; join to `portal_ticket_buckets` for its display name. |
| Assigned agent | `assigned_to`, `assigned_user` | UUID and optional compact agent object (`id`, `username`, `role`, `is_active`). Null means unassigned. |
| Missing information | `missing_fields` | JSON array of field labels/keys. Render as a list of items the team/customer still needs to provide. |
| Attachments | `documents` | JSON array of document metadata and current reference/OCR statuses; see document mapping below. |
| Customer conversation | `email_thread` | One thread object containing messages for the linked thread; see message mapping below. |
| Ticket audit | `ticket_history` | JSON array of portal ticket events (creation, selected changes, message receipt); see history notes below. |
| Workflow outcome | `ocr_result`, `execution_result` | Nullable JSON result fields. Do not assume a result exists before an integration writes one. |
| Created / updated | `created_at`, `updated_at` | Ticket timestamps. Show with the portal's local-time formatting convention. |

### Status fields are separate

| Field | Owner | What it answers |
|---|---|---|
| `workflow_status` | Portal ticket workflow | What stage is this work item at? |
| `clog_status` | CLOG | What status did the source system report? |
| `interaction_details.current_status` | Portal interaction mirror | What portal-owned status is recorded for this CRM interaction? |
| `actions[].state` | RPA action queue | What happened to a particular RPA action? |
| `documents[].ocr_status` / `ocr_jobs[].state` | Document/OCR workflow | What happened to OCR for a particular file? |
| `email_intake.stage` | Mailbox intake worker | What processing stage is the original incoming mailbox item in? |

Do not collapse these fields into one status pill. If the UI needs a single primary status, use `workflow_status` and show source/integration statuses as separate labels.

Current `workflow_status` values are:

| Value | Suggested label |
|---|---|
| `intake_pending` | New / intake review |
| `needs_information` | Needs information |
| `ready_for_ocr` | Ready for OCR |
| `ocr_processing` | OCR in progress |
| `ocr_manual_review` | OCR manual review |
| `ocr_rejected` | OCR needs review |
| `ready_for_execution` | Ready for execution |
| `execution_processing` | Execution in progress |
| `completed` | Completed |
| `failed` | Failed |
| `closed` | Closed |

These are allowed database values, not a guarantee that every transition or automation is implemented. The OCR worker is opt-in and leaves every processed ticket for human review; RPA execution and outbound customer messaging are not currently live.

## Queue / ticket-list query

Use a compact query for a paginated queue. Have the backend validate/allowlist any filter or sort values before building SQL.

```sql
SELECT
    t.id,
    t.source_type,
    t.source_ref,
    t.clog_crm_id,
    t.applicant_name,
    t.applicant_email,
    t.customer_type,
    t.workflow_status,
    t.clog_status,
    t.scenario_key,
    t.bucket_id,
    b.name AS bucket_name,
    t.assigned_to,
    u.username AS assigned_username,
    t.missing_fields,
    t.attachment_lookup_status,
    t.created_at,
    t.updated_at
FROM portal_tickets t
LEFT JOIN portal_ticket_buckets b ON b.id = t.bucket_id
LEFT JOIN portal_users u ON u.id = t.assigned_to
WHERE t.workflow_status <> 'closed'
ORDER BY t.updated_at DESC, t.id
LIMIT $1 OFFSET $2;
```

For active CLOG-origin work, add:

```sql
AND t.source_type = 'clog'
AND lower(trim(coalesce(t.clog_status, ''))) = 'open'
```

The `WHERE` conditions belong before `ORDER BY`. For a source dropdown, filter `source_type`; for a workflow dropdown, filter `workflow_status`; for an agent or bucket queue, filter `assigned_to` or `bucket_id`. Keep count queries separate from the page query.

## Ticket detail query

The view returns one denormalized row and uses JSON fields for related collections. Query it for a single selected ticket, not for the entire queue.

```sql
SELECT *
FROM portal_ticket_details
WHERE id = $1;
```

The main additional JSON fields are:

| Field | Shape |
|---|---|
| `documents` | Array of document objects; empty array when none. |
| `actions` | Array of RPA action objects; empty array when none. |
| `assigned_user` | Object or `null`. |
| `portal_request` | Object or `null`; legacy request/RPA-related details. |
| `email_intake` | Object or `null`; mailbox processing metadata. Message body belongs in the conversation record, not this object. |
| `interaction_details` | Object or `null`; normalized CRM/customer details. |
| `email_thread` | Object or `null`; includes `messages` array. |
| `ticket_history` | Array of portal audit event objects; empty array when none. |

### Document object

`documents[]` contains:

| Field | UI use |
|---|---|
| `id` | Stable document UUID. |
| `filename` | Display filename. |
| `mime_type` | Optional file type. |
| `crm_id`, `source_email_id`, `email_message_id` | Source linkage/debug context. |
| `retrieval_url` | CLOG file reference. Only expose/use through an authorized backend; it is a reference, not a guarantee the file still exists. |
| `reference_status` | `ready` means the URL was constructed safely; `invalid` means the reference needs review. |
| `reference_result` | Optional explanation/details of reference validation. |
| `ocr_status` | `pending`, `processing`, `valid`, `invalid`, `unreadable`, or `error`. |
| `ocr_result` | Nullable OCR response JSON. |

`portal_ticket_ocr_jobs` provides the future OCR dispatch ledger (`state`, `attempts`, `error_code`, `correlation_id`, request/response payloads). The view does not currently include OCR job rows. Load those separately through the authenticated backend if the detail screen needs job-level diagnostics.

### Email thread and message object

`email_thread` contains `id`, `crm_id`, `subject`, `first_message_at`, `last_message_at`, and `messages[]`.

Each message includes:

| Field | Meaning |
|---|---|
| `id` | Portal message UUID. |
| `source_system` | `clog`, `mailbox`, or `agent`. |
| `source_message_id` | Stable source identity used for de-duplication. |
| `direction` | `inbound`, `outbound`, `internal`, or `unknown`. CLOG messages currently use `unknown` until direction mapping is confirmed. |
| `delivery_status` | `received`, `draft`, `queued`, `sent`, `failed`, `uncertain`, or `unknown`. |
| `sender_name`, `sender_address` | Sender identity when provided by the source. |
| `participant_name`, `participant_address` | CLOG participant identity fields. |
| `recipients`, `cc_recipients` | JSON arrays; currently often empty. |
| `subject`, `body_text`, `body_html` | Message content. Prefer plain text by default; sanitize HTML before rendering. |
| `sent_at` | Source/message timestamp when known. |
| `parent_message_id`, `source_parent_message_id`, `rfc_message_id` | Optional reply linkage/headers. CLOG does not expose a confirmed parent key; do not fabricate a relationship. |
| `source_email_type`, `source_metadata` | Original source type and supplemental source fields. |

Messages in the CRM thread are ordered by `sent_at` (unknown timestamps last), then record creation time. A thread groups messages by CRM ID and may contain distinct requests. The UI should show each message's source and should not imply a precise parent-child chain unless a parent link is present.

### Ticket-history object

`ticket_history[]` contains:

| Field | Meaning |
|---|---|
| `id` | Event UUID. |
| `actor_user_id` | Portal user UUID or `null` for system/integration activity. |
| `actor_type` | `user`, `system`, or `integration`. |
| `event_type` | Event code such as `ticket.created`, `ticket.workflow_status_changed`, `ticket.assignment_changed`, `ticket.classification_changed`, or `ticket.message_received`. |
| `old_values`, `new_values` | JSON snapshots for the fields recorded by that event; may be null. |
| `metadata` | Additional JSON context, such as source reference or message UUID. |
| `created_at` | Event time. |

The audit trigger currently records ticket creation plus workflow, assignment/bucket/scenario, and classification/missing-field changes. It does not automatically audit every customer-field edit. CLOG's own activity and ticketing log are separate source tables, not included in `ticket_history`.

## CRM search query

Use an exact CRM ID match and return one customer summary:

```sql
SELECT *
FROM portal_crm_details
WHERE crm_id = $1;
```

`portal_crm_details` returns normalized customer/interaction fields, `email_threads[]` summaries (message counts and time range), `tickets[]` summaries, and `clog_records[]` source-detail summaries. It does not return full message bodies or portal ticket history. Open a ticket by selecting its UUID from `tickets[]` and calling the ticket-detail read.

`cidb_clog_activity` and `cidb_clog_ticketing_log` are separate tables. If the CRM page needs the original CLOG activity stream, the backend should query those by `interaction_id` and present them as a separately labeled source-system history; do not merge them into portal ticket audit events without distinguishing their origin.

## Front-end behavior and safety notes

- Null means the source has not provided that value; display a clear empty state rather than inventing it.
- JSON arrays are empty arrays when the views use `COALESCE`; nullable single objects may be `null`.
- Treat `source_data`, `source_metadata`, extraction results, and OCR/RPA payloads as untrusted data. Escape/sanitize content before rendering.
- Do not display full NRIC/ID values by default; mask them according to the portal's privacy rules.
- Do not expose PostgreSQL credentials or connect the browser directly to the database. Enforce authentication and ticket/customer authorization in the backend.
- Do not present `reference_status='ready'` as “file downloaded” or “OCR passed.” It only means a safe reference URL was formed.
- Do not present `manual_review` queue entries as already sent to a customer or RPA.
- Current CRM threading is a grouping convenience, not proof of an email parent/child relationship.
- UI actions such as changing assignment, status, classification, or sending a reply need authorized backend endpoints. The database views are read models, not write APIs.
