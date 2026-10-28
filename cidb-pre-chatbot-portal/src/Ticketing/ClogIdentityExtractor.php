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
