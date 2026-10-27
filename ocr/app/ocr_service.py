\
from dataclasses import dataclass
from typing import Any

import numpy as np
from paddleocr import PaddleOCR


@dataclass
class OCRLine:
    text: str
    confidence: float


class OCRService:
    def __init__(self) -> None:
        # PaddleOCR 3.x pipeline.
        # Models are downloaded automatically during the first run.
        self.engine = PaddleOCR(
            lang="en",
            use_doc_orientation_classify=True,
            use_doc_unwarping=True,
            use_textline_orientation=True,
        )

    @staticmethod
    def _as_dict(result: Any) -> dict:
        """
        Convert PaddleOCR result objects into a dictionary.
        This accommodates minor output differences across PaddleOCR 3.x releases.
        """
        if isinstance(result, dict):
            return result

        json_value = getattr(result, "json", None)
        if callable(json_value):
            json_value = json_value()

        if isinstance(json_value, dict):
            return json_value

        if isinstance(json_value, str):
            import json
            return json.loads(json_value)

        res_value = getattr(result, "res", None)
        if isinstance(res_value, dict):
            return res_value

        return {}

    def recognize(self, image: np.ndarray) -> list[OCRLine]:
        results = self.engine.predict(image)
        lines: list[OCRLine] = []

        for result in results:
            data = self._as_dict(result)
            payload = data.get("res", data)

            texts = payload.get("rec_texts", []) or []
            scores = payload.get("rec_scores", []) or []

            for index, raw_text in enumerate(texts):
                text = str(raw_text).strip()
                if not text:
                    continue

                score = float(scores[index]) if index < len(scores) else 0.0
                lines.append(OCRLine(text=text, confidence=score))

        return lines


ocr_service = OCRService()
