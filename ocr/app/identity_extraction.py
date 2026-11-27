"""Extract identity fields from OCR evidence without customer-supplied hints.

Prefer labelled fields and passport MRZ data. Unlabelled names require a
document-number anchor; arbitrary form text is not treated as a person's name.
Conflicting candidates are withheld rather than selected using request values.
"""
import re
import unicodedata


NRIC = re.compile(r"\b\d{6}[-\s]?\d{2}[-\s]?\d{4}\b")
NUMBER_LABEL = re.compile(r"^(?:PASSPORT\s*(?:NO\.?|NUMBER)|NO\.?\s*(?:PASPORT|PASSPORT)|NOMBOR\s+PASPORT)\b[.\s:/#-]*", re.I)
FULL_NAME = re.compile(r"^(?:FULL\s+NAME|NAME\s+OF\s+HOLDER|NAMA\s+PENUH|NAMA(?:\s*/\s*NAME)?|NAME(?:\s*/\s*NAMA)?)\b\s*[:\-]?\s*", re.I)
SURNAME = re.compile(r"^(?:SURNAME|FAMILY\s+NAME)(?:\s*/\s*NOM)?\b\s*[:\-]?\s*", re.I)
GIVEN_NAMES = re.compile(r"^(?:GIVEN\s+NAMES?|FORENAMES?)(?:\s*/\s*PRENOMS?)?\b\s*[:\-]?\s*", re.I)
FIELD_START = re.compile(
    r"^(?:ALAMAT|ADDRESS|NATIONALITY|KEWARGANEGARAAN|CITIZENSHIP|WARGANEGARA|"
    r"JANTINA|SEX|GENDER|AGAMA|RACE|TARIKH|DATE|PLACE|TEMPAT|AUTHORITY|ISSUING|"
    r"SIGNATURE|TANDATANGAN|NO\.?|NUMBER|NOMBOR|NRIC|IDENTITY|KAD\s+PENGENALAN|"
    r"PASSPORT|PASPORT|MYKAD|MALAYSIA|KETUA|PENDAFTARAN|JABATAN|PERMOHONAN|"
    r"BORANG|APPLICATION|CANCELLATION|PEMBATALAN|FOR\s+CIDB|KEGUNAAN\s+CIDB|CIDB)\b", re.I)
ADDRESS_START = re.compile(r"^(?:JALAN|JLN|LORONG|LRG|TAMAN|TMN|KAMPUNG|KG|BANDAR|"
                           r"BLOK|BLOCK|LOT|PERSIARAN|POSKOD|POSTCODE|NEGERI|STATE)\b", re.I)
NON_NAMES = {"ISLAM", "LELAKI", "PEREMPUAN", "MALE", "FEMALE", "MALAYSIAN", "SELANGOR",
             "JOHOR", "KEDAH", "KELANTAN", "MELAKA", "PAHANG", "PERAK", "PERLIS",
             "SABAH", "SARAWAK", "TERENGGANU", "KUALA LUMPUR", "PULAU PINANG", "NEGERI SEMBILAN"}


def readable_text(lines):
    # Blank slots prevent joining across unreadable text into a made-up field.
    return [" ".join(line.text.split()).upper() if line.confidence >= .45 else "" for line in lines]


def name_value(value):
    value = " ".join(unicodedata.normalize("NFC", value).strip().split())
    if not value or value in NON_NAMES or FIELD_START.match(value) or ADDRESS_START.match(value):
        return None
    if any(not (char.isalpha() or unicodedata.category(char).startswith("M") or char in " '-’./") for char in value):
        return None
    if sum(char.isalpha() for char in value) < 2:
        return None
    return value


def unique(values, *, names=False):
    found = {}
    for value in values:
        if not value:
            continue
        key = value
        if names:
            # Match VIZ accents/order to MRZ transliteration without using a
            # customer name or changing the preferred displayed OCR value.
            key = "".join(char for char in unicodedata.normalize("NFKD", value) if not unicodedata.combining(char))
            key = tuple(sorted(re.findall(r"[^\W\d_]+", key, re.UNICODE)))
        found.setdefault(key, value)
    return next(iter(found.values())) if len(found) == 1 else None


def extract_nric_value(lines):
    candidates = []
    for text in readable_text(lines):
        for match in NRIC.finditer(text):
            digits = re.sub(r"\D", "", match.group())
            candidates.append(f"{digits[:6]}-{digits[6:8]}-{digits[8:]}")
    return unique(candidates)


def _passport_value(text, *, labelled=False):
    if not labelled and re.search(r"\s", text):
        return None
    compact = re.sub(r"\s", "", text).strip("<")
    if not re.fullmatch(r"[A-Z0-9]{6,12}", compact) or not any(char.isdigit() for char in compact):
        return None
    if not labelled and not any(char.isalpha() for char in compact):
        return None
    return compact


