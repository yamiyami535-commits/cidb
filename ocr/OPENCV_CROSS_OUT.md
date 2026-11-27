# OpenCV cross-out testing — stage one

This optional backend detects cross-out stroke evidence in submitted images.
It does **not** implement customer-signature detection, embedded-signature
detection, reference matching or document-layout localisation. Every overall
verification remains `verified=false`, including when a cross-out is present.
PaddleOCR, identity extraction, document/quality checks, receiving APIs, database
handling and RPA integrations are unchanged. No model download or reference
package is required for this stage; OpenCV and NumPy are existing dependencies.

## Start locally

From the repository's `ocr` directory, using the existing OCR environment:

```powershell
$env:VISUAL_BACKEND = "opencv_crossout"
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8001
```

Alternatively set `VISUAL_BACKEND=opencv_crossout` in `ocr/.env` and restart the
OCR process. The default remains `onnx`; there is no fallback from a broken
ONNX package. `VISUAL_MANIFEST_PATH` is ignored only in explicit OpenCV mode.
Keep any existing API key configuration. Port 8001 matches the portal client's
default; if `OCR_SERVICE_URL` or `OCR_TEST_SERVICE_URL` overrides that default,
use the corresponding port/instance. Do not replace an existing environment file.

Open the portal's **OCR Testing** page as an authorised admin/manager. It uses
the existing upload routes and opt-in diagnostics. No test-page ticket writes
or RPA callbacks are added. Use a separate OCR test instance if production
requests must continue using a different visual backend.

`GET http://127.0.0.1:8001/health` confirms the process is reachable, not detection
accuracy. An otherwise eligible upload should show `opencv-crossout-v3` as the
visual method. Earlier OCR/document/quality failures still stop visual checking
and show `not_checked`; the testing page does not bypass those gates.

## What the detector checks

- Processes each original decoded page before service-generated watermarks.
- Uses local thresholding, morphological closing, probabilistic Hough lines and
  elongated contours; merges duplicate stroke candidates.
- Assesses stroke length, contrast and continuity. CIDB OCR text is never an
  input to detection; wording alone cannot supply a cross-out pass.
- The usual long-stroke path retains its existing minimum appearance checks.
  A shorter stroke can qualify through a separate, stricter path: a clear
  diagonal direction, contrast at least 100 gray levels, at least 95% support,
  maximum gap fraction 0.025, a narrow consistent width and length/width at
  least 25. It also needs a minimum visible extent. This is not a global
  lowering of the old length threshold, and graphic/edge exclusions still apply.
- Marks closed-frame edges, page edges and near-horizontal/vertical rules as
  uncertain. This intentionally includes genuine axis-aligned cross-outs whose
  origin cannot yet be distinguished from printed rules.
- Checks the full page at a bounded resolution (maximum side 1600). Very small
  images or downscaling below 25% produce uncertainty rather than a pass.
- Removes tiny disconnected pattern components before line detection and counts
  only significant contours. Weak/background lines, closed graphic edges and
  duplicate candidates are filtered before applying the candidate budget, so
  thousands of small card-pattern details do not block valid long strokes.
- Uses contrast on both sides of a stroke and continuity checks. Repeated nearby
  parallel hatching remains uncertain; printed graphics can still resemble
  genuine marks, so this is not proof of a mark's origin.
- Consolidates overlapping/adjacent collinear fragments only after measuring
  their combined pixels; blank gaps and weak extensions cannot create a pass.
  Hatching is assessed on groups of distinct well-supported strokes, not weak
  parallel fragments of lettering. Nonparallel endpoint junctions (such as a
  printed chevron) and broad/branching profiles remain uncertain. Centre
  intersections of an X are not treated as endpoint junctions.
- Limits remaining candidate/contour counts and checks the existing visual deadline.
  Errors or timeouts cannot be hidden by a positive result on another page.
- Reports original-pixel stroke endpoints/boxes, rule measurements and reasons.
  These are rule evidence, **not probability/confidence percentages**.
  The testing page draws up to six meaningful, numbered strokes rather than
  large overlapping boxes. Other inspected candidates have expandable reasons
  and remain in the raw diagnostic response. This display cap does not change
  the verification decision or skip page inspection.

Clear cross-out evidence can be on either page; both pages must be processed.
The accepted file types and endpoint-specific one/two-page PDF limits remain
unchanged. The same image algorithm runs on existing PDF-rendered pages.

## Outcomes

| Cross-out check | Overall status after successful earlier checks |
|---|---|
| Present | `MANUAL_REVIEW`, `verified=false`: signature not checked |
| Uncertain | `MANUAL_REVIEW`, `verified=false` |
| Absent | `INVALID_DOCUMENT`, `verified=false` |
| Error/timeout | `INTERNAL_ERROR`, `verified=false` |

Both signature checks display **Not checked**. There is no new signature
classifier and no assumption that a printed signature is customer-added.
This stage cannot approve documents even if an uploaded page contains a genuine
customer signature. Existing response fields/status handling remain unchanged;
additional evidence is confined to the existing opt-in diagnostics object.

## Tests and limitations

From `ocr/`, with the isolated test dependencies installed:

```powershell
.\.venv-visual\Scripts\python.exe -m unittest discover -s tests -p test_cross_out_detection.py -v
.\.venv-visual\Scripts\python.exe -m unittest discover -s tests -q
```

From the repository root, the existing isolated portal fixture exercises upload
forwarding and the browser's OpenCV diagnostic rendering:

```powershell
node tests/ocr-testing-http.cjs --browser
```

The fixtures cover plain/paired lines, wording alone (including rotation),
coloured strokes, dense-pattern backgrounds with two clear diagonal strokes,
closed-graphic/hatch exclusions, frame/grid exclusions, faint/short/ambiguous marks, bounds and
timeouts, both image pages and real two-page PDF rendering, unchanged extraction
and response contracts, and withheld approval. OCR text is mocked in endpoint
tests; the new OpenCV detector and PDF rendering are real. The browser fixture
uses synthetic OCR responses and isolated database/RPA substitutes.

These tests are **not real-document accuracy validation**. Diagonal printed
graphics can resemble cross-outs; short, faint, curved, crowded or heavily
compressed strokes may be missed. Candidate absence means the rules found no
qualifying stroke, not proof that no mark exists. Genuine horizontal/vertical
marks currently remain uncertain. No reference assets are required now because
layout and embedded-signature identification are deliberately deferred.
Evaluate real approved samples in the testing page before selecting thresholds
or expanding the supported visual appearances. Runtime uploads are not retained
or collected as a dataset by this implementation.

The v3 regressions include the reported 0.244-page-length candidate's numeric
evidence and synthetic strokes with its endpoints, but the old response has no
width measurements or original pixels. Width tests and graphic/crest-like
patterns are synthetic. Re-upload the original document to assess real results.
An isolated printed diagonal can still be indistinguishable from an added mark;
reference-based origin checks and signature detection remain unimplemented.
