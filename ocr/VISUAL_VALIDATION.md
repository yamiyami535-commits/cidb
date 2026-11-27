# Visual document validation

## Operational status

An explicit **OpenCV cross-out-only backend** is available for local evaluation:
set `VISUAL_BACKEND=opencv_crossout`. It requires no model/reference package,
shows stroke evidence in the testing page and never approves a document because
signature validation is not implemented in that backend. See
[stage-one setup, tests and limitations](OPENCV_CROSS_OUT.md).

The remainder of this document describes `VISUAL_BACKEND=onnx` (the default).
The runtime integration is implemented. This repository does **not** contain a
trained CIDB model, approved reference images, or a real-document evaluation.
The example manifest is a format example, not a deployable package. Its boxes,
thresholds, hashes and names must be replaced using evaluated assets.

Deploying this code without a usable `VISUAL_MANIFEST_PATH` disables automatic
approval: otherwise eligible requests return `verified=false`,
`status=INTERNAL_ERROR`. There is no identity-only bypass. Existing failed OCR,
identity and quality results retain their existing handling. `/health` retains
its original response and is not evidence that the visual package is ready.

The service receives files through the existing endpoints and processes original
decoded images in memory. It does not save uploads, collect training data,
contact a visual API, or change database operations. Static model/reference
files are deployment assets, not retained customer submissions. Existing
generated response images are still returned exactly through the existing path.

## Decision flow

PaddleOCR and preprocessing remain in place. Identity is extracted directly from
OCR evidence; customer-provided values are not compared or used as hints. The
same API response returns the extracted name/number with the existing outcome.
The additional gate requires a name span supported by existing OCR lines
(including passport MRZ names), an extracted number, a visible cross-out, and a
customer-added signature. CIDB wording is neither required nor sufficient.

`visual_validation.py` runs a full-page view plus overlapping detail tiles.
Every accepted page is inspected; one-page and two-page PDF limits are unchanged.
Cross-out evidence and signature evidence can come from different pages of the
same request. A late page error or incomplete tile coverage prevents approval.
Existing identity/quality failures are returned without extra visual inference.

The detector predicts six classes in this exact zero-based order:

0. `cross_out` (plain lines and CIDB-text cross-outs share this class)
1. `customer_signature`
2. `embedded_signature`
3. `mykad_front`
4. `mykad_back`
5. `passport`

Signature acceptance additionally requires recognised document layouts.
`document_references.py` uses ORB descriptors and RANSAC homography to align
each detected ID with unmarked reference designs. It requires distributed
alignment support, not a match to a single logo or signature. Embedded-signature
regions are projected into the submitted image. Candidates overlapping these
regions or detector-labelled embedded signatures remain uncertain. Unknown
layouts do not become customer signatures merely because no reference matches.

This conservative first implementation cannot automatically resolve customer
ink overlapping an embedded-signature region. Such cases remain unverified,
even if the mark was genuinely added. References must cover supported card
versions and passport layouts. Crops, weak reference features, missed document
regions and low-resolution marks can also cause manual review. Image-only
analysis does not prove when a mark was made or who made it.

| Result | Existing response |
|---|---|
| All mandatory conditions and prior identity/quality checks pass | `verified=true`; retain the route's success status |
| A required mark is absent | `verified=false`, `INVALID_DOCUMENT` |
| Mark origin/confidence or full-page coverage is uncertain | `verified=false`, `MANUAL_REVIEW` |
| Missing/broken assets, malformed inference, timeout or exception | `verified=false`, `INTERNAL_ERROR` |

`MANUAL_REVIEW` can historically accompany `verified=true` for successful PDF
and image routes. It is **the verified boolean** that controls downstream
automation; all uncertain/error cases explicitly clear it. Public response
models are unchanged. Worker storage keys such as `identity_check_verified` and
legacy `full_submission_checks` wording are unchanged. Historical stored results
are not reprocessed or rewritten by this implementation.

## Model and reference package

Start with a custom-trained PP-PicoDet-S, exported WITH PaddleDetection NMS and
postprocessing. Generic COCO weights or raw PicoDet heads are not usable here.
Use the `release/2.9` PicoDet LCNet 416 configuration as a training baseline,
and verify the export separately from the service's pinned Paddle runtime.
The reference contract is documented in `deploy/visual-manifest.example.json`.

Required preprocessing/export contract:

- Resize to the manifest's fixed square shape, `keep_ratio=False`, OpenCV cubic
  interpolation (`interp=2` in the PicoDet TestReader); RGB / 255, subtract the
  manifest mean, divide by std, then float32 NCHW `[1,3,size,size]`.
- Inputs: `image`, `scale_factor` (`[[size/original_height,size/original_width]]`),
  and optionally `im_shape` (`[[size,size]]`), all float32.
- Named output: `[N,6]` rows `[class_id,score,x1,y1,x2,y2]` in **original view
  pixels**, after NMS. Empty `(0,6)` or Paddle's `[-1,0,0,0,0,0]` sentinel is valid.
