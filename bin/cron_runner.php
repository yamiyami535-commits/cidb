<?php
/**
 * CIDB MySQL CLOG -> PostgreSQL interaction data sync & ticket intake (Cron 1).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI access only.\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/RpaClient.php';
require_once dirname(__DIR__) . '/src/Ticketing/CancellationDetector.php';
require_once dirname(__DIR__) . '/src/Ticketing/ClogCaseStatus.php';
require_once dirname(__DIR__) . '/src/Ticketing/ClogIdentityExtractor.php';
require_once dirname(__DIR__) . '/src/Ticketing/ClogAttachmentReference.php';
require_once dirname(__DIR__) . '/src/Ticketing/ClogAttachmentDocumentStore.php';
require_once dirname(__DIR__) . '/src/Ticketing/ClogConversationStore.php';


// Default to SIT 1 user test CRM-ID when not specified

// Add support for Cron 2 (attachment‑OCR) mode
$cron2Mode = false;
$scopedCrmId = null;
$allMode = false;

// Existing argument parsing – extend to recognise --cron2
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--all') {
        $allMode = true;
        continue;
    }
    if (str_starts_with($argument, '--crm-id=')) {
        if ($scopedCrmId !== null) {
            fwrite(STDERR, "Specify --crm-id only once.\n");
            exit(2);
        }
        $scopedCrmId = trim(substr($argument, strlen('--crm-id=')));
        if ($scopedCrmId === '' || strlen($scopedCrmId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $scopedCrmId) === 1) {
            fwrite(STDERR, "--crm-id must be a non-empty CRM ID of at most 255 characters.\n");
            exit(2);
        }
        continue;
    }
    if ($argument === '--cron2') {
        $cron2Mode = true;
        continue;
    }
    fwrite(STDERR, "Usage: php bin/cron_runner.php [--crm-id=CRM-ID] [--all] [--cron2]\n");
    exit(2);
}

// Default to SIT 1 user test CRM-ID when not specified
if ($scopedCrmId === null && !$allMode && !$cron2Mode) {
    $scopedCrmId = 'CRM-1793155923-92';
}

if ($scopedCrmId === null && !$allMode) {
    $scopedCrmId = 'CRM-1793155923-92';
}

/**
 * Open a fresh PostgreSQL connection.
 * Used for the main connection and again for post-RPA writes, because the main
 * connection can be dropped while we wait on the slow RPA HTTP call.
 */
function cidb_pg_connect(): PDO
{
    return new PDO(
        sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d;keepalives=1;keepalives_idle=30;keepalives_interval=10;keepalives_count=3',
            env_value('DB_HOST'),
            max(1, (int)env_value('DB_PORT', '5432')),
            env_value('DB_DATABASE'),
            max(1, (int)env_value('DB_TIMEOUT', '5'))
        ),
        env_value('DB_USERNAME'),
        env_value('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/**
 * Persist the outcome of an RPA call on a FRESH Postgres connection (one retry).
 * $row keys: ticket_id, dedupe_key, state, payload_json, response_json, error_code, event_json|null
 */
function cidb_record_rpa_result(array $row): bool
{
    $lastError = null;
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            $pdo = cidb_pg_connect();
            $pdo->beginTransaction();

            $queue = $pdo->prepare("
                INSERT INTO portal_rpa_action_queue
                    (ticket_id, action_type, dedupe_key, state, payload, response, error_code, updated_at)
                VALUES
                    (?, 'request_information', ?, ?, ?::jsonb, ?::jsonb, NULLIF(?, ''), NOW())
                ON CONFLICT (dedupe_key) DO UPDATE SET
                    state = EXCLUDED.state,
                    payload = EXCLUDED.payload,
                    response = EXCLUDED.response,
                    error_code = EXCLUDED.error_code,
                    updated_at = NOW()
            ");
            $queue->execute([
                $row['ticket_id'],
                $row['dedupe_key'],
                $row['state'],
                $row['payload_json'],
                $row['response_json'],
                $row['error_code'],
            ]);

            if ($row['event_json'] !== null) {
                $event = $pdo->prepare("
                    INSERT INTO portal_ticket_events (ticket_id, actor_type, event_type, metadata)
                    VALUES (?, 'integration', 'ticket.rpa_action_submitted', ?::jsonb)
                ");
                $event->execute([$row['ticket_id'], $row['event_json']]);
            }

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $lastError = $e;
            if (isset($pdo) && $pdo instanceof PDO) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable $ignored) {
                }
            }
            $pdo = null;
        }
    }

    echo "   [RPA Bot Error] Could not record RPA result for ticket {$row['ticket_id']}: "
        . ($lastError ? $lastError->getMessage() : 'unknown error') . "\n";
    return false;
}

