"""Synthetic geometry verifies rules/integration, not real-document accuracy."""
from time import monotonic
from io import BytesIO
import unittest
from unittest.mock import patch

import cv2
import numpy as np
from fastapi.testclient import TestClient
from PIL import Image

from app.config import Settings
from app.models import OCRLine
from app.cross_out_detection import OpenCVCrossOutDetector
from app.diagnostics import Diagnostics, current
from app.visual_types import Evidence
from app.visual_validation import VisualValidationService
from endpoint_support import main
from test_pdf_endpoint import FRONT, BACK, QUALITY


def page():
    return np.full((600, 800, 3), 245, np.uint8)


def marked(wording=False, paired=False, color=(10, 10, 10)):
    image = page()
    cv2.line(image, (80, 350), (390, 70), color, 3, cv2.LINE_AA)
    if paired:
        cv2.line(image, (120, 390), (430, 110), color, 3, cv2.LINE_AA)
    if wording:
        cv2.putText(image, "FOR CIDB USE ONLY", (70, 450), cv2.FONT_HERSHEY_SIMPLEX, .8, (0, 0, 0), 2)
    return image


def dense_card(with_strokes=True):
    """Non-ID fixture: reported page dimensions, many tiny security-like details."""
    image = np.full((577, 874, 3), 245, np.uint8)
    cv2.rectangle(image, (85, 95), (790, 515), (225, 185, 145), -1)
    for y in range(110, 505, 8):
        for x in range(100, 780, 8):
            cv2.circle(image, (x, y), 1, (70, 70, 70), -1)
    if with_strokes:
        cv2.line(image, (15, 210), (210, 15), (10, 10, 10), 3, cv2.LINE_AA)
        cv2.line(image, (40, 250), (250, 40), (10, 10, 10), 3, cv2.LINE_AA)
    return image


