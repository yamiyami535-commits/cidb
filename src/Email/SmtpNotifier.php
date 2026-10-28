<?php
declare(strict_types=1);
namespace Cidb\Email;

use PHPMailer\PHPMailer\PHPMailer;

final class SmtpNotifier implements Notifier {
    public function __construct(private readonly Config $config) {}
    public function send(array $job): array {
        $mail=new PHPMailer(true);
        $mail->isSMTP(); $mail->SMTPAuth=true;
        $mail->Host=$this->config->get('EMAIL_SMTP_HOST');
        $mail->Port=$this->config->integer('EMAIL_SMTP_PORT',465);
        $mail->SMTPSecure=$this->config->get('EMAIL_SMTP_ENCRYPTION','ssl');
        $mail->Username=$this->config->get('EMAIL_SMTP_USERNAME',$this->config->get('EMAIL_USERNAME'));
        $mail->Password=$this->config->get('EMAIL_SMTP_PASSWORD',$this->config->get('EMAIL_PASSWORD'));
        $mail->Timeout=$this->config->integer('EMAIL_TIMEOUT_SECONDS',15);
        $mail->SMTPOptions=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
        $mail->CharSet='UTF-8'; $mail->isHTML(false);
        $mail->setFrom($this->config->get('EMAIL_FROM_ADDRESS'),$this->config->get('EMAIL_FROM_NAME','Rent-A-Bot'));
        $mail->addAddress($job['recipient']);
        $mail->addCustomHeader('Auto-Submitted','auto-generated');
        $missing=$job['missing_fields']; if (is_string($missing)) $missing=json_decode($missing,true,512,JSON_THROW_ON_ERROR);
        $values=['{request_id}'=>$job['request_id'],'{missing_fields}'=>implode(', ',$missing),
            '{name}'=>$job['applicant_name'] ?? '', '{email}'=>$job['applicant_email'] ?? '',
            '{nric}'=>$job['id_number'] ?? '', '{state}'=>$job['location_area'] ?? '', '{subject}'=>$job['subject'] ?? ''];
        $mail->Subject=str_replace(["\r","\n"],' ',strtr($this->config->get('EMAIL_NOTIFICATION_SUBJECT'),$values));
        $mail->Body=strtr($this->config->get('EMAIL_NOTIFICATION_BODY'),$values);
        try {
            // No message was dispatched if connection/authentication itself fails.
            try { if (!$mail->smtpConnect()) return ['outcome'=>'safe_failure','error_code'=>'SMTP_CONNECT_FAILED']; }
            catch (\Throwable) { return ['outcome'=>'safe_failure','error_code'=>'SMTP_CONNECT_FAILED']; }
            try { $mail->send(); return ['outcome'=>'accepted']; }
            catch (\Throwable) { return ['outcome'=>'uncertain','error_code'=>'SMTP_ACCEPTANCE_UNKNOWN']; }
        } finally { $mail->smtpClose(); }
    }
}
