<?php
declare(strict_types=1);

final class OcrVerificationClient
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /** @return array{http_status:int,body:array<string,mixed>} */
    public function verify(array $fields, array $files): array
    {
        $documentType = strtolower(trim((string)($fields['document_type'] ?? '')));
        if (!in_array($documentType, ['mykad', 'passport'], true)) {
            return $this->response(400, 'INVALID_REQUEST', 'Choose MyKad or passport verification.');
        }

        $enteredName = trim((string)($fields['entered_name'] ?? ''));
        if ($enteredName === '' || mb_strlen($enteredName) > 200) {
            return $this->response(400, 'INVALID_REQUEST', 'Enter the name shown on the identity document.');
        }

        $multipart = [
            'document_type' => $documentType,
            'entered_name' => $enteredName,
        ];
        if ($documentType === 'mykad') {
            $nric = trim((string)($fields['entered_nric'] ?? ''));
            if ($nric === '' || mb_strlen($nric) > 40) {
                return $this->response(400, 'INVALID_REQUEST', 'Enter a valid NRIC.');
            }
            $multipart['entered_nric'] = $nric;
            $requiredFiles = ['mykad_front', 'mykad_back'];
        } else {
            $passportNumber = trim((string)($fields['entered_passport_number'] ?? ''));
            if ($passportNumber === '' || mb_strlen($passportNumber) > 40) {
                return $this->response(400, 'INVALID_REQUEST', 'Enter the passport number.');
            }
            $multipart['entered_passport_number'] = $passportNumber;
            $requiredFiles = ['passport_image'];
        }

        foreach ($requiredFiles as $fieldName) {
            $upload = $files[$fieldName] ?? null;
            if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return $this->response(400, 'INVALID_REQUEST', 'Upload all required identity document images.');
            }

            $path = (string)($upload['tmp_name'] ?? '');
            if ($path === '' || !is_uploaded_file($path)) {
                return $this->response(400, 'INVALID_REQUEST', 'The uploaded image could not be read.');
            }
            $size = (int)($upload['size'] ?? 0);
            $maxBytes = max(1, (int)env_value('OCR_MAX_FILE_SIZE_MB', '8')) * 1024 * 1024;
            if ($size < 1 || $size > $maxBytes) {
                return $this->response(413, 'INVALID_REQUEST', 'Each identity image must be smaller than the configured upload limit.');
            }

            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                return $this->response(400, 'INVALID_REQUEST', 'Upload a JPEG, PNG, or WebP image.');
            }

            $multipart[$fieldName] = new CURLFile(
                $path,
                $mimeType,
                basename((string)($upload['name'] ?? 'identity-image'))
            );
        }

        $baseUrl = rtrim(env_value('OCR_SERVICE_URL', 'http://127.0.0.1:8001'), '/');
        $urlParts = parse_url($baseUrl);
        if (!is_array($urlParts)
            || !in_array(strtolower((string)($urlParts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($urlParts['host'])
            || isset($urlParts['user'])
            || isset($urlParts['pass'])
            || isset($urlParts['query'])
            || isset($urlParts['fragment'])) {
            return $this->response(503, 'OCR_API_UNAVAILABLE', 'OCR verification is not configured.');
        }

        $isLoopback = in_array(strtolower((string)$urlParts['host']), ['127.0.0.1', 'localhost', '::1'], true);
        $apiKey = env_value('OCR_API_KEY');
        if ((strtolower((string)$urlParts['scheme']) !== 'https' && !$isLoopback)
            || (!$isLoopback && $apiKey === '')) {
            return $this->response(503, 'OCR_API_UNAVAILABLE', 'OCR verification is not configured securely.');
        }

        $endpoint = str_ends_with($baseUrl, '/api/verify-document')
            ? $baseUrl
            : $baseUrl . '/api/verify-document';
        $curl = curl_init($endpoint);
        if ($curl === false) {
            return $this->response(503, 'OCR_API_UNAVAILABLE', 'OCR verification is temporarily unavailable.');
        }

        $headers = ['Accept: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'X-API-Key: ' . $apiKey;
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $multipart,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => max(1000, (int)env_value('OCR_CONNECT_TIMEOUT_MS', '5000')),
            CURLOPT_TIMEOUT_MS => max(1000, (int)env_value('OCR_TIMEOUT_MS', '180000')),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $rawBody = curl_exec($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($curl);
        curl_close($curl);
        if ($rawBody === false || $curlError !== 0) {
            error_log('OCR verification request failed (cURL ' . $curlError . ').');
            return $this->response(503, 'OCR_API_UNAVAILABLE', 'OCR verification is temporarily unavailable.');
        }

        $body = json_decode((string)$rawBody, true);
        if (!is_array($body)) {
            error_log('OCR verification service returned an invalid JSON response.');
            return $this->response(502, 'OCR_FAILED', 'OCR verification returned an invalid response.');
        }

        return [
            'http_status' => $httpStatus >= 100 && $httpStatus <= 599 ? $httpStatus : 502,
            'body' => $body,
        ];
    }

    /** @return array{http_status:int,body:array<string,mixed>} */
    private function response(int $httpStatus, string $status, string $message): array
    {
        return [
            'http_status' => $httpStatus,
            'body' => ['verified' => false, 'status' => $status, 'message' => $message],
        ];
    }
}
