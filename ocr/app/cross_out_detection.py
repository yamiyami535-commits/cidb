"""Bounded, training-free cross-out candidates from original submitted pixels.

Stage one only: no signature classification or document reference matching.
Rule evidence is not a probability or proof of a mark's origin. Axis-aligned
rules and closed frames remain uncertain without document-layout evidence.
"""
from dataclasses import dataclass
from time import monotonic

import cv2
import numpy as np

from .visual_types import Evidence


@dataclass
class CrossOutResult:
    state: Evidence
    detections: list[dict]
    reasons: list[str]


def check_deadline(deadline):
    if monotonic() > deadline:
        raise TimeoutError("Cross-out inspection deadline exceeded.")


def line_geometry(line):
    start, end = np.asarray(line, dtype=float).reshape(2, 2)
    delta = end - start
    length = float(np.linalg.norm(delta))
    direction = delta / max(length, 1e-6)
    return start, end, length, direction


def same_stroke(first, second, tolerance):
    a, b, length, direction = line_geometry(first)
    c, d, other_length, other_direction = line_geometry(second)
    if abs(float(direction @ other_direction)) < np.cos(np.deg2rad(4)):
        return False
    normal = np.array([-direction[1], direction[0]])
    if max(abs(float((c-a) @ normal)), abs(float((d-a) @ normal))) > tolerance:
        return False
    low, high = sorted([float((c-a) @ direction), float((d-a) @ direction)])
    overlap = max(0., min(length, high) - max(0., low))
    return overlap >= .65 * min(length, other_length)


def stroke_appearance(gray, line):
    """Measure both sides of actual ink, rather than threshold-mask edges."""
    h, w = gray.shape
    start, end, length, direction = line_geometry(line)
    normal = np.array([-direction[1], direction[0]])
    samples = np.linspace(start, end, max(20, int(length)))

    def pixels(offset):
        xy = np.rint(samples + normal*offset).astype(int)
        return gray[np.clip(xy[:, 1], 0, h-1), np.clip(xy[:, 0], 0, w-1)].astype(float)

    ink = np.minimum.reduce([pixels(offset) for offset in (-2, 0, 2)])
    # Median flank bands tolerate tiny background-pattern dots without treating
    # the one-sided boundary of a filled graphic/card as a pen stroke.
    left = np.median([pixels(offset) for offset in (-8, -10, -12)], axis=0)
    right = np.median([pixels(offset) for offset in (8, 10, 12)], axis=0)
    difference = np.minimum(left, right)-ink
    supported = difference > 25
    breaks = np.flatnonzero(np.diff(np.r_[False, ~supported, False]))
    gap = max(breaks[1::2]-breaks[::2], default=0) / len(samples)
    return float(np.median(difference)), float(np.mean(supported)), float(gap)


def follows_closed_edge(edge, line, tolerance):
    if not same_stroke(edge, line, tolerance):
        return False
    start, _, length, direction = line_geometry(line)
    a, b, _, _ = line_geometry(edge)
    low, high = sorted([float((a-start) @ direction), float((b-start) @ direction)])
    # A real cross-out can cut through a frame and create a new closed polygon.
    # Its extension beyond that polygon must not be mistaken for a frame edge.
    return max(0., min(length, high)-max(0., low)) >= .85*length


def stroke_width(gray, line):
    """Robust width of the dark run, including connected graphic/letter branches."""
    h, w = gray.shape
    start, end, length, direction = line_geometry(line)
    normal = np.array([-direction[1], direction[0]])
    samples = np.linspace(start, end, max(20, min(300, int(length))))
    offsets = np.arange(-12, 13)
    xy = np.rint(samples[:, None, :] + offsets[None, :, None]*normal).astype(int)
    profile = gray[np.clip(xy[:, :, 1], 0, h-1), np.clip(xy[:, :, 0], 0, w-1)].astype(float)
    background = np.minimum(np.median(profile[:, :4], axis=1), np.median(profile[:, -4:], axis=1))
    dark = profile < background[:, None]-25
    centers = np.argmin(profile[:, 10:15], axis=1)+10
    widths = []
    for row, center in zip(dark, centers):
        if not row[center]:
            continue
        left = right = int(center)
        while left > 0 and row[left-1]:
            left -= 1
        while right < 24 and row[right+1]:
            right += 1
        widths.append(right-left+1)
    return (float(np.median(widths)), float(np.percentile(widths, 90))) if widths else (25., 25.)


