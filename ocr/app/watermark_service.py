import base64
from typing import Literal

import cv2
import numpy as np


DocumentImageType = Literal[
    "mykad_front",
    "mykad_back",
    "passport",
]


WATERMARK_TEXT = "KEGUNAAN CIDB SAHAJA"


def _rectangles_overlap(
    first: tuple[int, int, int, int],
    second: tuple[int, int, int, int],
) -> bool:
    first_x1, first_y1, first_x2, first_y2 = first
    second_x1, second_y1, second_x2, second_y2 = second

    return not (
        first_x2 <= second_x1
        or first_x1 >= second_x2
        or first_y2 <= second_y1
        or first_y1 >= second_y2
    )


def _get_protected_regions(
    width: int,
    height: int,
    document_image_type: DocumentImageType,
) -> list[tuple[int, int, int, int]]:
    """
    Return areas that the CIDB label should not cover.

    Coordinates are based on common MyKad and passport layouts.
    """

    if document_image_type == "mykad_front":
        return [
            # NRIC area.
            (
                int(width * 0.14),
                int(height * 0.15),
                int(width * 0.72),
                int(height * 0.44),
            ),

            # Name and address area.
            (
                int(width * 0.10),
                int(height * 0.52),
                int(width * 0.70),
                int(height * 0.90),
            ),

            # Photograph and citizenship area.
            (
                int(width * 0.67),
                int(height * 0.25),
                int(width * 0.99),
                int(height * 0.92),
            ),
        ]

    if document_image_type == "mykad_back":
        return [
            # Central signature and printed details.
            (
                int(width * 0.30),
                int(height * 0.25),
                int(width * 0.84),
                int(height * 0.82),
            ),

            # Serial-number area.
            (
                int(width * 0.60),
                int(height * 0.78),
                int(width * 0.99),
                int(height * 0.99),
            ),
        ]

    # Passport protected regions.
    return [
        # Photograph.
        (
            int(width * 0.01),
            int(height * 0.20),
            int(width * 0.36),
            int(height * 0.78),
        ),

        # Name, passport number, nationality and other details.
        (
            int(width * 0.30),
            int(height * 0.16),
            int(width * 0.99),
            int(height * 0.80),
        ),

        # Machine Readable Zone at the bottom.
        (
            0,
            int(height * 0.76),
            width,
            height,
        ),
    ]


def _create_rotated_label(
    image_width: int,
    opacity: float,
    scale_multiplier: float = 1.0,
) -> np.ndarray:
    """
    Create a diagonal BGRA label containing the text and two lines.
    """

    font = cv2.FONT_HERSHEY_SIMPLEX

    font_scale = max(
        0.42,
        min(1.15, image_width / 850),
    ) * scale_multiplier

    thickness = max(
        1,
        int(round(image_width / 550)),
    )

    text_size, baseline = cv2.getTextSize(
        WATERMARK_TEXT,
        font,
        font_scale,
        thickness,
    )

    text_width, text_height = text_size

    padding_x = max(12, int(image_width * 0.018))
    padding_y = max(10, int(image_width * 0.014))

    label_width = text_width + (padding_x * 2)
    label_height = text_height + baseline + (padding_y * 2) + 14

    label = np.zeros(
        (label_height, label_width, 4),
        dtype=np.uint8,
    )

    colour = (20, 20, 20)
    alpha = int(255 * opacity)

    top_line_y = 4
    bottom_line_y = label_height - 5

    cv2.line(
        label,
        (4, top_line_y),
        (label_width - 4, top_line_y),
        (*colour, alpha),
        thickness,
        cv2.LINE_AA,
    )

    cv2.line(
        label,
        (4, bottom_line_y),
        (label_width - 4, bottom_line_y),
        (*colour, alpha),
        thickness,
        cv2.LINE_AA,
    )

    text_x = padding_x
    text_y = padding_y + text_height + 3

    cv2.putText(
        label,
        WATERMARK_TEXT,
        (text_x, text_y),
        font,
        font_scale,
        (*colour, alpha),
        thickness,
        cv2.LINE_AA,
    )

    # Rotate so the label rises from bottom-left to top-right.
    angle = 42

    centre = (
        label_width / 2,
        label_height / 2,
    )

    rotation_matrix = cv2.getRotationMatrix2D(
        centre,
        angle,
        1.0,
    )

    cosine = abs(rotation_matrix[0, 0])
    sine = abs(rotation_matrix[0, 1])

    rotated_width = int(
        (label_height * sine)
        + (label_width * cosine)
    )

    rotated_height = int(
        (label_height * cosine)
        + (label_width * sine)
    )

    rotation_matrix[0, 2] += (
        rotated_width / 2
        - centre[0]
    )

    rotation_matrix[1, 2] += (
        rotated_height / 2
        - centre[1]
    )

    rotated = cv2.warpAffine(
        label,
        rotation_matrix,
        (rotated_width, rotated_height),
        flags=cv2.INTER_CUBIC,
        borderMode=cv2.BORDER_CONSTANT,
        borderValue=(0, 0, 0, 0),
    )

    return rotated


