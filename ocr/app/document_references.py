"""Align non-customer reference assets; never persist submitted documents.

Known embedded-signature regions are evidence against approval, not a fixed
position rule. A customer mark overlapping such a region remains uncertain.
"""
import hashlib
from pathlib import Path
from time import monotonic

import cv2
import numpy as np
from pydantic import BaseModel, ConfigDict, Field


class ReferenceSpec(BaseModel):
    model_config = ConfigDict(extra="forbid")
    kind: str
    image: str
    sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    # Normalized [x1,y1,x2,y2] boxes on the unmarked reference card.
    embedded_signatures: list[tuple[float, float, float, float]]


def checked_asset(root: Path, name: str, digest: str) -> Path:
    path = (root / name).resolve()
    if not path.is_relative_to(root.resolve()) or not path.is_file():
        raise ValueError("Visual asset must be a file inside its model package.")
    if hashlib.sha256(path.read_bytes()).hexdigest() != digest:
        raise ValueError("Visual asset checksum mismatch.")
    return path


def scaled_gray(image, limit=1200):
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    scale = min(1.0, limit / max(gray.shape))
    if scale < 1:
        gray = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA)
    return gray, scale


class DocumentReferences:
    def __init__(self, root: Path, specs: list[ReferenceSpec]):
        if not specs or len(specs) > 64:
            raise ValueError("A bounded reference library is required.")
        self.orb = cv2.ORB_create(nfeatures=2000)
        self.entries = []
        for spec in specs:
            if spec.kind not in {"mykad_front", "mykad_back", "passport"}:
                raise ValueError("Unsupported reference kind.")
            for x1, y1, x2, y2 in spec.embedded_signatures:
                if not (0 <= x1 < x2 <= 1 and 0 <= y1 < y2 <= 1):
                    raise ValueError("Invalid embedded-signature reference box.")
            if spec.kind == "mykad_back" and not spec.embedded_signatures:
                raise ValueError("MyKad back references must locate the embedded signature.")
            path = checked_asset(root, spec.image, spec.sha256)
            image = cv2.imdecode(np.frombuffer(path.read_bytes(), np.uint8), cv2.IMREAD_COLOR)
            if image is None:
                raise ValueError("Unreadable visual reference.")
            gray, _ = scaled_gray(image)
            points, descriptors = self.orb.detectAndCompute(gray, None)
            if descriptors is None or len(points) < 12:
                raise ValueError("Reference has insufficient alignment features.")
            self.entries.append((spec, gray.shape, points, descriptors))

    def match(self, image, document, deadline):
        """Return projected printed regions, or None when layout is unknown.

        Match the entire detected ID, not just a similar-looking signature.
        Unknown layouts never supply affirmative customer-signature evidence.
        """
        x1, y1, x2, y2 = map(int, document.box)
        crop = image[y1:y2, x1:x2]
        if crop.size == 0:
            return None
        gray, scale = scaled_gray(crop)
        points, descriptors = self.orb.detectAndCompute(gray, None)
        if descriptors is None or len(points) < 12:
            return None
        matches_found = []
        matcher = cv2.BFMatcher(cv2.NORM_HAMMING)
        for spec, shape, reference_points, reference_descriptors in self.entries:
            if monotonic() > deadline:
                raise TimeoutError("Visual reference deadline exceeded.")
            if spec.kind != document.label:
                continue
            pairs = matcher.knnMatch(reference_descriptors, descriptors, k=2)
            good = [pair[0] for pair in pairs if len(pair) == 2 and pair[0].distance < .7 * pair[1].distance]
            if len(good) < 12:
                continue
            source = np.float32([reference_points[m.queryIdx].pt for m in good])
            target = np.float32([points[m.trainIdx].pt for m in good])
            transform, inliers = cv2.findHomography(source, target, cv2.RANSAC, 3.0)
            if transform is None or inliers is None or inliers.sum() < 12 or inliers.mean() < .6:
                continue
            height, width = shape
            # Reject alignment supported only by one small logo or signature.
            supported = source[inliers.ravel().astype(bool)]
            if cv2.contourArea(cv2.convexHull(supported)) < .15 * width * height:
                continue
            corners = np.float32([[0, 0], [width, 0], [width, height], [0, height]])
            projected = cv2.perspectiveTransform(corners[None], transform)[0]
            if not np.isfinite(projected).all() or not cv2.isContourConvex(projected):
                continue
            coverage = abs(cv2.contourArea(projected)) / (gray.shape[0] * gray.shape[1])
            if not .4 <= coverage <= 1.4:
                continue
            boxes = []
            for a, b, c, d in spec.embedded_signatures:
                rectangle = np.float32([[a*width, b*height], [c*width, b*height], [c*width, d*height], [a*width, d*height]])
                region = cv2.perspectiveTransform(rectangle[None], transform)[0] / scale
                if not np.isfinite(region).all():
                    raise ValueError("Invalid reference projection.")
                boxes.append((float(region[:, 0].min()+x1), float(region[:, 1].min()+y1),
                              float(region[:, 0].max()+x1), float(region[:, 1].max()+y1)))
            matches_found.extend(boxes)
            # Empty regions are valid for a reference with no embedded signature.
            if not boxes:
                matches_found.append(None)
        return [box for box in matches_found if box is not None] if matches_found else None
