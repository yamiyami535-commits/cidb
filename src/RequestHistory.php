<?php
declare(strict_types=1);

/** One access rule for history, details, recent activity, and status polling. */
final class RequestHistory {
    private ?bool $emailSchema=null;
    private ?bool $emailTables=null;
    public function __construct(private readonly PDO $pdo) {}
    private function hasEmailSchema(): bool {
        return $this->emailSchema ??= (bool)$this->pdo->query("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid=to_regclass('portal_requests') AND attname='request_source' AND NOT attisdropped)")->fetchColumn();
    }
    private function hasEmailTables(): bool {
        // The source column can exist before the rest of a manual migration.
        // Keep the portal available without changing source-based authorization.
        return $this->emailTables ??= (bool)$this->pdo->query("SELECT to_regclass('portal_email_intake') IS NOT NULL
            AND to_regclass('portal_email_notifications') IS NOT NULL
            AND to_regclass('portal_email_attempts') IS NOT NULL")->fetchColumn();
    }
    private function select(): string {
        if (!$this->hasEmailSchema()) return "SELECT r.*, 'form' AS request_source FROM portal_requests r";
        if (!$this->hasEmailTables()) return 'SELECT r.* FROM portal_requests r';
        return 'SELECT r.*,i.stage AS email_stage,i.location_area AS email_location_area,i.missing_fields AS email_missing_fields,
            i.sender AS email_sender,i.subject AS email_subject,n.state AS notification_state,n.recipient AS notification_recipient,n.last_error AS notification_error
            FROM portal_requests r LEFT JOIN portal_email_intake i ON i.request_id=r.id
            LEFT JOIN portal_email_notifications n ON n.intake_id=i.id';
    }
    private function visibility(): string {
        return $this->hasEmailSchema()?"(r.request_source='email' OR (r.request_source='form' AND r.user_id=:uid))":'r.user_id=:uid';
    }
    private function filteredVisibility(string $userId, string $source): array {
        if ($userId==='') return ['1=0',[]];
        if (!in_array($source,['all','form','email'],true)) $source='all';
        if (!$this->hasEmailSchema() && $source==='email') return ['1=0',[]];
        $where=$this->visibility(); $params=['uid'=>$userId];
        if ($source!=='all' && $this->hasEmailSchema()) { $where.=' AND r.request_source=:source'; $params['source']=$source; }
        return [$where,$params];
    }
    public function list(string $userId, string $source='all', ?int $limit=null, int $offset=0): array {
        [$where,$params]=$this->filteredVisibility($userId,$source);
        $sql=$this->select().' WHERE '.$where;
        $sql.=' ORDER BY r.created_at DESC,r.id';
        if ($limit!==null) $sql.=' LIMIT '.max(1,$limit);
        if ($offset>0) $sql.=' OFFSET '.$offset;
        $q=$this->pdo->prepare($sql); $q->execute($params); return $q->fetchAll();
    }
    public function paginate(string $userId, string $source='all', int $page=1): array {
        $perPage=15;
        [$where,$params]=$this->filteredVisibility($userId,$source);
        $q=$this->pdo->prepare('SELECT count(*) FROM portal_requests r WHERE '.$where);
        $q->execute($params); $total=(int)$q->fetchColumn();
        $pages=max(1,(int)ceil($total/$perPage));
        $page=max(1,min($page,$pages));
        return ['requests'=>$this->list($userId,$source,$perPage,($page-1)*$perPage),
            'pagination'=>['page'=>$page,'pages'=>$pages,'total'=>$total,'per_page'=>$perPage]];
    }
    public function find(string $userId, string $id, bool $attempts=false): ?array {
        if ($userId==='') return null;
        $q=$this->pdo->prepare($this->select().' WHERE r.id=:id AND '.$this->visibility().' LIMIT 1');
        $q->execute(['id'=>$id,'uid'=>$userId]); $row=$q->fetch();
        if (!$row) return null;
        if ($attempts && $row['request_source']==='email' && $this->hasEmailTables()) {
            $q=$this->pdo->prepare('SELECT a.attempt_no,a.outcome,a.http_status,a.error_code,a.started_at,a.finished_at FROM portal_email_attempts a JOIN portal_email_intake i ON i.id=a.intake_id WHERE i.request_id=:id ORDER BY a.attempt_no');
            $q->execute(['id'=>$id]); $row['email_attempts']=$q->fetchAll();
        }
        return $row;
    }
}
