import re
from collections.abc import Sequence

from rapidfuzz import fuzz

from app.config import settings
from app.models import (
    ComparisonResult,
    DocumentResult,
    OCRLine,
)
from app.text_utils import validate_nric_format


MYKAD_FRONT_KEYWORDS = {
    "MALAYSIA": 3,
    "KAD PENGENALAN": 4,
    "IDENTITY CARD": 3,
    "MYKAD": 2,
    "WARGANEGARA": 2,
}

MYKAD_BACK_KEYWORDS = {
    "KETUA PENGARAH": 3,
    "PENDAFTARAN NEGARA": 4,
    "JABATAN PENDAFTARAN NEGARA": 4,
    "TOUCH N GO": 2,
    "MYKAD": 2,
    "MALAYSIA": 2,
}

PASSPORT_KEYWORDS = {
    "PASSPORT": 4,
    "MALAYSIA": 3,
    "NATIONALITY": 2,
    "DATE OF BIRTH": 2,
    "DATE OF EXPIRY": 2,
    "PASSPORT NO": 3,
    "SURNAME": 2,
    "GIVEN NAMES": 2,
}


NRIC_REGEX = re.compile(
    r"\b\d{6}[-\s]?\d{2}[-\s]?\d{4}\b"
)

PASSPORT_REGEX = re.compile(
    r"\b[A-Z][A-Z0-9]{7,8}\b"
)


def normalize_text(value: str | None) -> str:
    if not value:
        return ""

    cleaned = value.upper()
    cleaned = re.sub(r"[^A-Z0-9\s]", " ", cleaned)
    cleaned = re.sub(r"\s+", " ", cleaned)

    return cleaned.strip()


def normalize_name(value: str | None) -> str:
    if not value:
        return ""

    cleaned = value.upper()
    cleaned = re.sub(r"[^A-Z\s]", " ", cleaned)
    cleaned = re.sub(r"\s+", " ", cleaned)

    return cleaned.strip()


def normalize_document_number(
    value: str | None,
) -> str:
    if not value:
        return ""

    return re.sub(
        r"[^A-Z0-9]",
        "",
        value.upper(),
    )


def format_nric(value: str | None) -> str | None:
    digits = re.sub(
        r"\D",
        "",
        value or "",
    )

    if len(digits) != 12:
        return None

    return (
        f"{digits[:6]}-"
        f"{digits[6:8]}-"
        f"{digits[8:]}"
    )


def joined_ocr_text(
    lines: Sequence[OCRLine],
) -> str:
    return "\n".join(
        line.text
        for line in lines
        if line.text.strip()
    )


def average_ocr_confidence(
    lines: Sequence[OCRLine],
) -> float:
    confidences = [
        line.confidence
        for line in lines
        if line.confidence is not None
    ]

    if not confidences:
        return 0.0

    return sum(confidences) / len(confidences)


def extract_nric(
    lines: Sequence[OCRLine],
) -> str | None:
    text = joined_ocr_text(lines)

    match = NRIC_REGEX.search(text)

    if match:
        return format_nric(match.group(0))

    # OCR sometimes removes separators completely.
    compact_digits = re.findall(
        r"\b\d{12}\b",
        text,
    )

    if compact_digits:
        return format_nric(compact_digits[0])

    return None


def extract_passport_number(
    lines: Sequence[OCRLine],
    entered_passport_number: str | None = None,
) -> str | None:
    raw_text = joined_ocr_text(lines).upper()
    compact_text = normalize_document_number(raw_text)

    entered_normalized = normalize_document_number(
        entered_passport_number
    )

    # Most reliable method during verification:
    # check whether the entered number appears in OCR output.
    if (
        entered_normalized
        and entered_normalized in compact_text
    ):
        return entered_normalized

    candidates = PASSPORT_REGEX.findall(
        raw_text
    )

    ignored_values = {
        "MALAYSIA",
        "PASSPORT",
        "NATIONALITY",
        "IDENTITY",
    }

    for candidate in candidates:
        normalized_candidate = (
            normalize_document_number(candidate)
        )

        if normalized_candidate not in ignored_values:
            return normalized_candidate

    return None


def _keyword_result(
    lines: Sequence[OCRLine],
    keyword_weights: dict[str, int],
    required_score: int,
    has_document_number: bool,
) -> DocumentResult:
    full_text = normalize_text(
        joined_ocr_text(lines)
    )

    matched_keywords: list[str] = []
    score = 0

    for keyword, weight in keyword_weights.items():
        if normalize_text(keyword) in full_text:
            matched_keywords.append(keyword)
            score += weight

    if has_document_number:
        score += 4

    return DocumentResult(
        passed=score >= required_score,
        score=score,
        matched_keywords=matched_keywords,
        has_document_number=has_document_number,
        has_nric_pattern=has_document_number,
    )


