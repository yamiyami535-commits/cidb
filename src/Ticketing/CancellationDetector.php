<?php
declare(strict_types=1);
namespace Cidb\Ticketing;

final class CancellationDetector {
    public static function matches(array $fields): bool {
        $text=[];
        foreach ($fields as $key=>$value) {
            if (!is_string($value)) continue;
            $normalizedKey=strtolower((string)preg_replace('/[^a-z0-9]/i','',(string)$key));
            if (in_array($normalizedKey,['title','message','content','description','text','html'],true)
                || str_ends_with($normalizedKey,'subject')
                || str_ends_with($normalizedKey,'body')
                || str_ends_with($normalizedKey,'content')) {
                $text[]=$value;
            }
        }
        return preg_match(
            '/\b(?:cancellation|cancel|pembatalan)\b.{0,40}\b(?:id|account|registration)\b|\b(?:id|account|registration)\b.{0,40}\b(?:cancellation|cancel|pembatalan)\b/i',
            implode("\n",$text)
        )===1;
    }
}
