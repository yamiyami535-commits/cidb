from contextlib import asynccontextmanager
import hmac
from io import BytesIO
from pathlib import Path
import re
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
from . import diagnostics
from .document_validation import apply_document_validation, mandatory_document_failures
from .visual_validation import visual_service
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
from .verifier import (
    average_ocr_confidence,
    extract_document_name,
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
    extracted_document_number: str | None = None,
    extracted_name: str | None = None,
    extracted_address: str | None = None,
    extracted_text_lines: list[str] | None = None,
    processed_images: list[ProcessedImage] | None = None,
) -> VerificationResponse:
    return diagnostics.attach(VerificationResponse(
        verified=verified,
        status=status,
        message=message,
        document_type=document_type,
        images_quality=images_quality,
        documents=documents,
        comparison=comparison,
        ocr_average_confidence=ocr_average_confidence,
        extracted_document_number_masked=extracted_document_number_masked,
        extracted_document_number=extracted_document_number,
        extracted_name=extracted_name,
        extracted_address=extracted_address,
        extracted_text_lines=extracted_text_lines,
        processed_images=processed_images,
    ))


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
    extracted_document_number: str | None = None,
    extracted_name: str | None = None,
    extracted_address: str | None = None,
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
        extracted_document_number=extracted_document_number,
        extracted_name=extracted_name,
        extracted_address=extracted_address,
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
    visual_service.initialize()
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


@app.middleware("http")
async def diagnostic_observations(request: Request, call_next):
    # API-key authentication remains enforced by the existing endpoint dependencies.
    enabled = request.headers.get("x-ocr-diagnostics") == "1"
    token = diagnostics.current.set(diagnostics.Diagnostics() if enabled else None)
    try:
        response = await call_next(request)
        if enabled:
            response.headers["Cache-Control"] = "no-store"
        return response
    finally:
        diagnostics.current.reset(token)


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

    if diagnostics.current.get() is not None:
        diagnostics.current.get().image(decoded_image)
    quality = check_image_quality(
        decoded_image
    )

    return (
        file_bytes,
        decoded_image,
        quality,
    )


def run_ocr(decoded_image):
    if diagnostics.current.get() is not None:
        diagnostics.current.get().image(decoded_image)
    processed_image = preprocess_for_ocr(
        decoded_image
    )

    try:
        lines = ocr_service.recognize(
            processed_image
        )
        if diagnostics.current.get() is not None:
            diagnostics.current.get().text(lines, decoded_image)
        return lines

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


def extract_label_value(lines, labels: tuple[str, ...], max_lines: int = 1) -> str | None:
    stop_labels = (
        "NAMA", "NAME", "ALAMAT", "ADDRESS", "WARGANEGARA", "CITIZENSHIP",
        "JANTINA", "GENDER", "AGAMA", "RACE", "TARIKH LAHIR", "DATE OF BIRTH",
    )
    for index, line in enumerate(lines):
        text = " ".join(str(line.text).split()).strip()
        upper = text.upper()
        for label in labels:
            match = re.match(rf"^{re.escape(label)}\s*[:\-]?\s*(.*)$", upper, flags=re.IGNORECASE)
            if match:
                values = [match.group(1).strip()]
                for following in lines[index + 1:index + 1 + max_lines]:
                    candidate = " ".join(str(following.text).split()).strip()
                    if not candidate or any(candidate.upper().startswith(stop) for stop in stop_labels):
                        break
                    values.append(candidate)
                value = " ".join(part for part in values if part)
                return value or None
    return None


