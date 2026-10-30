from contextlib import asynccontextmanager
import hmac
from io import BytesIO
from pathlib import Path
import traceback
from typing import Any

from fastapi import (
    FastAPI,
    File,
    Form,
    Depends,
    Header,
    HTTPException,
    Request,
    UploadFile,
)
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse, JSONResponse
from starlette.datastructures import Headers

from .config import settings
from .image_quality import (
    check_image_quality,
    decode_image,
    preprocess_for_ocr,
)
from .models import (
    ProcessedImage,
    VerificationResponse,
)
from .pdf_input import PdfInputError, render_pdf_pages
from .ocr_service import ocr_service
from .text_utils import validate_nric_format
from .verifier import (
    average_ocr_confidence,
    compare_mykad_details,
    compare_passport_details,
    extract_nric,
    evaluate_mykad_back,
    evaluate_mykad_front,
    evaluate_passport,
    mask_document_number,
)
from .watermark_service import (
    add_cidb_label,
    encode_image_as_data_url,
)


ALLOWED_CONTENT_TYPES = {
    "image/jpeg",
    "image/jpg",
    "image/png",
    "image/webp",
}

INVALID_REQUEST_STATUS = "INVALID_REQUEST"
INTERNAL_ERROR_STATUS = "INTERNAL_ERROR"


def build_verification_response(
    *,
    verified: bool,
    status: str,
    message: str,
    document_type: str | None = None,
    images_quality: dict[str, Any] | None = None,
    documents: dict[str, Any] | None = None,
    comparison: Any | None = None,
    ocr_average_confidence: float | None = None,
    extracted_document_number_masked: str | None = None,
    extracted_text_lines: list[str] | None = None,
    processed_images: list[ProcessedImage] | None = None,
) -> VerificationResponse:
    return VerificationResponse(
        verified=verified,
        status=status,
        message=message,
        document_type=document_type,
        images_quality=images_quality,
        documents=documents,
        comparison=comparison,
        ocr_average_confidence=ocr_average_confidence,
        extracted_document_number_masked=extracted_document_number_masked,
        extracted_text_lines=extracted_text_lines,
        processed_images=processed_images,
    )


def verification_json_response(
    *,
    status_code: int,
    verified: bool,
    status: str,
    message: str,
    document_type: str | None = None,
    images_quality: dict[str, Any] | None = None,
    documents: dict[str, Any] | None = None,
    comparison: Any | None = None,
    ocr_average_confidence: float | None = None,
    extracted_document_number_masked: str | None = None,
    extracted_text_lines: list[str] | None = None,
    processed_images: list[ProcessedImage] | None = None,
) -> JSONResponse:
    payload = build_verification_response(
        verified=verified,
        status=status,
        message=message,
        document_type=document_type,
        images_quality=images_quality,
        documents=documents,
        comparison=comparison,
        ocr_average_confidence=ocr_average_confidence,
        extracted_document_number_masked=extracted_document_number_masked,
        extracted_text_lines=extracted_text_lines,
        processed_images=processed_images,
    )

    return JSONResponse(
        status_code=status_code,
        content=payload.model_dump(exclude_none=True),
    )


def validation_message_from_errors(
    errors: list[dict[str, Any]],
) -> str:
    if not errors:
        return "The submitted request is invalid."

    parts: list[str] = []

    for error in errors:
        location = error.get("loc", [])
        field_name = next(
            (
                str(part)
                for part in location
                if part not in {"body", "query", "path"}
            ),
            None,
        )
        message = str(error.get("msg", "is invalid"))

        if field_name:
            parts.append(f"{field_name} {message}")
        else:
            parts.append(message)

    return parts[0] if parts else "The submitted request is invalid."


def invalid_request_response(
    message: str,
    *,
    status_code: int = 400,
    document_type: str | None = None,
) -> JSONResponse:
    return verification_json_response(
        status_code=status_code,
        verified=False,
        status=INVALID_REQUEST_STATUS,
        message=message,
        document_type=document_type,
    )


@asynccontextmanager
async def lifespan(app: FastAPI):
    _ = ocr_service
    yield


