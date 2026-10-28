<?php
declare(strict_types=1);
namespace Cidb\Ticketing;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ClogConversationStore
{
    public function __construct(
        private readonly PDO $portalDb,
        private readonly DateTimeZone $sourceTimezone
    ) {}

    public function syncEmail(array $email): string
    {
        $crmId = trim((string)($email['uniqid'] ?? ''));
        if ($crmId === '') {
            throw new \RuntimeException('CLOG email is missing its CRM ID.');
        }
        $threadId = $this->portalDb->prepare("
            INSERT INTO cidb_email_threads (crm_id, thread_key, subject, first_message_at, last_message_at)
            VALUES (?, ?, ?, ?::timestamptz, ?::timestamptz)
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
        $timestamp = $this->timestamp((string)($email['time'] ?? ''));
        $threadId->execute([
            $crmId,
            'crm:' . $crmId,
            $this->nullable($email['subject'] ?? null),
            $timestamp,
            $timestamp,
        ]);
        $id = $threadId->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('Unable to create or update CLOG email thread.');
        }

        $sourceMessageId = (string)($email['id'] ?? '');
        if ($sourceMessageId === '') {
            throw new \RuntimeException('CLOG email is missing its source message ID.');
        }
        $metadata = [
            'case_id' => $email['case_id'] ?? null,
            'case_status' => $email['case_status'] ?? null,
            'case_assigned' => $email['case_assigned'] ?? null,
            'email_tagging' => $email['email_tagging'] ?? null,
        ];
        $message = $this->portalDb->prepare("
            INSERT INTO cidb_email_messages
                (thread_id, source_system, source_message_id, direction, participant_name,
                 participant_address, subject, body_text, body_html, source_email_type,
                 source_group_value, source_timestamp, sent_at, source_metadata)
            VALUES (?, 'clog', ?, 'unknown', ?, ?, ?, ?, ?, ?, ?, ?, ?::timestamptz, ?::jsonb)
            ON CONFLICT (source_system, source_message_id) DO UPDATE SET
                thread_id = EXCLUDED.thread_id,
                participant_name = EXCLUDED.participant_name,
                participant_address = EXCLUDED.participant_address,
                subject = EXCLUDED.subject,
                body_text = EXCLUDED.body_text,
                body_html = EXCLUDED.body_html,
                source_email_type = EXCLUDED.source_email_type,
                source_group_value = EXCLUDED.source_group_value,
                source_timestamp = EXCLUDED.source_timestamp,
                sent_at = EXCLUDED.sent_at,
                source_metadata = EXCLUDED.source_metadata,
                updated_at = NOW()
            RETURNING id
        ");
        $message->execute([
            $id,
            $sourceMessageId,
            $this->nullable($email['name'] ?? null),
            $this->nullable($email['email'] ?? null),
            $this->nullable($email['subject'] ?? null),
            (string)($email['body_text'] ?? ''),
            (string)($email['body_html'] ?? ''),
            $this->nullable($email['emailtype'] ?? null),
            (string)($email['lstinGroup'] ?? ''),
            (string)($email['time'] ?? ''),
            $timestamp,
            json_encode($metadata, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        if ($message->fetchColumn() === false) {
            throw new \RuntimeException('Unable to create or update CLOG email message.');
        }

        return (string)$id;
    }

    public function syncRelatedRecords(string $crmId, array $data, array $activities, array $ticketing): void
    {
        $interactionQuery = $this->portalDb->prepare('SELECT id FROM cidb_interaction_data WHERE crm_id = ?');
        $interactionQuery->execute([$crmId]);
        $interactionId = $interactionQuery->fetchColumn();
        if ($interactionId === false) {
            throw new \RuntimeException('CLOG details cannot be linked to a missing interaction.');
        }

        $dataUpsert = $this->portalDb->prepare("
            INSERT INTO cidb_clog_data_records
                (source_data_id, interaction_id, parent_id, form_id, form_name, customer_type,
                 interaction_type, language, state_location, contact_id, source_payload,
                 source_created_at, source_updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?)
            ON CONFLICT (source_data_id) DO UPDATE SET
                interaction_id = EXCLUDED.interaction_id,
                parent_id = EXCLUDED.parent_id,
                form_id = EXCLUDED.form_id,
                form_name = EXCLUDED.form_name,
                customer_type = EXCLUDED.customer_type,
                interaction_type = EXCLUDED.interaction_type,
                language = EXCLUDED.language,
                state_location = EXCLUDED.state_location,
                contact_id = EXCLUDED.contact_id,
                source_payload = EXCLUDED.source_payload,
                source_created_at = EXCLUDED.source_created_at,
                source_updated_at = EXCLUDED.source_updated_at,
                synced_at = NOW()
        ");
        foreach ($data as $row) {
            $dataUpsert->execute([
                (string)$row['ID'], $interactionId, $this->nullable($row['Parent_ID'] ?? null),
                $this->nullable($row['FormID'] ?? null), $this->nullable($row['FormName'] ?? null),
                $this->nullable($row['CustomerType'] ?? null), $this->nullable($row['TypeOfInteraction'] ?? null),
                $this->nullable($row['Language'] ?? null), $this->nullable($row['State_Location'] ?? null),
                $this->nullable($row['ContactID'] ?? null),
                json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                $this->nullable($row['CreatedDateTime'] ?? null),
                $this->nullable($row['LastUpdatedDateTime'] ?? null),
            ]);
        }

        $activityUpsert = $this->portalDb->prepare("
            INSERT INTO cidb_clog_activity
                (source_activity_id, interaction_id, source_data_id, clog_user_id, username, activity,
                 viewed_at, source_created_at, source_payload)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)
            ON CONFLICT (source_activity_id) DO UPDATE SET
                interaction_id = EXCLUDED.interaction_id,
                source_data_id = EXCLUDED.source_data_id,
                clog_user_id = EXCLUDED.clog_user_id,
                username = EXCLUDED.username,
                activity = EXCLUDED.activity,
                viewed_at = EXCLUDED.viewed_at,
                source_created_at = EXCLUDED.source_created_at,
                source_payload = EXCLUDED.source_payload,
                synced_at = NOW()
        ");
        foreach ($activities as $row) {
            $activityUpsert->execute([
                (string)$row['ID'], $interactionId, $this->nullable($row['DataID'] ?? null),
                $this->nullable($row['UserID'] ?? null), $this->nullable($row['UserName'] ?? null),
                $this->nullable($row['Activity'] ?? null), $this->nullable($row['ViewDateTime'] ?? null),
                $this->nullable($row['CreatedDateTime'] ?? null),
                json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        }

        $ticketingUpsert = $this->portalDb->prepare("
            INSERT INTO cidb_clog_ticketing_log
                (source_log_id, interaction_id, source_data_id, received_status, send_status,
                 created_by, source_created_at, source_payload)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb)
            ON CONFLICT (source_log_id) DO UPDATE SET
                interaction_id = EXCLUDED.interaction_id,
                source_data_id = EXCLUDED.source_data_id,
                received_status = EXCLUDED.received_status,
                send_status = EXCLUDED.send_status,
                created_by = EXCLUDED.created_by,
                source_created_at = EXCLUDED.source_created_at,
                source_payload = EXCLUDED.source_payload,
                synced_at = NOW()
        ");
        foreach ($ticketing as $row) {
            $ticketingUpsert->execute([
                (string)$row['ID'], $interactionId, $this->nullable($row['TicketID'] ?? null),
                $this->nullable($row['ReceivedStatus'] ?? null), $this->nullable($row['SendStatus'] ?? null),
                $this->nullable($row['CreatedBy'] ?? null), $this->nullable($row['CreatedDateTime'] ?? null),
                json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        }
    }

    private function timestamp(string $value): string
    {
        try {
            return (new DateTimeImmutable($value, $this->sourceTimezone))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Invalid CLOG email timestamp.', 0, $exception);
        }
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
