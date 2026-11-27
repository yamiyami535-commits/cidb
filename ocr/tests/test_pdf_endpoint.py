from io import BytesIO
import unittest
from unittest.mock import AsyncMock, patch

import cv2
import numpy as np
from fastapi import HTTPException, UploadFile
from fastapi.testclient import TestClient

from endpoint_support import main
from app.models import OCRLine, QualityResult, VerificationResponse
from app.pdf_input import PdfInputError
from app.visual_types import Evidence, VisualResult


def lines(*texts):
    return [OCRLine(text=text, confidence=.99) for text in texts]


FRONT = lines("MALAYSIA", "KAD PENGENALAN", "900101-10-1234", "SAMPLE CUSTOMER")
BACK = lines("KETUA PENGARAH", "PENDAFTARAN NEGARA", "MALAYSIA")
PASSPORT = lines("MALAYSIA", "PASSPORT", "A12345678", "SAMPLE CUSTOMER")
QUALITY = QualityResult(passed=True, width=800, height=600, blur_score=120,
                        brightness=130, dark_pixel_percentage=1, bright_pixel_percentage=1)
IMAGE = np.full((600, 800, 3), 240, np.uint8)
PNG = cv2.imencode(".png", IMAGE)[1].tobytes()


class EndpointTests(unittest.TestCase):
    def request(self, route, state, *, ocr=None, pages=None, data=None, raises=False, diagnostics=False):
        name = dict(data or {})
        page_count = 1
        if route == "mykad":
            url = "/api/verify-document"
            name["document_type"] = "mykad"
            files = {"mykad_front": ("front.png", PNG, "image/png"), "mykad_back": ("back.png", PNG, "image/png")}
            outputs, page_count = [FRONT, BACK], 2
        elif route == "passport":
            url = "/api/verify-document"
            name.update(document_type="passport")
            files = {"passport_image": ("passport.png", PNG, "image/png")}
            outputs = [PASSPORT]
        elif route == "image":
            url = "/api/verify-mykad-image"
            files = {"image_file": ("card.png", PNG, "image/png")}
            outputs = [FRONT]
        else:
            url = "/api/verify-cancellation-form-pdf" if route == "form" else "/api/verify-mykad-pdf"
            files = {"document_pdf": ("card.pdf", b"%PDF-test", "application/pdf")}
            page_count = 2 if route == "pdf2" else 1
            outputs = [FRONT, BACK] if page_count == 2 else [FRONT]
        result = VisualResult(*state, pages if pages is not None else page_count)
        with (
            patch.object(main.settings, "api_key", ""),
            patch.object(main, "run_ocr", side_effect=ocr if ocr is not None else outputs),
            patch.object(main, "check_image_quality", return_value=QUALITY),
            patch.object(main, "render_pdf_pages", return_value=[PNG] * page_count),
            patch.object(main.visual_service, "inspect", side_effect=RuntimeError("test") if raises else None, return_value=result) as inspect,
        ):
            response = TestClient(main.app).post(url, data=name, files=files,
                                                headers={"X-OCR-Diagnostics": "1"} if diagnostics else {})
        return response, inspect

    def test_all_routes_require_both_visual_conditions(self):
        for route in ("mykad", "passport", "image", "form", "pdf1", "pdf2"):
            for cross, signature, expected_status in (
                (Evidence.PRESENT, Evidence.PRESENT, None),
                (Evidence.ABSENT, Evidence.PRESENT, "INVALID_DOCUMENT"),
                (Evidence.PRESENT, Evidence.ABSENT, "INVALID_DOCUMENT"),
                (Evidence.UNCERTAIN, Evidence.PRESENT, "MANUAL_REVIEW"),
                (Evidence.PRESENT, Evidence.UNCERTAIN, "MANUAL_REVIEW"),
                (Evidence.ERROR, Evidence.PRESENT, "INTERNAL_ERROR"),
            ):
                with self.subTest(route=route, cross=cross, signature=signature):
                    response, inspect = self.request(route, (cross, signature))
                    self.assertEqual(response.status_code, 200)
                    body = response.json()
                    self.assertEqual(body["verified"], expected_status is None)
                    self.assertEqual(body["extracted_name"], "SAMPLE CUSTOMER")
                    self.assertEqual(body["extracted_document_number"],
                                     "A12345678" if route == "passport" else "900101-10-1234")
                    self.assertNotIn("comparison", body)
                    if expected_status:
                        self.assertEqual(body["status"], expected_status)
                    inspect.assert_called_once()
                    for image in inspect.call_args.args[0]:
                        np.testing.assert_array_equal(image, IMAGE)
                    self.assertLessEqual(set(body), set(VerificationResponse.model_fields))

    def test_second_page_must_be_inspected(self):
        for route in ("mykad", "pdf2"):
            response, _ = self.request(route, (Evidence.PRESENT, Evidence.PRESENT), pages=1)
            self.assertFalse(response.json()["verified"])
            self.assertEqual(response.json()["status"], "MANUAL_REVIEW")

    def test_unexpected_visual_exception_never_preserves_approval(self):
        response, _ = self.request("image", (Evidence.PRESENT, Evidence.PRESENT), raises=True)
        self.assertFalse(response.json()["verified"])
        self.assertEqual(response.json()["status"], "INTERNAL_ERROR")

    def test_missing_extracted_identity_does_not_pass_with_valid_marks(self):
        for texts in (
            lines("MALAYSIA", "KAD PENGENALAN", "SAMPLE CUSTOMER"),
            lines("MALAYSIA", "KAD PENGENALAN", "900101-10-1234"),
        ):
            response, inspect = self.request("image", (Evidence.PRESENT, Evidence.PRESENT), ocr=[texts])
            self.assertFalse(response.json()["verified"])
            inspect.assert_not_called()

    def test_unanchored_text_cannot_be_used_as_name(self):
        response, inspect = self.request("image", (Evidence.PRESENT, Evidence.PRESENT),
                                         ocr=[lines("SAMPLE CUSTOMER", "MALAYSIA", "KAD PENGENALAN", "900101-10-1234")])
        self.assertFalse(response.json()["verified"])
        inspect.assert_not_called()

    def test_existing_extraction_fields_are_preserved(self):
        response, _ = self.request("image", (Evidence.PRESENT, Evidence.PRESENT))
        self.assertEqual(response.json()["extracted_document_number"], "900101-10-1234")
        self.assertEqual(response.json()["extracted_name"], "SAMPLE CUSTOMER")
        self.assertEqual(response.json()["extracted_document_number_masked"], "********1234")

    def test_optional_nric_uses_extracted_number(self):
        response, _ = self.request("form", (Evidence.PRESENT, Evidence.PRESENT), data={"entered_nric": ""})
        self.assertTrue(response.json()["verified"])

    def test_legacy_customer_values_cannot_change_any_route_result(self):
        for route in ("mykad", "passport", "image", "form", "pdf1", "pdf2"):
            baseline, _ = self.request(route, (Evidence.PRESENT, Evidence.PRESENT))
            for supplied in (
                {"entered_name": "DIFFERENT CUSTOMER", "entered_nric": "900101-10-1235", "entered_passport_number": "B87654321"},
                {"entered_name": "", "entered_nric": "not-an-id", "entered_passport_number": "!"},
            ):
                with self.subTest(route=route, supplied=supplied):
                    response, inspect = self.request(route, (Evidence.PRESENT, Evidence.PRESENT), data=supplied)
                    self.assertEqual(response.status_code, 200)
                    self.assertEqual(response.json(), baseline.json())
                    self.assertTrue(response.json()["verified"])
                    inspect.assert_called_once()

    def test_customer_fields_cannot_fill_missing_document_identity(self):
        for route in ("mykad", "passport", "image", "form", "pdf1", "pdf2"):
            for missing in ("name", "number"):
                texts = lines("MALAYSIA", "PASSPORT" if route == "passport" else "KAD PENGENALAN")
                if missing == "name":
                    texts += lines("A12345678" if route == "passport" else "900101-10-1234")
                else:
                    texts += lines("NAME: SAMPLE CUSTOMER")
                outputs = [texts, BACK] if route in ("mykad", "pdf2") else [texts]
                response, inspect = self.request(route, (Evidence.PRESENT, Evidence.PRESENT), ocr=outputs,
                    data={"entered_name": "SAMPLE CUSTOMER", "entered_nric": "900101-10-1234", "entered_passport_number": "A12345678"})
                with self.subTest(route=route, missing=missing):
                    self.assertFalse(response.json()["verified"])
                    self.assertNotIn("extracted_name" if missing == "name" else "extracted_document_number", response.json())
                    inspect.assert_not_called()

    def test_quality_failures_remain_unverified(self):
        with patch.object(QUALITY, "passed", False):
            response, inspect = self.request("mykad", (Evidence.PRESENT, Evidence.PRESENT))
        self.assertEqual(response.json()["status"], "REUPLOAD_REQUIRED")
        inspect.assert_not_called()

    def test_api_key_and_request_contract_are_unchanged(self):
        with patch.object(main.settings, "api_key", "test-secret"):
            response = TestClient(main.app).post("/api/verify-mykad-image")
        self.assertEqual(response.status_code, 401)
        self.assertFalse(response.json()["verified"])
        self.assertEqual(response.json()["status"], "INVALID_REQUEST")


