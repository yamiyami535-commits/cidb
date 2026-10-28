<?php
declare(strict_types=1);
namespace Cidb\Ticketing;

use Cidb\Email\ClogAttachmentProvider;
use PDO;

final class Cron2AttachmentRechecker
{
    private const REPLIED_STATUSES = [
        'Customer Replied - Verified',
        'Customer Replied - Document Reject',
    ];

    public function __construct(
        private readonly PDO $portalDb,
        private readonly ClogAttachmentProvider $clog,
        private readonly ClogAttachmentDocumentStore $documents
    ) {}

    public function run(string $crmId, int $limit = 25): array
    {
        if (preg_match('/^CRM-[A-Za-z0-9-]{1,60}$/', $crmId) !== 1) {
            throw new \InvalidArgumentException('A valid CRM ID is required for Cron2 attachment recheck.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Cron2 recheck limit must be from 1 through 100.');
        }

        $query = $this->portalDb->prepare("
            SELECT t.id, t.clog_crm_id, t.workflow_status, i.current_status
            FROM portal_tickets t
            JOIN cidb_interaction_data i ON i.id = t.interaction_id
            WHERE t.clog_crm_id = ?
              AND t.source_type = 'clog'
              AND t.customer_type = 'individual'
              AND t.assigned_to IS NOT NULL
              AND t.workflow_status IN ('needs_information', 'ocr_rejected')
              AND LOWER(TRIM(COALESCE(t.clog_status, i.clog_status, ''))) = 'open'
              AND i.current_status IN (
                  'Pending from Customer',
                  'Customer Replied - Verified',
                  'Customer Replied - Document Reject'
              )
            ORDER BY t.updated_at, t.id
            LIMIT ?
        ");
        $query->bindValue(1, $crmId, PDO::PARAM_STR);
        $query->bindValue(2, $limit, PDO::PARAM_INT);
        $query->execute();
        $tickets = $query->fetchAll(PDO::FETCH_ASSOC);

        $summary = ['tickets_checked' => 0, 'new_files' => 0, 'ready_for_ocr' => 0];
        if ($tickets === []) {
            return $summary;
        }

        $lookupUpdate = $this->portalDb->prepare("
            UPDATE portal_tickets
            SET attachment_lookup_status = ?,
                attachment_lookup_checked_at = NOW(),
                attachment_lookup_result = ?::jsonb,
                updated_at = NOW()
            WHERE id = ?
        ");
        $existingQuery = $this->portalDb->prepare("
            SELECT source_ref FROM portal_ticket_documents WHERE ticket_id = ?
        ");
        $pendingQuery = $this->portalDb->prepare("
            SELECT count(*) FROM portal_ticket_documents
            WHERE ticket_id = ? AND reference_status = 'ready' AND ocr_status = 'pending'
        ");
        $advance = $this->portalDb->prepare("
            UPDATE portal_tickets
            SET workflow_status = 'ready_for_ocr', updated_at = NOW()
            WHERE id = ? AND workflow_status IN ('needs_information', 'ocr_rejected')
        ");
        $event = $this->portalDb->prepare("
            INSERT INTO portal_ticket_events (ticket_id, actor_type, event_type, metadata)
            VALUES (?, 'integration', 'ticket.attachments_rechecked', ?::jsonb)
        ");

        // One CRM ID is in scope, so one CLOG lookup serves every matching ticket.
        $files = $this->clog->lookup($crmId);

        foreach ($tickets as $ticket) {
            $existingQuery->execute([$ticket['id']]);
            $knownRefs = array_fill_keys($existingQuery->fetchAll(PDO::FETCH_COLUMN), true);

            // Informational only: Cron1 may already have stored these files, so this
            // count must not decide promotion.
            $newFiles = [];
            foreach ($files['files'] as $file) {
                $emailId = trim((string)($file['email_id'] ?? ''));
                $filename = (string)($file['filename'] ?? '');
                $sourceRef = 'clog:' . hash('sha256', $emailId . ':' . $filename);
                if (!isset($knownRefs[$sourceRef])) {
                    $newFiles[] = $file;
                    $knownRefs[$sourceRef] = true;
                }
            }

            $this->portalDb->beginTransaction();
            try {
                $stored = $this->documents->synchronize((string)$ticket['id'], $crmId, $files['files']);
                $lookupStatus = $files['email_ids'] === []
                    ? 'no_matching_crm_email'
                    : ($stored === [] ? 'no_files' : 'found');
                $lookupUpdate->execute([
                    $lookupStatus,
                    json_encode([
                        'status' => $lookupStatus,
                        'matched_email_ids' => $files['email_ids'],
                        'file_count' => count($stored),
                        'new_file_count' => count($newFiles),
                    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    $ticket['id'],
                ]);

                $pendingQuery->execute([$ticket['id']]);
                $pendingCount = (int)$pendingQuery->fetchColumn();

                $promoted = false;
                if ($pendingCount > 0 && in_array($ticket['current_status'], self::REPLIED_STATUSES, true)) {
                    $advance->execute([$ticket['id']]);
                    $promoted = $advance->rowCount() === 1;
                }
                $event->execute([
                    $ticket['id'],
                    json_encode([
                        'crm_id' => $crmId,
                        'lookup_status' => $lookupStatus,
                        'new_file_count' => count($newFiles),
                        'pending_document_count' => $pendingCount,
                        'ready_for_ocr' => $promoted,
                    ], JSON_THROW_ON_ERROR),
                ]);
                $this->portalDb->commit();
                $summary['tickets_checked']++;
                $summary['new_files'] += count($newFiles);
                $summary['ready_for_ocr'] += $promoted ? 1 : 0;
            } catch (\Throwable $error) {
                if ($this->portalDb->inTransaction()) {
                    $this->portalDb->rollBack();
                }
                throw $error;
            }
        }

        return $summary;
    }
}
