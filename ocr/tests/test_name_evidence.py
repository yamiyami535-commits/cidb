import unittest

from app.models import OCRLine
from app.verifier import extract_document_name, extract_nric, extract_passport_number


def lines(*texts):
    return [OCRLine(text=text, confidence=.99) for text in texts]


MRZ_NAME = "P<UTOERIKSSON<<ANNA<MARIA".ljust(44, "<")
MRZ_NUMBER = "L898902C36UTO7408122F1204159ZE184226B<<<<<10"


class NameEvidenceTests(unittest.TestCase):
    def test_reported_mykad_ocr_fragments_and_merged_address_are_not_name(self):
        # Actual reported name/address OCR; use a synthetic identification number.
        evidence = lines("MyKad", "CIDB USE ONLY", "IDEN", "CARD", "900101-10-1234")
        evidence += [OCRLine(text="OR", confidence=.8292719125747681)]
        evidence += lines("AZWANITA BINTI", "BAHARUDIN", "GDWKAMPUNGBAYANGAN", "8000OKENINGAU", "SABAH")
        self.assertEqual(extract_document_name(evidence), "AZWANITA BINTI BAHARUDIN")
        self.assertEqual(extract_nric(evidence), "900101-10-1234")

    def test_mykad_address_boundaries_allow_spaced_and_merged_prefixes(self):
        for address in ("GDW KAMPUNG BAYANGAN", "GDWKAMPUNGBAYANGAN", "KAMPUNGBAYANGAN", "JALANMERANTI"):
            with self.subTest(address=address):
                evidence = lines("900101101234", "SAMPLE BINTI", "CUSTOMER", address, "8000OKENINGAU", "SABAH")
                self.assertEqual(extract_document_name(evidence), "SAMPLE BINTI CUSTOMER")

    def test_short_names_and_prefix_like_names_are_not_generically_discarded(self):
        evidence = lines("CIDB USE ONLY", "900101101234")
        evidence += [OCRLine(text="LI", confidence=.83)] + lines("MEI", "JALAN TEST")
        self.assertEqual(extract_document_name(evidence), "LI MEI")
        self.assertEqual(extract_document_name(lines("900101101234", "OR", "AHMAD BIN ALI")), "OR AHMAD BIN ALI")
        self.assertEqual(extract_document_name(lines("900101101234", "JALANATHAN", "KUMAR")), "JALANATHAN KUMAR")
        self.assertEqual(extract_document_name(lines("NAME: OR AHMED"), document_type="passport"), "OR AHMED")

    def test_name_is_taken_from_document_without_customer_hint(self):
        evidence = lines("MALAYSIA", "900101-10-1234", "SAMPLE CUSTOMER", "12 JALAN TEST")
        for hint in (None, "OTHER PERSON", "Sample Custoner"):
            self.assertEqual(extract_document_name(evidence, hint), "SAMPLE CUSTOMER")

    def test_split_name_stops_before_address(self):
        self.assertEqual(extract_document_name(lines("900101101234", "SAMPLE", "BIN CUSTOMER", "JALAN TEST")), "SAMPLE BIN CUSTOMER")

    def test_name_prefix_is_not_mistaken_for_label(self):
        self.assertEqual(extract_document_name(lines("900101101234", "NAMAN KUMAR")), "NAMAN KUMAR")

    def test_labelled_unicode_name_is_preserved(self):
        self.assertEqual(extract_document_name(lines("FULL NAME: JOSÉ D’ANGELO", "NATIONALITY: MALAYSIAN")), "JOSÉ D’ANGELO")

    def test_passport_surname_and_given_name_fields_combine(self):
        self.assertEqual(extract_document_name(lines("SURNAME", "DOE", "GIVEN NAMES", "JANE ALICE"), document_type="passport"), "DOE JANE ALICE")

    def test_passport_mrz_name_is_supported(self):
        self.assertEqual(extract_document_name(lines(MRZ_NAME, MRZ_NUMBER), document_type="passport"), "ERIKSSON ANNA MARIA")

    def test_truncated_mrz_name_is_not_full_name(self):
        self.assertIsNone(extract_document_name(lines("P<MYSALI<<AHMAD<<<<<<<<<<<<<<<"), document_type="passport"))

    def test_missing_name_cannot_be_supplied_by_request(self):
        self.assertIsNone(extract_document_name(lines("MALAYSIA", "900101-10-1234"), "SAMPLE CUSTOMER"))

    def test_conflicting_labelled_names_are_withheld(self):
        self.assertIsNone(extract_document_name(lines("NAME: ALICE TAN", "NAME: BOB TAN"), "ALICE TAN"))

    def test_unreadable_name_is_withheld(self):
        evidence = lines("900101-10-1234") + [OCRLine(text="SAMPLE CUSTOMER", confidence=.1)]
        self.assertIsNone(extract_document_name(evidence, "SAMPLE CUSTOMER"))

    def test_nric_duplicates_normalize_but_conflicts_are_withheld(self):
        self.assertEqual(extract_nric(lines("900101101234", "900101-10-1234")), "900101-10-1234")
        self.assertIsNone(extract_nric(lines("900101101234", "900101101235")))

    def test_passport_number_never_uses_request_fallback_or_preference(self):
        self.assertIsNone(extract_passport_number(lines("PASSPORT", "NATIONALITY"), "A12345678"))
        self.assertEqual(extract_passport_number(lines("PASSPORT NO: B87654321"), "A12345678"), "B87654321")
        self.assertIsNone(extract_passport_number(lines("A12345678", "B87654321"), "A12345678"))

    def test_numeric_passport_and_mrz_number(self):
        self.assertEqual(extract_passport_number(lines("PASSPORT NO", "123456789")), "123456789")
        self.assertEqual(extract_passport_number(lines(MRZ_NAME, MRZ_NUMBER)), "L898902C3")

    def test_address_is_not_concatenated_into_a_passport_number(self):
        self.assertIsNone(extract_passport_number(lines("PASSPORT", "12 JALAN", "ADDRESS")))

    def test_invalid_mrz_checksum_and_conflicting_label_are_withheld(self):
        self.assertIsNone(extract_passport_number(lines(MRZ_NUMBER[:9] + "7" + MRZ_NUMBER[10:])))
        self.assertIsNone(extract_passport_number(lines("PASSPORT NO: A12345678", MRZ_NUMBER)))


if __name__ == "__main__":
    unittest.main()