def diagonal_angle(line):
    direction = line_geometry(line)[3]
    angle = abs(float(np.degrees(np.arctan2(direction[1], direction[0])))) % 90
    return min(angle, 90-angle)


def length_evidence(line, appearance, widths, short_side):
    """Two evidence paths; compact marks need substantially stronger support."""
    contrast, support, gap = appearance
    length = line_geometry(line)[2]
    width, broad_width = widths
    if length >= short_side*.28:
        return "long_stroke"
    if (length >= max(80., min(120., short_side*.20)) and diagonal_angle(line) >= 20
            and contrast >= 100 and support >= .95 and gap <= .025
            and width <= max(8., short_side*.014) and broad_width <= max(8., width*2)
            and length/max(width, 1) >= 25):
        return "compact_strong_stroke"
    return None


def joined_stroke(first, second, tolerance):
    """Propose a union on the same physical track, never across parallel marks."""
    start, _, length, direction = line_geometry(first)
    a, b, _, other_direction = line_geometry(second)
    if abs(float(direction @ other_direction)) < np.cos(np.deg2rad(4)):
        return None
    normal = np.array([-direction[1], direction[0]])
    if max(abs(float((a-start) @ normal)), abs(float((b-start) @ normal))) > tolerance:
        return None
    low, high = sorted([float((a-start) @ direction), float((b-start) @ direction)])
    if max(0., low-length, -high) > 12:
        return None
    return np.concatenate([start+min(0., low)*direction, start+max(length, high)*direction])


def endpoint_junction(line, lines, tolerance):
    """Connected nonparallel endpoints suggest a chevron/emblem, not a lone mark.

    An X's centre intersection is deliberately not an endpoint junction.
    """
    a, b, _, direction = line_geometry(line)
    for other in lines:
        c, d, _, other_direction = line_geometry(other)
        if abs(float(direction @ other_direction)) > np.cos(np.deg2rad(20)):
            continue
        if min(np.linalg.norm(first-second) for first in (a, b) for second in (c, d)) < tolerance:
            return True
    return False


def repeated_patterns(lines, distance_limit):
    """Group parallel hatch strokes, including members at the pattern's edges."""
    parents = list(range(len(lines)))
    geometry = [line_geometry(line) for line in lines]

    def root(index):
        while parents[index] != index:
            parents[index] = parents[parents[index]]
            index = parents[index]
        return index

    for index, (start, _, length, direction) in enumerate(geometry):
        normal = np.array([-direction[1], direction[0]])
        for other in range(index+1, len(lines)):
            a, b, other_length, other_direction = geometry[other]
            distance = abs(float(((a+b)/2-start) @ normal))
            if not 8 < distance < distance_limit or abs(float(direction @ other_direction)) < np.cos(np.deg2rad(4)):
                continue
            low, high = sorted([float((a-start) @ direction), float((b-start) @ direction)])
            if max(0., min(length, high)-max(0., low)) >= .65*min(length, other_length):
                parents[root(other)] = root(index)
    groups = {}
    for index, line in enumerate(lines):
        groups.setdefault(root(index), []).append(id(line))
    return {member for group in groups.values() if len(group) >= 4 for member in group}


