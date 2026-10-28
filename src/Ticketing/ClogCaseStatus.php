<?php
declare(strict_types=1);
namespace Cidb\Ticketing;

final class ClogCaseStatus
{
    public static function isOpen(?string $status): bool
    {
        return is_string($status) && strcasecmp(trim($status), 'Open') === 0;
    }
}
