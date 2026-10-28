# MySQL CLOG to PostgreSQL sync

Run the sync from the project directory with `php bin/cron_runner.php`. It reads MySQL connection settings from `.env` and uses the portal's existing PostgreSQL `DB_*` settings. Configure `CLOG_DB_HOST`, `CLOG_DB_DATABASE`, `CLOG_DB_USERNAME`, `CLOG_DB_PASSWORD`, and, if needed, `CLOG_TIMEZONE` (defaults to `Asia/Kuala_Lumpur`). Do not put database passwords in the PHP source.

For a controlled one-case test, run `php bin/cron_runner.php --crm-id=CRM-ID` with the exact CRM ID approved for the test. In scoped mode it reads only that CRM's `Open` CLOG email rows and refreshes attachment references only for that CRM in the portal database. It does not advance or modify the global `sync_meta` cursor. It can still copy that CRM's open email/thread details and filenames into the portal database, so use only an approved case and do not include the ID in shared logs or screenshots. Do not run the unrestricted command for an SIT test against production CLOG unless a broader production-data import is approved.

On first run, `CLOG_INITIAL_SYNC_DAYS=0` starts from midnight today in `CLOG_TIMEZONE` instead of importing the full email history. Set a larger non-negative number to include that many previous days. The selected start date is saved once in `sync_meta` as `customer_info_start`, so later daily runs do not move the cutoff forward and skip an unprocessed day. Each committed batch prints its count, cursor, and elapsed time. The sync continues from the last committed cursor after an interruption.

The source database is `cidb_crm`. The job reads the existing `emails`, `files`, `data`, `activity`, and `ticketing_log` tables; it does not require a `clog_email_cases` or `clog_interactions` table.

Apply both [ticket workflow migrations](ticket-workflow.md) and the conversation split migration (`database/migrations/20261023_ticket_conversation_foundation.sql`) before running the new ticket-handoff behavior. The migration stores CLOG and mailbox messages separately in `cidb_email_threads` / `cidb_email_messages`, while `cidb_clog_data_records`, `cidb_clog_activity`, and `cidb_clog_ticketing_log` keep the source details separate from the portal ticket model. CLOG does not expose a reliable parent-message key; parent links stay null unless a source message or RFC `In-Reply-To` header can be matched.

## Mapping

- `emails.uniqid` is the CRM key (`cidb_interaction_data.crm_id`).
- `emails.name` and `emails.email` populate the applicant name and email.
- `emails.case_status` populates `cidb_interaction_data.clog_status`; new interaction rows start with portal-owned `current_status='Open'`, and existing `current_status` values are preserved.
- `emails.time` drives the incremental cursor and the initial target `created_at`.
- `files.email_id` links filenames to `emails.id`.
- `data.InteractionID` links the source interaction data to the CRM key; `activity.DataID` and `ticketing_log.TicketID` are linked to `data.ID`.
- CLOG emails, data records, activity, and ticketing logs are stored in separate conversation/source tables after applying `20261023_ticket_conversation_foundation.sql`. Existing `clog_raw_data` is only retained as a migration/backfill source; new sync runs do not write the full CLOG payload into it. NRIC and phone are not inferred from generic source columns.

The job creates or refreshes a portal ticket only when the CLOG email has `case_status='Open'` and title/body contains a cancellation-ID phrase. `Closed` and other statuses do not create tickets. Existing CLOG ticket `clog_status` values are refreshed from source rows, and only `clog_status='Open'` CLOG tickets are rechecked for attachments. Candidate tickets enter the `cancellation-classification-pending` scenario and the `unassigned` bucket. The raw CLOG payload and filename references are retained for classification; no company/individual type is guessed. Ticket upserts do not reset manual assignment, scenario, or workflow status.

For each CLOG file attached to a cancellation ticket, the job stores a document metadata row containing the filename, CLOG `emails.id`, CRM ID, and a URL built from `CLOG_FILE_FETCH_BASE_URL` (defaults to `https://cidb.daythree.net/handler/email_fetch/files`). The full file is **not** copied into PostgreSQL or the portal server. `reference_status='ready'` validates only the URL inputs; it does not call the endpoint. Cron2 can fetch the file directly by URL when OCR is connected.

Every run also rechecks CLOG email/file relationships for existing CLOG and email tickets by exact CRM ID, considering only CLOG emails whose `case_status` is `Open`, so a file row added later can be linked without changing the original email timestamp. The email reader performs the same Open-only lookup immediately when it creates an email ticket. A CRM lookup result is recorded on the ticket, while each filename and OCR state is stored separately in `portal_ticket_documents`.

The sync still imports source email rows into the interaction mirror for status/history, but ticket intake is limited to CLOG `Open`. Changes to an old CLOG case are reflected only when CLOG provides a later email row; the incremental cursor does not poll old cases independently. The source-owned `clog_status`, portal-owned `current_status`, `portal_tickets.workflow_status`, and RPA action state are separate values.

New CLOG interaction records begin with `current_status='Open'`. The status should become `Offered` only after the information-request RPA/email action is confirmed successful. That sender is not implemented yet, so the current action-queue entries remain `manual_review` and no automatic transition to `Offered` occurs.

The job reads in bounded batches, upserts by CRM ID, logs changes to the source CLOG status, and advances the cursor as each batch commits. Reprocessing a timestamp boundary is safe because the upsert is idempotent.

For efficient filtering and joins, check that MySQL has indexes on `emails(time, id)`, `files(email_id)`, `data(InteractionID)`, `activity(DataID)`, and `ticketing_log(TicketID)`. Check existing indexes before adding any. Without the relevant indexes, MySQL may scan a large source table even though PHP fetches only the matching batch rows.

The source exports indicate these key names and relationships; verify that the live MySQL schema uses the same names before scheduling this job. The script reports a database error if a required source table or column is missing.
