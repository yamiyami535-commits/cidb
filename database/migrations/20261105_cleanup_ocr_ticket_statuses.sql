BEGIN;
SET LOCAL lock_timeout = '5s';

UPDATE portal_tickets
SET workflow_status = CASE workflow_status
    WHEN 'ocr_processing' THEN 'ready_for_ocr'
    WHEN 'ocr_manual_review' THEN 'ready_for_ocr'
    WHEN 'ocr_rejected' THEN 'needs_information'
    ELSE workflow_status
END,
updated_at = NOW()
WHERE workflow_status IN ('ocr_processing', 'ocr_manual_review', 'ocr_rejected');

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
            'intake_pending', 'needs_information', 'ready_for_ocr', 'ready_for_execution',
            'execution_processing', 'completed', 'failed', 'closed'
        ));
END $$;

COMMIT;