- Export's internal score cutoff must be no higher than `candidate_threshold`;
  otherwise low-confidence candidates disappear and uncertainty is lost.
- Use calibration data to select both manifest thresholds. Changing label order,
  output coordinate conventions or normalization silently invalidates a model;
  verify these against Paddle predictions before packaging.
- List unmarked references with kind, image path, SHA-256 and every embedded
  signature box `[x1,y1,x2,y2]` normalized to the reference. Front references
  may have an empty box list; MyKad back references may not. Use specimen or
  de-identified reference designs with enough stable layout features.
- All asset paths resolve within the manifest directory. SHA-256 must match.
  Restart the process after changing packages; assets are loaded once.

Source contracts:
[PicoDet](https://github.com/PaddlePaddle/PaddleDetection/blob/release/2.9/configs/picodet/README_en.md),
[TestReader](https://github.com/PaddlePaddle/PaddleDetection/blob/release/2.9/configs/picodet/_base_/picodet_416_reader.yml),
[ONNX export](https://github.com/PaddlePaddle/PaddleDetection/blob/release/2.9/deploy/EXPORT_ONNX_MODEL_en.md).

## Training and evaluation data (separate, explicitly supplied assets)

No automatic dataset collection is implemented. If approved local samples become
available, start with 300-500 submissions for feasibility and plan 1,500-3,000
labelled submissions for training/calibration/evaluation; these are planning
sizes, not a demonstrated sufficiency guarantee. Use COCO boxes for all six
classes in the exact order above. Include real negative pages with zero marks.
Use paired unmarked/marked copies to establish origin wherever possible.

Include plain lines, text with lines, text alone, printed signatures alone,
small/faint scribbles, arbitrary positions, overlapping ink, stamps, borders,
security patterns, scans, photographs, rotations, multiple ID versions and
marks on different pages. Split by customer/source document; duplicate scans
and all pages from one source must remain in the same split. Synthetic data
supplements, but cannot establish real-document accuracy.

Train in a separate PaddleDetection environment (preferably on a GPU), set
`num_classes=6`, supply the COCO TrainDataset/EvalDataset/TestDataset annotation
paths, and calibrate on the actual full-page-plus-tiles inference pipeline.
Export to fixed-shape ONNX with postprocessing, compare output against the
training runtime, then build the manifest with actual hashes and reference
regions. No trained weights or training-success claims are provided here.

Check a completed package from `ocr/`:

```bash
python -m tools.evaluate_visual --manifest /opt/cidb-ocr/models/release/manifest.json
```

Evaluate approved files using a JSON array next to the files:

```json
[
  {"files": ["front.png", "back.png"], "cross_out": true, "customer_signature": true},
  {"files": ["printed-signature-only.pdf"], "cross_out": true, "customer_signature": false}
]
```

```bash
python -m tools.evaluate_visual --manifest /path/manifest.json --dataset /path/evaluation.json
```

The tool reads images/PDFs without retaining copies and prints aggregate false
approvals, false rejections, per-mark false positives, uncertainty/errors, and
warm visual p50/p95/max latency. Exit 2 means package/input failure; exit 1 means
false approvals or runtime errors were found. Exit 0 alone is **not** a release
approval: review false rejections, sample coverage and statistical uncertainty.
These are visual-only metrics; run the OCR endpoint tests and staged real OCR
requests separately. Startup/decode/queue time and peak RSS need host measurement.

## Deployment and tests

Install `requirements.txt`, retaining the current PaddleOCR/PaddlePaddle pins.
The added runtime is `onnxruntime==1.23.2` CPU. Keep training/export dependencies
off the production host. Keep one Uvicorn worker and package assets readable by
`cidb-ocr`. Set `VISUAL_MANIFEST_PATH` to the local manifest. Defaults: two ONNX
threads, ten-second inference/reference budget, 1024-pixel detail tiles, maximum
32 views per page. ONNX RunOptions cancellation interrupts inference at the
deadline; OpenCV work is bounded and checked between operations (not a hard OS
kill). Exceeding tile coverage returns uncertainty; exceeding time returns error.
Do not remove limits or enable identity-only approval to hide failures.

Load and warm the package before enabling traffic. Keep the current 180-second
worker / 210-second proxy timeouts and benchmark total time plus concurrent
queueing on the actual host. The earlier 0.5-2 seconds/page and 0.25-0.75 GB
additional-memory figures are unmeasured planning allowances, not guarantees.

For isolated tests (no Paddle model downloads):

```bash
python -m pip install -r requirements-test.txt
python -m unittest discover -s tests -v
```

Tests cover endpoint response compatibility, all approved paths, missing identity,
multi-page failures, raw-image use, real ONNX loading with a synthetic constant
model, normalization, cancellation, checksum rejection and synthetic geometric
reference alignment. They do not validate learned detection accuracy. Never
deploy the synthetic test model as a production detector.