app = FastAPI(
    title="CIDB Identity Document Verification API",
    version="2.0.0",
    lifespan=lifespan,
)


app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_origins,
    allow_credentials=True,
    allow_methods=["GET", "POST"],
    allow_headers=["*"],
)


@app.exception_handler(HTTPException)
async def http_exception_handler(
    request: Request,  # noqa: ARG001
    exc: HTTPException,
):
    if exc.status_code >= 500:
        return verification_json_response(
            status_code=exc.status_code,
            verified=False,
            status="OCR_FAILED",
            message=(
                "Unable to read the uploaded document."
            ),
        )

    return verification_json_response(
        status_code=exc.status_code,
        verified=False,
        status=INVALID_REQUEST_STATUS,
        message=str(exc.detail),
    )


@app.exception_handler(RequestValidationError)
async def request_validation_handler(
    request: Request,  # noqa: ARG001
    exc: RequestValidationError,
):
    return verification_json_response(
        status_code=422,
        verified=False,
        status=INVALID_REQUEST_STATUS,
        message=validation_message_from_errors(
            exc.errors()
        ),
    )


@app.exception_handler(Exception)
async def unhandled_exception_handler(
    request: Request,  # noqa: ARG001
    exc: Exception,
):
    print("\n========== UNHANDLED ERROR ==========")
    traceback.print_exc()
    print("=====================================\n")

    return verification_json_response(
        status_code=500,
        verified=False,
        status=INTERNAL_ERROR_STATUS,
        message=(
            "An unexpected error occurred while "
            "processing the document."
        ),
    )


async def require_ocr_api_key(
    x_api_key: str | None = Header(default=None),
) -> None:
    if settings.api_key and not hmac.compare_digest(x_api_key or "", settings.api_key):
        raise HTTPException(status_code=401, detail="Invalid OCR API key.")


@app.get("/")
def test_page():
    static_file = (
        Path(__file__).resolve().parent.parent
        / "static"
        / "index.html"
    )

    return FileResponse(static_file)


@app.get("/health")
def health():
    return {
        "status": "ok",
        "service": (
            "cidb-identity-document-verification"
        ),
        "version": "2.0.0",
    }


async def read_and_validate_image(
    image: UploadFile,
) -> tuple[bytes, object, object]:
    if image.content_type not in ALLOWED_CONTENT_TYPES:
        raise HTTPException(
            status_code=400,
            detail=(
                "Only JPG, JPEG, PNG and WEBP "
                "image files are accepted."
            ),
        )

    file_bytes = await image.read()

    if not file_bytes:
        raise HTTPException(
            status_code=400,
            detail="One of the uploaded images is empty.",
        )

    if len(file_bytes) > settings.max_file_size_bytes:
        raise HTTPException(
            status_code=413,
            detail=(
                f"Each image must be smaller than "
                f"{settings.max_file_size_mb} MB."
            ),
        )

    try:
        decoded_image = decode_image(file_bytes)

    except ValueError as error:
        raise HTTPException(
            status_code=400,
            detail=str(error),
        ) from error

    quality = check_image_quality(
        decoded_image
    )

    return (
        file_bytes,
        decoded_image,
        quality,
    )


def run_ocr(decoded_image):
    processed_image = preprocess_for_ocr(
        decoded_image
    )

    try:
        return ocr_service.recognize(
            processed_image
        )

    except Exception as error:
        print("\n========== OCR ERROR ==========")
        traceback.print_exc()
        print("================================\n")

        raise HTTPException(
            status_code=500,
            detail=(
                "Unable to read the uploaded "
                "document."
            ),
        ) from error


def readable_ocr_lines(lines) -> list[str]:
    return [
        line.text
        for line in lines
        if line.confidence >= 0.45
    ]


