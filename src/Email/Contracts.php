<?php
declare(strict_types=1);
namespace Cidb\Email;

interface Mailbox {
    /** @return array{uid_validity:int, next_uid:int} */
    public function connect(): array;
    /** Sorted UIDs strictly greater than $after, at most $limit. */
    public function discover(int $after, int $limit): array;
    /** Decoded headers + text/html bodies only. */
    public function read(int $uid): array;
    public function markRead(int $uid): void;
    public function close(): void;
}
interface Selector { public function accepts(array $message): bool; }
interface Extractor { public function extract(array $message): array; }
interface Rpa { public function send(array $payload): array; }
interface Notifier { public function send(array $job): array; }
interface ClogAttachmentProvider {
    /** @return array{email_ids:list<string>,files:list<array{email_id:string,crm_id:string,filename:string}>} */
    public function lookup(string $crmId): array;
}
interface Store {
    public function lock(): bool;
    public function unlock(): void;
    public function state(): ?array;
    public function activate(int $validity, int $baseline): void;
    public function discover(array $uids): void;
    public function recover(): void;
    public function work(int $limit): array;
    public function ignored(string $id): void;
    public function extracted(array $item, array $message, array $extraction, string $recipient): array;
    public function attention(array $item, string $code): void;
    public function beginAttempt(array $item): ?array;
    public function finishAttempt(array $item, array $attempt, array $result): void;
    public function notifications(int $limit): array;
    public function beginNotification(string $id): bool;
    public function finishNotification(array $job, array $result, int $maxAttempts): void;
    public function markedRead(string $id): void;
    public function health(?string $error): void;
}
