# Portal OCR testing and lightweight RPA results

## Portal testing

Sign in as an active **admin or manager**, then use **OCR Testing** in the portal header or open `/ocr-testing`. The server rechecks account activity and role on each request. Agents and anonymous users cannot access the page or upload route. POST `/ocr-testing/run` requires the session CSRF token.

The page uses the same OCR application and decision code as production. It neither creates OCR jobs/tickets nor stores verification results nor sends RPA notifications, even when production result delivery is enabled. Uploads use PHP's normal temporary upload lifecycle; the Python service processes images in memory. Results and previews remain in the browser until cleared or the page is left. The clear button removes document selections and displayed identity. Responses use `Cache-Control: no-store`; identity and image contents are not added to application logs.

| Mode | Python route | Upload fields |
| --- | --- | --- |
| MyKad front/back | `/api/verify-document` | `document_type=mykad`, `mykad_front`, `mykad_back` |
| Passport image | `/api/verify-document` | `document_type=passport`, `passport_image` |
| MyKad image | `/api/verify-mykad-image` | `image_file` |
| Cancellation form PDF | `/api/verify-cancellation-form-pdf` | `document_pdf`, exactly one page |
| MyKad PDF | `/api/verify-mykad-pdf` | `document_pdf`, one or two pages |

PDF routing is explicit. The existing CLOG worker routing is unchanged. More than two pages and passport PDFs remain unsupported. The portal enforces detected MIME types and configurable upload limits; Python retains its existing decoding, quality and page-count validation. Configure PHP `upload_max_filesize`, `post_max_size`, proxy body-size limits and timeouts to accommodate the portal settings (default 8 MB/image, 16 MB/PDF, 17 MB/request).

`OCR_TEST_SERVICE_URL` and `OCR_TEST_API_KEY` optionally select a separate deployment of the same OCR code/model package. Blank values fall back to `OCR_SERVICE_URL` and `OCR_API_KEY`. A separate instance avoids test inference competing with production requests; sharing an instance does share CPU and inference capacity. Keys are sent only by the PHP backend, never rendered into the page.

## Shared diagnostics

The testing proxy sends `X-OCR-Diagnostics: 1` to the existing private endpoints. API-key authentication remains in force. An optional `diagnostics` response member contains:

- Identity extraction, CIDB wording, image quality, document recognition and applicable OCR-confidence outcomes.
- Cross-out and customer-signature evidence, original-page previews and candidate boxes/scores.
- Embedded-signature detector evidence, separately from expected embedded-signature regions projected by reference matching.
- Page coverage, signature exclusion reasons, model release/evaluation identity and processing duration.
- Original PaddleOCR text/confidence by page and the unchanged overall outcome.

Default requests omit `diagnostics`. Observation is request-local and does not change thresholds, rerun OCR/inference, or add an approval rule. `present`, `absent`, `uncertain`, `error`, and `not_checked` are distinct. Earlier quality/extraction failures can skip visual inspection; the page explicitly shows that it was not checked. CIDB wording is an informational search for the word `CIDB` in readable **original** OCR text, not generated watermark text. A missing text match is not proof that no wording exists. Reference regions alone do not prove a visible embedded signature.

Diagnostics are not an accuracy certification. **The repository still lacks validated visual weights/reference assets.** The default ONNX backend requires `VISUAL_MANIFEST_PATH`; missing assets produce an unverified error. For model-free cross-out evaluation, explicitly select `VISUAL_BACKEND=opencv_crossout` as described in [stage-one testing](../ocr/OPENCV_CROSS_OUT.md). It displays stroke evidence and reasons, reports signature checks as not checked and always withholds overall approval. `/health` does not certify visual readiness or accuracy. Evaluate approved real samples before relying on detector results.

## Lightweight RPA contract

The agreed contract is a separate HTTP POST to the **existing** `RPA_BOT_ENDPOINT`, authenticated using `RPA_BOT_API_KEY`. It does not use the legacy form/workflow envelope, scheduler ID normalization, or direct MySQL Hub insertion.