def evaluate_mykad_front(
    lines: Sequence[OCRLine],
) -> tuple[DocumentResult, str | None]:
    extracted_nric = extract_nric(lines)

    result = _keyword_result(
        lines=lines,
        keyword_weights=MYKAD_FRONT_KEYWORDS,
        required_score=5,
        has_document_number=bool(extracted_nric),
    )

    return result, extracted_nric


def evaluate_mykad_back(
    lines: Sequence[OCRLine],
) -> DocumentResult:
    extracted_nric = extract_nric(lines)

    result = _keyword_result(
        lines=lines,
        keyword_weights=MYKAD_BACK_KEYWORDS,
        required_score=4,
        has_document_number=bool(extracted_nric),
    )

    return result


def evaluate_passport(
    lines: Sequence[OCRLine],
    entered_passport_number: str | None = None,
) -> tuple[DocumentResult, str | None]:
    passport_number = extract_passport_number(
        lines,
        entered_passport_number,
    )

    full_text = joined_ocr_text(lines).upper()

    # Many passports contain MRZ lines beginning with P<.
    has_mrz = (
        "P<" in full_text
        or any(
            "<" in line.text
            and len(line.text.replace(" ", "")) >= 25
            for line in lines
        )
    )

    result = _keyword_result(
        lines=lines,
        keyword_weights=PASSPORT_KEYWORDS,
        required_score=7,
        has_document_number=bool(passport_number),
    )

    if has_mrz:
        result.score += 4

    result.passed = result.score >= 7

    if has_mrz:
        result.matched_keywords.append(
            "MACHINE READABLE ZONE"
        )

    return result, passport_number


def calculate_name_similarity(
    entered_name: str,
    lines: Sequence[OCRLine],
) -> float:
    normalized_entered_name = normalize_name(
        entered_name
    )

    if not normalized_entered_name:
        return 0.0

    readable_lines = [
        normalize_name(line.text)
        for line in lines
        if line.confidence >= 0.35
        and normalize_name(line.text)
    ]

    candidates: list[str] = []

    for index, line in enumerate(readable_lines):
        candidates.append(line)

        if index + 1 < len(readable_lines):
            candidates.append(
                f"{line} {readable_lines[index + 1]}"
            )

        if index + 2 < len(readable_lines):
            candidates.append(
                (
                    f"{line} "
                    f"{readable_lines[index + 1]} "
                    f"{readable_lines[index + 2]}"
                )
            )

    candidates.append(
        " ".join(readable_lines)
    )

    if not candidates:
        return 0.0

    return max(
        fuzz.token_set_ratio(
            normalized_entered_name,
            candidate,
        )
        for candidate in candidates
    )


def compare_mykad_details(
    entered_name: str,
    entered_nric: str,
    extracted_nric: str | None,
    lines: Sequence[OCRLine],
) -> ComparisonResult:
    entered_nric_formatted = format_nric(
        entered_nric
    )

    extracted_nric_formatted = format_nric(
        extracted_nric
    )

    nric_match = bool(
        entered_nric_formatted
        and extracted_nric_formatted
        and entered_nric_formatted
        == extracted_nric_formatted
    )

    name_similarity = calculate_name_similarity(
        entered_name,
        lines,
    )

    name_match = (
        name_similarity
        >= settings.min_name_similarity
    )

    entered_nric_valid = validate_nric_format(
        entered_nric
    )

    return ComparisonResult(
        entered_document_number_valid=(
            entered_nric_valid
        ),
        document_number_match=nric_match,
        name_match=name_match,
        name_similarity=round(
            name_similarity,
            2,
        ),
        entered_nric_valid=entered_nric_valid,
        nric_match=nric_match,
    )


def compare_passport_details(
    entered_name: str,
    entered_passport_number: str,
    extracted_passport_number: str | None,
    lines: Sequence[OCRLine],
) -> ComparisonResult:
    entered_normalized = normalize_document_number(
        entered_passport_number
    )

    extracted_normalized = (
        normalize_document_number(
            extracted_passport_number
        )
    )

    passport_number_valid = (
        6 <= len(entered_normalized) <= 12
        and entered_normalized.isalnum()
    )

    number_match = bool(
        entered_normalized
        and extracted_normalized
        and entered_normalized
        == extracted_normalized
    )

    name_similarity = calculate_name_similarity(
        entered_name,
        lines,
    )

    name_match = (
        name_similarity
        >= settings.min_name_similarity
    )

    return ComparisonResult(
        entered_document_number_valid=(
            passport_number_valid
        ),
        document_number_match=number_match,
        name_match=name_match,
        name_similarity=round(
            name_similarity,
            2,
        ),
        entered_nric_valid=False,
        nric_match=False,
    )


def mask_document_number(
    document_number: str | None,
) -> str | None:
    normalized = normalize_document_number(
        document_number
    )

    if not normalized:
        return None

    if len(normalized) <= 4:
        return "*" * len(normalized)

    return (
        "*" * (len(normalized) - 4)
        + normalized[-4:]
    )
