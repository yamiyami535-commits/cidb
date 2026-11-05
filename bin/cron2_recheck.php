<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI access only.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Email/autoload.php';
require dirname(__DIR__) . '/src/Ticketing/ClogAttachmentReference.php';
require dirname(__DIR__) . '/src/Ticketing/ClogAttachmentDocumentStore.php';
require dirname(__DIR__) . '/src/Ticketing/Cron2AttachmentRechecker.php';

function cron2_recheck_options(array $arguments): array
{
    $crmId = null;
    $backlog = false;
    $command = $arguments[0] ?? 'check';
    if (!in_array($command, ['check', 'run'], true)) {
        throw new InvalidArgumentException('Usage: php bin/cron2_recheck.php check|run [--crm-id=CRM-...]');
    }
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--backlog' && !$backlog) {
            $backlog = true;
            continue;
        }
        if (!str_starts_with($argument, '--crm-id=') || $crmId !== null) {
            throw new InvalidArgumentException('Usage: php bin/cron2_recheck.php check|run [--crm-id=CRM-...]');
        }
        $crmId = substr($argument, strlen('--crm-id='));
    }
    if ($crmId !== null && preg_match('/^CRM-[A-Za-z0-9-]{1,60}$/', $crmId) !== 1) {
        throw new InvalidArgumentException('Provide one valid --crm-id=CRM-... scope.');
    }
    return [$command, $crmId, $backlog];
}

try {
    [$command, $crmId, $backlog] = cron2_recheck_options(array_slice($argv, 1));
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}

foreach (['pdo_pgsql', 'pdo_mysql'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "PHP extension {$extension} is required for Cron2 attachment recheck.\n");
        exit(2);
    }
}
foreach (['CLOG_DB_HOST', 'CLOG_DB_DATABASE', 'CLOG_DB_USERNAME'] as $key) {
    if (env_value($key) === '') {
        fwrite(STDERR, "{$key} is required for Cron2 attachment recheck.\n");
        exit(2);
    }
}
if ($command === 'check') {
    echo "Cron2 attachment and OCR configuration passed. No database or CLOG connection opened.\n";
    exit(0);
}

try {
    $clogDatabase = env_value('CLOG_DB_DATABASE');
    $clogOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => max(1, (int)env_value('CLOG_DB_TIMEOUT', '5')),
    ];
    try {
        $clogDb = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env_value('CLOG_DB_HOST'), $clogDatabase),
            env_value('CLOG_DB_USERNAME'),
            env_value('CLOG_DB_PASSWORD'),
            $clogOptions
        );
    } catch (PDOException $connectionError) {
        if (!str_contains($connectionError->getMessage(), '1049')) {
            throw $connectionError;
        }
        $alternateDatabase = str_contains($clogDatabase, '_')
            ? str_replace('_', '-', $clogDatabase)
            : str_replace('-', '_', $clogDatabase);
        $clogDb = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env_value('CLOG_DB_HOST'), $alternateDatabase),
            env_value('CLOG_DB_USERNAME'),
            env_value('CLOG_DB_PASSWORD'),
            $clogOptions
        );
    }
    $portalDb = db();
    $provider = new \Cidb\Email\PdoClogAttachmentProvider($clogDb);
    $baseUrl = env_value('CLOG_FILE_FETCH_BASE_URL', 'https://cidb.daythree.net/handler/email_fetch/files');
    $documentStore = new \Cidb\Ticketing\ClogAttachmentDocumentStore($portalDb, $baseUrl);
    $rechecker = new \Cidb\Ticketing\Cron2AttachmentRechecker($portalDb, $provider, $documentStore);
    $timezone = new DateTimeZone(env_value('CLOG_TIMEZONE', 'Asia/Kuala_Lumpur'));
    $windowStart = (new DateTimeImmutable('today', $timezone))->format('Y-m-d H:i:s');
    $windowEnd = (new DateTimeImmutable('today', $timezone))->modify('+1 day')->format('Y-m-d H:i:s');
    if ($crmId === null) {
        $candidates = $portalDb->query("\n            SELECT DISTINCT t.clog_crm_id\n            FROM portal_tickets t\n            JOIN cidb_interaction_data i ON i.id = t.interaction_id\n            WHERE t.source_type = 'clog'\n              AND t.customer_type = 'individual'\n              AND t.workflow_status = 'needs_information'\n              AND LOWER(TRIM(COALESCE(t.clog_status, i.clog_status, ''))) = 'open'\n              AND i.current_status IN (\n                  'Pending from Customer', 'Pending Customer Attachment', 'Pending Information',\n                  'Customer Replied - Verified', 'Customer Replied - Document Reject'\n              )\n            ORDER BY t.clog_crm_id\n        ")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $candidates = [$crmId];
    }

    $summary = ['tickets_checked' => 0, 'new_files' => 0, 'ready_for_ocr' => 0];
    foreach ($candidates as $candidateCrmId) {
        $part = $rechecker->run((string)$candidateCrmId, 25, $backlog ? null : $windowStart, $backlog ? null : $windowEnd);
        foreach ($summary as $key => $_) {
            $summary[$key] += $part[$key];
        }
    }
    echo 'Attachment recheck: ' . json_encode($summary, JSON_THROW_ON_ERROR) . "\n";

    // Rechecking only makes attachments eligible. Run OCR in the same Cron2
    // invocation so a newly received reply does not stop at ready_for_ocr.
    $ocrRunner = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ocr_runner.php';
    $commandLine = escapeshellarg(PHP_BINARY)
        . ' -d extension_dir=' . escapeshellarg((string)ini_get('extension_dir'))
        . ' ' . escapeshellarg($ocrRunner);
    if ($crmId !== null) {
        $commandLine .= ' ' . escapeshellarg('--crm-id=' . $crmId);
    }
    if ($backlog) {
        $commandLine .= ' ' . escapeshellarg('--backlog');
    }
    passthru($commandLine, $ocrExitCode);
    if ($ocrExitCode !== 0) {
        exit($ocrExitCode);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Cron2 attachment recheck failed (' . get_class($error) . "). Check database/configuration and ticket history.\n");
    exit(1);
}
