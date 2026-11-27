"""Local visual checks on the images already received by the OCR API.

The package contract is a custom-trained PaddleDetection PicoDet export WITH
postprocessing: RGB Resize/Normalize/Permute inputs and original-image xyxy
boxes [class_id, score, x1, y1, x2, y2]. Raw detector heads are not accepted.
The explicitly selected opencv_crossout backend provides stage-one stroke
evidence only. It never supplies affirmative customer-signature evidence.
"""
import json
import logging
from pathlib import Path
from threading import Lock, Timer
from time import monotonic

import cv2
import numpy as np
from pydantic import BaseModel, ConfigDict, Field, model_validator

from .config import settings
from .cross_out_detection import OpenCVCrossOutDetector, check_deadline
from .diagnostics import current as current_diagnostics
from .document_references import DocumentReferences, ReferenceSpec, checked_asset
from .visual_types import Detection, Evidence, VisualResult, combine_evidence, intersection_fraction


logger = logging.getLogger(__name__)
LABELS = ["cross_out", "customer_signature", "embedded_signature", "mykad_front", "mykad_back", "passport"]
DOCUMENT_LABELS = set(LABELS[3:])


class ModelManifest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    format_version: int
    release_id: str = Field(min_length=1)
    model: str
    sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    labels: list[str]
    input_size: int = Field(ge=256, le=1024)
    output_name: str = Field(min_length=1)
    mean: tuple[float, float, float]
    std: tuple[float, float, float]
    candidate_threshold: float = Field(gt=0, lt=1)
    present_threshold: float = Field(gt=0, lt=1)
    references: list[ReferenceSpec]
    # Identify the held-out evaluation used to select this release's thresholds.
    evaluation_id: str = Field(min_length=1)

    @model_validator(mode="after")
    def validate_contract(self):
        if self.format_version != 1 or self.labels != LABELS:
            raise ValueError("Unsupported visual model contract.")
        if self.candidate_threshold >= self.present_threshold:
            raise ValueError("Visual confidence thresholds must be ordered.")
        if not all(np.isfinite(self.mean)) or not all(np.isfinite(self.std)) or min(self.std) <= 0:
            raise ValueError("Invalid model normalization.")
        return self


class OnnxDetector:
    def __init__(self, manifest_path: str, threads: int):
        import onnxruntime as ort

        path = Path(manifest_path).resolve()
        self.manifest = ModelManifest.model_validate(json.loads(path.read_text(encoding="utf-8")))
        model_path = checked_asset(path.parent, self.manifest.model, self.manifest.sha256)
        options = ort.SessionOptions()
        options.intra_op_num_threads = threads
        options.inter_op_num_threads = 1
        options.execution_mode = ort.ExecutionMode.ORT_SEQUENTIAL
        self.session = ort.InferenceSession(str(model_path), sess_options=options, providers=["CPUExecutionProvider"])
        self.ort = ort
        self.inputs = {item.name: item for item in self.session.get_inputs()}
        if not {"image", "scale_factor"} <= self.inputs.keys() or self.inputs.keys() - {"image", "im_shape", "scale_factor"}:
            raise ValueError("Unsupported detector inputs; export with PaddleDetection postprocessing.")
        size = self.manifest.input_size
        if self.inputs["image"].shape != [1, 3, size, size]:
            raise ValueError("Detector must have the manifest's fixed NCHW input shape.")
        if any(item.type != "tensor(float)" for item in self.inputs.values()):
            raise ValueError("Detector inputs must be float32.")
        if self.manifest.output_name not in {item.name for item in self.session.get_outputs()}:
            raise ValueError("Detector output is missing.")
        self.references = DocumentReferences(path.parent, self.manifest.references)

    def detect(self, image, deadline) -> list[Detection]:
        height, width = image.shape[:2]
        size = self.manifest.input_size
        # PicoDet TestReader Resize interp=2, keep_ratio=False.
        resized = cv2.resize(image, (size, size), interpolation=cv2.INTER_CUBIC)
        rgb = cv2.cvtColor(resized, cv2.COLOR_BGR2RGB).astype(np.float32) / 255.0
        normalized = (rgb - np.array(self.manifest.mean, np.float32)) / np.array(self.manifest.std, np.float32)
        feed = {
            "image": np.ascontiguousarray(normalized.transpose(2, 0, 1)[None]),
            "im_shape": np.array([[size, size]], np.float32),
            "scale_factor": np.array([[size / height, size / width]], np.float32),
        }
        remaining = deadline - monotonic()
        if remaining <= 0:
            raise TimeoutError("Visual inference deadline exceeded.")
        run_options = self.ort.RunOptions()
        timer = Timer(remaining, lambda: setattr(run_options, "terminate", True))
        timer.daemon = True
        timer.start()
        try:
            output = self.session.run([self.manifest.output_name],
                                      {key: feed[key] for key in self.inputs}, run_options)[0]
        finally:
            timer.cancel()
        if monotonic() > deadline:
            raise TimeoutError("Visual inference deadline exceeded.")
        return parse_detections(output, width, height, self.manifest.candidate_threshold)


