<?php
declare(strict_types=1);

// RPA owns status after insertion. HTTP responses and legacy display messages
// never determine the business outcome for either request source.
function request_result(array $request): array
{
    $status=(string)($request['status'] ?? 'processing');
    $complete=in_array($status,['success','failed'],true);
    $message=match($status) {
        'success'=>'Success', 'failed'=>'Failed',
        default=>'In progress',
    };
    $stage=$request['email_stage'] ?? '';
    if (!$complete && ($request['request_source'] ?? 'form')==='email') {
        // Local intake/transport problems are separate from the RPA-owned status.
        if ($stage==='missing_fields') {
            $missing=$request['email_missing_fields'] ?? [];
            if (is_string($missing)) $missing=json_decode($missing,true) ?: [];
            $message='Missing fields: '.implode(', ',$missing).'. RPA not submitted.';
            $complete=true;
        } elseif (in_array($stage,['extraction_attention','read_error','submission_uncertain','submission_failed'],true)) {
            $message=match($stage) {
                'extraction_attention'=>'Email extraction needs review. RPA not submitted.',
                'read_error'=>'The email could not be read or parsed. RPA not submitted.',
                'submission_uncertain'=>'RPA acceptance is uncertain. Verify the request before retrying.',
                default=>'RPA submission failed. No further automatic retry will be made.'
            };
            $complete=$stage!=='submission_uncertain';
        } elseif ($stage==='retry_due') $message='RPA could not be reached. One retry is scheduled.';
    } elseif (!$complete && !empty($request['error_code'])) {
        $message='Submission needs review. Waiting for an RPA status update.';
    }
    $terminal=in_array($status,['success','failed'],true);
    return ['id'=>$request['id'],'status'=>$status,'complete'=>$complete,'message'=>$message,
        'completed_at'=>$terminal ? (!empty($request['completed_at']) ? date('d M Y · H:i',strtotime($request['completed_at'])) : 'Not provided by RPA') : ($complete?'Not submitted':'In progress')];
}
