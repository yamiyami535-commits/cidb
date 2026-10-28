from io import BytesIO
import unittest
from unittest.mock import AsyncMock, patch

from fastapi import HTTPException, UploadFile

from app import main
from app.models import ComparisonResult, QualityResult, VerificationResponse
from app.pdf_input import PdfInputError


class PdfEndpointTests(unittest.IsolatedAsyncioTestCase):
    async def test_identity_match_is_downgraded_to_manual_review(self):
        uploaded_pdf = UploadFile(
            file=BytesIO(b"%PDF-test"),
            filename="mykad.pdf",
            headers={"content-type": "application/pdf"},
        )
        identity_result = VerificationResponse(
            verified=True,
            status="VERIFIED",
            message="Identity details matched.",
        )

        with (
            patch(
                "app.main.render_pdf_pages",
                return_value=[b"front-image", b"back-image"],
            ),
            patch(
                "app.main.verify_document",
                new_callable=AsyncMock,
                return_value=identity_result,
            ) as verify_document,
        ):
            result = await main.verify_mykad_pdf(
                uploaded_pdf,
                "Sample Customer",
                "900101-10-1234",
            )

        self.assertEqual(result.status, "MANUAL_REVIEW")
        self.assertFalse(result.verified)
        self.assertIn("cross-out", result.message)
        self.assertIn("signature", result.message)
        self.assertIn("stamp", result.message)
        verify_document.assert_awaited_once()
        self.assertEqual(verify_document.await_args.kwargs["entered_name"], "Sample Customer")
        self.assertEqual(verify_document.await_args.kwargs["entered_nric"], "900101-10-1234")

    async def test_single_page_form_always_requires_manual_review(self):
        uploaded_pdf = UploadFile(
            file=BytesIO(b"%PDF-test"),
            filename="cancellation-form.pdf",
            headers={"content-type": "application/pdf"},
        )
        form_quality = QualityResult(
            passed=True,
            width=1600,
            height=2200,
            blur_score=120,
            brightness=130,
            dark_pixel_percentage=1,
            bright_pixel_percentage=1,
        )
        comparison = ComparisonResult(
            entered_document_number_valid=True,
            document_number_match=True,
            name_match=True,
        )
        ocr_line = object()

        with (
            patch("app.main.render_pdf_pages", return_value=[b"form-image"]),
            patch("app.main.decode_image", return_value="decoded-form"),
            patch("app.main.check_image_quality", return_value=form_quality),
            patch("app.main.run_ocr", return_value=[ocr_line]),
            patch("app.main.extract_nric", return_value="900101-10-1234"),
            patch("app.main.compare_mykad_details", return_value=comparison),
            patch("app.main.average_ocr_confidence", return_value=0.91),
        ):
            result = await main.verify_mykad_pdf(
                uploaded_pdf,
                "Sample Customer",
                "900101-10-1234",
            )

        self.assertEqual(result.status, "MANUAL_REVIEW")
        self.assertFalse(result.verified)
        self.assertEqual(result.document_type, "cancellation_form")
        self.assertIsNone(result.extracted_text_lines)
        self.assertEqual(
            result.images_quality["cancellation_form"].passed,
            form_quality.passed,
        )
        self.assertTrue(result.comparison.document_number_match)
        self.assertEqual(result.ocr_average_confidence, 0.91)
        self.assertIn("marked/crossed-out IC and signature", result.message)

    async def test_cancellation_form_endpoint_accepts_single_page_without_expected_nric(self):
        uploaded_pdf = UploadFile(
            file=BytesIO(b"%PDF-test"),
            filename="cancellation-form.pdf",
            headers={"content-type": "application/pdf"},
        )
        form_quality = QualityResult(
            passed=True,
            width=1600,
            height=2200,
            blur_score=120,
            brightness=130,
            dark_pixel_percentage=1,
            bright_pixel_percentage=1,
        )

        with (
            patch("app.main.render_pdf_pages", return_value=[b"form-image"]),
            patch("app.main.decode_image", return_value="decoded-form"),
            patch("app.main.check_image_quality", return_value=form_quality),
            patch("app.main.run_ocr", return_value=[object()]),
            patch("app.main.extract_nric", return_value="900101-10-1234"),
            patch("app.main.average_ocr_confidence", return_value=0.91),
        ):
            result = await main.verify_cancellation_form_pdf(uploaded_pdf)

        self.assertEqual(result.status, "MANUAL_REVIEW")
        self.assertFalse(result.verified)
        self.assertEqual(result.document_type, "cancellation_form")
        self.assertIsNone(result.comparison)
        self.assertIsNone(result.extracted_text_lines)
        self.assertEqual(
            result.extracted_document_number_masked,
            "********1234",
        )
        self.assertIn("does not verify those marks", result.message)

    async def test_cancellation_form_endpoint_rejects_two_page_pdf(self):
        uploaded_pdf = UploadFile(
            file=BytesIO(b"%PDF-test"),
            filename="two-page.pdf",
            headers={"content-type": "application/pdf"},
        )

        with patch(
            "app.main.render_pdf_pages",
            return_value=[b"front", b"back"],
        ):
            with self.assertRaises(HTTPException) as raised:
                await main.verify_cancellation_form_pdf(uploaded_pdf)

        self.assertEqual(raised.exception.status_code, 400)
        self.assertIn("exactly one page", str(raised.exception.detail))

    async def test_pdf_renderer_rejection_is_returned_as_bad_request(self):
        uploaded_pdf = UploadFile(
            file=BytesIO(b"%PDF-test"),
            filename="wrong-pages.pdf",
            headers={"content-type": "application/pdf"},
        )

        with patch(
            "app.main.render_pdf_pages",
            side_effect=PdfInputError("A PDF must contain one cancellation-form page or two MyKad pages."),
        ):
            with self.assertRaises(HTTPException) as raised:
                await main.verify_mykad_pdf(
                    uploaded_pdf,
                    "Sample Customer",
                    "900101-10-1234",
                )

        self.assertEqual(raised.exception.status_code, 400)
        self.assertIn("one cancellation-form page", str(raised.exception.detail))

    async def test_rejects_non_pdf_upload_content_type(self):
        uploaded_image = UploadFile(
            file=BytesIO(b"image-bytes"),
            filename="mykad.jpg",
            headers={"content-type": "image/jpeg"},
        )

        with self.assertRaises(HTTPException) as raised:
            await main.verify_mykad_pdf(
                uploaded_image,
                "Sample Customer",
                "900101-10-1234",
            )

        self.assertEqual(raised.exception.status_code, 400)

    async def test_api_key_is_required_when_configured(self):
        original_key = main.settings.api_key
        main.settings.api_key = "test-only-secret"
        try:
            with self.assertRaises(HTTPException) as raised:
                await main.require_ocr_api_key("wrong-secret")
            self.assertEqual(raised.exception.status_code, 401)
            await main.require_ocr_api_key("test-only-secret")
        finally:
            main.settings.api_key = original_key


if __name__ == "__main__":
    unittest.main()
