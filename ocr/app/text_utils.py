\
import re
from datetime import date


COMMON_OCR_DIGIT_REPLACEMENTS = str.maketrans({
    "O": "0",
    "Q": "0",
    "I": "1",
    "L": "1",
    "Z": "2",
    "S": "5",
    "B": "8",
})


def normalize_name(value: str) -> str:
    value = value.upper()
    value = re.sub(r"[^A-Z0-9 ]", " ", value)
    value = re.sub(r"\s+", " ", value)
    return value.strip()


def normalize_nric(value: str) -> str:
    return re.sub(r"\D", "", value)


def mask_nric(value: str | None) -> str | None:
    if not value or len(value) != 12:
        return None
    return f"******-**-{value[-4:]}"


def validate_nric_format(value: str) -> bool:
    """
    Performs structural/date validation only.
    It does not prove that the NRIC exists or is genuine.
    """
    cleaned = normalize_nric(value)

    if len(cleaned) != 12:
        return False

    yy = int(cleaned[0:2])
    mm = int(cleaned[2:4])
    dd = int(cleaned[4:6])

    current_year_2d = date.today().year % 100
    possible_years = [1900 + yy, 2000 + yy]

    # Prefer a non-future plausible birth year.
    possible_years = [
        year for year in possible_years
        if year <= date.today().year
    ]

    if not possible_years:
        return False

    # Accept if at least one century interpretation forms a real date.
    for year in possible_years:
        try:
            birthday = date(year, mm, dd)
            age = (
                date.today().year
                - birthday.year
                - (
                    (date.today().month, date.today().day)
                    < (birthday.month, birthday.day)
                )
            )
            if 0 <= age <= 120:
                return True
        except ValueError:
            continue

    return False


def find_nric_in_text(lines: list[str]) -> str | None:
    joined = " ".join(lines).upper()

    # First look for conventional formatting.
    matches = re.findall(r"(?<!\d)(\d{6})[\s-]?(\d{2})[\s-]?(\d{4})(?!\d)", joined)
    for parts in matches:
        candidate = "".join(parts)
        if validate_nric_format(candidate):
            return candidate

    # Then attempt conservative OCR letter-to-digit correction on tokens.
    tokens = re.findall(r"[A-Z0-9-]{12,16}", joined)
    for token in tokens:
        corrected = token.translate(COMMON_OCR_DIGIT_REPLACEMENTS)
        candidate = normalize_nric(corrected)
        if len(candidate) == 12 and validate_nric_format(candidate):
            return candidate

    return None
