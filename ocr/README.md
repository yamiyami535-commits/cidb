\
# CIDB OCR Verification Starter

This project checks:

1. Whether the uploaded file is a supported image.
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
py -3.12 -m venv .venv
.\.venv\Scripts\Activate.ps1
```

Windows Command Prompt:

```bat
py -3.12 -m venv .venv
.venv\Scripts\activate
```

macOS/Linux:

```bash
python3.12 -m venv .venv
source .venv/bin/activate
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
The OCR route has no API-key authentication and does not provide TLS itself;
keep Uvicorn bound to loopback and allow only the portal host/network through
the private HTTPS proxy.

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

## React integration

This is for local development only. In production, the RPA caller should call
the portal proxy (`POST /api/ocr/verify-document`) rather than this direct OCR
endpoint. The portal sanitizes OCR output and records only its approved summary.

```tsx
async function verifyMyKad(
  name: string,
  nric: string,
  front: File,
  back: File
) {
  const formData = new FormData();
  formData.append("document_type", "mykad");
  formData.append("entered_name", name);
  formData.append("entered_nric", nric);
  formData.append("mykad_front", front);
  formData.append("mykad_back", back);

  const response = await fetch(
    "http://127.0.0.1:8000/api/verify-document",
    {
      method: "POST",
      body: formData,
    }
  );

  const data = await response.json();

  if (!response.ok) {
    throw new Error(
      data.message ?? data.detail ?? "MyKad verification failed"
    );
  }

  return data;
}
```

Do not manually set `Content-Type` for `FormData`; the browser generates the
correct multipart boundary.

## n8n integration

This direct OCR integration is suitable only for a trusted private test setup.
For production RPA use, call the authenticated portal proxy documented in the
CIDB Portal README; the OCR service endpoint itself does not authenticate
callers.

Use an HTTP Request node:

- Method: `POST`
- URL: `http://YOUR_OCR_SERVER:8000/api/verify-document`
- Body content type: `Form-Data`
- Fields:
  - `document_type`: `mykad` or `passport`
  - `entered_name`: user-entered name
  - `entered_nric`: user-entered NRIC for MyKad
  - `entered_passport_number`: user-entered passport number for passport verification
  - `mykad_front`: binary front image for MyKad
  - `mykad_back`: binary back image for MyKad
  - `passport_image`: binary passport information page

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
