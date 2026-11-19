<?php
declare(strict_types=1);

const TICKET_STATUSES = [
    'intake_pending'=>'New / intake review', 'needs_information'=>'Needs information',
    'ready_for_ocr'=>'Ready for OCR', 'ready_for_execution'=>'Ready for execution',
    'execution_processing'=>'Execution in progress', 'completed'=>'Completed',
    'failed'=>'Failed', 'closed'=>'Closed',
];
const TICKET_TABS = ['details'=>'Ticket Details', 'conversation'=>'Conversation', 'activity'=>'Ticketing Activity', 'history'=>'Ticketing History', 'audit'=>'Audit Log', 'documents'=>'Documents & OCR', 'actions'=>'RPA Actions'];

final class TicketAccessDenied extends RuntimeException {}

function ticket_uuid(string $value): bool
{
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value) === 1;
}

function ticket_input(array $input, string $key, string $default = ''): string
{
    if (!isset($input[$key])) return $default;
    if (!is_string($input[$key])) throw new InvalidArgumentException('Invalid '.str_replace('_', ' ', $key).'.');
    return trim($input[$key]);
}

function ticket_filters(array $input): array
{
    $f = [];
    foreach (['status'=>'active', 'source'=>'', 'agent'=>'', 'bucket'=>'', 'sort'=>'updated_desc', 'page'=>'1', 'per_page'=>'20', 'clog_open'=>''] as $key=>$default) {
        $f[$key] = ticket_input($input, $key, $default);
    }
    if (!in_array($f['status'], array_merge(['active','all'], array_keys(TICKET_STATUSES)), true)) throw new InvalidArgumentException('Choose a supported workflow status.');
    if (!in_array($f['source'], ['', 'clog', 'email', 'portal'], true)) throw new InvalidArgumentException('Choose a supported source.');
    if (!in_array($f['sort'], ['updated_desc','created_desc','created_asc'], true)) throw new InvalidArgumentException('Choose a supported sort order.');
    if (!in_array($f['per_page'], ['20','50','100'], true)) throw new InvalidArgumentException('Choose 20, 50 or 100 tickets per page.');
    if (!ctype_digit($f['page']) || (int)$f['page'] < 1 || strlen($f['page']) > 7) throw new InvalidArgumentException('Invalid page number.');
    foreach (['agent','bucket'] as $key) {
        if ($f[$key] !== '' && !($key === 'agent' && $f[$key] === 'unassigned') && !ticket_uuid($f[$key])) throw new InvalidArgumentException('Invalid '.$key.' filter.');
    }
    if (!in_array($f['clog_open'], ['', '1'], true)) throw new InvalidArgumentException('Invalid CLOG filter.');
    if ($f['clog_open'] === '1' && !in_array($f['source'], ['', 'clog'], true)) throw new InvalidArgumentException('Active CLOG work requires the CLOG source.');
    $f['page'] = (int)$f['page']; $f['per_page'] = (int)$f['per_page'];
    return $f;
}

