"""Read approved local fixtures and report aggregate visual accuracy/latency.

Usage from ocr/: python -m tools.evaluate_visual --manifest /path/manifest.json
Add --dataset /path/evaluation.json to evaluate submissions. No documents, OCR
text, images or per-case identifiers are written to disk or printed.
"""
import argparse
import json
from pathlib import Path
from time import perf_counter

import numpy as np
from pydantic import BaseModel, ConfigDict, Field, StrictBool

from app.config import Settings
from app.image_quality import decode_image
from app.pdf_input import render_pdf_pages
from app.visual_types import Evidence
from app.visual_validation import VisualValidationService


class EvaluationCase(BaseModel):
    model_config = ConfigDict(extra="forbid")
    files: list[str] = Field(min_length=1, max_length=2)
    cross_out: StrictBool
    customer_signature: StrictBool


def evaluate(service, cases, root):
    metrics = {"submissions": 0, "visual_false_approvals": 0, "visual_false_rejections": 0,
               "uncertain": 0, "errors": 0, "cross_out_false_positives": 0,
               "signature_false_positives": 0}
    durations = []
    for case in cases:
        images = []
        for filename in case.files:
            path = (root / filename).resolve()
            if not path.is_relative_to(root.resolve()):
                raise ValueError("Evaluation files must be inside the dataset directory.")
            data = path.read_bytes()
            if data.startswith(b"%PDF-"):
                images.extend(decode_image(page) for page in render_pdf_pages(data))
            else:
                images.append(decode_image(data))
        if not 1 <= len(images) <= 2:
            raise ValueError("Evaluation submissions must match the service's one/two-page limit.")
        start = perf_counter()
        result = service.inspect(images)
        durations.append(perf_counter()-start)
        states = (result.cross_out, result.signature)
        predicted = states == (Evidence.PRESENT, Evidence.PRESENT) and result.pages_checked == len(images)
        expected = case.cross_out and case.customer_signature
        metrics["submissions"] += 1
        metrics["visual_false_approvals"] += int(predicted and not expected)
        metrics["visual_false_rejections"] += int(expected and not predicted)
        metrics["uncertain"] += int(Evidence.UNCERTAIN in states)
        metrics["errors"] += int(Evidence.ERROR in states)
        metrics["cross_out_false_positives"] += int(result.cross_out == Evidence.PRESENT and not case.cross_out)
        metrics["signature_false_positives"] += int(result.signature == Evidence.PRESENT and not case.customer_signature)
    if not durations:
        raise ValueError("Evaluation dataset must not be empty.")
    metrics["visual_latency_p50_seconds"] = round(float(np.percentile(durations, 50)), 4)
    metrics["visual_latency_p95_seconds"] = round(float(np.percentile(durations, 95)), 4)
    metrics["visual_latency_max_seconds"] = round(max(durations), 4)
    return metrics


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--dataset")
    args = parser.parse_args()
    service = VisualValidationService(Settings(visual_manifest_path=args.manifest))
    service.initialize()
    if service.detector is None:
        print(json.dumps({"ready": False, "reason": "Visual package could not be loaded and warmed."}))
        return 2
    report = {"ready": True, "release_id": service.detector.manifest.release_id}
    if args.dataset:
        path = Path(args.dataset).resolve()
        try:
            cases = [EvaluationCase.model_validate(item) for item in json.loads(path.read_text(encoding="utf-8"))]
            report.update(evaluate(service, cases, path.parent))
        except Exception as error:
            # Avoid printing document filenames or data via exception messages.
            print(json.dumps({"ready": False, "reason": "Evaluation input or execution failed.", "error_type": type(error).__name__}))
            return 2
    print(json.dumps(report, indent=2))
    return 1 if report.get("visual_false_approvals", 0) or report.get("errors", 0) else 0


if __name__ == "__main__":
    raise SystemExit(main())
