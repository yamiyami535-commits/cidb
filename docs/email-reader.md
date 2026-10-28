# PHP email reader: cancellation intake

## Current workflow

The CLI reader processes post-activation arrivals in the configured mailbox folder. It accepts a message only when its subject or body mentions cancellation/cancel/pembatalan together with ID, account, or registration. `EMAIL_SELECTION_MODE=all` permits any sender in the dedicated folder, but does not bypass the cancellation check. Existing safeguards for self-generated mail and configured rule-mode sender/subject checks still apply.

The worker extracts the currently recognized `Name`, `Email to Cancel ID`, `NRIC`, `State`, and `CRM` labels. It stores decoded text/HTML in `cidb_email_messages` and keeps message headers and extracted-field metadata in `portal_email_intake.raw_message`. When a valid CRM ID is present, it queries CLOG `emails.uniqid` for `case_status='Open'`, then follows each matching `emails.id` through `files.email_id` to find attachment filenames. It stores each match as a `portal_ticket_documents` row linked to the email ticket and records the lookup outcome on `portal_tickets`. It does not invent a customer type or treat the current individual-only RPA payload mapping as a classification rule.

Each accepted case is recorded in `portal_requests` for existing shared email history and in `portal_tickets` for the new workflow. Unclassified cases enter `cancellation-classification-pending`; missing customer type, contact number, language, or other required fields keep the ticket in `needs_information`. The current extractor does not yet recognize every confirmed field (including contact number, language, and company details), so those cases must not be treated as execution-ready.

## No external dispatch in Cron1

The email worker does not call the cancellation execution RPA or SMTP. For a case with missing information it creates a `request_information` intent in `portal_rpa_action_queue` with state `manual_review`. This is a durable placeholder only; it is not sent to RPA because the request-information endpoint/payload is not confirmed. `EmailRpa` remains in the codebase for its existing payload utility but is not used by the email intake runner.

Email attachment parts in the mailbox are not extracted or persisted. The CLOG file lookup is separate: it uses `CLOG_DB_*` settings and the CRM ID in the message body, and stores metadata/retrieval URLs only. No binary file is copied. The lookup does not verify that the remote URL currently returns a file. OCR and Cron2 are not part of the current worker. See [the ticket workflow foundation and remaining integration requirements](ticket-workflow.md).

## Separate status fields

`portal_tickets.workflow_status` is the operational ticket status. `cidb_interaction_data.clog_status` remains the CLOG-origin status. New CLOG interactions start with `current_status='Open'`; `Offered` is reserved for a confirmed information-request email and is not set by the current worker. Existing status values are preserved. `portal_requests.status` remains the RPA-owned result for the existing form flow. Email intake does not set this status to success or failed and does not send email cases to cancellation execution RPA. Ticket RPA intents are tracked individually in `portal_rpa_action_queue`; currently they remain `manual_review`.

The existing status-only result behavior for legacy form submissions is unchanged:

| Database status | UI label | Result polling |
|---|---|---|
| `processing` or `pending` | In progress | Continue awaiting RPA |
| `success` | Success | Stop |
| `failed` | Failed | Stop |

## Storage and activation

Apply the email migration and all [ticket workflow migrations](ticket-workflow.md) before running this worker. The email feature remains disabled by default; no schedule or live configuration is installed. `php bin/email-reader.php check` checks local configuration, including CLOG database settings, without connecting to IMAP or CLOG.

After reviewing the intended environment and obtaining approval for mailbox access:

1. Set `EMAIL_ENABLED=true`.
2. Run `php bin/email-reader.php activate` once to record the mailbox UID baseline; this contacts IMAP and writes database state but sends no customer or RPA messages.
3. Run `php bin/email-reader.php run` from a scheduler only after validating representative messages and confirming retention approval.

Activation excludes messages already in the folder. UID validity plus UID prevents duplicate intake. Restarts preserve the cursor; changed UID validity stops processing for operator review rather than resetting the baseline. Do not delete intake records or reset activation to recover a case.

The current installation requires the locked Composer dependencies and CLI extensions listed in `.env.example`. Secrets must remain in deployment configuration or protected untracked `.env` values, not source code or logs.
