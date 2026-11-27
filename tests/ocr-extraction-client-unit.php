<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/OcrVerificationClient.php';

// No upload or network request is made. Identity fields must not block the
// existing multipart upload validation, whether omitted or conflicting.
$client = new OcrVerificationClient();
foreach (['mykad', 'passport'] as $documentType) {
    $withoutIdentity = $client->verify(['document_type' => $documentType], []);
    $withLegacyIdentity = $client->verify([
        'document_type' => $documentType,
        'entered_name' => '',
        'entered_nric' => 'not-an-id',
        'entered_passport_number' => '!',
    ], []);
    if ($withoutIdentity !== $withLegacyIdentity
        || $withoutIdentity['http_status'] !== 400
        || $withoutIdentity['body']['verified'] !== false
        || $withoutIdentity['body']['message'] !== 'Upload all required identity document images.') {
        throw new RuntimeException('Legacy identity fields must not constrain document submission.');
    }
}
if ($client->verify(['document_type' => 'unknown'], [])['body']['status'] !== 'INVALID_REQUEST') {
    throw new RuntimeException('Document type validation must be preserved.');
}
echo "OCR extraction client checks passed.\n";
