<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI access only.\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

function ocr_runner_crm_id_argument(): ?string
{
    $crmId = null;
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
        if (!str_starts_with($argument, '--crm-id=')) {
            fwrite(STDERR, "Unknown argument. Use --crm-id=CRM-... to limit this run to one CRM ID.\n");
            exit(2);
        }
        $value = substr($argument, strlen('--crm-id='));
        if (preg_match('/^CRM-[A-Za-z0-9-]{1,60}$/', $value) !== 1 || $crmId !== null) {
            fwrite(STDERR, "Provide one valid --crm-id=CRM-... argument.\n");
            exit(2);
        }
        $crmId = $value;
    }
    return $crmId;
}

function ocr_runner_service_url(): string
{
    $base = rtrim(env_value('OCR_SERVICE_URL', 'http://127.0.0.1:8000'), '/');
    $parts = parse_url($base);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('OCR_SERVICE_URL must be an HTTP(S) URL without credentials, query, or fragment.');
    }

    $isLoopback = in_array(strtolower((string)$parts['host']), ['127.0.0.1', 'localhost', '::1'], true);
    if (strtolower((string)$parts['scheme']) !== 'https' && !$isLoopback) {
        throw new RuntimeException('Non-loopback OCR services must use HTTPS.');
    }
    if (!$isLoopback && env_value('OCR_API_KEY') === '') {
        throw new RuntimeException('OCR_API_KEY is required for a remote OCR service.');
    }

    return $base;
}

function ocr_runner_fetch_document(string $url, int $maxBytes): string
{
    $baseUrl = env_value('CLOG_FILE_FETCH_BASE_URL', 'https://cidb.daythree.net/handler/email_fetch/files');
    $base = parse_url($baseUrl);
    $target = parse_url($url);
    if (!is_array($base) || !is_array($target)
        || strtolower((string)($base['scheme'] ?? '')) !== 'https'
        || strtolower((string)($target['scheme'] ?? '')) !== 'https'
        || strtolower((string)($base['host'] ?? '')) !== strtolower((string)($target['host'] ?? ''))
        || !str_starts_with((string)($target['path'] ?? ''), rtrim((string)($base['path'] ?? ''), '/') . '/')) {
        throw new RuntimeException('Attachment URL does not match the configured CLOG file endpoint.');
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to create the CLOG attachment request.');
    }

    $body = '';
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT_MS => max(1000, (int)env_value('OCR_CONNECT_TIMEOUT_MS', '5000')),
        CURLOPT_TIMEOUT_MS => max(1000, (int)env_value('CLOG_FILE_TIMEOUT_MS', '30000')),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/pdf,image/jpeg,image/png,image/webp'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $errorNumber = curl_errno($curl);
    curl_close($curl);

    if ($ok === false || $errorNumber !== 0) {
        throw new RuntimeException('CLOG attachment download failed (cURL ' . $errorNumber . ').');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('CLOG attachment endpoint returned HTTP ' . $status . '.');
    }
    if ($body === '') {
        throw new RuntimeException('CLOG attachment endpoint returned an empty file.');
    }
    return $body;
}

function ocr_runner_extension(string $filename): string
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function ocr_runner_upload_type(string $extension): string
{
    return match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        default => throw new RuntimeException('Unsupported attachment file type.'),
    };
}

function ocr_runner_request(string $endpoint, array $fields): array
{
    $curl = curl_init($endpoint);
    if ($curl === false) {
        throw new RuntimeException('Unable to create the OCR request.');
    }

    $headers = ['Accept: application/json'];
    $apiKey = env_value('OCR_API_KEY');
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT_MS => max(1000, (int)env_value('OCR_CONNECT_TIMEOUT_MS', '5000')),
        CURLOPT_TIMEOUT_MS => max(1000, (int)env_value('OCR_TIMEOUT_MS', '180000')),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $errorNumber = curl_errno($curl);
    curl_close($curl);

    if ($body === false || $errorNumber !== 0) {
        throw new RuntimeException('OCR request failed (cURL ' . $errorNumber . ').');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('OCR service returned HTTP ' . $status . '.');
    }
    $response = json_decode((string)$body, true);
    if (!is_array($response)
        || !isset($response['status'], $response['verified'], $response['message'])
        || !is_string($response['status'])
        || !is_bool($response['verified'])
        || !is_string($response['message'])) {
        throw new RuntimeException('OCR service returned an invalid response.');
    }
    return $response;
}

