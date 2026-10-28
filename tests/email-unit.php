<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Email/autoload.php';
require dirname(__DIR__).'/src/Ticketing/CancellationDetector.php';
require dirname(__DIR__).'/src/Ticketing/ClogAttachmentReference.php';
require dirname(__DIR__).'/src/Ticketing/ClogCaseStatus.php';
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/request-result.php';
require dirname(__DIR__).'/src/RpaClient.php';
require dirname(__DIR__).'/vendor/autoload.php';

use Cidb\Email\{FieldExtractor,EmailRpa,Config,MessageSelector,BodyMessage};
function expect(bool $condition,string $why): void { if (!$condition) throw new RuntimeException($why); }

expect(\Cidb\Ticketing\ClogCaseStatus::isOpen('Open')
    && \Cidb\Ticketing\ClogCaseStatus::isOpen(' open '),'CLOG Open status matching is case/space tolerant');
expect(!\Cidb\Ticketing\ClogCaseStatus::isOpen('Closed')
    && !\Cidb\Ticketing\ClogCaseStatus::isOpen('Offered')
    && !\Cidb\Ticketing\ClogCaseStatus::isOpen(null),'Only CLOG Open cases are active candidates');

$extractor=new FieldExtractor();
$body="Hi support@example.test,\nTitle: Sample request\nState: Sarawak\nRemarks: For testing\nName: Sample Person\nNRIC: B0012345\nCRM: 001234-Ab/56\nEmail to Cancel ID: customer@example.test";
$result=$extractor->extract(['text'=>$body]);
expect(!$result['attention'] && !$result['missing'],'Complete template');
expect(EmailRpa::payload($result['fields'])===['company'=>'CIDB','scenario_key'=>'cidb_masterbot','channel'=>'Email',
    'fields'=>['sCRMID'=>'001234-Ab/56','sEmail'=>'customer@example.test','sCustomerName'=>'Sample Person','sCustomerType'=>'Individual','sLocationArea'=>'Sarawak','sIdentificationNumber'=>'B0012345']], 'Exact wrapped email payload without legacy sChannel');
foreach (['',"State:\n","State:   \t\n"] as $stateLine) {
    $optional=$extractor->extract(['text'=>str_replace("State: Sarawak\n",$stateLine,$body)]);
    expect(!$optional['attention'] && !$optional['missing'] && $optional['fields']['state']===null,'Absent or blank State does not block extraction');
    $expected=EmailRpa::payload($result['fields']); unset($expected['fields']['sLocationArea']);
    expect(EmailRpa::payload($optional['fields'])===$expected,'Missing State omits only the optional location payload key');
}
foreach ([null,''," \t"] as $state) {
    expect(!array_key_exists('sLocationArea',EmailRpa::payload(array_replace($result['fields'],['state'=>$state]))['fields']),'Null or blank location is omitted');
}
$withoutState=$result['fields']; unset($withoutState['state']);
expect(!array_key_exists('sLocationArea',EmailRpa::payload($withoutState)['fields']),'Absent location key is supported');
expect($extractor->extract(['text'=>$body."\nState: Selangor"])['attention'],'Conflicting optional State still requires review');
foreach (['CRM ID','CRMID','sCRMID','crm'] as $label) {
    $alias=$extractor->extract(['text'=>str_replace('CRM:',$label.':',$body)]);
    expect(!$alias['attention'] && !$alias['missing'] && EmailRpa::payload($alias['fields'])['fields']['sCRMID']==='001234-Ab/56','CRM aliases preserve identifier case, punctuation and leading zeros');
}
$missingCrm=$extractor->extract(['text'=>str_replace("CRM: 001234-Ab/56\n",'',$body)]);
expect($missingCrm['missing']===['CRM'] && $missingCrm['fields']['crm']===null,'Missing CRM is required without a fabricated fallback');
$oversizedCrm=$extractor->extract(['text'=>str_replace('001234-Ab/56',str_repeat('C',256),$body)]);
expect($oversizedCrm['attention'] && in_array('CRM',$oversizedCrm['missing'],true),'Oversized CRM IDs require review and are not looked up');
expect($extractor->extract(['text'=>$body."\nsCRMID: Other"])['attention'],'Conflicting CRM aliases require review');
expect($extractor->extract(['text'=>$body."\nCRM: 001234-Ab/56"])['attention'],'Repeated CRM label requires review');
foreach (FieldExtractor::LABELS as $field=>$label) {
    $partial=preg_replace('/^'.preg_quote($label,'/').':.*$/m',$label.':',$body);
    $missing=$extractor->extract(['text'=>$partial]);
    expect($missing['missing']===($field==='state'?[]:[$label]) && $missing['fields'][$field]===null,"Blank $label must not capture next line");
}
$partial=$extractor->extract(['text'=>"Name:\nNRIC: 001234\nState: Sarawak\nEmail to Cancel ID: a@example.test"]);
expect($partial['fields']['name']===null && $partial['fields']['nric']==='001234','Blank line and leading zero');
expect($extractor->extract(['text'=>$body."\nName: Another Person"])['attention'],'Duplicate field requires review');
expect($extractor->extract(['text'=>'> '.$body])['attention'],'Quoted input requires review');
expect($extractor->extract(['text'=>$body."\nFrom: previous@example.test"])['attention'],'Forwarded content requires review');
$html='<html><body><p>Hi support@example.test</p><table><tr><td>Name:</td><td>Sample Person</td></tr><tr><td>NRIC:</td><td>B0012345</td></tr><tr><td>State:</td><td>Sarawak</td></tr><tr><td>CRM ID:</td><td>001234-Ab/56</td></tr><tr><td>Email to Cancel ID:</td><td><a href="mailto:customer@example.test">customer@example.test</a></td></tr></table></body></html>';
expect($extractor->extract(['html'=>$html])['fields']===$result['fields'],'HTML table and mailto');
expect($extractor->extract(['html'=>'<blockquote>'.$html.'</blockquote>'])['attention'],'HTML quote detection');