def parse_detections(output, width, height, threshold):
    rows = np.asarray(output)
    if rows.ndim != 2 or rows.shape[1] != 6 or not np.isfinite(rows).all():
        raise ValueError("Malformed visual detector output.")
    detections = []
    for class_id, score, x1, y1, x2, y2 in rows:
        # PaddleDetection's no-detection sentinel.
        if class_id == -1 and score == 0:
            continue
        if class_id != int(class_id) or not 0 <= class_id < len(LABELS) or not 0 <= score <= 1:
            raise ValueError("Invalid visual class/confidence.")
        if score < threshold:
            continue
        if not x1 < x2 or not y1 < y2:
            raise ValueError("Invalid visual box.")
        box = (max(0., float(x1)), max(0., float(y1)), min(float(width), float(x2)), min(float(height), float(y2)))
        if box[0] >= box[2] or box[1] >= box[3]:
            raise ValueError("Visual box falls outside the image.")
        detections.append(Detection(LABELS[int(class_id)], float(score), box))
    return detections


def page_windows(image, tile_size, max_tiles):
    """Full-page context plus complete overlapping detail coverage.

    Never silently truncate the grid: that would let an unprocessed part of a
    document be treated as inspected. The caller returns uncertainty instead.
    """
    height, width = image.shape[:2]
    if height <= tile_size and width <= tile_size:
        return [(0, 0, width, height)]
    def starts(length):
        return sorted(set([*range(0, max(1, length-tile_size+1), tile_size*3//4), max(0, length-tile_size)]))
    xs, ys = starts(width), starts(height)
    if 1 + len(xs)*len(ys) > max_tiles:
        return None
    return [(0, 0, width, height)] + [(x, y, min(x+tile_size, width), min(y+tile_size, height)) for y in ys for x in xs]


def deduplicate(detections):
    kept = []
    for item in sorted(detections, key=lambda value: value.score, reverse=True):
        if any(item.label == other.label and
               min(intersection_fraction(item.box, other.box), intersection_fraction(other.box, item.box)) > .6
               for other in kept):
            continue
        kept.append(item)
    return kept


def evaluate_page(image, detections, references, threshold, deadline, details=None):
    crosses = [item for item in detections if item.label == "cross_out"]
    cross = (Evidence.PRESENT if any(item.score >= threshold for item in crosses)
             else Evidence.UNCERTAIN if crosses else Evidence.ABSENT)
    candidates = [item for item in detections if item.label == "customer_signature"]
    if not candidates:
        if details is not None:
            details["signature_reasons"] = ["No customer-signature candidate detected."]
        return cross, Evidence.ABSENT
    documents = [item for item in detections if item.label in DOCUMENT_LABELS]
    # Match all detected IDs so that an unrecognised second card cannot supply
    # an embedded signature misclassified as a customer addition.
    layouts_known = bool(documents)
    printed_regions = []
    for document in documents:
        regions = references.match(image, document, deadline) if document.score >= threshold else None
        if regions is None:
            layouts_known = False
        else:
            printed_regions.extend(regions)
    printed = [item.box for item in detections if item.label == "embedded_signature"]
    if details is not None:
        details["reference_regions"] = printed_regions
        details["reference_state"] = "matched" if layouts_known else "uncertain"
        details["signature_reasons"] = []
    uncertain = False
    for candidate in candidates:
        # Do not automatically count the same stroke as both mandatory marks.
        overlaps = any(intersection_fraction(candidate.box, box) > .05 for box in printed + printed_regions)
        same_cross = any(min(intersection_fraction(candidate.box, item.box),
                             intersection_fraction(item.box, candidate.box)) > .8 for item in crosses)
        if details is not None:
            reasons = []
            if candidate.score < threshold:
                reasons.append("Candidate confidence below acceptance threshold.")
            if not layouts_known:
                reasons.append("One or more ID layouts could not be matched to a reference.")
            if overlaps:
                reasons.append("Candidate overlaps an embedded signature or expected reference region.")
            if same_cross:
                reasons.append("Candidate overlaps the cross-out; the same stroke cannot satisfy both requirements.")
            details["signature_reasons"].extend(reasons or ["Customer-added candidate accepted after reference exclusions."])
        if candidate.score >= threshold and layouts_known and not overlaps and not same_cross:
            return cross, Evidence.PRESENT
        uncertain = True
    return cross, Evidence.UNCERTAIN if uncertain else Evidence.ABSENT


class VisualValidationService:
    def __init__(self, config=settings):
        self.config = config
        self.detector = None
        self._attempted = False
        self._lock = Lock()

    def initialize(self):
        with self._lock:
            if self._attempted:
                return
            self._attempted = True
            try:
                if self.config.visual_backend == "opencv_crossout":
                    self.detector = OpenCVCrossOutDetector()
                    return
                if not self.config.visual_manifest_path:
                    raise ValueError("VISUAL_MANIFEST_PATH is not configured.")
                detector = OnnxDetector(self.config.visual_manifest_path, self.config.visual_threads)
                size = detector.manifest.input_size
                detector.detect(np.zeros((size, size, 3), np.uint8), monotonic()+self.config.visual_timeout_seconds)
                self.detector = detector
            except Exception as error:
                # No image contents, OCR text, or model paths are logged.
                logger.error("Visual validation unavailable (%s); automatic approval is disabled.", type(error).__name__)

    def inspect(self, images) -> VisualResult:
        self.initialize()
        if self.config.visual_backend == "opencv_crossout":
            return self._inspect_cross_out(images)
        observer = current_diagnostics.get()
        if self.detector is None or not images:
            if observer is not None:
                for page in observer.pages:
                    page.update(visual_state="error", visual_reason="Visual model unavailable.")
            return VisualResult(Evidence.ERROR, Evidence.ERROR, 0)
        if observer is not None:
            observer.model = {"release_id": self.detector.manifest.release_id,
                              "evaluation_id": self.detector.manifest.evaluation_id,
                              "present_threshold": self.detector.manifest.present_threshold}
        deadline = monotonic() + self.config.visual_timeout_seconds
        crosses, signatures = [], []
        checked = 0
        try:
            for image in images:
                if not isinstance(image, np.ndarray) or image.ndim != 3 or image.shape[2] != 3 or image.dtype != np.uint8 or image.size == 0:
                    raise ValueError("Invalid visual input image.")
                windows = page_windows(image, self.config.visual_tile_size, self.config.visual_max_tiles)
                if windows is None:
                    if observer is not None:
                        observer.image(image).update(visual_state="uncertain", visual_reason="Tile limit prevents complete page coverage.")
                    # Coverage was not completed, so even another positive page
                    # must not cause automatic approval.
                    return VisualResult(Evidence.UNCERTAIN, Evidence.UNCERTAIN, checked)
                detections = []
                for index, (x1, y1, x2, y2) in enumerate(windows):
                    for item in self.detector.detect(image[y1:y2, x1:x2], deadline):
                        # Card layouts require full-page context. A cropped ID
                        # detected in a tile must not create a partial reference.
                        if index and item.label in DOCUMENT_LABELS:
                            continue
                        a, b, c, d = item.box
                        detections.append(Detection(item.label, item.score, (a+x1, b+y1, c+x1, d+y1)))
                detections = deduplicate(detections)
                details = observer.image(image) if observer is not None else None
                cross, signature = evaluate_page(image, detections, self.detector.references,
                                                 self.detector.manifest.present_threshold, deadline, details)
                if details is not None:
                    details["detections"] = [{"label": item.label, "score": item.score, "box": item.box} for item in detections]
                    embedded = [item for item in detections if item.label == "embedded_signature"]
                    details["embedded_signature"] = ("present" if any(item.score >= self.detector.manifest.present_threshold for item in embedded)
                                                     else "uncertain" if embedded else "absent")
                    details.update(visual_state="checked", cross_out=cross.value, customer_signature=signature.value)
                if monotonic() > deadline:
                    raise TimeoutError("Visual validation deadline exceeded.")
                crosses.append(cross)
                signatures.append(signature)
                checked += 1
            return VisualResult(combine_evidence(crosses), combine_evidence(signatures), checked)
        except Exception as error:
            if observer is not None:
                for page in observer.pages[checked:]:
                    page.update(visual_state="error", visual_reason="Visual inspection failed or could not finish.")
            logger.warning("Visual validation failed (%s).", type(error).__name__)
            return VisualResult(Evidence.ERROR, Evidence.ERROR, checked)

    def _inspect_cross_out(self, images) -> VisualResult:
        """Same page/decision interface, deliberately no signature implementation."""
        observer = current_diagnostics.get()
        if observer is not None:
            observer.model = {"release_id": OpenCVCrossOutDetector.release_id,
                              "method": "opencv_crossout", "capabilities": ["cross_out"],
                              "evaluation_id": "synthetic-tests-only; real-document accuracy not established"}
        checked, crosses = 0, []
        deadline = monotonic() + self.config.visual_timeout_seconds
        try:
            if self.detector is None or not images:
                raise ValueError("Cross-out detector unavailable or no pages supplied.")
            for image in images:
                result = self.detector.detect(image, deadline)
                check_deadline(deadline)
                if observer is not None:
                    details = observer.image(image)
                    details.update(visual_state="checked", cross_out=result.state.value,
                                   cross_out_reasons=result.reasons, detections=result.detections,
                                   customer_signature="not_checked", embedded_signature="not_checked",
                                   reference_state="not_checked", reference_regions=[],
                                   visual_reason="Stage one checks cross-out strokes only. Signature verification is not implemented in this backend; automatic approval is withheld.")
                crosses.append(result.state)
                checked += 1
            return VisualResult(combine_evidence(crosses), Evidence.UNCERTAIN, checked)
        except Exception as error:
            if observer is not None:
                for page in observer.pages[checked:]:
                    page.update(visual_state="error", visual_reason="Cross-out inspection failed or could not finish.")
            logger.warning("OpenCV cross-out inspection failed (%s).", type(error).__name__)
            return VisualResult(Evidence.ERROR, Evidence.UNCERTAIN, checked)


visual_service = VisualValidationService()
