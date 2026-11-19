# Manual ticket review and queued RPA process

This guide describes the implemented CLI review path as of 13 November 2026. The code restricts it to Individual CLOG tickets and identifies it as an SIT review path. It complements the automatic Cron2/OCR handoff; ordinary OCR-verified cases can go directly to RPA without these review commands. The staff web workspace remains read-only.

**Flow:** Inspect ticket → classify/assign if needed → obtain OCR identity matches → approve or reject → optionally queue an action → action worker validates eligibility → direct RPA Hub submission.

1. **Select a reviewable ticket.** `bin/ticket_review.php` requires a CRM ID and a reviewer username. The reviewer must be an active portal `admin` or `manager`. The CRM must resolve to exactly one CLOG-origin ticket linked to an interaction. These are CLI operations; the reviewer option is a database username lookup, not an interactive password challenge.

2. **Classify and assign where needed.** `classify-individual` accepts a ticket in `intake_pending`, `needs_information`, or `ready_for_ocr`, requires an Open CLOG case, and refuses company tickets. It selects the active default Individual cancellation bucket, sets the Individual scenario/customer type, assigns the ticket to the reviewer, and updates the interaction classification. A ready attachment leads to `ready_for_ocr`; otherwise the ticket remains `needs_information`. It records a classification event. This action does not itself run OCR.

3. **Inspect evidence before a decision.** Approval/rejection requires an Individual ticket in `ready_for_ocr`, an assigned reviewer, and an Open linked CLOG case. Approval additionally requires at least one ready attachment and verified OCR identity on every ready attachment. The document status must be `manual_review` or `valid` and `identity_check_verified=true`. Manual approval is not an override for missing or failed OCR identity checks. The review service checks every ready attachment; the automatic worker/action-runner queries specifically select ready CLOG customer attachments.

4. **Record approval or rejection.**

   | Action | Recorded result |
   | --- | --- |
   | `approve` | Saves reviewer/time and `MANUAL_REVIEW_APPROVED` metadata, moves the ticket to `ready_for_execution`, and creates an `execute_cancellation` action in `manual_review`. |
   | `approve --queue-rpa` | Records the same approval, but creates the action in `queued` so the separate worker can evaluate it. |
   | `reject --reason=...` | Saves rejection metadata/reason, removes prior approval metadata, and returns the ticket to `needs_information`. |

   Approval without `--queue-rpa` creates a held action. The current CLI has no command to release that held action. Repeating approval is not a release mechanism, because the ticket has already left `ready_for_ocr`. Rejection does not send a customer email, clear document OCR results, or automatically schedule a re-OCR of the same files.

5. **Evaluate queued actions.** `bin/rpa_action_runner.php` supports `check`, `dry-run`, and `run`. `check` opens the portal database and reports the dispatch flag; it does not validate a full Hub submission. `dry-run` examines due queued actions without claiming them or inserting Hub rows. `run` requires `RPA_WORKFLOW_ACTIONS_ENABLED=true`, claims due queued actions, increments attempts, and evaluates their eligibility.

   For `execute_cancellation`, the ticket must be Individual, assigned, `ready_for_execution`, CLOG Open, and have ready customer documents with verified OCR plus recorded manual approval. For `request_information`, it must be Individual, have missing fields, and have no ready customer documents. Input must also pass the direct inserter's validation. Ineligible actions move to `manual_review` with a reason code.

6. **Submit and interpret the outcome.** Eligible actions use `RpaHubDirectInserter` to submit `Email Cancel` or `Pending Customer Attachment`. The inserter applies the approved payload format and CRM/request deduplication. The worker records the result, marks the action `succeeded`, and adds `ticket.rpa_action_submitted`. This does not mark the ticket completed or confirm customer-message delivery/cancellation success.

   If dispatch is interrupted, the action may remain `dispatching`. A later live run marks dispatching actions older than 15 minutes as `uncertain`; they are not automatically requeued. Investigate Hub acceptance before arranging recovery. The runner also does not automatically release `manual_review` actions.

These files implement the flow: [CLI argument handling](../bin/ticket_review.php), [review rules and updates](../src/Ticketing/TicketManualReview.php), [action worker](../bin/rpa_action_runner.php), and [Hub validation/deduplication](../src/RpaHubDirectInserter.php). For the main automatic route, see [system process](system-process-flow.md) and [OCR process](ocr-process-flow.md).
