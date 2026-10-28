import unittest

from app.pdf_input import PdfInputError, render_mykad_pdf_pages, render_pdf_pages


def build_pdf(page_count: int) -> bytes:
    page_ids = [3 + (index * 2) for index in range(page_count)]
    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        (
            f"<< /Type /Pages /Kids [{' '.join(f'{page_id} 0 R' for page_id in page_ids)}] "
            f"/Count {page_count} >>"
        ).encode(),
    ]

    for index, page_id in enumerate(page_ids):
        content_id = page_id + 1
        content = f"BT /F1 18 Tf 72 700 Td (PAGE {index + 1}) Tj ET".encode()
        objects.extend([
            (
                f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
                f"/Resources << /Font << /F1 {3 + (page_count * 2)} 0 R >> >> "
                f"/Contents {content_id} 0 R >>"
            ).encode(),
            f"<< /Length {len(content)} >>\nstream\n".encode()
            + content
            + b"\nendstream",
        ])

    objects.append(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>")
    pdf = bytearray(b"%PDF-1.4\n")
    offsets = [0]
    for object_id, body in enumerate(objects, start=1):
        offsets.append(len(pdf))
        pdf.extend(f"{object_id} 0 obj\n".encode() + body + b"\nendobj\n")

    xref_offset = len(pdf)
    pdf.extend(f"xref\n0 {len(offsets)}\n".encode())
    pdf.extend(b"0000000000 65535 f \n")
    for offset in offsets[1:]:
        pdf.extend(f"{offset:010d} 00000 n \n".encode())
    pdf.extend(
        f"trailer\n<< /Size {len(offsets)} /Root 1 0 R >>\n"
        f"startxref\n{xref_offset}\n%%EOF\n".encode()
    )
    return bytes(pdf)


class PdfInputTests(unittest.TestCase):
    def test_renders_single_page_cancellation_form(self):
        pages = render_pdf_pages(build_pdf(1))
        self.assertEqual(len(pages), 1)
        self.assertTrue(pages[0].startswith(b"\x89PNG\r\n\x1a\n"))

    def test_renders_two_pages_as_png(self):
        front, back = render_mykad_pdf_pages(build_pdf(2))
        self.assertTrue(front.startswith(b"\x89PNG\r\n\x1a\n"))
        self.assertTrue(back.startswith(b"\x89PNG\r\n\x1a\n"))
        self.assertNotEqual(front, back)

    def test_mykad_renderer_rejects_single_page(self):
        with self.assertRaisesRegex(PdfInputError, "exactly two pages"):
            render_mykad_pdf_pages(build_pdf(1))

    def test_generic_renderer_rejects_more_than_two_pages(self):
        with self.assertRaisesRegex(PdfInputError, "one cancellation-form page"):
            render_pdf_pages(build_pdf(3))

    def test_rejects_non_pdf(self):
        with self.assertRaisesRegex(PdfInputError, "not a PDF"):
            render_mykad_pdf_pages(b"not a PDF")


if __name__ == "__main__":
    unittest.main()
