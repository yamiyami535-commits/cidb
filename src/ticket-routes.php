<?php
declare(strict_types=1);

// This file is entered only after the existing login guard, for the new workspace.
require_once __DIR__.'/tickets.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
$workspace = ['screen'=>'error', 'heading'=>'Ticket management', 'message'=>'', 'counts'=>[], 'filters'=>ticket_filters([])];
try {
    if ($method !== 'GET') {
        header('Allow: GET'); http_response_code(405);
        $workspace['message']='This ticket workspace supports viewing records only.';
    } else {
        $repository = new TicketRepository(db(), current_user());
        // Retain bookmarks from the original preview without inventing workflow states.
        $legacyQueues = [
            '/tickets/customer-responded'=>'customer-responded',
            '/tickets/waiting-on-customer'=>'waiting-on-customer',
            '/tickets/waiting-acknowledgement'=>'waiting-acknowledgement',
            '/tickets/resolved'=>'resolved', '/tickets/closed'=>'closed',
        ];
        if (isset($legacyQueues[$path])) {
            $legacy=$legacyQueues[$path];
            redirect('/tickets?'.http_build_query(['status'=>match($legacy) {'resolved'=>'completed','closed'=>'closed',default=>'active'},'legacy'=>$legacy]));
        }
        if ($path === '/tickets') {
            $filters = ticket_filters($_GET);
            $queue = $repository->queue($filters);
            $workspace = array_replace($workspace, $queue, $repository->options(), ['screen'=>'queue','heading'=>'Ticket queue','counts'=>$repository->counts($filters)]);
            $legacy=ticket_input($_GET,'legacy');
            if (in_array($legacy,array_values($legacyQueues),true)) $workspace['legacyNotice']='This bookmark used a preview category. The queue now uses the portal workflow statuses shown below; source-system statuses remain separate.';
        } elseif ($path === '/tickets/crm') {
            $crmId = ticket_input($_GET, 'crm_id');
            if (mb_strlen($crmId) > 120 || preg_match('/[\x00-\x1f]/', $crmId)) throw new InvalidArgumentException('Enter a CRM ID of up to 120 characters.');
            $customer = $crmId !== '' ? $repository->crm($crmId) : null;
            $workspace = array_replace($workspace, ['screen'=>'crm','heading'=>'CRM search','crmId'=>$crmId,'customer'=>$customer,'counts'=>$repository->counts($workspace['filters'])]);
        } elseif (preg_match('#^/tickets/([a-f0-9-]{36})$#i', $path, $match) && ticket_uuid($match[1])) {
            $tab = ticket_input($_GET, 'tab', 'details');
            if (!isset(TICKET_TABS[$tab])) throw new InvalidArgumentException('Choose a supported ticket tab.');
            $ticket = $repository->detail($match[1]);
            if (!$ticket) { http_response_code(404); $workspace['message']='This ticket is unavailable or no longer exists.'; }
            else {
                $workspace = array_replace($workspace, ['screen'=>'detail','heading'=>ticket_label($ticket['workflow_status']),'ticket'=>$ticket,'tab'=>$tab,'counts'=>$repository->counts($workspace['filters'])]);
                if (in_array($tab,['activity','history'],true)) $workspace += $repository->sourceHistory($ticket['interaction_id']??null);
                if ($tab === 'documents') $workspace['jobs'] = $repository->ocrJobs($ticket['id']);
            }
        } else { http_response_code(404); $workspace['message']='This ticket page could not be found.'; }
    }
} catch (TicketAccessDenied) {
    http_response_code(403); $workspace['message']='Ticket management is available to active portal staff only.';
} catch (InvalidArgumentException $e) {
    http_response_code(400); $workspace['message']=$e->getMessage();
} catch (Throwable $e) {
    error_log('Ticket workspace failed: '.$e->getMessage());
    http_response_code(503); $workspace['message']='Ticket data is temporarily unavailable. Please try again. If this continues, ask your administrator to check the database connection and ticket workflow migrations.';
}
render('tickets/workspace', $workspace);
