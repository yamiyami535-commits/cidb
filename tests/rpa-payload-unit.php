<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/RpaClient.php';

function expect_rpa_payload(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$client = new RpaClient();
$formInput = [
    'name' => 'Test',
    'email' => 'test@example.test',
    'id_number' => 'TEST001',
    'crm' => 'TEST-CRM',
    'language' => 'en',
];
$formPayload = $client->payload($formInput);
expect_rpa_payload(
    $formPayload['fields'] === [
        'sCustomerType' => 'Individual',
        'sEmail' => 'test@example.test',
        'sCustomerName' => 'Test',
        'sIdentificationNumber' => 'TEST001',
        'sLanguage' => 'en',
        'sChannel' => 'Email',
        'sCRMID' => 'TEST-CRM',
    ],
    'Legacy form payload must remain unchanged.'
);
expect_rpa_payload(
    $client->payload($formInput + ['location_area' => 'Sarawak']) === $formPayload,
    'Legacy location fields must not change the form RPA payload.'
);

$requestPayload = $client->workflowPayload([
    'action_type' => 'request_information',
    'crm' => 'TEST-CRM',
    'email' => 'test@example.test',
    'name' => 'Test',
    'language' => 'English',
    'filename' => 'front.png,back.png',
    'state' => 'Sarawak',
    'contact_number' => '0123456789',
]);
expect_rpa_payload(
    $requestPayload['fields'] === [
        'sCRMID' => 'TEST-CRM',
        'sEmail' => 'test@example.test',
        'sCustomerName' => 'Test',
        'sLanguage' => 'en',
        'sChannel' => 'Email',
        'sState' => 'Sarawak',
        'sContactNumber' => '0123456789',
        'sFilename' => 'front.png,back.png',
        'sRequst' => 'Pending Customer Attachment',
    ],
    'Request-information Bot_Input must include the provided customer context.'
);

$cancelPayload = $client->workflowPayload([
    'action_type' => 'execute_cancellation',
    'crm' => 'TEST-CRM',
    'email' => 'test@example.test',
    'name' => 'Test',
    'customer_type' => 'individual',
    'id_number' => 'TEST001',
    'language' => 'ms',
    'ocr_status' => 'MANUAL_REVIEW_APPROVED',
    'filename' => 'signed-form.pdf',
]);
expect_rpa_payload(
    $cancelPayload['fields'] === [
        'sCRMID' => 'TEST-CRM',
        'sEmail' => 'test@example.test',
        'sCustomerName' => 'Test',
        'sLanguage' => 'ms',
        'sChannel' => 'Email',
        'sCustomerType' => 'Individual',
        'sIdentificationNumber' => 'TEST001',
        'sFilename' => 'signed-form.pdf',
        'sOCRStatus' => 'MANUAL_REVIEW_APPROVED',
        'sAction' => 'Email Cancellation',
    ],
    'Cancellation Bot_Input must include identity and explicit review status.'
);

foreach ([
    [
        'action_type' => 'execute_cancellation',
        'crm' => 'TEST-CRM',
        'email' => 'test@example.test',
        'name' => 'Test',
        'language' => 'en',
        'id_number' => 'TEST001',
        'ocr_status' => 'VERIFIED',
    ],
    [
        'action_type' => 'request_information',
        'crm' => 'TEST-CRM',
        'email' => 'test@example.test',
        'name' => 'Test',
    ],
] as $invalidInput) {
    try {
        $client->workflowPayload($invalidInput);
        throw new RuntimeException('Invalid workflow payload should have been rejected.');
    } catch (InvalidArgumentException) {
    }
}

echo "RPA workflow payload checks passed.\n";
