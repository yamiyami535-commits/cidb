<?php
declare(strict_types=1);

require_once __DIR__ . '/OcrTestingAccess.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
$isRun = $path === '/ocr-testing/run';
if ($isRun) header('Content-Type: application/json; charset=utf-8');
if (!OcrTestingAccess::allowed(current_user())) {
    http_response_code(current_user() ? 403 : 401);
    if ($isRun) echo json_encode(['error'=>'An active admin or manager account is required.']);
    else echo 'An active admin or manager account is required.';
    return;
}
if ($path === '/ocr-testing' && $method === 'GET') {
    render('ocr-testing');
    return;
}
if (!$isRun || $method !== 'POST') {
    http_response_code(405);
    header('Allow: ' . ($isRun ? 'POST' : 'GET'));
    if ($isRun) echo json_encode(['error'=>'Method not allowed.']);
    return;
}
$maxBytes = max(1, (int)env_value('OCR_MAX_REQUEST_SIZE_MB', '17')) * 1024 * 1024;
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
    http_response_code(413);
    echo json_encode(['error'=>'The upload exceeds the configured request limit.']);
    return;
}
if (!csrf_valid()) {
    http_response_code(419);
    echo json_encode(['error'=>'Your session expired. Refresh the page and try again.']);
    return;
}
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) {
    http_response_code(400);
    echo json_encode(['error'=>'Send documents as multipart/form-data.']);
    return;
}
session_write_close();
try {
    // Intentionally no job creation, result persistence, or outbound RPA call.
    $result = (new OcrVerificationClient())->verifyForTesting((string)($_POST['mode'] ?? ''), $_FILES);
    http_response_code($result['http_status']);
    echo json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    error_log('OCR testing request failed (' . get_class($error) . ').');
    http_response_code(503);
    echo json_encode(['error'=>'OCR testing is temporarily unavailable.']);
}
