<?php
declare(strict_types=1);
namespace Cidb\Email;

final class Config {
    public function __construct(private readonly array $values) {}
    public static function environment(): self {
        $keys = ['EMAIL_ENABLED','EMAIL_IMAP_HOST','EMAIL_IMAP_PORT','EMAIL_IMAP_ENCRYPTION','EMAIL_USERNAME',
            'EMAIL_PASSWORD','EMAIL_FOLDER','EMAIL_BATCH_SIZE','EMAIL_TIMEOUT_SECONDS','EMAIL_MAX_MESSAGE_BYTES',
            'EMAIL_SELECTION_MODE','EMAIL_ALLOWED_SENDERS','EMAIL_SUBJECT_PATTERN','EMAIL_ALLOW_REPLIES',
            'EMAIL_SELECTION_APPROVED','EMAIL_EXTRACTION_APPROVED',
            'EMAIL_RETENTION_APPROVED','EMAIL_SMTP_HOST','EMAIL_SMTP_PORT','EMAIL_SMTP_ENCRYPTION',
            'EMAIL_SMTP_USERNAME','EMAIL_SMTP_PASSWORD','EMAIL_FROM_ADDRESS','EMAIL_FROM_NAME','EMAIL_TL_ADDRESS',
            'EMAIL_NOTIFICATION_SUBJECT','EMAIL_NOTIFICATION_BODY','EMAIL_NOTIFICATION_MAX_ATTEMPTS'];
        $keys = array_merge($keys, [
            'CLOG_DB_HOST','CLOG_DB_DATABASE','CLOG_DB_USERNAME','CLOG_DB_PASSWORD',
            'CLOG_DB_TIMEOUT','CLOG_FILE_FETCH_BASE_URL',
        ]);
        $values = [];
        foreach ($keys as $key) $values[$key] = \env_value($key);
        return new self($values);
    }
    public function get(string $key, string $default = ''): string {
        if ($default==='') $default=['EMAIL_FOLDER'=>'CIDB','EMAIL_SELECTION_MODE'=>'all',
            'EMAIL_SELECTION_APPROVED'=>'true','EMAIL_TL_ADDRESS'=>'tl@example.invalid'][$key] ?? '';
        $value = (string)($this->values[$key] ?? '');
        return $value === '' ? $default : $value;
    }
    public function yes(string $key): bool { return filter_var($this->get($key), FILTER_VALIDATE_BOOL); }
    public function integer(string $key, int $default): int { return (int)$this->get($key, (string)$default); }
    public function mailboxKey(): string {
        return hash('sha256', strtolower($this->get('EMAIL_IMAP_HOST')).':'.$this->get('EMAIL_IMAP_PORT','993').'|'.
            strtolower($this->get('EMAIL_USERNAME')).'|'.$this->get('EMAIL_FOLDER'));
    }
    /** No I/O. Pending decisions must be supplied explicitly, not inferred from the sample. */
    public function problems(): array {
        $errors = [];
        foreach (['EMAIL_SELECTION_APPROVED','EMAIL_EXTRACTION_APPROVED','EMAIL_RETENTION_APPROVED'] as $key) {
            if (!$this->yes($key)) $errors[] = "$key must be confirmed";
        }
        foreach (['EMAIL_IMAP_HOST','EMAIL_USERNAME','EMAIL_PASSWORD','EMAIL_FOLDER'] as $key) {
            if ($this->get($key) === '') $errors[] = "$key is required";
        }
        /* Temporarily disabled with TL notifications. Restore these requirements
           together with notification dispatch and its approved retry policy.
        foreach (['EMAIL_SMTP_HOST','EMAIL_FROM_ADDRESS','EMAIL_TL_ADDRESS',
            'EMAIL_NOTIFICATION_SUBJECT','EMAIL_NOTIFICATION_BODY','EMAIL_NOTIFICATION_MAX_ATTEMPTS'] as $key) {
            if ($this->get($key)==='') $errors[]="$key is required";
        }
        foreach (['EMAIL_FROM_ADDRESS','EMAIL_TL_ADDRESS'] as $key) {
            if (!filter_var($this->get($key), FILTER_VALIDATE_EMAIL)) $errors[] = "$key must be a valid address";
        }
        if (!in_array($this->get('EMAIL_NOTIFICATION_MAX_ATTEMPTS'), ['1','2'], true)) $errors[]='Notification attempts must explicitly be 1 or 2';
        if (preg_match('/[\r\n]/', $this->get('EMAIL_NOTIFICATION_SUBJECT'))) $errors[]='Notification subject must be a single line';
        */
        foreach (['EMAIL_IMAP_ENCRYPTION'] as $key) {
            if (!in_array($this->get($key, 'ssl'), ['ssl','tls'], true)) $errors[] = "$key must be ssl or tls";
        }
        foreach (['EMAIL_IMAP_PORT'=>993] as $key=>$default) {
            if ($this->integer($key,$default)<1 || $this->integer($key,$default)>65535) $errors[] = "$key is invalid";
        }
        if (!in_array($this->get('EMAIL_SELECTION_MODE'), ['all','rules'], true)) $errors[] = 'EMAIL_SELECTION_MODE must explicitly be all or rules';
        if ($this->get('EMAIL_SELECTION_MODE')==='rules' && $this->get('EMAIL_ALLOWED_SENDERS')==='' && $this->get('EMAIL_SUBJECT_PATTERN')==='') $errors[] = 'At least one selection rule is required';
        $pattern=$this->get('EMAIL_SUBJECT_PATTERN');
        if ($pattern!=='' && @preg_match($pattern,'')===false) $errors[]='EMAIL_SUBJECT_PATTERN is invalid';
        if ($this->get('EMAIL_SELECTION_MODE')==='rules' && !in_array(strtolower($this->get('EMAIL_ALLOW_REPLIES')), ['true','false','1','0'], true)) $errors[]='EMAIL_ALLOW_REPLIES must explicitly be true or false for rules mode';
        foreach (['EMAIL_BATCH_SIZE'=>25,'EMAIL_TIMEOUT_SECONDS'=>15,'EMAIL_MAX_MESSAGE_BYTES'=>2097152] as $key=>$default) {
            if ($this->integer($key,$default)<1) $errors[]="$key must be positive";
        }
        return array_unique($errors);
    }
    public function clogProblems(): array {
        $errors = [];
        foreach (['CLOG_DB_HOST','CLOG_DB_DATABASE','CLOG_DB_USERNAME'] as $key) {
            if ($this->get($key) === '') $errors[] = "$key is required to look up CLOG attachments";
        }
        $baseUrl = $this->get('CLOG_FILE_FETCH_BASE_URL', 'https://cidb.daythree.net/handler/email_fetch/files');
        $parts = parse_url($baseUrl);
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            $errors[] = 'CLOG_FILE_FETCH_BASE_URL must be an HTTPS URL without credentials, query, or fragment';
        }
        return $errors;
    }
}