foreach ([
    'test@example.com<mailto:test@example.com>'=>'test@example.com',
    '  Test.User+tag@EXAMPLE.COM  '=>'Test.User+tag@example.com',
    'Test Applicant <test@example.com>'=>'test@example.com',
    'Please use test@example.com (primary account).'=>'test@example.com',
    'test@example.com.'=>'test@example.com',
    'mailto:test@example.com'=>'test@example.com',
    'mailto:test%2Btag@EXAMPLE.COM?subject=Help&cc=other@example.com'=>'test+tag@example.com',
    'test@example.com&lt;mailto:test@example.com&gt;'=>'test@example.com',
    '[test@example.com](mailto:test@example.com)'=>'test@example.com',
    'test@EXAMPLE.COM<mailto:test@example.com>'=>'test@example.com',
] as $input=>$expected) {
    $clean=$extractor->extract(['text'=>str_replace('customer@example.test',$input,$body)]);
    expect(!$clean['attention'] && !$clean['missing'] && $clean['fields']['email']===$expected,'Clean email: '.$input);
    expect(EmailRpa::payload($clean['fields'])['fields']['sEmail']===$expected,'Only the cleaned address enters the RPA payload');
    $otherFields=$clean['fields']; unset($otherFields['email']);
    $originalFields=$result['fields']; unset($originalFields['email']);
    expect($otherFields===$originalFields,'Email cleanup leaves other fields unchanged');
}
foreach (['not-an-email','test@@example.com','test..name@example.com','test@-example.com',
    'test @example.com','test@example.com@invalid','test@example.com; other@example.com',
    'test@example.com<mailto:other@example.com>','broken@bad; test@example.com'] as $input) {
    $invalid=$extractor->extract(['text'=>str_replace('customer@example.test',$input,$body)]);
    expect($invalid['attention'] && $invalid['fields']['email']===null && in_array('Email to Cancel ID',$invalid['missing'],true),'Invalid or ambiguous email requires review: '.$input);
}
$cleanHtml=$extractor->extract(['html'=>str_replace('customer@example.test','test@example.com&lt;mailto:test@example.com&gt;',$html)]);
expect(!$cleanHtml['attention'] && $cleanHtml['fields']['email']==='test@example.com','HTML email suffix is cleaned');

$raw="From: Agent <agent@example.test>\r\nSubject: =?UTF-8?B?VGVzdCByZXF1ZXN0?=\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=x\r\n\r\n".
    "--x\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode($body)."\r\n".
    "--x\r\nContent-Type: text/plain\r\nContent-Disposition: attachment; filename=untrusted.txt\r\n\r\nName: Wrong Name\r\n--x--\r\n";