class OpenCVCrossOutDetector:
    release_id = "opencv-crossout-v3"
    # Bound expensive native operations and diagnostic payloads before looping.
    max_side = 1600
    max_lines = 512
    max_contours = 2000

    def detect(self, image, deadline):
        check_deadline(deadline)
        if (not isinstance(image, np.ndarray) or image.dtype != np.uint8 or
                image.ndim != 3 or image.shape[2] != 3 or image.size == 0):
            raise ValueError("Invalid cross-out input image.")
        height, width = image.shape[:2]
        scale = min(1., self.max_side / max(height, width))
        work = cv2.resize(image, (max(1, round(width*scale)), max(1, round(height*scale))),
                          interpolation=cv2.INTER_AREA) if scale < 1 else image
        h, w = work.shape[:2]
        if min(h, w) < 100 or scale < .25:
            return CrossOutResult(Evidence.UNCERTAIN, [], ["Resolution prevents reliable cross-out inspection."])
        gray = cv2.cvtColor(work, cv2.COLOR_BGR2GRAY)
        binary = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
                                       cv2.THRESH_BINARY_INV, 31, 8)
        # Keep local dark strokes; prevent bright paper texture becoming ink.
        binary[gray > 220] = 0
        binary = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, np.ones((3, 3), np.uint8))
        minimum = max(35, round(min(h, w)*.12))
        # Filter tiny disconnected texture BEFORE Hough/contours. Raw contour
        # count is not a work budget: thousands of small security-pattern dots
        # must not prevent examination of a long, continuous submitted mark.
        _, components, stats, _ = cv2.connectedComponentsWithStats(binary, connectivity=8)
        retain = np.maximum(stats[:, cv2.CC_STAT_WIDTH], stats[:, cv2.CC_STAT_HEIGHT]) >= minimum
        retain[0] = False
        small_components = int(np.sum(~retain[1:]))
        binary = (retain[components].astype(np.uint8)*255)
        check_deadline(deadline)
        lines = cv2.HoughLinesP(binary, 1, np.pi/720, threshold=max(25, minimum//2),
                                minLineLength=minimum, maxLineGap=max(5, minimum//12))
        contours, _ = cv2.findContours(binary, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
        check_deadline(deadline)
        significant = []
        for contour in contours:
            check_deadline(deadline)
            _, _, cw, ch = cv2.boundingRect(contour)
            if max(cw, ch) >= minimum:
                significant.append(contour)
        if len(significant) > self.max_contours:
            return CrossOutResult(Evidence.UNCERTAIN, [], ["Significant contour evidence exceeded the inspection limit after small-pattern filtering."])
        candidates = [(line[0].astype(float), "hough") for line in lines] if lines is not None else []
        frames = []
        for contour in significant:
            check_deadline(deadline)
            perimeter = cv2.arcLength(contour, True)
            polygon = cv2.approxPolyDP(contour, .02*perimeter, True)
            # Also suppress closed printed emblems, not only rectangular cards.
            if 3 <= len(polygon) <= 12 and cv2.isContourConvex(polygon) and abs(cv2.contourArea(polygon)) > .005*w*h:
                corners = polygon.reshape(-1, 2)
                frames.extend(np.concatenate([corners[i], corners[(i+1) % len(corners)]]) for i in range(len(corners)))
            # An elongated contour supplies an additional straight-stroke candidate.
            (_, _), (cw, ch), _ = cv2.minAreaRect(contour)
            if max(cw, ch) < minimum or min(cw, ch) < 1 or max(cw, ch)/min(cw, ch) < 10:
                continue
            points = contour.reshape(-1, 2).astype(float)
            vx, vy, cx, cy = cv2.fitLine(contour, cv2.DIST_L2, 0, .01, .01).ravel()
            direction, center = np.array([vx, vy]), np.array([cx, cy])
            projection = (points-center) @ direction
            endpoints = np.concatenate([center+projection.min()*direction, center+projection.max()*direction])
            candidates.append((endpoints, "contour"))
        plausible = []
        rejected = {"weak/background lines": 0, "frame/graphic edges": 0}
        tolerance = max(6., .008*min(h, w))
        for line, source in sorted(candidates, key=lambda item: -line_geometry(item[0])[2]):
            check_deadline(deadline)
            contrast, support, gap = stroke_appearance(gray, line)
            if contrast < 20 or support < .35:
                rejected["weak/background lines"] += 1
                continue
            if any(follows_closed_edge(edge, line, tolerance*1.5) for edge in frames):
                rejected["frame/graphic edges"] += 1
                continue
            plausible.append((line, {source}, (contrast, support, gap)))
        # A long weak Hough extension must not suppress a shorter continuous
        # stroke when deduplicating; prefer affirmative appearance first.
        def priority(item):
            contrast, support, gap = item[2]
            strong = contrast >= 45 and support >= .8 and gap <= .12
            return (-int(strong), -line_geometry(item[0])[2])

        unique = []
        for line, sources, appearance in sorted(plausible, key=priority):
            check_deadline(deadline)
            consolidated = False
            for index, (previous, previous_sources, previous_appearance) in enumerate(unique):
                union = joined_stroke(previous, line, tolerance)
                if union is None:
                    continue
                union_appearance = stroke_appearance(gray, union)
                # Never bridge blank gaps or replace clear evidence with a weak
                # extension. All union evidence is measured on original pixels.
                if (union_appearance[0] >= 45 and union_appearance[1] >= max(.8, previous_appearance[1]-.03)
                        and union_appearance[2] <= min(.08, previous_appearance[2]+.02)):
                    unique[index] = (union, previous_sources | sources, union_appearance)
                    consolidated = True
                    break
                if same_stroke(previous, line, tolerance):
                    previous_sources.update(sources)
                    consolidated = True
                    break
            if not consolidated:
                unique.append((line, sources, appearance))
            if len(unique) > self.max_lines:
                return CrossOutResult(Evidence.UNCERTAIN, [], ["Too many plausible stroke candidates remain after filtering; inspection is incomplete."])
        evidence = []
        # Weak fragments of lettering/crest patterns must not turn two actual
        # clear marks into a spurious four-line hatch classification.
        pattern_lines = [line for line, _, (contrast, support, gap) in unique
                         if contrast >= 45 and support >= .8 and gap <= .12]
        pattern_members = repeated_patterns(pattern_lines, min(h, w)*.2)
        for line, sources, (contrast, support, gap) in unique:
            check_deadline(deadline)
            start, end, length, direction = line_geometry(line)
            reasons = []
            near_edge = (max(start[0], end[0]) < tolerance or min(start[0], end[0]) > w-tolerance or
                         max(start[1], end[1]) < tolerance or min(start[1], end[1]) > h-tolerance)
            if near_edge:
                reasons.append("Stroke follows a page edge; cross-out origin is ambiguous.")
            angle = diagonal_angle(line)
            if angle < 10:
                reasons.append("Near-horizontal/vertical stroke may be a printed rule or underline.")
            widths = stroke_width(gray, line)
            acceptance = length_evidence(line, (contrast, support, gap), widths, min(h, w))
            if acceptance is None:
                reasons.append("Insufficient combined length, contrast, continuity and slenderness evidence for a compact stroke.")
            if contrast < 45 or support < .8 or gap > .12:
                reasons.append("Weak or interrupted stroke contrast; cross-out cannot be confirmed.")
            patterned = id(line) in pattern_members
            junction = endpoint_junction(line, pattern_lines, tolerance*2)
            if patterned:
                reasons.append("Repeated parallel strokes may be a printed hatch or graphic; cross-out origin is ambiguous.")
            if junction:
                reasons.append("Stroke joins a nonparallel stroke at its endpoint; a printed graphic or decoration cannot be excluded.")
            if widths[0] > max(10., min(h, w)*.018) or widths[1] > max(12., widths[0]*3):
                reasons.append("Broad or branching ink profile may belong to lettering or a graphic.")
            state = Evidence.UNCERTAIN if reasons else Evidence.PRESENT
            # Independent x/y scales keep overlays correct after rounded resizing.
            coordinates = line * np.array([width/w, height/h, width/w, height/h])
            x1, y1, x2, y2 = coordinates.tolist()
            evidence.append({"label": "cross_out", "state": state.value,
                             "box": [max(0., min(x1, x2)-3), max(0., min(y1, y2)-3),
                                     min(float(width), max(x1, x2)+3), min(float(height), max(y1, y2)+3)],
                             "line": coordinates.tolist(), "method": "+".join(sorted(sources)),
                             "display": state == Evidence.PRESENT or (angle >= 10 and contrast >= 45 and support >= .65
                                                                      and not near_edge and not patterned and not junction),
                             "metrics": {"length_fraction": round(length/min(h, w), 3),
                                         "contrast": round(contrast, 1), "support": round(support, 3),
                                         "longest_gap_fraction": round(gap, 3), "stroke_width": round(widths[0], 2),
                                         "width_p90": round(widths[1], 2), "length_evidence": acceptance},
                             "reason": " ".join(reasons) or ("Compact diagonal with very strong contrast, continuity and a narrow stroke profile; CIDB wording is not used."
                                                             if acceptance == "compact_strong_stroke" else
                                                             "Long, continuous, contrasting stroke independent of any CIDB wording.")})
        check_deadline(deadline)
        state = (Evidence.PRESENT if any(item["state"] == "present" for item in evidence) else
                 Evidence.UNCERTAIN if evidence or rejected["frame/graphic edges"] else Evidence.ABSENT)
        reason = {Evidence.PRESENT: "Cross-out stroke evidence found using OpenCV rules; not an overall approval.",
                  Evidence.UNCERTAIN: "Line candidates found, but their appearance does not establish a cross-out confidently.",
                  Evidence.ABSENT: "No qualifying cross-out stroke found. CIDB text alone cannot satisfy this check."}[state]
        filtering = (f"Filtered {small_components} small components, "
                     f"{rejected['weak/background lines']} weak/background lines and "
                     f"{rejected['frame/graphic edges']} frame/graphic edges before the candidate limit.")
        return CrossOutResult(state, evidence, [reason, filtering])
