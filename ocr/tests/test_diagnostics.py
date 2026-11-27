import unittest
from time import monotonic
from types import SimpleNamespace
from unittest.mock import Mock, patch

from endpoint_support import main
import test_pdf_endpoint as endpoints
from test_pdf_endpoint import FRONT, BACK, IMAGE, QUALITY, lines
from app.diagnostics import Diagnostics, current
from app.visual_types import Detection, Evidence
from app.visual_validation import evaluate_page, VisualValidationService
from app.config import Settings


class DiagnosticEndpointTests(unittest.TestCase):
    request = endpoints.EndpointTests.request

    def test_opt_in_preserves_all_route_outcomes_and_only_adds_diagnostics(self):
        for route in ("mykad", "passport", "image", "form", "pdf1", "pdf2"):
            for state in ((Evidence.PRESENT, Evidence.PRESENT), (Evidence.ABSENT, Evidence.PRESENT), (Evidence.ERROR, Evidence.ERROR)):
                with self.subTest(route=route, state=state):
                    normal, normal_inspect = self.request(route, state)
                    detailed, detailed_inspect = self.request(route, state, diagnostics=True)
                    body = detailed.json()
                    evidence = body.pop("diagnostics")
                    self.assertEqual(body, normal.json())
                    self.assertEqual(evidence["checks"]["cross_out"]["state"], state[0].value)
                    self.assertEqual(evidence["checks"]["customer_signature"]["state"], state[1].value)
                    self.assertEqual(detailed.headers["cache-control"], "no-store")
                    normal_inspect.assert_called_once()
                    detailed_inspect.assert_called_once()

    def test_wording_is_informational_and_request_observations_do_not_leak(self):
        marked = FRONT + lines("FOR CIDB USE ONLY")
        response, _ = self.request("mykad", (Evidence.PRESENT, Evidence.PRESENT), ocr=[marked, BACK], diagnostics=True)
        self.assertTrue(response.json()["verified"])
        self.assertEqual(response.json()["diagnostics"]["checks"]["cidb_wording"]["state"], "present")
        response, _ = self.request("mykad", (Evidence.PRESENT, Evidence.PRESENT), diagnostics=True)
        self.assertEqual(response.json()["diagnostics"]["checks"]["cidb_wording"]["state"], "absent")
        normal, _ = self.request("mykad", (Evidence.PRESENT, Evidence.PRESENT))
        self.assertNotIn("diagnostics", normal.json())
        self.assertIsNone(current.get())

    def test_early_exit_reports_not_checked_not_absent(self):
        with patch.object(QUALITY, "passed", False):
            result, inspect = self.request("mykad", (Evidence.PRESENT, Evidence.PRESENT), diagnostics=True)
        checks = result.json()["diagnostics"]["checks"]
        self.assertEqual(checks["cross_out"]["state"], "not_checked")
        self.assertEqual(checks["name"]["state"], "not_checked")
        inspect.assert_not_called()

    def test_missing_identity_and_visual_exception_report_correctly(self):
        response, inspect = self.request("image", (Evidence.PRESENT, Evidence.PRESENT), ocr=[lines("MALAYSIA", "900101101234")], diagnostics=True)
        self.assertEqual(response.json()["diagnostics"]["checks"]["name"]["state"], "absent")
        self.assertEqual(response.json()["diagnostics"]["checks"]["cross_out"]["state"], "not_checked")
        inspect.assert_not_called()
        response, _ = self.request("image", (Evidence.PRESENT, Evidence.PRESENT), raises=True, diagnostics=True)
        self.assertEqual(response.json()["diagnostics"]["checks"]["cross_out"]["state"], "error")

    def test_ocr_text_and_preview_are_original_not_service_watermark(self):
        observer = Diagnostics()
        token = current.set(observer)
        try:
            with patch.object(main.ocr_service, "recognize", return_value=FRONT):
                self.assertEqual(main.run_ocr(IMAGE), FRONT)
            self.assertEqual(observer.pages[0]["ocr_lines"][0]["text"], "MALAYSIA")
            self.assertTrue(observer.pages[0]["preview"].startswith("data:image/"))
            self.assertFalse(any("CIDB" in item["text"] for item in observer.texts))
        finally:
            current.reset(token)


class VisualDiagnosticTests(unittest.TestCase):
    def test_reference_regions_and_reasons_are_observed_without_extra_matching(self):
        card = Detection("mykad_back", .99, (0, 0, 700, 500))
        signature = Detection("customer_signature", .99, (410, 260, 540, 310))
        reference = Mock()
        reference.match.return_value = [(400, 250, 550, 320)]
        details = {}
        result = evaluate_page(IMAGE, [card, signature], reference, .85, monotonic()+10, details)
        self.assertEqual(result[1], Evidence.UNCERTAIN)
        self.assertEqual(details["reference_regions"], reference.match.return_value)
        self.assertIn("overlaps", " ".join(details["signature_reasons"]))
        reference.match.assert_called_once()

    def test_page_evidence_comes_from_same_detector_pass(self):
        service = VisualValidationService(Settings(visual_manifest_path=""))
        service._attempted = True
        detector = Mock(return_value=[Detection("embedded_signature", .99, (20, 30, 40, 50))])
        service.detector = SimpleNamespace(detect=detector, references=Mock(),
            manifest=SimpleNamespace(present_threshold=.85, release_id="synthetic", evaluation_id="test"))
        observer = Diagnostics()
        token = current.set(observer)
        try:
            result = service.inspect([IMAGE])
            detector.assert_called_once()
            self.assertEqual(result.signature, Evidence.ABSENT)
            self.assertEqual(observer.pages[0]["embedded_signature"], "present")
            self.assertEqual(observer.pages[0]["reference_state"], "not_checked")
            self.assertEqual(observer.model["release_id"], "synthetic")
        finally:
            current.reset(token)
