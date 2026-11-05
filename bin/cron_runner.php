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
require_once dirname(__DIR__) . '/src/RpaHubDirectInserter.php';
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
$backlogMode = false;
$skipRpaDispatch = false;

// Existing argument parsing – extend to recognise --cron2
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--all') {
        $allMode = true;
        continue;
    }
    if ($argument === '--skip-rpa-dispatch') {
        $skipRpaDispatch = true;
        continue;
    }
    if ($argument === '--backlog') {
        $backlogMode = true;
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
    fwrite(STDERR, "Usage: php bin/cron_runner.php [--crm-id=CRM-ID] [--all] [--backlog] [--cron2] [--skip-rpa-dispatch]\n");
    exit(2);
}

if ($skipRpaDispatch && ($scopedCrmId === null || $allMode || $cron2Mode)) {
    fwrite(STDERR, "--skip-rpa-dispatch requires one scoped --crm-id and cannot be combined with --all or --cron2.\n");
    exit(2);
}
if ($allMode && $scopedCrmId !== null) {
    fwrite(STDERR, "Use --all or --crm-id, not both.\n");
    exit(2);
}

if ($cron2Mode) {
    $cron2Runner = __DIR__ . DIRECTORY_SEPARATOR . 'cron2_recheck.php';
    $commandLine = escapeshellarg(PHP_BINARY)
        . ' -d extension_dir=' . escapeshellarg((string)ini_get('extension_dir'))
        . ' ' . escapeshellarg($cron2Runner) . ' run';
    if ($scopedCrmId !== null) {
        $commandLine .= ' ' . escapeshellarg('--crm-id=' . $scopedCrmId);
    }
    if ($backlogMode) {
        $commandLine .= ' --backlog';
    }
    passthru($commandLine, $cron2ExitCode);
    exit($cron2ExitCode);
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

function cidb_bot_input(array $input, string $request): array
{
    return RpaHubDirectInserter::buildBotInput($input, $request);
}

function cidb_direct_rpa_jobs(array $jobs): void
{
    foreach ($jobs as $job) {
        $ticketId = (string)$job['ticket_id'];
        $crmId = (string)$job['crm_id'];
        $botInput = cidb_bot_input($job['input'], 'Pending Customer Attachment');
        $dedupeKey = 'clog:' . $crmId . ':request-information:' . hash('sha256', json_encode($botInput, JSON_THROW_ON_ERROR));
        try {
            $result = (new RpaHubDirectInserter())->insert($botInput);
            $scheduleId = $result['schedule_id'] ?? null;
            echo "   [RPA Hub DB] {$result['status']} for {$crmId}" . ($scheduleId ? " (Schedule ID: {$scheduleId})" : '') . ".\n";
            $statusDb = cidb_pg_connect();
            $statusDb->prepare("UPDATE cidb_interaction_data SET current_status = 'Pending Customer Attachment', updated_at = NOW() WHERE crm_id = ? AND current_status NOT IN ('Customer Replied - Verified', 'Customer Replied - Document Reject')")
                ->execute([$crmId]);
            $row = [
                'ticket_id' => $ticketId,
                'dedupe_key' => $dedupeKey,
                'state' => 'succeeded',
                'payload_json' => json_encode($botInput, JSON_THROW_ON_ERROR),
                'response_json' => json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'error_code' => '',
                'event_json' => json_encode([
                    'action_type' => 'request_information',
                    'state' => 'succeeded',
                    'schedule_id' => $scheduleId,
                ], JSON_THROW_ON_ERROR),
            ];
        } catch (Throwable $error) {
            echo "   [RPA Hub DB Error] " . get_class($error) . " for {$crmId}.\n";
            $row = [
                'ticket_id' => $ticketId,
                'dedupe_key' => $dedupeKey,
                'state' => 'failed',
                'payload_json' => json_encode($botInput, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'response_json' => json_encode(['error' => get_class($error)], JSON_THROW_ON_ERROR),
                'error_code' => 'RPA_DIRECT_INSERT_FAILED',
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

function clog_sync_emails_by_crm_ids(PDO $pdo, array $crmIds, bool $openOnly = false, ?string $start = null, ?string $end = null): array
{
    if ($crmIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($crmIds), '?'));
    $statusFilter = $openOnly ? " AND LOWER(TRIM(`case_status`)) = 'open'" : '';
    $dateFilter = $start !== null && $end !== null ? ' AND `time` >= ? AND `time` < ?' : '';
    $stmt = $pdo->prepare("
        SELECT *
        FROM `emails`
        WHERE `uniqid` IN ({$placeholders})
          AND LOWER(TRIM(COALESCE(`emailtype`, ''))) IN ('new email', 'reply mail')
          {$statusFilter}{$dateFilter}
        ORDER BY `time` ASC, `id` ASC
    ");
    $params = array_values($crmIds);
    if ($dateFilter !== '') {
        $params[] = $start;
        $params[] = $end;
    }
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function clog_sync_customer_type_from_data(array $dataRows): string
{
    $hasIndividualType = false;
    foreach ($dataRows as $row) {
        $type = strtolower(trim((string)($row['CustomerType'] ?? '')));
        $category = strtolower(trim((string)($row['Column8'] ?? '')));
        if (str_contains($type, 'company') || str_contains($type, 'syarikat')
            || str_contains($category, 'syarikat')) {
            return 'company';
        }
        if ($type !== '') {
            if (str_starts_with($type, 'individual') || str_starts_with($type, 'individu')) {
                $hasIndividualType = true;
            } else {
                return 'unknown';
            }
        }
    }
    return $hasIndividualType ? 'individual' : 'unknown';
}

function clog_sync_is_individual_cancellation(array $emailRow, array $dataRows): bool
{
    if (strtolower(trim((string)($emailRow['emailtype'] ?? ''))) === 'replyto mail') {
        return false;
    }
    if (clog_sync_customer_type_from_data($dataRows) !== 'individual') {
        return false;
    }
    $categoryConfirmsCancellation = false;
    foreach ($dataRows as $row) {
        $category = strtolower(trim((string)($row['Column8'] ?? '')));
        if (str_contains($category, 'syarikat') || str_contains($category, 'company')) {
            return false;
        }
        if (str_contains($category, 'pembatalan')
            && (str_contains($category, 'id') || str_contains($category, 'kad pengenalan'))
            && (str_contains($category, 'individu') || str_contains($category, 'individual'))) {
            $categoryConfirmsCancellation = true;
        }
    }
    // CLOG can retain a cancellation category while the same CRM thread later
    // receives a different request. Explicit reset/change intents on today's
    // email take precedence over that stale category.
    $currentText = strtolower(implode(' ', array_filter(array_map(
        static fn(mixed $value): string => is_string($value) ? $value : '',
        array_values($emailRow)
    ))));
    if (preg_match('/\b(reset|password|kata laluan|tukar e-?mel|tukar emel|change of e-?mail|change e-?mail|kemaskini e-?mel|kemaskini emel|forgot password|unlock account|permohonan baru|new application|already exists)\b/i', $currentText) === 1) {
        return false;
    }
    if (\Cidb\Ticketing\CancellationDetector::matches($emailRow)) {
        return true;
    }
    return $categoryConfirmsCancellation;
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
$todayStart = (new DateTimeImmutable('today', $sourceTimezone))->format('Y-m-d H:i:s');
$tomorrowStart = (new DateTimeImmutable('today', $sourceTimezone))->modify('+1 day')->format('Y-m-d H:i:s');

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
    $cursorTime = $backlogMode && $scopedCrmId === null
        ? $cursor->setTimezone($sourceTimezone)->format('Y-m-d H:i:s')
        : ($backlogMode ? '1000-01-01 00:00:00' : $todayStart);
    $cursorId = '0';
    $processedCount = 0;
    $cancellationCount = 0;
    $attachmentReferenceCount = 0;
    $invalidAttachmentReferenceCount = 0;
    $todayCrmIds = [];
    $batchCount = 0;
    $batchSize = 500;
    $syncStartedAt = microtime(true);
    if ($backlogMode) {
        echo "   [AD HOC BACKLOG] Reading records outside the normal today-only window.\n";
    } else {
        echo "   [TODAY ONLY] Reading open CLOG emails from {$todayStart} until {$tomorrowStart} ({$sourceTimezoneName}).\n";
    }
    if ($scopedCrmId !== null) {
        echo "   Scoped to CRM ID: {$scopedCrmId}. Global sync cursor will not be changed.\n";
    }
    if (defined('STDOUT')) {
        fflush(STDOUT);
    }

    if ($backlogMode && $scopedCrmId === null) {
        $emailSql = "SELECT * FROM `emails` WHERE `time` >= ? AND (`time` > ? OR (`time` = ? AND `id` > ?)) AND `uniqid` IS NOT NULL AND TRIM(`uniqid`) <> '' ORDER BY `time`,`id` LIMIT {$batchSize}";
    } elseif ($backlogMode) {
        $emailSql = "SELECT * FROM `emails` WHERE `uniqid` = ? AND LOWER(TRIM(COALESCE(`case_status`,'')))='open' AND (`time` > ? OR (`time` = ? AND `id` > ?)) ORDER BY `time`,`id` LIMIT {$batchSize}";
    } elseif ($scopedCrmId === null) {
        $emailSql = "SELECT * FROM `emails` WHERE `time` >= ? AND `time` < ? AND LOWER(TRIM(COALESCE(`case_status`,'')))='open' AND (`time` > ? OR (`time` = ? AND `id` > ?)) AND `uniqid` IS NOT NULL AND TRIM(`uniqid`) <> '' ORDER BY `time`,`id` LIMIT {$batchSize}";
    } else {
        $emailSql = "SELECT * FROM `emails` WHERE `uniqid` = ? AND `time` >= ? AND `time` < ? AND LOWER(TRIM(COALESCE(`case_status`,'')))='open' AND (`time` > ? OR (`time` = ? AND `id` > ?)) ORDER BY `time`,`id` LIMIT {$batchSize}";
    }
    $emailQuery = $pdo_mysql->prepare($emailSql);

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
            current_status = COALESCE(cidb_interaction_data.current_status, EXCLUDED.current_status),
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
            workflow_status = CASE
                WHEN portal_tickets.workflow_status IN ('intake_pending', 'needs_information')
                     AND EXCLUDED.workflow_status = 'ready_for_ocr'
                THEN 'ready_for_ocr'
                ELSE portal_tickets.workflow_status
            END,
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
    $readyDocumentCount = $pdo_pg->prepare("SELECT count(*) FROM portal_ticket_documents WHERE ticket_id = ? AND reference_status = 'ready'");

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
        if ($backlogMode) {
            $emailQuery->execute($scopedCrmId === null
                ? [$cursorTime, $cursorTime, $cursorTime, $cursorId]
                : [$scopedCrmId, $cursorTime, $cursorTime, $cursorId]
            );
        } elseif ($scopedCrmId === null) {
            $emailQuery->execute([$todayStart, $tomorrowStart, $cursorTime, $cursorTime, $cursorId]);
        } else {
            $emailQuery->execute([$scopedCrmId, $todayStart, $tomorrowStart, $cursorTime, $cursorTime, $cursorId]);
        }
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
        $caseHasAttachments = [];
        foreach ($emails as $sourceEmail) {
            $sourceCrmId = trim((string)($sourceEmail['uniqid'] ?? ''));
            $sourceEmailId = (string)$sourceEmail['id'];
            $todayCrmIds[$sourceCrmId] = true;
            if (in_array(strtolower(trim((string)($sourceEmail['emailtype'] ?? ''))), ['new email', 'reply mail'], true)
                && ($filesByEmail[$sourceEmailId] ?? []) !== []) {
                $caseHasAttachments[$sourceCrmId] = true;
            }
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
                // CLOG's stored case type is authoritative. Email subjects and
                // display names must not promote a company or unknown case to Individual.
                $customerType = clog_sync_customer_type_from_data($relatedData);
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

                if (clog_sync_is_individual_cancellation($emailRow, $relatedData)
                    && \Cidb\Ticketing\ClogCaseStatus::isOpen($caseStatus)) {

                    $scenarioKey = 'cancellation-individual';
                    $scenarioBucketLookup->execute([$scenarioKey]);
                    $bucketId = $scenarioBucketLookup->fetchColumn();
                    if (!$bucketId) {
                        throw new RuntimeException('The individual cancellation bucket is not configured.');
                    }

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
                        $readyDocumentCount->execute([$ticketId]);
                        $threadHasPortalDocument = (int)$readyDocumentCount->fetchColumn() > 0;
                        if (!isset($caseHasAttachments[$crmId]) && !$threadHasPortalDocument) {
                            try {
                                $emailTagUpdate->execute(['Pending Customer', $emailId]);
                            } catch (\Throwable $tagErr) {
                                // CLOG tagging is informational; the portal ticket remains authoritative.
                            }
                            echo "   [Ticket #{$ticketId}] No attachment exists on today's CLOG thread; staging the exact request-information payload.\n";
                            $pendingRpaJobs[] = [
                                'ticket_id' => $ticketId,
                                'crm_id' => $crmId,
                                'input' => [
                                    'action_type' => 'request_information',
                                    'crm' => $crmId,
                                    'email' => $address,
                                    'name' => $name,
                                    'customer_type' => 'individual',
                                    'id_number' => $identityNumber,
                                    'language' => $language,
                                    'state' => $state,
                                    'contact_number' => $phone,
                                ],
                            ];
                        } else {
                            echo "   [Ticket #{$ticketId}] No attachment on this email, but an attachment exists elsewhere in today's thread or portal; no request was sent.\n";
                        }
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
            if ($backlogMode && $scopedCrmId === null) {
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

        // Batch is committed before writing to the separate RPA Hub database.
        if ($pendingRpaJobs !== []) {
            if ($skipRpaDispatch) {
                echo '   RPA dispatch skipped for ' . count($pendingRpaJobs) . " request-information job(s) by explicit CLI option.\n";
            } else {
                cidb_direct_rpa_jobs($pendingRpaJobs);
            }
            $pendingRpaJobs = [];
        }
    }

    $ticketCrmIds = array_keys($todayCrmIds);
    if ($ticketCrmIds === []) {
        $clogTickets = [];
    } else {
    $ticketPlaceholders = implode(',', array_fill(0, count($ticketCrmIds), '?'));
    $clogTicketsQuery = $pdo_pg->prepare("
        SELECT id, clog_crm_id
        FROM portal_tickets
        WHERE source_type = 'clog'
          AND customer_type = 'individual'
          AND scenario_key = 'cancellation-individual'
          AND LOWER(TRIM(COALESCE(clog_status, ''))) = 'open'
          AND clog_crm_id IN ({$ticketPlaceholders})
    ");
    $clogTicketsQuery->execute($ticketCrmIds);
    $clogTickets = $clogTicketsQuery->fetchAll();
    }
    foreach (array_chunk($clogTickets, 500) as $ticketChunk) {
        $chunkCrmIds = array_values(array_unique(array_map(
            static fn(array $ticket): string => (string)$ticket['clog_crm_id'],
            $ticketChunk
        )));
        $emailsByCrm = [];
        $crmByEmail = [];
        foreach (clog_sync_emails_by_crm_ids(
            $pdo_mysql,
            $chunkCrmIds,
            true,
            $backlogMode ? null : $todayStart,
            $backlogMode ? null : $tomorrowStart
        ) as $sourceEmail) {
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
