<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/RpaClient.php';

$client = new RpaClient();
$input = [
    'action_type' => 'request_information',
    'crm' => 'CRM-1793155923-92',
    'email' => 'clientrail@gmail.com',
    'name' => 'Suresh Naiker A/L Sivanayan',
    'id_number' => '840411235085',
    'language' => 'ms',
    'state' => 'SELANGOR',
    'contact_number' => '0139920802',
];
$payload = $client->workflowPayload($input);

// Output the payload for verification
echo "Payload:\n" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

$response = $client->send($payload);

echo "Response:\n" . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
?>

