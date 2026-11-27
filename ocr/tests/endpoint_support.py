"""Endpoint tests use real extraction/validation without downloading OCR models."""
import importlib
import sys
from types import ModuleType
from unittest.mock import Mock


fake_ocr = ModuleType("app.ocr_service")
fake_ocr.ocr_service = Mock()
previous_ocr = sys.modules.get("app.ocr_service")
sys.modules["app.ocr_service"] = fake_ocr
try:
    main = importlib.import_module("app.main")
finally:
    if previous_ocr is None:
        del sys.modules["app.ocr_service"]
    else:
        sys.modules["app.ocr_service"] = previous_ocr
