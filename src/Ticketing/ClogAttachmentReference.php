<?php
declare(strict_types=1);
namespace Cidb\Ticketing;

final class ClogAttachmentReference {
    public static function url(string $baseUrl, string $crmId, string $filename): string {
        if (trim($crmId)==='' || preg_match('/[\x00-\x1f\x7f\\\\]/', $crmId)===1
            || $crmId==='.' || $crmId==='..') {
            throw new \InvalidArgumentException('Attachment reference contains an empty or invalid CRM ID.');
        }
        if (trim($filename)==='' || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $filename)===1
            || $filename==='.' || $filename==='..') {
            throw new \InvalidArgumentException('Attachment reference contains an empty or invalid filename.');
        }
        if (filter_var($baseUrl, FILTER_VALIDATE_URL)===false) {
            throw new \InvalidArgumentException('Attachment endpoint must be a valid HTTPS URL.');
        }
        $parts=parse_url($baseUrl);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? ''))!=='https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Attachment endpoint must use HTTPS without credentials, query, or fragment.');
        }

        return rtrim($baseUrl,'/').'/'.rawurlencode($crmId).'/'.rawurlencode($filename);
    }
}
