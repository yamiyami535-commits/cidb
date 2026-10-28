<?php
declare(strict_types=1);
namespace Cidb\Email;

use Cidb\Ticketing\CancellationDetector;

require_once dirname(__DIR__).'/Ticketing/CancellationDetector.php';

final class MessageSelector implements Selector {
    public function __construct(private readonly Config $config) {}
    public function accepts(array $message): bool {
        if (!CancellationDetector::matches($message)) return false;

        // Dedicated-folder policy: every arrival is selected regardless of sender,
        // sender or reply headers, once it is identified as an ID cancellation.
        $mode=$this->config->get('EMAIL_SELECTION_MODE');
        if ($mode==='all') return true;
        $sender=strtolower(trim($message['sender'] ?? ''));
        // Prevent notification loops; no sample subject or customer values are hardcoded.
        if ($sender==='' || in_array($sender, array_filter([
            strtolower($this->config->get('EMAIL_USERNAME')), strtolower($this->config->get('EMAIL_FROM_ADDRESS'))
        ]), true)) return false;
        if (strtolower($message['auto_submitted'] ?? 'no')!=='no' || !empty($message['is_report'])) return false;
        if (!$this->config->yes('EMAIL_ALLOW_REPLIES') && !empty($message['in_reply_to'])) return false;
        if ($mode!=='rules') return false;
        $senders=array_filter(array_map(static fn($s)=>strtolower(trim($s)), explode(',', $this->config->get('EMAIL_ALLOWED_SENDERS'))));
        $pattern=$this->config->get('EMAIL_SUBJECT_PATTERN');
        if (!$senders && $pattern==='') return false;
        return (!$senders || in_array($sender,$senders,true)) && ($pattern==='' || preg_match($pattern,$message['subject'] ?? '')===1);
    }
}
