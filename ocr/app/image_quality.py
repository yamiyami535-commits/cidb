\
import cv2
import numpy as np

from .config import settings
from .models import QualityResult


def decode_image(file_bytes: bytes) -> np.ndarray:
    image_array = np.frombuffer(file_bytes, dtype=np.uint8)
    image = cv2.imdecode(image_array, cv2.IMREAD_COLOR)

    if image is None:
        raise ValueError("The uploaded file is not a valid image.")

    return image


def check_image_quality(image: np.ndarray) -> QualityResult:
    height, width = image.shape[:2]
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

    # Variance of the Laplacian is a practical sharpness indicator.
    # The threshold is camera-dependent and should be calibrated.
    blur_score = float(cv2.Laplacian(gray, cv2.CV_64F).var())
    brightness = float(np.mean(gray))

    dark_pixel_percentage = float(np.mean(gray < 30) * 100)
    bright_pixel_percentage = float(np.mean(gray > 245) * 100)

    issues: list[str] = []

    # The agreed automated gate is blur only. Scanned card copies often have
    # large white margins, so brightness/glare and nominal dimensions must not
    # turn a readable scan into a manual-review case.
    if blur_score < settings.min_blur_score:
        issues.append("The image is blurry. Hold the camera steady and try again.")

    return QualityResult(
        passed=len(issues) == 0,
        width=width,
        height=height,
        blur_score=round(blur_score, 2),
        brightness=round(brightness, 2),
        dark_pixel_percentage=round(dark_pixel_percentage, 2),
        bright_pixel_percentage=round(bright_pixel_percentage, 2),
        issues=issues,
    )


def preprocess_for_ocr(image: np.ndarray) -> np.ndarray:
    """
    Improve the image before OCR.

    PaddleOCR expects a normal image array with colour channels,
    so the final processed grayscale image is converted back to BGR.
    """
    processed = image.copy()

    height, width = processed.shape[:2]

    # Upscale smaller images to make the text easier for OCR to read.
    if width < 1400:
        scale = 1400 / width

        processed = cv2.resize(
            processed,
            None,
            fx=scale,
            fy=scale,
            interpolation=cv2.INTER_CUBIC,
        )

    # Convert image to grayscale.
    gray = cv2.cvtColor(
        processed,
        cv2.COLOR_BGR2GRAY,
    )

    # Improve local contrast.
    clahe = cv2.createCLAHE(
        clipLimit=2.0,
        tileGridSize=(8, 8),
    )

    enhanced = clahe.apply(gray)

    # Reduce noise while preserving edges.
    denoised = cv2.bilateralFilter(
        enhanced,
        7,
        35,
        35,
    )

    # Sharpen text.
    blurred = cv2.GaussianBlur(
        denoised,
        (0, 0),
        2,
    )

    sharpened = cv2.addWeighted(
        denoised,
        1.45,
        blurred,
        -0.45,
        0,
    )

    # IMPORTANT:
    # Convert the 2D grayscale image back to a 3-channel BGR image.
    ocr_ready_image = cv2.cvtColor(
        sharpened,
        cv2.COLOR_GRAY2BGR,
    )

    return ocr_ready_image