def extraction_result(
    *, images, lines, document_type, images_quality, extracted_name,
    extracted_number, documents=None, processed_images=None,
    extracted_address=None, success_status="MANUAL_REVIEW",
    failure_status=None, failure_message=None, require_confidence=False,
    include_text=True,
):
    """Return document-derived identity through the existing response payload.

    Customer-supplied values and comparison scores do not participate. The
    existing visual gate and status/storage contract are reused unchanged.
    """
    observer = diagnostics.current.get()
    if observer is not None:
        for image in images:
            observer.image(image)
        if not observer.ocr_ran:
            observer.text(lines)
    confidence = average_ocr_confidence(lines)
    if observer is not None:
        observer.additional_checks["ocr_confidence"] = {
            "state": ("passed" if confidence >= settings.min_ocr_confidence else "failed") if require_confidence else "informational",
            "reason": f"Average OCR confidence: {confidence:.3f}. " + (f"Required minimum: {settings.min_ocr_confidence:.3f}." if require_confidence else "This route does not apply an average-confidence gate."),
        }
    missing = mandatory_document_failures(
        cross_out_present=True, customer_signature_present=True,
        extracted_name=extracted_name, extracted_document_number=extracted_number,
    )
    if failure_status is None and missing:
        failure_status, failure_message = "INVALID_DOCUMENT", " ".join(missing)
    if failure_status is None and require_confidence and confidence < settings.min_ocr_confidence:
        failure_status = "MANUAL_REVIEW"
        failure_message = "The identity text was extracted, but OCR confidence requires manual review."
    response = build_verification_response(
        verified=failure_status is None,
        status=failure_status or success_status,
        message=failure_message or "Identity details were extracted from the submitted document.",
        document_type=document_type, images_quality=images_quality,
        documents=documents, ocr_average_confidence=round(confidence, 4),
        extracted_document_number_masked=mask_document_number(extracted_number),
        extracted_document_number=extracted_number, extracted_name=extracted_name,
        extracted_address=extracted_address,
        extracted_text_lines=(readable_ocr_lines(lines) or None) if include_text else None,
        processed_images=processed_images,
    )
    return apply_document_validation(
        response, images=images, extracted_name=extracted_name,
        extracted_document_number=extracted_number, service=visual_service,
    )


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

    # Legacy entered_* form fields remain accepted but are not used.

    normalized_document_type = (
        document_type.strip().lower()
    )

    if normalized_document_type == "mykad":
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

        front_document, extracted_nric = evaluate_mykad_front(front_ocr_lines)
        back_document = evaluate_mykad_back(back_ocr_lines)
        all_lines = list(front_ocr_lines) + list(back_ocr_lines)
        failure_status = failure_message = None
        if not back_ocr_lines:
            failure_status = "OCR_FAILED"
            failure_message = "No readable text was detected on the MyKad back image."
        elif not front_document.passed:
            failure_status = "INVALID_DOCUMENT"
            failure_message = "The front image does not appear to be a valid MyKad."
        elif not back_document.passed:
            failure_status = "MANUAL_REVIEW"
            failure_message = "The back image could not be confirmed confidently."
        return extraction_result(
            images=[front_image, back_image], lines=all_lines, document_type="mykad",
            images_quality=images_quality,
            documents={"mykad_front": front_document, "mykad_back": back_document},
            extracted_name=extract_document_name(front_ocr_lines), extracted_number=extracted_nric,
            processed_images=processed_images, success_status="VERIFIED",
            failure_status=failure_status, failure_message=failure_message, require_confidence=True,
        )

    if normalized_document_type == "passport":
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

        passport_document, passport_number = evaluate_passport(passport_ocr_lines)
        return extraction_result(
            images=[decoded_passport], lines=passport_ocr_lines, document_type="passport",
            images_quality=images_quality, documents={"passport": passport_document},
            extracted_name=extract_document_name(passport_ocr_lines, document_type="passport"),
            extracted_number=passport_number, processed_images=processed_images,
            success_status="VERIFIED", require_confidence=True,
            failure_status=None if passport_document.passed else "INVALID_DOCUMENT",
            failure_message=None if passport_document.passed else "The image does not appear to be a passport information page.",
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
    entered_name: str | None = Form(default=None),
    entered_nric: str | None = Form(default=None),
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
        form_document, extracted_nric = evaluate_mykad_front(form_ocr_lines)
        if diagnostics.current.get() is not None:
            diagnostics.current.get().additional_checks["document_cancellation_form"] = {
                "state": "passed" if form_document.passed else "failed",
                "reason": "Document recognition score: " + str(form_document.score),
            }
        eligible = form_quality.passed and form_document.passed
        return extraction_result(
            images=[form_image], lines=form_ocr_lines, document_type="cancellation_form",
            images_quality={"cancellation_form": form_quality},
            extracted_name=extract_document_name(form_ocr_lines), extracted_number=extracted_nric,
            include_text=False, failure_status=None if eligible else "MANUAL_REVIEW",
            failure_message=None if eligible else "The image or document could not be confirmed automatically.",
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
    return result


@app.post(
    "/api/verify-cancellation-form-pdf",
    response_model=VerificationResponse,
    response_model_exclude_none=True,
    dependencies=[Depends(require_ocr_api_key)],
)
async def verify_cancellation_form_pdf(
    document_pdf: UploadFile = File(...),
    entered_name: str | None = Form(default=None),
    entered_nric: str | None = Form(default=None),
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
    front_document, extracted_nric = evaluate_mykad_front(form_ocr_lines)
    eligible = form_quality.passed and front_document.passed
    return extraction_result(
        images=[form_image], lines=form_ocr_lines, document_type="cancellation_form",
        images_quality={"cancellation_form": form_quality}, documents={"mykad_front": front_document},
        extracted_name=extract_document_name(form_ocr_lines), extracted_number=extracted_nric,
        extracted_address=extract_label_value(form_ocr_lines, ("ALAMAT", "ADDRESS"), max_lines=3),
        failure_status=None if eligible else "MANUAL_REVIEW",
        failure_message=None if eligible else "The image or document could not be confirmed automatically.",
    )


@app.post(
    "/api/verify-mykad-image",
    response_model=VerificationResponse,
    response_model_exclude_none=True,
    dependencies=[Depends(require_ocr_api_key)],
)
async def verify_mykad_image(
    image_file: UploadFile = File(...),
    entered_name: str | None = Form(default=None),
    entered_nric: str | None = Form(default=None),
):
    if image_file.content_type not in ALLOWED_CONTENT_TYPES:
        raise HTTPException(status_code=400, detail="Upload a supported MyKad image.")
    _, image, quality = await read_and_validate_image(image_file)
    lines = run_ocr(image)
    document, extracted_nric = evaluate_mykad_front(lines)
    eligible = quality.passed and document.passed
    return extraction_result(
        images=[image], lines=lines, document_type="mykad",
        images_quality={"mykad_image": quality}, documents={"mykad_front": document},
        extracted_name=extract_document_name(lines), extracted_number=extracted_nric,
        extracted_address=extract_label_value(lines, ("ALAMAT", "ADDRESS"), max_lines=3),
        failure_status=None if eligible else "MANUAL_REVIEW",
        failure_message=None if eligible else "The image or document could not be confirmed automatically.",
    )
