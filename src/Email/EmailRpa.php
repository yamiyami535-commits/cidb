<?php
declare(strict_types=1);
namespace Cidb\Email;

final class EmailRpa implements Rpa {
    public function __construct(private readonly string $endpoint, private readonly string $key,
        private readonly int $timeoutMs=15000, private readonly int $connectTimeoutMs=5000) {}
    public static function payload(array $fields): array {
        $customer=['sCRMID'=>$fields['crm'],'sEmail'=>$fields['email'],
            'sCustomerName'=>$fields['name'],'sCustomerType'=>'Individual'];
        if (isset($fields['state']) && trim($fields['state'])!=='') $customer['sLocationArea']=$fields['state'];
        $customer['sIdentificationNumber']=$fields['nric'];
        return ['company'=>'CIDB','scenario_key'=>'cidb_masterbot','channel'=>'Email','fields'=>$customer];
    }
    public function send(array $payload): array {
        if (!filter_var($this->endpoint,FILTER_VALIDATE_URL) || $this->key==='') throw new \RuntimeException('RPA configuration missing');
        $ch=curl_init($this->endpoint);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-API-Key: '.$this->key],
            CURLOPT_CONNECTTIMEOUT_MS=>max(1000,$this->connectTimeoutMs),CURLOPT_TIMEOUT_MS=>max(1000,$this->timeoutMs),
            CURLOPT_FOLLOWLOCATION=>false]);
        $body=curl_exec($ch); $errno=curl_errno($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        return self::interpret($http,$body===false?'':$body,$errno);
    }
    public static function interpret(int $http, string $raw, int $errno=0): array {
        $parsed=json_decode($raw,true);
        $base=['http_status'=>$http?:null,'raw'=>$raw,'parsed'=>is_array($parsed)?$parsed:null,'reference'=>null];
        if ($errno!==0) {
            // Only errors proven to occur before dispatch. Timeouts/send/receive errors are uncertain.
            $safe=in_array($errno,[CURLE_COULDNT_RESOLVE_PROXY,CURLE_COULDNT_RESOLVE_HOST,CURLE_COULDNT_CONNECT],true);
            return $base+['outcome'=>$safe?'safe_failure':'uncertain','error_code'=>'CURL_'.$errno];
        }
        if ($http<200 || $http>=300 || !is_array($parsed)) return $base+['outcome'=>'uncertain','error_code'=>'RPA_UNCONFIRMED_RESPONSE'];
        $reference=$parsed['reference_no'] ?? $parsed['schedule_id'] ?? $parsed['data']['schedule_id'] ?? $parsed['inserts'][0]['schedule_id'] ?? null;
        $base['reference']=is_scalar($reference)?(string)$reference:null;
        // HTTP response fields cannot finalize a case; wait for RPA's database status.
        return $base+['outcome'=>'accepted'];
    }
}
