from io import BytesIO

import pypdfium2 as pdfium


class PdfInputError(ValueError):
    pass


def render_pdf_pages(file_bytes: bytes) -> list[bytes]:
    if not file_bytes.startswith(b"%PDF-"):
        raise PdfInputError("The uploaded file is not a PDF.")

    document = None
    try:
        document = pdfium.PdfDocument(file_bytes)
        if len(document) not in (1, 2):
            raise PdfInputError(
                "A PDF must contain one cancellation-form page or two MyKad pages."
            )

        rendered_pages: list[bytes] = []
        for page_index in range(len(document)):
            page = document[page_index]
            width_points, height_points = page.get_size()
            if width_points <= 0 or height_points <= 0 or max(width_points, height_points) > 3000:
                page.close()
                raise PdfInputError("A PDF page has unsupported dimensions.")

            bitmap = page.render(scale=1.5)
            image = bitmap.to_pil()
            output = BytesIO()
            image.save(output, format="PNG")
            rendered_pages.append(output.getvalue())
            bitmap.close()
            page.close()
    except PdfInputError:
        raise
    except Exception as error:
        raise PdfInputError("The PDF could not be read.") from error
    finally:
        if document is not None:
            document.close()

    return rendered_pages


def render_mykad_pdf_pages(file_bytes: bytes) -> tuple[bytes, bytes]:
    rendered_pages = render_pdf_pages(file_bytes)
    if len(rendered_pages) != 2:
        raise PdfInputError("A MyKad PDF must contain exactly two pages: front, then back.")
    return rendered_pages[0], rendered_pages[1]