class PdfInputEndpointTests(unittest.IsolatedAsyncioTestCase):
    def upload(self, mime="application/pdf"):
        return UploadFile(file=BytesIO(b"%PDF-test"), filename="test.pdf", headers={"content-type": mime})

    async def test_two_page_pdf_preserves_delegate_failure(self):
        failure = VerificationResponse(verified=False, status="INTERNAL_ERROR", message="Visual model unavailable.")
        with patch.object(main, "render_pdf_pages", return_value=[PNG, PNG]), patch.object(main, "verify_document", new_callable=AsyncMock, return_value=failure):
            result = await main.verify_mykad_pdf(self.upload(), "SAMPLE CUSTOMER", "900101-10-1234")
        self.assertIs(result, failure)
        self.assertEqual(result.status, "INTERNAL_ERROR")

    async def test_cancellation_form_rejects_two_pages(self):
        with patch.object(main, "render_pdf_pages", return_value=[PNG, PNG]):
            with self.assertRaises(HTTPException) as error:
                await main.verify_cancellation_form_pdf(self.upload(), None, None)
        self.assertEqual(error.exception.status_code, 400)

    async def test_renderer_errors_and_mime_errors_are_bad_requests(self):
        with patch.object(main, "render_pdf_pages", side_effect=PdfInputError("bad PDF")):
            with self.assertRaises(HTTPException) as error:
                await main.verify_mykad_pdf(self.upload(), "SAMPLE CUSTOMER", "900101-10-1234")
        self.assertEqual(error.exception.status_code, 400)
        with self.assertRaises(HTTPException) as error:
            await main.verify_mykad_pdf(self.upload("image/jpeg"), "SAMPLE CUSTOMER", "900101-10-1234")
        self.assertEqual(error.exception.status_code, 400)


if __name__ == "__main__":
    unittest.main()
