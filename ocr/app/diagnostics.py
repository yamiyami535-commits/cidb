"""Opt-in, request-local observations of the existing verification pipeline.

This module does not make approval decisions or retain submitted documents.
"""
from contextvars import ContextVar
from time import monotonic
import re

import cv2

from .watermark_service import encode_image_as_data_url


current = ContextVar("ocr_diagnostics", default=None)


class Diagnostics:
    def __init__(self):
        self.started = monotonic()
        self.pages = []
        self.image_ids = {}
        self.texts = []
        self.ocr_ran = False
        self.visual = None
        self.model = None
        self.additional_checks = {}

    def image(self, image):
        key = id(image)
        if key not in self.image_ids:
            height, width = image.shape[:2]
            # Bound debug previews; boxes remain in original pixel coordinates.
            scale = min(1, 1000 / max(width, height))
            preview = cv2.resize(image, (max(1, round(width * scale)), max(1, round(height * scale)))) if scale < 1 else image
            self.image_ids[key] = len(self.pages)
            self.pages.append({"page": len(self.pages) + 1, "width": width, "height": height,
                               "preview": encode_image_as_data_url(preview), "ocr_lines": [],
                               "visual_state": "not_checked", "detections": [],
                               "reference_regions": [], "reference_state": "not_checked"})
        return self.pages[self.image_ids[key]]

    def text(self, lines, image=None):
        self.ocr_ran = True
        evidence = [{"text": line.text, "confidence": float(line.confidence)} for line in lines]
        if image is not None:
            self.image(image)["ocr_lines"] = evidence
        self.texts.extend(evidence)

    def snapshot(self, response):
        def check(state, reason):
            return {"state": state, "reason": reason}

        checks = {
            "name": check("present" if response.extracted_name else "absent" if self.ocr_ran else "not_checked",
                          "Extracted from document text." if response.extracted_name else "No unambiguous full name extracted."),
            "document_number": check("present" if response.extracted_document_number else "absent" if self.ocr_ran else "not_checked",
                                     "Extracted from document text." if response.extracted_document_number else "No unambiguous identification number extracted."),
        }
        checks.update(self.additional_checks)
        wording = [line for line in self.texts if line["confidence"] >= .45 and re.search(r"\bCIDB\b", line["text"], re.I)]
        checks["cidb_wording"] = check("present" if wording else "absent" if self.ocr_ran else "not_checked",
            "CIDB wording found in original OCR text; informational only." if wording else "CIDB wording not detected in OCR text; not required for approval.")
        checks["cidb_wording"]["evidence"] = wording
        for label, quality in (response.images_quality or {}).items():
            checks["quality_" + label] = check("passed" if quality.passed else "failed", "; ".join(quality.issues) or "Blur check passed.")
        for label, document in (response.documents or {}).items():
            checks["document_" + label] = check("passed" if document.passed else "failed", "Document recognition score: " + str(document.score))
        for name in ("cross_out", "customer_signature", "embedded_signature"):
            checks[name] = check("not_checked", "Visual checks were not run because an earlier check stopped verification.")
        if self.visual:
            for name in ("cross_out", "customer_signature"):
                state = self.visual[name]
                reason = {"present": "Affirmative visual evidence found; see per-page candidates and exclusions.",
                          "absent": "No qualifying visual evidence detected.",
                          "uncertain": "Evidence or reference matching could not establish this check confidently.",
                          "error": "Visual inspection was unavailable or failed; no approval is inferred."}[state]
                checks[name] = check(state, reason)
            embedded = [page.get("embedded_signature") for page in self.pages if page.get("embedded_signature")]
            state = ("error" if self.visual["cross_out"] == "error" else
                     "present" if "present" in embedded else
                     "uncertain" if "uncertain" in embedded or self.visual["pages_checked"] != len(self.pages) else
                     "absent" if embedded else "not_checked")
            checks["embedded_signature"] = check(state, "Detector evidence only. Expected reference regions are shown separately and do not prove a visible signature.")
            if self.model and self.model.get("method") == "opencv_crossout":
                reason = "Not implemented in the OpenCV cross-out stage. This mandatory signature check cannot pass; automatic approval is withheld."
                checks["customer_signature"] = check("not_checked", reason)
                checks["embedded_signature"] = check("not_checked", "Embedded-signature detection and reference matching are not run in this stage.")
                if self.visual["cross_out"] != "error":
                    checks["cross_out"]["reason"] = " ".join(
                        f"Page {page['page']}: {reason}" for page in self.pages for reason in page.get("cross_out_reasons", [])) or checks["cross_out"]["reason"]
        checks["overall"] = check("passed" if response.verified else "failed", response.message)
        return {"checks": checks, "pages": self.pages, "model": self.model,
                "pages_checked": self.visual["pages_checked"] if self.visual else 0,
                "processing_ms": round((monotonic() - self.started) * 1000, 1)}


def attach(response):
    observer = current.get()
    if observer is not None:
        response.diagnostics = observer.snapshot(response)
    return response
