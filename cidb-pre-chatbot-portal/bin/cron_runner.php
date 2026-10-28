<?php
/**
 * CIDB MySQL CLOG -> PostgreSQL interaction data sync.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI access only.\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Ticketing/CancellationDetector.php';
require_once dirname(__DIR__) . '/src/Ticketing/ClogCaseStatus.php';
require_once dirname(__DIR__) . '/src/Ticketing/ClogIdentityExtractor.php';
require_once dirname(__DIR__) . '/src/Ticketing/ClogAttachmentReference.php';
require_once dirname(__DIR__) . '/src/Ticketing/ClogAttachmentDocumentStore.php';
require_once dirname(__DIR__) . '/src/Ticketing/ClogConversationStore.php';

$scopedCrmId = null;
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--crm-id=')) {
        fwrite(STDERR, "Usage: php bin/cron_runner.php [--crm-id=CRM-ID]\n");
        exit(2);
    }
    if ($scopedCrmId !== null) {
        fwrite(STDERR, "Specify --crm-id only once.\n");
        exit(2);
    }
    $scopedCrmId = trim(substr($argument, strlen('--crm-id=')));
    if ($scopedCrmId === '' || strlen($scopedCrmId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $scopedCrmId) === 1) {
        fwrite(STDERR, "--crm-id must be a non-empty CRM ID of at most 255 characters.\n");
        exit(2);
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
    $pdo_mysql = new PDO(
        sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            env_value('CLOG_DB_HOST'),
            env_value('CLOG_DB_DATABASE')
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

    $pdo_pg = new PDO(
        sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d',
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
    echo '[' . date('Y-m-d H:i:s') . "] Starting MySQL CLOG -> PostgreSQL sync...\n";

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
        echo "   Scoped read-only CLOG source sync for one CRM ID; global sync cursor will not be changed.\n";
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
            (crm_id, applicant_name, nric, email, current_status, clog_status, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'Open', ?, ?::timestamptz, NOW())
        ON CONFLICT (crm_id) DO UPDATE SET
            applicant_name = COALESCE(EXCLUDED.applicant_name, cidb_interaction_data.applicant_name),
            nric = COALESCE(NULLIF(TRIM(cidb_interaction_data.nric), ''), EXCLUDED.nric),
            email = COALESCE(EXCLUDED.email, cidb_interaction_data.email),
            clog_status = COALESCE(EXCLUDED.clog_status, cidb_interaction_data.clog_status),
            updated_at = NOW()
    ");
    $existingCase = $pdo_pg->prepare(
        'SELECT id, clog_status FROM cidb_interaction_data WHERE crm_id = ?'
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
    ");
    $pendingScenarioBucket->execute();
    $pendingBucketId = $pendingScenarioBucket->fetchColumn();
    if (!$pendingBucketId) {
        throw new RuntimeException("Missing default ticket bucket for cancellation classification.");
    }
    $ticketUpsert = $pdo_pg->prepare("
        INSERT INTO portal_tickets
            (source_type, source_ref, scenario_key, bucket_id, customer_type, workflow_status, clog_crm_id,
             clog_status, applicant_name, applicant_email, id_number, missing_fields, attachment_refs, source_data)
        VALUES
            ('clog', ?, 'cancellation-classification-pending', ?, 'unknown', 'intake_pending', ?,
             ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb)
        ON CONFLICT (source_type, source_ref) DO UPDATE SET
            clog_status = EXCLUDED.clog_status,
            clog_crm_id = EXCLUDED.clog_crm_id,
            applicant_name = COALESCE(portal_tickets.applicant_name, EXCLUDED.applicant_name),
            applicant_email = COALESCE(portal_tickets.applicant_email, EXCLUDED.applicant_email),
            id_number = COALESCE(NULLIF(TRIM(portal_tickets.id_number), ''), EXCLUDED.id_number),
            missing_fields = CASE
                WHEN EXCLUDED.id_number IS NOT NULL THEN portal_tickets.missing_fields - 'nric'
                ELSE portal_tickets.missing_fields
            END,
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
    $fileReferenceBaseUrl=env_value(
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
    $informationActionState = filter_var(
        env_value('RPA_WORKFLOW_ACTIONS_ENABLED', 'false'),
        FILTER_VALIDATE_BOOLEAN
    ) ? 'queued' : 'manual_review';
    $clogInformationIntent = $pdo_pg->prepare("
        INSERT INTO portal_rpa_action_queue (ticket_id, action_type, dedupe_key, state, payload)
        VALUES (?, 'request_information', ?, ?, ?::jsonb)
        ON CONFLICT (dedupe_key) DO NOTHING
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
                $emailKey=(string)$file['email_id'];
                $filename=(string)$file['filename'];
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
                $name = trim((string)($emailRow['name'] ?? ''));
                $name = $name === '' ? null : $name;
                $identityNumber = \Cidb\Ticketing\ClogIdentityExtractor::extractNric(
                    (string)($emailRow['body_text'] ?? ''),
                    (string)($emailRow['body_html'] ?? '')
                );
                $address = trim((string)($emailRow['email'] ?? ''));
                $address = $address === '' ? null : $address;

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

                $existingCase->execute([$crmId]);
                $previous = $existingCase->fetch();
                $fileUpsert->execute([
                    $crmId,
                    $name,
                    $identityNumber,
                    $address,
                    $caseStatus,
                    $emailCreatedAt,
                ]);
                $threadId=$conversationStore->syncEmail($emailRow);
                $conversationStore->syncRelatedRecords($crmId,$relatedData,$relatedActivity,$relatedTickets);
                $ticketStatusUpdate->execute([$caseStatus,$crmId]);

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
                    $ticketSourceData = [
                        'source_email_id' => $emailId,
                        'source_timestamp' => (string)$emailRow['time'],
                        'email_type' => $emailRow['emailtype'] ?? null,
                        'case_id' => $emailRow['case_id'] ?? null,
                        'case_status' => $caseStatus,
                    ];
                    $ticketUpsert->execute([
                        $crmId,
                        $pendingBucketId,
                        $crmId,
                        $caseStatus,
                        $name,
                        $address,
                        $identityNumber,
                        json_encode(array_values(array_filter([
                            'customer_type',
                            $identityNumber === null ? 'nric' : null,
                            'contact_number',
                            'state',
                            'language',
                            'company_name/company_no_if_company',
                            'attachment_storage_review',
                        ])), JSON_THROW_ON_ERROR),
                        json_encode($filesByEmail[$emailId] ?? [], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                        json_encode($ticketSourceData, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    ]);
                    $ticketId = $ticketUpsert->fetchColumn();
                    $ticketConversationUpdate->execute([$crmId,$threadId,$crmId]);
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
                        (string)$ticketId,
                        $crmId,
                        [$emailId],
                        $currentFiles
                    );
                    $missingFields = array_values(array_filter([
                        'customer_type',
                        $identityNumber === null ? 'nric' : null,
                        'contact_number',
                        'state',
                        'language',
                        'company_name/company_no_if_company',
                        'attachment_storage_review',
                    ]));
                    $clogInformationIntent->execute([
                        $ticketId,
                        'clog:'.$crmId.':request-information:'.hash('sha256', json_encode($missingFields, JSON_THROW_ON_ERROR)),
                        $informationActionState,
                        json_encode(['missing_fields' => $missingFields], JSON_THROW_ON_ERROR),
                    ]);
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

    echo "Sync completed. Processed {$processedCount} MySQL email records, queued {$cancellationCount} cancellation candidates, and checked {$attachmentReferenceCount} attachment references ({$invalidAttachmentReferenceCount} invalid).\n";
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
