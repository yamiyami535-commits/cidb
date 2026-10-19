<?php
declare(strict_types=1);

final class OcrVerificationClient
{
    private const FORM_FIELDS = [
        'document_type',
        'entered_name',
        'entered_nric',
        'entered_passport_number',
    ];

    private const FILE_FIELDS = [
        'mykad_front',
        'mykad_back',
        'passport_image',
    ];

    private const IMAGE_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const OUTCOMES = [
        'VERIFIED',
        'REUPLOAD_REQUIRED',
        'INVALID_DOCUMENT',
        'DETAILS_MISMATCH',
        'OCR_FAILED',
        'MANUAL_REVIEW',
        'INVALID_REQUEST',
        'INTERNAL_ERROR',
    ];

    public function verify(array $fields, array $files): array
    {
        $key = env_value('OCR_RPA_API_KEY');
        if ($key === '') {
            return self::error(503, 'OCR_API_UNAVAILABLE', 'OCR verification is not configured.');
        }

        $endpoint = env_value('OCR_SERVICE_URL');
        if (!$this->validEndpoint($endpoint)) {
            return self::error(503, 'OCR_API_UNAVAILABLE', 'OCR verification is not configured.');
        }

        $content = [];
        foreach (self::FORM_FIELDS as $name) {
            if (isset($fields[$name]) && is_string($fields[$name])) {
                $content[$name] = $fields[$name];
            }
        }

        $maxFileBytes = max(1, (int)env_value('OCR_MAX_FILE_SIZE_MB', '8')) * 1024 * 1024;
        foreach (self::FILE_FIELDS as $name) {
            $upload = $files[$name] ?? null;
            if (!is_array($upload)) {
                continue;
            }
            $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return self::error(413, 'INVALID_REQUEST', 'One of the uploaded images exceeds the configured upload size limit.');
            }
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
                return self::error(400, 'INVALID_REQUEST', 'One of the uploaded images could not be received.');
            }
            $path = (string)$upload['tmp_name'];
            $size = (int)($upload['size'] ?? 0);
            if ($size <= 0) {
                return self::error(400, 'INVALID_REQUEST', 'One of the uploaded images is empty.');
            }
            if ($size > $maxFileBytes) {
                return self::error(413, 'INVALID_REQUEST', 'Each image exceeds the configured upload size limit.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!is_string($mime) || !in_array($mime, self::IMAGE_TYPES, true)) {
                return self::error(400, 'INVALID_REQUEST', 'Only JPG, PNG and WEBP images are accepted.');
            }
            $content[$name] = new CURLFile($path, $mime, $name . '.' . $this->extensionFor($mime));
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return self::error(503, 'OCR_API_UNAVAILABLE', 'OCR verification is temporarily unavailable.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT_MS => max(1000, (int)env_value('OCR_CONNECT_TIMEOUT_MS', '5000')),
            CURLOPT_TIMEOUT_MS => max(1000, (int)env_value('OCR_TIMEOUT_MS', '180000')),
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $upstreamStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch);
        curl_close($ch);

        if (!is_string($body)) {
            if (in_array($curlError, [CURLE_OPERATION_TIMEDOUT], true)) {
                return self::error(504, 'OCR_TIMEOUT', 'OCR verification timed out. Please retry.');
            }
            return self::error(503, 'OCR_API_UNAVAILABLE', 'OCR verification is temporarily unavailable.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !is_string($decoded['status'] ?? null)
            || !in_array($decoded['status'], self::OUTCOMES, true)) {
            error_log('OCR service returned an invalid response.');
            return self::error(502, 'OCR_INVALID_RESPONSE', 'OCR verification returned an invalid response.');
        }
        if ($upstreamStatus < 200 || $upstreamStatus >= 600 || ($upstreamStatus >= 300 && $upstreamStatus < 400)) {
            error_log('OCR service returned an unexpected HTTP status.');
            return self::error(502, 'OCR_INVALID_RESPONSE', 'OCR verification returned an invalid response.');
        }

        $response = [
            'verified' => ($decoded['verified'] ?? false) === true,
            'status' => $decoded['status'],
            'message' => is_string($decoded['message'] ?? null) ? $decoded['message'] : 'OCR verification completed.',
            'document_type' => $this->safeDocumentType($decoded['document_type'] ?? $fields['document_type'] ?? null),
            'extracted_document_number_masked' => $this->safeMaskedNumber($decoded['extracted_document_number_masked'] ?? null),
        ];

        try {
            $statement = db()->prepare(
                'INSERT INTO portal_ocr_verifications (status, document_type, masked_document_number) '
                . 'VALUES (:status, :document_type, :masked_document_number)'
            );
            $statement->execute([
                'status' => $response['status'],
                'document_type' => $response['document_type'],
                'masked_document_number' => $response['extracted_document_number_masked'],
            ]);
        } catch (Throwable $error) {
            error_log('Unable to record OCR verification summary: ' . $error->getMessage());
            return self::error(503, 'OCR_AUDIT_UNAVAILABLE', 'OCR verification could not be recorded. Please retry.');
        }

        return ['http_status' => $upstreamStatus >= 400 ? $upstreamStatus : 200, 'body' => $response];
    }

    private function validEndpoint(string $endpoint): bool
    {
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string)parse_url($endpoint, PHP_URL_SCHEME));
        if ($scheme === 'https') {
            return true;
        }
        if ($scheme !== 'http' || !in_array(strtolower(env_value('APP_ENV', 'production')), ['local', 'test'], true)) {
            return false;
        }
        $host = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'webp',
        };
    }

    private function safeDocumentType(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        return in_array($value, ['mykad', 'passport'], true) ? $value : null;
    }

    private function safeMaskedNumber(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 32 || !preg_match('/^[A-Za-z0-9* -]+$/', $value)) {
            return null;
        }
        return $value;
    }

    public static function error(int $status, string $code, string $message): array
    {
        return [
            'http_status' => $status,
            'body' => [
                'verified' => false,
                'status' => $code,
                'message' => $message,
            ],
        ];
    }
}
