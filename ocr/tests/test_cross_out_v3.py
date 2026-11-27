"""v3 regressions: reported numeric evidence + synthetic pixels, NOT real ICs.

The supplied response replaces previews with '[image shown separately]'. Tests
must not claim to replay that document's pixels. OCR/extraction are unchanged.
"""
from time import monotonic
import unittest
from unittest.mock import patch

import cv2
import numpy as np
from fastapi.testclient import TestClient

from app.cross_out_detection import OpenCVCrossOutDetector, length_evidence
from app.visual_types import Evidence
from app.visual_validation import VisualValidationService
from app.config import Settings
from endpoint_support import main
from test_pdf_endpoint import FRONT, QUALITY


def blank():
    return np.full((577, 874, 3), 255, np.uint8)


def front_strokes():
    image = blank()
    # First endpoints reproduce the reported 0.244-page-length candidate.
    cv2.line(image, (103, 114), (202, 14), (7, 7, 7), 2, cv2.LINE_AA)
    cv2.line(image, (138, 149), (237, 49), (7, 7, 7), 2, cv2.LINE_AA)
    return image


class CompactStrokeTests(unittest.TestCase):
    def detect(self, image):
        return OpenCVCrossOutDetector().detect(image, monotonic()+10)

    def test_reported_metrics_need_shape_and_direction_evidence_not_just_high_contrast(self):
        line = [103, 114, 202, 14]
        # Width is synthetic additional evidence: it was not in the old response.
        self.assertEqual(length_evidence(line, (248, 1., 0.), (3., 4.), 577), 'compact_strong_stroke')
        for appearance, widths in (((70, 1., 0.), (3., 4.)), ((248, .8, 0.), (3., 4.)),
                                   ((248, 1., .05), (3., 4.)), ((248, 1., 0.), (12., 20.))):
            self.assertIsNone(length_evidence(line, appearance, widths, 577))
        self.assertIsNone(length_evidence([100, 100, 240, 100], (248, 1., 0.), (3., 4.), 577))

    def test_two_compact_clear_diagonals_qualify_on_pixels_without_any_wording(self):
        for image in (front_strokes(), np.rot90(front_strokes()).copy(),
                      cv2.resize(front_strokes(), None, fx=2, fy=2)):
            with self.subTest(shape=image.shape):
                result = self.detect(image)
                accepted = [item for item in result.detections if item['state'] == 'present']
                self.assertEqual(result.state, Evidence.PRESENT)
                self.assertEqual(len(accepted), 2)
                self.assertTrue(all(item['metrics']['length_evidence'] == 'compact_strong_stroke' for item in accepted))
                self.assertTrue(all(item['display'] for item in accepted))

    def test_short_faint_or_interrupted_strokes_are_not_promoted(self):
        faint = blank()
        cv2.line(faint, (103, 114), (202, 14), (170, 170, 170), 2)
        gaps = front_strokes()
        cv2.rectangle(gaps, (100, 65), (250, 95), (255, 255, 255), -1)
        for image in (faint, gaps):
            self.assertNotEqual(self.detect(image).state, Evidence.PRESENT)

    def test_page_two_graphics_do_not_become_cross_outs(self):
        image = blank()
        cv2.rectangle(image, (64, 74), (733, 506), (0, 0, 0), 2)
        # Synthetic crest/chevron and hatch, not copied identification artwork.
        cv2.polylines(image, [np.array([[100, 170], [210, 60], [320, 170]])], False, (0, 0, 0), 2)
        cv2.polylines(image, [np.array([[350, 300], [470, 180], [550, 300]])], True, (0, 0, 0), 2)
        for x in range(400, 551, 30):
            cv2.line(image, (x, 470), (x+100, 370), (0, 0, 0), 2)
        result = self.detect(image)
        self.assertNotEqual(result.state, Evidence.PRESENT)
        self.assertFalse(any(item['display'] for item in result.detections))

    def test_weak_parallel_graphics_do_not_turn_two_clear_marks_into_hatching(self):
        image = blank()
        cv2.line(image, (0, 210), (199, 11), (7, 7, 7), 2)
        cv2.line(image, (33, 242), (229, 43), (7, 7, 7), 2)
        for shift in (12, 22, 30):
            for distance in range(70, 160, 12):
                cv2.line(image, (distance, 210-distance+shift), (distance+5, 205-distance+shift), (150, 150, 150), 1)
        result = self.detect(image)
        self.assertEqual(result.state, Evidence.PRESENT)
        self.assertEqual(sum(item['state'] == 'present' for item in result.detections), 2)

    def test_fragments_and_duplicates_merge_but_parallel_marks_remain_separate(self):
        image = blank()
        cv2.line(image, (100, 400), (400, 100), (0, 0, 0), 2)
        cv2.line(image, (135, 435), (435, 135), (0, 0, 0), 2)
        fragments = np.array([[[100, 400, 250, 250]], [[240, 260, 400, 100]],
                              [[101, 400, 250, 251]], [[135, 435, 280, 290]],
                              [[270, 300, 435, 135]]], np.int32)
        with patch('app.cross_out_detection.cv2.HoughLinesP', return_value=fragments):
            result = self.detect(image)
        self.assertEqual(sum(item['state'] == 'present' for item in result.detections), 2)
        self.assertEqual(len(result.detections), 2)

    def test_printed_chevron_is_uncertain_but_x_centre_crossing_is_not_endpoint_junction(self):
        chevron = blank()
        cv2.polylines(chevron, [np.array([[100, 250], [250, 100], [400, 250]])], False, (0, 0, 0), 2)
        self.assertNotEqual(self.detect(chevron).state, Evidence.PRESENT)
        cross = blank()
        cv2.line(cross, (100, 100), (350, 350), (0, 0, 0), 2)
        cv2.line(cross, (100, 350), (350, 100), (0, 0, 0), 2)
        self.assertEqual(self.detect(cross).state, Evidence.PRESENT)

    def test_compact_cross_out_does_not_supply_a_signature_pass(self):
        service = VisualValidationService(Settings(visual_backend='opencv_crossout'))
        result = service.inspect([front_strokes(), blank()])
        self.assertEqual(result.cross_out, Evidence.PRESENT)
        self.assertEqual(result.pages_checked, 2)
        self.assertEqual(result.signature, Evidence.UNCERTAIN)

    def test_compact_stroke_endpoint_still_withholds_approval(self):
        service = VisualValidationService(Settings(visual_backend='opencv_crossout'))
        with (patch.object(main, 'visual_service', service),
              patch.object(main.settings, 'api_key', ''),
              patch.object(main.ocr_service, 'recognize', return_value=FRONT),
              patch.object(main, 'check_image_quality', return_value=QUALITY)):
            body = TestClient(main.app).post('/api/verify-mykad-image',
                files={'image_file': ('synthetic.png', cv2.imencode('.png', front_strokes())[1].tobytes(), 'image/png')},
                headers={'X-OCR-Diagnostics':'1'}).json()
        self.assertFalse(body['verified'])
        self.assertEqual(body['status'], 'MANUAL_REVIEW')
        self.assertEqual(body['diagnostics']['checks']['cross_out']['state'], 'present')
        self.assertEqual(body['diagnostics']['checks']['customer_signature']['state'], 'not_checked')


if __name__ == '__main__':
    unittest.main()
