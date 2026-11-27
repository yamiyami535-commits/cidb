<?php
declare(strict_types=1);

require_once __DIR__ . '/RpaClient.php';

/** Optional notification, independent of OCR decisions and database status. */
final class OcrResultDelivery
{
    public function __construct(private mixed $transport = null) {}

    public static function payload(array $result): array
    {
        if (!isset($result['verified'], $result['status']) || !is_bool($result['verified'])
            || !in_array($result['status'], ['VERIFIED','MANUAL_REVIEW','INVALID_DOCUMENT','DETAILS_MISMATCH','REUPLOAD_REQUIRED','OCR_FAILED','INTERNAL_ERROR'], true)) {
            throw new InvalidArgumentException('Not an OCR verification outcome.');
        }
        if ($result['verified'] && !in_array($result['status'], ['VERIFIED', 'MANUAL_REVIEW'], true)) {
            throw new InvalidArgumentException('Inconsistent OCR verification outcome.');
        }
        $identity = static function (string $field) use ($result): ?string {
            $value = $result[$field] ?? null;
            if ($value !== null && !is_string($value)) throw new InvalidArgumentException('Invalid extracted identity type.');
            return $value !== null && trim($value) !== '' ? trim($value) : null;
        };
        return [
            'extracted_name' => $identity('extracted_name'),
            'extracted_document_number' => $identity('extracted_document_number'),
            'verification_status' => $result['verified'] ? 'VERIFIED'
                : (in_array($result['status'], ['OCR_FAILED', 'INTERNAL_ERROR'], true) ? 'ERROR' : 'NOT_VERIFIED'),
        ];
    }

    public static function correlation(mixed $supplied): string
    {
        return is_string($supplied) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $supplied)
            ? $supplied : new_uuid();
    }

    public function deliver(array $result, string $source, string $correlationId): array
    {
        if (!in_array($source, ['api', 'worker'], true)) return ['state'=>'disabled'];
        if (!filter_var(env_value('OCR_RPA_RESULT_ENABLED', 'false'), FILTER_VALIDATE_BOOL)
            || !filter_var(env_value($source === 'api' ? 'OCR_RPA_RESULT_API_ENABLED' : 'OCR_RPA_RESULT_WORKER_ENABLED', 'true'), FILTER_VALIDATE_BOOL)) {
            return ['state'=>'disabled'];
        }
        try {
            $payload = self::payload($result);
            $correlationId = self::correlation($correlationId);
            $key = 'ocr-' . hash('sha256', $source . ':' . $correlationId);
            $response = $this->transport !== null
                ? ($this->transport)($payload, $correlationId, $key)
                : (new RpaClient())->sendOcrResult($payload, $correlationId, $key);
            // This contract acknowledges receipt via HTTP 2xx, not a scheduler ID.
            $http = (int)($response['http_status'] ?? 0);
            if ($http < 200 || $http >= 300 || !empty($response['error'])) {
                error_log('OCR result delivery failed (' . $source . ', HTTP ' . $http . '). Verification was preserved.');
                return ['state'=>'failed', 'http_status'=>$http];
            }
            return ['state'=>'delivered', 'http_status'=>$http];
        } catch (Throwable $error) {
            error_log('OCR result delivery failed (' . $source . ', ' . get_class($error) . '). Verification was preserved.');
            return ['state'=>'failed'];
        }
    }
}
