# CIDB overall system process

Reviewed against the source on 13 November 2026. The application is a standalone PHP/PostgreSQL portal for request submission and cancellation ticket handling. It integrates with CLOG MySQL, a Python OCR service, and RPA. The flows below describe implemented behavior; they do not establish that the integrations are running in production.

| Component | Responsibility |
| --- | --- |
| PHP portal and PostgreSQL | Login, form requests, ticket/conversation records, attachment references, OCR/action ledgers, and staff views. |
| CLOG MySQL and file endpoint | Source CRM/customer data, emails, activity/history, and customer attachment files. |
| Python FastAPI / PaddleOCR | Extract and return document identity, assess image blur, and validate cross-outs/signatures. |
| RPA API | Receive requests from the existing portal submission form. |
| RPA Hub MySQL | Receive scheduled Individual cancellation and missing-attachment requests through direct database insertion. |
| Optional IMAP reader | Record new qualifying mailbox messages as email requests and tickets. |

**1. Portal form submission and result display**

The user signs in, enters Name, ID Number, Email, CRM and Language, and submits the form. The backend validates required fields, email, session/CSRF protection, and a submission key used to prevent repeat submissions. It inserts a `portal_requests` row with `status='processing'`, sends the payload through `RpaClient` to the configured RPA API, and saves transport diagnostics.

RPA owns the final `portal_requests.status`: `success` or `failed`. An HTTP acceptance response does not make the request successful. The home, history and detail screens poll `/request-status/{id}` immediately and then every three seconds after successful checks; temporary errors increase the delay up to 30 seconds. Polling stops on completion or loss of access. Users can read their own form requests and shared email request records. Request history supports All/Form/Email filtering and pagination.

This form path does not create a cancellation ticket or automatically invoke the ticket OCR pipeline. Email cancellation tickets do not currently use the same form-result completion lifecycle.

**2. Scheduled CLOG cancellation intake**

Cron1 runs `bin/cron_runner.php`. It reads today's Open CLOG emails in `CLOG_TIMEZONE` and identifies CLOG-confirmed Individual ID cancellations. Company cases and agent `ReplyTo Mail` messages are excluded from customer intake. Explicit non-cancellation intent can override a stale cancellation category.

The worker stores CRM/interaction details, portal tickets, conversation messages, source activity/history, and attachment metadata. Files are joined using `emails.uniqid` (CRM) → `emails.id` → `files.email_id`. File bytes remain at the CLOG file endpoint.

- With customer documents, the ticket can enter `ready_for_ocr`.
- Without customer documents, it enters `needs_information`. When no attachment is found elsewhere in the relevant thread/portal checks, Cron1 can insert `Pending Customer Attachment` directly into RPA Hub. It also attempts informational CLOG email tagging. Hub insertion is not evidence that a customer email was delivered.

Repeated source records and CRM/request Hub submissions are deduplicated. Normal runs do not sweep historical backlog. See [CLOG sync](clog-sync.md) for source mapping and scoped repair options.

**3. Customer reply, attachment recheck, and OCR**

Cron2 runs `bin/cron_runner.php --cron2`. It rechecks eligible Individual tickets in `needs_information`, collects today's customer attachments, and promotes qualifying replies to `ready_for_ocr`. It may collect customer replies marked Closed in CLOG, while the linked ticket's effective CLOG status must still be Open. Agent attachments remain outside customer OCR inputs.

Cron2 then starts `ocr_runner.php` in the same invocation. With OCR enabled, the worker queues eligible documents, downloads them, and calls the private OCR service. The service preserves input handling, PDF rendering, PaddleOCR and identity/image-quality checks. Automatic approval additionally requires a visible cross-out and customer-added signature across the accepted pages; CIDB wording is optional. Missing visual assets, uncertain evidence or processing errors prevent approval. See [visual validation](../ocr/VISUAL_VALIDATION.md). Stamps are not checked.

Document results are saved in `portal_ticket_documents`; jobs and completion events provide processing history. `ocr_status='manual_review'` can represent either confirmed or unconfirmed identity. The separate `ocr_result.identity_check_verified` flag controls eligibility. Technical failures have delayed retries; completed negative identity results need investigation or replacement. See [OCR process](ocr-process-flow.md) for routing, extraction rules, and retry limits.

**4. Verified cancellation handoff**

The automatic path requires an eligible Individual cancellation, name/NRIC, at least one ready customer document, and verified identity for every ready customer document. The worker inserts `Email Cancel` directly into RPA Hub MySQL using the exact nine-field payload documented in [ticket workflow](ticket-workflow.md), BotID `118`, and runner `BR20`.

