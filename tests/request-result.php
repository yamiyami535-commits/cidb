<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/RpaClient.php';
require dirname(__DIR__).'/src/request-result.php';
require dirname(__DIR__).'/src/bootstrap.php';
function check(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function response(array $parsed,int $http=200): array {
    return ['parsed'=>$parsed,'raw'=>json_encode($parsed),'http_status'=>$http,'error'=>$http>=400?'HTTP error':null];
}
$client=new RpaClient();
$formInput=['name'=>'Test','email'=>'test@example.test','id_number'=>'TEST001','crm'=>'TEST-CRM','language'=>'en'];
$formPayload=$client->payload($formInput);
check($formPayload['fields']===['sCustomerType'=>'Individual','sEmail'=>'test@example.test','sCustomerName'=>'Test','sIdentificationNumber'=>'TEST001','sLanguage'=>'en','sChannel'=>'Email','sCRMID'=>'TEST-CRM'],'Form payload keeps all other fields without location');
check($client->payload($formInput+['location_area'=>'Selangor'])===$formPayload,'Legacy form location input is not sent to RPA');
$requestPayload=$client->workflowPayload([
    'action_type'=>'request_information','crm'=>'TEST-CRM','email'=>'test@example.test',
    'name'=>'Test','language'=>'en','filename'=>'front.png,back.png','state'=>'Sarawak',
    'contact_number'=>'0123456789',
]);
check($requestPayload['fields']===[
    'sCRMID'=>'TEST-CRM','sEmail'=>'test@example.test','sCustomerName'=>'Test','sLanguage'=>'en',
    'sChannel'=>'Email','sState'=>'Sarawak','sContactNumber'=>'0123456789',
    'sFilename'=>'front.png,back.png','sRequst'=>'Pending Customer Attachment',
],'Request-information payload includes the agreed request and customer context');
$cancelPayload=$client->workflowPayload([
    'action_type'=>'execute_cancellation','crm'=>'TEST-CRM','email'=>'test@example.test',
    'name'=>'Test','customer_type'=>'individual','id_number'=>'TEST001','language'=>'ms',
    'ocr_status'=>'MANUAL_REVIEW_APPROVED','filename'=>'signed-form.pdf',
]);
check($cancelPayload['fields']===[
    'sCRMID'=>'TEST-CRM','sEmail'=>'test@example.test','sCustomerName'=>'Test',
    'sLanguage'=>'ms','sChannel'=>'Email','sCustomerType'=>'Individual',
    'sIdentificationNumber'=>'TEST001','sFilename'=>'signed-form.pdf',
    'sOCRStatus'=>'MANUAL_REVIEW_APPROVED','sAction'=>'Email Cancellation',
],'Cancellation payload includes the identity and OCR-review context');
check(
    $client->workflowPayload([
        'action_type'=>'request_information','crm'=>'TEST-CRM','email'=>'test@example.test',
        'name'=>'Test','language'=>'en',
    ])['fields']['sRequst']==='Pending Customer Attachment',
    'Request-information does not assume an unverified customer type'
);
try {
    $client->workflowPayload([
        'action_type'=>'execute_cancellation','crm'=>'TEST-CRM','email'=>'test@example.test',
        'name'=>'Test','language'=>'en','id_number'=>'TEST001','ocr_status'=>'VERIFIED',
    ]);
    throw new RuntimeException('Cancellation must reject unverified customer type.');
} catch (InvalidArgumentException $error) {
    check(str_contains($error->getMessage(),'verified customer type'),'Cancellation requires verified customer type');
}
$ack=['status'=>'inserted','schedule_id'=>'test-schedule','runner_note'=>'Assigned runner'];
foreach ([$ack,['status'=>'success','display_message'=>'Old message'],['status'=>'failed']] as $payload) {
    $normalized=$client->normalize(response($payload));
    check(!isset($normalized['status']) && !isset($normalized['message']),'HTTP results must not assign business status or display text');
}
check($client->normalize(response($ack))['reference']==='test-schedule','Preserve submission reference');
check($client->normalize(response($ack,500))['error_code']==='RPA_REQUEST_FAILED','Record transport diagnostics separately');

$row=['id'=>'00000000-0000-4000-8000-000000000001','status'=>'processing','rpa_display_message'=>'LEGACY TEXT MUST NOT DISPLAY'];
foreach (['form','email'] as $source) {
    foreach (['processing','pending','success','failed'] as $status) {
        $result=request_result(array_replace($row,['request_source'=>$source,'status'=>$status,'email_stage'=>'awaiting_result']));
        check($result['status']===$status,'Return actual database status');
        check($result['complete']===in_array($status,['success','failed'],true),'Only final database statuses finish the RPA wait');
        check($result['message']===match($status) {'success'=>'Success','failed'=>'Failed',default=>'In progress'},'Status-only presentation');
        check(!array_key_exists('rpa_display_message',$result),'Deprecated column absent from polling API');
    }
}
check(!request_result(array_replace($row,['error_code'=>'PROCESSING_ERROR']))['complete'],'Local form error cannot finalize RPA status');
check(request_result(array_replace($row,['status'=>'success','error_code'=>'PROCESSING_ERROR']))['message']==='Success','Final RPA status takes precedence over diagnostics');

$_SESSION=['user'=>['id'=>'test-user','username'=>'Test']];
$request=array_replace($row,['rpa_response'=>json_encode($ack),'rpa_request_payload'=>'{}','rpa_reference_id'=>null,'rpa_http_status'=>200,
    'error_code'=>null,'error_detail'=>null,'completed_at'=>null,'created_at'=>'2026-09-23 10:00:00','applicant_name'=>'Test',
    'id_number'=>'Test','applicant_email'=>'test@example.test','crim'=>'Test']);
foreach (['home','request-history','request-details'] as $view) {
    ob_start(); render($view,['request'=>$request,'requests'=>[$request]]); $html=ob_get_clean();
    check(str_contains($html,'data-request-id="'.$row['id'].'"'),'Poll correct request');
    check(str_contains($html,'data-request-complete="false"'),'Wait for RPA status');
    check(str_contains($html,'In progress'),'Pending UI label');
    check(!str_contains($html,'LEGACY TEXT MUST NOT DISPLAY'),'No legacy display message in UI');
}
foreach (['form','email'] as $source) {
    foreach (['processing','success','failed'] as $status) {
        $detail=array_replace($request,['request_source'=>$source,'status'=>$status,'email_stage'=>'awaiting_result',
            'rpa_response'=>json_encode(['runner_id'=>'private-runner-id','runner_name'=>'private-runner-name','schedule_id'=>'private-schedule','control_room_ip'=>'private-control-room']),
            'rpa_response_text'=>'private-raw-text','rpa_http_status'=>502,'rpa_reference_id'=>'private-reference',
            'rpa_request_payload'=>json_encode(['company'=>'private-company','fields'=>['sCRMID'=>'CUSTOMER-CRM','sLanguage'=>'en'],'secret'=>'private-payload']),
            'error_code'=>'PRIVATE_ERROR_CODE','error_detail'=>'private-error-detail',
            'email_attempts'=>[['attempt_no'=>1,'outcome'=>'private-attempt','http_status'=>502]]]);
        ob_start(); render('request-details',['request'=>$detail]); $html=ob_get_clean();
        foreach (['private-','PRIVATE_ERROR_CODE','response-raw','technical-note','HTTP 502','RPA reference ID','Submission attempts','View raw','View email RPA request','TL notification'] as $secret) {
            check(!str_contains($html,$secret),'Technical information absent from rendered HTML: '.$secret);
        }
        check(str_contains($html,'test@example.test') && str_contains($html,'data-request-status'),'Customer fields and live status retained');
        check(!str_contains($html,'<small>Completed</small>') && !str_contains($html,'Not provided by RPA') && !str_contains($html,'data-request-completed'),'Completion field is not rendered or reintroduced by polling');
        if ($source==='email') check(str_contains($html,'CUSTOMER-CRM'),'Customer CRM retained without raw payload');
        if ($status!=='processing') check(str_contains($html,$status==='success'?'Success':'Failed'),'Final status remains visible');
    }
}
foreach ([1,4,10] as $page) {
    ob_start(); render('request-history',['requests'=>[$request],'source'=>'email','pagination'=>['page'=>$page,'pages'=>10,'total'=>150,'per_page'=>15]]); $html=ob_get_clean();
    check(strpos($html,'class="history-pagination"')>strpos($html,'class="history-list"'),'Pagination follows request list');
    check(str_contains($html,'aria-current="page" aria-label="Page '.$page.'"'),'Current page identified');
    check(str_contains($html,'href="/request-history?source=email&amp;page='),'Pagination preserves filter');
    check(str_contains($html,'href="/request-history?source=form"'),'Filter navigation resets page');
    check(str_contains($html,'rel="prev"')===($page>1) && str_contains($html,'rel="next"')===($page<10),'Previous/Next respect boundaries');
    check(str_contains($html,'&hellip;'),'Long pagination is compact');
}
foreach ([0,15] as $total) {
    ob_start(); render('request-history',['requests'=>$total?[$request]:[],'pagination'=>['page'=>1,'pages'=>1,'total'=>$total,'per_page'=>15]]); $html=ob_get_clean();
    check(!str_contains($html,'class="history-pagination"'),'Zero to fifteen records need no pagination');
    if (!$total) check(str_contains($html,'No requests yet'),'Empty state retained');
}
echo "PHP status, customer-facing details, pagination controls and view regression checks passed.\n";
