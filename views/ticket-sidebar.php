<?php
require_once dirname(__DIR__).'/src/tickets.php';
$ticketNavigation ??= [];
$ticketPages = [
    'management' => ['label'=>'All active tickets','href'=>'/tickets','tone'=>'service','count'=>$ticketNavigation ? array_sum($ticketNavigation)-($ticketNavigation['closed']??0) : '—'],
];
foreach (TICKET_STATUSES as $status=>$label) {
    $ticketPages[$status] = ['label'=>$label,'href'=>'/tickets?status='.$status,'tone'=>match($status) {
        'needs_information','failed'=>'responded', 'completed'=>'resolved', 'closed'=>'closed', default=>'service',
    },'count'=>$ticketNavigation[$status]??'—'];
}
?>
<aside class="ticket-sidebar" id="ticket-sidebar" aria-label="Ticket status navigation">
  <div class="sidebar-heading"><span class="panel-kicker">TICKET QUEUE</span><span class="sidebar-caption">Workflow views</span></div>
  <nav class="ticket-nav">
    <?php foreach ($ticketPages as $key=>$page): ?>
      <a class="ticket-nav-link tone-<?= e($page['tone']) ?><?= ($activeTicketPage ?? '') === $key ? ' is-active' : '' ?>" href="<?= e($page['href']) ?>" <?= ($activeTicketPage ?? '') === $key ? 'aria-current="page"' : '' ?>>
        <span class="ticket-nav-mark" aria-hidden="true"></span><span><?= e($page['label']) ?></span><?php if ($page['count'] !== null): ?><span class="ticket-nav-count"><?= e($page['count']) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>
