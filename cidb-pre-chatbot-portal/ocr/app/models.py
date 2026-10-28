from typing import Optional

from pydantic import BaseModel, Field


class QualityResult(BaseModel):
    passed: bool
    width: int
    height: int
    blur_score: float
    brightness: float
    dark_pixel_percentage: float
    bright_pixel_percentage: float
    issues: list[str] = Field(default_factory=list)


class OCRLine(BaseModel):
    text: str
    confidence: float


class DocumentResult(BaseModel):
    passed: bool
    score: int
    matched_keywords: list[str] = Field(default_factory=list)
    has_document_number: bool = False

    # Kept for compatibility with the previous MyKad response.
    has_nric_pattern: bool = False


class ComparisonResult(BaseModel):
    entered_document_number_valid: bool = False
    document_number_match: bool = False
    name_match: bool = False
    name_similarity: float = 0

    # Kept for compatibility with the previous MyKad response.
    entered_nric_valid: bool = False
    nric_match: bool = False


class ProcessedImage(BaseModel):
    label: str
    filename: str
    mime_type: str = "image/jpeg"

    # Browser-displayable Base64 image.
    data_url: str


class VerificationResponse(BaseModel):
    verified: bool
    status: str
    message: str

    document_type: Optional[str] = None

    images_quality: Optional[
        dict[str, QualityResult]
    ] = None

    documents: Optional[
        dict[str, DocumentResult]
    ] = None

    comparison: Optional[ComparisonResult] = None

    ocr_average_confidence: Optional[float] = None

    extracted_document_number_masked: Optional[str] = None

    extracted_text_lines: Optional[list[str]] = None

    processed_images: Optional[
        list[ProcessedImage]
    ] = None
