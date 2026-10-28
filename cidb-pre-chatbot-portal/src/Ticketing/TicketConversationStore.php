<?php
declare(strict_types=1);
namespace Cidb\Ticketing;

use PDO;

final class TicketConversationStore
{
    public function __construct(private readonly PDO $pdo) {}

    public function recordMailboxMessage(
        string $ticketId,
        ?string $crmId,
        string $mailboxMessageKey,
        ?string $parentRfcMessageId,
        ?string $rfcMessageId,
        array $message,
        ?string $actorUserId = null
    ): string {
        $threadKey = $crmId === null ? 'ticket:' . $ticketId : 'crm:' . $crmId;
        $thread = $this->pdo->prepare("
            INSERT INTO cidb_email_threads (crm_id, thread_key, subject, first_message_at, last_message_at)
            VALUES (?, ?, ?, NOW(), NOW())
            ON CONFLICT (thread_key) DO UPDATE SET
                subject = COALESCE(cidb_email_threads.subject, EXCLUDED.subject),
                first_message_at = LEAST(
                    COALESCE(cidb_email_threads.first_message_at, EXCLUDED.first_message_at),
                    EXCLUDED.first_message_at
                ),
                last_message_at = GREATEST(
                    COALESCE(cidb_email_threads.last_message_at, EXCLUDED.last_message_at),
                    EXCLUDED.last_message_at
                ),
                updated_at = NOW()
            RETURNING id
        ");
        $thread->execute([
            $crmId,
            $threadKey,
            $this->nullable($message['subject'] ?? null),
        ]);
        $threadId = $thread->fetchColumn();
        if ($threadId === false) {
            throw new \RuntimeException('Unable to create or update ticket email thread.');
        }

        $insert = $this->pdo->prepare("
            INSERT INTO cidb_email_messages
                (thread_id, source_system, source_message_id, source_parent_message_id, rfc_message_id,
                 actor_user_id, direction, sender_address, recipients, subject, body_text, body_html,
                 sent_at, delivery_status, source_metadata)
            VALUES (?, 'mailbox', ?, ?, ?, ?, 'inbound', ?, '[]'::jsonb, ?, ?, ?, NOW(), 'received', ?::jsonb)
            ON CONFLICT (source_system, source_message_id) DO UPDATE SET
                thread_id = EXCLUDED.thread_id,
                source_parent_message_id = EXCLUDED.source_parent_message_id,
                rfc_message_id = EXCLUDED.rfc_message_id,
                sender_address = EXCLUDED.sender_address,
                subject = EXCLUDED.subject,
                body_text = EXCLUDED.body_text,
                body_html = EXCLUDED.body_html,
                delivery_status = 'received',
                source_metadata = EXCLUDED.source_metadata,
                updated_at = NOW()
            RETURNING id
        ");
        $insert->execute([
            $threadId,
            $mailboxMessageKey,
            $this->nullable($parentRfcMessageId),
            $this->nullable($rfcMessageId),
            $actorUserId,
            $this->nullable($message['sender'] ?? null),
            $this->nullable($message['subject'] ?? null),
            (string)($message['text'] ?? ''),
            (string)($message['html'] ?? ''),
            json_encode([
                'message_id' => $rfcMessageId,
                'in_reply_to' => $parentRfcMessageId,
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        $messageId = $insert->fetchColumn();
        if ($messageId === false) {
            throw new \RuntimeException('Unable to record email in ticket conversation.');
        }

        if ($parentRfcMessageId !== null && $parentRfcMessageId !== '') {
            $this->pdo->prepare("
                UPDATE cidb_email_messages child
                SET parent_message_id = parent.id
                FROM cidb_email_messages parent
                WHERE child.id = ?
                  AND parent.source_system = 'mailbox'
                  AND parent.rfc_message_id = ?
                  AND parent.thread_id = child.thread_id
            ")->execute([$messageId, $parentRfcMessageId]);
        }

        $event = $this->pdo->prepare("
            INSERT INTO portal_ticket_events
                (ticket_id, actor_user_id, actor_type, event_type, metadata)
            VALUES (?, ?, ?, 'ticket.message_received', ?::jsonb)
        ");
        $event->execute([
            $ticketId,
            $actorUserId,
            $actorUserId === null ? 'system' : 'user',
            json_encode(['message_record_id' => $messageId], JSON_THROW_ON_ERROR),
        ]);

        return (string)$threadId;
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
