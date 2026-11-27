import itertools
import unittest

from app.document_validation import mandatory_document_failures


class MandatoryDocumentValidationTests(unittest.TestCase):
    def test_only_all_four_requirements_allow_approval(self):
        for cross_out, signature, name, number in itertools.product((False, True), repeat=4):
            with self.subTest(cross_out=cross_out, signature=signature, name=name, number=number):
                failures = mandatory_document_failures(
                    cross_out_present=cross_out,
                    customer_signature_present=signature,
                    extracted_name="SAMPLE CUSTOMER" if name else None,
                    extracted_document_number="900101-10-1234" if number else None,
                )
                self.assertEqual(not failures, all((cross_out, signature, name, number)))
                self.assertEqual(len(failures), 4 - sum((cross_out, signature, name, number)))

    def test_passport_with_plain_cross_out_needs_no_cidb_wording(self):
        self.assertEqual(
            mandatory_document_failures(
                cross_out_present=True,
                customer_signature_present=True,
                extracted_name="SAMPLE CUSTOMER",
                extracted_document_number="A12345678",
            ),
            (),
        )

    def test_unknown_marks_and_blank_identity_fields_cannot_pass(self):
        failures = mandatory_document_failures(
            cross_out_present=None,
            customer_signature_present=None,
            extracted_name="   ",
            extracted_document_number="\t",
        )
        self.assertEqual(len(failures), 4)

    def test_printed_signature_does_not_satisfy_customer_signature_requirement(self):
        failures = mandatory_document_failures(
            cross_out_present=True,
            customer_signature_present=False,
            extracted_name="SAMPLE CUSTOMER",
            extracted_document_number="900101-10-1234",
        )
        self.assertEqual(len(failures), 1)
        self.assertIn("customer-added", failures[0])


if __name__ == "__main__":
    unittest.main()