@app.post(
    "/api/verify-document",
    response_model=VerificationResponse,
    response_model_exclude_none=True,
    dependencies=[Depends(require_ocr_api_key)],
)
async def verify_document(
    document_type: str | None = Form(
        default=None,
    ),

    entered_name: str | None = Form(
        default=None,
    ),

    entered_nric: str | None = Form(
        default=None,
    ),

    entered_passport_number: str | None = Form(
        default=None,
    ),

    mykad_front: UploadFile | None = File(
        default=None,
    ),

    mykad_back: UploadFile | None = File(
        default=None,
    ),

    passport_image: UploadFile | None = File(
        default=None,
    ),
):
    if not document_type or not document_type.strip():
        raise HTTPException(
            status_code=422,
            detail="document_type is required.",
        )

    if not entered_name or not entered_name.strip():
        raise HTTPException(
            status_code=422,
            detail="entered_name is required.",
        )

    entered_name = entered_name.strip()

    normalized_document_type = (
        document_type.strip().lower()
    )

    if normalized_document_type == "mykad":
        if not entered_nric or not entered_nric.strip():
            raise HTTPException(
                status_code=422,
                detail="NRIC number is required.",
            )

        entered_nric = entered_nric.strip()

        if not validate_nric_format(entered_nric):
            raise HTTPException(
                status_code=400,
                detail=(
                    "The entered NRIC is invalid. "
                    "Use a value such as "
                    "900101-10-1234."
                ),
            )

        if not mykad_front or not mykad_back:
            raise HTTPException(
                status_code=422,
                detail=(
                    "Both the MyKad front and "
                    "MyKad back images are required."
                ),
            )

        (
            _,
            front_image,
            front_quality,
        ) = await read_and_validate_image(
            mykad_front
        )

        (
            _,
            back_image,
            back_quality,
        ) = await read_and_validate_image(
            mykad_back
        )

        watermarked_front = add_cidb_label(
            front_image,
            "mykad_front",
        )

        watermarked_back = add_cidb_label(
            back_image,
            "mykad_back",
        )

        processed_images = [
            ProcessedImage(
                label="MyKad Front",
                filename=(
                    "mykad_front_cidb.jpg"
                ),
                data_url=encode_image_as_data_url(
                    watermarked_front
                ),
            ),
            ProcessedImage(
                label="MyKad Back",
                filename=(
                    "mykad_back_cidb.jpg"
                ),
                data_url=encode_image_as_data_url(
                    watermarked_back
                ),
            ),
        ]

        images_quality = {
            "mykad_front": front_quality,
            "mykad_back": back_quality,
        }

        if (
            not front_quality.passed
            or not back_quality.passed
        ):
            return build_verification_response(
                verified=False,
                status="REUPLOAD_REQUIRED",
                message=(
                    "One or both MyKad images do "
                    "not meet the required image "
                    "quality. Please upload clearer "
                    "photos."
                ),
                document_type="mykad",
                images_quality=images_quality,
                processed_images=processed_images,
            )

        front_ocr_lines = run_ocr(
            front_image
        )

        back_ocr_lines = run_ocr(
            back_image
        )

        if not front_ocr_lines:
            return build_verification_response(
                verified=False,
                status="OCR_FAILED",
                message=(
                    "No readable text was detected "
                    "on the MyKad front image."
                ),
                document_type="mykad",
                images_quality=images_quality,
                processed_images=processed_images,
            )

        front_document, extracted_nric = (
            evaluate_mykad_front(
                front_ocr_lines
            )
        )

        back_document = evaluate_mykad_back(
            back_ocr_lines
        )

        comparison = compare_mykad_details(
            entered_name=entered_name,
            entered_nric=entered_nric,
            extracted_nric=extracted_nric,
            lines=front_ocr_lines,
        )

        all_lines = (
            list(front_ocr_lines)
            + list(back_ocr_lines)
        )

        if not back_ocr_lines:
            return build_verification_response(
                verified=False,
                status="OCR_FAILED",
                message=(
                    "No readable text was detected "
                    "on the MyKad back image."
                ),
                document_type="mykad",
                images_quality=images_quality,
                processed_images=processed_images,
            )

        ocr_confidence = (
            average_ocr_confidence(
                all_lines
            )
        )

        documents = {
            "mykad_front": front_document,
            "mykad_back": back_document,
        }

        extracted_lines = (
            readable_ocr_lines(
                front_ocr_lines
            )
            + readable_ocr_lines(
                back_ocr_lines
            )
        )

        if not front_document.passed:
            return build_verification_response(
                verified=False,
                status="INVALID_DOCUMENT",
                message=(
                    "The front image does not "
                    "appear to be a valid MyKad."
                ),
                document_type="mykad",
                images_quality=images_quality,
                documents=documents,
                comparison=comparison,
                ocr_average_confidence=round(
                    ocr_confidence,
                    4,
                ),
                extracted_document_number_masked=(
                    mask_document_number(
                        extracted_nric
                    )
                ),
                extracted_text_lines=(
                    extracted_lines or None
                ),
                processed_images=processed_images,
            )

        if not back_document.passed:
            return build_verification_response(
                verified=False,
                status="MANUAL_REVIEW",
                message=(
                    "The front image is recognised, "
                    "but the back image could not be "
                    "confirmed confidently. Send the "
                    "submission for manual review."
                ),
                document_type="mykad",
                images_quality=images_quality,
                documents=documents,
                comparison=comparison,
                ocr_average_confidence=round(
                    ocr_confidence,
                    4,
                ),
                extracted_document_number_masked=(
                    mask_document_number(
                        extracted_nric
                    )
                ),
                extracted_text_lines=(
                    extracted_lines or None
                ),
                processed_images=processed_images,
            )

        if (
            comparison.document_number_match
            and comparison.name_match
            and ocr_confidence
            >= settings.min_ocr_confidence
        ):
            return build_verification_response(
                verified=True,
                status="VERIFIED",
                message=(
                    "The MyKad front and back "
                    "images were confirmed, and "
                    "the submitted details match."
                ),
                document_type="mykad",
                images_quality=images_quality,
                documents=documents,
                comparison=comparison,
                ocr_average_confidence=round(
                    ocr_confidence,
                    4,
                ),
                extracted_document_number_masked=(
                    mask_document_number(
                        extracted_nric
                    )
                ),
                extracted_text_lines=(
                    extracted_lines or None
                ),
                processed_images=processed_images,
            )

        if (
            comparison.document_number_match
            and comparison.name_similarity
            >= settings.manual_review_name_score
        ):
            status = "MANUAL_REVIEW"
            message = (
                "The NRIC matches, but the name "
                "or OCR result needs manual review."
            )

        else:
            status = "DETAILS_MISMATCH"
            message = (
                "The MyKad information does not "
                "match the entered details."
            )

        return build_verification_response(
            verified=False,
            status=status,
            message=message,
            document_type="mykad",
            images_quality=images_quality,
            documents=documents,
            comparison=comparison,
            ocr_average_confidence=round(
                ocr_confidence,
                4,
            ),
            extracted_document_number_masked=(
                mask_document_number(
                    extracted_nric
                )
            ),
            extracted_text_lines=extracted_lines or None,
            processed_images=processed_images,
        )

    if normalized_document_type == "passport":
        if not entered_passport_number or not entered_passport_number.strip():
            raise HTTPException(
                status_code=422,
                detail=(
                    "Passport number is required."
                ),
            )

        entered_passport_number = (
            entered_passport_number.strip()
        )

        if not passport_image:
            raise HTTPException(
                status_code=422,
                detail=(
                    "A passport information-page "
                    "image is required."
                ),
            )

        (
            _,
            decoded_passport,
            passport_quality,
        ) = await read_and_validate_image(
            passport_image
        )

        watermarked_passport = add_cidb_label(
            decoded_passport,
            "passport",
        )

        processed_images = [
            ProcessedImage(
                label="Passport",
                filename="passport_cidb.jpg",
                data_url=encode_image_as_data_url(
                    watermarked_passport
                ),
            )
        ]

        images_quality = {
            "passport": passport_quality,
        }

        if not passport_quality.passed:
            return build_verification_response(
                verified=False,
                status="REUPLOAD_REQUIRED",
                message=(
                    "The passport image does not "
                    "meet the required image quality. "
                    "Please upload a clearer image."
                ),
                document_type="passport",
                images_quality=images_quality,
                processed_images=processed_images,
            )

        passport_ocr_lines = run_ocr(
            decoded_passport
        )

        if not passport_ocr_lines:
            return build_verification_response(
                verified=False,
                status="OCR_FAILED",
                message=(
                    "No readable text was detected "
                    "on the passport image."
                ),
                document_type="passport",
                images_quality=images_quality,
                processed_images=processed_images,
            )

        passport_document, passport_number = (
            evaluate_passport(
                lines=passport_ocr_lines,
                entered_passport_number=(
                    entered_passport_number
                ),
            )
        )

        comparison = compare_passport_details(
            entered_name=entered_name,
            entered_passport_number=(
                entered_passport_number
            ),
            extracted_passport_number=(
                passport_number
            ),
            lines=passport_ocr_lines,
        )

        ocr_confidence = (
            average_ocr_confidence(
                passport_ocr_lines
            )
        )

        extracted_lines = readable_ocr_lines(
            passport_ocr_lines
        )

        documents = {
            "passport": passport_document,
        }

        if not passport_document.passed:
            return build_verification_response(
                verified=False,
                status="INVALID_DOCUMENT",
                message=(
                    "The uploaded image does not "
                    "appear to be a passport "
                    "information page."
                ),
                document_type="passport",
                images_quality=images_quality,
                documents=documents,
                comparison=comparison,
                ocr_average_confidence=round(
                    ocr_confidence,
                    4,
                ),
                extracted_document_number_masked=(
                    mask_document_number(
                        passport_number
                    )
                ),
                extracted_text_lines=(
                    extracted_lines or None
                ),
                processed_images=processed_images,
            )

        if (
            comparison.document_number_match
            and comparison.name_match
            and ocr_confidence
            >= settings.min_ocr_confidence
        ):
            return build_verification_response(
                verified=True,
                status="VERIFIED",
                message=(
                    "The passport details match "
                    "the information entered by "
                    "the user."
                ),
                document_type="passport",
                images_quality=images_quality,
                documents=documents,
                comparison=comparison,
                ocr_average_confidence=round(
                    ocr_confidence,
                    4,
                ),
                extracted_document_number_masked=(
                    mask_document_number(
                        passport_number
                    )
                ),
                extracted_text_lines=(
                    extracted_lines or None
                ),
                processed_images=processed_images,
            )

        if (
            comparison.document_number_match
            and comparison.name_similarity
            >= settings.manual_review_name_score
        ):
            status = "MANUAL_REVIEW"
            message = (
                "The passport number matches, "
                "but the name or OCR confidence "
                "requires manual review."
            )

        else:
            status = "DETAILS_MISMATCH"
            message = (
                "The passport details do not "
                "match the entered information."
            )

        return build_verification_response(
            verified=False,
            status=status,
            message=message,
            document_type="passport",
            images_quality=images_quality,
            documents=documents,
            comparison=comparison,
            ocr_average_confidence=round(
                ocr_confidence,
                4,
            ),
            extracted_document_number_masked=(
                mask_document_number(
                    passport_number
                )
            ),
            extracted_text_lines=extracted_lines or None,
            processed_images=processed_images,
        )

    raise HTTPException(
        status_code=400,
        detail=(
            "Invalid document type. Select "
            "either MyKad or Passport."
        ),
    )


