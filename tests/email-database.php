<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/Email/autoload.php';
require dirname(__DIR__).'/src/Ticketing/ClogAttachmentReference.php';
require dirname(__DIR__).'/src/Ticketing/ClogAttachmentDocumentStore.php';
require dirname(__DIR__).'/src/Ticketing/Cron2AttachmentRechecker.php';
require dirname(__DIR__).'/src/Ticketing/TicketManualReview.php';
require dirname(__DIR__).'/src/Ticketing/ClogCaseStatus.php';
require dirname(__DIR__).'/src/RequestHistory.php';
require dirname(__DIR__).'/src/request-result.php';
require dirname(__DIR__).'/src/RpaSubmission.php';
require dirname(__DIR__).'/src/RpaClient.php';

use Cidb\Email\{PgStore,Processor,Config,FieldExtractor,MessageSelector,Mailbox,Rpa};

function verify(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejected(PDO $pdo,string $sql): bool { try { $pdo->exec($sql); return false; } catch (PDOException) { return true; } }

class TestMailbox implements Mailbox {
    public array $messages=[]; public array $read=[]; public int $validity=100;
    public function connect(): array { return ['uid_validity'=>$this->validity,'next_uid'=>$this->messages?max(array_keys($this->messages))+1:1]; }
    public function discover(int $after,int $limit): array { $ids=array_filter(array_keys($this->messages),fn($id)=>$id>$after); sort($ids); return array_slice($ids,0,$limit); }
    public function read(int $uid): array { if ($this->messages[$uid] instanceof Throwable) throw $this->messages[$uid]; return $this->messages[$uid]; }
    public function markRead(int $uid): void { $this->read[]=$uid; }
    public function close(): void {}
}
class TestRpa implements Rpa {
    public array $sent=[];
    public function send(array $payload): array { $this->sent[]=$payload; return ['outcome'=>'accepted']; }
}
class TestClogAttachmentProvider implements \Cidb\Email\ClogAttachmentProvider {
    public function lookup(string $crmId): array {
        if ($crmId!=='001234-Ab/56') return ['email_ids'=>[],'files'=>[]];
        return [
            'email_ids'=>['clog-email-200'],
            'files'=>[[
                'email_id'=>'clog-email-200',
                'crm_id'=>$crmId,
                'filename'=>'identity-document.pdf',
            ]],
        ];
    }
}
class TestReturnedClogAttachmentProvider implements \Cidb\Email\ClogAttachmentProvider {
    public function lookup(string $crmId): array {
        return [
            'email_ids'=>['clog-return-1'],
            'files'=>[[
                'email_id'=>'clog-return-1',
                'crm_id'=>$crmId,
                'filename'=>'signed-nric.pdf',
            ]],
        ];
    }
}
$dsn=getenv('EMAIL_TEST_DSN');
if (!$dsn) { fwrite(STDERR,"Use the isolated node tests/email-database.cjs harness or set an explicit test DSN.\n"); exit(2); }
$pdo=new PDO($dsn,getenv('EMAIL_TEST_USER') ?: 'postgres',getenv('EMAIL_TEST_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$schema='email_test_'.bin2hex(random_bytes(6));
$pdo->exec('CREATE SCHEMA '.$schema);
try {
    $pdo->exec('SET search_path TO '.$schema.', public');
    $base=preg_replace('/^\\\\ir .*$/m','',file_get_contents(dirname(__DIR__).'/database/schema.sql'));
    $pdo->exec($base);
    $u1=$pdo->query("INSERT INTO portal_users(username,password_hash) VALUES ('one','unused') RETURNING id")->fetchColumn();
    $u2=$pdo->query("INSERT INTO portal_users(username,password_hash) VALUES ('two','unused') RETURNING id")->fetchColumn();
    $formSql="INSERT INTO portal_requests(user_id,submission_key,applicant_name,id_number,applicant_email,crim,rpa_request_payload) VALUES ('$u1',gen_random_uuid(),'One','ID1','one@example.test','CRM1','{}') RETURNING id";
    $form=$pdo->query($formSql)->fetchColumn();
    $legacy=null;
    if (($argv[1] ?? '')==='legacy') {
        $pdo->exec('ALTER TABLE portal_requests ALTER COLUMN crim DROP NOT NULL');
        $legacy=$pdo->query(str_replace("'CRM1'",'NULL',$formSql))->fetchColumn();
    }
    $migration=file_get_contents(dirname(__DIR__).'/database/migrations/20260925_email_reader.sql');
    $pdo->exec($migration); $pdo->exec($migration);
    $workflowMigration=file_get_contents(dirname(__DIR__).'/database/migrations/20261020_ticket_workflow.sql');
    $pdo->exec($workflowMigration); $pdo->exec($workflowMigration);
    $clogTicket=$pdo->query("INSERT INTO portal_tickets
        (source_type,source_ref,scenario_key,bucket_id,customer_type,workflow_status,attachment_refs,source_data)
        SELECT 'clog','CRM-FILE-001','cancellation-classification-pending',id,'unknown','intake_pending',
            '[\"proof 1.pdf\"]'::jsonb,'{\"email\":{\"id\":\"email-100\"}}'::jsonb
        FROM portal_ticket_buckets WHERE scenario_key='cancellation-classification-pending' AND is_default
        RETURNING id")->fetchColumn();
    $pdo->exec($workflowMigration);
    $fileReference=$pdo->query("SELECT * FROM portal_ticket_documents WHERE ticket_id='$clogTicket'")->fetch();
    verify($fileReference['source_email_id']==='email-100' && $fileReference['crm_id']==='CRM-FILE-001'
        && $fileReference['original_filename']==='proof 1.pdf','Migration creates metadata references from prior CLOG tickets');
    verify($fileReference['storage_key']===null && $fileReference['retrieval_url']===null
        && $fileReference['reference_status']==='invalid','Migration does not copy attachment bytes or assume a checked URL');
    $attachmentMigration=file_get_contents(dirname(__DIR__).'/database/migrations/20261021_ticket_attachment_lookup.sql');
    $pdo->exec('DROP INDEX uq_portal_ticket_documents_source_ref');
    $pdo->exec('CREATE UNIQUE INDEX uq_portal_ticket_documents_source_ref ON portal_ticket_documents(source_ref)');
    $pdo->exec($attachmentMigration); $pdo->exec($attachmentMigration);
    verify($pdo->query("SELECT clog_crm_id FROM portal_tickets WHERE id='$clogTicket'")->fetchColumn()==='CRM-FILE-001',
        'Existing CLOG tickets are backfilled with their CRM ID');
    verify(is_array(json_decode($pdo->query("SELECT documents FROM portal_ticket_details WHERE id='$clogTicket'")->fetchColumn(),true)),
        'Ticket detail view exposes document metadata as JSON');
    verify(\Cidb\Ticketing\ClogAttachmentReference::url(
        'https://cidb.daythree.net/handler/email_fetch/files','CRM-FILE-001','proof 1.pdf'
    )==='https://cidb.daythree.net/handler/email_fetch/files/CRM-FILE-001/proof%201.pdf','Cron URL uses CRM ID and encoded filename');
    verify(\Cidb\Ticketing\ClogAttachmentReference::url(
        'https://cidb.daythree.net/handler/email_fetch/files','001234-Ab/56','proof 1.pdf'
    )==='https://cidb.daythree.net/handler/email_fetch/files/001234-Ab%2F56/proof%201.pdf',
        'CRM ID slash is encoded into a single URL segment');
    $pdo->exec("CREATE TABLE cidb_interaction_data (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        crm_id varchar(255) NOT NULL UNIQUE,
        applicant_name varchar(200),
        nric varchar(40),
        email varchar(254),
        phone varchar(40),
        current_status varchar(50) NOT NULL DEFAULT 'Offered',
        clog_status text,
        outcome_code varchar(80),
        clog_raw_data jsonb NOT NULL DEFAULT '{}'::jsonb,
        completed_at timestamptz,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )");
    $pdo->exec("INSERT INTO cidb_interaction_data (crm_id) VALUES ('CRM-FILE-001')");
    $statusMigration=file_get_contents(dirname(__DIR__).'/database/migrations/20261022_clog_open_status.sql');
    $pdo->exec($statusMigration); $pdo->exec($statusMigration);
    verify($pdo->query("SELECT column_default FROM information_schema.columns
        WHERE table_schema=current_schema() AND table_name='cidb_interaction_data' AND column_name='current_status'")
        ->fetchColumn()==="'Open'::character varying",'New CLOG interaction status defaults to Open');
    verify($pdo->query("SELECT current_status FROM portal_ticket_interaction_status
        WHERE ticket_id='$clogTicket'")->fetchColumn()==='Offered',
        'Historical interaction statuses are exposed unchanged for review');
    $pdo->exec("INSERT INTO cidb_interaction_data (crm_id) VALUES ('CRM-NEW-OPEN')");
    verify($pdo->query("SELECT current_status FROM cidb_interaction_data WHERE crm_id='CRM-NEW-OPEN'")
        ->fetchColumn()==='Open','New interaction rows use Open rather than Offered by default');
    verify(\Cidb\Ticketing\ClogCaseStatus::isOpen('Open')
        && !\Cidb\Ticketing\ClogCaseStatus::isOpen('Closed'),'CLOG intake ticket gate only accepts Open status');
    $conversationMigration=file_get_contents(dirname(__DIR__).'/database/migrations/20261023_ticket_conversation_foundation.sql');
    $pdo->exec($conversationMigration); $pdo->exec($conversationMigration);
    $ocrMigration=file_get_contents(dirname(__DIR__).'/database/migrations/20261027_ocr_manual_review.sql');
    $pdo->exec($ocrMigration); $pdo->exec($ocrMigration);
    verify((int)$pdo->query("SELECT character_maximum_length FROM information_schema.columns
        WHERE table_schema=current_schema() AND table_name='cidb_interaction_data' AND column_name='current_status'")
        ->fetchColumn() >= strlen('Customer Replied - Document Reject'),
        'The interaction status column can store the longest agreed RPA status value');
    $pdo->exec("UPDATE portal_tickets SET workflow_status='ocr_manual_review' WHERE id='$clogTicket'");
    verify($pdo->query("SELECT workflow_status FROM portal_tickets WHERE id='$clogTicket'")->fetchColumn()==='ocr_manual_review',
        'OCR migration allows the explicit ticket manual-review state');
    $pdo->exec("UPDATE portal_tickets SET workflow_status='intake_pending' WHERE id='$clogTicket'");
    $pdo->exec("UPDATE portal_ticket_documents SET ocr_status='manual_review' WHERE ticket_id='$clogTicket'");
    verify($pdo->query("SELECT ocr_status FROM portal_ticket_documents WHERE ticket_id='$clogTicket'")->fetchColumn()==='manual_review',
        'OCR migration allows documents to be marked for manual review');
    $pdo->exec("UPDATE portal_ticket_documents SET ocr_status='pending' WHERE ticket_id='$clogTicket'");
    verify((int)$pdo->query("SELECT count(*) FROM cidb_email_threads WHERE crm_id='CRM-FILE-001'")->fetchColumn()===1,
        'Conversation migration creates one CRM thread idempotently');
    verify($pdo->query("SELECT current_status FROM cidb_interaction_data WHERE crm_id='CRM-FILE-001'")
        ->fetchColumn()==='Offered','Conversation migration preserves historical interaction status');
    if ($legacy) $pdo->exec("UPDATE portal_requests SET status='success' WHERE id='$legacy'");
    verify(rejected($pdo,str_replace("'CRM1'",'NULL',$formSql)),'New forms still require CRM');
    verify(rejected($pdo,str_replace("'$u1'",'NULL',$formSql)),'New forms still require an owner');
    verify(rejected($pdo,"UPDATE portal_requests SET request_source='email' WHERE id='$form'"),'Origin cannot be changed');
    verify((int)$pdo->query("SELECT count(*) FROM portal_ticket_scenarios")->fetchColumn()===3,'Ticket scenarios seeded idempotently');
    verify((int)$pdo->query("SELECT count(*) FROM portal_ticket_buckets WHERE is_default")->fetchColumn()===3,'Default buckets seeded per scenario');
    $pdo->exec("UPDATE portal_users SET role='admin' WHERE id='$u1'");
    $pdo->exec("UPDATE cidb_interaction_data
        SET current_status='Customer Replied - Verified', clog_status='Open',
            applicant_name='Sample Individual', nric='A1234567'
        WHERE crm_id='CRM-FILE-001'");
    $interactionId=$pdo->query("SELECT id FROM cidb_interaction_data WHERE crm_id='CRM-FILE-001'")->fetchColumn();
    $pdo->exec("UPDATE portal_tickets
        SET interaction_id='$interactionId', clog_crm_id='CRM-FILE-001', clog_status='Open',
            customer_type='individual', assigned_to='$u1', workflow_status='needs_information'
        WHERE id='$clogTicket'");
    $documentStore=new \Cidb\Ticketing\ClogAttachmentDocumentStore(
        $pdo,
        'https://cidb.daythree.net/handler/email_fetch/files'
    );
    $rechecker=new \Cidb\Ticketing\Cron2AttachmentRechecker(
        $pdo,
        new TestReturnedClogAttachmentProvider(),
        $documentStore
    );
    // Simulate Cron1 having already stored the returned file before Cron2 runs.
    $documentStore->synchronize((string)$clogTicket,'CRM-FILE-001',[[
        'email_id'=>'clog-return-1','crm_id'=>'CRM-FILE-001','filename'=>'signed-nric.pdf',
    ]]);
    $recheckSummary=$rechecker->run('CRM-FILE-001');
    verify($recheckSummary===['tickets_checked'=>1,'new_files'=>0,'ready_for_ocr'=>1]
        && $pdo->query("SELECT workflow_status FROM portal_tickets WHERE id='$clogTicket'")->fetchColumn()==='ready_for_ocr',
        'Cron2 promotes on pending documents even when Cron1 already stored them');
    $pdo->exec("UPDATE portal_ticket_documents
        SET ocr_status='manual_review', ocr_result='{\"identity_check_verified\":true}'::jsonb
        WHERE ticket_id='$clogTicket' AND original_filename='signed-nric.pdf'");
    $pdo->exec("UPDATE portal_tickets SET workflow_status='ocr_manual_review' WHERE id='$clogTicket'");
    $reviewResult=(new \Cidb\Ticketing\TicketManualReview($pdo))->approve('CRM-FILE-001','one',false);
    verify($reviewResult['workflow_status']==='ready_for_execution'
        && $reviewResult['rpa_action_state']==='manual_review'
        && $pdo->query("SELECT state FROM portal_rpa_action_queue
            WHERE id='".$reviewResult['action_id']."'")->fetchColumn()==='manual_review',
        'Manual approval records identity review and keeps cancellation staged without dispatch');
    verify(json_decode($pdo->query("SELECT metadata FROM portal_ticket_events
        WHERE ticket_id='$clogTicket' AND event_type='ticket.manual_approval_recorded'")->fetchColumn(),true)['rpa_action_state']==='manual_review',
        'Manual approval is recorded in ticket history');

    $config=new Config(['EMAIL_SELECTION_MODE'=>'rules','EMAIL_ALLOWED_SENDERS'=>'agent@example.test','EMAIL_ALLOW_REPLIES'=>'false',
        'EMAIL_USERNAME'=>'support@example.test','EMAIL_TL_ADDRESS'=>'tl@example.test']);
    $mailbox=new TestMailbox(); $rpa=new TestRpa();
    $store=new PgStore($pdo,'test-mailbox',new TestClogAttachmentProvider());
    $processor=new Processor($store,$mailbox,new MessageSelector($config),new FieldExtractor(),$config);
    $body="Name: Sample\nEmail to Cancel ID: customer@example.test\nNRIC: B001\nState: Sarawak\nCRM ID: 001234-Ab/56";
    $message=['text'=>$body,'sender'=>'agent@example.test','subject'=>'Request','message_id'=>'sample'];
    $mailbox->messages=[1=>$message]; $processor->activate();
    verify((int)$store->state()['activation_uid']===1,'Activation excludes older messages');
    try { $processor->activate(); throw new LogicException('Baseline reset'); } catch (RuntimeException $e) { verify(!($e instanceof LogicException),'No reactivation'); }
    $mailbox->messages += [
        2=>$message,
        3=>array_replace($message,['text'=>str_replace('NRIC: B001','NRIC:',$body)]),
        4=>array_replace($message,['text'=>"Hello, please assist with my enquiry."]),
        5=>array_replace($message,['text'=>$body."\nName: Duplicate"]),
        6=>new RuntimeException('Read failure'),
    ];
    $counts=$processor->run();
    verify(count($rpa->sent)===0,'Cron1 stages requests without invoking RPA');
    verify($counts['tickets_created']===3,'Extracted cancellation emails create tickets');
    verify($counts['ignored']===1 && $counts['errors']===1,'Non-cancellations and read errors are isolated');
    verify((int)$pdo->query('SELECT count(*) FROM portal_email_intake')->fetchColumn()===5,'Mailbox cursor excludes pre-activation messages');
    verify((int)$pdo->query("SELECT count(*) FROM portal_tickets WHERE source_type='email'")->fetchColumn()===4,'Accepted and unreadable cancellation emails remain visible as tickets');

    $intake=$pdo->query("SELECT * FROM portal_email_intake WHERE message_uid=2")->fetch();
    $requestId=$intake['request_id'];
    $request=$pdo->query("SELECT * FROM portal_requests WHERE id='$requestId'")->fetch();
    $ticket=$pdo->query("SELECT * FROM portal_tickets WHERE portal_request_id='$requestId'")->fetch();
    verify($request['rpa_request_payload']===null && $request['status']==='processing','Cron1 does not dispatch or finalize RPA status');
    verify($ticket['scenario_key']==='cancellation-classification-pending' && $ticket['customer_type']==='unknown','Unknown customer type is routed to classification review');
    verify($ticket['workflow_status']==='needs_information','Internal workflow status is separate from the request/RPA status');
    verify($ticket['clog_status']===null && $ticket['assigned_to']===null,'CLOG status is not fabricated and no PIC is assigned by default');
    verify($ticket['clog_crm_id']==='001234-Ab/56'
        && $ticket['attachment_lookup_status']==='found'
        && $ticket['attachment_lookup_checked_at']!==null,
        'Email ticket uses the extracted CRM ID to check CLOG email and file rows');
    verify((int)$pdo->query("SELECT count(*) FROM portal_ticket_documents WHERE ticket_id='".$ticket['id']."'")->fetchColumn()===1,
        'Matching CLOG file is linked to the email ticket without moving the CLOG ticket document');
    verify((int)$pdo->query("SELECT count(*) FROM portal_ticket_documents WHERE source_ref LIKE 'clog:%' AND original_filename='identity-document.pdf'")->fetchColumn()===3,
        'The same CLOG file reference can be linked to separate source tickets');
    $ticketDetails=$pdo->query("SELECT documents,attachment_lookup_result,email_thread,ticket_history,interaction_details
        FROM portal_ticket_details WHERE id='".$ticket['id']."'")->fetch();
    verify(count(json_decode($ticketDetails['documents'],true))===1
        && json_decode($ticketDetails['attachment_lookup_result'],true)['matched_email_ids']===['clog-email-200'],
        'Front-end ticket detail view contains the linked file and source email ID');
    $conversationDetails=json_decode($ticketDetails['email_thread'],true);
    $storedMessages=array_column($conversationDetails['messages'],null,'source_message_id');
    verify(isset($storedMessages['mailbox:test-mailbox:100:2'])
        && $storedMessages['mailbox:test-mailbox:100:2']['body_text']===$body,
        'Ticket detail view returns its conversation without duplicating message body in intake');
    verify(count(json_decode($ticketDetails['ticket_history'],true))>=2
        && json_decode($ticketDetails['interaction_details'],true)['crm_id']==='001234-Ab/56',
        'Ticket detail view includes audit history and normalized CRM interaction data');
    $crmDetails=$pdo->query("SELECT email_threads,tickets FROM portal_crm_details WHERE crm_id='001234-Ab/56'")->fetch();
    verify(count(json_decode($crmDetails['email_threads'],true))===1
        && count(json_decode($crmDetails['tickets'],true))===3,
        'CRM detail view aggregates its conversation and ticket references');
    if (getenv('EMAIL_TEST_SHOW_EXAMPLE')==='true') {
        $example=$pdo->query("SELECT id,source_type,source_ref,customer_type,workflow_status,clog_crm_id,
                attachment_lookup_status,attachment_lookup_checked_at,attachment_lookup_result,documents
            FROM portal_ticket_details WHERE id='".$ticket['id']."'")->fetch();
        echo "Synthetic ticket detail example:\n";
        echo json_encode($example,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
    $rawMessage=json_decode($intake['raw_message'],true);
    $storedMessage=$pdo->query("SELECT body_text FROM cidb_email_messages
        WHERE source_system='mailbox' AND source_message_id='mailbox:test-mailbox:100:2'")->fetchColumn();
    verify($storedMessage===$body && !isset($rawMessage['text'])
        && $rawMessage['extracted_fields']['crm']==='001234-Ab/56',
        'Email body is stored in the conversation table and intake retains extraction metadata');
    verify((int)$pdo->query("SELECT count(*) FROM portal_rpa_action_queue WHERE ticket_id='".$ticket['id']."' AND action_type='request_information' AND state='manual_review'")->fetchColumn()===1,'Missing-information RPA action is staged for contract review and not sent');
    verify($pdo->query("SELECT workflow_status FROM portal_tickets WHERE source_type='email' AND source_ref LIKE '%:6'")->fetchColumn()==='intake_pending','Unreadable message remains in the classification/review bucket');

    $history=new RequestHistory($pdo);
    verify($history->find($u2,$form)===null && $history->find($u1,$form)!==null,'Form ownership remains private');
    verify($history->find($u1,$requestId)!==null && $history->find($u2,$requestId)!==null,'Email history remains shared between authenticated users');
    verify($history->find('',$requestId)===null,'Unauthenticated history access remains denied');
    verify(count($history->list($u2,'email'))===4 && count($history->list($u2,'form'))===0,'Source filters preserve history visibility');
    $processor->run();
    verify((int)$pdo->query("SELECT count(*) FROM portal_tickets WHERE source_type='email'")->fetchColumn()===4,'Repeated cron run does not duplicate tickets');
    verify(count($rpa->sent)===0,'Repeated cron run does not dispatch external actions');

    $formResponse=['http_status'=>500,'parsed'=>['status'=>'failed'],'raw'=>'{"status":"failed"}','error'=>'HTTP 500'];
    RpaSubmission::record($pdo,$form,$formResponse,(new RpaClient())->normalize($formResponse));
    verify($history->find($u1,$form)['status']==='processing','Form transport errors do not finalize RPA status');
    $pdo->exec("UPDATE portal_requests SET status='success' WHERE id='$form'");
    RpaSubmission::record($pdo,$form,$formResponse,(new RpaClient())->normalize($formResponse));
    verify($history->find($u1,$form)['status']==='success','Form response cannot overwrite an early RPA result');
} finally {
    $pdo->exec('DROP SCHEMA '.$schema.' CASCADE');
}
