"""Mandatory document rules, independent of OCR and visual detection engines.

Mark evidence must come from the submitted image, before service watermarks are
added. A signature flag must describe a customer-added mark, not merely the
presence of any signature on the original identification document. CIDB wording
is deliberately not an input to the approval rule.
"""


def mandatory_document_failures(
    *,
    cross_out_present: bool,
    customer_signature_present: bool,
    extracted_name: str | None,
    extracted_document_number: str | None,
) -> tuple[str, ...]:
    """Return rejection reasons; no failures means the mandatory rules passed.

    Callers must still apply the existing document and
    image-quality checks. Unknown visual evidence must not count as a pass.
    """
    failures: list[str] = []
    if cross_out_present is not True:
        failures.append("No identifiable cross-out/cross-over marking was found.")
    if customer_signature_present is not True:
        failures.append("No customer-added handwritten signature or signature-like pen marking was found.")
    if not extracted_name or not extracted_name.strip():
        failures.append("The name could not be extracted from the identification document.")
    if not extracted_document_number or not extracted_document_number.strip():
        failures.append("The NRIC/passport number could not be extracted from the identification document.")
    return tuple(failures)


def apply_document_validation(response, *, images, extracted_name, extracted_document_number, service):
    """Tighten an existing identity result without changing its response schema.

    Existing failed identity/quality checks retain their status and payload.
    Only an otherwise eligible submission incurs visual inference.
    """
    from .visual_types import Evidence
    from .diagnostics import current, attach

    if not response.verified:
        return response
    # Clear the approval first: even exceptions must never retain an old pass.
    response.verified = False
    identity_failures = mandatory_document_failures(
        cross_out_present=True,
        customer_signature_present=True,
        extracted_name=extracted_name,
        extracted_document_number=extracted_document_number,
    )
    if identity_failures:
        response.status = "INVALID_DOCUMENT"
        response.message = " ".join(identity_failures)
        return response
    try:
        visual = service.inspect(images)
        if current.get() is not None:
            current.get().visual = {"cross_out": visual.cross_out.value,
                                   "customer_signature": visual.signature.value,
                                   "pages_checked": visual.pages_checked, "reason": ""}
        states = (visual.cross_out, visual.signature)
        if Evidence.ERROR in states:
            response.status = "INTERNAL_ERROR"
            response.message = "Visual document validation is unavailable or failed. Automatic approval was withheld."
        elif visual.pages_checked != len(images):
            response.status = "MANUAL_REVIEW"
            response.message = "Visual inspection could not cover the entire submission. Automatic approval was withheld."
        elif Evidence.ABSENT in states:
            response.status = "INVALID_DOCUMENT"
            response.message = " ".join(mandatory_document_failures(
                cross_out_present=visual.cross_out != Evidence.ABSENT,
                customer_signature_present=visual.signature != Evidence.ABSENT,
                extracted_name=extracted_name,
                extracted_document_number=extracted_document_number,
            ))
        elif states != (Evidence.PRESENT, Evidence.PRESENT):
            response.status = "MANUAL_REVIEW"
            response.message = "The cross-out or customer-added signature could not be confirmed confidently. Automatic approval was withheld."
        else:
            response.verified = True
            response.message = "Identity, image quality, cross-out and customer-added signature checks passed."
    except Exception:
        response.status = "INTERNAL_ERROR"
        response.message = "Visual document validation failed. Automatic approval was withheld."
        if current.get() is not None:
            current.get().visual = {"cross_out": "error", "customer_signature": "error",
                                   "pages_checked": 0, "reason": response.message}
    if current.get() is not None and current.get().visual:
        current.get().visual["reason"] = response.message
    return attach(response)
