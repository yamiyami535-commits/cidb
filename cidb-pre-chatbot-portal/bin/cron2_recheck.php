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
    $command = $arguments[0] ?? 'check';
    if (!in_array($command, ['check', 'run'], true)) {
        throw new InvalidArgumentException('Usage: php bin/cron2_recheck.php check|run --crm-id=CRM-...');
    }
    foreach (array_slice($arguments, 1) as $argument) {
        if (!str_starts_with($argument, '--crm-id=') || $crmId !== null) {
            throw new InvalidArgumentException('Usage: php bin/cron2_recheck.php check|run --crm-id=CRM-...');
        }
        $crmId = substr($argument, strlen('--crm-id='));
    }
    if ($command === 'run' && ($crmId === null || preg_match('/^CRM-[A-Za-z0-9-]{1,60}$/', $crmId) !== 1)) {
        throw new InvalidArgumentException('Cron2 recheck run requires one valid --crm-id=CRM-... scope.');
    }
    return [$command, $crmId];
}

try {
    [$command, $crmId] = cron2_recheck_options(array_slice($argv, 1));
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
    echo "Cron2 recheck configuration passed. No database or CLOG connection opened.\n";
    exit(0);
}

try {
    $clogDb = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env_value('CLOG_DB_HOST'), env_value('CLOG_DB_DATABASE')),
        env_value('CLOG_DB_USERNAME'),
        env_value('CLOG_DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => max(1, (int)env_value('CLOG_DB_TIMEOUT', '5')),
        ]
    );
    $portalDb = db();
    $provider = new \Cidb\Email\PdoClogAttachmentProvider($clogDb);
    $baseUrl = env_value('CLOG_FILE_FETCH_BASE_URL', 'https://cidb.daythree.net/handler/email_fetch/files');
    $documentStore = new \Cidb\Ticketing\ClogAttachmentDocumentStore($portalDb, $baseUrl);
    $rechecker = new \Cidb\Ticketing\Cron2AttachmentRechecker($portalDb, $provider, $documentStore);
    $summary = $rechecker->run((string)$crmId, 25);
    echo json_encode($summary, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Cron2 attachment recheck failed (' . get_class($error) . "). Check database/configuration and ticket history.\n");
    exit(1);
}
