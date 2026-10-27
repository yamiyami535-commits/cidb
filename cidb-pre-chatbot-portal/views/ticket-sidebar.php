<?php
$ticketNavigation ??= [];
$ticketPages = [
    'service' => ['label'=>'CIDB SERVICE REQUEST','href'=>'/','tone'=>'service','count'=>null],
    'management' => ['label'=>'TICKET MANAGEMENT','href'=>'/tickets','tone'=>'service','count'=>$ticketNavigation ? array_sum($ticketNavigation)-($ticketNavigation['closed']??0) : '—'],
    'needs_information' => ['label'=>'NEEDS INFORMATION','href'=>'/tickets?status=needs_information','tone'=>'responded','count'=>$ticketNavigation['needs_information']??'—'],
    'ready_for_ocr' => ['label'=>'READY FOR OCR','href'=>'/tickets?status=ready_for_ocr','tone'=>'waiting','count'=>$ticketNavigation['ready_for_ocr']??'—'],
    'ready_for_execution' => ['label'=>'READY FOR EXECUTION','href'=>'/tickets?status=ready_for_execution','tone'=>'acknowledgement','count'=>$ticketNavigation['ready_for_execution']??'—'],
    'completed' => ['label'=>'COMPLETED','href'=>'/tickets?status=completed','tone'=>'resolved','count'=>$ticketNavigation['completed']??'—'],
    'closed' => ['label'=>'CLOSED','href'=>'/tickets?status=closed','tone'=>'closed','count'=>$ticketNavigation['closed']??'—'],
];
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
  <div class="sidebar-footnote">Open <a href="/tickets">Ticket management</a> for all workflow queues.<?= !$ticketNavigation ? ' Counts are currently unavailable.' : '' ?></div>
</aside>
