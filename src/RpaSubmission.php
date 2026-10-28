<?php
declare(strict_types=1);

/** Save submission diagnostics only; never overwrite the RPA-owned status. */
final class RpaSubmission
{
    public static function record(PDO $pdo, string $id, array $response, array $normalized): void
    {
        $q=$pdo->prepare('UPDATE portal_requests SET rpa_http_status=:http,rpa_reference_id=COALESCE(rpa_reference_id,:ref),rpa_response=CAST(:response AS jsonb),rpa_response_text=:raw,error_code=:code,error_detail=:detail,updated_at=now() WHERE id=:id');
        $q->execute(['http'=>$response['http_status'],'ref'=>$normalized['reference'],
            'response'=>$response['parsed']===null?null:json_encode($response['parsed'],JSON_THROW_ON_ERROR),
            'raw'=>$response['raw'],'code'=>$normalized['error_code'],'detail'=>$response['error'],'id'=>$id]);
    }
}
