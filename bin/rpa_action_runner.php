<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/RpaClient.php';

$command = $argv[1] ?? 'check';
if (!in_array($command, ['check', 'dry-run', 'run'], true)) {
    fwrite(STDERR, "Usage: php bin/rpa_action_runner.php check|dry-run|run\n");
    exit(2);
}

$enabled = filter_var(env_value('RPA_WORKFLOW_ACTIONS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
$endpoint = env_value('RPA_BOT_ENDPOINT');
if ($command === 'run') {
    if (!$enabled) {
        fwrite(STDERR, "RPA workflow dispatch is disabled. Set RPA_WORKFLOW_ACTIONS_ENABLED=true after approval.\n");
        exit(2);
    }
    if (strtolower((string)parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
        fwrite(STDERR, "RPA workflow dispatch requires an HTTPS endpoint.\n");
        exit(2);
    }
    if (!extension_loaded('curl')) {
        fwrite(STDERR, "The PHP cURL extension is required for RPA workflow dispatch.\n");
        exit(2);
    }
}

try {
    $pdo = db();
} catch (Throwable $error) {
    fwrite(STDERR, "Database connection failed (" . get_class($error) . ").\n");
    exit(1);
}

if ($command === 'check') {
    echo "Database connection passed. RPA workflow dispatch is " . ($enabled ? "enabled" : "disabled") . ". No RPA request sent.\n";
    exit(0);
}

$batchSize = filter_var(env_value('RPA_ACTION_BATCH_SIZE', '1'), FILTER_VALIDATE_INT);
if ($batchSize === false || $batchSize < 1 || $batchSize > 10) {
    fwrite(STDERR, "RPA_ACTION_BATCH_SIZE must be an integer from 1 through 10.\n");
    exit(2);
}

$lockPath = __DIR__ . '/logs/rpa_action_runner.lock';
if (!is_dir(dirname($lockPath)) && !mkdir(dirname($lockPath), 0755, true) && !is_dir(dirname($lockPath))) {
    fwrite(STDERR, "Unable to create the RPA worker log directory.\n");
    exit(1);
}
$lock = fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_resource($lock)) {
        fclose($lock);
    }
    fwrite(STDOUT, "RPA action worker already running; skipping this run.\n");
    exit(0);
}

function rpa_action_candidate(PDO $pdo, bool $claim, array $skipIds = []): ?array
{
    $pdo->beginTransaction();
    try {
        if ($claim) {
            $stale = $pdo->prepare("
                UPDATE portal_rpa_action_queue
                SET state = 'uncertain', error_code = 'RPA_DISPATCH_OUTCOME_UNKNOWN',
                    updated_at = NOW()
                WHERE state = 'dispatching' AND updated_at < NOW() - INTERVAL '15 minutes'
            ");
            $stale->execute();
        }

        $skipSql = $skipIds === []
            ? ''
            : 'AND q.id::text NOT IN (' . implode(',', array_fill(0, count($skipIds), '?')) . ')';
        $query = $pdo->prepare("
            SELECT q.id AS action_id, q.action_type, q.payload AS action_payload,
                   t.id AS ticket_id, t.source_type, t.workflow_status, t.assigned_to,
                   t.customer_type AS ticket_customer_type, t.applicant_name AS ticket_name,
                   t.applicant_email AS ticket_email, t.id_number, t.contact_number AS ticket_phone,
                   t.state AS ticket_state, t.language AS ticket_language,
                   t.clog_crm_id, t.clog_status AS ticket_clog_status,
                   t.missing_fields, t.execution_result,
                   i.crm_id AS interaction_crm_id, i.applicant_name AS interaction_name,
                   i.nric AS interaction_nric, i.email AS interaction_email,
                   i.phone AS interaction_phone, i.customer_type AS interaction_customer_type,
                   i.state AS interaction_state, i.language AS interaction_language,
                   i.clog_status AS interaction_clog_status,
                   COALESCE(documents.file_count, 0) AS file_count,
                   COALESCE(documents.filenames, '[]'::jsonb) AS filenames,
                   COALESCE(documents.all_documents_verified, false) AS all_documents_verified
            FROM portal_rpa_action_queue q
            JOIN portal_tickets t ON t.id = q.ticket_id
            LEFT JOIN cidb_interaction_data i ON i.id = t.interaction_id
            LEFT JOIN LATERAL (
                SELECT count(*) AS file_count,
                       jsonb_agg(d.original_filename ORDER BY d.created_at, d.id) AS filenames,
                       bool_and(
                           d.ocr_status IN ('valid', 'manual_review')
                           AND COALESCE(d.ocr_result->>'identity_check_verified' = 'true', false)
                       ) AS all_documents_verified
                FROM portal_ticket_documents d
                WHERE d.ticket_id = t.id AND d.reference_status = 'ready'
            ) documents ON true
            WHERE q.state = 'queued' AND q.next_attempt_at <= NOW()
              {$skipSql}
            ORDER BY q.created_at, q.id
            LIMIT 1
            FOR UPDATE OF q, t SKIP LOCKED
        ");
        $query->execute(array_values(array_map('strval', $skipIds)));
        $candidate = $query->fetch(PDO::FETCH_ASSOC);
        if (!$candidate) {
            $pdo->commit();
            return null;
        }

        $candidate['action_payload'] = is_array($candidate['action_payload'])
            ? $candidate['action_payload']
            : json_decode((string)$candidate['action_payload'], true, 512, JSON_THROW_ON_ERROR);
        $candidate['missing_fields'] = is_array($candidate['missing_fields'])
            ? $candidate['missing_fields']
            : json_decode((string)$candidate['missing_fields'], true, 512, JSON_THROW_ON_ERROR);
        $candidate['execution_result'] = is_array($candidate['execution_result'])
            ? $candidate['execution_result']
            : json_decode((string)$candidate['execution_result'], true);
        $candidate['filenames'] = is_array($candidate['filenames'])
            ? $candidate['filenames']
            : json_decode((string)$candidate['filenames'], true, 512, JSON_THROW_ON_ERROR);

        if ($claim) {
            $update = $pdo->prepare("
                UPDATE portal_rpa_action_queue
                SET state = 'dispatching', attempts = attempts + 1, updated_at = NOW()
                WHERE id = ? AND state = 'queued'
            ");
            $update->execute([$candidate['action_id']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Unable to claim the queued RPA action.');
            }
        }
        $pdo->commit();
        return $candidate;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function rpa_action_reason(array $candidate): ?string
{
    if ($candidate['action_type'] === 'request_information') {
        if ((int)$candidate['file_count'] !== 0) {
            return 'RPA_REVIEW_ATTACHMENT_PRESENT';
        }
        if (!is_array($candidate['missing_fields']) || $candidate['missing_fields'] === []) {
            return 'RPA_REVIEW_NO_MISSING_FIELDS';
        }
        return null;
    }

    if ($candidate['action_type'] !== 'execute_cancellation') {
        return 'RPA_REVIEW_UNSUPPORTED_ACTION';
    }
    if ($candidate['workflow_status'] !== 'ready_for_execution' || $candidate['assigned_to'] === null) {
        return 'RPA_REVIEW_TICKET_NOT_READY';
    }
    if ($candidate['ticket_customer_type'] !== 'individual') {
        return 'RPA_REVIEW_INDIVIDUAL_ONLY';
    }
    if (strtolower(trim((string)($candidate['ticket_clog_status'] ?: $candidate['interaction_clog_status']))) !== 'open') {
        return 'RPA_REVIEW_CLOG_CASE_NOT_OPEN';
    }
    if ((int)$candidate['file_count'] < 1 || !$candidate['all_documents_verified']) {
        return 'RPA_REVIEW_OCR_NOT_VERIFIED';
    }

    $approval = is_array($candidate['execution_result']) ? $candidate['execution_result'] : [];
    if (($approval['manual_approval_status'] ?? null) !== 'approved'
        || trim((string)($approval['approved_by'] ?? '')) === ''
        || ($approval['ocr_status'] ?? null) !== 'MANUAL_REVIEW_APPROVED') {
        return 'RPA_REVIEW_MANUAL_APPROVAL_REQUIRED';
    }
    return null;
}

function rpa_action_input(array $candidate): array
{
    $language = $candidate['ticket_language'] ?: $candidate['interaction_language'];
    $customerType = $candidate['ticket_customer_type'] === 'unknown'
        ? $candidate['interaction_customer_type']
        : $candidate['ticket_customer_type'];
    return [
        'action_type' => $candidate['action_type'],
        'crm' => $candidate['clog_crm_id'] ?: $candidate['interaction_crm_id'],
        'email' => $candidate['ticket_email'] ?: $candidate['interaction_email'],
        'name' => $candidate['ticket_name'] ?: $candidate['interaction_name'],
        'customer_type' => $customerType,
        'id_number' => $candidate['id_number'] ?: $candidate['interaction_nric'],
        'language' => $language,
        'state' => $candidate['ticket_state'] ?: $candidate['interaction_state'],
        'contact_number' => $candidate['ticket_phone'] ?: $candidate['interaction_phone'],
        'filename' => implode(', ', array_map('strval', $candidate['filenames'])),
        'ocr_status' => $candidate['execution_result']['ocr_status'] ?? null,
    ];
}

function rpa_action_finish(PDO $pdo, array $candidate, string $state, string $errorCode, array $response): void
{
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare("
            UPDATE portal_rpa_action_queue
            SET state = ?, error_code = NULLIF(?, ''), response = ?::jsonb, updated_at = NOW()
            WHERE id = ? AND state = 'dispatching'
        ");
        $update->execute([
            $state,
            $errorCode,
            json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            $candidate['action_id'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('RPA action state changed before the response was recorded.');
        }
        $event = $pdo->prepare("
            INSERT INTO portal_ticket_events (ticket_id, actor_type, event_type, metadata)
            VALUES (?, 'integration', 'ticket.rpa_action_submitted', ?::jsonb)
        ");
        $event->execute([
            $candidate['ticket_id'],
            json_encode([
                'action_id' => $candidate['action_id'],
                'action_type' => $candidate['action_type'],
                'state' => $state,
                'schedule_id' => $response['schedule_id'] ?? null,
            ], JSON_THROW_ON_ERROR),
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

$processed = 0;
$inspected = [];
try {
    while ($processed < $batchSize) {
        // Dry-run never claims an action, so exclude ones already inspected in this run.
        $candidate = rpa_action_candidate($pdo, $command === 'run', $command === 'dry-run' ? $inspected : []);
        if ($candidate === null) {
            echo "No due queued RPA actions.\n";
            break;
        }
        if ($command === 'dry-run') {
            $inspected[] = (string)$candidate['action_id'];
        }
        $reason = rpa_action_reason($candidate);
        if ($reason !== null) {
            if ($command === 'run') {
                $hold = $pdo->prepare("
                    UPDATE portal_rpa_action_queue
                    SET state = 'manual_review', error_code = ?, updated_at = NOW()
                    WHERE id = ? AND state = 'dispatching'
                ");
                $hold->execute([$reason, $candidate['action_id']]);
            }
            echo $command . ': action held for review (' . $reason . ").\n";
            $processed++;
            continue;
        }

        try {
            $payload = (new RpaClient())->workflowPayload(rpa_action_input($candidate));
        } catch (Throwable $error) {
            if ($command === 'run') {
                $hold = $pdo->prepare("
                    UPDATE portal_rpa_action_queue
                    SET state = 'manual_review', error_code = 'RPA_INPUT_INVALID', updated_at = NOW()
                    WHERE id = ? AND state = 'dispatching'
                ");
                $hold->execute([$candidate['action_id']]);
            }
            echo $command . ": action held for review (RPA_INPUT_INVALID).\n";
            $processed++;
            continue;
        }

        if ($command === 'dry-run') {
            echo "dry-run: action is eligible; payload not displayed and no RPA request sent.\n";
            $processed++;
            break;
        }

        $response = (new RpaClient())->send($payload);
        $parsed = $response['parsed'];
        $status = (int)($response['http_status'] ?? 0);
        $acknowledged = is_array($parsed) && strtolower((string)($parsed['status'] ?? '')) === 'inserted';
        $scheduleId = is_array($parsed) && is_scalar($parsed['schedule_id'] ?? null)
            ? (string)$parsed['schedule_id']
            : null;
        $state = $acknowledged ? 'succeeded' : (($status >= 400 && $status < 500) ? 'failed' : 'uncertain');
        $errorCode = $acknowledged ? '' : ($state === 'failed' ? 'RPA_REJECTED_REQUEST' : 'RPA_OUTCOME_UNCONFIRMED');
        rpa_action_finish($pdo, $candidate, $state, $errorCode, [
            'http_status' => $response['http_status'],
            'schedule_id' => $scheduleId,
            'acknowledged' => $acknowledged,
        ]);
        echo "RPA action " . $state . "; schedule reference " . ($scheduleId ?? 'unavailable') . ".\n";
        $processed++;
    }
} catch (Throwable $error) {
    fwrite(STDERR, "RPA action worker failed (" . get_class($error) . "). Check queue state and configuration.\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
