# OCR and Cron2 operations checklist

This checklist describes the scheduled individual-cancellation workflow. Use redacted or approved SIT documents for manual checks; do not copy customer NRICs into logs or reports.

## Configuration

- Apply the ticket foundation, attachment, conversation, OCR, and OCR-status cleanup migrations in order. Fresh databases use `database/schema.sql`.
- Configure CLOG and portal database connections in the portal `.env`.
- Configure the OCR service privately, set `API_KEY` on that service and the same `OCR_API_KEY` in the portal `.env`, and confirm `GET /health` from the portal host.
- Set `OCR_ENABLED=true` only after the service and migrations are ready.
- Confirm the RPA Hub MySQL connection, BotID `118`, and BR20 runner settings.

## Scheduled flow

1. Cron1 runs `php bin/cron_runner.php`. It reads Open CLOG records for today in `CLOG_TIMEZONE`, filters to Individual ID cancellations, excludes company records and agent `ReplyTo Mail`, and stores customer email/document metadata.
2. Cron2 runs `php bin/cron_runner.php --cron2`. It checks today's customer `New Email` / `Reply Mail` attachments, including customer replies CLOG marks Closed, then runs OCR in the same invocation.
3. OCR checks blur and extracts name, NRIC, and address where readable. It records OCR state on each document, without adding ticket workflow statuses.
4. Identity mismatch or unreadable documents remain out of the automatic RPA gate. If all customer documents have verified identity and the case is an Individual cancellation, the worker directly inserts the exact nine-field `Email Cancel` payload into RPA Hub.

Use `--crm-id=CRM-... --backlog` only for a specific historical repair. Add `--skip-rpa-dispatch` to Cron1 when repairing ticket/thread metadata without sending an RPA request. Do not run unrestricted backlog as a scheduled task.

## Database verification

```sql
SELECT t.clog_crm_id, t.workflow_status, t.clog_status,
       d.source_email_id, d.original_filename, d.reference_status,
       d.ocr_status, d.ocr_result->>'identity_check_verified' AS identity_verified
FROM portal_tickets t
JOIN portal_ticket_documents d ON d.ticket_id = t.id
WHERE t.clog_crm_id = :crm_id
ORDER BY d.source_email_id, d.original_filename;
```

Expected for a customer file is a matching CLOG email ID, filename, `reference_status='ready'`, and an OCR result after Cron2. A ready reference confirms URL construction; the OCR worker must still fetch the actual file. Agent `ReplyTo Mail` attachments are not OCR inputs.

## RPA verification

The Hub scheduler row should contain only these Bot_Input keys, in order: `sCRMID`, `sEmail`, `sCustomerName`, `sIdentificationNumber`, `sContactNumber`, `sLanguage`, `sChannel`, `sRequest`, `sCustomerType`. For cancellation, `sRequest` must be `Email Cancel` and `sCustomerType` must be `Individual`. The Hub row being assigned is not proof that the cancellation completed; check its completion status separately.
