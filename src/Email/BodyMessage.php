<?php
declare(strict_types=1);
namespace Cidb\Email;

/** MIME decoding only. Attachment contents are never decoded, saved, or extracted. */
final class BodyMessage extends \Webklex\PHPIMAP\Message {
    public function __construct(string $raw) {
        $parts=preg_split('/\r?\n\r?\n/',$raw,2);
        if (count($parts)!==2) throw new \RuntimeException('Malformed MIME message');
        $this->boot();
        $this->parseRawHeader($parts[0]);
        $this->parseRawBody($parts[1]);
        $this->setUid(0);
    }
    protected function fetchAttachment(\Webklex\PHPIMAP\Part $part): void {}
    public function data(): array {
        $header=$this->getHeader();
        $data=[
            'sender'=>(string)($header->get('from')->first()->mail ?? ''),
            'subject'=>iconv_mime_decode((string)$header->get('subject'),ICONV_MIME_DECODE_CONTINUE_ON_ERROR,'UTF-8') ?: (string)$header->get('subject'),
            'message_id'=>(string)$header->get('message_id'),
            'auto_submitted'=>trim((string)$header->get('auto_submitted')) ?: 'no',
            'in_reply_to'=>(string)$header->get('in_reply_to'),
            'is_report'=>str_contains(strtolower((string)$header->get('content_type')),'multipart/report'),
            'text'=>$this->getTextBody(),'html'=>$this->getHTMLBody(),
        ];
        foreach (['sender','subject','message_id'] as $key) {
            if (!mb_check_encoding($data[$key],'UTF-8') || str_contains($data[$key],"\0")) {
                throw new \RuntimeException('Unsupported message header encoding');
            }
        }
        return $data;
    }
}