/**
 * Call the RPA API for each queued job. Runs AFTER the batch transaction has
 * been committed, so no DB transaction/connection is held open during slow HTTP calls.
 */
function cidb_dispatch_rpa_jobs(array $jobs): void
{
    foreach ($jobs as $job) {
        $ticketId = (string)$job['ticket_id'];
        $crmId = (string)$job['crm_id'];
        $rpaInput = $job['input'];

        $dedupeKey = 'clog:' . $crmId . ':request-information:' . hash('sha256', json_encode($rpaInput, JSON_THROW_ON_ERROR));

        try {
            $rpaClient = new \RpaClient();
            $rpaPayload = $rpaClient->workflowPayload($rpaInput);
            echo "   [RPA Bot] Triggering RPA API for {$crmId} ('Pending Customer Attachment')...\n";
            $rpaResponse = $rpaClient->send($rpaPayload);
            $parsed = $rpaResponse['parsed'];
            $httpStatus = (int)($rpaResponse['http_status'] ?? 0);
            $acknowledged = is_array($parsed) && strtolower((string)($parsed['status'] ?? '')) === 'inserted';
            $scheduleId = is_array($parsed) && is_scalar($parsed['schedule_id'] ?? null) ? (string)$parsed['schedule_id'] : null;
            $rpaState = $acknowledged ? 'succeeded' : (($httpStatus >= 400 && $httpStatus < 500) ? 'failed' : 'uncertain');
            $errorCode = $acknowledged ? '' : ($rpaState === 'failed' ? 'RPA_REJECTED_REQUEST' : ($rpaResponse['error'] ?? 'RPA_OUTCOME_UNCONFIRMED'));

            echo "   [RPA Bot] Result: {$rpaState}" . ($scheduleId ? " (Schedule ID: {$scheduleId})" : "") . ($errorCode ? " (Details: {$errorCode})" : "") . "\n";

            $row = [
                'ticket_id' => $ticketId,
                'dedupe_key' => $dedupeKey,
                'state' => $rpaState,
                'payload_json' => json_encode($rpaPayload, JSON_THROW_ON_ERROR),
                'response_json' => json_encode($rpaResponse, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'error_code' => (string)$errorCode,
                'event_json' => json_encode([
                    'action_type' => 'request_information',
                    'state' => $rpaState,
                    'schedule_id' => $scheduleId,
                    'http_status' => $httpStatus,
                ], JSON_THROW_ON_ERROR),
            ];
        } catch (\Throwable $rpaErr) {
            echo "   [RPA Bot Error] " . $rpaErr->getMessage() . "\n";
            $row = [
                'ticket_id' => $ticketId,
                'dedupe_key' => $dedupeKey,
                'state' => 'failed',
                'payload_json' => json_encode($rpaInput, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'response_json' => json_encode(['error' => $rpaErr->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                'error_code' => 'RPA_DISPATCH_EXCEPTION',
                'event_json' => null,
            ];
        }

        cidb_record_rpa_result($row);
    }
}

function clog_sync_rows_by_values(PDO $pdo, string $table, string $column, array $values): array
{
    if ($values === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($values), '?'));
    $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
    $stmt->execute(array_values($values));
    return $stmt->fetchAll();
}

function clog_sync_emails_by_crm_ids(PDO $pdo, array $crmIds, bool $openOnly = false): array
{
    if ($crmIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($crmIds), '?'));
    $statusFilter = $openOnly ? " AND LOWER(TRIM(`case_status`)) = 'open'" : '';
    $stmt = $pdo->prepare("
        SELECT *
        FROM `emails`
        WHERE `uniqid` IN ({$placeholders}){$statusFilter}
        ORDER BY `time` ASC, `id` ASC
    ");
    $stmt->execute(array_values($crmIds));
    return $stmt->fetchAll();
}

function clog_sync_ticket_attachments(
    PDOStatement $lookupUpdate,
    \Cidb\Ticketing\ClogAttachmentDocumentStore $documentStore,
    string $ticketId,
    string $crmId,
    array $emailIds,
    array $files
): array {
    $documents = $documentStore->synchronize($ticketId, $crmId, $files);
    $status = $emailIds === []
        ? 'no_matching_crm_email'
        : ($documents === [] ? 'no_files' : 'found');
    $result = [
        'status' => $status,
        'matched_email_ids' => array_values(array_unique($emailIds)),
        'file_count' => count($documents),
        'files' => $documents,
    ];
    $lookupUpdate->execute([
        'crm_id' => $crmId,
        'status' => $status,
        'checked_at' => gmdate('Y-m-d H:i:sP'),
        'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        'attachment_refs' => json_encode(
            array_values(array_unique(array_column($documents, 'original_filename'))),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        ),
        'ticket_id' => $ticketId,
    ]);
    return $documents;
}

function clog_sync_timestamp(string $value, DateTimeZone $timezone): DateTimeImmutable
{
    try {
        return new DateTimeImmutable($value, $timezone);
    } catch (Throwable $e) {
        throw new RuntimeException("Invalid source email timestamp: {$value}", 0, $e);
    }
}

$requiredConfig = [
    'CLOG_DB_HOST' => env_value('CLOG_DB_HOST'),
    'CLOG_DB_DATABASE' => env_value('CLOG_DB_DATABASE'),
    'CLOG_DB_USERNAME' => env_value('CLOG_DB_USERNAME'),
    'DB_HOST' => env_value('DB_HOST'),
    'DB_DATABASE' => env_value('DB_DATABASE'),
    'DB_USERNAME' => env_value('DB_USERNAME'),
];
$missingConfig = array_keys(array_filter($requiredConfig, static fn(string $value): bool => $value === ''));
if ($missingConfig !== []) {
    fwrite(STDERR, 'Missing database configuration: ' . implode(', ', $missingConfig) . "\n");
    exit(1);
}

$sourceTimezoneName = env_value('CLOG_TIMEZONE', 'Asia/Kuala_Lumpur');
try {
    $sourceTimezone = new DateTimeZone($sourceTimezoneName);
} catch (Throwable $e) {
    fwrite(STDERR, "Invalid CLOG_TIMEZONE configuration.\n");
    exit(1);
}

$initialSyncDays = filter_var(env_value('CLOG_INITIAL_SYNC_DAYS', '0'), FILTER_VALIDATE_INT);
if ($initialSyncDays === false || $initialSyncDays < 0) {
    fwrite(STDERR, "CLOG_INITIAL_SYNC_DAYS must be a non-negative integer.\n");
    exit(1);
}

try {
    $mysqlDb = env_value('CLOG_DB_DATABASE');
    try {
        $pdo_mysql = new PDO(
            sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                env_value('CLOG_DB_HOST'),
                $mysqlDb
            ),
            env_value('CLOG_DB_USERNAME'),
            env_value('CLOG_DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => max(1, (int)env_value('CLOG_DB_TIMEOUT', '5')),
            ]
        );
    } catch (PDOException $mysqlEx) {
        if (str_contains($mysqlEx->getMessage(), '1049')) {
            $altDb = str_contains($mysqlDb, '_') ? str_replace('_', '-', $mysqlDb) : str_replace('-', '_', $mysqlDb);
            $pdo_mysql = new PDO(
                sprintf(
                    'mysql:host=%s;dbname=%s;charset=utf8mb4',
                    env_value('CLOG_DB_HOST'),
                    $altDb
                ),
                env_value('CLOG_DB_USERNAME'),
                env_value('CLOG_DB_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => max(1, (int)env_value('CLOG_DB_TIMEOUT', '5')),
                ]
            );
        } else {
            throw $mysqlEx;
        }
    }

    $pdo_pg = cidb_pg_connect();
} catch (PDOException $e) {
    fwrite(STDERR, "Database connection error: {$e->getMessage()}\n");
    exit(1);
}

$lockDir = __DIR__ . '/logs';
if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "Unable to create cron log directory.\n");
    exit(1);
}

