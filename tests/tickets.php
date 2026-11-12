<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/tickets.php';

set_error_handler(function(int $severity, string $message, string $file, int $line): never { throw new ErrorException($message,0,$severity,$file,$line); });
function expect_ticket(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function ticket_render_test(array $data): string { ob_start(); try { render('tickets/workspace',$data); return ob_get_contents(); } finally { ob_end_clean(); } }

$id='00000000-0000-4000-8000-123456789012';
$filters=ticket_filters([]);
expect_ticket($filters['status']==='active' && $filters['per_page']===20,'Default queue excludes closed and limits rows.');
foreach ([['status'=>'customer_responded'],['status'=>"closed' OR 1=1 --"],['source'=>'other'],['sort'=>'id; DROP TABLE portal_tickets'],['agent'=>'someone'],['bucket'=>[]],['page'=>'-1'],['page'=>'1e3'],['page'=>'999999999'],['per_page'=>'1000'],['source'=>'email','clog_open'=>'1']] as $input) {
    try { ticket_filters($input); throw new RuntimeException('Invalid filter was accepted.'); } catch (InvalidArgumentException) {}
}
expect_ticket(ticket_filters(['agent'=>'unassigned','bucket'=>$id,'status'=>'closed'])['status']==='closed','Supported filters must be accepted.');
expect_ticket(ticket_mask_id('900101-14-5678')==='**********5678','NRIC masking must retain at most four trailing characters.');
expect_ticket(ticket_mask_id('123')==='***','Short IDs must be fully masked.');
expect_ticket(ticket_private_text($id)===$id,'Numeric UUID segments must never be masked.');
expect_ticket(ticket_safe_data(['crm_id'=>'123456789012','clog_crm_id'=>'123456789012','source_email_id'=>'123456789012'])===['crm_id'=>'123456789012','clog_crm_id'=>'123456789012','source_email_id'=>'123456789012'],'Numeric CRM and source references must remain exact.');
expect_ticket(ticket_date('2026-10-21T01:42:00Z')==='21 Oct 2026, 09:42','Ticket times must use Malaysia time.');
$unsafe=['id_number'=>'900101-14-5678','nested'=>['nric'=>'900101145678','retrieval_url'=>'https://private.invalid/file','api_key'=>'secret-value','payload'=>json_encode(['passport'=>'AB1234567'])]];
$safe=ticket_safe_data($unsafe);
expect_ticket(!str_contains(json_encode($safe),'900101') && !str_contains(json_encode($safe),'secret-value') && !str_contains(json_encode($safe),'private.invalid'),'Payloads must mask IDs and withhold file references and credentials.');
expect_ticket(is_string(ticket_safe_data('{"nric":"900101145678"}')),'Messages containing JSON must remain text, not arrays.');

// Constructor authorization is checked from the database on every request, not session role/name.
class TicketRoleStatement extends PDOStatement {
    public function __construct(private mixed $role) {}
    public function execute(?array $params=null): bool { return true; }
    public function fetchColumn(int $column=0): mixed { return $this->role; }
}
class TicketRolePDO extends PDO {
    public function __construct(private mixed $role) {}
    public function prepare(string $query, array $options=[]): PDOStatement|false { return new TicketRoleStatement($this->role); }
}
foreach ([false,'customer','guest',''] as $role) {
    try { new TicketRepository(new TicketRolePDO($role),['id'=>$id,'role'=>'admin']); throw new RuntimeException('Unauthorized role accepted.'); } catch (TicketAccessDenied) {}
}
foreach (['admin','manager','agent'] as $role) new TicketRepository(new TicketRolePDO($role),['id'=>$id]);
try { new TicketRepository(new TicketRolePDO('admin'),[]); throw new RuntimeException('Anonymous access accepted.'); } catch (TicketAccessDenied) {}

$_SESSION=['user'=>['id'=>$id,'username'=>'Preview agent']];
$ticket=array_fill_keys(['portal_request_id','bucket_id','assigned_to','contact_number','state','language','company_name','company_no','attachment_lookup_checked_at','interaction_id','email_thread_id','source_ref','clog_crm_id','clog_status','id_number','applicant_email'],null)+[
    'id'=>$id,'source_type'=>'clog','scenario_key'=>'cancellation-classification-pending','customer_type'=>'individual','workflow_status'=>'needs_information','applicant_name'=>'Aisyah Abdullah',
    'created_at'=>'2026-10-21T01:42:00Z','updated_at'=>'2026-10-21T02:18:00Z','attachment_lookup_status'=>'not_checked','bucket_name'=>'Service desk',
    'missing_fields'=>['Contact number','Supporting document'],'documents'=>[],'actions'=>[],'assigned_user'=>null,'portal_request'=>null,'email_intake'=>null,'interaction_details'=>null,'email_thread'=>null,'ticket_history'=>[],'ocr_result'=>null,'execution_result'=>null,'attachment_lookup_result'=>null,
];
$ticket['id_number']='900101-14-5678';
$ticket['email_thread']=['id'=>$id,'subject'=>'Conversation example','messages'=>[
    ['id'=>$id,'subject'=>'<img src=x onerror=alert(1)>','body_html'=>'<script>alert("unsafe")</script><p>NRIC 900101-14-5678</p><img src=x onerror=alert(1)><p>&lt;b&gt;Text only&lt;/b&gt;</p>','sent_at'=>null,'direction'=>'unknown','source_system'=>'clog','delivery_status'=>'unknown']
]];
$ticket['ticket_history']=[['id'=>$id,'event_type'=>'ticket.classification_changed','new_values'=>['id_number'=>'900101-14-5678'],'metadata'=>['payload'=>'900101-14-5678']]];
$ticket=ticket_normalize($ticket);
$base=['screen'=>'detail','heading'=>'Needs information','filters'=>$filters,'counts'=>array_fill_keys(array_keys(TICKET_STATUSES),0),'ticket'=>$ticket,'activity'=>[],'history'=>[],'jobs'=>[]];
foreach (TICKET_TABS as $tab=>$label) {
    $html=ticket_render_test($base+['tab'=>$tab]);
    expect_ticket(str_contains($html,'aria-current="page"'),'Selected section must be indicated.');
    expect_ticket(!str_contains($html,'900101'),'Full identity must not appear in any tab.');
    expect_ticket(!str_contains($html,'<img src=x') && !str_contains($html,'<script>alert'),'Untrusted message HTML must never execute.');
    if ($tab==='conversation') expect_ticket(str_contains($html,'&lt;b&gt;Text only&lt;/b&gt;'),'HTML-only content must render as escaped plain text.');
}
foreach (['','UNKNOWN'] as $crmId) ticket_render_test(['screen'=>'crm','heading'=>'CRM search','filters'=>$filters,'counts'=>[],'crmId'=>$crmId,'customer'=>null]);
ticket_render_test(['screen'=>'queue','heading'=>'Ticket queue','filters'=>$filters,'counts'=>[],'rows'=>[],'total'=>0,'pages'=>1,'agents'=>[],'buckets'=>[]]);
ticket_render_test(['screen'=>'error','heading'=>'Ticket management','filters'=>$filters,'counts'=>[],'message'=>'Unavailable']);
if (in_array('--preview',$argv,true)) {
    file_put_contents(dirname(__DIR__).'/var/ticket-ui-preview.html', str_replace('="/assets/','="../public/assets/',ticket_render_test($base+['tab'=>'details'])));
    echo "Synthetic visual preview written to var/ticket-ui-preview.html.\n";
}
echo "Ticket validation, authorization, privacy, escaping and view checks passed.\n";

if (!in_array('--live',$argv,true)) exit;
// Explicit opt-in: use real read models inside a READ ONLY snapshot. No writes or integrations.
$pdo=db(); $pdo->beginTransaction(); $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
try {
    $user=$pdo->query("SELECT id,username FROM portal_users WHERE is_active=true AND role IN ('admin','manager','agent') ORDER BY id LIMIT 1")->fetch();
    expect_ticket((bool)$user,'Live smoke check requires an active staff user.'); $_SESSION=['user'=>$user];
    $repo=new TicketRepository($pdo,$user);
    $counts=$repo->counts($filters); $queue=$repo->queue($filters);
    expect_ticket(array_sum($counts)-$counts['closed']===$queue['total'],'Counts must match the queue in the same snapshot.');
    ticket_render_test($queue+$repo->options()+['screen'=>'queue','heading'=>'Ticket queue','counts'=>$counts]);
    $firstIds=array_column($queue['rows'],'id');
    if ($queue['pages']>1) {
        $second=$repo->queue(ticket_filters(['page'=>'2']));
        expect_ticket(!array_intersect($firstIds,array_column($second['rows'],'id')),'Stable pagination must not duplicate tickets.');
    }
    foreach (TICKET_STATUSES as $status=>$label) {
        $page=$repo->queue(ticket_filters(['status'=>$status]));
        expect_ticket($page['total']===$counts[$status],'Workflow counts must match status filters.');
        foreach ($page['rows'] as $row) expect_ticket($row['workflow_status']===$status,'Status filter returned another workflow.');
    }
    foreach (['clog','email','portal'] as $source) {
        foreach ($repo->queue(ticket_filters(['source'=>$source]))['rows'] as $row) expect_ticket($row['source_type']===$source,'Source filter must be exact.');
    }
    $clog=$repo->queue(ticket_filters(['clog_open'=>'1']));
    foreach($clog['rows'] as $row) expect_ticket($row['source_type']==='clog' && strtolower(trim($row['clog_status']??''))==='open','Active CLOG filter must check source status.');
    // Include examples with conversations, documents and actions when available.
    $sampleIds=$pdo->query('SELECT id FROM (SELECT id FROM portal_tickets ORDER BY updated_at DESC,id LIMIT 1) t UNION SELECT ticket_id FROM (SELECT ticket_id FROM portal_ticket_documents LIMIT 1) d UNION SELECT ticket_id FROM (SELECT ticket_id FROM portal_rpa_action_queue LIMIT 1) a UNION SELECT id FROM (SELECT id FROM portal_tickets WHERE email_thread_id IS NOT NULL LIMIT 1) m')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($sampleIds as $sampleId) {
        $detail=$repo->detail($sampleId); expect_ticket($detail!==null,'Detail lookup must return selected ticket.');
        $source=$repo->sourceHistory($detail['interaction_id']); $jobs=$repo->ocrJobs($sampleId);
        foreach(TICKET_TABS as $tab=>$label) ticket_render_test(['screen'=>'detail','heading'=>'Ticket','filters'=>$filters,'counts'=>$counts,'ticket'=>$detail,'tab'=>$tab,'jobs'=>$jobs]+$source);
        $previous=null; $unknown=false;
        foreach ($detail['email_thread']['messages']??[] as $message) {
            $time=$message['sent_at']??null;
            if (!$time) { $unknown=true; continue; }
            expect_ticket(!$unknown,'Unknown message timestamps must be last.');
            $stamp=strtotime($time); expect_ticket($previous===null || $stamp >= $previous,'Messages must be chronological.'); $previous=$stamp;
        }
    }
    $crmId=$pdo->query('SELECT crm_id FROM portal_crm_details ORDER BY crm_id LIMIT 1')->fetchColumn();
    if ($crmId!==false) {
        $customer=$repo->crm($crmId); expect_ticket($customer['crm_id']===$crmId,'CRM lookup must be exact.');
        ticket_render_test(['screen'=>'crm','heading'=>'CRM search','filters'=>$filters,'counts'=>$counts,'crmId'=>$crmId,'customer'=>$customer]);
    }
    expect_ticket($repo->crm("' OR 1=1 --")===null,'CRM lookup must use parameters.');
    expect_ticket($repo->detail('not-a-uuid')===null,'Invalid UUIDs must not reach PostgreSQL.');
    try { new TicketRepository($pdo,['id'=>'00000000-0000-0000-0000-000000000000']); throw new RuntimeException('Missing user authorized.'); } catch(TicketAccessDenied) {}
    echo 'Live read-only integration checks passed: '.$queue['total'].' active tickets; '.count($sampleIds)." representative detail records, all tabs, CRM, filtering and pagination.\n";
} finally { $pdo->rollBack(); }
