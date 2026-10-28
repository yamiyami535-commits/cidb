<?php
declare(strict_types=1);
namespace Cidb\Ticketing;

use PDO;

final class TicketManualReview
{
    public function __construct(private readonly PDO $pdo) {}

    public function classifyIndividual(string $crmId, string $reviewer): array
    {
        return $this->transaction(function () use ($crmId, $reviewer): array {
            $user = $this->reviewer($reviewer);
            $ticket = $this->ticket($crmId);
            if (!in_array($ticket['workflow_status'], ['intake_pending', 'needs_information', 'ready_for_ocr'], true)) {
                throw new \RuntimeException('Only an unclassified intake ticket can be classified as individual.');
            }
            if (strtolower(trim((string)$ticket['clog_status'])) !== 'open') {
                throw new \RuntimeException('The linked CLOG case must remain Open.');
            }
            if ($ticket['customer_type'] === 'company') {
                throw new \RuntimeException('Company tickets are outside the current individual SIT scope.');
            }

            $bucket = $this->pdo->prepare("
                SELECT id FROM portal_ticket_buckets
                WHERE scenario_key = 'cancellation-individual'
                  AND is_default = true AND is_active = true
            ");
            $bucket->execute();
            $bucketId = $bucket->fetchColumn();
            if ($bucketId === false) {
                throw new \RuntimeException('The individual cancellation bucket is not configured.');
            }
            $files = $this->pdo->prepare("
                SELECT count(*) FROM portal_ticket_documents
                WHERE ticket_id = ? AND reference_status = 'ready'
            ");
            $files->execute([$ticket['id']]);
            $workflowStatus = (int)$files->fetchColumn() > 0 ? 'ready_for_ocr' : 'needs_information';

            $update = $this->pdo->prepare("
                UPDATE portal_tickets
                SET customer_type = 'individual',
                    scenario_key = 'cancellation-individual',
                    bucket_id = ?,
                    assigned_to = ?,
                    workflow_status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $update->execute([$bucketId, $user['id'], $workflowStatus, $ticket['id']]);
            $this->pdo->prepare("
                UPDATE cidb_interaction_data SET customer_type = 'individual', updated_at = NOW()
                WHERE id = ?
            ")->execute([$ticket['interaction_id']]);
            $this->event($ticket['id'], $user['id'], 'ticket.individual_classified', [
                'workflow_status' => $workflowStatus,
            ]);
            return ['ticket_id' => $ticket['id'], 'workflow_status' => $workflowStatus];
        });
    }

    public function approve(string $crmId, string $reviewer, bool $queueRpa): array
    {
        return $this->transaction(function () use ($crmId, $reviewer, $queueRpa): array {
            $user = $this->reviewer($reviewer);
            $ticket = $this->ticket($crmId);
            $this->assertReviewable($ticket);
            $documents = $this->pdo->prepare("
                SELECT count(*) AS file_count,
                       bool_and(
                           d.ocr_status IN ('manual_review', 'valid')
                           AND COALESCE(d.ocr_result->>'identity_check_verified' = 'true', false)
                       ) AS identity_matches
                FROM portal_ticket_documents d
                WHERE d.ticket_id = ? AND d.reference_status = 'ready'
            ");
            $documents->execute([$ticket['id']]);
            $result = $documents->fetch(PDO::FETCH_ASSOC);
            if ((int)$result['file_count'] < 1 || !$this->asBool($result['identity_matches'])) {
                throw new \RuntimeException('Approval requires OCR identity matches for every ready attachment.');
            }

            $reviewedAt = gmdate('c');
            $approval = [
                'manual_approval_status' => 'approved',
                'approved_by' => $reviewer,
                'approved_at' => $reviewedAt,
                'ocr_status' => 'MANUAL_REVIEW_APPROVED',
                'customer_type' => 'individual',
            ];
            $this->pdo->prepare("
                UPDATE portal_tickets
                SET workflow_status = 'ready_for_execution',
                    execution_result = (
                        COALESCE(execution_result, '{}'::jsonb)
                        - ARRAY['rejected_by', 'rejected_at', 'review_reason']
                    ) || ?::jsonb,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                json_encode($approval, JSON_THROW_ON_ERROR),
                $ticket['id'],
            ]);

            $state = $queueRpa ? 'queued' : 'manual_review';
            $dedupeKey = 'ticket:' . $ticket['id'] . ':execute-cancellation:' . hash('sha256', $reviewedAt);
            $action = $this->pdo->prepare("
                INSERT INTO portal_rpa_action_queue
                    (ticket_id, action_type, dedupe_key, state, payload)
                VALUES (?, 'execute_cancellation', ?, ?, ?::jsonb)
                RETURNING id
            ");
            $action->execute([
                $ticket['id'],
                $dedupeKey,
                $state,
                json_encode(['manual_review_approved' => true], JSON_THROW_ON_ERROR),
            ]);
            $actionId = $action->fetchColumn();
            $this->event($ticket['id'], $user['id'], 'ticket.manual_approval_recorded', [
                'action_id' => $actionId,
                'rpa_action_state' => $state,
            ]);
            return [
                'ticket_id' => $ticket['id'],
                'action_id' => $actionId,
                'workflow_status' => 'ready_for_execution',
                'rpa_action_state' => $state,
            ];
        });
    }

    public function reject(string $crmId, string $reviewer, string $reason): array
    {
        return $this->transaction(function () use ($crmId, $reviewer, $reason): array {
            $user = $this->reviewer($reviewer);
            $ticket = $this->ticket($crmId);
            $this->assertReviewable($ticket);
            $this->pdo->prepare("
                UPDATE portal_tickets
                SET workflow_status = 'ocr_rejected',
                    execution_result = (
                        COALESCE(execution_result, '{}'::jsonb)
                        - ARRAY['approved_by', 'approved_at', 'ocr_status']
                    ) || ?::jsonb,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                json_encode([
                    'manual_approval_status' => 'rejected',
                    'rejected_by' => $reviewer,
                    'rejected_at' => gmdate('c'),
                    'review_reason' => $reason,
                ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                $ticket['id'],
            ]);
            $this->event($ticket['id'], $user['id'], 'ticket.manual_review_rejected', [
                'reason' => $reason,
            ]);
            return ['ticket_id' => $ticket['id'], 'workflow_status' => 'ocr_rejected'];
        });
    }

    private function assertReviewable(array $ticket): void
    {
        if ($ticket['customer_type'] !== 'individual') {
            throw new \RuntimeException('SIT manual approval is restricted to individually classified tickets.');
        }
        if ($ticket['workflow_status'] !== 'ocr_manual_review') {
            throw new \RuntimeException('Ticket must be in ocr_manual_review before approval or rejection.');
        }
        if ($ticket['assigned_to'] === null) {
            throw new \RuntimeException('Assign the ticket to a portal reviewer before manual review.');
        }
        if (strtolower(trim((string)$ticket['clog_status'])) !== 'open') {
            throw new \RuntimeException('The linked CLOG case must remain Open.');
        }
    }

    private function reviewer(string $username): array
    {
        $query = $this->pdo->prepare("
            SELECT id, username FROM portal_users
            WHERE lower(username) = lower(?) AND is_active = true AND role IN ('admin', 'manager')
            LIMIT 1
        ");
        $query->execute([$username]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new \RuntimeException('Reviewer must be an active portal admin or manager username.');
        }
        return $user;
    }

    private function ticket(string $crmId): array
    {
        if (preg_match('/^CRM-[A-Za-z0-9-]{1,60}$/', $crmId) !== 1) {
            throw new \InvalidArgumentException('Provide one valid CRM ID.');
        }
        $query = $this->pdo->prepare("
            SELECT t.id, t.customer_type, t.workflow_status, t.assigned_to,
                   COALESCE(t.clog_status, i.clog_status) AS clog_status,
                   t.interaction_id
            FROM portal_tickets t
            JOIN cidb_interaction_data i ON i.id = t.interaction_id
            WHERE t.clog_crm_id = ? AND t.source_type = 'clog'
            FOR UPDATE OF t
        ");
        $query->execute([$crmId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new \RuntimeException('Expected exactly one linked CLOG ticket for this CRM ID.');
        }
        return $rows[0];
    }

    private function event(string $ticketId, string $userId, string $eventType, array $metadata): void
    {
        $this->pdo->prepare("SELECT set_config('app.actor_user_id', ?, true)")->execute([$userId]);
        $this->pdo->prepare("
            INSERT INTO portal_ticket_events
                (ticket_id, actor_user_id, actor_type, event_type, metadata)
            VALUES (?, ?, 'user', ?, ?::jsonb)
        ")->execute([
            $ticketId,
            $userId,
            $eventType,
            json_encode($metadata, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    private function transaction(callable $work): array
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function asBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
