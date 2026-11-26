# Documentation relevance and reading guide

Reviewed against the repository on 13 November 2026. This is a source-code review, not verification of deployed services, database migrations, scheduler configuration, or live RPA results.

All nine pre-existing documents remain relevant. Some contain historical or outdated statements; use the qualifications below. No existing document needs deletion based on this review.

| Existing document | Relevance | Qualifications / current interpretation |
| --- | --- | --- |
| [clog-sync.md](clog-sync.md) | Current CLOG source mapping and scoped repair guide. | A ready attachment is a reference, not a downloaded file. Cron2 can read Closed reply rows while the ticket must still have an effective Open CLOG status. |
| [ticket-workflow.md](ticket-workflow.md) | Current cancellation eligibility, statuses, migration order, and direct Hub payload guide. | Its instruction to schedule Cron1 and Cron2 separately conflicts with the Windows wrapper guide. For the supplied Windows setup, use one sequential wrapper task; do not also schedule a second Cron2 task. |
| [ocr-process-flow.md](ocr-process-flow.md) | Current end-to-end scheduled OCR explanation. | Covers the actual PDF/image routing, identity flag, storage, retries, and RPA gate. OCR verification is not cancellation completion. |
| [ocr-internal-test.md](ocr-internal-test.md) | Relevant controlled validation checklist. | Describes checks to perform, not proof they have passed. The automatic gate evaluates all **ready customer documents**; the ticket's effective CLOG status must be Open. |
| [windows-server-cron-setup.md](windows-server-cron-setup.md) | Current Windows scheduling runbook for `run-cron-sequence.ps1`. | Sample paths and intervals require deployment-specific configuration. The repository does not establish that a production task is installed. |
| [ticket-workspace.md](ticket-workspace.md) | Relevant read-only staff UI, access, privacy, and test guide. | Commit-restoration statements are historical. The current `database/schema.sql` includes the ticket migrations and views, so the statement that the original schema is insufficient does not describe a fresh database created from today's schema. Existing databases still need migrations. |
| [frontend-ticket-data-mapping.md](frontend-ticket-data-mapping.md) | Relevant table/view relationships, field meanings, and display guidance. | The workspace now exists. OCR jobs and the RPA action runner are implemented, so their “future” labels are stale. Its document-status table omits supported `manual_review`, although later text discusses it. The outbound-message table remains a foundation, not an implemented staff reply flow. |
| [email-reader.md](email-reader.md) | Relevant to the optional IMAP intake worker. | “No external dispatch in Cron1” applies to the mailbox reader, not CLOG `cron_runner.php`, which can insert Hub requests. “OCR and Cron2 are not part of the current worker” is mailbox-specific. The claim that information-request intents always stay `manual_review` is outdated: `PgStore` creates `queued` intents when `RPA_WORKFLOW_ACTIONS_ENABLED=true`; the separate action runner still applies eligibility checks. |
| [email-migration.md](email-migration.md) | Relevant historical email schema upgrade and form-result ownership reference. | It documents the email migration, not the entire current schema. Use the later migration sequence as well. Statements about what was applied during earlier implementation work do not establish today's database state. |

New documents added to fill verified gaps:

| Document | Why it is needed |
| --- | --- |
| [system-process-flow.md](system-process-flow.md) | One overall explanation joining the form/API path, CLOG cancellation/OCR path, optional mailbox intake, staff workspace, and downstream outcomes. No existing file in this folder covered all of them. |
| [manual-review-process-flow.md](manual-review-process-flow.md) | Explains the implemented CLI classification/approval/rejection and separate RPA action runner, including eligibility and held/uncertain outcomes. These steps were not adequately documented elsewhere. |
| [README.md](README.md) | This relevance inventory and the qualifications needed when reading older guides. |

Start with the system process guide, then follow the linked specialist guide for the path being investigated. Existing OCR, sync, email, migration, and scheduling guides already cover their main topics; additional duplicate process documents are unnecessary.

Evidence for the qualifications: [fresh schema](../database/schema.sql), [Windows wrapper](../bin/run-cron-sequence.ps1), [staff routes](../src/ticket-routes.php), [mailbox action creation](../src/Email/PgStore.php), [OCR worker](../bin/ocr_runner.php), [RPA action runner](../bin/rpa_action_runner.php), and [OCR status migration](../database/migrations/20261027_ocr_manual_review.sql).
