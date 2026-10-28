<?php
declare(strict_types=1);
namespace Cidb\Email;

final class Processor {
    public function __construct(private readonly Store $store, private readonly Mailbox $mailbox,
        private readonly Selector $selector, private readonly Extractor $extractor,
        private readonly Config $config) {}

    public function activate(): void {
        if (!$this->store->lock()) throw new \RuntimeException('Mailbox worker is busy');
        try {
            if ($this->store->state()) throw new \RuntimeException('Mailbox already activated; its baseline cannot be reset');
            $identity=$this->mailbox->connect();
            $this->store->activate($identity['uid_validity'], $identity['next_uid']-1);
        } finally { try { $this->mailbox->close(); } finally { $this->store->unlock(); } }
    }
    public function run(): array {
        $counts=['discovered'=>0,'recorded'=>0,'tickets_created'=>0,'ignored'=>0,'errors'=>0];
        if (!$this->store->lock()) return $counts+['busy'=>true];
        try {
            $state=$this->store->state();
            if (!$state) throw new \RuntimeException('Mailbox has not been activated');
            $this->store->recover();
            $identity=$this->mailbox->connect();
            if ($identity['uid_validity']!==(int)$state['uid_validity']) throw new \RuntimeException('Mailbox UID validity changed; operator review required');
            $limit=$this->config->integer('EMAIL_BATCH_SIZE',25);
            $uids=$this->mailbox->discover((int)$state['last_uid'],$limit);
            $this->store->discover($uids); $counts['discovered']=count($uids);
            foreach ($this->store->work($limit) as $item) {
                if ($item['stage']==='discovered') {
                    try { $message=$this->mailbox->read((int)$item['message_uid']); }
                    catch (\Throwable) { $this->store->attention($item,'EMAIL_READ_ERROR'); $counts['errors']++; continue; }
                    if (!$this->selector->accepts($message)) { $this->store->ignored($item['id']); $counts['ignored']++; continue; }
                    try { $extraction=$this->extractor->extract($message); }
                    catch (\Throwable) { $this->store->attention($item,'EMAIL_PARSE_ERROR'); $counts['errors']++; continue; }
                    $item=$this->store->extracted($item,$message,$extraction,$this->config->get('EMAIL_TL_ADDRESS'));
                    $counts['recorded']++;
                    if (!empty($item['ticket_id'])) $counts['tickets_created']++;
                    try { $this->mailbox->markRead((int)$item['message_uid']); $this->store->markedRead($item['id']); }
                    catch (\Throwable) { $counts['errors']++; } // Durable work remains independent of \Seen.
                }
            }
            $this->store->health($counts['errors']?'MESSAGE_PROCESSING_ERROR':null);
            return $counts;
        } catch (\Throwable $e) {
            $this->store->health('WORKER_FAILED'); throw $e;
        } finally { try { $this->mailbox->close(); } finally { $this->store->unlock(); } }
    }
}