```json
{
  "extracted_name": "SAMPLE CUSTOMER",
  "extracted_document_number": "900101-10-1234",
  "verification_status": "VERIFIED"
}
```

Only these three JSON fields are sent. Missing identity is `null`; existing stored/customer-entered identity is never substituted. `verified=true` maps to `VERIFIED`, even when the original status is `MANUAL_REVIEW`. Unverified `OCR_FAILED`/`INTERNAL_ERROR` map to `ERROR`; other completed negative outcomes map to `NOT_VERIFIED`. This mapping is exclusive to the outgoing payload: database statuses and OCR responses are unchanged.

Headers:

- `Content-Type: application/json`
- `X-API-Key: <existing RPA key>`
- `X-OCR-Result-Type: identity-extraction-v1`
- `X-Correlation-ID: <opaque submission/job identifier>`
- `Idempotency-Key: ocr-<SHA256(source:correlation)>`

For inbound API submissions, the caller should supply a stable `X-Correlation-ID` for retries of the same submission, and a new identifier for a new submission. Allowed characters are letters, digits, `.`, `_`, `:`, `-`, maximum 128 characters. Missing/invalid identifiers are replaced with a generated UUID; in that case retries cannot be associated with the original request automatically. Cron2 uses the existing OCR job ID. Correlation identifiers should not contain identity details. The receiver must enforce idempotency; the sender does not maintain a deduplication database.

## Enabling delivery

```env
# Existing HTTP integration configuration
RPA_BOT_ENDPOINT=https://your-existing-rpa-endpoint
RPA_BOT_API_KEY=<existing key>

# New notification switches (disabled by default)
OCR_RPA_RESULT_ENABLED=true
OCR_RPA_RESULT_API_ENABLED=true
OCR_RPA_RESULT_WORKER_ENABLED=true
OCR_RPA_RESULT_TIMEOUT_MS=5000
```

The result-type header must route this notification as an extraction result on the RPA side, not a new cancellation request. Production enables are explicit; no live `.env` is changed by this implementation. Both entry paths are wired and independently switchable. Direct callers of Python bypass the PHP notification hooks.

The new call verifies HTTPS certificates (loopback HTTP is supported for local integration tests). Existing `RpaClient::send()` transport defaults and legacy payload builders remain unchanged. The receiver acknowledges receipt with HTTP 2xx; this does not imply cancellation success.

Delivery is one bounded synchronous attempt (default 5 seconds, maximum 30). The inbound API may therefore take that much longer to respond when enabled. Cron2 invokes it **after** the existing result transaction commits. Network, HTTP and configuration failures are logged without identity payloads, never change verification/status storage, never rerun OCR, and never block the existing Hub handoff through an exception. There is no durable delivery queue or automatic retry. Guaranteed delivery would require a separate outbox/acknowledgement design; it must not reuse OCR failure or verification-status fields.

## Files and validation

Portal routes: `public/index.php`, `src/ocr-testing-routes.php`; access: `src/OcrTestingAccess.php`; shared upload transport: `src/OcrVerificationClient.php`; UI: `views/ocr-testing.php`, `views/layout.php`, `public/assets/ocr-testing.*`.

Shared observations: `ocr/app/diagnostics.py`, `main.py`, `models.py`, `document_validation.py`, `visual_validation.py`. Result delivery: `src/OcrResultDelivery.php`, `src/RpaClient.php`, completion hooks in `public/index.php` and `bin/ocr_runner.php`.

Run Python tests from `ocr/` with the isolated test dependencies: `python -m unittest discover -s tests -v`. Run `php tests/ocr-result-delivery-unit.php`, `php tests/ocr-extraction-client-unit.php` and `node tests/ocr-testing-http.cjs --browser` from the repository root. The HTTP/browser suite uses a temporary application copy, synthetic documents, a read-only authentication fixture and local OCR/RPA mock servers; it never loads the real environment or contacts production. Chrome can be selected with `CHROME_BINARY` (omit `--browser` for HTTP-only checks). Python tests cover the real extraction/validation logic and synthetic visual adapters; these are not real-document accuracy results.
