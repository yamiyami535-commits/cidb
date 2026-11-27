from pathlib import Path
import unittest
from unittest.mock import Mock, patch

from app.visual_types import Evidence, VisualResult
from tools.evaluate_visual import EvaluationCase, evaluate


class VisualEvaluationTests(unittest.TestCase):
    def test_reports_false_approvals_uncertainty_and_errors(self):
        cases = [EvaluationCase(files=["sample.png"], cross_out=True, customer_signature=False),
                 EvaluationCase(files=["sample.png"], cross_out=True, customer_signature=True),
                 EvaluationCase(files=["sample.png"], cross_out=True, customer_signature=True)]
        service = Mock()
        service.inspect.side_effect = [VisualResult(Evidence.PRESENT, Evidence.PRESENT, 1),
                                       VisualResult(Evidence.PRESENT, Evidence.UNCERTAIN, 1),
                                       VisualResult(Evidence.ERROR, Evidence.ERROR, 0)]
        with patch.object(Path, "read_bytes", return_value=b"image"), patch("tools.evaluate_visual.decode_image", return_value=object()):
            report = evaluate(service, cases, Path(__file__).parent)
        self.assertEqual(report["visual_false_approvals"], 1)
        self.assertEqual(report["signature_false_positives"], 1)
        self.assertEqual(report["visual_false_rejections"], 2)
        self.assertEqual(report["errors"], 1)
        self.assertEqual(report["uncertain"], 1)
        self.assertIn("visual_latency_p95_seconds", report)

    def test_empty_dataset_is_not_reported_as_success(self):
        with self.assertRaises(ValueError):
            evaluate(Mock(), [], Path(__file__).parent)


if __name__ == "__main__":
    unittest.main()
