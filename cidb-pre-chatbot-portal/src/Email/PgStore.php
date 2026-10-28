<?php
declare(strict_types=1);
namespace Cidb\Email;

require_once dirname(__DIR__).'/Ticketing/ClogAttachmentReference.php';
require_once dirname(__DIR__).'/Ticketing/ClogAttachmentDocumentStore.php';
require_once dirname(__DIR__).'/Ticketing/TicketConversationStore.php';

use PDO;
use Throwable;

final class PgStore implements Store {
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $key,
        private readonly ?ClogAttachmentProvider $clogAttachments = null,
        private readonly string $attachmentBaseUrl = 'https://cidb.daythree.net/handler/email_fetch/files'
    ) {}
    private function query(string $sql, array $params=[]): \PDOStatement {
        $q=$this->pdo->prepare($sql); $q->execute($params); return $q;
    }
    private function transaction(callable $work): mixed {
        $this->pdo->beginTransaction();
        try { $value=$work(); $this->pdo->commit(); return $value; }
        catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    public function lock(): bool {
        return (bool)$this->query('SELECT pg_try_advisory_lock(hashtextextended(:key,0))',['key'=>'cidb-email:'.$this->key])->fetchColumn();
    }
    public function unlock(): void { $this->query('SELECT pg_advisory_unlock(hashtextextended(:key,0))',['key'=>'cidb-email:'.$this->key]); }
    public function state(): ?array { return $this->query('SELECT * FROM portal_email_mailboxes WHERE mailbox_key=:key',['key'=>$this->key])->fetch() ?: null; }
    public function activate(int $validity, int $baseline): void {
        // Never reset the cutoff of an already activated mailbox.
        $this->query('INSERT INTO portal_email_mailboxes(mailbox_key,uid_validity,activation_uid,last_uid) VALUES (:key,:v,:b,:last)',['key'=>$this->key,'v'=>$validity,'b'=>$baseline,'last'=>$baseline]);
    }
    public function discover(array $uids): void {
        $this->transaction(function() use ($uids) {
            $state=$this->state();
            if (!$state) throw new \RuntimeException('Mailbox is not activated');
            foreach ($uids as $uid) {
                if ($uid<=(int)$state['last_uid']) continue;
                $this->query('INSERT INTO portal_email_intake(mailbox_key,uid_validity,message_uid) VALUES (:key,:v,:uid) ON CONFLICT DO NOTHING', ['key'=>$this->key,'v'=>$state['uid_validity'],'uid'=>$uid]);
            }
            if ($uids) $this->query('UPDATE portal_email_mailboxes SET last_uid=GREATEST(last_uid,:uid) WHERE mailbox_key=:key',['uid'=>max($uids),'key'=>$this->key]);
        });
    }
    public function recover(): void {
        $this->transaction(function() {
            $this->query("UPDATE portal_email_attempts a SET outcome='uncertain',error_code='INTERRUPTED_DISPATCH',finished_at=now() FROM portal_email_intake i WHERE a.intake_id=i.id AND i.mailbox_key=:key AND a.outcome='dispatching'",['key'=>$this->key]);
            $this->query("UPDATE portal_requests r SET error_code='EMAIL_SUBMISSION_UNCERTAIN',error_detail='Submission was interrupted; verify acceptance before retrying.',updated_at=now() FROM portal_email_intake i WHERE i.request_id=r.id AND i.mailbox_key=:key AND i.stage='dispatching' AND r.status NOT IN ('success','failed')",['key'=>$this->key]);
            $this->query("UPDATE portal_email_intake SET stage='submission_uncertain',updated_at=now() WHERE mailbox_key=:key AND stage='dispatching'",['key'=>$this->key]);
            $this->query("UPDATE portal_email_notifications n SET state='uncertain',last_error='INTERRUPTED_SMTP',updated_at=now() FROM portal_email_intake i WHERE n.intake_id=i.id AND i.mailbox_key=:key AND n.state='sending'",['key'=>$this->key]);
        });
    }
    public function work(int $limit): array {
        return $this->query("SELECT i.*,r.rpa_request_payload FROM portal_email_intake i LEFT JOIN portal_requests r ON r.id=i.request_id WHERE i.mailbox_key=:key AND i.stage='discovered' AND i.next_attempt_at<=now() ORDER BY i.next_attempt_at,i.message_uid LIMIT ".max(1,$limit),['key'=>$this->key])->fetchAll();
    }
    public function ignored(string $id): void { $this->query("UPDATE portal_email_intake SET stage='ignored',updated_at=now() WHERE id=:id",['id'=>$id]); }
    private function request(array $item): string {
        if (!empty($item['request_id'])) return $item['request_id'];
        $id=$this->query("INSERT INTO portal_requests (user_id,submission_key,request_source,status) VALUES (NULL,gen_random_uuid(),'email','processing') RETURNING id")->fetchColumn();
        $this->query('UPDATE portal_email_intake SET request_id=:request WHERE id=:id',['request'=>$id,'id'=>$item['id']]);
        return $id;
    }
    public function extracted(array $item, array $message, array $extraction, string $recipient): array {
        return $this->transaction(function() use ($item,$message,$extraction,$recipient) {
            $id=$this->request($item);
            $fields=$extraction['fields']; $attention=$extraction['attention'];
            $missing=array_values(array_unique(array_merge($extraction['missing'],[
                'Customer type','Contact number','State','Language',
            ])));
            foreach (['state'=>'State'] as $key=>$label) {
                if (!empty($fields[$key])) $missing=array_values(array_diff($missing,[$label]));
            }
            $stage=$attention?'extraction_attention':($missing?'missing_fields':'ready');
            $code=$attention?'EMAIL_EXTRACTION_ATTENTION':($missing?'EMAIL_MISSING_FIELDS':null);
            $this->query('UPDATE portal_requests SET applicant_name=:name,applicant_email=:email,id_number=:nric,
                customer_type=:customer_type,contact_number=:contact_number,language=:language,
                company_name=:company_name,company_no=:company_no,rpa_request_payload=NULL,error_code=:code,updated_at=now() WHERE id=:id',[
                'id'=>$id,'name'=>$this->bounded($fields['name'] ?? null,200),'email'=>$this->bounded($fields['email'] ?? null,254),'nric'=>$this->bounded($fields['nric'] ?? null,40),
                'customer_type'=>$fields['customer_type'] ?? null,'contact_number'=>$this->bounded($fields['contact_number'] ?? null,40),
                'language'=>$fields['language'] ?? null,'company_name'=>$this->bounded($fields['company_name'] ?? null,200),
                'company_no'=>$this->bounded($fields['company_no'] ?? null,80),'code'=>$code]);
            $this->query('UPDATE portal_email_intake SET message_id=:message,sender=:sender,subject=:subject,location_area=:state,
                missing_fields=CAST(:missing AS jsonb),raw_message=CAST(:raw_message AS jsonb),stage=:stage,updated_at=now() WHERE id=:id',[
                'id'=>$item['id'],'message'=>$message['message_id'] ?? '', 'sender'=>$message['sender'] ?? '', 'subject'=>$message['subject'] ?? '',
                'state'=>$fields['state'] ?? null,'missing'=>json_encode($missing,JSON_THROW_ON_ERROR),
                'raw_message'=>json_encode([
                    'sender'=>$message['sender'] ?? '',
                    'subject'=>$message['subject'] ?? '',
                    'message_id'=>$message['message_id'] ?? '',
                    'in_reply_to'=>$message['in_reply_to'] ?? '',
                    'extracted_fields'=>$fields,
                ],JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),'stage'=>$stage]);

            $bucketId=$this->query("SELECT id FROM portal_ticket_buckets
                WHERE scenario_key='cancellation-classification-pending' AND is_default=true AND is_active=true")
                ->fetchColumn();
            if (!$bucketId) throw new \RuntimeException('Missing default cancellation classification ticket bucket');
            $sourceRef=$this->key.':'.(string)$item['uid_validity'].':'.(string)$item['message_uid'];
            $crmId=$this->bounded($fields['crm'] ?? null,255);
            $interactionId=null;
            if ($crmId!==null) {
                $interactionId=$this->query("
                    INSERT INTO cidb_interaction_data
                        (crm_id,applicant_name,nric,email,phone,customer_type,state,language,company_name,company_no)
                    VALUES (:crm,:name,:nric,:email,:phone,:customer_type,:state,:language,:company_name,:company_no)
                    ON CONFLICT (crm_id) DO UPDATE SET
                        applicant_name=COALESCE(NULLIF(cidb_interaction_data.applicant_name,''),EXCLUDED.applicant_name),
                        nric=COALESCE(NULLIF(cidb_interaction_data.nric,''),EXCLUDED.nric),
                        email=COALESCE(NULLIF(cidb_interaction_data.email,''),EXCLUDED.email),
                        phone=COALESCE(NULLIF(cidb_interaction_data.phone,''),EXCLUDED.phone),
                        customer_type=COALESCE(cidb_interaction_data.customer_type,EXCLUDED.customer_type),
                        state=COALESCE(cidb_interaction_data.state,EXCLUDED.state),
                        language=COALESCE(cidb_interaction_data.language,EXCLUDED.language),
                        company_name=COALESCE(cidb_interaction_data.company_name,EXCLUDED.company_name),
                        company_no=COALESCE(cidb_interaction_data.company_no,EXCLUDED.company_no),
                        updated_at=NOW()
                    RETURNING id",[
                        'crm'=>$crmId,
                        'name'=>$this->bounded($fields['name'] ?? null,200),
                        'nric'=>$this->bounded($fields['nric'] ?? null,40),
                        'email'=>$this->bounded($fields['email'] ?? null,254),
                        'phone'=>$this->bounded($fields['contact_number'] ?? null,40),
                        'customer_type'=>$this->bounded($fields['customer_type'] ?? null,16),
                        'state'=>$this->bounded($fields['state'] ?? null,120),
                        'language'=>$this->bounded($fields['language'] ?? null,8),
                        'company_name'=>$this->bounded($fields['company_name'] ?? null,200),
                        'company_no'=>$this->bounded($fields['company_no'] ?? null,80),
                    ])->fetchColumn();
            }
            $ticket=$this->query("INSERT INTO portal_tickets
                (source_type,source_ref,portal_request_id,scenario_key,bucket_id,customer_type,workflow_status,
                 applicant_name,applicant_email,id_number,contact_number,state,language,company_name,company_no,
                 clog_crm_id,interaction_id,attachment_lookup_status,missing_fields,source_data)
                VALUES ('email',:source_ref,:request_id,'cancellation-classification-pending',:bucket_id,'unknown',
                    :workflow_status,:name,:email,:id_number,:contact_number,:state,:language,:company_name,:company_no,
                    :crm_id,:interaction_id,:attachment_status,CAST(:missing AS jsonb),CAST(:source_data AS jsonb))
                ON CONFLICT (source_type,source_ref) DO UPDATE SET
                    portal_request_id=COALESCE(portal_tickets.portal_request_id,EXCLUDED.portal_request_id),
                    interaction_id=COALESCE(portal_tickets.interaction_id,EXCLUDED.interaction_id),
                    applicant_name=COALESCE(portal_tickets.applicant_name,EXCLUDED.applicant_name),
                    applicant_email=COALESCE(portal_tickets.applicant_email,EXCLUDED.applicant_email),
                    id_number=COALESCE(portal_tickets.id_number,EXCLUDED.id_number),
                    clog_crm_id=COALESCE(portal_tickets.clog_crm_id,EXCLUDED.clog_crm_id),
                    missing_fields=EXCLUDED.missing_fields,
                    source_data=EXCLUDED.source_data,
                    updated_at=now()
                RETURNING id",[
                    'source_ref'=>$sourceRef,'request_id'=>$id,'bucket_id'=>$bucketId,
                    'workflow_status'=>$missing?'needs_information':'intake_pending',
                    'name'=>$this->bounded($fields['name'] ?? null,200),'email'=>$this->bounded($fields['email'] ?? null,254),
                    'id_number'=>$this->bounded($fields['nric'] ?? null,40),'contact_number'=>$this->bounded($fields['contact_number'] ?? null,40),
                    'state'=>$this->bounded($fields['state'] ?? null,120),'language'=>$fields['language'] ?? null,
                    'company_name'=>$this->bounded($fields['company_name'] ?? null,200),'company_no'=>$this->bounded($fields['company_no'] ?? null,80),
                    'crm_id'=>$crmId,'interaction_id'=>$interactionId,'attachment_status'=>$crmId===null?'crm_missing':'not_checked',
                    'missing'=>json_encode($missing,JSON_THROW_ON_ERROR),
                    'source_data'=>json_encode([
                        'mailbox_key'=>$this->key,
                        'uid_validity'=>(string)$item['uid_validity'],
                        'message_uid'=>(string)$item['message_uid'],
                        'extraction_attention'=>(bool)$attention,
                    ],JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                ])->fetchColumn();

            $conversation = new \Cidb\Ticketing\TicketConversationStore($this->pdo);
            $mailboxMessageKey='mailbox:'.$sourceRef;
            $threadId=$conversation->recordMailboxMessage(
                (string)$ticket,
                $crmId,
                $mailboxMessageKey,
                $this->bounded($message['in_reply_to'] ?? null,255),
                $this->bounded($message['message_id'] ?? null,255),
                $message
            );
            $this->query("UPDATE portal_tickets SET email_thread_id=:thread_id WHERE id=:id AND email_thread_id IS NULL",[
                'thread_id'=>$threadId,'id'=>$ticket,
            ]);

            if ($this->clogAttachments !== null && $crmId !== null) {
                $lookup = $this->clogAttachments->lookup($crmId);
                $documents = (new \Cidb\Ticketing\ClogAttachmentDocumentStore($this->pdo,$this->attachmentBaseUrl))
                    ->synchronize($ticket,$crmId,$lookup['files']);
                $status = $lookup['email_ids'] === []
                    ? 'no_matching_crm_email'
                    : ($documents === [] ? 'no_files' : 'found');
                $lookupResult = [
                    'status'=>$status,
                    'matched_email_ids'=>$lookup['email_ids'],
                    'file_count'=>count($documents),
                    'files'=>$documents,
                ];
                $filenames=array_values(array_unique(array_column($documents,'original_filename')));
                $this->query("UPDATE portal_tickets SET
                    clog_crm_id=:crm_id,
                    attachment_lookup_status=:status,
                    attachment_lookup_checked_at=now(),
                    attachment_lookup_result=CAST(:result AS jsonb),
                    attachment_refs=CAST(:filenames AS jsonb),
                    source_data=jsonb_set(source_data,'{clog_attachment_lookup}',CAST(:lookup_data AS jsonb),true),
                    updated_at=now()
                    WHERE id=:id",[
                        'crm_id'=>$crmId,
                        'status'=>$status,
                        'result'=>json_encode($lookupResult,JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                        'lookup_data'=>json_encode($lookupResult,JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                        'filenames'=>json_encode($filenames,JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                        'id'=>$ticket,
                    ]);
            }

            if ($missing) {
                $actionState=filter_var(
                    \env_value('RPA_WORKFLOW_ACTIONS_ENABLED','false'),
                    FILTER_VALIDATE_BOOLEAN
                ) ? 'queued' : 'manual_review';
                $dedupeKey='email:'.$sourceRef.':request-information:'.hash('sha256',json_encode($missing,JSON_THROW_ON_ERROR));
                $this->query("INSERT INTO portal_rpa_action_queue
                    (ticket_id,action_type,dedupe_key,state,payload)
                    VALUES (:ticket_id,'request_information',:dedupe_key,:state,CAST(:payload AS jsonb))
                    ON CONFLICT (dedupe_key) DO NOTHING",[
                        'ticket_id'=>$ticket,'dedupe_key'=>$dedupeKey,'state'=>$actionState,
                        'payload'=>json_encode(['missing_fields'=>$missing],JSON_THROW_ON_ERROR),
                    ]);
            }

            return array_merge($item,['request_id'=>$id,'stage'=>$stage,'rpa_request_payload'=>null,'ticket_id'=>$ticket]);
        });
    }
    private function bounded(?string $value, int $limit): ?string {
        // Oversized values are flagged by the extractor; never silently truncate identities.
        return $value!==null && mb_strlen($value)<=$limit && !str_contains($value,"\0") ? $value : null;
    }
    public function attention(array $item, string $code): void {
        $this->transaction(function() use ($item,$code) {
            $id=$this->request($item);
            $this->query("UPDATE portal_requests SET error_code=:code,updated_at=now() WHERE id=:id",['id'=>$id,'code'=>$code]);
            $this->query("UPDATE portal_email_intake SET stage='read_error',last_error=:code,updated_at=now() WHERE id=:id",['id'=>$item['id'],'code'=>$code]);
            $bucketId=$this->query("SELECT id FROM portal_ticket_buckets
                WHERE scenario_key='cancellation-classification-pending' AND is_default=true AND is_active=true")
                ->fetchColumn();
            if (!$bucketId) throw new \RuntimeException('Missing default cancellation classification ticket bucket');
            $sourceRef=$this->key.':'.(string)$item['uid_validity'].':'.(string)$item['message_uid'];
            $this->query("INSERT INTO portal_tickets
                (source_type,source_ref,portal_request_id,scenario_key,bucket_id,customer_type,workflow_status,
                 missing_fields,source_data)
                VALUES ('email',:source_ref,:request_id,'cancellation-classification-pending',:bucket_id,
                    'unknown','intake_pending','[\"manual review\"]'::jsonb,CAST(:source_data AS jsonb))
                ON CONFLICT (source_type,source_ref) DO UPDATE SET
                    source_data=EXCLUDED.source_data,
                    updated_at=now()",[
                        'source_ref'=>$sourceRef,'request_id'=>$id,'bucket_id'=>$bucketId,
                        'source_data'=>json_encode(['error_code'=>$code],JSON_THROW_ON_ERROR),
                    ]);
        });
    }
    public function beginAttempt(array $item): ?array {
        return $this->transaction(function() use ($item) {
            $row=$this->query('SELECT * FROM portal_requests WHERE id=:id FOR UPDATE',['id'=>$item['request_id']])->fetch();
            if (!$row) throw new \RuntimeException('Request not found');
            if (in_array($row['status'],['success','failed'],true)) {
                $this->query("UPDATE portal_email_intake SET stage='finished' WHERE id=:id",['id'=>$item['id']]); return null;
            }
            $count=(int)$this->query('SELECT count(*) FROM portal_email_attempts WHERE intake_id=:id',['id'=>$item['id']])->fetchColumn();
            if ($count>=2) return null;
            $attempt=$this->query('INSERT INTO portal_email_attempts(intake_id,attempt_no) VALUES (:id,:n) RETURNING *',['id'=>$item['id'],'n'=>$count+1])->fetch();
            $this->query("UPDATE portal_email_intake SET stage='dispatching',updated_at=now() WHERE id=:id",['id'=>$item['id']]);
            return $attempt;
        });
    }
    public function finishAttempt(array $item, array $attempt, array $result): void {
        $this->transaction(function() use ($item,$attempt,$result) {
            $kind=$result['outcome'];
            $retry=$kind==='safe_failure' && (int)$attempt['attempt_no']<2;
            $stage=$retry?'retry_due':match($kind) {'accepted','success','failed'=>'awaiting_result','uncertain'=>'submission_uncertain',default=>'submission_failed'};
            $code=match($stage) {'retry_due'=>'EMAIL_RETRY_PENDING','submission_uncertain'=>'EMAIL_SUBMISSION_UNCERTAIN','submission_failed'=>'EMAIL_SUBMISSION_FAILED',default=>null};
            $this->query('UPDATE portal_email_attempts SET outcome=:outcome,http_status=:http,response=CAST(:response AS jsonb),response_text=:raw,error_code=:code,finished_at=now() WHERE id=:id',[
                'id'=>$attempt['id'],'outcome'=>$kind,'http'=>$result['http_status'] ?? null,'response'=>isset($result['parsed'])?json_encode($result['parsed'],JSON_THROW_ON_ERROR):null,'raw'=>$result['raw'] ?? '', 'code'=>$result['error_code'] ?? null]);
            // Never write status/completed_at: RPA owns the final database outcome.
            $this->query("UPDATE portal_requests SET rpa_http_status=:http,rpa_response=CAST(:response AS jsonb),rpa_response_text=:raw,rpa_reference_id=COALESCE(rpa_reference_id,:reference),
                error_code=CASE WHEN status IN ('success','failed') THEN error_code ELSE :code END,updated_at=now() WHERE id=:id",[
                'id'=>$item['request_id'], 'http'=>$result['http_status'] ?? null,
                'response'=>isset($result['parsed'])?json_encode($result['parsed'],JSON_THROW_ON_ERROR):null,'raw'=>$result['raw'] ?? '', 'reference'=>$result['reference'] ?? null,
                'code'=>$code]);
            $this->query("UPDATE portal_email_intake SET stage=:stage,next_attempt_at=now()+interval '1 minute',updated_at=now() WHERE id=:id",['id'=>$item['id'],'stage'=>$stage]);
        });
    }
    public function notifications(int $limit): array {
        return $this->query("SELECT n.*,i.request_id,i.missing_fields,i.subject,r.applicant_name,r.applicant_email,r.id_number,i.location_area
            FROM portal_email_notifications n JOIN portal_email_intake i ON i.id=n.intake_id JOIN portal_requests r ON r.id=i.request_id
            WHERE i.mailbox_key=:key AND n.state='pending' AND n.next_attempt_at<=now() ORDER BY n.next_attempt_at LIMIT ".max(1,$limit),['key'=>$this->key])->fetchAll();
    }
    public function beginNotification(string $id): bool {
        return $this->query("UPDATE portal_email_notifications SET state='sending',attempts=attempts+1,updated_at=now() WHERE id=:id AND state='pending' AND attempts<2",['id'=>$id])->rowCount()===1;
    }
    public function finishNotification(array $job, array $result, int $maxAttempts): void {
        $outcome=$result['outcome'];
        $retry=$outcome==='safe_failure' && ((int)$job['attempts']+1)<$maxAttempts;
        $state=$retry?'pending':match($outcome) {'accepted'=>'accepted','uncertain'=>'uncertain',default=>'failed'};
        $this->query("UPDATE portal_email_notifications SET state=:state,last_error=:error,next_attempt_at=now()+interval '1 minute',updated_at=now() WHERE id=:id",['id'=>$job['id'],'state'=>$state,'error'=>$result['error_code'] ?? null]);
    }
    public function markedRead(string $id): void { $this->query('UPDATE portal_email_intake SET read_marked=true WHERE id=:id',['id'=>$id]); }
    public function health(?string $error): void {
        $this->query('UPDATE portal_email_mailboxes SET last_error=:error,last_checked_at=CASE WHEN :ok=1 THEN now() ELSE last_checked_at END WHERE mailbox_key=:key',['key'=>$this->key,'error'=>$error,'ok'=>$error===null?1:0]);
    }
}
