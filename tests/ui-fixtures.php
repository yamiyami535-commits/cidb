<?php
declare(strict_types=1);
// Synthetic records only. Never connect to the application database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/tickets.php';
require dirname(__DIR__).'/src/request-result.php';
set_error_handler(function ($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
$id='00000000-0000-4000-8000-000000000001';
$_SESSION=['user'=>['id'=>$id,'username'=>'Preview agent'],'csrf'=>'preview-only','submission_key'=>$id];
$message=['id'=>$id,'source_system'=>'clog','direction'=>'unknown','delivery_status'=>'unknown','subject'=>'Pembatalan pendaftaran individu','sender_name'=>'Customer Services','participant_name'=>'Aisyah Abdullah','participant_address'=>'aisyah@example.test','sent_at'=>'2026-11-18T09:30:00+08:00','body_text'=>'<html><head><style>body{color:red}</style></head><body><p>Salam sejahtera,</p><p>Sila semak permohonan <strong>pembatalan pendaftaran</strong> saya.</p><ul><li>Salinan kad pengenalan</li><li>Borang permohonan lengkap</li></ul><p>ID: 900101-14-5678</p><p>Terima kasih,<br>Aisyah Abdullah</p><script>window.emailScriptExecuted=true</script></body></html>'];
$ticket=array_fill_keys(['portal_request_id','bucket_id','assigned_to','attachment_lookup_checked_at','email_thread_id','ocr_result','execution_result','attachment_lookup_result'],null)+[
    'id'=>$id,'source_type'=>'clog','source_ref'=>'CRM-DEMO-001','clog_crm_id'=>'CRM-DEMO-001','interaction_id'=>$id,'scenario_key'=>'cancellation-individual','customer_type'=>'individual','workflow_status'=>'ready_for_ocr','clog_status'=>'Open','applicant_name'=>'Aisyah Abdullah','applicant_email'=>'aisyah@example.test','id_number'=>'900101-14-5678','contact_number'=>'03 1234 5678','state'=>'Kuala Lumpur','language'=>'ms','company_name'=>null,'company_no'=>null,'attachment_lookup_status'=>'found','created_at'=>'2026-11-17T08:00:00+08:00','updated_at'=>'2026-11-18T10:00:00+08:00','bucket_name'=>'Operations','assigned_username'=>'Preview agent','assigned_user'=>['username'=>'Preview agent','role'=>'agent'],
    'missing_fields'=>[], 'actions'=>[], 'portal_request'=>[], 'email_intake'=>[], 'ticket_history'=>[],
    'interaction_details'=>['crm_id'=>'CRM-DEMO-001','current_status'=>'Completed'],
    'email_thread'=>['id'=>$id,'crm_id'=>'CRM-DEMO-001','subject'=>$message['subject'],'first_message_at'=>'2026-11-17T08:30:00+08:00','last_message_at'=>$message['sent_at'],'messages'=>[$message]],
    'documents'=>array_map(fn($name)=>['id'=>$id,'filename'=>$name,'crm_id'=>'CRM-DEMO-001','reference_status'=>'ready','ocr_status'=>'pending','created_at'=>'2026-11-17T08:00:00+08:00','updated_at'=>'2026-11-18T10:00:00+08:00'],['Kad pengenalan.pdf','Borang pembatalan.pdf','Dokumen sokongan.pdf'])
];
$escapedMessage=array_replace($message, ['sent_at'=>'2026-11-18T10:30:00+08:00','body_text'=>'Salam Sejahtera,\r\n\r\nSaya lampirkan dokumen seperti yang diminta.\r\n\r\nTerima kasih,\r\nAisyah Abdullah']);
$ticket['email_thread']['messages'][]=$escapedMessage;
$ticket=ticket_normalize($ticket);
$customer=ticket_normalize(['crm_id'=>'CRM-DEMO-001','nric'=>'900101-14-5678','applicant_name'=>'Aisyah Abdullah','email'=>'aisyah@example.test','phone'=>'03 1234 5678','customer_type'=>'individual','state'=>'Kuala Lumpur','language'=>'ms','company_name'=>null,'company_no'=>null,'current_status'=>'Completed','clog_status'=>'Open','tickets'=>[$ticket], 'email_thread'=>['messages'=>[$message]],'email_threads'=>[['id'=>$id,'subject'=>$message['subject'],'message_count'=>2,'first_message_at'=>'2026-11-17T08:30:00+08:00','last_message_at'=>$message['sent_at']]]]);
$customer['email_threads'][0]['created_at']='2026-11-17T08:30:00+08:00';
$customer['email_thread']['messages'][]=$escapedMessage;
$jobs=[array_fill_keys(['error_code','correlation_id','external_reference','next_attempt_at','started_at','completed_at'],null)+['id'=>$id,'document_id'=>$id,'original_filename'=>'Kad pengenalan.pdf','state'=>'succeeded','attempts'=>1,'created_at'=>'2026-11-18T08:00:00+08:00','updated_at'=>'2026-11-18T08:01:00+08:00','request_payload'=>['marker'=>'hidden-request-payload'],'response_payload'=>['marker'=>'hidden-response-payload']]];
$base=['filters'=>ticket_filters([]),'counts'=>array_fill_keys(array_keys(TICKET_STATUSES),3)];
$screens=['home'=>['home',['requests'=>[],'ticketNavigation'=>$base['counts']]],'queue'=>['tickets/workspace',$base+['screen'=>'queue','heading'=>'Ticket queue','rows'=>array_fill(0,12,$ticket),'total'=>12,'pages'=>1,'agents'=>[],'buckets'=>[]]],'crm'=>['tickets/workspace',$base+['screen'=>'crm','heading'=>'CRM search','crmId'=>'CRM-DEMO-001','customer'=>$customer]]];
foreach (['details','conversation','documents'] as $tab) $screens[$tab]=['tickets/workspace',$base+['screen'=>'detail','heading'=>'Ready for OCR','ticket'=>$ticket,'tab'=>$tab,'jobs'=>$jobs]];
foreach ($screens as $name=>[$view,$data]) {
    ob_start(); render($view,$data); $html=ob_get_clean();
    if ($name!=='home' && str_contains($html,'900101-14-5678')) throw new RuntimeException('Full identity leaked into '.$name);
    if ($name==='details' && (!str_contains($html,'data-identity-url') || str_contains($html,'Linked CRM record'))) throw new RuntimeException('Detail visibility regression');
    if ($name==='conversation' && (str_contains($html,'Delivery: Unknown') || str_contains($html,'Thread UUID') || str_contains($html,'<script>window.emailScriptExecuted'))) throw new RuntimeException('Conversation display regression');
    if ($name==='documents' && (substr_count($html,'<dt>CRM ID</dt>')!==3 || str_contains($html,'Reference validation details') || str_contains($html,'Lookup checked'))) throw new RuntimeException('Document display regression');
    if ($name==='crm' && (!str_contains($html,'href="/tickets/'.$id.'">CRM-DEMO-001') || str_contains($html,'CLOG source records'))) throw new RuntimeException('CRM relationship regression');
    if ($name==='crm' && !str_contains($html,'20 Nov 2026, 08:30')) throw new RuntimeException('SLA due date is not creation time plus three days');
    if (in_array($name,['crm','details','conversation'],true) && (!str_contains($html,"Salam Sejahtera,\n\nSaya lampirkan") || str_contains($html,'\r\n'))) throw new RuntimeException('Escaped email line endings must render as paragraph spacing in '.$name);
    if ($name==='documents' && (str_contains($html,'OCR request payload') || str_contains($html,'OCR response payload') || str_contains($html,'hidden-request-payload') || str_contains($html,'hidden-response-payload'))) throw new RuntimeException('OCR payloads must be excluded from the rendered page');
    if (isset($argv[1])) file_put_contents($argv[1].'/'.$name.'.html',$html);
}
foreach (['body_text','body_html'] as $field) {
    $html=ticket_email_html([$field=>'<html><head><style>.secret{color:red}</style></head><body><p>Hello <strong>customer</strong></p><ol><li>First</li><li>Second</li></ol><a href="javascript:alert(1)" onclick="alert(1)">Bad link</a><a href="https://example.test">Good link</a><img src="https://tracker.test" onerror="alert(1)"><script>alert(1)</script><svg onload="alert(1)"></svg></body></html>']);
    foreach (['<script','<style','javascript:','onclick','onerror','tracker.test','<svg','.secret'] as $unsafe) if (str_contains($html,$unsafe)) throw new RuntimeException('Unsafe email markup: '.$unsafe);
    foreach (['<strong>customer</strong>','<ol><li>First</li>','href="https://example.test"'] as $expected) if (!str_contains($html,$expected)) throw new RuntimeException('Lost email formatting: '.$expected);
}
if (!str_contains(ticket_email_html(['body_text'=>'&lt;p&gt;Encoded email&lt;/p&gt;']),'<p>Encoded email</p>')) throw new RuntimeException('Encoded HTML not formatted');
if (ticket_latest_message([['subject'=>'unknown','sent_at'=>null],['subject'=>'old','sent_at'=>'2026-11-01'],['subject'=>'latest','sent_at'=>'2026-11-18']])['subject']!=='latest') throw new RuntimeException('Incorrect latest message');
if (ticket_latest_message([])!==null) throw new RuntimeException('Empty message handling');
if (ticket_latest_message([['subject'=>'old','sent_at'=>null],['subject'=>'new','sent_at'=>null]])['subject']!=='new') throw new RuntimeException('Unknown timestamp tie handling');
if (ticket_sla_due('2026-12-30T16:30:00Z')!=='03 Jan 2027, 00:30') throw new RuntimeException('SLA timezone/year rollover');
if (ticket_sla_due(null)!=='Not provided' || ticket_sla_due('invalid')!=='Not provided') throw new RuntimeException('Missing SLA date handling');
if (str_contains(ticket_email_html(['body_text'=>'<p>&#57;00101-14-5678</p>']),'900101')) throw new RuntimeException('Encoded identity must remain masked');
if (ticket_email_linebreaks('First\r\n\r\nSecond\nThird\rFourth')!=="First\n\nSecond\nThird\nFourth") throw new RuntimeException('Escaped line ending conversion');
if (ticket_email_linebreaks("Already\r\nformatted\ntext")!=="Already\r\nformatted\ntext") throw new RuntimeException('Real line endings must be retained');
$literal='Keep C:\notes\reports and \\"quoted text\\" and \\t intact.';
if (ticket_email_linebreaks($literal)!==$literal) throw new RuntimeException('Unrelated content and escapes must remain unchanged');
if (!str_contains(ticket_email_html(['body_text'=>'<p>First\r\n\r\nSecond</p>']),'<p>First<br><br>Second</p>')) throw new RuntimeException('HTML text node line breaks');
echo "Six synthetic views, masked IDs, email sanitization, formatting and latest-message checks passed.\n";
