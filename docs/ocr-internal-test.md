# OCR Cron2 internal test checklist

This checklist is for a controlled non-production test. The worker is OCR-only:
it does not send customer email or call RPA. It leaves completed OCR work in
`ocr_manual_review` for an authorised person to inspect.

## Required before starting

- The portal PostgreSQL database has the ticket foundation migrations and
  `20261027_ocr_manual_review.sql` applied in order. Do not apply
  `database/schema.sql` to an existing database.
- The OCR service starts successfully and its `/health` endpoint returns
  `{"status":"ok", ...}`.
- Portal PHP has `pdo_pgsql` and `curl` enabled. The worker connects to the
  portal PostgreSQL database and retrieves CLOG attachment bytes over HTTPS.
- A controlled test ticket is assigned, has CLOG status `Open`, and has
  `workflow_status='ready_for_ocr'`.
- The ticket or linked interaction has the correct customer name and NRIC in
  `portal_tickets.applicant_name` / `portal_tickets.id_number` or
  `cidb_interaction_data.applicant_name` / `cidb_interaction_data.nric`.
- The ticket has either one single-page cancellation-form PDF, one two-page
  MyKad PDF (front page first), or two image attachments named clearly as
  front/back (`front`/`back` or `depan`/`belakang`).
- The attachment URLs point to the configured HTTPS CLOG file endpoint.
- An authorised operator has confirmed the case is an individual cancellation,
  verified the submitted information is sufficient for OCR, assigned the ticket,
  and set its workflow status to `ready_for_ocr`. Cron1 does not perform that
  classification or assignment automatically.

Use only a test/redacted document approved for SIT. The previously shared
instruction sheet is not a suitable MyKad verification sample. Ensure the
sample's identity values match the test ticket's mapped name and NRIC. Do not
copy a customer's real ID into logs, screenshots, or test reports.

If the approved attachment is in production CLOG, import only the approved CRM
with `php bin/cron_runner.php --crm-id=<approved-crm-id>`. This scoped command
reads only its `Open` email rows, does not advance the global sync cursor, and
does not refresh unrelated tickets. It still copies that CRM's open
message/thread details and attachment metadata into the portal Dev database.
Do not run the unrestricted Cron1 command for this test.

## Configure and start

1. In `ocr/.env`, set a test `API_KEY` if the portal will connect over HTTPS.
   For loopback-only local testing, the key can remain empty.
2. In the portal `.env`, configure:

   ```env
   OCR_ENABLED=false
   OCR_SERVICE_URL=http://127.0.0.1:8000
   OCR_API_KEY=
   OCR_BATCH_SIZE=1
   ```

   For a remote OCR host, use its private HTTPS URL and the same strong key in
   both environments. Do not use HTTP for a non-loopback host.
3. From PowerShell, start OCR in one terminal:

   ```powershell
   Set-Location .\ocr
   .\.venv312\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8000
   ```

4. In another terminal, confirm `http://127.0.0.1:8000/health` reports `ok`.
5. Confirm the test ticket meets every condition above, then change
   `OCR_ENABLED=true` in the portal `.env`.
6. From the portal root, run only the approved CRM:

   ```powershell
   php .\bin\ocr_runner.php --crm-id=CRM-...
   ```

   For a one-page PDF with no expected NRIC mapped, Cron2 uses the dedicated
   cancellation-form OCR route. It stores a masked document number, if readable,
   and always leaves the case in manual review.

## Confirm the result

Use the backend's normal database connection and the test ticket UUID:

```sql
SELECT
    t.id,
    t.workflow_status,
    t.clog_status,
    d.original_filename,
    d.ocr_status,
    d.ocr_result,
    j.state AS ocr_job_state,
    j.response_payload
FROM portal_tickets t
JOIN portal_ticket_documents d ON d.ticket_id = t.id
LEFT JOIN portal_ticket_ocr_jobs j ON j.document_id = d.id
WHERE t.id = :test_ticket_id
ORDER BY d.created_at, j.created_at;
```

Expected after a processed document:

- Ticket `workflow_status` is `ocr_manual_review`.
- The interaction's portal-owned `current_status` remains unchanged; the OCR
  worker does not set it to `Offered` or another outcome.
- Document `ocr_status` is one of `manual_review`, `invalid`, `unreadable`, or
  `error`, as appropriate. An identity match is still `manual_review`.
- OCR job state is `succeeded` when the service returned a valid OCR response;
  transport or processing failures are `failed`.
- Stored results contain a masked document number and limited comparison
  fields, not complete OCR text, image data, or an unmasked NRIC.
- No RPA action or customer email was sent.

If the worker reports a missing NRIC/name, no OCR request is made. If the file
layout is not recognised, it marks the document/ticket for manual review
without sending that file to OCR. Correct the test data or file naming before
re-running. Failed or already queued jobs are not automatically retried by this
worker; review the stored job and document state before any manual retry.

## SIT limitations

- One-page PDFs are treated as cancellation forms. OCR can compare readable
  name/NRIC text, but always leaves the case in manual review.
- Two-page PDFs are assumed to be MyKad front then back.
- Image pairs depend on clear side labels in filenames.
- The CLOG NRIC source mapping still needs confirmation by the backend owner.
- OCR checks document content and identity matches; it does not prove document
  authenticity or visually verify the CIDB-use cross-out, company stamp, or
  director signature. Those checks must be completed by an authorised reviewer.
- This worker does not transition tickets to execution-ready and does not
  invoke RPA. Keep `OCR_ENABLED=false` outside the controlled test window until
  the Ops/backend owners approve the test setup.
