<?php
declare(strict_types=1);

final class RpaHubDirectInserter
{
    private const BOT_INPUT_KEYS = [
        'sCRMID',
        'sEmail',
        'sCustomerName',
        'sIdentificationNumber',
        'sContactNumber',
        'sLanguage',
        'sChannel',
        'sRequest',
        'sCustomerType',
    ];

    public static function buildBotInput(array $input, string $request): array
    {
        if (strtolower(trim((string)($input['customer_type'] ?? ''))) !== 'individual') {
            throw new InvalidArgumentException('RPA Hub payloads are restricted to CLOG-confirmed Individual cases.');
        }
        return [
            'sCRMID' => trim((string)($input['crm'] ?? $input['crm_id'] ?? '')),
            'sEmail' => trim((string)($input['email'] ?? '')),
            'sCustomerName' => trim((string)($input['name'] ?? '')),
            'sIdentificationNumber' => preg_replace('/\D+/', '', (string)($input['id_number'] ?? $input['nric'] ?? '')) ?? '',
            'sContactNumber' => trim((string)($input['contact_number'] ?? $input['phone'] ?? '')),
            'sLanguage' => in_array(strtolower(trim((string)($input['language'] ?? ''))), ['en', 'english'], true) ? 'en' : 'ms',
            'sChannel' => 'Email',
            'sRequest' => $request,
            'sCustomerType' => 'Individual',
        ];
    }

    /** @param array<string, string> $botInput */
    public function insert(array $botInput): array
    {
        if (array_keys($botInput) !== self::BOT_INPUT_KEYS) {
            throw new InvalidArgumentException('Bot_Input must use the approved nine keys in the approved order.');
        }
        foreach ($botInput as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Every Bot_Input value must be a string.');
            }
        }
        if ($botInput['sCRMID'] === '' || !str_starts_with($botInput['sCRMID'], 'CRM-')) {
            throw new InvalidArgumentException('A valid CLOG CRM ID is required for direct RPA insertion.');
        }
        if (strtolower(trim($botInput['sCustomerType'])) !== 'individual') {
            throw new InvalidArgumentException('Direct CIDB cancellation/RPA insertion is Individual-only.');
        }
        if ($botInput['sChannel'] !== 'Email'
            || !in_array($botInput['sRequest'], ['Email Cancel', 'Pending Customer Attachment'], true)) {
            throw new InvalidArgumentException('Unsupported CIDB Email RPA request.');
        }
        if ($botInput['sRequest'] === 'Email Cancel'
            && (trim($botInput['sCustomerName']) === '' || trim($botInput['sIdentificationNumber']) === '')) {
            throw new InvalidArgumentException('Email Cancel requires the CLOG customer name and NRIC.');
        }
        if ($botInput['sRequest'] === 'Email Cancel') {
            $this->assertCancellationHasVerifiedDocument($botInput['sCRMID']);
        }

