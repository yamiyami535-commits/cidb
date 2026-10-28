<?php
declare(strict_types=1);

/**
 * Helper class for validating OCR results against stored interaction data.
 */
final class StatusUpdater
{
    /** Expected keys in OCR result (for reference only). */
    private const EXPECTED_KEYS = [
        'nric',
        'full_name',
        'signature',
        'cross_present',
    ];

    /**
     * Compare OCR extracted data with the stored interaction record.
     *
     * @param array $stored   Row from `cidb_interaction_data`
     * @param array $ocrData  Decoded JSON from OCR service
     * @return array ['status'=>string, 'reason'=>?string]
     */
    public function validate(array $stored, array $ocrData): array
    {
        // Normalise values for comparison
        $storedNric   = trim((string)($stored['nric'] ?? ''));
        $storedName   = trim((string)($stored['applicant_name'] ?? ''));
        $ocrNric      = trim((string)($ocrData['nric'] ?? ''));
        $ocrName      = trim((string)($ocrData['full_name'] ?? ''));

        $nricMatch   = $storedNric !== '' && strtolower($storedNric) === strtolower($ocrNric);
        $nameMatch   = $storedName !== '' && strtolower($storedName) === strtolower($ocrName);

        $signatureOk    = ($ocrData['signature'] ?? false) === true;
        $crossPresentOk = ($ocrData['cross_present'] ?? false) === true;

        if ($nricMatch && $nameMatch && $signatureOk && $crossPresentOk) {
            return ['status' => 'Customer - Verified', 'reason' => null];
        }

        $reasons = [];
        if (!$nricMatch)   $reasons[] = 'NRIC mismatch';
        if (!$nameMatch)   $reasons[] = 'Name mismatch';
        if (!$signatureOk) $reasons[] = 'Missing signature';
        if (!$crossPresentOk) $reasons[] = 'Cross not present';

        return [
            'status' => 'Rejected',
            'reason' => implode(', ', $reasons),
        ];
    }
}
?>

