<?php
declare(strict_types=1);
namespace Cidb\Ticketing;

use PDO;

final class ClogAttachmentDocumentStore
{
    private \PDOStatement $upsert;

    public function __construct(private readonly PDO $portalDb, private readonly string $baseUrl)
    {
        $this->upsert = $portalDb->prepare("
            INSERT INTO portal_ticket_documents
                (ticket_id, source_ref, source_email_id, email_message_id, crm_id, original_filename,
                 retrieval_url, reference_status, reference_result)
            VALUES (
                ?, ?, ?,
                (SELECT id FROM cidb_email_messages
                 WHERE source_system = 'clog' AND source_message_id = ? LIMIT 1),
                ?, ?, ?, ?, ?::jsonb
            )
            ON CONFLICT (ticket_id, source_ref) DO UPDATE SET
                source_email_id = EXCLUDED.source_email_id,
                email_message_id = COALESCE(EXCLUDED.email_message_id, portal_ticket_documents.email_message_id),
                crm_id = EXCLUDED.crm_id,
                original_filename = EXCLUDED.original_filename,
                retrieval_url = EXCLUDED.retrieval_url,
                reference_status = EXCLUDED.reference_status,
                reference_result = EXCLUDED.reference_result,
                updated_at = NOW()
        ");
    }

    /**
     * @param list<array{email_id:mixed,crm_id?:mixed,filename:mixed}> $files
     * @return list<array<string,mixed>>
     */
    public function synchronize(string $ticketId, string $fallbackCrmId, array $files): array
    {
        $documents = [];
        foreach ($files as $file) {
            $emailId = trim((string)$file['email_id']);
            if ($emailId === '') {
                throw new \RuntimeException('CLOG attachment is missing its source email ID.');
            }
            $crmId = trim((string)($file['crm_id'] ?? $fallbackCrmId));
            $filename = (string)$file['filename'];
            try {
                $url = ClogAttachmentReference::url($this->baseUrl, $crmId, $filename);
                $status = 'ready';
                $result = null;
            } catch (\InvalidArgumentException) {
                $url = null;
                $status = 'invalid';
                $result = json_encode(
                    ['reason' => 'Invalid attachment reference metadata'],
                    JSON_THROW_ON_ERROR
                );
            }

            $sourceRef = 'clog:' . hash('sha256', $emailId . ':' . $filename);
            $this->upsert->execute([
                $ticketId,
                $sourceRef,
                $emailId,
                $emailId,
                $crmId,
                $filename,
                $url,
                $status,
                $result,
            ]);
            $documents[] = [
                'source_ref' => $sourceRef,
                'source_email_id' => $emailId,
                'crm_id' => $crmId,
                'original_filename' => $filename,
                'retrieval_url' => $url,
                'reference_status' => $status,
                'reference_result' => $result === null ? null : json_decode($result, true, 512, JSON_THROW_ON_ERROR),
            ];
        }

        return $documents;
    }
}