function ticket_json(mixed $value): array
{
    if (is_array($value)) return $value;
    if (!is_string($value)) return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

// Initial views and payloads always mask identity values; explicit reveal uses a staff-only endpoint.
function ticket_mask_id(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') return 'Not provided';
    return mb_strlen($value) <= 4 ? str_repeat('*', mb_strlen($value)) : str_repeat('*', mb_strlen($value)-4).mb_substr($value, -4);
}

function ticket_private_text(string $value, array $identities = []): string
{
    if (ticket_uuid($value)) return $value;
    foreach ($identities as $identity) {
        if (is_scalar($identity) && strlen(trim((string)$identity)) >= 5) $value = str_replace((string)$identity, ticket_mask_id($identity), $value);
    }
    return preg_replace_callback('/(?<![\w*\-])\d{6}[- ]?\d{2}[- ]?\d{4}(?![\w\-])/', fn($m)=>ticket_mask_id($m[0]), $value) ?? '';
}

function ticket_safe_data(mixed $value, array $identities = [], string $key = ''): mixed
{
    if (preg_match('/password|secret|token|api.?key|authorization|retrieval_url|storage_key|raw_message|body_html/i', $key)) return '[not displayed]';
    if (preg_match('/nric|id.?number|identification|passport|identity.?number/i', $key)) return ticket_mask_id(is_scalar($value) ? $value : '');
    // CRM, ticket and source identifiers are references, not personal identity numbers.
    // Preserve them exactly so numeric CRM IDs still round-trip through search and links.
    if (is_scalar($value) && preg_match('/(^id$|_id$|^source_ref$|^external_reference$)/i', $key)) return $value;
    if (is_array($value)) {
        foreach ($value as $k=>$v) $value[$k] = ticket_safe_data($v, $identities, (string)$k);
    } elseif (is_string($value)) {
        // Nested JSON strings are common in integration payloads.
        $decoded = json_decode($value, true);
        $value = is_array($decoded)
            ? json_encode(ticket_safe_data($decoded, $identities), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            : ticket_private_text($value, $identities);
    }
    return $value;
}

function ticket_normalize(array $row): array
{
    foreach (['missing_fields','documents','actions','assigned_user','portal_request','email_intake','interaction_details','email_thread','ticket_history','ocr_result','execution_result','attachment_lookup_result','tickets','email_threads','clog_records'] as $key) {
        if (array_key_exists($key, $row)) $row[$key] = ticket_json($row[$key]);
    }
    $identities = array_filter([$row['id_number']??null, $row['nric']??null, $row['interaction_details']['nric']??null]);
    // Never send source payloads, file references or raw mailbox bodies to the view.
    unset($row['source_data'], $row['attachment_refs']);
    if (isset($row['email_thread']['messages'])) {
        foreach ($row['email_thread']['messages'] as &$message) {
            if (empty($message['body_text']) && !empty($message['body_html'])) {
                $message['body_text'] = $message['body_html'];
            }
            unset($message['body_html']);
        }
        unset($message);
    }
    return ticket_safe_data($row, $identities);
}

function ticket_date(mixed $value): string
{
    if (!$value || !is_scalar($value)) return 'Not provided';
    try { return (new DateTimeImmutable((string)$value, new DateTimeZone('Asia/Kuala_Lumpur')))->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'))->format('d M Y, H:i'); }
    catch (Throwable) { return (string)$value; }
}

function ticket_sla_due(mixed $created): string
{
    if (!$created || !is_scalar($created)) return 'Not provided';
    try {
        $start = new DateTimeImmutable((string)$created, new DateTimeZone('Asia/Kuala_Lumpur'));
        return ticket_date($start->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'))->add(new DateInterval('P3D'))->format(DateTimeInterface::ATOM));
    } catch (Throwable) { return 'Not provided'; }
}

function ticket_text(mixed $value): string
{
    if ($value === null || $value === '' || $value === []) return 'Not provided';
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    return is_array($value) ? (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$value;
}

function ticket_label(mixed $status): string
{
    return TICKET_STATUSES[$status??''] ?? ucwords(str_replace('_', ' ', (string)($status ?: 'Not provided')));
}

function ticket_link(array $changes = [], ?array $base = null): string
{
    $query = array_replace($base ?? ticket_filters([]), $changes);
    return '/tickets?'.http_build_query(array_filter($query, fn($v)=>$v !== '' && $v !== null));
}

function ticket_facts(array $facts, ?string $identityUrl = null): void
{
    echo '<dl class="tm-facts">';
    foreach ($facts as $label=>$value) {
        echo '<div><dt>'.e($label).'</dt><dd>';
        if ($label === 'ID number' && $identityUrl && $value && $value !== 'Not provided') {
            echo '<span class="tm-identity"><span data-identity-value>'.e($value).'</span><button type="button" class="tm-eye" data-identity-url="'.e($identityUrl).'" aria-label="Reveal ID number" aria-pressed="false"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="tm-eye-slash" d="m3 3 18 18"/></svg></button><small data-identity-error role="status"></small></span>';
        } else echo e(ticket_text($value));
        echo '</dd></div>';
    }
    echo '</dl>';
}

// Decode only escaped line endings for display, not quotes or other escapes.
// Keep Windows file paths intact when they contain sequences such as \notes.
function ticket_email_linebreaks(string $text, string $lineBreak = "\n"): string
{
    return preg_replace_callback('~[A-Za-z]:\\\\[^\s<>]*|\\\\r\\\\n|\\\\[rn]~',
        fn($match) => $match[0][0] === '\\' ? $lineBreak : $match[0], $text) ?? $text;
}

// Rebuild email markup from a small formatting allowlist. No source attributes,
// CSS, remote images, forms or executable content reach the rendered page.
function ticket_email_html(array $message): string
{
    $body = trim((string)($message['body_text'] ?? $message['body_html'] ?? ''));
    if ($body === '') return '<p>No message content available.</p>';
    if (!preg_match('/<\/?[a-z][^>]*>/i', $body) && preg_match('/&lt;\/?(?:html|body|div|p|br|table)\b/i', $body)) $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match('/<\/?[a-z][^>]*>/i', $body)) return '<div class="tm-email-plain">'.e(ticket_email_linebreaks($body)).'</div>';
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try { $document->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    $render = function (DOMNode $node) use (&$render): string {
        if ($node instanceof DOMText) return ticket_email_linebreaks(e(ticket_private_text($node->textContent)), '<br>');
        if (!$node instanceof DOMElement && !$node instanceof DOMDocument) return '';
        $tag = strtolower($node->nodeName);
        if (in_array($tag, ['head','script','style','iframe','object','embed','svg','math','form','input','button','textarea','select','template'], true)) return '';
        if ($tag === 'img') return e(ticket_private_text($node->getAttribute('alt')));
        $content = '';
        foreach ($node->childNodes as $child) $content .= $render($child);
        if (in_array($tag, ['p','div','br','hr','strong','b','em','i','u','s','ul','ol','li','blockquote','pre','table','thead','tbody','tfoot','tr','th','td','h1','h2','h3','h4'], true)) {
            if (in_array($tag, ['br','hr'], true)) return '<'.$tag.'>';
            return '<'.$tag.'>'.$content.'</'.$tag.'>';
        }
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if (preg_match('#^(https?://|mailto:)[^\s\x00-\x1f]+$#i', $href)) return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">'.$content.'</a>';
        }
        return $content;
    };
    return $render($document);
}

function ticket_latest_message(array $messages): ?array
{
    // Unknown sent times must not displace the latest known email.
    // The read model orders equal/unknown times by creation time, oldest first.
    $messages = array_reverse($messages);
    usort($messages, function ($a, $b) {
        $sent = (strtotime((string)($b['sent_at']??'')) ?: 0) <=> (strtotime((string)($a['sent_at']??'')) ?: 0);
        return $sent ?: ((strtotime((string)($b['created_at']??'')) ?: 0) <=> (strtotime((string)($a['created_at']??'')) ?: 0));
    });
    return $messages[0] ?? null;
}

function ticket_payload(string $label, mixed $value): void
{
    if ($value === null || $value === '' || $value === []) { echo '<p class="tm-empty-inline">'.e($label).': no data recorded.</p>'; return; }
    echo '<details class="tm-payload"><summary>'.e($label).'</summary><pre>'.e(json_encode(ticket_safe_data(is_string($value) ? (json_decode($value, true) ?? $value) : $value), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)).'</pre></details>';
}

final class TicketRepository
{
    public function __construct(private PDO $pdo, array $sessionUser)
    {
        if (!ticket_uuid((string)($sessionUser['id']??''))) throw new TicketAccessDenied();
        $q = $pdo->prepare('SELECT role FROM portal_users WHERE id=:id AND is_active=true');
        $q->execute(['id'=>$sessionUser['id']]);
        if (!in_array($q->fetchColumn(), ['admin','manager','agent'], true)) throw new TicketAccessDenied();
    }

    private function rows(string $sql, array $params = []): array
    {
        $q = $this->pdo->prepare($sql);
        foreach ($params as $key=>$value) $q->bindValue(':'.$key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $q->execute();
        return $q->fetchAll();
    }

    private function where(array $f, bool $withStatus): array
    {
        $clauses = []; $params = [];
        if ($withStatus && $f['status'] === 'active') $clauses[] = "t.workflow_status <> 'closed'";
        elseif ($withStatus && $f['status'] !== 'all') { $clauses[] = 't.workflow_status=:status'; $params['status']=$f['status']; }
        foreach (['source'=>'source_type','agent'=>'assigned_to','bucket'=>'bucket_id'] as $filter=>$column) {
            if ($f[$filter] === '') continue;
            if ($filter === 'agent' && $f[$filter] === 'unassigned') $clauses[] = 't.assigned_to IS NULL';
            else { $clauses[] = 't.'.$column.'=:'.$filter; $params[$filter]=$f[$filter]; }
        }
        if ($f['clog_open'] === '1') $clauses[] = "t.source_type='clog' AND lower(trim(coalesce(t.clog_status,'')))='open'";
        return [$clauses ? ' WHERE '.implode(' AND ', $clauses) : '', $params];
    }

    public function counts(array $f): array
    {
        [$where,$params] = $this->where($f, false);
        $counts = array_fill_keys(array_keys(TICKET_STATUSES), 0);
        foreach ($this->rows('SELECT workflow_status,count(*) AS count FROM portal_tickets t'.$where.' GROUP BY workflow_status', $params) as $row) $counts[$row['workflow_status']] = (int)$row['count'];
        return $counts;
    }

    public function queue(array $f): array
    {
        [$where,$params] = $this->where($f, true);
        $total = (int)$this->rows('SELECT count(*) AS total FROM portal_tickets t'.$where, $params)[0]['total'];
        $pages = max(1, (int)ceil($total/$f['per_page']));
        $f['page'] = min($f['page'], $pages);
        $order = ['updated_desc'=>'t.updated_at DESC','created_desc'=>'t.created_at DESC','created_asc'=>'t.created_at ASC'][$f['sort']];
        $rows = $this->rows('SELECT t.id,t.source_type,t.source_ref,t.clog_crm_id,t.applicant_name,t.applicant_email,t.customer_type,t.workflow_status,t.clog_status,t.scenario_key,t.bucket_id,b.name AS bucket_name,t.assigned_to,u.username AS assigned_username,t.missing_fields,t.attachment_lookup_status,t.created_at,t.updated_at FROM portal_tickets t LEFT JOIN portal_ticket_buckets b ON b.id=t.bucket_id LEFT JOIN portal_users u ON u.id=t.assigned_to'.$where.' ORDER BY '.$order.',t.id LIMIT :limit OFFSET :offset', $params+['limit'=>$f['per_page'],'offset'=>($f['page']-1)*$f['per_page']]);
        return ['rows'=>array_map('ticket_normalize',$rows),'total'=>$total,'pages'=>$pages,'filters'=>$f];
    }

    public function options(): array
    {
        return ['buckets'=>$this->rows('SELECT id,name,is_active FROM portal_ticket_buckets ORDER BY name,id'), 'agents'=>$this->rows('SELECT id,username,is_active FROM portal_users ORDER BY username,id')];
    }

    public function detail(string $id): ?array
    {
        if (!ticket_uuid($id)) return null;
        $rows = $this->rows('SELECT d.*,b.name AS bucket_name FROM portal_ticket_details d LEFT JOIN portal_ticket_buckets b ON b.id=d.bucket_id WHERE d.id=:id', ['id'=>$id]);
        return $rows ? ticket_normalize($rows[0]) : null;
    }

    public function crm(string $crm): ?array
    {
        $rows = $this->rows('SELECT c.*, (SELECT jsonb_object_agg(th.id,th.created_at) FROM cidb_email_threads th WHERE th.crm_id=c.crm_id) AS thread_created_dates FROM portal_crm_details c WHERE c.crm_id=:crm', ['crm'=>$crm]);
        if (!$rows) return null;
        $createdDates = ticket_json($rows[0]['thread_created_dates']);
        $rows[0]['email_threads'] = ticket_json($rows[0]['email_threads']);
        foreach ($rows[0]['email_threads'] as &$thread) $thread['created_at'] = $createdDates[$thread['id']] ?? null;
        unset($thread, $rows[0]['thread_created_dates']);
        $messages = $this->rows('SELECT m.subject,m.sender_name,m.participant_name,m.participant_address,m.body_text,m.body_html,m.sent_at,m.created_at,m.source_system FROM cidb_email_messages m JOIN cidb_email_threads th ON th.id=m.thread_id WHERE th.crm_id=:crm ORDER BY m.sent_at DESC NULLS LAST,m.created_at DESC,m.id DESC LIMIT 1', ['crm'=>$crm]);
        $rows[0]['email_thread'] = ['messages'=>$messages];
        return ticket_normalize($rows[0]);
    }

    public function identity(string $kind, string $reference): ?string
    {
        $rows = $kind === 'crm'
            ? $this->rows('SELECT nric AS identity FROM portal_crm_details WHERE crm_id=:id', ['id'=>$reference])
            : $this->rows('SELECT id_number AS identity FROM portal_tickets WHERE id=:id', ['id'=>$reference]);
        return isset($rows[0]['identity']) ? (string)$rows[0]['identity'] : null;
    }

    public function sourceHistory(?string $interaction): array
    {
        if (!$interaction) return ['activity'=>[], 'history'=>[]];
        return [
            'activity'=>array_map('ticket_normalize', $this->rows('SELECT source_activity_id,source_data_id,clog_user_id,username,activity,viewed_at,source_created_at,synced_at FROM cidb_clog_activity WHERE interaction_id=:id ORDER BY source_created_at NULLS LAST,source_activity_id', ['id'=>$interaction])),
            'history'=>array_map('ticket_normalize', $this->rows('SELECT source_log_id,source_data_id,received_status,send_status,created_by,source_created_at,synced_at FROM cidb_clog_ticketing_log WHERE interaction_id=:id ORDER BY source_created_at NULLS LAST,source_log_id', ['id'=>$interaction])),
        ];
    }

    public function ocrJobs(string $ticket): array
    {
        return array_map(fn($r)=>ticket_safe_data($r), $this->rows('SELECT j.id,j.document_id,d.original_filename,j.state,j.attempts,j.error_code,j.correlation_id,j.external_reference,j.next_attempt_at,j.started_at,j.completed_at,j.created_at,j.updated_at,j.request_payload,j.response_payload FROM portal_ticket_ocr_jobs j JOIN portal_ticket_documents d ON d.id=j.document_id WHERE d.ticket_id=:id ORDER BY j.created_at,j.id', ['id'=>$ticket]));
    }
}
