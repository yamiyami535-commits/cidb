<?php $requests ??= []; $source ??= 'all'; $pagination ??= ['page'=>1,'pages'=>1,'total'=>count($requests),'per_page'=>15]; $labels=['processing'=>'In progress','pending'=>'In progress','success'=>'Success','failed'=>'Failed']; ob_start(); ?>
<div data-request-history data-history-source="<?= e($source) ?>" data-history-page="<?= e($pagination['page']) ?>">
<section class="page-heading">
  <a class="back-link" href="/">← Back to request form</a>
  <div class="panel-kicker">REQUEST HISTORY</div>
  <h1>All your requests</h1>
  <p class="muted">Review your form requests and shared email requests, and open one to see its full details.</p>
  <nav class="history-filters" aria-label="Request type">
    <?php foreach (['all'=>'All','form'=>'Form','email'=>'Email'] as $value=>$label): ?>
      <a href="/request-history?source=<?= e($value) ?>" data-history-link data-history-source="<?= e($value) ?>" data-history-page="1" class="history-filter <?= $source===$value?'active':'' ?>" <?= $source===$value?'aria-current="page"':'' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</section>
<p class="history-feedback" data-history-feedback role="status" aria-live="polite" hidden></p>
<section class="history-page-card">
  <?php if (empty($requests)) { ?>
    <div class="empty-state history-empty"><span class="empty-icon" aria-hidden="true">⌁</span><strong>No requests yet</strong><small>Your submitted requests will appear here.</small></div>
  <?php } else { ?>
    <div class="history-table-head"><span>Request</span><span>Date &amp; time</span><span>Detail</span><span>Status</span></div>
    <div class="history-list">
      <?php foreach ($requests as $request) { $result=request_result($request); ?>
        <a class="history-list-row" href="/request-history/<?= e($request['id']) ?>" data-request-id="<?= e($request['id']) ?>" data-request-complete="<?= $result['complete'] ? 'true' : 'false' ?>">
          <span class="history-request-id"><span class="status-dot <?= e($result['status']) ?>" data-request-status-dot></span><span><span class="request-source"><?= e(strtoupper($request['request_source'] ?? 'form')) ?></span><strong><?= e($request['id']) ?></strong></span></span>
          <span class="history-date"><strong><?= e(date('d M Y', strtotime($request['created_at']))) ?></strong><small><?= e(date('H:i', strtotime($request['created_at']))) ?></small></span>
          <span class="history-detail" data-request-message aria-live="polite"><?= e($result['message']) ?></span>
          <span class="status-label <?= e($result['status']) ?>" data-request-status><?= e($labels[$result['status']]) ?></span>
          <span class="row-arrow" aria-hidden="true">→</span>
        </a>
      <?php } ?>
    </div>
  <?php } ?>
  <?php if ($pagination['pages']>1):
    $current=$pagination['page']; $last=$pagination['pages'];
    $pageNumbers=$last<=7 ? range(1,$last) : array_values(array_unique(array_merge([1],range(max(2,$current-2),min($last-1,max(5,$current+2))),[$last])));
    $pageUrl=static fn(int $number): string => '/request-history?'.http_build_query(['source'=>$source,'page'=>$number]);
  ?>
    <nav class="history-pagination" aria-label="Request history pages">
      <p class="pagination-summary">Showing <?= e(($current-1)*$pagination['per_page']+1) ?>&ndash;<?= e(min($current*$pagination['per_page'],$pagination['total'])) ?> of <?= e($pagination['total']) ?> requests</p>
      <div class="pagination-controls">
        <?php if ($current>1): ?><a class="history-filter" href="<?= e($pageUrl($current-1)) ?>" data-history-link data-history-source="<?= e($source) ?>" data-history-page="<?= e($current-1) ?>" rel="prev">Previous</a><?php else: ?><span class="history-filter pagination-disabled" aria-disabled="true">Previous</span><?php endif; ?>
        <?php $previous=0; foreach ($pageNumbers as $number): ?>
          <?php if ($previous && $number>$previous+1): ?><span class="pagination-gap" aria-hidden="true">&hellip;</span><?php endif; ?>
          <?php if ($number===$current): ?><span class="history-filter active" aria-current="page" aria-label="Page <?= e($number) ?>" tabindex="-1"><?= e($number) ?></span><?php else: ?><a class="history-filter" href="<?= e($pageUrl($number)) ?>" data-history-link data-history-source="<?= e($source) ?>" data-history-page="<?= e($number) ?>" aria-label="Page <?= e($number) ?>"><?= e($number) ?></a><?php endif; ?>
        <?php $previous=$number; endforeach; ?>
        <?php if ($current<$last): ?><a class="history-filter" href="<?= e($pageUrl($current+1)) ?>" data-history-link data-history-source="<?= e($source) ?>" data-history-page="<?= e($current+1) ?>" rel="next">Next</a><?php else: ?><span class="history-filter pagination-disabled" aria-disabled="true">Next</span><?php endif; ?>
      </div>
    </nav>
  <?php endif; ?>
</section>
</div>
<?php $content=ob_get_clean(); $title='Request history · CIDB Digital Services'; require __DIR__.'/layout.php'; ?>
