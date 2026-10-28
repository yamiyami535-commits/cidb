<?php
declare(strict_types=1);
namespace Cidb\Email;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

final class WebklexMailbox implements Mailbox {
    private ?Client $client=null;
    private int $nextUid=0;
    public function __construct(private readonly Config $config) {}
    public function connect(): array {
        $this->client=(new ClientManager())->make([
            'host'=>$this->config->get('EMAIL_IMAP_HOST'),'port'=>$this->config->integer('EMAIL_IMAP_PORT',993),
            'encryption'=>$this->config->get('EMAIL_IMAP_ENCRYPTION','ssl'),'validate_cert'=>true,
            'username'=>$this->config->get('EMAIL_USERNAME'),'password'=>$this->config->get('EMAIL_PASSWORD'),
            'protocol'=>'imap','timeout'=>$this->config->integer('EMAIL_TIMEOUT_SECONDS',15),
        ]);
        $this->client->connect();
        $status=$this->client->openFolder($this->config->get('EMAIL_FOLDER'));
        $validity=(int)($status['uidvalidity'] ?? 0);
        $this->nextUid=(int)($status['uidnext'] ?? 0);
        if ($validity<1 || $this->nextUid<1) throw new \RuntimeException('Mailbox identity unavailable');
        return ['uid_validity'=>$validity,'next_uid'=>$this->nextUid];
    }
    public function discover(int $after, int $limit): array {
        if ($after >= $this->nextUid-1) return [];
        $uids=$this->client->getConnection()->search(['UID '.($after+1).':'.($this->nextUid-1)],IMAP::ST_UID)->validatedData();
        $uids=array_values(array_filter(array_map('intval',$uids),fn($uid)=>$uid>$after && $uid<$this->nextUid));
        sort($uids,SORT_NUMERIC);
        return array_slice($uids,0,$limit);
    }
    public function read(int $uid): array {
        $connection=$this->client->getConnection();
        $size=$connection->fetch('RFC822.SIZE',[$uid],null,IMAP::ST_UID)->validatedData();
        $bytes=(int)($size[$uid] ?? 0);
        if ($bytes<1 || $bytes>$this->config->integer('EMAIL_MAX_MESSAGE_BYTES',2097152)) throw new \RuntimeException('Message size unsupported');
        // PEEK avoids changing \Seen until durable recording. The parser discards attachment parts.
        // IMAP returns BODY[] for a BODY.PEEK[] request. Fetch multiple items so
        // Webklex preserves the response keys instead of matching the request token.
        $data=$connection->fetch(['UID','BODY.PEEK[]'],[$uid],null,IMAP::ST_UID)->validatedData();
        $raw=$data[$uid]['BODY[]'] ?? null;
        if (!is_string($raw)) throw new \RuntimeException('Message unavailable');
        return (new BodyMessage($raw))->data();
    }
    public function markRead(int $uid): void {
        $this->client->getConnection()->store(['\\Seen'],$uid,null,'+',true,IMAP::ST_UID)->validatedData();
    }
    public function close(): void {
        if ($this->client!==null) { $this->client->disconnect(); $this->client=null; }
    }
}
