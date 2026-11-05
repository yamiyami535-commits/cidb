# CIDB Pre-Chatbot Portal

Independent PHP 8.2 / PostgreSQL application. It does not load or depend on the chatbot's PHP classes, routes, sessions, or database tables.

## Requirements

- PHP 8.2+ with `pdo_pgsql` and `curl`
- PostgreSQL 13+
- Composer for the optional email module (Webklex PHP IMAP and PHPMailer)
- Email CLI extensions: `openssl`, `mbstring`, `iconv`, `libxml`, `dom`, `zip`, `fileinfo`, `curl`, `pdo_pgsql`

## Run

1. `cd cidb-pre-chatbot-portal`
2. `composer install` (required before using the email module)
3. Copy `.env.example` to `.env` and configure PostgreSQL, CLOG MySQL, and RPA Hub MySQL credentials. Keep credentials out of tracked files.
4. Create the database and run `psql -d cidb_portal -f database/schema.sql`.
5. Create the first login: `php bin/create-user.php USERNAME EMAIL 'a-long-password-here'` (password must be at least 12 characters).
6. Start: `php -S 127.0.0.1:8080 -t public public/index.php`
7. Open <http://127.0.0.1:8080/login>.

Use HTTPS and set `SESSION_SECURE_COOKIE=true` in a deployed environment. Provision users with the CLI script; no public registration is exposed.

For a database used while CRM was removed, apply `psql -d cidb_portal -f database/migrations/20260924_restore_crm_requirement.sql` before deploying this version. CRM is required for all new portal submissions. Existing requests without CRM retain their original values; the legacy column remains nullable when needed to preserve those records. New databases created from `schema.sql` do not need this migration.

## Environment

See `.env.example` for portal, CLOG, OCR, and RPA Hub settings. `RPA_HUB_ENV_FILE` can point to the RPA Hub `.env`; otherwise use the `RPA_HUB_DB_*` settings. Do not commit `.env` files.

The portal's own environment values are read from `.env`; no chatbot environment file is loaded.

The CLOG to portal sync runs with `php bin/cron_runner.php`. Normal runs read only Open CLOG emails dated today in `CLOG_TIMEZONE` (Asia/Kuala_Lumpur by default). Use `--backlog` only for a scoped, explicitly requested historical reprocess. `--skip-rpa-dispatch` can be combined with a scoped `--crm-id` to repair portal records without creating Hub scheduler rows. The source mapping is documented in [the CLOG sync guide](docs/clog-sync.md).

The cancellation workflow inserts directly into the RPA Hub MySQL database; it does not use the RPA API. Bot_Input uses the exact nine fields documented in [the ticket workflow guide](docs/ticket-workflow.md). Email Cancel insertion requires ready customer attachments and verified OCR identity.

## RPA result polling

The submission response is saved in `rpa_response` / `rpa_response_text` for diagnostics only. New form rows start with `status='processing'`; the backend does not subsequently assign the business status. RPA updates that same row's `status` to `success` or `failed`. The legacy `rpa_display_message` column is no longer read, written or displayed by result handling. Email cancellations are staged as portal tickets and do not use this RPA result lifecycle yet.

The home, history and details pages start polling immediately for each displayed form request whose result is incomplete, then repeat three seconds after each successful check. Request IDs come from the existing records rendered on the page, so polling can occur without a new submission. This does not start the email worker or submit requests to RPA. Every lookup requires authentication: users can see their own form requests and shared email requests. Polling retries temporary errors with a delay up to 30 seconds and stops on a final RPA status or loss of access. Raw HTTP data is retained in the database for diagnostics, is not displayed on the request detail page, and cannot determine the displayed business status.

Request History stays at `/request-history`: page numbers, Previous/Next, and All/Form/Email filters update the list in place without navigation. Background requests use the existing server-side pagination with at most 15 requests per page. Each filter counts and paginates its own authorized result set, ordered by `created_at DESC, id`. Switching filters starts on page 1; pagination controls appear below the list only when needed. Status polling stops for removed rows and starts for newly displayed rows. Failed loads leave the current list intact. Invalid page values default to page 1 and out-of-range pages use the last page. Query links remain as a fallback when JavaScript is unavailable. Detail pages retain customer fields and live status but do not render raw RPA payloads/responses, submission attempts, HTTP/reference values, or internal error diagnostics. Stored processing data is unchanged.

Run the regression checks with `php tests/request-result.php` and `node tests/polling.cjs` (Node is only needed for the frontend test). These checks use fixtures and do not submit live RPA requests.

## Cancellation intake and ticket workflow

Cron1 runs with `php bin/cron_runner.php`; Cron2 runs with `php bin/cron_runner.php --cron2`. Cron1 defaults to today-only, Open, Individual ID-cancellation intake. Companies and agent `ReplyTo Mail` messages are excluded. When no customer attachment exists, Cron1 can send the `Pending Customer Attachment` request through direct Hub insertion. Cron2 checks same-day customer attachments (including Closed customer reply rows), runs OCR automatically when enabled, and sends `Email Cancel` directly to Hub only when identity is verified and a ready customer document exists. Agent reply attachments are retained as thread history but are not OCR inputs.

For existing databases, apply the migrations in order through `20261027_ocr_manual_review.sql`, then apply `20261105_cleanup_ocr_ticket_statuses.sql`. These migrations are not run automatically. Fresh `database/schema.sql` includes them. Configure and verify the private OCR service and API key before setting `OCR_ENABLED=true`. Read [the ticket workflow guide](docs/ticket-workflow.md) and [the CLOG sync guide](docs/clog-sync.md) before scheduling the workers.

The OCR worker extracts name, NRIC, and address where readable, and rejects blurred images. Document OCR state is stored in `portal_ticket_documents.ocr_status`; OCR does not create extra ticket workflow statuses. A verified identity can proceed automatically to RPA. The pipeline does not validate CIDB cross-outs, signatures, or company stamps. Ticket-management UI and role-enforced assignment APIs remain separate work.

For the controlled Cron2/OCR procedure and expected database results, see the [OCR internal test checklist](docs/ocr-internal-test.md).