def _overlay_bgra(
    background: np.ndarray,
    overlay: np.ndarray,
    x: int,
    y: int,
) -> np.ndarray:
    """
    Place a transparent BGRA overlay on a BGR image.
    """

    result = background.copy()

    image_height, image_width = result.shape[:2]
    overlay_height, overlay_width = overlay.shape[:2]

    x1 = max(0, x)
    y1 = max(0, y)
    x2 = min(image_width, x + overlay_width)
    y2 = min(image_height, y + overlay_height)

    if x1 >= x2 or y1 >= y2:
        return result

    overlay_x1 = x1 - x
    overlay_y1 = y1 - y
    overlay_x2 = overlay_x1 + (x2 - x1)
    overlay_y2 = overlay_y1 + (y2 - y1)

    overlay_crop = overlay[
        overlay_y1:overlay_y2,
        overlay_x1:overlay_x2,
    ]

    alpha = (
        overlay_crop[:, :, 3:4].astype(np.float32)
        / 255.0
    )

    foreground = overlay_crop[:, :, :3].astype(
        np.float32
    )

    background_crop = result[
        y1:y2,
        x1:x2,
    ].astype(np.float32)

    blended = (
        foreground * alpha
        + background_crop * (1.0 - alpha)
    )

    result[y1:y2, x1:x2] = blended.astype(np.uint8)

    return result


def add_cidb_label(
    image: np.ndarray,
    document_image_type: DocumentImageType,
) -> np.ndarray:
    """
    Add the CIDB label while avoiding predefined important areas.

    The method tests multiple safe positions and reduces the label
    size if necessary.
    """

    height, width = image.shape[:2]

    protected_regions = _get_protected_regions(
        width=width,
        height=height,
        document_image_type=document_image_type,
    )

    # Candidate positions are primarily around the top-left edge.
    candidate_positions = [
        (int(width * -0.04), int(height * -0.12)),
        (int(width * -0.08), int(height * -0.02)),
        (int(width * 0.00), int(height * -0.18)),
        (int(width * 0.02), int(height * 0.00)),
        (int(width * -0.12), int(height * 0.06)),
    ]

    for scale_multiplier in [1.0, 0.88, 0.76, 0.66]:
        label = _create_rotated_label(
            image_width=width,
            opacity=0.88,
            scale_multiplier=scale_multiplier,
        )

        label_height, label_width = label.shape[:2]

        for candidate_x, candidate_y in candidate_positions:
            label_rectangle = (
                candidate_x,
                candidate_y,
                candidate_x + label_width,
                candidate_y + label_height,
            )

            overlaps_protected_area = any(
                _rectangles_overlap(
                    label_rectangle,
                    protected_rectangle,
                )
                for protected_rectangle
                in protected_regions
            )

            if not overlaps_protected_area:
                return _overlay_bgra(
                    background=image,
                    overlay=label,
                    x=candidate_x,
                    y=candidate_y,
                )

    # Final fallback: use the smallest label at the top-left.
    fallback_label = _create_rotated_label(
        image_width=width,
        opacity=0.82,
        scale_multiplier=0.58,
    )

    return _overlay_bgra(
        background=image,
        overlay=fallback_label,
        x=int(width * -0.04),
        y=int(height * -0.08),
    )


def encode_image_as_data_url(
    image: np.ndarray,
    quality: int = 92,
) -> str:
    """
    Convert an OpenCV image into a browser-displayable Base64 URL.
    """

    success, encoded = cv2.imencode(
        ".jpg",
        image,
        [
            int(cv2.IMWRITE_JPEG_QUALITY),
            quality,
        ],
    )

    if not success:
        raise ValueError(
            "The processed image could not be encoded."
        )

    encoded_base64 = base64.b64encode(
        encoded.tobytes()
    ).decode("utf-8")

    return (
        "data:image/jpeg;base64,"
        + encoded_base64
    )