<?php
declare(strict_types=1);

final class RpaClient
{
    public function payload(array $input): array
    {
        $language = in_array($input['language'] ?? null, ['en', 'ms'], true) ? $input['language'] : 'ms';
        return [
            'company' => env_value('RPA_COMPANY', 'CIDB'),
            'scenario_key' => env_value('RPA_SCENARIO_KEY', 'cidb_masterbot'),
            'channel' => 'Email',
            'fields' => [
                'sCustomerType' => 'Individual',
                'sEmail' => $input['email'],
                'sCustomerName' => $input['name'],
                'sIdentificationNumber' => $input['id_number'],
                'sLanguage' => $language,
                'sChannel' => 'Email',
                'sCRMID' => $input['crm'],
            ],
        ];
    }

    public function workflowPayload(array $input): array
    {
        $actionType = $input['action_type'] ?? null;
        if (!in_array($actionType, ['request_information', 'execute_cancellation'], true)) {
            throw new InvalidArgumentException('Unsupported RPA workflow action.');
        }

        $languageValue = strtolower(trim((string)($input['language'] ?? '')));
        $language = match ($languageValue) {
            'en', 'english' => 'en',
            'ms', 'malay', 'bahasa melayu' => 'ms',
            default => null,
        };
        if ($language === null) {
            throw new InvalidArgumentException('RPA workflow requires an explicit English or Malay language.');
        }
        $customerType = strtolower((string)($input['customer_type'] ?? ''));
        if ($actionType === 'execute_cancellation' && !in_array($customerType, ['individual', 'company'], true)) {
            throw new InvalidArgumentException('RPA workflow requires a verified customer type.');
        }

        $fields = [
            'sCRMID' => $input['crm'] ?? $input['crm_id'] ?? null,
            'sEmail' => $input['email'] ?? null,
            'sCustomerName' => $input['name'] ?? null,
            'sLanguage' => $language,
            'sChannel' => 'Email',
        ];
        if (in_array($customerType, ['individual', 'company'], true)) {
            $fields['sCustomerType'] = ucfirst($customerType);
        }
        foreach ([
            'sIdentificationNumber' => ['id_number', 'nric'],
            'sState' => ['state'],
            'sContactNumber' => ['contact_number', 'phone'],
            'sFilename' => ['filename'],
            'sOCRStatus' => ['ocr_status'],
        ] as $key => $candidateKeys) {
            foreach ($candidateKeys as $candidateKey) {
                $value = trim((string)($input[$candidateKey] ?? ''));
                if ($value !== '') {
                    $fields[$key] = $value;
                    break;
                }
            }
        }

        if ($actionType === 'request_information') {
            $fields['sRequest'] = 'Pending Customer Attachment';
        } else {
            $fields['sAction'] = 'Email Cancellation';
        }

        foreach ($fields as $key => $value) {
            if ($value === null || trim((string)$value) === '') {
                unset($fields[$key]);
            } else {
                $fields[$key] = trim((string)$value);
            }
        }

        $required = $actionType === 'request_information'
            ? ['sCRMID', 'sEmail', 'sCustomerName', 'sLanguage', 'sRequest']
            : [
                'sCRMID', 'sEmail', 'sCustomerName', 'sCustomerType',
                'sIdentificationNumber', 'sLanguage', 'sOCRStatus', 'sAction',
            ];
        $missing = array_values(array_filter($required, static fn(string $key): bool => !isset($fields[$key])));
        if ($missing !== []) {
            throw new InvalidArgumentException(
                'RPA workflow payload is missing required fields: ' . implode(', ', $missing)
            );
        }

        return [
            'company' => env_value('RPA_COMPANY', 'CIDB'),
            'scenario_key' => env_value('RPA_SCENARIO_KEY', 'cidb_masterbot'),
            'channel' => 'Email',
            'fields' => $fields,
        ];
    }

    public function send(array $payload): array
    {
        $endpoint = env_value('RPA_BOT_ENDPOINT');
        $key = env_value('RPA_BOT_API_KEY');
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || $key === '') throw new RuntimeException('RPA integration is not configured.');
        $ch = curl_init($endpoint);
        if ($ch === false) throw new RuntimeException('Unable to start the RPA request.');
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload, JSON_THROW_ON_ERROR), CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: */*','X-API-Key: '.$key],
            CURLOPT_CONNECTTIMEOUT_MS=>max(1000,(int)env_value('RPA_BOT_CONNECT_TIMEOUT_MS','5000')),
            CURLOPT_TIMEOUT_MS=>max(1000,(int)env_value('RPA_BOT_TIMEOUT_MS','15000'))]);
        $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
        if ($body === false) return ['http_status'=>$status ?: null,'raw'=>'','parsed'=>null,'error'=>'RPA network request failed: '.$curlError];
        $parsed = json_decode((string)$body, true);
        return ['http_status'=>$status ?: null,'raw'=>(string)$body,'parsed'=>is_array($parsed)?$parsed:null,'error'=>($status < 200 || $status >= 300) ? 'RPA returned HTTP '.$status : null];
    }

    public function normalize(array $response): array
    {
        $p = $response['parsed'];
        $scheduleId = $p['schedule_id'] ?? $p['data']['schedule_id'] ?? $p['inserts'][0]['schedule_id'] ?? $p['data']['inserts'][0]['schedule_id'] ?? null;
        $ack = strtolower((string)($p['status'] ?? $p['data']['status'] ?? '')) === 'inserted';
        $ref = $p['external_reference_no'] ?? $p['reference_no'] ?? $p['ticket_no'] ?? $p['ticket_number'] ?? $p['data']['external_reference_no'] ?? $p['data']['reference_no'] ?? $p['data']['ticket_no'] ?? $p['data']['ticket_number'] ?? null;
        $ref ??= $scheduleId;
        // Submission diagnostics only. Final status comes from RPA's database update.
        $error=$response['error']!==null || !$response['http_status'] || $p===null;
        return ['reference'=>is_scalar($ref) ? (string)$ref : null,'acknowledgement'=>$ack,
            'error_code'=>$error?'RPA_REQUEST_FAILED':null];
    }
}