function ocr_runner_safe_result(array $response): array
{
    $safe = [
        'status' => $response['status'],
        'identity_check_verified' => $response['verified'],
        'full_submission_checks' => 'manual_review_required',
        'message' => $response['message'],
    ];
    foreach (['document_type', 'extracted_document_number_masked', 'ocr_average_confidence'] as $key) {
        if (isset($response[$key]) && is_scalar($response[$key])) {
            $safe[$key] = $response[$key];
        }
    }
    if (isset($response['images_quality']) && is_array($response['images_quality'])) {
        $safe['images_quality'] = [];
        foreach ($response['images_quality'] as $label => $quality) {
            if (!is_string($label) || !is_array($quality)) {
                continue;
            }
            $safe['images_quality'][$label] = array_intersect_key(
                $quality,
                array_flip(['passed', 'width', 'height', 'blur_score', 'brightness'])
            );
        }
    }
    if (isset($response['comparison']) && is_array($response['comparison'])) {
        $safe['comparison'] = array_intersect_key(
            $response['comparison'],
            array_flip([
                'entered_document_number_valid',
                'document_number_match',
                'name_match',
                'name_similarity',
            ])
        );
    }
    return $safe;
}

function ocr_runner_document_status(string $status): string
{
    return match ($status) {
        'INVALID_DOCUMENT', 'DETAILS_MISMATCH' => 'invalid',
        'REUPLOAD_REQUIRED', 'OCR_FAILED' => 'unreadable',
        'INTERNAL_ERROR' => 'error',
        default => 'manual_review',
    };
}

$crmId = ocr_runner_crm_id_argument();
if (!filter_var(env_value('OCR_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDOUT, "OCR worker is disabled. Set OCR_ENABLED=true after configuring the private OCR service.\n");
    exit(0);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "The PHP cURL extension is required for the OCR worker.\n");
    exit(1);
}

try {
    $serviceUrl = ocr_runner_service_url();
    $pdo = db();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$batchSize = filter_var(env_value('OCR_BATCH_SIZE', '5'), FILTER_VALIDATE_INT);
if ($batchSize === false || $batchSize < 1 || $batchSize > 25) {
    fwrite(STDERR, "OCR_BATCH_SIZE must be an integer from 1 through 25.\n");
    exit(1);
}
$maxDownloadBytes = max(1, (int)env_value('OCR_MAX_PDF_SIZE_MB', '16')) * 1024 * 1024;

$lockPath = __DIR__ . '/logs/ocr_runner.lock';
if (!is_dir(dirname($lockPath)) && !mkdir(dirname($lockPath), 0755, true) && !is_dir(dirname($lockPath))) {
    fwrite(STDERR, "Unable to create the OCR worker log directory.\n");
    exit(1);
}
$lock = fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_resource($lock)) {
        fclose($lock);
    }
    fwrite(STDOUT, "OCR worker already running; skipping this run.\n");
    exit(0);
}

