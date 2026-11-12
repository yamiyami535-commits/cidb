<?php $title = 'CIDB | '.$heading; $ticketWorkspace = true; ob_start(); ?>
<a class="tm-skip" href="#ticket-content">Skip to ticket content</a>
<div class="tm-shell" data-ticket-shell>
  <aside class="tm-sidebar" id="ticket-menu" aria-label="Ticket navigation">
    <div class="tm-sidebar-heading"><span class="panel-kicker">Ticket queue</span><small>Live records</small></div>
    <nav>
      <a class="tm-nav<?= $screen === 'queue' && $filters['status'] === 'active' ? ' selected' : '' ?>" href="<?= e(ticket_link(['status'=>'active','page'=>1], $filters)) ?>"<?= $screen === 'queue' && $filters['status'] === 'active' ? ' aria-current="page"' : '' ?>><span>All active tickets</span><b><?= $counts ? array_sum($counts)-($counts['closed']??0) : '—' ?></b></a>
      <?php foreach (TICKET_STATUSES as $status=>$label): $selected = ($screen === 'queue' && $filters['status'] === $status) || ($screen === 'detail' && $ticket['workflow_status'] === $status); ?>
      <a class="tm-nav<?= $selected ? ' selected' : '' ?>" href="<?= e(ticket_link(['status'=>$status,'page'=>1], $filters)) ?>"<?= $selected ? ' aria-current="page"' : '' ?>><span><i class="tm-dot tm-<?= e($status) ?>" aria-hidden="true"></i><?= e($label) ?></span><b><?= $counts[$status]??'—' ?></b></a>
      <?php endforeach; ?>
    </nav>
    <div class="tm-sidebar-footer"><a href="/tickets/crm"<?= $screen === 'crm' ? ' aria-current="page"' : '' ?>>Search CRM ID <span aria-hidden="true">↗</span></a><a href="/">Submit a request <span aria-hidden="true">↗</span></a><a href="/request-history">Recent activity <span aria-hidden="true">↗</span></a><p>Counts follow source, agent and team filters. Times shown in Malaysia time (MYT).</p></div>
  </aside>
  <section class="tm-main" id="ticket-content" tabindex="-1">
    <div class="tm-toolbar"><button class="tm-button tm-menu-button" type="button" aria-controls="ticket-menu" aria-expanded="true" data-menu-toggle hidden>☰ <span>Hide menu</span></button><a href="/tickets/crm">CRM search</a><a href="<?= e($screen === 'detail' ? '/tickets' : ($_SERVER['REQUEST_URI']??'/tickets')) ?>"><?= $screen === 'detail' ? '← Ticket queue' : 'Refresh records' ?></a></div>
    <header class="tm-heading"><div class="eyebrow"><span class="eyebrow-line"></span>CIDB ticket management</div><div class="tm-heading-row"><h1><?= e($heading) ?></h1><?php if ($screen === 'detail'): ?><span class="tm-badge tm-<?= e($ticket['workflow_status']) ?>"><?= e(ticket_label($ticket['workflow_status'])) ?></span><?php endif; ?></div><p class="muted"><?php if ($screen === 'queue'): ?>Review, find and follow operational tickets across the service workflow.<?php elseif ($screen === 'detail'): ?>Ticket <span class="tm-uuid"><?= e($ticket['id']) ?></span> · Read-only workspace<?php elseif ($screen === 'crm'): ?>Find a customer by exact CRM ID, then open a linked ticket.<?php endif; ?></p></header>
    <?php if ($screen === 'error'): ?>
      <div class="tm-card tm-error" role="alert"><h2>Unable to open this view</h2><p><?= e($message) ?></p><a class="tm-button" href="/tickets">Return to ticket queue</a></div>
    <?php else: require __DIR__.'/'.$screen.'.php'; endif; ?>
    <p class="tm-loading" data-loading-message role="status" hidden>Loading records…</p>
  </section>
</div>
<?php $content = ob_get_clean(); require dirname(__DIR__).'/layout.php'; ?>
