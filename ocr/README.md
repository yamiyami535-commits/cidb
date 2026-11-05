# CIDB OCR service

The OCR service is called by the portal's Cron2 worker. It extracts a readable customer name, NRIC, and address where present, and uses image blur as the image-quality rejection gate. The CIDB form's cross-out, signature, and stamp are not OCR checks.

The worker stores each document result in `portal_ticket_documents.ocr_status`. For a readable identity match it records `manual_review` for the frontend and `identity_check_verified=true`; the verified identity can then pass the automatic RPA gate. A mismatch or unreadable document does not pass that gate. No additional ticket workflow statuses are created.

## Local setup

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

## Routes used by the worker

- `POST /api/verify-cancellation-form-pdf` — one-page PDF, with optional `entered_name` and `entered_nric`.
- `POST /api/verify-mykad-pdf` — two-page MyKad PDF, front then back, with `entered_name` and `entered_nric`.
- `POST /api/verify-mykad-image` — one MyKad image, with optional expected name and NRIC.

Every route accepts multipart form data and requires `X-API-Key` when the service has `API_KEY` configured. The portal worker downloads files from CLOG and sends them to these routes. Do not send real customer documents to a public test instance or include full NRICs in logs.

## Local request example

```bash
curl -X POST "http://127.0.0.1:8000/api/verify-cancellation-form-pdf" \
  -H "X-API-Key: $OCR_API_KEY" \
  -F "entered_name=TEST USER" \
  -F "entered_nric=900101101234" \
  -F "document_pdf=@cancellation-form.pdf;type=application/pdf"
```

Check `GET /health` for service availability. Service health alone does not prove OCR models initialized successfully; check startup logs and run an approved non-customer sample before enabling Cron2.
