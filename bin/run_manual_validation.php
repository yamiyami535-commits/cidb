<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI access only.\n");
}

/**
 * Scoped operator run using the same strict today-only Cron1/Cron2 flow.
 * Use --backlog only for an explicitly authorized historical reprocess.
 */
$backlog = false;
$crmIds = [];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--backlog' && !$backlog) {
        $backlog = true;
        continue;
    }
    if (preg_match('/^CRM-[A-Za-z0-9-]{1,60}$/', $argument) !== 1) {
        fwrite(STDERR, 'Usage: php bin/run_manual_validation.php [--backlog] CRM-... [CRM-...]' . "\n");
        exit(2);
    }
    $crmIds[] = $argument;
}
if ($crmIds === []) {
    fwrite(STDERR, 'Provide at least one CRM ID.' . "\n");
    exit(2);
}

$runner = __DIR__ . DIRECTORY_SEPARATOR . 'cron_runner.php';
foreach (array_values(array_unique($crmIds)) as $crmId) {
    foreach ([false, true] as $cron2) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner)
            . ' --crm-id=' . escapeshellarg($crmId);
        if ($cron2) {
            $command .= ' --cron2';
        }
        if ($backlog) {
            $command .= ' --backlog';
        }
        passthru($command, $exitCode);
        if ($exitCode !== 0) {
            exit($exitCode);
        }
    }
}
