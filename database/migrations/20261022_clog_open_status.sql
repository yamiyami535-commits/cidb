BEGIN;
SET LOCAL lock_timeout = '5s';

DO $$ BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'cidb_interaction_data'
          AND column_name = 'current_status'
    ) THEN
        ALTER TABLE cidb_interaction_data
            ALTER COLUMN current_status SET DEFAULT 'Open';
    END IF;
END $$;

DO $$ BEGIN
    IF to_regclass('cidb_interaction_data') IS NOT NULL
       AND EXISTS (
           SELECT 1 FROM information_schema.columns
           WHERE table_schema = current_schema()
             AND table_name = 'cidb_interaction_data'
             AND column_name = 'current_status'
       )
       AND EXISTS (
           SELECT 1 FROM information_schema.columns
           WHERE table_schema = current_schema()
             AND table_name = 'cidb_interaction_data'
             AND column_name = 'outcome_code'
       ) THEN
        EXECUTE $view$
            CREATE OR REPLACE VIEW portal_ticket_interaction_status AS
            SELECT
                t.id AS ticket_id,
                t.clog_crm_id,
                i.current_status,
                i.outcome_code AS interaction_outcome_code
            FROM portal_tickets t
            LEFT JOIN cidb_interaction_data i ON i.crm_id = t.clog_crm_id
        $view$;
    END IF;
END $$;

COMMIT;
