\
# CIDB OCR Verification Starter

This project checks:

1. Whether the uploaded file is a supported image or a two-page MyKad PDF.
2. Whether the image is sufficiently clear, bright and high-resolution.
3. Whether OCR finds typical MyKad or passport text and a valid document number.
4. Whether the extracted details match the user's submitted details.
5. Whether the result should be `VERIFIED`, `REUPLOAD_REQUIRED`,
   `INVALID_DOCUMENT`, `DETAILS_MISMATCH`, `OCR_FAILED`, or `MANUAL_REVIEW`.

## Important limitation

This is document-content validation, not proof that a MyKad is authentic.
A forged or edited card image may still pass. Production identity verification
requires additional anti-spoofing, liveness, tamper detection and/or an
authorised identity-verification provider.

The API accepts separate MyKad front/back images or a PDF through
`POST /api/verify-mykad-pdf`. A two-page PDF is treated as a MyKad (front then
back). A one-page PDF is treated as a cancellation form: OCR compares identity
text where readable, but the result is always `MANUAL_REVIEW`. OCR does not
verify the CIDB-use cross-out, company stamp, or director's signature. PDFs
with any other page count are rejected.

## Recommended Python version

Use Python 3.10, 3.11 or 3.12. Python 3.12 is a practical choice on Windows.

## 1. Open the project

From the CIDB Portal repository root:

```bash
cd ocr
```

## 2. Create a virtual environment

Windows PowerShell:

```powershell
py -3.12 -m venv .venv312
.\.venv312\Scripts\Activate.ps1
```

Windows Command Prompt:

```bat
py -3.12 -m venv .venv312
.venv312\Scripts\activate
```

macOS/Linux:

```bash
python3.12 -m venv .venv312
source .venv312/bin/activate
```

## 3. Upgrade pip

```bash
python -m pip install --upgrade pip setuptools wheel
```

## 4. Install dependencies

```bash
pip install -r requirements.txt
```

If the exact PaddlePaddle version is unavailable for your operating system,
install the compatible CPU build using PaddlePaddle's official installation
selector, then run:

```bash
pip install paddleocr==3.7.0
pip install -r requirements.txt
```

## 5. Create the environment configuration

PowerShell:

```powershell
Copy-Item .env.example .env
```

Command Prompt:

```bat
copy .env.example .env
```

macOS/Linux:

```bash
cp .env.example .env
```

## 6. Start the API

Local development only:

```bash
uvicorn app.main:app --reload --host 127.0.0.1 --port 8000
```

The first OCR request may download pretrained PaddleOCR model files.

For hosted Linux deployment, do not use `--reload`. Run Uvicorn as a systemd
service with one worker, and put a TLS reverse proxy in front of it. Example
templates and the deployment checklist are in [`deploy/`](deploy/README.md).
Set `API_KEY` in the protected OCR environment to require `X-API-Key` on OCR
routes. The API does not provide TLS itself; keep Uvicorn bound to loopback and
allow only the portal host/network through the private HTTPS proxy.

## 7. Test in the browser

Open:

```text
http://127.0.0.1:8000
```

API documentation:

```text
http://127.0.0.1:8000/docs
```

Health endpoint:

```text
http://127.0.0.1:8000/health
```

## 8. Test with curl

```bash
curl -X POST "http://127.0.0.1:8000/api/verify-document" \
  -F "document_type=mykad" \
  -F "entered_name=TEST USER" \
  -F "entered_nric=900101-10-1234" \
  -F "mykad_front=@front.jpg" \
  -F "mykad_back=@back.jpg"
```

For a two-page MyKad PDF:

```bash
curl -X POST "http://127.0.0.1:8000/api/verify-mykad-pdf" \
  -F "entered_name=TEST USER" \
  -F "entered_nric=900101-10-1234" \
  -F "document_pdf=@mykad.pdf"
```

For a one-page cancellation form, use the same endpoint. Its result can never
be `VERIFIED`; an authorised reviewer must visually confirm the marked IC and
signature.

## Portal Cron2 worker

The portal's `bin/ocr_runner.php` is an OCR-only worker. It considers assigned
tickets in `ready_for_ocr` whose CLOG status is `Open`, then accepts one
one- or two-page PDF or two image attachments whose filenames clearly identify
`front`/`depan` and `back`/`belakang`. It reads the name and NRIC from the
portal's normalized ticket/CRM interaction fields; the CLOG NRIC mapping must
be populated before OCR can run.

Configure the portal `.env` from the root `.env.example`, start this service,
and only then set `OCR_ENABLED=true`. The worker fetches attachments over
HTTPS, sends them to this API, stores a reduced result without full OCR text or
unmasked document numbers, and marks the ticket for manual review. It does not
call RPA or send customer email. Unknown file layouts remain in manual review.
For a one-page PDF without an expected NRIC, the worker uses the dedicated
cancellation-form route, records only a masked extracted number when available,
and still requires an authorised manual review.

Required response fields:

- `verified`
- `status`
- `message`

Optional response fields:

- `documents`
- `comparison`
- `images_quality`
- `extracted_document_number_masked`
- `ocr_average_confidence`
- `extracted_text_lines`
- `processed_images`

Then branch using `status`:

- `VERIFIED`: continue registration or verification.
- `REUPLOAD_REQUIRED`: ask for another photo.
- `INVALID_DOCUMENT`: tell the user to upload a MyKad.
- `DETAILS_MISMATCH`: ask the user to check entered details.
- `OCR_FAILED`: tell the user the image could not be read.
- `INVALID_REQUEST`: tell the user the submission is missing or malformed.
- `INTERNAL_ERROR`: retry later or alert an operator.
- `MANUAL_REVIEW`: queue the submission for an authorised reviewer.

## Threshold calibration

Edit `.env` after testing a representative set of images:

```env
MIN_BLUR_SCORE=80
MIN_OCR_CONFIDENCE=0.65
MIN_NAME_SCORE=85
MANUAL_REVIEW_NAME_SCORE=70
```

Do not choose final thresholds from one or two cards. Test:

- Clear cards.
- Slightly blurry cards.
- Dim and overexposed cards.
- Tilted cards.
- Different phone cameras.
- Non-MyKad documents.
- Images with unrelated text.
- Valid cards where OCR makes one-character mistakes.

## Production privacy checklist

- Use HTTPS.
- Obtain explicit user consent.
- Avoid logging raw image bytes, full names or complete NRIC values.
- Delete temporary images immediately after processing.
- Encrypt any retained files and database fields.
- Restrict staff access.
- Add authentication, rate limiting and audit logs.
- Remove `extracted_text_lines` from API responses unless genuinely required.
- Establish a retention policy.
- Conduct legal/privacy review before production use.

## Response contract

The OCR service returns a consistent verification response body for all OCR-related outcomes.

Mandatory fields:

- `verified`
- `status`
- `message`

Optional fields are included only when meaningful and may be omitted entirely on validation failures or early request rejection.
