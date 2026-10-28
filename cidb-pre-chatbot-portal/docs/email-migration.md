# Exact email migration

Migration: [`database/migrations/20260925_email_reader.sql`](../database/migrations/20260925_email_reader.sql). That file contains the complete executable SQL, including its transaction and five-second lock timeout. It has not been applied to the portal database by this implementation work.

The cancellation ticketing foundation is a separate follow-up migration: [`20261020_ticket_workflow.sql`](../database/migrations/20261020_ticket_workflow.sql). Apply it after this email migration before running either CLOG or email Cron1 ticket handoff. It creates ticket/scenario/bucket/document/action tables and does not run automatically.

## Existing database

From the project directory, use the connection details for the intended portal database:

```text
psql -h <database-host> -p <database-port> -U <database-user> -d <portal-database> -v ON_ERROR_STOP=1 -f database/migrations/20260925_email_reader.sql
```

Supply passwords securely through PostgreSQL's normal prompt/credential mechanism. The placeholders above are connection values, not literal arguments to use.

Alternatively, in a SQL editor connected to the correct database, execute the complete contents of `20260925_email_reader.sql` unchanged. No additional ad hoc ALTER statements are required. Review and back up the target database first; no migration is run automatically by PHP.

If upgrading a database from the period when the CRM column was removed, first apply `database/migrations/20260924_restore_crm_requirement.sql`. The email migration then permits historical null-CRM rows to receive result updates while continuing to require CRM for new form records. Do not reapply the older CRM migration after email records exist.

## Fresh database

Use `database/schema.sql` via psql. It creates the base tables and includes the email migration using `\ir migrations/20260925_email_reader.sql`. Do not run the base schema against an existing database.

## Exact schema changes

### `portal_requests` (existing table)

- Add `request_source varchar(8) NOT NULL DEFAULT 'form'`. Existing rows automatically remain form requests.
- Drop column-level NOT NULL from `user_id`, `applicant_name`, `id_number`, `applicant_email`, `crim`, and `rpa_request_payload` so unowned or incomplete email requests can be stored.
- Add `ck_portal_requests_source`: source must be `form` or `email`.
- Add `ck_portal_form_required`: form rows still require owner, name, ID, email and payload.
- Add the `portal_request_source_guard()` function and `portal_request_source_guard` BEFORE INSERT OR UPDATE trigger: source cannot change; new forms require CRM; existing non-null form CRM cannot be removed; historical null CRM remains valid for updates.
- Retain existing foreign keys, nonempty-value checks, HTTP status check and unique `(user_id, submission_key)` form duplicate protection.
- Add index `ix_portal_requests_source_created (request_source, created_at DESC)`.

### `portal_email_mailboxes` (new table)

Columns: `mailbox_key varchar(64)` primary key; `uid_validity bigint`; `activation_uid bigint`; `last_uid bigint`; `activated_at timestamptz`; nullable `last_checked_at timestamptz`; nullable `last_error varchar(100)`.

Checks: UID validity > 0; activation UID >= 0; cursor >= activation UID. Activation timestamp defaults to now.

### `portal_email_intake` (new table)

Columns: UUID `id` primary key; `mailbox_key varchar(64)` foreign key; `uid_validity bigint`; `message_uid bigint`; nullable unique UUID `request_id` foreign key; text `message_id`, `sender`, `subject`, `location_area`; `missing_fields jsonb DEFAULT '[]'`; `stage varchar(32) DEFAULT 'discovered'`; `next_attempt_at timestamptz`; `read_marked boolean DEFAULT false`; nullable `last_error varchar(100)`; `created_at` and `updated_at` timestamps.

Constraints: unique `(mailbox_key, uid_validity, message_uid)`; UID > 0; stage allowlist `discovered`, `ignored`, `ready`, `retry_due`, `dispatching`, `awaiting_result`, `finished`, `missing_fields`, `extraction_attention`, `read_error`, `submission_uncertain`, `submission_failed`. Stage is operational state, separate from RPA's request status.

Index: `ix_portal_email_work (mailbox_key, stage, next_attempt_at)`.

### `portal_email_attempts` (new table)

Columns: UUID `id` primary key; UUID `intake_id` foreign key; `attempt_no smallint`; `outcome varchar(24) DEFAULT 'dispatching'`; nullable `http_status smallint`, `response jsonb`, `response_text text`, `error_code varchar(100)`; `started_at timestamptz DEFAULT now()`; nullable `finished_at timestamptz`.

Constraints: unique `(intake_id, attempt_no)`; attempt number 1–2; outcome allowlist `dispatching`, `accepted`, `success`, `failed`, `safe_failure`, `uncertain`. The retained outcome vocabulary does not authorize PHP to update the request's business status.

### `portal_email_notifications` (new, currently unused for sending)

Columns: UUID `id` primary key; unique UUID `intake_id` foreign key; `recipient varchar(254)`; `state varchar(24) DEFAULT 'pending'`; `attempts smallint DEFAULT 0`; `next_attempt_at timestamptz DEFAULT now()`; nullable `last_error varchar(100)`; `updated_at timestamptz DEFAULT now()`.

Constraints: attempts 0–2; state allowlist `pending`, `sending`, `accepted`, `failed`, `uncertain`. Index: `ix_portal_email_notifications_due (state, next_attempt_at)`. The table is retained for future restoration; notification job creation, dispatch and retries are commented out.

## Status-only RPA results: no additional migration

The existing `status varchar(24) NOT NULL DEFAULT 'processing'` and `ck_portal_requests_status` already allow `processing`, `pending`, `success`, `failed`. Keep these unchanged. PHP inserts the initial processing value and never subsequently writes a business status. RPA updates only `success` or `failed`; both initial values display as In progress.

`rpa_display_message` remains in storage but is unused by current submission/polling/UI code. Do not drop it or rewrite historical statuses. No status backfill, new status column, status trigger or index is required. `completed_at` can remain null because status alone ends polling.

Once the RPA team has established the correct portal row ID, the final write has this parameterized SQL shape (executed by RPA, not by the portal or this migration):

```sql
UPDATE portal_requests
SET status = :final_status
WHERE id = :portal_request_id;
```

`:final_status` must be `success` or `failed`. These are bind parameters, not a manual instruction to mark a real request complete. The email payload does not contain `portal_request_id`; correlation must be resolved by the existing RPA integration.

If `20260925_email_reader.sql` is already applied, the status-only change requires no further SQL. The migration is repeatable, but do not apply it merely to change the presentation behavior.