        $pdo = $this->connect();
        $lockName = 'cidb-rpa-' . substr(hash('sha256', $botInput['sCRMID'] . ':' . $botInput['sRequest']), 0, 48);
        try {
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
            $lock->execute([$lockName]);
            if ((int)$lock->fetchColumn() !== 1) {
                throw new RuntimeException('RPA Hub direct-insert lock is busy; retry on the next cron run.');
            }
            $pdo->beginTransaction();
            $scenarioQuery = $pdo->prepare(
                "SELECT hardcoded_json FROM ticket_scenarios "
                . "WHERE company = 'CIDB' AND scenario_key = 'cidb_masterbot' AND is_active = 1 LIMIT 1"
            );
            $scenarioQuery->execute();
            $scenarioRow = $scenarioQuery->fetch(PDO::FETCH_ASSOC);
            if (!$scenarioRow) {
                throw new RuntimeException('The active CIDB masterbot scenario is missing from RPA Hub.');
            }
            $hardcoded = json_decode((string)$scenarioRow['hardcoded_json'], true, 512, JSON_THROW_ON_ERROR);
            $playId = (int)($hardcoded['PlayID'] ?? 0);
            if ($playId < 1 || (int)($hardcoded['BotID'] ?? 0) !== 118) {
                throw new RuntimeException('The CIDB masterbot is not configured for BotID 118.');
            }

            $playlistQuery = $pdo->prepare(
                'SELECT ScheduleName, GroupID, CompanyID, BotID FROM bot_playlist WHERE ID = ? LIMIT 1'
            );
            $playlistQuery->execute([$playId]);
            $playlist = $playlistQuery->fetch(PDO::FETCH_ASSOC);
            if (!$playlist || (int)$playlist['BotID'] !== 118) {
                throw new RuntimeException('The live CIDB playlist is missing or has the wrong BotID.');
            }

            $runnerQuery = $pdo->prepare(
                'SELECT RunnerName, RunnerID, ControlRoomIP FROM rpa_runner WHERE RunnerName = ? LIMIT 1'
            );
            $runnerQuery->execute(['BR20']);
            $runner = $runnerQuery->fetch(PDO::FETCH_ASSOC);
            if (!$runner || trim((string)$runner['RunnerID']) === '') {
                throw new RuntimeException('The live BR20 runner has no RunnerID configured.');
            }

            $duplicateQuery = $pdo->prepare(
                "SELECT id, ScheduleID FROM bot_scheduler "
                . "WHERE CASE WHEN JSON_VALID(Bot_Input) "
                . "THEN JSON_UNQUOTE(JSON_EXTRACT(Bot_Input, '$.sCRMID')) ELSE NULL END = ? "
                . "AND CASE WHEN JSON_VALID(Bot_Input) "
                . "THEN JSON_UNQUOTE(JSON_EXTRACT(Bot_Input, '$.sRequest')) ELSE NULL END = ? "
                . 'ORDER BY id DESC LIMIT 1'
            );
            $duplicateQuery->execute([$botInput['sCRMID'], $botInput['sRequest']]);
            $duplicate = $duplicateQuery->fetch(PDO::FETCH_ASSOC);
            if ($duplicate) {
                $pdo->commit();
                return [
                    'status' => 'duplicate',
                    'schedule_id' => (string)$duplicate['ScheduleID'],
                    'runner_name' => (string)$runner['RunnerName'],
                    'runner_id' => (string)$runner['RunnerID'],
                ];
            }

            $scheduleId = random_int(0, 100) . '-' . time() . '-' . random_int(0, 100);
            $botInputJson = json_encode($botInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $insert = $pdo->prepare("
                INSERT INTO bot_scheduler (
                    ScheduleType, GlobalStatus, ScheduleID, ScheduleName,
                    ScheduleDateTime, ScheduleDateTimeE,
                    PlayOrder, PlayID, GroupID, CompanyID, BotID, BotType,
                    ControlRoomIP, RunnerName, RunnerID, Bot_Input,
                    DigitalWorkerType, CreatedBy, CreatedDateTime, AssignedDateTime,
                    CompletionStatus, RecordingRequired, Intervention
                ) VALUES (
                    'Production', 'RUN', ?, ?, NOW(), NOW(),
                    ?, ?, ?, ?, 118, 'Automation Anywhere',
                    ?, ?, ?, ?, 'Dedicated - On Premise', 'cidb_cron', NOW(), NOW(),
                    'PENDING', 'No', 'No'
                )
            ");
            $insert->execute([
                $scheduleId,
                (string)$playlist['ScheduleName'],
                (int)($hardcoded['PlayOrder'] ?? 1),
                $playId,
                (string)$playlist['GroupID'],
                (int)$playlist['CompanyID'],
                (string)$runner['ControlRoomIP'],
                (string)$runner['RunnerName'],
                (string)$runner['RunnerID'],
                $botInputJson,
            ]);

            $audit = $pdo->prepare(
                'INSERT INTO hub_audit_log '
                . '(actor_username, action, resource_type, resource_id, success, client_ip, detail_json) '
                . 'VALUES (?, ?, ?, ?, 1, NULL, ?)'
            );
            $audit->execute([
                'cidb_cron',
                'external_ticket_insert.direct_db',
                'bot_scheduler',
                $scheduleId,
                json_encode([
                    'company' => 'CIDB',
                    'scenario' => 'cidb_masterbot',
                    'runner_name' => $runner['RunnerName'],
                    'runner_id' => $runner['RunnerID'],
                    'bot_input_fields' => array_keys($botInput),
                ], JSON_THROW_ON_ERROR),
            ]);
            $pdo->commit();

            return [
                'status' => 'inserted',
                'schedule_id' => $scheduleId,
                'runner_name' => (string)$runner['RunnerName'],
                'runner_id' => (string)$runner['RunnerID'],
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
            }
            $pdo = null;
        }
    }

    private function connect(): PDO
    {
        $config = [
            'host' => env_value('RPA_HUB_DB_HOST'),
            'port' => env_value('RPA_HUB_DB_PORT', '3306'),
            'database' => env_value('RPA_HUB_DB_DATABASE'),
            'username' => env_value('RPA_HUB_DB_USERNAME'),
            'password' => env_value('RPA_HUB_DB_PASSWORD'),
        ];

        if (in_array('', [$config['host'], $config['database'], $config['username'], $config['password']], true)) {
            $envFile = env_value('RPA_HUB_ENV_FILE');
            if ($envFile !== '' && is_file($envFile) && is_readable($envFile)) {
                $hubEnv = [];
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$key, $value] = explode('=', $line, 2);
                    $hubEnv[trim($key)] = trim(trim($value), "\"'");
                }
                $config = [
                    'host' => $config['host'] !== '' ? $config['host'] : ($hubEnv['MYSQL_HOST'] ?? ''),
                    'port' => $config['port'] !== '3306' ? $config['port'] : ($hubEnv['MYSQL_PORT'] ?? '3306'),
                    'database' => $config['database'] !== '' ? $config['database'] : ($hubEnv['MYSQL_DB'] ?? ''),
                    'username' => $config['username'] !== '' ? $config['username'] : ($hubEnv['MYSQL_USER'] ?? ''),
                    'password' => $config['password'] !== '' ? $config['password'] : ($hubEnv['MYSQL_PASSWORD'] ?? ''),
                ];
            }
        }
        if (in_array('', [$config['host'], $config['database'], $config['username'], $config['password']], true)) {
            throw new RuntimeException('RPA Hub MySQL connection settings are not configured.');
        }

        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], (int)$config['port'], $config['database']),
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
    }

    private function assertCancellationHasVerifiedDocument(string $crmId): void
    {
        if (!function_exists('db')) {
            throw new RuntimeException('Portal database bootstrap is required before an Email Cancel insert.');
        }

        $query = db()->prepare(<<<'SQL'
            SELECT count(d.id) FILTER (WHERE d.reference_status = 'ready') AS ready_document_count,
                   COALESCE(
                       bool_and(
                           d.ocr_status IN ('valid', 'manual_review')
                           AND COALESCE(d.ocr_result->>'identity_check_verified' = 'true', false)
                       ) FILTER (WHERE d.reference_status = 'ready'),
                       false
                   ) AS all_documents_verified
            FROM portal_tickets t
            LEFT JOIN portal_ticket_documents d ON d.ticket_id = t.id
            LEFT JOIN cidb_email_messages m ON m.id = d.email_message_id
            WHERE t.source_type = 'clog'
              AND t.customer_type = 'individual'
              AND t.scenario_key = 'cancellation-individual'
              AND t.clog_crm_id = ?
              AND (d.id IS NULL OR (
                  m.source_system = 'clog'
                  AND LOWER(TRIM(COALESCE(m.source_email_type, ''))) IN ('new email', 'reply mail')
              ))
            SQL);
        $query->execute([$crmId]);
        $result = $query->fetch(PDO::FETCH_ASSOC);
        $allDocumentsVerified = $result !== false
            && ($result['all_documents_verified'] === true
                || in_array(strtolower(trim((string)$result['all_documents_verified'])), ['1', 't', 'true', 'yes'], true));

        if (!$result
            || (int)$result['ready_document_count'] < 1
            || !$allDocumentsVerified) {
            throw new InvalidArgumentException(
                'Email Cancel requires at least one ready portal document and verified OCR identity for every ready document.'
            );
        }
    }
}
