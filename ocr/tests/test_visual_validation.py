import hashlib
import json
from pathlib import Path
import tempfile
from time import monotonic, sleep
from types import SimpleNamespace
import unittest
from unittest.mock import Mock

import cv2
import numpy as np
import onnx
from onnx import TensorProto, helper, numpy_helper

from app.config import Settings
from app.document_references import DocumentReferences, ReferenceSpec, checked_asset
from app.visual_types import Detection, Evidence
from app.visual_validation import LABELS, OnnxDetector, VisualValidationService, evaluate_page, page_windows, parse_detections


def synthetic_reference():
    """Non-customer geometric fixture, deliberately not a real ID."""
    image = np.full((400, 640, 3), 245, np.uint8)
    random = np.random.default_rng(123)
    for _ in range(180):
        x, y = random.integers([15, 15], [625, 385])
        cv2.circle(image, (int(x), int(y)), int(random.integers(2, 6)), (25, 25, 25), 2)
    cv2.putText(image, "SYNTHETIC TEST REFERENCE", (30, 60), cv2.FONT_HERSHEY_SIMPLEX, .8, (0, 0, 0), 2)
    return image


def write_reference(root):
    image = synthetic_reference()
    blob = cv2.imencode(".png", image)[1].tobytes()
    (root / "reference.png").write_bytes(blob)
    spec = ReferenceSpec(kind="mykad_back", image="reference.png", sha256=hashlib.sha256(blob).hexdigest(),
                         embedded_signatures=[(.4, .55, .65, .72)])
    return spec, image


class VisualPolicyTests(unittest.TestCase):
    def setUp(self):
        self.image = np.zeros((600, 800, 3), np.uint8)
        self.card = Detection("mykad_back", .99, (50, 50, 700, 500))
        self.cross = Detection("cross_out", .99, (100, 80, 600, 120))
        self.signature = Detection("customer_signature", .99, (200, 300, 350, 360))
        self.references = Mock()
        self.references.match.return_value = [(400, 250, 550, 320)]

    def evaluate(self, detections):
        return evaluate_page(self.image, detections, self.references, .85, monotonic()+10)

    def test_plain_cross_and_added_signature_pass_without_text_input(self):
        self.assertEqual(self.evaluate([self.card, self.cross, self.signature]), (Evidence.PRESENT, Evidence.PRESENT))

    def test_embedded_signature_cannot_satisfy_customer_requirement(self):
        printed = Detection("embedded_signature", .99, (400, 250, 550, 320))
        self.assertEqual(self.evaluate([self.card, self.cross, printed]), (Evidence.PRESENT, Evidence.ABSENT))

    def test_reference_blocks_misclassified_director_signature(self):
        mistaken = Detection("customer_signature", .99, (410, 260, 540, 310))
        self.assertEqual(self.evaluate([self.card, self.cross, mistaken])[1], Evidence.UNCERTAIN)

    def test_added_signature_in_margin_can_pass(self):
        margin = Detection("customer_signature", .99, (10, 520, 120, 580))
        self.assertEqual(self.evaluate([self.card, self.cross, margin])[1], Evidence.PRESENT)

    def test_unknown_layout_and_low_confidence_cannot_pass(self):
        self.references.match.return_value = None
        self.assertEqual(self.evaluate([self.card, self.signature])[1], Evidence.UNCERTAIN)
        self.references.match.return_value = []
        faint = Detection("customer_signature", .5, self.signature.box)
        self.assertEqual(self.evaluate([self.card, faint])[1], Evidence.UNCERTAIN)

    def test_same_cross_stroke_cannot_automatically_count_as_signature(self):
        duplicate = Detection("customer_signature", .99, self.cross.box)
        self.assertEqual(self.evaluate([self.card, self.cross, duplicate])[1], Evidence.UNCERTAIN)

    def service(self, detections):
        service = VisualValidationService(Settings(visual_tile_size=1024))
        service._attempted = True
        service.detector = SimpleNamespace(detect=Mock(side_effect=detections), references=self.references,
                                           manifest=SimpleNamespace(present_threshold=.85))
        return service

    def test_evidence_can_be_on_different_pages(self):
        service = self.service([[self.cross], [self.card, self.signature]])
        result = service.inspect([self.image, self.image])
        self.assertEqual((result.cross_out, result.signature, result.pages_checked), (Evidence.PRESENT, Evidence.PRESENT, 2))
        self.assertEqual(service.detector.detect.call_count, 2)

    def test_second_page_error_overrides_first_page_success(self):
        service = self.service([[self.card, self.cross, self.signature], RuntimeError("test")])
        result = service.inspect([self.image, self.image])
        self.assertEqual(result.cross_out, Evidence.ERROR)
        self.assertEqual(result.pages_checked, 1)

    def test_missing_model_never_approves(self):
        service = VisualValidationService(Settings(visual_manifest_path=""))
        result = service.inspect([self.image])
        self.assertEqual(result.signature, Evidence.ERROR)

    def test_tile_limit_does_not_silently_skip_uninspected_regions(self):
        service = self.service([[self.card, self.cross, self.signature]])
        service.config.visual_max_tiles = 1
        result = service.inspect([self.image, np.zeros((1100, 1100, 3), np.uint8)])
        self.assertEqual(result.signature, Evidence.UNCERTAIN)
        self.assertEqual(result.pages_checked, 1)

    def test_detail_tiles_cover_every_pixel(self):
        image = np.zeros((1800, 1400, 3), np.uint8)
        windows = page_windows(image, 1024, 32)
        coverage = np.zeros(image.shape[:2], bool)
        for x1, y1, x2, y2 in windows[1:]:
            coverage[y1:y2, x1:x2] = True
        self.assertTrue(coverage.all())

    def test_malformed_detector_output_is_not_treated_as_absence(self):
        for rows in (np.zeros((1, 7)), [[0, float("nan"), 1, 2, 3, 4]], [[10, .99, 1, 2, 3, 4]], [[0, .99, 9, 9, 1, 1]]):
            with self.subTest(rows=rows), self.assertRaises(ValueError):
                parse_detections(rows, 100, 100, .25)
        self.assertEqual(parse_detections([[-1, 0, 0, 0, 0, 0]], 100, 100, .25), [])


