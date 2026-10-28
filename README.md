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
3. Copy `.env.example` to `.env`, set database credentials, RPA endpoint and API key. Confirm the CRM and Email RPA field mappings with the RPA owner before production use.
4. Create the database and run `psql -d cidb_portal -f database/schema.sql`.
5. Create the first login: `php bin/create-user.php USERNAME EMAIL 'a-long-password-here'` (password must be at least 12 characters).
6. Start: `php -S 127.0.0.1:8080 -t public public/index.php`
7. Open <http://127.0.0.1:8080/login>.

Use HTTPS and set `SESSION_SECURE_COOKIE=true` in a deployed environment. Provision users with the CLI script; no public registration is exposed.

For a database used while CRM was removed, apply `psql -d cidb_portal -f database/migrations/20260924_restore_crm_requirement.sql` before deploying this version. CRM is required for all new portal submissions. Existing requests without CRM retain their original values; the legacy column remains nullable when needed to preserve those records. New databases created from `schema.sql` do not need this migration.

## Environment

See `.env.example`: `APP_ENV`, `APP_URL`, `SESSION_SECURE_COOKIE`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_TIMEOUT`, `CLOG_DB_HOST`, `CLOG_DB_DATABASE`, `CLOG_DB_USERNAME`, `CLOG_DB_PASSWORD`, `CLOG_DB_TIMEOUT`, `CLOG_TIMEZONE`, `CLOG_INITIAL_SYNC_DAYS`, `CLOG_FILE_FETCH_BASE_URL`, `RPA_BOT_ENDPOINT`, `RPA_BOT_API_KEY`, `RPA_BOT_TIMEOUT_MS`, `RPA_BOT_CONNECT_TIMEOUT_MS`, `RPA_COMPANY`, `RPA_SCENARIO_KEY`, and `RPA_CHANNEL`.

The portal's own environment values are read from `.env`; no chatbot environment file is loaded.

The optional MySQL CLOG to PostgreSQL sync runs with `php bin/cron_runner.php`. Configure its source credentials separately from the portal's PostgreSQL `DB_*` settings. The source-to-target mapping and tables used are documented in [the CLOG sync guide](docs/clog-sync.md).

`RPA_COMPANY`, `RPA_SCENARIO_KEY`, and the RPA endpoint configure the existing form-submission path. Email cancellation intake no longer dispatches cancellation execution RPA from Cron1; see [the ticket workflow foundation](docs/ticket-workflow.md).

## RPA result polling

The submission response is saved in `rpa_response` / `rpa_response_text` for diagnostics only. New form rows start with `status='processing'`; the backend does not subsequently assign the business status. RPA updates that same row's `status` to `success` or `failed`. The legacy `rpa_display_message` column is no longer read, written or displayed by result handling. Email cancellations are staged as portal tickets and do not use this RPA result lifecycle yet.

The home, history and details pages start polling immediately for each displayed form request whose result is incomplete, then repeat three seconds after each successful check. Request IDs come from the existing records rendered on the page, so polling can occur without a new submission. This does not start the email worker or submit requests to RPA. Every lookup requires authentication: users can see their own form requests and shared email requests. Polling retries temporary errors with a delay up to 30 seconds and stops on a final RPA status or loss of access. Raw HTTP data is retained in the database for diagnostics, is not displayed on the request detail page, and cannot determine the displayed business status.

Request History stays at `/request-history`: page numbers, Previous/Next, and All/Form/Email filters update the list in place without navigation. Background requests use the existing server-side pagination with at most 15 requests per page. Each filter counts and paginates its own authorized result set, ordered by `created_at DESC, id`. Switching filters starts on page 1; pagination controls appear below the list only when needed. Status polling stops for removed rows and starts for newly displayed rows. Failed loads leave the current list intact. Invalid page values default to page 1 and out-of-range pages use the last page. Query links remain as a fallback when JavaScript is unavailable. Detail pages retain customer fields and live status but do not render raw RPA payloads/responses, submission attempts, HTTP/reference values, or internal error diagnostics. Stored processing data is unchanged.

Run the regression checks with `php tests/request-result.php` and `node tests/polling.cjs` (Node is only needed for the frontend test). These checks use fixtures and do not submit live RPA requests.

## Cancellation intake and ticket workflow

The optional CLOG sync runs with `php bin/cron_runner.php`; the email cancellation intake runs with `php bin/email-reader.php`. Both create durable ticket records rather than executing a cancellation in Cron1. The email worker stages missing-information RPA intents for later contract review, and no customer/RPA messages are sent. `bin/ocr_runner.php` is an opt-in OCR-only Cron2 worker; it processes assigned, Open tickets marked `ready_for_ocr` and always leaves the result for manual review. It does not send email or call RPA.

For existing databases, apply `20260925_email_reader.sql` first if it has not already been applied, then `20261020_ticket_workflow.sql`, `20261021_ticket_attachment_lookup.sql`, `20261022_clog_open_status.sql`, `20261023_ticket_conversation_foundation.sql`, and `20261027_ocr_manual_review.sql` in order. These migrations are not run automatically. Fresh `database/schema.sql` includes them. Configure `OCR_SERVICE_URL` and `OCR_API_KEY`, verify the service, and only then set `OCR_ENABLED=true`. Read [the ticket workflow guide](docs/ticket-workflow.md), [the CLOG sync guide](docs/clog-sync.md), and [the email intake guide](docs/email-reader.md) before enabling either cron.

The ticket foundation provides required scenario tracking, scenario-specific buckets, PIC references, separate workflow status, source/customer metadata, document/OCR result storage, and a staged RPA action queue. The OCR worker expects a mapped customer name and NRIC and supports one two-page PDF or clearly named front/back image pair. It does not yet check CIDB-use cross-outs, company stamps, or signatures, and does not provide ticket-management UI or role-enforced assignment APIs. RPA action payloads and final cancellation execution remain pending.

For the controlled Cron2/OCR procedure and expected database results, see the [OCR internal test checklist](docs/ocr-internal-test.md).