def _mrz_number(text):
    # ICAO TD3 line 2: document number in positions 1-9; check digit at 10.
    if len(text) != 44 or not re.fullmatch(r"[A-Z0-9<]{44}", text):
        return None
    if not re.fullmatch(r"[A-Z<]{3}\d{6}\d[MF<]\d{6}\d[A-Z0-9<]{14}[0-9<]\d", text[10:]):
        return None
    raw, digit = text[:9], text[9]
    if not digit.isdigit():
        return None
    values = [(ord(char)-55 if char.isalpha() else int(char) if char.isdigit() else 0) for char in raw]
    if sum(value * (7, 3, 1)[index % 3] for index, value in enumerate(values)) % 10 != int(digit):
        return None
    return _passport_value(raw, labelled=True)


def extract_passport_value(lines):
    texts = readable_text(lines)
    anchored, loose = [], []
    has_label = False
    for index, text in enumerate(texts):
        label = NUMBER_LABEL.match(text)
        if label:
            has_label = True
            value = text[label.end():] or (texts[index+1] if index+1 < len(texts) else "")
            anchored.append(_passport_value(value, labelled=True))
        compact = text.replace(" ", "")
        mrz = _mrz_number(compact)
        if mrz:
            anchored.append(mrz)
        # A standalone token avoids matching ordinary words or serial numbers
        # embedded in addresses and unrelated form sentences.
        value = _passport_value(text)
        if value:
            loose.append(value)
    if any(anchored) or has_label:
        return unique(anchored)
    return unique(loose)


def _name_block(texts, start, initial=""):
    values = [initial] if initial else []
    for text in texts[start:start+4]:
        if not text or any(label.match(text) for label in (FULL_NAME, SURNAME, GIVEN_NAMES)):
            break
        value = name_value(text)
        if not value:
            break
        values.append(value)
    return name_value(" ".join(values))


def _mykad_name_block(lines, texts, start):
    """Bound the unlabelled MyKad name using surrounding OCR evidence only.

    Do not strip arbitrary short names or globally blacklist name substrings.
    Merged street prefixes need following postal evidence; the isolated OR/FOR
    fragment needs CIDB context, weak recognition and a strong next name line.
    Number extraction, labelled names and passport paths are unchanged.
    """
    if (start+1 < len(texts) and texts[start] in {"OR", "FOR"}
            and lines[start].confidence < .9
            and any(re.search(r"\bCIDB\b", text) for text in texts[:start])
            and lines[start+1].confidence >= .95
            and re.search(r"\b(?:BIN|BINTI)\b", texts[start+1])
            and name_value(texts[start+1])):
        start += 1
    bounded = list(texts)
    for index in range(start, min(start+4, len(texts))):
        # OCR can merge the street/address words and confuse O with postcode 0.
        # Require a subsequent postcode or state line to avoid treating a name
        # such as JALANATHAN as an address solely because of its prefix.
        if not re.match(r"^(?:GDW\s*)?(?:KAMPUNG|JALAN|LORONG|PERSIARAN|TAMAN|BANDAR)", texts[index]):
            continue
        following = texts[index+1:index+3]
        postal = any(re.match(r"^[0-9O]{5}", text) and sum(c.isdigit() for c in text[:5]) >= 3
                     for text in following)
        region = any(text in NON_NAMES - {"ISLAM", "LELAKI", "PEREMPUAN", "MALE", "FEMALE", "MALAYSIAN"}
                     for text in following)
        if postal or region:
            bounded[index] = ""
            break
    return _name_block(bounded, start)


def extract_name_value(lines, document_type="mykad"):
    texts = readable_text(lines)
    full, surnames, given, mrz_names = [], [], [], []
    for index, text in enumerate(texts):
        for label, bucket in ((FULL_NAME, full), (SURNAME, surnames), (GIVEN_NAMES, given)):
            match = label.match(text)
            if match:
                inline = text[match.end():]
                if inline and not name_value(inline):
                    continue
                bucket.append(_name_block(texts, index+1, inline))
                break
        compact = text.replace(" ", "")
        if document_type == "passport" and re.fullmatch(r"P[A-Z<][A-Z<]{3}[A-Z<]{39}", compact):
            field = compact[5:]
            # A completely filled MRZ name can be truncated. Require padding
            # for a full-name fallback; prefer the labelled visual name.
            if "<<" in field and field.endswith("<"):
                mrz_names.append(name_value(" ".join(field.replace("<", " ").split())))
    if any(surnames) or any(given):
        surname, given_name = unique(surnames, names=True), unique(given, names=True)
        if not surname or (given and not given_name):
            return None
        full.append(" ".join(part for part in (surname, given_name) if part))
    if any(full):
        return unique(full, names=True)
    if mrz_names:
        return unique(mrz_names, names=True)
    number = extract_passport_value(lines) if document_type == "passport" else extract_nric_value(lines)
    if not number:
        return None
    number_key = re.sub(r"[^A-Z0-9]", "", number)
    anchored = []
    for index, text in enumerate(texts):
        if number_key in re.sub(r"[^A-Z0-9]", "", text):
            anchored.append(_name_block(texts, index+1) if document_type == "passport"
                            else _mykad_name_block(lines, texts, index+1))
    return unique(anchored, names=True)
