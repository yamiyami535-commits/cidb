# Cron1 and Cron2 operating notes

## Normal scheduled runs

- Cron1: `php bin/cron_runner.php`
- Cron2: `php bin/cron_runner.php --cron2`
- Normal runs use the current day in `CLOG_TIMEZONE` (default `Asia/Kuala_Lumpur`) and do not import yesterday's backlog.
- Cron1 accepts only CLOG-confirmed Individual ID cancellation cases. It excludes company cases and agent `ReplyTo Mail` rows. CLOG customer attachments are found through `emails.uniqid` -> `emails.id` -> `files.email_id`.
- CLOG attachments on `New Email` and `Reply Mail` messages are customer documents. `ReplyTo Mail` attachments are agent-sent thread material and are not OCR inputs.
- If a new case has no customer attachment, Cron1 inserts the `Pending Customer Attachment` request directly into RPA Hub.
- Cron2 looks for customer documents on today's thread, including CLOG rows marked Closed, and runs OCR in the same invocation when OCR is enabled.
- `Email Cancel` is inserted directly into RPA Hub only after at least one customer document is ready and OCR has verified identity.

## Explicit historical repair

Scope every historical repair to named CRM IDs. For example:

```powershell
php bin/cron_runner.php --crm-id=CRM-... --backlog --skip-rpa-dispatch
php bin/cron_runner.php --crm-id=CRM-... --cron2 --backlog
```

The first command syncs ticket/thread/customer attachment references without creating an RPA scheduler row. The second command refreshes historical customer attachments and runs OCR; only use it when OCR processing is intended. Do not use `--backlog` for routine schedules.

## RPA Bot_Input

The Hub row uses exactly these keys, in order: `sCRMID`, `sEmail`, `sCustomerName`, `sIdentificationNumber`, `sContactNumber`, `sLanguage`, `sChannel`, `sRequest`, `sCustomerType`. The Hub connection is configured with `RPA_HUB_ENV_FILE` or the `RPA_HUB_DB_*` variables. Do not store credentials in this repository.

## Deployment notes

Apply `database/migrations/20261105_cleanup_ocr_ticket_statuses.sql` to an existing portal database after the earlier ticket/OCR migrations. No deployment target or production host is configured in this repository; deploy using the approved infrastructure runbook.
