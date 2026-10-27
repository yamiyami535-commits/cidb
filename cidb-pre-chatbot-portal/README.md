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

See `.env.example`: `APP_ENV`, `APP_URL`, `SESSION_SECURE_COOKIE`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_TIMEOUT`, `RPA_BOT_ENDPOINT`, `RPA_BOT_API_KEY`, `RPA_BOT_TIMEOUT_MS`, `RPA_BOT_CONNECT_TIMEOUT_MS`, `RPA_COMPANY`, `RPA_SCENARIO_KEY`, `RPA_CHANNEL`, `OCR_RPA_API_KEY`, `OCR_SERVICE_URL`, `OCR_CONNECT_TIMEOUT_MS`, `OCR_TIMEOUT_MS`, `OCR_MAX_FILE_SIZE_MB`, and `OCR_MAX_REQUEST_SIZE_MB`.

The portal's own environment values are read from `.env`; no chatbot environment file is loaded.

## Inbound OCR verification API

RPA can submit applicant fields and images to `POST /api/ocr/verify-document` using `multipart/form-data`. The portal authenticates the caller with `X-API-Key`, forwards the request to the private OCR service configured by `OCR_SERVICE_URL`, and returns the OCR outcome as JSON. It does not submit this API request to RPA. The existing authenticated portal form and its RPA flow are unchanged.

Supported multipart fields match the OCR service: `document_type` (`mykad` or `passport`), `entered_name`, `entered_nric` for MyKad or `entered_passport_number` for passport, plus `mykad_front` and `mykad_back` or `passport_image`. Outcomes such as `VERIFIED`, `MANUAL_REVIEW`, `DETAILS_MISMATCH`, and `REUPLOAD_REQUIRED` are returned in the JSON `status`; a successful HTTP response means OCR processed the request. Invalid requests retain the OCR service's 4xx status, and upstream timeouts or availability failures return 504/503.

Configure `OCR_RPA_API_KEY` as a long random secret shared with the RPA caller, and set `OCR_SERVICE_URL` to the private OCR endpoint. The local example uses loopback HTTP; deployed OCR service URLs must use HTTPS with certificate verification enabled. Publish the portal endpoint only through HTTPS ingress, restrict inbound access to the RPA network where possible, and configure PHP `upload_max_filesize` to at least 8M, `post_max_size` to at least 17M, and PHP/web-server request timeouts to exceed `OCR_TIMEOUT_MS` (180 seconds by default). The timeout should allow for PaddleOCR cold start/model loading.

For the hosted Linux OCR VM deployment, systemd unit and Nginx HTTPS proxy templates, install steps, network requirements, and verification checks are in the repository's [`ocr/deploy` guide](../ocr/deploy/README.md). The templates require infrastructure-provided paths, private DNS, and certificate details; the portal hosting configuration is not stored in this repository.

The portal records only the outcome status, document type, masked document number, and timestamp in `portal_ocr_verifications`. It does not retain upload bytes, full extracted text, processed images, or the raw OCR response. For an existing database, apply `database/migrations/20261019_ocr_verifications.sql`; fresh databases created from `database/schema.sql` include it automatically. The OCR verification thresholds remain configured in the OCR service environment (`MIN_BLUR_SCORE`, `MIN_BRIGHTNESS`, `MAX_BRIGHTNESS`, `MIN_OCR_CONFIDENCE`, `MIN_NAME_SIMILARITY`, and `MANUAL_REVIEW_NAME_SCORE`).

`RPA_COMPANY` and `RPA_SCENARIO_KEY` configure form payloads only. Email payloads use fixed `company: "CIDB"`, `scenario_key: "cidb_masterbot"`, and `channel: "Email"`. Both paths share the RPA endpoint, API key and timeouts. `RPA_CHANNEL` is currently unused; both paths hardcode the channel to `Email`.

## RPA result polling

The submission response is saved in `rpa_response` / `rpa_response_text` for diagnostics only. New rows start with `status='processing'`; the backend does not subsequently assign the business status. RPA updates that same row's `status` to `success` or `failed`. Both form and email requests show **In progress** until that update, then **Success** or **Failed**. The legacy `rpa_display_message` column is no longer read, written or displayed by result handling.

The home, history and details pages start polling immediately for each displayed request whose result is incomplete, then repeat three seconds after each successful check. Request IDs come from the existing records rendered on the page, so polling can occur without a new submission. This applies to both form history and shared email history; it does not start the email worker or submit requests to RPA. Every lookup requires authentication: users can see their own form requests and shared email requests. Polling retries temporary errors with a delay up to 30 seconds and stops on a final RPA status or loss of access. Local email cases that were not submitted display their separate intake error and stop polling when review is needed or retries are exhausted. Uncertain submissions keep checking for a possible RPA update. Raw HTTP data is retained in the database for diagnostics, is not displayed on the request detail page, and cannot determine the displayed business status.

Request History stays at `/request-history`: page numbers, Previous/Next, and All/Form/Email filters update the list in place without navigation. Background requests use the existing server-side pagination with at most 15 requests per page. Each filter counts and paginates its own authorized result set, ordered by `created_at DESC, id`. Switching filters starts on page 1; pagination controls appear below the list only when needed. Status polling stops for removed rows and starts for newly displayed rows. Failed loads leave the current list intact. Invalid page values default to page 1 and out-of-range pages use the last page. Query links remain as a fallback when JavaScript is unavailable. Detail pages retain customer fields and live status but do not render raw RPA payloads/responses, submission attempts, HTTP/reference values, or internal error diagnostics. Stored processing data is unchanged.

Run the regression checks with `php tests/request-result.php` and `node tests/polling.cjs` (Node is only needed for the frontend test). These checks use fixtures and do not submit live RPA requests.

## Email reader (opt-in CLI worker)

The email module has its own extraction, nested RPA payload, durable processing records, and shared history. The visible `CIDB` mailbox folder has the IMAP path `INBOX.CIDB`; explicitly set `EMAIL_FOLDER=INBOX.CIDB`, as in `.env.example`. The code fallback when this setting is omitted is still `CIDB`, which is not the confirmed path for this mailbox. With `EMAIL_SELECTION_MODE=all`, all senders' post-activation arrivals in this folder are eligible. Attachments and OCR are excluded.

`.env.example` disables processing with `EMAIL_ENABLED=false`. The local development `.env` reviewed on 28 September 2026 has `EMAIL_ENABLED=true`; this is not a deployment default or proof that a scheduler is running. Processing requires both enabling the feature and a recorded mailbox baseline (`php bin/email-reader.php activate`, once), followed by manual or scheduled `php bin/email-reader.php run` invocations. Activation excludes messages already in the folder and does not dispatch requests. The worker does not start from a web request.

### Extraction and RPA payload

| Email body label | Key inside `fields` | Requirement |
|---|---|---|
| `Name:` | `sCustomerName` | Required |
| `Email to Cancel ID:` | `sEmail` | Required; cleaned and validated |
| `NRIC:` | `sIdentificationNumber` | Required |
| `CRM:`, `CRM ID:`, `CRMID:`, or `sCRMID:` | `sCRMID` | Required |
| `State:` | `sLocationArea` | Optional; omitted if absent or blank |
| Fixed value `Individual` | `sCustomerType` | Always included |

Email cleanup removes mail-link suffixes and surrounding text, trims whitespace, and lowercases the domain while preserving the local part. Invalid or ambiguous addresses require review and are not submitted. The same cleaned address is stored and used in the payload. CRM remains a string, preserving leading zeros, case and punctuation after trimming. Missing required fields or ambiguous extraction prevent dispatch; omitting State alone does not.

Newly extracted requests use this structure (customer values below are illustrative, not hardcoded):

```json
{
  "company": "CIDB",
  "scenario_key": "cidb_masterbot",
  "channel": "Email",
  "fields": {
    "sCRMID": "CRM-EXAMPLE-001",
    "sEmail": "applicant@example.com",
    "sCustomerName": "Example Applicant",
    "sCustomerType": "Individual",
    "sLocationArea": "Selangor",
    "sIdentificationNumber": "EXAMPLE-ID-001"
  }
}
```

All customer fields are inside `fields`; there is no email `sChannel` field. The API key is sent in the `X-API-Key` header, not the JSON body. Previously stored payloads are not rewritten by code updates: retries reuse the original stored payload, which may have an older structure. Do not reset or blindly resubmit uncertain requests to adopt the new format.

TL notification dispatch, templates and retry requirements are temporarily commented out; the TL address is a placeholder. Unexpected incomplete cases remain recorded without sending to RPA.

Read [the email operations and configuration guide](docs/email-reader.md) before configuring or activating it. Representative extraction samples, RPA row correlation, retention, and production-runtime verification remain launch requirements. TL settings are not currently required. `php bin/email-reader.php check` performs local readiness checks only; `run` with email disabled opens no connections.

Existing databases need `database/migrations/20260925_email_reader.sql` after the CRM compatibility migration. Fresh `database/schema.sql` includes it using a psql-relative include. No migration is automatically applied by the application. The form history continues to work before the email migration is applied.

See [the exact migration, tables, columns, constraints and manual commands](docs/email-migration.md). If the email migration is already applied, the status-only change requires no additional SQL.