After a successful insertion or recognized duplicate, the interaction becomes `Customer Replied - Verified` and the ticket becomes `ready_for_execution`. Eligible verified cases whose Hub insertion failed can be revisited by a later OCR worker run. The ordinary automatic path does not require a separate CLI manual approval.

This is the boundary of the implemented automated ticket handoff. A Hub scheduler row or action marked `succeeded` means submission was recorded; it does not prove the bot completed cancellation. These workers do not reconcile downstream Hub completion into ticket `completed`/`failed`. Check the downstream RPA outcome separately.

**5. Optional mailbox intake**

The separate `bin/email-reader.php` worker uses `EMAIL_ENABLED` and a one-time mailbox activation baseline. It discovers later message UIDs, filters cancellation messages, extracts supported labeled fields, and stores the message, shared email request, and ticket. It looks up CLOG file references when a valid CRM is available; it does not persist MIME attachment bytes. Successfully recorded messages are marked read where possible.

Unclassified/incomplete tickets remain in review or `needs_information`. The reader itself sends neither cancellation requests nor SMTP messages. Missing-information action intents are stored as `manual_review` by default, or `queued` when `RPA_WORKFLOW_ACTIONS_ENABLED=true`; the separate action worker applies further gates before dispatch. Mailbox intake is not a replacement for the scheduled CLOG path. See [email reader](email-reader.md) with the corrections in the [documentation index](README.md).

**6. Staff review and supporting integrations**

Active `admin`, `manager`, and `agent` users can inspect `/tickets`, exact CRM searches, ticket conversations, documents/OCR jobs, and activity/audit history. The workspace is read-only; opening a page does not run OCR, send replies, assign work, or execute RPA. Identity fields are masked in the ticket workspace. See [workspace](ticket-workspace.md).

A separate CLI supports classification, approval, and rejection using an active admin/manager reviewer. Approval can create a held or queued cancellation action, and `rpa_action_runner.php` dispatches eligible queued actions when enabled. See [manual review process](manual-review-process-flow.md) for this alternate route and its limitations.

The portal also exposes API-key-protected `POST /api/ocr/verify-document`. It validates MyKad/passport image requests and forwards them to the Python service. This standalone verification endpoint returns a response; it does not persist ticket OCR results or trigger ticket cancellation. Its inbound `OCR_RPA_API_KEY` is separate from the `OCR_API_KEY` used when calling Python.

**Status interpretation**

| Field | Meaning |
| --- | --- |
| `portal_requests.status` | Form/legacy request business outcome owned by RPA after insertion. |
| `portal_tickets.workflow_status` | Ticket work stage, such as `needs_information`, `ready_for_ocr`, or `ready_for_execution`. |
| `clog_status` | Mirrored source case state. |
| `cidb_interaction_data.current_status` | Recorded CRM interaction/customer-processing state. |
| Document `reference_status` / `ocr_status` | Attachment reference validity / OCR display result. |
| OCR job or RPA action `state` | Processing or submission state of that individual job/action. |

**Operating sequence and current boundaries**

For the supplied Windows setup, one `run-cron-sequence.ps1` task runs Cron1 and then Cron2, prevents overlapping wrapper runs, and logs results. Cron1 failure stops the sequence before Cron2. Do not combine this with a duplicate Cron2 schedule. The wrapper does not run the optional mailbox reader or separate RPA action worker. Follow [Windows setup](windows-server-cron-setup.md) and the migration order in [ticket workflow](ticket-workflow.md).

OCR enablement, mailbox enablement, and the separate action-runner flag control different paths. In particular, `RPA_WORKFLOW_ACTIONS_ENABLED` does not disable direct Hub insertion by CLOG Cron1 or the OCR worker. Missing services/configuration, unconfirmed identity, and unsupported cases remain operational follow-up items; supported database status names alone do not establish an implemented transition or successful outcome.

Source references: [HTTP/form routes](../public/index.php), [result handling](../src/request-result.php), [Cron1](../bin/cron_runner.php), [Cron2](../bin/cron2_recheck.php), [OCR worker](../bin/ocr_runner.php), [direct Hub insertion](../src/RpaHubDirectInserter.php), [mailbox processor](../src/Email/Processor.php), [mailbox storage](../src/Email/PgStore.php), and [OCR API proxy](../src/OcrVerificationClient.php).
