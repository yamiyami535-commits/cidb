-- Restore CRM storage without inventing values for requests submitted without CRM.
-- The portal validates CRM as required for every new submission.
BEGIN;
SET LOCAL lock_timeout = '5s';

ALTER TABLE portal_requests ADD COLUMN IF NOT EXISTS crim varchar(120);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'portal_requests'::regclass
          AND conname = 'ck_portal_requests_crim'
    ) THEN
        ALTER TABLE portal_requests ADD CONSTRAINT ck_portal_requests_crim
            CHECK (length(trim(crim)) > 0);
    END IF;

    -- Historical requests without CRM must still accept status/result updates.
    IF NOT EXISTS (SELECT 1 FROM portal_requests WHERE crim IS NULL) THEN
        ALTER TABLE portal_requests ALTER COLUMN crim SET NOT NULL;
    END IF;
END
$$;

COMMIT;
