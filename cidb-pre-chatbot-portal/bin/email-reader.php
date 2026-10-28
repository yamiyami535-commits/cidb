<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/Email/autoload.php';

use Cidb\Email\{Config,PgStore,Processor,WebklexMailbox,MessageSelector,FieldExtractor};

$command=$argv[1] ?? 'check';
if (!in_array($command,['check','activate','run'],true)) { fwrite(STDERR,"Usage: php bin/email-reader.php check|activate|run\n"); exit(2); }
$config=Config::environment();
if ($command==='run' && !$config->yes('EMAIL_ENABLED')) { echo "Email processing disabled; no connections opened.\n"; exit(0); }
$problems=$config->problems();
if ($command==='check' || $command==='run') $problems=array_merge($problems,$config->clogProblems());
foreach (['openssl','mbstring','iconv','libxml','dom','zip','fileinfo','curl','pdo_pgsql'] as $extension) {
    if (!extension_loaded($extension)) $problems[]="PHP extension $extension is required";
}
foreach (['autoload.php','webklex/php-imap/src/ClientManager.php'] as $dependency) {
    if (!is_file(dirname(__DIR__).'/vendor/'.$dependency)) {
        $problems[]='Email Composer dependencies are not installed';
        break;
    }
}
if ($command==='check' || $problems) {
    foreach ($problems as $problem) echo '- '.$problem."\n";
    echo $problems ? "Not ready. No connections opened.\n" : "Local configuration checks passed. No connections opened; live contracts still require verification.\n";
    exit($problems?2:0);
}
if (!$config->yes('EMAIL_ENABLED')) { fwrite(STDERR,"EMAIL_ENABLED must explicitly be true before activation.\n"); exit(2); }
require dirname(__DIR__).'/vendor/autoload.php';
try {
    $clogProvider=null;
    if ($command==='run') {
        $clogDb=new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$config->get('CLOG_DB_HOST'),$config->get('CLOG_DB_DATABASE')),
            $config->get('CLOG_DB_USERNAME'),
            $config->get('CLOG_DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
                PDO::ATTR_TIMEOUT=>max(1,$config->integer('CLOG_DB_TIMEOUT',5)),
            ]
        );
        $clogProvider=new \Cidb\Email\PdoClogAttachmentProvider($clogDb);
    }
    $processor=new Processor(
        new PgStore(
            db(),
            $config->mailboxKey(),
            $clogProvider,
            $config->get('CLOG_FILE_FETCH_BASE_URL','https://cidb.daythree.net/handler/email_fetch/files')
        ),
        new WebklexMailbox($config),
        new MessageSelector($config),
        new FieldExtractor(),
        $config
    );
    if ($command==='activate') { $processor->activate(); echo "Mailbox baseline recorded; no messages dispatched.\n"; }
    else echo json_encode($processor->run(),JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    // External exceptions may include credentials/message contents; never print them.
    fwrite(STDERR,"Email task failed (".get_class($e)."). Check configuration, migration, and mailbox health records.\n");
    exit(1);
}
