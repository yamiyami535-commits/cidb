<?php
declare(strict_types=1);
namespace Cidb\Ticketing;

final class ClogIdentityExtractor
{
    public static function extractNric(string $text, string $html = ''): ?string
    {
        $content = html_entity_decode(
            preg_replace(
                '/<(?:br|\/p|\/div|\/tr|\/td|\/li)\b[^>]*>/i',
                "\n",
                $text . "\n" . $html
            ) ?? $text . "\n" . $html,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $content = strip_tags($content);
        $pattern = '/\b(?:NRIC|K\s*\/\s*P|IC(?:\s*(?:NO|NUMBER))?|'
            . '(?:(?:NO\.?\s*)?(?:NOMBOR\s+)?KAD\s+PENGENALAN)|'
            . 'IDENTIFICATION\s*(?:NO|NUMBER)?)\b'
            . '\s*(?:[:#=]\s*|\s+)'
            . '(\d[\d\s-]{8,18}\d)/iu';
        preg_match_all($pattern, $content, $matches);

        $candidates = [];
        foreach ($matches[1] ?? [] as $candidate) {
            $digits = preg_replace('/\D/', '', $candidate);
            if (is_string($digits) && self::isPlausibleNric($digits)) {
                $candidates[$digits] = true;
            }
        }

        return count($candidates) === 1 ? (string)array_key_first($candidates) : null;
    }

    public static function extractDetails(array $emailRow): array
    {
        $text = (string)($emailRow['body_text'] ?? '');
        $html = (string)($emailRow['body_html'] ?? '');
        $subject = (string)($emailRow['subject'] ?? '');

        $nric = self::extractNric($text, $html);

        $tableData = [];
        if (preg_match_all('/<tr>\s*<th[^>]*>([^<:]+):?\s*<\/th>\s*<td[^>]*>(.*?)<\/td>\s*<\/tr>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $k = strtolower(trim(strip_tags($m[1])));
                $v = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $tableData[$k] = $v;
            }
        }
        $lines = preg_split('/\r?\n/', $text);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (preg_match('/^\s*([A-Za-z0-9\s.]+?)\s*:\s*(.+)$/u', $line, $m)) {
                    $k = strtolower(trim($m[1]));
                    if (!isset($tableData[$k])) {
                        $tableData[$k] = trim($m[2]);
                    }
                }
            }
        }

        $name = $tableData['name'] ?? trim((string)($emailRow['name'] ?? '')) ?: null;
        $email = $tableData['email'] ?? trim((string)($emailRow['email'] ?? '')) ?: null;
        $state = $tableData['state'] ?? null;
        $phone = $tableData['mobile'] ?? $tableData['phone'] ?? $tableData['contact'] ?? $tableData['contact number'] ?? null;
        $companyName = $tableData['company'] ?? $tableData['company name'] ?? null;
        $companyNo = $tableData['company no.'] ?? $tableData['company no'] ?? null;

        $categoryRaw = strtolower($tableData['customer category'] ?? $tableData['category'] ?? '');
        if (str_contains($categoryRaw, 'individu') || str_contains(strtolower($subject), 'individu')) {
            $customerType = 'individual';
        } elseif (str_contains($categoryRaw, 'syarikat') || str_contains(strtolower($subject), 'syarikat') || str_contains($categoryRaw, 'company')) {
            $customerType = 'company';
        } else {
            $customerType = 'individual';
        }

        $combinedText = strtolower($subject . ' ' . $text . ' ' . ($tableData['remarks'] ?? ''));
        if (preg_match('/\b(?:pembatalan|batal|individu|syarikat|kad pengenalan|terima kasih|rayuan|permohonan)\b/i', $combinedText)) {
            $language = 'ms';
        } else {
            $language = 'en';
        }

        return [
            'name' => $name,
            'nric' => $nric,
            'email' => $email,
            'state' => $state,
            'phone' => $phone,
            'customer_type' => $customerType,
            'company_name' => $companyName,
            'company_no' => $companyNo,
            'language' => $language,
        ];
    }

    private static function isPlausibleNric(string $digits): bool
    {
        if (strlen($digits) !== 12) {
            return false;
        }

        $year = (int)substr($digits, 0, 2);
        $month = (int)substr($digits, 2, 2);
        $day = (int)substr($digits, 4, 2);
        $currentYear = (int)date('Y');

        foreach ([1900 + $year, 2000 + $year] as $birthYear) {
            if ($birthYear <= $currentYear && checkdate($month, $day, $birthYear)) {
                $age = $currentYear - $birthYear;
                if ($age >= 0 && $age <= 120) {
                    return true;
                }
            }
        }

        return false;
    }
}