class CrossOutDetectionTests(unittest.TestCase):
    def detect(self, image):
        return OpenCVCrossOutDetector().detect(image, monotonic()+10)

    def test_plain_lines_with_or_without_wording_and_multiple_strokes(self):
        for wording in (False, True):
            for paired in (False, True):
                with self.subTest(wording=wording, paired=paired):
                    image = marked(wording, paired)
                    before = image.copy()
                    result = self.detect(image)
                    self.assertEqual(result.state, Evidence.PRESENT)
                    self.assertTrue(any(item['state'] == 'present' for item in result.detections))
                    self.assertTrue(all('score' not in item for item in result.detections))
                    np.testing.assert_array_equal(image, before)

    def test_cidb_wording_alone_cannot_satisfy_cross_out_even_rotated(self):
        image = page()
        cv2.putText(image, "FOR CIDB USE ONLY", (100, 310), cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 0, 0), 2)
        for angle in (0, 25, 45, 90):
            rotated = cv2.warpAffine(image, cv2.getRotationMatrix2D((400, 300), angle, 1),
                                     (800, 600), borderValue=(245, 245, 245))
            with self.subTest(angle=angle):
                self.assertNotEqual(self.detect(rotated).state, Evidence.PRESENT)

    def test_unmarked_page_has_no_evidence(self):
        result = self.detect(page())
        self.assertEqual(result.state, Evidence.ABSENT)
        self.assertEqual(result.detections, [])

    def test_dense_security_pattern_does_not_block_two_prominent_cross_outs(self):
        image = dense_card()
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        binary = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY_INV, 31, 8)
        binary = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, np.ones((3, 3), np.uint8))
        contours, _ = cv2.findContours(binary, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
        self.assertGreater(len(contours), 2000, 'Must reproduce the old dense-contour rejection')
        result = self.detect(image)
        self.assertEqual(result.state, Evidence.PRESENT)
        present = [item for item in result.detections if item['state'] == 'present']
        self.assertEqual(len(present), 2)
        self.assertTrue(all(max(item['line']) < 280 for item in present))

    def test_dense_unmarked_pattern_and_printed_graphic_are_not_cross_outs(self):
        self.assertNotEqual(self.detect(dense_card(False)).state, Evidence.PRESENT)
        graphic = page()
        cv2.polylines(graphic, [np.array([[100, 400], [400, 100], [500, 400]])], True, (0, 0, 0), 3)
        self.assertNotEqual(self.detect(graphic).state, Evidence.PRESENT)

    def test_repeated_diagonal_hatching_remains_uncertain(self):
        pattern = page()
        for x in range(100, 301, 25):
            cv2.line(pattern, (x, 400), (x+250, 150), (0, 0, 0), 2)
        self.assertNotEqual(self.detect(pattern).state, Evidence.PRESENT)

    def test_duplicate_hough_lines_do_not_exhaust_the_plausible_candidate_budget(self):
        duplicates = np.tile(np.array([[[80, 350, 390, 70]]], np.int32), (600, 1, 1))
        with patch('app.cross_out_detection.cv2.HoughLinesP', return_value=duplicates):
            result = self.detect(marked())
        self.assertEqual(result.state, Evidence.PRESENT)
        self.assertEqual(sum(item['state'] == 'present' for item in result.detections), 1)

    def test_significant_contour_budget_still_fails_closed(self):
        detector = OpenCVCrossOutDetector()
        detector.max_contours = 0
        self.assertEqual(detector.detect(marked(), monotonic()+10).state, Evidence.UNCERTAIN)

    def test_weak_long_hough_extension_does_not_hide_the_continuous_stroke(self):
        candidates = np.array([[[10, 413, 460, 7]], [[80, 350, 390, 70]]], np.int32)
        with patch('app.cross_out_detection.cv2.HoughLinesP', return_value=candidates):
            self.assertEqual(self.detect(marked()).state, Evidence.PRESENT)

    def test_position_color_rotation_and_resizing_preserve_clear_stroke(self):
        for color in ((10, 10, 10), (170, 20, 20), (20, 20, 170)):
            image = marked(color=color)
            for angle in (0, 90, 180):
                rotated = np.rot90(image, angle//90).copy()
                for size in (1, 2):
                    with self.subTest(color=color, angle=angle, size=size):
                        resized = cv2.resize(rotated, None, fx=size, fy=size)
                        result = self.detect(resized)
                        self.assertEqual(result.state, Evidence.PRESENT)
                        for item in result.detections:
                            x1, y1, x2, y2 = item['box']
                            self.assertTrue(0 <= x1 < x2 <= resized.shape[1])
                            self.assertTrue(0 <= y1 < y2 <= resized.shape[0])

    def test_short_faint_axis_aligned_and_interrupted_lines_are_not_affirmative(self):
        short, faint, horizontal, vertical, dashed = [page() for _ in range(5)]
        cv2.line(short, (200, 200), (275, 125), (0, 0, 0), 2)
        cv2.line(faint, (80, 350), (390, 70), (210, 210, 210), 2)
        cv2.line(horizontal, (100, 300), (700, 300), (0, 0, 0), 2)
        cv2.line(vertical, (300, 100), (300, 500), (0, 0, 0), 2)
        for start in range(100, 400, 20):
            cv2.line(dashed, (start, start), (start+9, start+9), (0, 0, 0), 2)
        for name, image in zip(('short', 'faint', 'horizontal', 'vertical', 'dashed'),
                               (short, faint, horizontal, vertical, dashed)):
            with self.subTest(name=name):
                self.assertNotEqual(self.detect(image).state, Evidence.PRESENT)
        self.assertEqual(self.detect(horizontal).state, Evidence.UNCERTAIN)

    def test_card_frames_rotated_frames_and_form_grid_do_not_pass(self):
        image = page()
        cv2.rectangle(image, (100, 150), (700, 450), (0, 0, 0), 3)
        rotated = cv2.warpAffine(image, cv2.getRotationMatrix2D((400, 300), 20, 1),
                                 (800, 600), borderValue=(245, 245, 245))
        grid = page()
        for y in range(100, 501, 80):
            cv2.line(grid, (100, y), (700, y), (0, 0, 0), 2)
        for x in range(100, 701, 150):
            cv2.line(grid, (x, 100), (x, 500), (0, 0, 0), 2)
        for item in (image, rotated, grid):
            self.assertNotEqual(self.detect(item).state, Evidence.PRESENT)

    def test_clear_stroke_over_card_frame_is_still_found(self):
        image = marked()
        cv2.rectangle(image, (100, 150), (700, 450), (0, 0, 0), 2)
        self.assertEqual(self.detect(image).state, Evidence.PRESENT)

    def test_deadline_bad_input_and_resolution_limits(self):
        detector = OpenCVCrossOutDetector()
        with self.assertRaises(TimeoutError):
            detector.detect(marked(), monotonic()-1)
        with self.assertRaises(ValueError):
            detector.detect(np.zeros((50, 50), np.uint8), monotonic()+10)
        self.assertEqual(self.detect(np.zeros((30, 30, 3), np.uint8)).state, Evidence.UNCERTAIN)
        detector.max_lines = 0
        self.assertEqual(detector.detect(marked(), monotonic()+10).state, Evidence.UNCERTAIN)


class CrossOutServiceTests(unittest.TestCase):
    def service(self):
        return VisualValidationService(Settings(visual_backend="opencv_crossout", visual_manifest_path=""))

    def test_no_model_or_reference_required_and_second_page_is_inspected(self):
        service = self.service()
        with patch('app.visual_validation.OnnxDetector', side_effect=AssertionError('ONNX must not load')):
            result = service.inspect([page(), marked()])
        self.assertEqual(result.cross_out, Evidence.PRESENT)
        self.assertEqual(result.signature, Evidence.UNCERTAIN)
        self.assertEqual(result.pages_checked, 2)

    def test_late_page_error_is_not_hidden_by_first_page_evidence(self):
        service = self.service()
        result = service.inspect([marked(), np.zeros((10, 10), np.uint8)])
        self.assertEqual(result.cross_out, Evidence.ERROR)
        self.assertEqual(result.pages_checked, 1)
        self.assertEqual(result.signature, Evidence.UNCERTAIN)

    def test_timeout_has_no_successful_page_and_never_returns_signature_pass(self):
        service = self.service()
        service.initialize()
        with patch.object(service.detector, 'detect', side_effect=TimeoutError):
            result = service.inspect([marked()])
        self.assertEqual(result.cross_out, Evidence.ERROR)
        self.assertEqual(result.pages_checked, 0)
        self.assertNotEqual(result.signature, Evidence.PRESENT)

    def test_existing_onnx_mode_does_not_silently_fall_back(self):
        service = VisualValidationService(Settings(visual_backend="onnx", visual_manifest_path=""))
        self.assertEqual(service.inspect([marked()]).cross_out, Evidence.ERROR)

    def test_original_coordinate_evidence_and_signature_not_checked_are_reported(self):
        observer = Diagnostics()
        token = current.set(observer)
        try:
            self.service().inspect([marked(), page()])
        finally:
            current.reset(token)
        self.assertEqual(observer.model['method'], 'opencv_crossout')
        self.assertEqual(len(observer.pages), 2)
        self.assertEqual(observer.pages[0]['customer_signature'], 'not_checked')
        self.assertEqual(observer.pages[0]['reference_state'], 'not_checked')
        self.assertTrue(observer.pages[0]['detections'])
        self.assertEqual(observer.pages[1]['cross_out'], 'absent')

    def test_actual_endpoint_returns_cross_out_evidence_without_approval_or_contract_change(self):
        # Real decoding, cross-out detector, extraction and verification. Mock only
        # OCR text/quality; no Paddle download, customer data, DB or external calls.
        service = self.service()
        files = {'mykad_front': ('front.png', cv2.imencode('.png', page())[1].tobytes(), 'image/png'),
                 'mykad_back': ('back.png', cv2.imencode('.png', marked())[1].tobytes(), 'image/png')}
        with (patch.object(main, 'visual_service', service),
              patch.object(main.settings, 'api_key', ''),
              patch.object(main.ocr_service, 'recognize', side_effect=[FRONT, BACK, FRONT, BACK]),
              patch.object(main, 'check_image_quality', return_value=QUALITY)):
            client = TestClient(main.app)
            normal = client.post('/api/verify-document', data={'document_type': 'mykad'}, files=files).json()
            detailed = client.post('/api/verify-document', data={'document_type': 'mykad'}, files=files,
                                   headers={'X-OCR-Diagnostics': '1'}).json()
        diagnostics = detailed.pop('diagnostics')
        self.assertEqual(normal, detailed)
        self.assertFalse(normal['verified'])
        self.assertEqual(normal['status'], 'MANUAL_REVIEW')
        self.assertEqual(normal['extracted_name'], 'SAMPLE CUSTOMER')
        self.assertEqual(normal['extracted_document_number'], '900101-10-1234')
        self.assertEqual(diagnostics['pages_checked'], 2)
        self.assertEqual(diagnostics['checks']['cross_out']['state'], 'present')
        self.assertEqual(diagnostics['checks']['customer_signature']['state'], 'not_checked')
        self.assertEqual(diagnostics['checks']['embedded_signature']['state'], 'not_checked')
        self.assertEqual(diagnostics['pages'][1]['cross_out'], 'present')

    def test_reported_name_and_dense_card_case_through_existing_endpoint_stays_unverified(self):
        texts = ['MyKad', 'CIDB USE ONLY', 'IDEN', 'CARD', '900101-10-1234',
                 'OR', 'AZWANITA BINTI', 'BAHARUDIN', 'GDWKAMPUNGBAYANGAN', '8000OKENINGAU', 'SABAH']
        front = [OCRLine(text=text, confidence=.83 if text == 'OR' else .99) for text in texts]
        files = {'mykad_front': ('front.png', cv2.imencode('.png', dense_card())[1].tobytes(), 'image/png'),
                 'mykad_back': ('back.png', cv2.imencode('.png', marked())[1].tobytes(), 'image/png')}
        with (patch.object(main, 'visual_service', self.service()),
              patch.object(main.settings, 'api_key', ''),
              patch.object(main.ocr_service, 'recognize', side_effect=[front, BACK]),
              patch.object(main, 'check_image_quality', return_value=QUALITY)):
            body = TestClient(main.app).post('/api/verify-document', data={'document_type': 'mykad'},
                files=files, headers={'X-OCR-Diagnostics': '1'}).json()
        self.assertFalse(body['verified'])
        self.assertEqual(body['status'], 'MANUAL_REVIEW')
        self.assertEqual(body['extracted_name'], 'AZWANITA BINTI BAHARUDIN')
        self.assertEqual(body['extracted_document_number'], '900101-10-1234')
        self.assertEqual(body['diagnostics']['pages_checked'], 2)
        self.assertEqual(body['diagnostics']['model']['release_id'], 'opencv-crossout-v3')
        self.assertTrue(all(item['cross_out'] == 'present' for item in body['diagnostics']['pages']))
        self.assertEqual(body['diagnostics']['checks']['customer_signature']['state'], 'not_checked')

    def test_missing_ambiguous_and_failed_cross_out_use_existing_rejection_statuses(self):
        ambiguous = page()
        cv2.line(ambiguous, (100, 300), (700, 300), (0, 0, 0), 2)
        for image, failure, expected, state in (
            (page(), False, 'INVALID_DOCUMENT', 'absent'),
            (ambiguous, False, 'MANUAL_REVIEW', 'uncertain'),
            (marked(), True, 'INTERNAL_ERROR', 'error'),
        ):
            with self.subTest(state=state):
                service = self.service()
                service.initialize()
                original = service.detector.detect
                with (patch.object(main, 'visual_service', service),
                      patch.object(main.settings, 'api_key', ''),
                      patch.object(main.ocr_service, 'recognize', return_value=FRONT),
                      patch.object(main, 'check_image_quality', return_value=QUALITY),
                      patch.object(service.detector, 'detect', side_effect=TimeoutError if failure else original)):
                    response = TestClient(main.app).post('/api/verify-mykad-image',
                        files={'image_file': ('card.png', cv2.imencode('.png', image)[1].tobytes(), 'image/png')},
                        headers={'X-OCR-Diagnostics': '1'}).json()
                self.assertFalse(response['verified'])
                self.assertEqual(response['status'], expected)
                self.assertEqual(response['diagnostics']['checks']['cross_out']['state'], state)
                self.assertEqual(response['diagnostics']['checks']['customer_signature']['state'], 'not_checked')

    def test_real_two_page_pdf_rendering_inspects_mark_on_second_page(self):
        pdf = BytesIO()
        Image.fromarray(page()).save(pdf, format='PDF', save_all=True,
                                      append_images=[Image.fromarray(marked())])
        with (patch.object(main, 'visual_service', self.service()),
              patch.object(main.settings, 'api_key', ''),
              patch.object(main.ocr_service, 'recognize', side_effect=[FRONT, BACK]),
              patch.object(main, 'check_image_quality', return_value=QUALITY)):
            body = TestClient(main.app).post('/api/verify-mykad-pdf',
                files={'document_pdf': ('cards.pdf', pdf.getvalue(), 'application/pdf')},
                headers={'X-OCR-Diagnostics': '1'}).json()
        self.assertFalse(body['verified'])
        self.assertEqual(body['status'], 'MANUAL_REVIEW')
        self.assertEqual(body['diagnostics']['pages_checked'], 2)
        self.assertEqual(body['diagnostics']['pages'][0]['cross_out'], 'absent')
        self.assertEqual(body['diagnostics']['pages'][1]['cross_out'], 'present')


if __name__ == '__main__':
    unittest.main()
