<?php
declare(strict_types=1);
namespace Cidb\Email;

use PDO;

final class PdoClogAttachmentProvider implements ClogAttachmentProvider
{
    public function __construct(private readonly PDO $clogDb) {}

    public function lookup(string $crmId): array
    {
        $query = $this->clogDb->prepare("
            SELECT e.id AS email_id, e.uniqid AS crm_id, f.filename
            FROM emails e
            LEFT JOIN files f ON f.email_id = e.id
            WHERE e.uniqid = ?
              AND LOWER(TRIM(e.case_status)) = 'open'
            ORDER BY e.id, f.filename
        ");
        $query->execute([$crmId]);

        $emailIds = [];
        $files = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $emailId = (string)$row['email_id'];
            $emailIds[$emailId] = true;
            if ($row['filename'] !== null) {
                $files[] = [
                    'email_id' => $emailId,
                    'crm_id' => (string)$row['crm_id'],
                    'filename' => (string)$row['filename'],
                ];
            }
        }

        return ['email_ids' => array_keys($emailIds), 'files' => $files];
    }
}
