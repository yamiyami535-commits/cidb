<?php
declare(strict_types=1);
// Opt-in local HTTP verification: no writes to the connected database.
if (PHP_SAPI !== 'cli' || !in_array('--live',$argv,true)) { echo "Usage: php tests/tickets-http.php --live\n"; exit(2); }
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/RequestHistory.php';
$root=dirname(__DIR__); $runtime=$root.'/var/ticket-http-check-'.bin2hex(random_bytes(5));
mkdir($runtime,0700,true);
$user=db()->query("SELECT id,username FROM portal_users WHERE is_active=true AND role IN ('admin','manager','agent') ORDER BY id LIMIT 1")->fetch();
if (!$user) throw new RuntimeException('An active staff user is required.');
$ticketId=db()->query('SELECT id FROM portal_tickets ORDER BY updated_at DESC,id LIMIT 1')->fetchColumn();
$crmId=db()->query('SELECT crm_id FROM portal_crm_details ORDER BY crm_id LIMIT 1')->fetchColumn();
ini_set('session.save_path',$runtime); session_name('cidb_portal');
$staffSession=bin2hex(random_bytes(20)); session_id($staffSession); session_start(); $_SESSION=['user'=>$user]; session_write_close();
$deniedSession=bin2hex(random_bytes(20)); session_id($deniedSession); session_start(); $_SESSION=['user'=>['id'=>'00000000-0000-0000-0000-000000000000','username'=>'admin','role'=>'admin']]; session_write_close();
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr); if (!$socket) throw new RuntimeException('Cannot allocate local test port.');
$address=stream_socket_get_name($socket,false); fclose($socket);
$server=proc_open([PHP_BINARY,'-d','session.save_path='.$runtime,'-S',$address,'-t',$root.'/public',$root.'/public/index.php'],[0=>['pipe','r'],1=>['file',$runtime.'/server.log','a'],2=>['file',$runtime.'/server.log','a']],$pipes,$root);
if (!is_resource($server)) throw new RuntimeException('Cannot start local PHP server.');
fclose($pipes[0]);
function ticket_http(string $address,string $path,?string $session=null,string $method='GET'): array {
    $c=curl_init('http://'.$address.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15,CURLOPT_CUSTOMREQUEST=>$method]);
    if($session) curl_setopt($c,CURLOPT_COOKIE,'cidb_portal='.$session);
    $response=curl_exec($c); $status=curl_getinfo($c,CURLINFO_HTTP_CODE); $length=curl_getinfo($c,CURLINFO_HEADER_SIZE); $error=curl_error($c); curl_close($c);
    if ($response===false) throw new RuntimeException('Local HTTP request failed: '.$error);
    return ['status'=>$status,'headers'=>substr($response,0,$length),'body'=>substr($response,$length)];
}
try {
    $ready=false;
    for($i=0;$i<50;$i++) { $probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1); if($probe) { fclose($probe);$ready=true;break; } usleep(100000); }
    if(!$ready) throw new RuntimeException('Local PHP server did not start.');
    $checks=[['/tickets',null,303],['/tickets',$deniedSession,403],['/tickets',$staffSession,200],['/tickets?page=2',$staffSession,200],['/tickets?status=closed',$staffSession,200],['/tickets?status=invalid',$staffSession,400],['/tickets?source%5B%5D=clog',$staffSession,400],['/tickets?sort=invalid',$staffSession,400],['/tickets/not-a-ticket',$staffSession,404],['/tickets/00000000-0000-0000-0000-000000000000',$staffSession,404],['/tickets/crm',$staffSession,200],['/tickets/crm?crm_id=NO-SUCH-CRM-HTTP-TEST',$staffSession,200],['/',$staffSession,200],['/request-history',$staffSession,200],['/assets/tickets.css',null,200],['/assets/tickets.js',null,200]];
    if($crmId) $checks[]=['/tickets/crm?crm_id='.rawurlencode($crmId),$staffSession,200];
    foreach (['customer-responded','waiting-on-customer','waiting-acknowledgement','resolved','closed'] as $legacy) $checks[]=['/tickets/'.$legacy,$staffSession,303];
    foreach (['all','form','email'] as $source) $checks[]=['/request-history?source='.$source,$staffSession,200];
    $visible=(new RequestHistory(db()))->list($user['id'],'all',1);
    if ($visible) {
        $checks[]=['/request-history/'.$visible[0]['id'],$staffSession,200];
        $checks[]=['/request-status/'.$visible[0]['id'],$staffSession,200];
        $checks[]=['/request-status/'.$visible[0]['id'],null,401];
    }
    $private=db()->prepare("SELECT id FROM portal_requests WHERE request_source='form' AND user_id<>:uid LIMIT 1"); $private->execute(['uid'=>$user['id']]); $privateId=$private->fetchColumn();
    if ($privateId) { $checks[]=['/request-history/'.$privateId,$staffSession,404]; $checks[]=['/request-status/'.$privateId,$staffSession,404]; }
    if($ticketId) foreach(['details','conversation','activity','history','audit','documents','actions'] as $tab) $checks[]=['/tickets/'.$ticketId.'?tab='.$tab,$staffSession,200];
    foreach($checks as [$path,$session,$expected]) {
        $result=ticket_http($address,$path,$session);
        if($result['status']!==$expected) throw new RuntimeException('Unexpected HTTP status '.$result['status'].' for test route; expected '.$expected.'.');
        if(str_contains($result['body'],'Fatal error') || str_contains($result['body'],'Warning:')) throw new RuntimeException('PHP warning/error in response.');
        if($path==='/tickets' && $session===null && !preg_match('#Location: /login#i',$result['headers'])) throw new RuntimeException('Anonymous users must go to login.');
        if(str_starts_with($path,'/tickets') && $session && !str_contains(strtolower($result['headers']),'cache-control: no-store, private')) throw new RuntimeException('Ticket data must not be cached.');
        if(in_array($path,['/','/request-history'],true) && str_contains($result['body'],'/assets/tickets.')) throw new RuntimeException('Existing pages must not load ticket assets.');
        if($path==='/' && (!str_contains($result['body'],'href="/tickets"') || !str_contains($result['body'],'id="ticket-sidebar"') || str_contains($result['body'],'name="contact_number"') || str_contains($result['body'],'name="location_area"'))) throw new RuntimeException('Restored home must expose ticket navigation and the original four required fields.');
        if(str_starts_with($path,'/request-history?source=') && !str_contains($result['body'],'data-request-history')) throw new RuntimeException('Original filtered history must be restored.');
        if(str_starts_with($path,'/request-status/') && $expected===200) {
            $body=json_decode($result['body'],true,512,JSON_THROW_ON_ERROR);
            if(!isset($body['status'],$body['complete'],$body['message']) || array_key_exists('rpa_display_message',$body)) throw new RuntimeException('Original status-based polling contract must be preserved.');
        }
    }
    $method=ticket_http($address,'/tickets',$staffSession,'POST');
    if($method['status']!==405 || !str_contains($method['headers'],'Allow: GET')) throw new RuntimeException('Mutation methods must be rejected.');
    echo 'Local HTTP checks passed: '.(count($checks)+1)." routes/methods, login redirect, staff authorization, uncached data and unchanged existing page assets.\n";
} finally {
    proc_terminate($server); proc_close($server);
    // Only our two generated session files contain authentication material.
    foreach ([$staffSession,$deniedSession] as $session) { $file=$runtime.'/sess_'.$session; if(is_file($file)) unlink($file); }
}
