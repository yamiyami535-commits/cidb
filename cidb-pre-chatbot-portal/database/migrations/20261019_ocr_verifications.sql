BEGIN;
SET LOCAL lock_timeout = '5s';

CREATE TABLE IF NOT EXISTS portal_ocr_verifications (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    status varchar(32) NOT NULL,
    document_type varchar(16),
    masked_document_number varchar(32),
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT ck_portal_ocr_verifications_status CHECK (status IN (
        'VERIFIED', 'REUPLOAD_REQUIRED', 'INVALID_DOCUMENT', 'DETAILS_MISMATCH',
        'OCR_FAILED', 'MANUAL_REVIEW', 'INVALID_REQUEST', 'INTERNAL_ERROR'
    )),
    CONSTRAINT ck_portal_ocr_verifications_document_type CHECK (
        document_type IS NULL OR document_type IN ('mykad', 'passport')
    )
);

CREATE INDEX IF NOT EXISTS ix_portal_ocr_verifications_created
    ON portal_ocr_verifications (created_at DESC);

COMMIT;
