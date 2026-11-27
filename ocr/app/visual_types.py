"""Internal evidence only; these types are not part of the HTTP response."""
from dataclasses import dataclass
from enum import Enum


class Evidence(str, Enum):
    PRESENT = "present"
    ABSENT = "absent"
    UNCERTAIN = "uncertain"
    ERROR = "error"


@dataclass(frozen=True)
class Detection:
    label: str
    score: float
    box: tuple[float, float, float, float]


@dataclass(frozen=True)
class VisualResult:
    cross_out: Evidence
    signature: Evidence
    pages_checked: int


def combine_evidence(values: list[Evidence]) -> Evidence:
    # A processing failure is never hidden by evidence on another page.
    if not values or Evidence.ERROR in values:
        return Evidence.ERROR
    if Evidence.PRESENT in values:
        return Evidence.PRESENT
    if Evidence.UNCERTAIN in values:
        return Evidence.UNCERTAIN
    return Evidence.ABSENT


def intersection_fraction(first, second) -> float:
    """Fraction of the first box covered by the second box."""
    x1, y1, x2, y2 = first
    a1, b1, a2, b2 = second
    area = max(0, x2 - x1) * max(0, y2 - y1)
    overlap = max(0, min(x2, a2) - max(x1, a1)) * max(0, min(y2, b2) - max(y1, b1))
    return overlap / area if area else 0.0
