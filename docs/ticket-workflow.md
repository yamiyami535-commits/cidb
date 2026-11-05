# Cancellation ticket workflow

## Database setup

For an existing database, apply the ticket migrations in order: `20261020_ticket_workflow.sql`, `20261021_ticket_attachment_lookup.sql`, `20261022_clog_open_status.sql`, `20261023_ticket_conversation_foundation.sql`, `20261027_ocr_manual_review.sql`, then `20261105_cleanup_ocr_ticket_statuses.sql`. The preceding email-reader migration must also be applied if the email workflow is installed. Migrations are not run automatically. `database/schema.sql` includes the complete sequence for fresh databases.

## Status model

`portal_tickets.workflow_status` tracks ticket work and uses only the existing values: `intake_pending`, `needs_information`, `ready_for_ocr`, `ready_for_execution`, `execution_processing`, `completed`, `failed`, and `closed`. OCR outcomes live on `portal_ticket_documents.ocr_status`; OCR does not add ticket workflow statuses.

`portal_tickets.clog_status` mirrors the source case status. `cidb_interaction_data.current_status` records the existing RPA/customer status. These fields are separate and must not be substituted for one another.

## CLOG source and attachment mapping

CLOG attachments are references; file bytes remain at the CLOG file endpoint. For each CRM, resolve files through `emails.uniqid` -> `emails.id` -> `files.email_id`. Customer documents are attached to `New Email` and `Reply Mail` messages. `ReplyTo Mail` is an agent reply and its attachments are not customer OCR inputs.

Each customer attachment is stored in `portal_ticket_documents` with `source_email_id`, `email_message_id`, `crm_id`, `original_filename`, a URL-encoded retrieval URL, `reference_status`, and OCR fields. `reference_status='ready'` means the reference is structurally valid; the OCR worker separately downloads the file. The document table does not contain the binary file.

## Cron1: new cases

Run `php bin/cron_runner.php`. Normal operation considers Open CLOG email rows from the current day in `CLOG_TIMEZONE` (default `Asia/Kuala_Lumpur`). It accepts only CLOG-confirmed Individual ID cancellations, excludes company cases and agent `ReplyTo Mail` rows, and does not process backlog. If customer documents are absent, it sends an RPA Hub `Pending Customer Attachment` request. The direct Hub insert uses the agreed nine Bot_Input keys in order: `sCRMID`, `sEmail`, `sCustomerName`, `sIdentificationNumber`, `sContactNumber`, `sLanguage`, `sChannel`, `sRequest`, `sCustomerType`.

Use `--crm-id=CRM-... --backlog` only for an explicitly scoped historical repair. Add `--skip-rpa-dispatch` when syncing data without inserting an RPA request.

## Cron2: customer replies and OCR

Run `php bin/cron_runner.php --cron2`. Cron2 checks eligible individual cancellation tickets for customer documents dated today, including customer `Reply Mail` rows that CLOG has marked Closed. Use `--crm-id=CRM-... --backlog` only for an explicitly scoped historical reprocess. The worker imports files by their source email IDs and invokes OCR in the same run when OCR is enabled.

OCR extracts name, NRIC, and address where readable and checks image blur. Identity and image-quality success is recorded on document rows as `ocr_status='manual_review'` for frontend display, with `identity_check_verified=true`. OCR does not claim to verify signature, CIDB-use markings, or company stamps. Identity mismatches and unreadable documents do not pass the automatic RPA gate.

## RPA cancellation gate

`Email Cancel` is inserted directly into RPA Hub MySQL; the API is not used for this path. The Hub row uses BotID `118` and the configured `BR20` runner. Insertion requires an Individual cancellation ticket, at least one ready customer document, verified OCR identity for every ready customer document, and the customer's name and NRIC. A repeated CRM/request pair is deduplicated. The Bot_Input contains exactly the nine keys listed above; `sRequest` is `Email Cancel` or `Pending Customer Attachment`.

The standalone Python Hub injector cannot verify portal OCR documents and therefore refuses `Email Cancel`. Use the PHP OCR-verified path for cancellation submissions.

## Scheduled operation

Schedule Cron1 and Cron2 separately, with Cron2 a few minutes after Cron1. Configure the task runner to prevent overlapping instances. Keep the OCR service persistent and available before setting `OCR_ENABLED=true`. No production task scheduler, portal deployment target, or production host is configured by this repository; follow the infrastructure runbook for deployment.