$mime=new BodyMessage($raw); $message=$mime->data();
expect($message['sender']==='agent@example.test' && $message['subject']==='Test request','Decode sender and header');
expect($extractor->extract($message)['fields']===$result['fields'],'Attachment cannot replace body');
expect($mime->getAttachments()->count()===0,'No attachment objects');
// Exercise the installed IMAP fetch parser with server tokens (no sockets).
// BODY.PEEK[] requests are returned by IMAP servers under the BODY[] key.
class FixtureImapProtocol extends \Webklex\PHPIMAP\Connection\Protocols\ImapProtocol {
    public array $commands=[];
    private array $reply=[];
    public function __construct(private readonly string $raw) { parent::__construct(\Webklex\PHPIMAP\Config::make()); }
    public function connected(): bool { return true; }
    public function sendRequest(string $command,array $tokens=[],?string &$tag=null): \Webklex\PHPIMAP\Connection\Protocols\Response {
        $tag='fixture'; $this->commands[]=[$command,$tokens];
        $size=str_contains(implode(' ',$tokens),'RFC822.SIZE');
        $this->reply=[1,'FETCH',['UID',42,$size?'RFC822.SIZE':'BODY[]',$size?strlen($this->raw):$this->raw]];
        return \Webklex\PHPIMAP\Connection\Protocols\Response::empty();
    }
    public function readLine(\Webklex\PHPIMAP\Connection\Protocols\Response $response,array|string &$tokens=[],string $wantedTag='*',bool $dontParse=false): bool {
        if (!$this->reply) return true;
        $tokens=$this->reply; $this->reply=[]; return false;
    }
    public function __destruct() {} // There is no connection to log out of.
}
$protocol=new FixtureImapProtocol($raw);
$client=(new \Webklex\PHPIMAP\ClientManager())->make([]);
$client->connection=$protocol;
$reader=new \Cidb\Email\WebklexMailbox(new Config([]));
(new ReflectionProperty($reader,'client'))->setValue($reader,$client);
expect($reader->read(42)['text']===$message['text'],'Actual IMAP fetch parser handles BODY[] response to PEEK');
expect(str_contains(implode(' ',$protocol->commands[1][1]),'BODY.PEEK[]'),'Read does not mark seen');
$client->connection=null;
$latin=new BodyMessage("From: a@example.test\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nName: Andr=E9\r\n");
expect(str_contains($latin->data()['text'],'André'),'Declared charset decoding');

$config=new Config(['EMAIL_SELECTION_MODE'=>'rules','EMAIL_ALLOWED_SENDERS'=>'agent@example.test','EMAIL_SUBJECT_PATTERN'=>'/request/i','EMAIL_ALLOW_REPLIES'=>'false','EMAIL_USERNAME'=>'support@example.test']);
$selector=new MessageSelector($config);
expect($selector->accepts($message),'Approved sender and subject');
expect(!$selector->accepts(array_replace($message,['sender'=>'other@example.test'])),'Unapproved sender');
expect(!$selector->accepts(array_replace($message,['auto_submitted'=>'auto-generated'])),'Ignore automated notification');
expect(!$selector->accepts(array_replace($message,['in_reply_to'=>'thread'])),'Reply policy');
expect((new Config([]))->get('EMAIL_FOLDER')==='CIDB','Confirmed dedicated folder default');
$folderSelector=new MessageSelector(new Config([]));
foreach (['agent@example.test','other@example.test','support@example.test',''] as $sender) {
    expect($folderSelector->accepts(array_replace($message,['sender'=>$sender,'auto_submitted'=>'auto-generated','in_reply_to'=>'thread'])),'Folder-only selection ignores sender and reply metadata');
}
expect(\Cidb\Ticketing\CancellationDetector::matches(['email_subject'=>'Permohonan pembatalan ID-123']), 'CLOG title identifies a Malay ID cancellation');
expect(\Cidb\Ticketing\CancellationDetector::matches(['email_body'=>'Please cancel the registration ID ABC']), 'CLOG body identifies an English cancellation');
expect(!\Cidb\Ticketing\CancellationDetector::matches(['email_subject'=>'General account enquiry']), 'Non-cancellation CLOG email is not ticketed');
expect(!$folderSelector->accepts(array_replace($message,['text'=>'General account enquiry'])), 'Email intake still requires cancellation intent');
$fileBase='https://cidb.daythree.net/handler/email_fetch/files';
expect(\Cidb\Ticketing\ClogAttachmentReference::url($fileBase,'CRM-123','id document 1.pdf')
    ===$fileBase.'/CRM-123/id%20document%201.pdf','Attachment URL encodes the filename as one path segment');
expect(\Cidb\Ticketing\ClogAttachmentReference::url($fileBase,'CRM-123','File(1).pdf')
    ===$fileBase.'/CRM-123/File%281%29.pdf','Attachment URL safely encodes ordinary punctuation');
expect(\Cidb\Ticketing\ClogAttachmentReference::url($fileBase,'001234-Ab/56','proof 1.pdf')
    ===$fileBase.'/001234-Ab%2F56/proof%201.pdf',
    'Slash in CRM ID is encoded without adding a URL path segment');
