# CIDB OCR: Brief End-to-End Process Flow

This document describes the current implementation for Individual ID cancellation requests. OCR (Optical Character Recognition) reads identity text from customer attachments and checks whether the document can proceed to cancellation automation. It does not itself cancel the customer's ID.

**Flow:** Customer email → CLOG → portal ticket and attachment references → Cron2 → OCR job → file download → Python OCR service → stored verification result → RPA eligibility check → RPA Hub handoff.

1. **Receive and register the request.** Cron1 (`php bin/cron_runner.php`) imports eligible Open CLOG Individual cancellation records and stores customer details, conversation messages, and attachment references in the portal. Tickets with documents can enter `ready_for_ocr`; those needing documents enter `needs_information`. Customer `New Email` and `Reply Mail` attachments are OCR inputs. Agent `ReplyTo Mail` attachments are excluded.

2. **Find customer replies and start OCR.** Cron2 (`php bin/cron_runner.php --cron2`) rechecks eligible pending tickets for today's attachments, stores their references, and promotes qualifying tickets to `ready_for_ocr`. It then invokes `bin/ocr_runner.php` in the same run. Reply attachments may be collected even if CLOG marks the reply Closed, but the OCR worker still requires the ticket's effective CLOG status to be Open. Scheduled processing uses today's window in `CLOG_TIMEZONE`; historical processing needs an explicitly scoped CRM/backlog run.

3. **Queue eligible documents.** With `OCR_ENABLED=true`, the worker selects Individual cancellation tickets in `ready_for_ocr` with ready, pending customer documents. It creates deduplicated jobs in `portal_ticket_ocr_jobs` and processes a configurable batch (default: five). A ready reference means the file URL is available; downloading the actual file can still fail.

4. **Download and route the files.** The worker downloads attachments from the configured CLOG HTTPS endpoint, checks size/type constraints, and temporarily saves them for upload. It sends the file and available customer name/NRIC to the private OCR API, using `X-API-Key` when configured.

   | Attachment selected by the worker | OCR route |
   | --- | --- |
   | PDF | `/api/verify-cancellation-form-pdf` — requires exactly one page |
   | JPG, JPEG, PNG, or WebP | `/api/verify-mykad-image` |
   | Exactly two images identified by front/depan and back/belakang filenames, with mapped name and NRIC | `/api/verify-document` — MyKad front/back verification |
   | Unsupported file type | Marked for manual review without OCR |

   The service also supports `/api/verify-mykad-pdf`, and the worker can execute that job type, but its current automatic job creation routes PDFs to the single-page cancellation-form endpoint. A two-page PDF is therefore not automatically classified as a MyKad PDF.

5. **Read the document.** The Python FastAPI service validates the upload and renders PDF pages into images. It measures image quality, with blur as the quality rejection criterion. Preprocessing enlarges small images, converts to grayscale, improves contrast, reduces noise, and sharpens text. PaddleOCR then produces recognized text and confidence scores.

6. **Check identity.** The service extracts NRIC and readable name/address information, checks document evidence, and compares normalized NRIC and name similarity with supplied customer details. For the single-image and cancellation-form routes, verification requires a clear, recognized document, an extracted NRIC, and a matching supplied name. A supplied NRIC must match; if absent, the extracted NRIC can be used. Front/back verification additionally checks both sides and OCR confidence. Address extraction is informational. Cross-out marks, signatures, and stamps are outside the OCR checks.

7. **Save the result.** The worker stores a limited response in `portal_ticket_documents.ocr_result`, updates `ocr_status`, completes the job, and records `ticket.ocr_completed`. The stored summary includes a masked document number, identity comparison, and quality information. An extracted 12-digit NRIC can fill missing identity fields in the ticket/interaction; existing values are preserved. Temporary files are removed after processing.

   | API outcome | Document status and meaning |
   | --- | --- |
   | `VERIFIED` or `MANUAL_REVIEW` | `manual_review`; inspect `identity_check_verified` to determine whether identity passed |
   | `INVALID_DOCUMENT` / `DETAILS_MISMATCH` | `invalid`; automatic RPA blocked |
   | `REUPLOAD_REQUIRED` / `OCR_FAILED` | `unreadable`; automatic RPA blocked |
   | `INTERNAL_ERROR`, or download/request failure | `error`; automatic RPA blocked |

   The single-image and cancellation-form routes return `MANUAL_REVIEW` for both passing and unconfirmed results. **The status label alone is not proof of verification.** A job marked `succeeded` means a response was processed, not that identity passed.

8. **Hand off or hold.** Automatic RPA requires an eligible Individual cancellation, customer name/NRIC, at least one ready customer document, and verified identity on every ready customer document. Passing cases are inserted directly into RPA Hub with `sRequest='Email Cancel'`; the interaction becomes `Customer Replied - Verified` and the ticket becomes `ready_for_execution`. This handoff does not prove cancellation completed; completion must be checked in the downstream RPA outcome. Unconfirmed documents remain blocked for investigation or replacement. OCR failure alone does not automatically change the ticket to `needs_information`; the manual rejection action does that.

Technical job failures are scheduled for retry after 30 minutes, up to ten attempts, subject to the same ticket/date eligibility rules. Completed negative OCR responses are not automatically retried as technical failures. A separate worker pass retries eligible verified cases whose RPA insertion failed.

Implementation references: [Cron1](../bin/cron_runner.php), [Cron2](../bin/cron2_recheck.php), [attachment recheck](../src/Ticketing/Cron2AttachmentRechecker.php), [OCR worker](../bin/ocr_runner.php), [API and identity decisions](../ocr/app/main.py), [image preprocessing](../ocr/app/image_quality.py), [text comparison](../ocr/app/verifier.py), [RPA gate](../src/RpaHubDirectInserter.php), and [manual review](../src/Ticketing/TicketManualReview.php).