class ReferenceTests(unittest.TestCase):
    def test_reference_alignment_survives_perspective_and_page_position(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).parent) as folder:
            root = Path(folder)
            spec, image = write_reference(root)
            library = DocumentReferences(root, [spec])
            source = np.float32([[0, 0], [640, 0], [640, 400], [0, 400]])
            target = np.float32([[90, 150], [740, 170], [720, 570], [70, 550]])
            transform = cv2.getPerspectiveTransform(source, target)
            page = cv2.warpPerspective(image, transform, (850, 750), borderValue=(255, 255, 255))
            regions = library.match(page, Detection("mykad_back", .99, (65, 145, 745, 575)), monotonic()+10)
            self.assertIsNotNone(regions)
            expected_center = cv2.perspectiveTransform(np.float32([[[.525*640, .635*400]]]), transform)[0, 0]
            self.assertTrue(any(x1 < expected_center[0] < x2 and y1 < expected_center[1] < y2 for x1,y1,x2,y2 in regions))

    def test_unrelated_reference_does_not_establish_signature_origin(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).parent) as folder:
            spec, _ = write_reference(Path(folder))
            library = DocumentReferences(Path(folder), [spec])
            self.assertIsNone(library.match(np.full((400, 640, 3), 255, np.uint8),
                                           Detection("mykad_back", .99, (0, 0, 640, 400)), monotonic()+10))

    def test_asset_checksum_and_package_boundary_are_enforced(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).parent) as folder:
            root = Path(folder)
            spec, _ = write_reference(root)
            with self.assertRaises(ValueError):
                checked_asset(root, spec.image, "0"*64)
            with self.assertRaises(ValueError):
                checked_asset(root, "../test_visual_validation.py", spec.sha256)


class OnnxAdapterTests(unittest.TestCase):
    def package(self, root):
        spec, _ = write_reference(root)
        # Synthetic constant model tests the real runtime/adapter, NOT accuracy.
        rows = np.array([[0, .99, 10, 20, 150, 80]], np.float32)
        inputs = [helper.make_tensor_value_info("image", TensorProto.FLOAT, [1, 3, 416, 416]),
                  helper.make_tensor_value_info("scale_factor", TensorProto.FLOAT, [1, 2]),
                  helper.make_tensor_value_info("im_shape", TensorProto.FLOAT, [1, 2])]
        node = helper.make_node("Constant", [], ["bbox"], value=numpy_helper.from_array(rows))
        graph = helper.make_graph([node], "synthetic-test", inputs,
                                  [helper.make_tensor_value_info("bbox", TensorProto.FLOAT, [1, 6])])
        model = helper.make_model(graph, opset_imports=[helper.make_opsetid("", 11)])
        model.ir_version = 10
        blob = model.SerializeToString()
        (root / "model.onnx").write_bytes(blob)
        manifest = dict(format_version=1, release_id="synthetic-test-only", model="model.onnx",
                        sha256=hashlib.sha256(blob).hexdigest(), labels=LABELS, input_size=416,
                        output_name="bbox", mean=[.485, .456, .406], std=[.229, .224, .225],
                        candidate_threshold=.25, present_threshold=.85, references=[spec.model_dump()],
                        evaluation_id="synthetic-not-a-trained-model")
        path = root / "manifest.json"
        path.write_text(json.dumps(manifest), encoding="utf-8")
        return path

    def test_real_onnx_session_loads_and_decodes_contract(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).parent) as folder:
            detector = OnnxDetector(str(self.package(Path(folder))), 1)
            results = detector.detect(np.zeros((200, 300, 3), np.uint8), monotonic()+10)
            self.assertEqual(results[0].label, "cross_out")
            self.assertEqual(results[0].box, (10, 20, 150, 80))

    def test_preprocessing_preserves_color_normalization_and_original_coordinates(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).parent) as folder:
            detector = OnnxDetector(str(self.package(Path(folder))), 1)
            detector.session = Mock()
            detector.session.run.return_value = [np.empty((0, 6), np.float32)]
            image = np.zeros((200, 300, 3), np.uint8)
            image[:, :, 2] = 255
            detector.detect(image, monotonic()+10)
            feed = detector.session.run.call_args.args[1]
            np.testing.assert_allclose(feed["scale_factor"], [[416/200, 416/300]])
            np.testing.assert_allclose(feed["image"][0, :, 0, 0], [(1-.485)/.229, -.456/.224, -.406/.225], rtol=1e-6)

    def test_timeout_requests_runtime_termination(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).parent) as folder:
            detector = OnnxDetector(str(self.package(Path(folder))), 1)
            def wait_for_cancel(names, feed, options):
                limit = monotonic()+1
                while not options.terminate and monotonic() < limit:
                    sleep(.005)
                self.assertTrue(options.terminate)
                raise RuntimeError("terminated")
            detector.session = Mock()
            detector.session.run.side_effect = wait_for_cancel
            with self.assertRaises(RuntimeError):
                detector.detect(np.zeros((200, 300, 3), np.uint8), monotonic()+.05)


if __name__ == "__main__":
    unittest.main()
