<?php
declare(strict_types=1);
namespace Cidb\Email;

final class FieldExtractor implements Extractor {
    public const LABELS=['name'=>'Name','email'=>'Email to Cancel ID','nric'=>'NRIC','state'=>'State','crm'=>'CRM'];
    public function extract(array $message): array {
        $text=(string)($message['text'] ?? '');
        $html=(string)($message['html'] ?? '');
        if (str_contains($text,"\0") || str_contains($html,"\0")) throw new \RuntimeException('Unsupported body character');
        $text=trim($text);
        // Quoted/forwarded requests require a confirmed policy; never select an identity silently.
        $ambiguous=(bool)preg_match('/(?mi)^\s*>|^\s*-{2,}\s*(?:Original|Forwarded) message|^On .+wrote:|^\s*(?:From|Sent|To|Subject):/', $text)
            || (bool)preg_match('/<blockquote\b|(?:gmail_quote|divRplyFwdMsg|yahoo_quoted)/i', $html);
        if ($text==='') $text=$this->htmlText($html);
        $text=str_replace(["\r\n","\r","\xc2\xa0"],["\n","\n",' '],$text);
        if (!mb_check_encoding($text,'UTF-8')) throw new \RuntimeException('Invalid body encoding');
        if (str_contains($text,"\0")) throw new \RuntimeException('Unsupported body character');
        $fields=[]; $missing=[];
        foreach (self::LABELS as $key=>$label) {
            $pattern=$key==='crm' ? '(?:CRM(?:[\t ]*ID)?|sCRMID)' : preg_quote($label,'/');
            preg_match_all('/^[\t ]*'.$pattern.'[\t ]*:[\t ]*([^\n]*)$/miu',$text,$matches);
            $values=array_map('trim',$matches[1]);
            if (count($values)>1) $ambiguous=true;
            $value=count($values)===1 ? $values[0] : '';
            $fields[$key]=$value!=='' ? $value : null;
            if ($key==='email' && $fields[$key]!==null) {
                $fields[$key]=$this->cleanEmail($fields[$key]);
                if ($fields[$key]===null) $ambiguous=true;
            }
            if ($fields[$key]===null && $key!=='state') $missing[]=$label;
        }
        // Storage limits, not invented customer validation. Oversized fields need review, not truncation.
        foreach (['name'=>200,'email'=>254,'nric'=>40,'crm'=>255] as $key=>$limit) {
            if (mb_strlen($fields[$key] ?? '')>$limit) {
                $ambiguous=true;
                if ($key==='crm' && !in_array('CRM',$missing,true)) $missing[]='CRM';
            }
        }
        return ['fields'=>$fields,'missing'=>$missing,'attention'=>$ambiguous];
    }
    private function cleanEmail(string $value): ?string {
        $value=trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Preserve mailbox identity: normalize the domain only, not the local part.
        $normalize=static function(string $email): string {
            $at=strrpos($email,'@');
            return substr($email,0,$at+1).strtolower(substr($email,$at+1));
        };
        if (filter_var($value,FILTER_VALIDATE_EMAIL)!==false) return $normalize($value);
        // Mail clients can append <mailto:address> to visible address text.
        // Decode only mailto targets; query parameters are not recipient addresses.
        $value=preg_replace_callback('~mailto:([^\s<>"\[\]()]+)~iu',
            static fn(array $match): string => rawurldecode(explode('?',$match[1],2)[0]),$value);
        $addresses=[];
        foreach (preg_split('~[\s<>()[\],;:"]+~u',$value,-1,PREG_SPLIT_NO_EMPTY) as $token) {
            if (!str_contains($token,'@')) continue;
            $candidate=rtrim($token,'.!?');
            // Validate whole tokens so malformed addresses cannot yield a valid substring.
            if (filter_var($candidate,FILTER_VALIDATE_EMAIL)===false) return null;
            $addresses[$normalize($candidate)]=true;
        }
        // Repeated display/link addresses are fine; distinct recipients need review.
        return count($addresses)===1 ? array_key_first($addresses) : null;
    }
    private function htmlText(string $html): string {
        if ($html==='') return '';
        $old=libxml_use_internal_errors(true);
        try {
            $doc=new \DOMDocument();
            if (!$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) throw new \RuntimeException('Invalid HTML body');
            $xpath=new \DOMXPath($doc);
            foreach ($xpath->query('//script|//style|//head') as $node) $node->parentNode?->removeChild($node);
            foreach ($xpath->query('//br') as $node) $node->parentNode?->replaceChild($doc->createTextNode("\n"),$node);
            // Label/value cells stay on one line; rows and paragraphs retain their boundaries.
            foreach ($xpath->query('//td|//th') as $node) $node->appendChild($doc->createTextNode(' '));
            foreach ($xpath->query('//tr|//p|//div|//li') as $node) $node->appendChild($doc->createTextNode("\n"));
            $text=$doc->textContent;
            return $text;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    }
}
