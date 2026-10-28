BEGIN;
SET LOCAL lock_timeout = '5s';

DO $$
DECLARE
    status_constraint text;
BEGIN
    SELECT conname
    INTO status_constraint
    FROM pg_constraint
    WHERE conrelid = 'portal_tickets'::regclass
      AND contype = 'c'
      AND pg_get_constraintdef(oid) LIKE '%workflow_status%';

    IF status_constraint IS NULL THEN
        RAISE EXCEPTION 'Unable to find portal_tickets workflow_status constraint.';
    END IF;

    EXECUTE format('ALTER TABLE portal_tickets DROP CONSTRAINT %I', status_constraint);
    ALTER TABLE portal_tickets
        ADD CONSTRAINT ck_portal_tickets_workflow_status
        CHECK (workflow_status IN (
            'intake_pending', 'needs_information', 'ready_for_ocr', 'ocr_processing',
            'ocr_manual_review', 'ocr_rejected', 'ready_for_execution',
            'execution_processing', 'completed', 'failed', 'closed'
        ));
END $$;

DO $$
DECLARE
    status_constraint text;
BEGIN
    SELECT conname
    INTO status_constraint
    FROM pg_constraint
    WHERE conrelid = 'portal_ticket_documents'::regclass
      AND contype = 'c'
      AND pg_get_constraintdef(oid) LIKE '%ocr_status%';

    IF status_constraint IS NULL THEN
        RAISE EXCEPTION 'Unable to find portal_ticket_documents ocr_status constraint.';
    END IF;

    EXECUTE format('ALTER TABLE portal_ticket_documents DROP CONSTRAINT %I', status_constraint);
    ALTER TABLE portal_ticket_documents
        ADD CONSTRAINT ck_portal_ticket_documents_ocr_status
        CHECK (ocr_status IN (
            'pending', 'processing', 'valid', 'invalid', 'unreadable', 'error', 'manual_review'
        ));
END $$;

COMMIT;
