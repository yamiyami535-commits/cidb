<?php
declare(strict_types=1);

$configuration = [];
function env_value(string $key, string $default = ''): string { global $configuration; return $configuration[$key] ?? $default; }
function new_uuid(): string { return '00000000-0000-4000-8000-000000000001'; }
require __DIR__ . '/../src/OcrResultDelivery.php';
require __DIR__ . '/../src/OcrTestingAccess.php';
function check_ocr(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$result = ['verified'=>true, 'status'=>'MANUAL_REVIEW', 'message'=>'pass',
           'extracted_name'=>'DOCUMENT PERSON', 'extracted_document_number'=>'A12345678',
           'entered_name'=>'OTHER PERSON', 'diagnostics'=>['private'=>'not transmitted']];
$calls = [];
$sender = new OcrResultDelivery(function ($payload, $correlation, $key) use (&$calls) {
    $calls[] = compact('payload', 'correlation', 'key');
    return ['http_status'=>204, 'error'=>null];
});
check_ocr($sender->deliver($result, 'api', 'request-1')['state'] === 'disabled' && !$calls, 'Default must not send.');
$configuration['OCR_RPA_RESULT_ENABLED'] = 'true';
check_ocr($sender->deliver($result, 'testing', 'request-1')['state'] === 'disabled' && !$calls, 'Testing cannot send even when enabled.');
check_ocr($sender->deliver($result, 'api', 'request-1')['state'] === 'delivered', 'Enabled result must send.');
check_ocr($calls[0]['payload'] === ['extracted_name'=>'DOCUMENT PERSON', 'extracted_document_number'=>'A12345678', 'verification_status'=>'VERIFIED'], 'Exactly three document-derived fields.');
$sender->deliver($result, 'api', 'request-1');
check_ocr($calls[0]['key'] === $calls[1]['key'], 'A repeated correlation ID must have a stable idempotency key.');
$sender->deliver($result, 'worker', 'request-1');
check_ocr($calls[1]['key'] !== $calls[2]['key'], 'Sources must have separate idempotency namespaces.');
$configuration['OCR_RPA_RESULT_API_ENABLED'] = 'false';
check_ocr($sender->deliver($result, 'api', 'request-1')['state'] === 'disabled', 'Source-level switch must disable.');
foreach (['MANUAL_REVIEW'=>'NOT_VERIFIED', 'INVALID_DOCUMENT'=>'NOT_VERIFIED', 'REUPLOAD_REQUIRED'=>'NOT_VERIFIED', 'OCR_FAILED'=>'ERROR', 'INTERNAL_ERROR'=>'ERROR'] as $status=>$expected) {
    $payload = OcrResultDelivery::payload(['verified'=>false, 'status'=>$status, 'entered_name'=>'NEVER USE']);
    check_ocr($payload === ['extracted_name'=>null, 'extracted_document_number'=>null, 'verification_status'=>$expected], 'Missing extraction must stay null.');
}
$before = $result;
$failing = new OcrResultDelivery(static function () { throw new RuntimeException('simulated transport failure'); });
check_ocr($failing->deliver($result, 'worker', 'request-2')['state'] === 'failed' && $result === $before, 'Failure cannot mutate verification.');
$negative = new OcrResultDelivery(static fn() => ['http_status'=>503, 'error'=>'simulated']);
check_ocr($negative->deliver($result, 'worker', 'request-2')['state'] === 'failed', 'Negative HTTP response must fail delivery.');
check_ocr(OcrResultDelivery::correlation("bad\r\nHeader: value") === new_uuid(), 'Header injection must be rejected.');
foreach (['admin'=>true, 'manager'=>true, 'agent'=>false, 'customer'=>false, ''=>false] as $role=>$allowed) {
    check_ocr(OcrTestingAccess::allowedRole($role) === $allowed, 'Staff authorization mismatch.');
}
check_ocr(!OcrTestingAccess::allowed(null), 'Anonymous testing forbidden.');
// Existing builders retain their existing shapes and are not used for this call.
$legacy = (new RpaClient())->payload(['name'=>'OLD', 'id_number'=>'OLD1', 'email'=>'a@example.test', 'crm'=>'C1', 'language'=>'en']);
check_ocr(array_keys($legacy) === ['company','scenario_key','channel','fields'] && count($legacy['fields']) === 7, 'Legacy form contract changed.');
echo "OCR delivery isolation, payload, status, idempotency and role checks passed.\n";