@app.post(
    "/api/verify-mykad-pdf",
    response_model=VerificationResponse,
    response_model_exclude_none=True,
    dependencies=[Depends(require_ocr_api_key)],
)
async def verify_mykad_pdf(
    document_pdf: UploadFile = File(...),
    entered_name: str = Form(...),
    entered_nric: str = Form(...),
):
    if document_pdf.content_type != "application/pdf":
        raise HTTPException(status_code=400, detail="Upload a PDF containing the MyKad front and back.")

    file_bytes = await document_pdf.read(settings.max_pdf_size_bytes + 1)
    if not file_bytes:
        raise HTTPException(status_code=400, detail="The uploaded PDF is empty.")
    if len(file_bytes) > settings.max_pdf_size_bytes:
        raise HTTPException(
            status_code=413,
            detail=f"The PDF must be smaller than {settings.max_pdf_size_mb} MB.",
        )

    try:
        rendered_pages = render_pdf_pages(file_bytes)
    except PdfInputError as error:
        raise HTTPException(status_code=400, detail=str(error)) from error

    if len(rendered_pages) == 1:
        form_image = decode_image(rendered_pages[0])
        form_quality = check_image_quality(form_image)
        form_ocr_lines = run_ocr(form_image)
        extracted_nric = extract_nric(form_ocr_lines)
        comparison = compare_mykad_details(
            entered_name=entered_name,
            entered_nric=entered_nric,
            extracted_nric=extracted_nric,
            lines=form_ocr_lines,
        )
        return build_verification_response(
            verified=False,
            status="MANUAL_REVIEW",
            message=(
                "OCR inspected the single-page cancellation form and compared "
                "identity text where readable. An authorised reviewer must "
                "visually confirm the marked/crossed-out IC and signature; "
                "OCR does not verify those marks."
            ),
            document_type="cancellation_form",
            images_quality={"cancellation_form": form_quality},
            comparison=comparison,
            ocr_average_confidence=round(
                average_ocr_confidence(form_ocr_lines),
                4,
            ),
            extracted_document_number_masked=mask_document_number(extracted_nric),
        )

    front_bytes, back_bytes = rendered_pages
    front_upload = UploadFile(
        file=BytesIO(front_bytes),
        filename="mykad_front.png",
        headers=Headers({"content-type": "image/png"}),
    )
    back_upload = UploadFile(
        file=BytesIO(back_bytes),
        filename="mykad_back.png",
        headers=Headers({"content-type": "image/png"}),
    )
    result = await verify_document(
        document_type="mykad",
        entered_name=entered_name,
        entered_nric=entered_nric,
        entered_passport_number=None,
        mykad_front=front_upload,
        mykad_back=back_upload,
        passport_image=None,
    )

    if result.status == "VERIFIED":
        result.status = "MANUAL_REVIEW"
        result.verified = False
        result.message = (
            "The identity details matched, but the required CIDB-use cross-out, "
            "director signature, and company stamp have not been machine-checked. "
            "An authorised reviewer must confirm the complete submission."
        )
    else:
        result.message = (
            result.message
            + " The CIDB-use cross-out, director signature, and company stamp "
            "have not been machine-checked."
        )
    return result