foreach ([
    static fn()=>\Cidb\Ticketing\ClogAttachmentReference::url('http://cidb.daythree.net/files','CRM-123','file.pdf'),
    static fn()=>\Cidb\Ticketing\ClogAttachmentReference::url($fileBase,'','file.pdf'),
    static fn()=>\Cidb\Ticketing\ClogAttachmentReference::url($fileBase,'CRM-123',"bad\0name.pdf"),
    static fn()=>\Cidb\Ticketing\ClogAttachmentReference::url($fileBase,'CRM-123','../private.pdf'),
    static fn()=>\Cidb\Ticketing\ClogAttachmentReference::url($fileBase,"bad\\id",'file.pdf'),
] as $invalidReference) {
    try { $invalidReference(); throw new RuntimeException('Invalid attachment reference was accepted'); }
    catch (InvalidArgumentException) {}
}
expect(count((new Config([]))->problems())>0,'Incomplete configuration cannot activate');
expect(!array_filter((new Config([]))->problems(),fn($p)=>str_contains($p,'NOTIFICATION') || str_contains($p,'TL_ADDRESS') || str_contains($p,'SMTP') || str_contains($p,'FROM_ADDRESS')),'Disabled notifications require no settings');
$launch=new Config(['EMAIL_IMAP_HOST'=>'mail.example.test','EMAIL_USERNAME'=>'support@example.test','EMAIL_PASSWORD'=>'fixture-only',
    'EMAIL_EXTRACTION_APPROVED'=>'true','EMAIL_RETENTION_APPROVED'=>'true']);
expect($launch->problems()===[],'Cancellation intake is ready without email-execution RPA or SMTP settings');

expect(EmailRpa::interpret(0,'',CURLE_COULDNT_CONNECT)['outcome']==='safe_failure','Pre-dispatch failure safe');
expect(EmailRpa::interpret(0,'',CURLE_OPERATION_TIMEDOUT)['outcome']==='uncertain','Timeout is uncertain');
expect(EmailRpa::interpret(500,'error')['outcome']==='uncertain','HTTP 500 is not proof of non-acceptance');
expect(EmailRpa::interpret(200,'invalid')['outcome']==='uncertain','Invalid response not success');
expect(EmailRpa::interpret(200,'{"status":"inserted","message":"Accepted"}')['outcome']==='accepted','Acknowledgement waits');
expect(EmailRpa::interpret(200,'{"status":"failed","display_message":"Not successful"}')['outcome']==='accepted','HTTP status cannot replace database outcome');

$row=['id'=>'test','request_source'=>'email','status'=>'processing','email_stage'=>'missing_fields','email_missing_fields'=>'["NRIC"]','rpa_display_message'=>null,'notification_state'=>'pending'];
expect(str_contains(request_result($row)['message'],'Missing fields: NRIC'),'Missing-fields presentation');
expect(request_result($row)['complete'],'No notification waiting while disabled');
$row['notification_state']='accepted';
expect(request_result($row)['complete'] && request_result($row)['status']==='processing','Local missing-field case does not invent RPA status');
expect(request_result(array_replace($row,['email_stage'=>'awaiting_result','status'=>'pending','rpa_display_message'=>'Result']))['status']==='pending','Message alone does not invent an outcome');
expect(request_result(array_replace($row,['email_stage'=>'awaiting_result','status'=>'success','rpa_display_message'=>'Result']))['status']==='success','Confirmed final result');
expect(request_result(array_replace($row,['email_stage'=>'awaiting_result','status'=>'success','rpa_display_message'=>'Result']))['completed_at']==='Not provided by RPA','No invented completion timestamp');

$_SESSION=['user'=>['id'=>'test','username'=>'Test']];
$request=array_replace($row,['id'=>'00000000-0000-4000-8000-000000000001','created_at'=>'2026-09-25 10:00:00','completed_at'=>null,
    'rpa_response'=>null,'rpa_request_payload'=>null,'rpa_http_status'=>null,'rpa_reference_id'=>null,'error_code'=>null,'error_detail'=>null,
    'applicant_name'=>'<script>bad()</script>','applicant_email'=>'a@example.test','id_number'=>null,'email_location_area'=>'Sarawak']);
ob_start(); render('request-details',['request'=>$request]); $output=ob_get_clean();
expect(!str_contains($output,'<script>bad()') && str_contains($output,'&lt;script&gt;bad()'),'Escaped customer content');
expect(!str_contains($output,'CRM submitted') && !str_contains($output,'Language submitted'),'No form-only email fields');
expect(str_contains($output,'RPA not submitted') && !str_contains($output,'TL notification'),'Missing field explanation without internal notification configuration');
ob_start(); render('request-history',['requests'=>[$request],'source'=>'email']); $output=ob_get_clean();
expect(str_contains($output,'?source=form') && str_contains($output,'?source=email') && str_contains($output,'EMAIL'),'History filters and source badge');
echo "Email extraction, MIME, selection, response, and view checks passed.\n";
