# CIDB OCR service

The OCR service is called through the existing private API. PaddleOCR extracts identity text and the existing blur/document checks remain in place. Automatic approval additionally requires a visible cross-out (CIDB wording optional), a customer-added signature, and an OCR-supported name and identification number. Company stamps are not checked.

Identity is extracted from the submitted document, without comparison to customer-provided values. The same HTTP response returns `extracted_name` and the full `extracted_document_number` alongside `verified`, `status`, and `message`. These existing response fields are populated wherever extraction succeeds, including when visual verification fails. Missing values are omitted using the existing response serialization. There is no new outbound RPA API call. The portal's `/api/ocr/verify-document` proxy passes this response through unchanged.

Legacy `entered_name`, `entered_nric`, and `entered_passport_number` fields remain accepted but are optional and ignored. The optional `comparison` result is no longer populated. Extraction uses labelled fields, MyKad number/name order, and passport MRZ evidence; conflicting candidates are withheld. Unusual layouts and OCR mistakes still require real-document evaluation; an extracted value is not a guarantee of accuracy.

The worker stores each document result in `portal_ticket_documents.ocr_status`. A complete verification can be recorded as `manual_review` for the frontend with `identity_check_verified=true`; that boolean controls the automatic RPA gate. Missing, uncertain or failed visual checks never set it true. No additional ticket workflow statuses are created.

**Visual backend selection:** `VISUAL_BACKEND=opencv_crossout` enables the [OpenCV cross-out testing stage](OPENCV_CROSS_OUT.md) without model/reference assets. It never approves documents: signature validation is not implemented in this backend. The default `VISUAL_BACKEND=onnx` still requires a validated local package configured through `VISUAL_MANIFEST_PATH`; missing assets return `INTERNAL_ERROR` and `verified=false`, without automatic fallback. Read [visual validation deployment, package format and limitations](VISUAL_VALIDATION.md) before deploying. Incoming documents remain in the existing receiving flow; no training-data collection or additional upload storage is introduced.

## Local setup

For the portal's admin/manager **OCR Testing** page, opt-in per-check diagnostics,
and the separate three-field RPA notification, see
[portal testing and result delivery](../docs/ocr-testing-and-result-delivery.md).
The page does not create production jobs or send notifications. Notifications
on production paths are separately enabled; database status storage is unchanged.

Use Python 3.10–3.12. From `ocr/` create a virtual environment, install `requirements.txt`, and copy `.env.example` to `.env`. Set `API_KEY` for the OCR service and configure the same value as `OCR_API_KEY` in the portal environment. The service checks the `X-API-Key` header whenever `API_KEY` is set.

```powershell
py -3.12 -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install --upgrade pip setuptools wheel
pip install -r requirements.txt
Copy-Item .env.example .env
uvicorn app.main:app --host 127.0.0.1 --port 8000
```

Keep local development on loopback. For hosted deployment, do not use `--reload`; use the systemd and private HTTPS proxy templates in [`deploy/`](deploy/README.md). Keep the OCR service private to the portal host/network.

## Cron2 integration

Enable the service first, then configure the portal `.env`:

```env
OCR_ENABLED=true
OCR_SERVICE_URL=http://127.0.0.1:8000
OCR_API_KEY=<same key as API_KEY>
OCR_BATCH_SIZE=5
OCR_CONNECT_TIMEOUT_MS=5000
OCR_TIMEOUT_MS=180000
```

When the portal and OCR service are on separate hosts, use the private HTTPS URL instead of loopback. Schedule `php bin/cron_runner.php --cron2`; Cron2 synchronizes today's customer attachments and invokes OCR in the same run. Historical cases require an explicit scoped `--crm-id=... --backlog` run. OCR success may insert `Email Cancel` directly into RPA Hub only for CLOG-confirmed Individual cancellations with a ready customer document and verified name/NRIC.

The worker accepts CLOG `New Email` and `Reply Mail` attachments. Agent `ReplyTo Mail` attachments are stored in the conversation but are not OCR inputs.

Database schemas, result storage, and status mappings are unchanged. The separate Cron2 direct-to-RPA-Hub workflow also remains unchanged: it fills missing stored identity fields when eligible and otherwise prefers stored customer details. The extraction-only change applies to OCR API responses; it does not replace existing stored details or alter that separate Hub payload.

## Routes used by the worker

- `POST /api/verify-cancellation-form-pdf` — one-page PDF.
- `POST /api/verify-mykad-pdf` — one-page form or two-page MyKad PDF, front then back.
- `POST /api/verify-mykad-image` — one MyKad image.
- `POST /api/verify-document` — `document_type=mykad` with `mykad_front` and `mykad_back`, or `document_type=passport` with `passport_image`.

Every route accepts multipart form data and requires `X-API-Key` when the service has `API_KEY` configured. The portal worker downloads files from CLOG and sends them to these routes. Do not send real customer documents to a public test instance or include full NRICs in logs.

## Local request example

```bash
curl -X POST "http://127.0.0.1:8000/api/verify-cancellation-form-pdf" \
  -H "X-API-Key: $OCR_API_KEY" \
  -F "document_pdf=@cancellation-form.pdf;type=application/pdf"
```

Check `GET /health` for service availability. Service health alone does not prove OCR models initialized successfully; check startup logs and run an approved non-customer sample before enabling Cron2.
