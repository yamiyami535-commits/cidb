<?php
declare(strict_types=1);

/**
 * Simple wrapper for the local OCR FastAPI service shipped in the `ocr/` directory.
 *
 * Expected endpoint (configurable via .env `RPA_OCR_ENDPOINT`):
 *   POST <endpoint>
 *   multipart/form-data with field name "file"
 *
 * The service returns a JSON object containing at least the following keys:
 *   - nric (string)
 *   - full_name (string)
 *   - signature (bool)
 *   - cross_present (bool)
 */
final class OcrClient
{
    private string $endpoint;

    public function __construct()
    {
        $this->endpoint = env_value('RPA_OCR_ENDPOINT', 'http://127.0.0.1:8000/process');
    }

    /**
     * Send an image (or PDF) to the OCR service and decode the JSON response.
     *
     * @param string $filePath absolute path to the attachment file
     * @return array associative array of OCR results
     * @throws RuntimeException on network or JSON errors
     */
    public function extractData(string $filePath): array
    {
        $boundary = '--------------------------' . bin2hex(random_bytes(12));
        $fileName = basename($filePath);
        $fileData = file_get_contents($filePath);
        if ($fileData === false) {
            throw new RuntimeException("Unable to read file $filePath for OCR processing");
        }

        $body = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"$fileName\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $fileData . "\r\n"
            . "--$boundary--\r\n";

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: multipart/form-data; boundary=$boundary",
                "Accept: application/json",
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_TIMEOUT_MS => 15000,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("OCR request failed: $error");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("OCR service returned HTTP $httpCode");
        }

        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }
}
?>

