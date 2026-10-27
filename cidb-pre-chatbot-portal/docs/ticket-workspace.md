# Ticket management workspace

After signing in, select **Ticket Management** in the submission page's sidebar, or open `/tickets`. `/tickets/crm` searches an exact CRM ID; `/tickets/{uuid}` opens a ticket. The sidebar includes live counts and common workflow queues. The full workspace lists every supported workflow status.

## Restored application baseline

The working branch was based on the initial commit `ba2654eb`, while the original screenshot and established request/email/OCR functionality were in `ace89d48` (`feature/ticketing-flow-ui`, following `8f64e2a9`). The first ticket implementation preserved the initial commit's older pages and omitted a visible entry to `/tickets`; this was the cause of the reported regression.

The submission template, Recent Activity source badges, filtered/paginated history, customer-only result view, RPA-owned request statuses, polling/navigation JavaScript, email services and existing OCR verification endpoint have been restored from `ace89d48`, including their supporting dependencies. The existing request form has Name, ID Number, Email, CRM and Language; it does not require the older Contact Number/Location Area fields. `home.php`, `request-history.php`, `request-details.php`, `request-result.php` and `app.js` match that commit. Database credentials and data were not changed, and no migrations or live dispatches were executed.

The original sidebar styling is preserved in `portal-shell.css` and loaded only by the submission shell. `email.css` restores existing request-history styling. New `tickets.css` and `tickets.js` load only in the staff workspace. The home sidebar uses actual workflow labels in place of the retired preview categories. Old `/tickets/customer-responded`, `/tickets/waiting-on-customer` and `/tickets/waiting-acknowledgement` bookmarks lead to the active queue with an explanation, rather than asserting unsupported status mappings. `/tickets/resolved` opens completed work; `/tickets/closed` opens closed work.

The implementation uses the existing PHP router, PDO connection, session and layout. `src/tickets.php` contains the read repository, validation and presentation/privacy helpers. `src/ticket-routes.php` handles the new routes. Views and assets are isolated in `views/tickets/` and `public/assets/tickets.*`.

## Access and deployment

The connected database must already contain the ticket workflow migrations and the `portal_ticket_details` / `portal_crm_details` views from the mapping document. The original `database/schema.sql` alone is insufficient. This feature does not apply migrations or create an alternative schema.

Every ticket request rechecks the session user's database row for `is_active=true` and role `admin`, `manager` or `agent`. These staff roles have shared read access to the operational queue, tickets and CRM records; the database has no tenant or per-ticket visibility policy in this project. A session username such as `admin` grants no privileges by itself. Existing submission ownership checks are unchanged. All new pages use `Cache-Control: no-store, private`.

The workspace is read-only. There are no assignment/status mutations, file-fetch endpoints, outbound replies, OCR dispatch or RPA execution controls. Related database rows are evidence of recorded state only. The existing request submission RPA client is not called from ticket pages. The existing API-key-protected `/api/ocr/verify-document` endpoint, `OcrVerificationClient.php` and Python OCR source were recovered from Git. That inbound verification integration remains separate from the ticket OCR dispatch ledger; it is not invoked merely by viewing a ticket.

## Data behavior

- Queue reads use compact `portal_tickets` rows with bucket and user joins. Counts are separate queries. Filters and sort options are allowlisted; parameters include UUID filters, CRM ID, limits and offsets. Active excludes `closed`, following the mapping query. Sidebar counts retain source, agent, team and active-CLOG filters, but span all workflow statuses.
- Detail reads select one UUID from `portal_ticket_details`. Conversation order is preserved from the view (`sent_at`, then creation time and UUID; unknown timestamps last). No parent relationships are inferred.
- CRM reads use exact `crm_id` equality in `portal_crm_details`. Tickets, thread summaries and CLOG form summaries remain distinct.
- Ticketing Activity reads `cidb_clog_activity`; Ticketing History reads `cidb_clog_ticketing_log`, both by `interaction_id`. Audit Log displays the portal view's `ticket_history`, which does not audit every customer-field change.
- Documents and RPA actions come from the detail view. OCR jobs are queried separately through `portal_ticket_documents` because the job ledger is not embedded in that view.
- Ticket workflow, CLOG status, CRM interaction status, reference status, OCR status/job state, RPA action state, and mailbox intake stage are separately labeled. A ready reference is not a successful download or OCR result.
- Times are shown in `Asia/Kuala_Lumpur`. Unparseable CLOG source timestamps remain escaped source text. Nulls/empty collections have explicit empty states.
- Identity fields show only their last four characters (short IDs are fully masked). The same masking covers identity fields in structured diagnostics and known ticket/CRM IDs in text, with an additional NRIC-pattern pass. No identity reveal control is provided. This is a conservative default because the existing project has no reusable privacy policy/helper.
- Message HTML is converted to text, then escaped like all other source content. It is never inserted as HTML. File URLs, storage keys, raw mailbox bodies and credential fields are withheld from diagnostics.

## Verification

```text
php tests/tickets.php
php tests/tickets.php --live
php tests/tickets-http.php --live
php tests/request-result.php
node tests/polling.cjs
node tests/history-navigation.cjs
php tests/email-unit.php
node tests/portal-http.cjs
node tests/workspace-browser.cjs --live
node --check public/assets/tickets.js
```

`--live` uses the configured PostgreSQL database inside a read-only, repeatable-read transaction and rolls it back. It checks real views/joins, status and source filters, count consistency, stable pagination, chronological conversations, representative detail tabs, exact CRM matching and access rejection. It does not mutate users/tickets or invoke integrations. `--preview` writes a synthetic HTML preview to ignored `var/ticket-ui-preview.html` for visual inspection.

The separate HTTP check starts an isolated loopback PHP server with a temporary session directory. It checks login redirects, staff access, filter errors, all ticket tabs, CRM and the existing submission/Recent Activity GET routes. Generated test sessions are removed when it finishes; no passwords or live session cookies are used.

`portal-http.cjs` runs the restored login, form submission, RPA, OCR, history and polling regression scenarios in an isolated PGlite database with mock integration endpoints. Its PHP subprocess disables SSL only for that local test database. The browser check uses headless Chrome, an isolated session/server and PostgreSQL connections forced read-only. It checks the visible home entry, real navigation, filters, pagination, all ticket tabs and desktop/mobile layout; screenshots stay in ignored `var/workspace-browser-*` directories. Pending-result transitions are covered by the isolated tests even when live accessible requests are already complete.