$fp = fopen($lockDir . '/cross_db_sync.lock', 'c+');
if ($fp === false) {
    fwrite(STDERR, "Unable to open cron lock file.\n");
    exit(1);
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    exit('[' . date('Y-m-d H:i:s') . "] Sync cron already running. Skipping.\n");
}

try {
    echo '[' . date('Y-m-d H:i:s') . "] Starting MySQL CLOG -> PostgreSQL sync (Cron 1)...\n";

    $metaStmt = $pdo_pg->prepare("SELECT last_sync FROM sync_meta WHERE sync_key = 'customer_info'");
    $metaStmt->execute();
    $meta = $metaStmt->fetch();
    if (!$meta) {
        throw new RuntimeException("Missing sync_meta row for 'customer_info'.");
    }

    $initialCutoff = (new DateTimeImmutable('today', $sourceTimezone))
        ->modify("-{$initialSyncDays} days")
        ->setTimezone(new DateTimeZone('UTC'));
    $saveInitialCutoff = $pdo_pg->prepare("
        INSERT INTO sync_meta (sync_key, last_sync)
        VALUES ('customer_info_start', ?::timestamptz)
        ON CONFLICT (sync_key) DO NOTHING
    ");
    $saveInitialCutoff->execute([$initialCutoff->format('Y-m-d H:i:sP')]);

    $startStmt = $pdo_pg->prepare("SELECT last_sync FROM sync_meta WHERE sync_key = 'customer_info_start'");
    $startStmt->execute();
    $startMeta = $startStmt->fetch();
    if (!$startMeta) {
        throw new RuntimeException("Unable to read the initial CLOG sync cutoff.");
    }

    $cursor = new DateTimeImmutable((string)$meta['last_sync']);
    $initialCutoff = new DateTimeImmutable((string)$startMeta['last_sync']);
    if ($cursor < $initialCutoff) {
        $cursor = $initialCutoff;
    }
    $cursorTime = $scopedCrmId === null
        ? $cursor->setTimezone($sourceTimezone)->format('Y-m-d H:i:s')
        : '1000-01-01 00:00:00';
    $cursorId = '0';
    $processedCount = 0;
    $cancellationCount = 0;
    $attachmentReferenceCount = 0;
    $invalidAttachmentReferenceCount = 0;
    $batchCount = 0;
    $batchSize = 500;
    $syncStartedAt = microtime(true);
    if ($scopedCrmId === null) {
        echo '   Reading emails from ' . $cursorTime . " ({$sourceTimezoneName}) in batches of {$batchSize}...\n";
    } else {
        echo "   [SIT Mode] Scoped to CRM ID: {$scopedCrmId}. Global sync cursor will not be changed.\n";
    }
    if (defined('STDOUT')) {
        fflush(STDOUT);
    }

    $emailQuery = $pdo_mysql->prepare($scopedCrmId === null
        ? "
            SELECT *
            FROM `emails`
            WHERE `time` >= ?
              AND (`time` > ? OR (`time` = ? AND `id` > ?))
              AND `uniqid` IS NOT NULL
              AND TRIM(`uniqid`) <> ''
            ORDER BY `time` ASC, `id` ASC
            LIMIT {$batchSize}
        "
        : "
            SELECT *
            FROM `emails`
            WHERE `uniqid` = ?
              AND LOWER(TRIM(COALESCE(`case_status`, ''))) = 'open'
              AND (`time` > ? OR (`time` = ? AND `id` > ?))
            ORDER BY `time` ASC, `id` ASC
            LIMIT {$batchSize}
        "
    );

    $fileUpsert = $pdo_pg->prepare("
        INSERT INTO cidb_interaction_data
            (crm_id, applicant_name, nric, email, phone, state, customer_type, language,
             company_name, company_no, current_status, clog_status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::timestamptz, NOW())
        ON CONFLICT (crm_id) DO UPDATE SET
            applicant_name = COALESCE(EXCLUDED.applicant_name, cidb_interaction_data.applicant_name),
            nric = COALESCE(NULLIF(TRIM(cidb_interaction_data.nric), ''), EXCLUDED.nric),
            email = COALESCE(EXCLUDED.email, cidb_interaction_data.email),
            phone = COALESCE(EXCLUDED.phone, cidb_interaction_data.phone),
            state = COALESCE(EXCLUDED.state, cidb_interaction_data.state),
            customer_type = COALESCE(EXCLUDED.customer_type, cidb_interaction_data.customer_type),
            language = COALESCE(EXCLUDED.language, cidb_interaction_data.language),
            company_name = COALESCE(EXCLUDED.company_name, cidb_interaction_data.company_name),
            company_no = COALESCE(EXCLUDED.company_no, cidb_interaction_data.company_no),
            current_status = COALESCE(EXCLUDED.current_status, cidb_interaction_data.current_status),
            clog_status = COALESCE(EXCLUDED.clog_status, cidb_interaction_data.clog_status),
            updated_at = NOW()
    ");

    $existingCase = $pdo_pg->prepare(
        'SELECT id, clog_status, current_status FROM cidb_interaction_data WHERE crm_id = ?'
    );

    $ticketStatusUpdate = $pdo_pg->prepare("
        UPDATE portal_tickets
        SET clog_status = ?, updated_at = NOW()
        WHERE source_type = 'clog' AND source_ref = ?
    ");

    $ticketConversationUpdate = $pdo_pg->prepare("
        UPDATE portal_tickets
        SET interaction_id = (SELECT id FROM cidb_interaction_data WHERE crm_id = ?),
            email_thread_id = ?,
            updated_at = NOW()
        WHERE source_type = 'clog' AND source_ref = ?
    ");

    $statusEvent = $pdo_pg->prepare("
        INSERT INTO portal_event_logs (interaction_id, event_type, description, metadata)
        VALUES (?, 'CLOG_STATUS_CHANGE', ?, ?::jsonb)
    ");

    $pendingScenarioBucket = $pdo_pg->prepare("
        SELECT id
        FROM portal_ticket_buckets
        WHERE scenario_key = 'cancellation-classification-pending'
          AND is_default = true
          AND is_active = true
        LIMIT 1
    ");
    $pendingScenarioBucket->execute();
    $pendingBucketId = $pendingScenarioBucket->fetchColumn();
    if (!$pendingBucketId) {
        throw new RuntimeException("Missing default ticket bucket for cancellation classification.");
    }

    $scenarioBucketLookup = $pdo_pg->prepare("
        SELECT id
        FROM portal_ticket_buckets
        WHERE scenario_key = ?
          AND is_default = true
          AND is_active = true
        LIMIT 1
    ");

    $ticketUpsert = $pdo_pg->prepare("
        INSERT INTO portal_tickets
            (source_type, source_ref, scenario_key, bucket_id, customer_type, workflow_status, clog_crm_id,
             clog_status, applicant_name, applicant_email, id_number, contact_number, state, language,
             company_name, company_no, missing_fields, attachment_refs, source_data)
        VALUES
            ('clog', ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?, ?,
             ?, ?, ?::jsonb, ?::jsonb, ?::jsonb)
        ON CONFLICT (source_type, source_ref) DO UPDATE SET
            scenario_key = EXCLUDED.scenario_key,
            bucket_id = EXCLUDED.bucket_id,
            customer_type = EXCLUDED.customer_type,
            workflow_status = EXCLUDED.workflow_status,
            clog_status = EXCLUDED.clog_status,
            clog_crm_id = EXCLUDED.clog_crm_id,
            applicant_name = COALESCE(portal_tickets.applicant_name, EXCLUDED.applicant_name),
            applicant_email = COALESCE(portal_tickets.applicant_email, EXCLUDED.applicant_email),
            id_number = COALESCE(NULLIF(TRIM(portal_tickets.id_number), ''), EXCLUDED.id_number),
            contact_number = COALESCE(portal_tickets.contact_number, EXCLUDED.contact_number),
            state = COALESCE(portal_tickets.state, EXCLUDED.state),
            language = COALESCE(portal_tickets.language, EXCLUDED.language),
            company_name = COALESCE(portal_tickets.company_name, EXCLUDED.company_name),
            company_no = COALESCE(portal_tickets.company_no, EXCLUDED.company_no),
            missing_fields = EXCLUDED.missing_fields,
            attachment_refs = (
                SELECT COALESCE(jsonb_agg(to_jsonb(value)), '[]'::jsonb)
                FROM (
                    SELECT DISTINCT value
                    FROM jsonb_array_elements_text(portal_tickets.attachment_refs || EXCLUDED.attachment_refs) AS refs(value)
                ) refs
            ),
            source_data = EXCLUDED.source_data,
            updated_at = NOW()
        RETURNING id
    ");

    $fileReferenceBaseUrl = env_value(
        'CLOG_FILE_FETCH_BASE_URL',
        'https://cidb.daythree.net/handler/email_fetch/files'
    );
    $documentStore = new \Cidb\Ticketing\ClogAttachmentDocumentStore($pdo_pg, $fileReferenceBaseUrl);
    $conversationStore = new \Cidb\Ticketing\ClogConversationStore($pdo_pg, $sourceTimezone);
    $attachmentLookupUpdate = $pdo_pg->prepare("
        UPDATE portal_tickets
        SET clog_crm_id=:crm_id,
            attachment_lookup_status=:status,
            attachment_lookup_checked_at=CAST(:checked_at AS timestamptz),
            attachment_lookup_result=CAST(:result AS jsonb),
            attachment_refs=CAST(:attachment_refs AS jsonb),
            updated_at=NOW()
        WHERE id=:ticket_id
    ");

    $emailTagUpdate = $pdo_mysql->prepare("
        UPDATE `emails`
        SET `email_tagging` = ?
        WHERE `id` = ?
    ");

    $advanceCursor = $pdo_pg->prepare("
        UPDATE sync_meta
        SET last_sync = GREATEST(last_sync, ?::timestamptz), updated_at = NOW()
        WHERE sync_key = 'customer_info'
    ");

    while (true) {
        echo '   Fetching next email batch after ' . $cursorTime . " (id {$cursorId})...\n";
        if (defined('STDOUT')) {
            fflush(STDOUT);
        }
        $emailQuery->execute($scopedCrmId === null
            ? [$cursorTime, $cursorTime, $cursorTime, $cursorId]
            : [$scopedCrmId, $cursorTime, $cursorTime, $cursorId]
        );
        $emails = $emailQuery->fetchAll();
        if ($emails === []) {
            echo "   No more matching emails.\n";
            break;
        }
        echo '   Found ' . count($emails) . " emails; loading matching related rows...\n";
        if (defined('STDOUT')) {
            fflush(STDOUT);
        }

        $emailIds = [];
        $crmIds = [];
        foreach ($emails as $emailRow) {
            $emailIds[] = (string)$emailRow['id'];
            $crmIds[] = (string)$emailRow['uniqid'];
        }

        $filesByEmail = [];
        $fileRowsByEmail = [];
        foreach (clog_sync_rows_by_values($pdo_mysql, 'files', 'email_id', array_values(array_unique($emailIds))) as $file) {
            $emailKey = (string)$file['email_id'];
            $filename = (string)$file['filename'];
            $filesByEmail[$emailKey][] = $filename;
            $fileRowsByEmail[$emailKey][] = $file;
        }

        $dataByCrm = [];
        $dataIds = [];
        foreach (clog_sync_rows_by_values($pdo_mysql, 'data', 'InteractionID', array_values(array_unique($crmIds))) as $dataRow) {
            $crmId = (string)$dataRow['InteractionID'];
            $dataByCrm[$crmId][] = $dataRow;
            $dataIds[] = (string)$dataRow['ID'];
        }

        $activityByData = [];
        foreach (clog_sync_rows_by_values($pdo_mysql, 'activity', 'DataID', array_values(array_unique($dataIds))) as $activity) {
            $activityByData[(string)$activity['DataID']][] = $activity;
        }

        $ticketsByData = [];
        foreach (clog_sync_rows_by_values($pdo_mysql, 'ticketing_log', 'TicketID', array_values(array_unique($dataIds))) as $ticket) {
            $ticketsByData[(string)$ticket['TicketID']][] = $ticket;
        }

        // RPA calls are queued here during the batch and dispatched AFTER commit,
        // so no Postgres transaction is held open during slow HTTP calls.
        $pendingRpaJobs = [];

        $pdo_pg->beginTransaction();
        try {
            $batchCancellationCount = 0;
            foreach ($emails as $emailRow) {
                $crmId = trim((string)$emailRow['uniqid']);
                $emailId = (string)$emailRow['id'];
                $emailTimestamp = clog_sync_timestamp((string)$emailRow['time'], $sourceTimezone);
                $emailCreatedAt = $emailTimestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
                $caseStatus = trim((string)($emailRow['case_status'] ?? ''));
                $caseStatus = $caseStatus === '' ? null : $caseStatus;
                $ticketStatusUpdate->execute([$caseStatus, $crmId]);

                // Comprehensive extraction of customer details from enquiry
                $extracted = \Cidb\Ticketing\ClogIdentityExtractor::extractDetails($emailRow);
                $name = $extracted['name'];
                $identityNumber = $extracted['nric'];
                $address = $extracted['email'];
                $phone = $extracted['phone'];
                $state = $extracted['state'];
                $customerType = $extracted['customer_type'];
                $companyName = $extracted['company_name'];
                $companyNo = $extracted['company_no'];
                $language = $extracted['language'];

                $relatedData = $dataByCrm[$crmId] ?? [];
                $relatedDataIds = array_map(static fn(array $row): string => (string)$row['ID'], $relatedData);
                $relatedActivity = [];
                $relatedTickets = [];
                foreach ($relatedDataIds as $dataId) {
                    foreach ($activityByData[$dataId] ?? [] as $activityRow) {
                        $relatedActivity[] = $activityRow;
                    }
                    foreach ($ticketsByData[$dataId] ?? [] as $ticketRow) {
                        $relatedTickets[] = $ticketRow;
                    }
                }

                $filesForThisEmail = $filesByEmail[$emailId] ?? [];
                $hasDocs = count($filesForThisEmail) > 0;

                // Tagging status logic:
                // If no docs -> 'Pending from Customer'
                // If has docs -> 'Open'
                $currentStatus = $hasDocs ? 'Open' : 'Pending from Customer';
                $workflowStatus = $hasDocs ? 'ready_for_ocr' : 'needs_information';

                $existingCase->execute([$crmId]);
                $previous = $existingCase->fetch();
                $fileUpsert->execute([
                    $crmId,
                    $name,
                    $identityNumber,
                    $address,
                    $phone,
                    $state,
                    $customerType,
                    $language,
                    $companyName,
                    $companyNo,
                    $currentStatus,
                    $caseStatus,
                    $emailCreatedAt,
                ]);

                $threadId = $conversationStore->syncEmail($emailRow);
                $conversationStore->syncRelatedRecords($crmId, $relatedData, $relatedActivity, $relatedTickets);
                $ticketStatusUpdate->execute([$caseStatus, $crmId]);

                if ($previous && $previous['clog_status'] !== $caseStatus) {
                    $statusEvent->execute([
                        $previous['id'],
                        'CLOG status changed from ' . ($previous['clog_status'] ?? '(none)') . ' to ' . ($caseStatus ?? '(none)'),
                        json_encode([
                            'crm_id' => $crmId,
                            'previous_status' => $previous['clog_status'],
                            'new_status' => $caseStatus,
                        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    ]);
                }

                if (\Cidb\Ticketing\CancellationDetector::matches($emailRow)
                    && \Cidb\Ticketing\ClogCaseStatus::isOpen($caseStatus)) {

                    $scenarioKey = $customerType === 'individual' ? 'cancellation-individual' : ($customerType === 'company' ? 'cancellation-company' : 'cancellation-classification-pending');
                    $scenarioBucketLookup->execute([$scenarioKey]);
                    $bucketId = $scenarioBucketLookup->fetchColumn() ?: $pendingBucketId;

                    $missingFields = [];
                    if (!$hasDocs) {
                        $missingFields[] = 'attachments';
                    }
                    if ($identityNumber === null) {
                        $missingFields[] = 'nric';
                    }

                    $ticketSourceData = [
                        'source_email_id' => $emailId,
                        'source_timestamp' => (string)$emailRow['time'],
                        'email_type' => $emailRow['emailtype'] ?? null,
                        'case_id' => $emailRow['case_id'] ?? null,
                        'case_status' => $caseStatus,
                        'extracted_fields' => $extracted,
                    ];

                    $ticketUpsert->execute([
                        $crmId,
                        $scenarioKey,
                        $bucketId,
                        $customerType,
                        $workflowStatus,
                        $crmId,
                        $caseStatus,
                        $name,
                        $address,
                        $identityNumber,
                        $phone,
                        $state,
                        $language,
                        $companyName,
                        $companyNo,
                        json_encode($missingFields, JSON_THROW_ON_ERROR),
                        json_encode($filesForThisEmail, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                        json_encode($ticketSourceData, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    ]);
                    $ticketId = (string)$ticketUpsert->fetchColumn();
                    $ticketConversationUpdate->execute([$crmId, $threadId, $crmId]);

                    $currentFiles = array_map(
                        static fn(array $file): array => [
                            'email_id' => $emailId,
                            'crm_id' => $crmId,
                            'filename' => (string)($file['filename'] ?? ''),
                        ],
                        $fileRowsByEmail[$emailId] ?? []
                    );

                    clog_sync_ticket_attachments(
                        $attachmentLookupUpdate,
                        $documentStore,
                        $ticketId,
                        $crmId,
                        [$emailId],
                        $currentFiles
                    );

                    if (!$hasDocs) {
                        // Tag as 'Pending Customer' in MySQL emails table
                        try {
                            $emailTagUpdate->execute(['Pending Customer', $emailId]);
                        } catch (\Throwable $tagErr) {
                            // Non-fatal if table column is restricted
                        }

                        echo "   [Ticket #{$ticketId}] No docs found. Tagged as 'Pending Customer' (workflow: needs_information).\n";

                        // Queue the RPA call; it is dispatched after this batch commits.
                        $pendingRpaJobs[] = [
                            'ticket_id' => $ticketId,
                            'crm_id' => $crmId,
                            'input' => [
                                'action_type' => 'request_information',
                                'crm' => $crmId,
                                'email' => $address,
                                'name' => $name,
                                'customer_type' => $customerType,
                                'id_number' => $identityNumber,
                                'language' => $language,
                                'state' => $state,
                                'contact_number' => $phone,
                            ],
                        ];
                    } else {
                        echo "   [Ticket #{$ticketId}] Found " . count($currentFiles) . " document(s). Validated and queued for OCR (workflow: ready_for_ocr).\n";
                    }

                    $cancellationCount++;
                    $batchCancellationCount++;
                }

                $processedCount++;
            }

            $lastEmail = $emails[array_key_last($emails)];
            $lastSourceTime = clog_sync_timestamp((string)$lastEmail['time'], $sourceTimezone);
            if ($scopedCrmId === null) {
                $advanceCursor->execute([
                    $lastSourceTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
                ]);
                if ($advanceCursor->rowCount() !== 1) {
                    throw new RuntimeException("Failed to advance 'customer_info' sync cursor.");
                }
            }
            $pdo_pg->commit();

            $cursorTime = (string)$lastEmail['time'];
            $cursorId = (string)$lastEmail['id'];
            $batchCount++;
            $lastTimestamp = clog_sync_timestamp($cursorTime, $sourceTimezone);
            echo sprintf(
                "   Batch %d committed: %d records processed, %d cancellation candidates queued%s; %.1f sec elapsed.\n",
                $batchCount,
                $processedCount,
                $batchCancellationCount,
                $scopedCrmId === null
                    ? '; cursor ' . $lastTimestamp->format('Y-m-d H:i:s') . ' (email id ' . $cursorId . ')'
                    : '',
                microtime(true) - $syncStartedAt
            );
            if (defined('STDOUT')) {
                fflush(STDOUT);
            }
        } catch (Throwable $e) {
            if ($pdo_pg->inTransaction()) {
                $pdo_pg->rollBack();
            }
            throw $e;
        }

        // Batch is committed. Now it is safe to call the slow RPA API.
        if ($pendingRpaJobs !== []) {
            cidb_dispatch_rpa_jobs($pendingRpaJobs);
            $pendingRpaJobs = [];
        }
    }

    $ticketScope = $scopedCrmId === null ? '' : ' AND clog_crm_id = :crm_id';
    $clogTicketsQuery = $pdo_pg->prepare("
        SELECT id, clog_crm_id
        FROM portal_tickets
        WHERE clog_crm_id IS NOT NULL{$ticketScope}
          AND (
              (source_type = 'clog' AND LOWER(TRIM(COALESCE(clog_status, ''))) = 'open')
              OR source_type = 'email'
          )
    ");
    if ($scopedCrmId === null) {
        $clogTicketsQuery->execute();
    } else {
        $clogTicketsQuery->execute(['crm_id' => $scopedCrmId]);
    }
    $clogTickets = $clogTicketsQuery->fetchAll();
    foreach (array_chunk($clogTickets, 500) as $ticketChunk) {
        $chunkCrmIds = array_values(array_unique(array_map(
            static fn(array $ticket): string => (string)$ticket['clog_crm_id'],
            $ticketChunk
        )));
        $emailsByCrm = [];
        $crmByEmail = [];
        foreach (clog_sync_emails_by_crm_ids($pdo_mysql, $chunkCrmIds, true) as $sourceEmail) {
            $crmId = (string)$sourceEmail['uniqid'];
            $emailId = (string)$sourceEmail['id'];
            $emailsByCrm[$crmId][] = $emailId;
            $crmByEmail[$emailId] = $crmId;
        }
        $filesByEmail = [];
        foreach (clog_sync_rows_by_values($pdo_mysql, 'files', 'email_id', array_keys($crmByEmail)) as $file) {
            $filesByEmail[(string)$file['email_id']][] = $file;
        }

        $pdo_pg->beginTransaction();
        try {
            foreach ($ticketChunk as $ticket) {
                $crmId = (string)$ticket['clog_crm_id'];
                $emailIdsForCrm = $emailsByCrm[$crmId] ?? [];
                $filesForCrm = [];
                foreach ($emailIdsForCrm as $emailId) {
                    foreach ($filesByEmail[$emailId] ?? [] as $file) {
                        $filesForCrm[] = [
                            'email_id' => $emailId,
                            'crm_id' => $crmId,
                            'filename' => (string)($file['filename'] ?? ''),
                        ];
                    }
                }
                $documents = clog_sync_ticket_attachments(
                    $attachmentLookupUpdate,
                    $documentStore,
                    (string)$ticket['id'],
                    $crmId,
                    $emailIdsForCrm,
                    $filesForCrm
                );
                foreach ($documents as $document) {
                    if ($document['reference_status'] === 'ready') {
                        $attachmentReferenceCount++;
                    } else {
                        $invalidAttachmentReferenceCount++;
                    }
                }
            }
            $pdo_pg->commit();
        } catch (Throwable $e) {
            if ($pdo_pg->inTransaction()) {
                $pdo_pg->rollBack();
            }
            throw $e;
        }
    }

    echo "Sync completed. Processed {$processedCount} MySQL email records, processed {$cancellationCount} cancellation candidates, and checked {$attachmentReferenceCount} attachment references ({$invalidAttachmentReferenceCount} invalid).\n";
    echo '[' . date('Y-m-d H:i:s') . "] Cross-DB sync completed successfully.\n";
} catch (Throwable $e) {
    error_log('CRON ERROR: ' . $e->getMessage());
    fwrite(STDERR, "Error encountered: {$e->getMessage()}\n");
    $exitCode = 1;
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}

if (isset($exitCode)) {
    exit($exitCode);
}