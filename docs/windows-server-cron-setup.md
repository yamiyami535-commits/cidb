# Windows Server scheduled task setup

This guide sets up one Windows Task Scheduler job for the cancellation workflow. The task calls `bin/run-cron-sequence.ps1`, which runs Cron1 and Cron2 sequentially. Cron2 runs attachment recheck and OCR in that same invocation; a file should not stop at `ready_for_ocr` just because the operator ran the normal sequence. The wrapper never uses `--backlog`.

## 1. Deploy and prepare the server

Before creating an active schedule:

1. Deploy the approved application release, including `bin/run-cron-sequence.ps1` and the Cron1/Cron2/OCR changes it depends on.
2. Apply the ticket and email database migrations for that release, in the order listed in [ticket-workflow.md](ticket-workflow.md). Confirm the application and CLOG database connections work from the server.
3. Install the PHP CLI used by the application. Use the full path to `php.exe` from the same PHP installation whose `php.ini` has the required database drivers (`pdo_pgsql`, `pdo_mysql`) and cURL enabled.
4. Install/configure the private OCR service and make it reachable from the server. In the deployed portal `.env`, set `OCR_ENABLED=true`, the correct `OCR_SERVICE_URL`, and the service's `OCR_API_KEY`. Keep `CLOG_TIMEZONE=Asia/Kuala_Lumpur`. Never put secrets in this guide or in the task arguments.
   Start with `OCR_BATCH_SIZE=1` and review observed run times before increasing it. The OCR HTTP timeout can be up to three minutes per document, so a larger batch may make a run exceed the five-minute trigger interval.
5. Confirm the production `.env` has the portal, CLOG, and RPA Hub database settings used by the current release. The task account must be able to read `.env`; do not grant broad access to it.
6. Give the task account read/execute access to the release and write access to `var\cron-logs\` and `bin\logs\`. It also needs outbound network access to the configured databases, CLOG attachment endpoint, and OCR service.

Do not enable the schedule while OCR is disabled or unreachable. With OCR disabled, Cron2 can discover and import attachments but it will not process them for RPA cancellation.

## 2. Create the task account

Use a dedicated Windows service account with:

- **Log on as a batch job** permission;
- read/execute access to the deployed application and read access to its `.env`;
- write access to the two log/lock directories above;
- network access to the required databases, CLOG file endpoint, and OCR service.

Avoid using a personal account whose password or access may change when the person leaves. In Task Scheduler, select **Run whether user is logged on or not** and enter this service account's credentials. The task does not need **Run with highest privileges** if the permissions above are set correctly.

## 3. Configure PHP for the task

The most reliable option is to pass the absolute PHP executable path as a script parameter. For example, replace the sample paths below with the deployed locations:

- PHP: `C:\PHP\php.exe`
- application: `D:\Apps\cidb-pre-chatbot-portal`

The wrapper also accepts `CIDB_PHP_EXE` from the task account's environment or finds `php.exe` on that account's `PATH`. Prefer the explicit `-PhpExe` argument so the task does not depend on an interactive user's PATH.

## 4. Create one scheduled task

Open **Task Scheduler** and choose **Create Task**.

### General

- Name: `CIDB cancellation Cron1-Cron2`
- Select the dedicated service account.
- Select **Run whether user is logged on or not**.
- Do not select **Run with highest privileges** unless the server administrator has determined the application directories require it.

### Trigger

Create a **Daily** trigger, then set **Repeat task every** to **5 minutes** and **for a duration of** **Indefinitely**. The task uses the current day in `Asia/Kuala_Lumpur`; it does not sweep previous days. If the full sequence regularly takes more than five minutes, use a ten-minute interval instead.

### Action

- **Program/script:** `%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe`
- **Add arguments:** `-NoProfile -ExecutionPolicy Bypass -File "D:\Apps\cidb-pre-chatbot-portal\bin\run-cron-sequence.ps1" -PhpExe "C:\PHP\php.exe"`
- **Start in:** `D:\Apps\cidb-pre-chatbot-portal`

Do not create a separate scheduled task for Cron2. This wrapper ensures Cron1 completes before Cron2 starts.

### Conditions and Settings

- Configure the task for the installed Windows Server version.
- Under **Settings**, choose **If the task is already running: Do not start a new instance**.
- Do not set a short **Stop the task if it runs longer than** limit; OCR requests may take time. Keep the overlapping-instance setting enabled.
- Review any network or idle conditions and ensure they will not prevent this server task from running.

Save the task and provide the service account password when prompted.

## 5. First run and ongoing checks

The scheduled wrapper is a live workflow: Cron1 can create a `Pending Customer Attachment` RPA request, and Cron2 can submit `Email Cancel` to RPA when documents pass the automatic OCR/identity gate. Do not use **Run** as a harmless configuration test against production data.

Before enabling the repeating trigger, have the release owner confirm that the current day's eligible cases are expected to be processed. Then enable the task and inspect:

- `D:\Apps\cidb-pre-chatbot-portal\var\cron-logs\cron-sequence-YYYY-MM-DD.log`;
- Task Scheduler's **Last Run Result** and **History**;
- portal ticket/document statuses and RPA Hub records for the processed CRM IDs.

The wrapper records Cron1 and Cron2 exit codes. If Cron1 fails, it records the failure and skips Cron2 for that run. If OCR is disabled, Cron2 logs that OCR is disabled and exits successfully; in that situation, fix the `.env`/service configuration before expecting automatic RPA cancellation submissions.

## Troubleshooting

- **Task succeeds in a console but fails in Task Scheduler:** confirm the task uses the service account, explicit `-PhpExe` path, correct **Start in** folder, and has permissions to `.env`, both log directories, and network resources.
- **PHP driver or cURL error:** verify the specified `php.exe` loads the expected `php.ini` and required extensions. A different PHP executable may be used by an interactive shell.
- **OCR disabled message:** check `OCR_ENABLED`, `OCR_SERVICE_URL`, and `OCR_API_KEY` in the deployed `.env`, then check service health and network access.
- **Files imported but not OCR processed:** check OCR service reachability, `OCR_ENABLED=true`, the daily wrapper log, and the OCR worker lock/log files in `bin\logs\`.
- **No cases processed:** normal runs are today-only and apply the individual cancellation, CLOG status, customer-message, and attachment requirements described in [ticket-workflow.md](ticket-workflow.md). Historical catch-up is a separate, explicitly scoped manual operation; do not add `--backlog` to this recurring task.
- **Task skipped while another run is active:** this is expected with the no-overlap setting. Check how long the current run takes and increase the interval if needed.

This guide and wrapper prepare the application for Windows Task Scheduler; they do not create or enable a task on the production server.