$exitCode = 0;
try {
    $candidateQuery = $pdo->prepare("
        SELECT t.id, t.applicant_name, t.id_number, t.clog_crm_id, t.interaction_id,
               i.applicant_name AS interaction_name, i.nric AS interaction_nric
        FROM portal_tickets t
        LEFT JOIN cidb_interaction_data i ON i.id = t.interaction_id
        WHERE t.assigned_to IS NOT NULL
          AND t.workflow_status = 'ready_for_ocr'
          AND LOWER(TRIM(COALESCE(t.clog_status, i.clog_status, ''))) = 'open'
          AND EXISTS (
              SELECT 1 FROM portal_ticket_documents d
              WHERE d.ticket_id = t.id
                AND d.reference_status = 'ready'
                AND d.ocr_status = 'pending'
          )
          " . ($crmId !== null ? "AND t.clog_crm_id = :crm_id" : "") . "
        ORDER BY t.updated_at, t.id
        LIMIT :limit
    ");
    if ($crmId !== null) {
        $candidateQuery->bindValue(':crm_id', $crmId, PDO::PARAM_STR);
    }
    $candidateQuery->bindValue(':limit', $batchSize, PDO::PARAM_INT);
    $candidateQuery->execute();

    $enqueue = $pdo->prepare("
        INSERT INTO portal_ticket_ocr_jobs
            (document_id, dedupe_key, state, request_payload)
        VALUES (?, ?, ?, ?::jsonb)
        ON CONFLICT (dedupe_key) DO NOTHING
    ");
    $documentQuery = $pdo->prepare("
        SELECT id, original_filename, retrieval_url, reference_status, ocr_status
        FROM portal_ticket_documents
        WHERE ticket_id = ? AND reference_status = 'ready' AND ocr_status = 'pending'
        ORDER BY created_at, id
    ");
    $ticketReview = $pdo->prepare("
        UPDATE portal_tickets SET workflow_status = 'ocr_manual_review', updated_at = NOW()
        WHERE id = ? AND workflow_status = 'ready_for_ocr'
    ");

    $enqueued = 0;
    $skippedIdentity = 0;
    foreach ($candidateQuery->fetchAll() as $candidate) {
        $name = trim((string)($candidate['applicant_name'] ?: $candidate['interaction_name'] ?: ''));
        $identityNumber = trim((string)($candidate['id_number'] ?: $candidate['interaction_nric'] ?: ''));

        $documentQuery->execute([$candidate['id']]);
        $documents = $documentQuery->fetchAll();
        if ($documents === []) {
            continue;
        }

        $inputType = 'unsupported';
        $state = 'manual_review';
        if (count($documents) === 1 && ocr_runner_extension((string)$documents[0]['original_filename']) === 'pdf') {
            $inputType = $identityNumber === '' ? 'cancellation_form_pdf' : 'mykad_pdf';
            $state = 'queued';
        } elseif (count($documents) === 2) {
            $frontIndex = null;
            $backIndex = null;
            $hasUnsupportedFile = false;
            foreach ($documents as $index => $document) {
                $extension = ocr_runner_extension((string)$document['original_filename']);
                $filename = strtolower((string)$document['original_filename']);
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $hasUnsupportedFile = true;
                    break;
                }
                if (preg_match('/front|depan/', $filename) === 1) {
                    $frontIndex = $index;
                } elseif (preg_match('/back|belakang/', $filename) === 1) {
                    $backIndex = $index;
                }
            }
            if (!$hasUnsupportedFile && $frontIndex !== null && $backIndex !== null) {
                $documents = [$documents[$frontIndex], $documents[$backIndex]];
                $inputType = 'mykad_image_pair';
                $state = 'queued';
            }
        }
        if ($inputType !== 'cancellation_form_pdf' && ($name === '' || $identityNumber === '')) {
            $skippedIdentity++;
            fwrite(STDERR, 'Ticket ' . $candidate['id'] . " is missing a mapped name or NRIC; identity OCR not started.\n");
            continue;
        }

        $documentIds = array_map(static fn(array $document): string => (string)$document['id'], $documents);
        $documentIdsJson = json_encode($documentIds, JSON_THROW_ON_ERROR);
        $dedupeKey = 'ticket:' . $candidate['id'] . ':ocr:' . hash('sha256', implode(':', $documentIds));
        $enqueue->execute([
            $documents[0]['id'],
            $dedupeKey,
            $state,
            json_encode(['input_type' => $inputType, 'document_ids' => $documentIds], JSON_THROW_ON_ERROR),
        ]);
        $inserted = $enqueue->rowCount() === 1;
        if ($inserted) {
            $enqueued++;
        }
        if ($state === 'manual_review') {
            $documentPlaceholders = implode(',', array_fill(0, count($documentIds), '?'));
            $manualReview = $pdo->prepare("
                UPDATE portal_ticket_documents
                SET ocr_status = 'manual_review', updated_at = NOW()
                WHERE id IN ({$documentPlaceholders})
            ");
            $manualReview->execute($documentIds);
            $ticketReview->execute([$candidate['id']]);
            fwrite(STDOUT, 'Ticket ' . $candidate['id'] . " requires manual file identification; OCR not started.\n");
        }
    }

    $processed = 0;
    while ($processed < $batchSize) {
        $pdo->beginTransaction();
        $jobQuery = $pdo->prepare("
            SELECT j.id, d.ticket_id, j.document_id, j.request_payload,
                   t.applicant_name, t.id_number, t.clog_crm_id, t.interaction_id,
                   i.applicant_name AS interaction_name, i.nric AS interaction_nric
            FROM portal_ticket_ocr_jobs j
            JOIN portal_ticket_documents d ON d.id = j.document_id
            JOIN portal_tickets t ON t.id = d.ticket_id
            LEFT JOIN cidb_interaction_data i ON i.id = t.interaction_id
            WHERE j.state = 'queued'
              AND t.assigned_to IS NOT NULL
              AND t.workflow_status = 'ready_for_ocr'
              AND LOWER(TRIM(COALESCE(t.clog_status, i.clog_status, ''))) = 'open'
              " . ($crmId !== null ? "AND t.clog_crm_id = :crm_id" : "") . "
            ORDER BY j.created_at, j.id
            LIMIT 1
            FOR UPDATE OF j SKIP LOCKED
        ");
        if ($crmId !== null) {
            $jobQuery->bindValue(':crm_id', $crmId, PDO::PARAM_STR);
        }
        $jobQuery->execute();
        $job = $jobQuery->fetch();
        if (!$job) {
            $pdo->commit();
            break;
        }
        $pdo->prepare("
            UPDATE portal_ticket_ocr_jobs
            SET state = 'dispatching', attempts = attempts + 1, started_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ")->execute([$job['id']]);
        $pdo->prepare("
            UPDATE portal_tickets SET workflow_status = 'ocr_processing', updated_at = NOW()
            WHERE id = ? AND workflow_status = 'ready_for_ocr'
        ")->execute([$job['ticket_id']]);
        $pdo->commit();

        $temporaryFiles = [];
        try {
            $name = trim((string)($job['applicant_name'] ?: $job['interaction_name'] ?: ''));
            $identityNumber = trim((string)($job['id_number'] ?: $job['interaction_nric'] ?: ''));
            $payload = json_decode((string)$job['request_payload'], true, 512, JSON_THROW_ON_ERROR);
            $documentIds = $payload['document_ids'] ?? [];
            $inputType = $payload['input_type'] ?? null;
            if (!is_array($documentIds)
                || !in_array($inputType, ['mykad_pdf', 'mykad_image_pair', 'cancellation_form_pdf'], true)
                || count($documentIds) !== (in_array($inputType, ['mykad_pdf', 'cancellation_form_pdf'], true) ? 1 : 2)) {
                throw new RuntimeException('OCR job has no attachment references.');
            }
            if ($inputType !== 'cancellation_form_pdf' && ($name === '' || $identityNumber === '')) {
                throw new RuntimeException('Mapped customer name or NRIC is missing.');
            }
            foreach ($documentIds as $documentId) {
                if (!is_string($documentId)
                    || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $documentId) !== 1) {
                    throw new RuntimeException('OCR job contains an invalid attachment reference.');
                }
            }

            $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
            $filesQuery = $pdo->prepare("
                SELECT id, original_filename, retrieval_url
                FROM portal_ticket_documents
                WHERE ticket_id = ? AND id IN ({$placeholders}) AND reference_status = 'ready'
                ORDER BY created_at, id
            ");
            $filesQuery->execute(array_merge([$job['ticket_id']], $documentIds));
            $files = $filesQuery->fetchAll();
            if (count($files) !== count($documentIds)) {
                throw new RuntimeException('One or more OCR attachment references are no longer available.');
            }

            $maxBytes = in_array($inputType, ['mykad_pdf', 'cancellation_form_pdf'], true)
                ? $maxDownloadBytes
                : max(1, (int)env_value('OCR_MAX_FILE_SIZE_MB', '8')) * 1024 * 1024;
            $fields = $inputType === 'cancellation_form_pdf'
                ? []
                : ['document_type' => 'mykad', 'entered_name' => $name, 'entered_nric' => $identityNumber];

            foreach ($files as $index => $file) {
                $extension = ocr_runner_extension((string)$file['original_filename']);
                $contentType = ocr_runner_upload_type($extension);
                $bytes = ocr_runner_fetch_document((string)$file['retrieval_url'], $maxBytes);
                $temporaryPath = tempnam(sys_get_temp_dir(), 'cidb-ocr-');
                if ($temporaryPath === false) {
                    throw new RuntimeException('Unable to create a temporary OCR attachment file.');
                }
                $temporaryFiles[] = $temporaryPath;
                if (file_put_contents($temporaryPath, $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new RuntimeException('Unable to write the temporary OCR attachment file.');
                }

                if (in_array($inputType, ['mykad_pdf', 'cancellation_form_pdf'], true)) {
                    if (!str_starts_with($bytes, '%PDF-')) {
                        throw new RuntimeException('The downloaded attachment is not a PDF.');
                    }
                    $fields['document_pdf'] = new CURLFile($temporaryPath, $contentType, basename((string)$file['original_filename']));
                } else {
                    if (count($files) !== 2 || !str_starts_with($contentType, 'image/')) {
                        throw new RuntimeException('The MyKad image pair is incomplete or unsupported.');
                    }
                    $side = preg_match('/front|depan/', strtolower((string)$file['original_filename'])) === 1
                        ? 'mykad_front'
                        : 'mykad_back';
                    $fields[$side] = new CURLFile($temporaryPath, $contentType, basename((string)$file['original_filename']));
                }
            }

            $endpoint = $serviceUrl . match ($inputType) {
                'cancellation_form_pdf' => '/api/verify-cancellation-form-pdf',
                'mykad_pdf' => '/api/verify-mykad-pdf',
                default => '/api/verify-document',
            };
            $response = ocr_runner_request($endpoint, $fields);
            $safeResult = ocr_runner_safe_result($response);
            $ocrStatus = ocr_runner_document_status((string)$response['status']);
            if ($response['status'] === 'VERIFIED') {
                $safeResult['status'] = 'MANUAL_REVIEW';
                $safeResult['full_submission_checks'] = 'cross_out_signature_stamp_not_automated';
                $safeResult['message'] = 'Identity OCR matched. CIDB cross-out, company stamp, and signature still require human review.';
                $ocrStatus = 'manual_review';
            }

            $pdo->beginTransaction();
            $pdo->prepare("
                UPDATE portal_ticket_ocr_jobs
                SET state = 'succeeded', response_payload = ?::jsonb, error_code = NULL,
                    completed_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ")->execute([json_encode($safeResult, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $job['id']]);
            $documentPlaceholders = implode(',', array_fill(0, count($documentIds), '?'));
            $pdo->prepare("
                UPDATE portal_ticket_documents
                SET ocr_status = ?, ocr_result = ?::jsonb, updated_at = NOW()
                WHERE id IN ({$documentPlaceholders})
            ")->execute([
                $ocrStatus,
                json_encode($safeResult, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                ...$documentIds,
            ]);
            $pdo->prepare("
                UPDATE portal_tickets SET workflow_status = 'ocr_manual_review', updated_at = NOW()
                WHERE id = ? AND workflow_status = 'ocr_processing'
            ")->execute([$job['ticket_id']]);
            $pdo->prepare("
                INSERT INTO portal_ticket_events
                    (ticket_id, actor_type, event_type, metadata)
                VALUES (?, 'integration', 'ticket.ocr_completed', ?::jsonb)
            ")->execute([
                $job['ticket_id'],
                json_encode(['ocr_job_id' => $job['id'], 'ocr_status' => $safeResult['status']], JSON_THROW_ON_ERROR),
            ]);
            $pdo->commit();
            $processed++;
            fwrite(STDOUT, 'OCR completed for ticket ' . $job['ticket_id'] . ': ' . $safeResult['status'] . ". Manual review remains required; no RPA request was sent.\n");
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->beginTransaction();
            $pdo->prepare("
                UPDATE portal_ticket_ocr_jobs
                SET state = 'failed', error_code = ?, completed_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ")->execute(['OCR_WORKER_ERROR', $job['id']]);
            $payload = json_decode((string)$job['request_payload'], true);
            $documentIds = $payload['document_ids'] ?? [$job['document_id']];
            if (!is_array($documentIds) || $documentIds === []) {
                $documentIds = [$job['document_id']];
            }
            $documentPlaceholders = implode(',', array_fill(0, count($documentIds), '?'));
            $pdo->prepare("
                UPDATE portal_ticket_documents SET ocr_status = 'error', updated_at = NOW()
                WHERE id IN ({$documentPlaceholders})
            ")->execute($documentIds);
            $pdo->prepare("
                UPDATE portal_tickets SET workflow_status = 'ocr_manual_review', updated_at = NOW()
                WHERE id = ? AND workflow_status = 'ocr_processing'
            ")->execute([$job['ticket_id']]);
            $pdo->commit();
            fwrite(STDERR, 'OCR failed for ticket ' . $job['ticket_id'] . ': ' . $error->getMessage() . "\n");
            $exitCode = 1;
        } finally {
            foreach ($temporaryFiles as $temporaryPath) {
                if (is_file($temporaryPath) && !unlink($temporaryPath)) {
                    fwrite(STDERR, "Unable to remove a temporary OCR file.\n");
                    $exitCode = 1;
                }
            }
        }
    }

    $scopeMessage = $crmId === null ? 'all eligible CRMs' : 'CRM ' . $crmId;
    fwrite(STDOUT, "OCR worker finished for {$scopeMessage}: {$enqueued} jobs prepared, {$processed} jobs processed, {$skippedIdentity} tickets skipped for missing identity fields. RPA remains disabled.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'OCR worker error: ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($exitCode);