@app.post(
    "/api/verify-cancellation-form-pdf",
    response_model=VerificationResponse,
    response_model_exclude_none=True,
    dependencies=[Depends(require_ocr_api_key)],
)
async def verify_cancellation_form_pdf(
    document_pdf: UploadFile = File(...),
):
    if document_pdf.content_type != "application/pdf":
        raise HTTPException(
            status_code=400,
            detail="Upload a one-page cancellation-form PDF.",
        )

    file_bytes = await document_pdf.read(settings.max_pdf_size_bytes + 1)
    if not file_bytes:
        raise HTTPException(status_code=400, detail="The uploaded PDF is empty.")
    if len(file_bytes) > settings.max_pdf_size_bytes:
        raise HTTPException(
            status_code=413,
            detail=f"The PDF must be smaller than {settings.max_pdf_size_mb} MB.",
        )

    try:
        rendered_pages = render_pdf_pages(file_bytes)
    except PdfInputError as error:
        raise HTTPException(status_code=400, detail=str(error)) from error
    if len(rendered_pages) != 1:
        raise HTTPException(
            status_code=400,
            detail="A cancellation-form PDF must contain exactly one page.",
        )

    form_image = decode_image(rendered_pages[0])
    form_quality = check_image_quality(form_image)
    form_ocr_lines = run_ocr(form_image)
    extracted_nric = extract_nric(form_ocr_lines)
    return build_verification_response(
        verified=False,
        status="MANUAL_REVIEW",
        message=(
            "OCR inspected the cancellation form. An authorised reviewer must "
            "visually confirm the marked/crossed-out IC and signature; OCR "
            "does not verify those marks or compare identity to a case record."
        ),
        document_type="cancellation_form",
        images_quality={"cancellation_form": form_quality},
        ocr_average_confidence=round(
            average_ocr_confidence(form_ocr_lines),
            4,
        ),
        extracted_document_number_masked=mask_document_number(extracted_nric),
    )
