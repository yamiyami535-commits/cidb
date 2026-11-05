# MySQL CLOG to PostgreSQL sync

Run Cron1 with `php bin/cron_runner.php`. It reads CLOG MySQL credentials from `.env` and writes ticket, thread, and document-reference metadata to the portal PostgreSQL database. Configure `CLOG_DB_*`, `DB_*`, and `CLOG_TIMEZONE` (default `Asia/Kuala_Lumpur`). Do not put credentials in PHP source or tracked files.

Normal Cron1 operation is today-only and limited to Open CLOG emails. Use `--crm-id=CRM-... --backlog` only for a specifically authorized historical repair. Add `--skip-rpa-dispatch` to that scoped command when the repair should sync portal records without creating an RPA Hub row. The scoped command does not advance the global sync cursor.

## Source mapping

- `emails.uniqid` is the CRM key.
- `emails.id` is the source message ID and links `files.email_id` to attachment filenames.
- CLOG `data.InteractionID` links interaction data to the CRM key; `activity.DataID` and `ticketing_log.TicketID` link to `data.ID`.
- `New Email` and `Reply Mail` are customer-originated messages and are eligible to provide OCR attachments. `ReplyTo Mail` records an agent response; its attachments remain outside the customer-document OCR set.
- Cron2 can include Closed customer replies because CLOG may close a case after an agent response. Normal Cron2 is still limited to today's email window; use its scoped `--backlog` option only for a historical repair.

The sync stores CLOG message history and attachment references; it does not copy attachment bytes into PostgreSQL or the portal server. Each `portal_ticket_documents` row includes the CRM ID, source email ID, filename, retrieval URL, reference state, and OCR state. `reference_status='ready'` confirms that a safe retrieval URL was built, not that the remote file was downloaded. OCR downloads eligible customer files from the CLOG endpoint.

Ticket classification uses CLOG's stored customer type and cancellation category. Company cases are excluded from the Individual cancellation path. Explicit reset, password, email-change, and new-application intent in the current customer email overrides a stale cancellation category.

## Attachment repair

For a scoped historical repair, first sync the CRM's ticket/thread without dispatch using:

```powershell
php bin/cron_runner.php --crm-id=CRM-... --backlog --skip-rpa-dispatch
```

Then, when OCR is intended, run:

```powershell
php bin/cron_runner.php --crm-id=CRM-... --cron2 --backlog
```

The source email ID is the attachment join key. Do not join CLOG files by filename alone; filenames can repeat across different emails or CRM threads.
