<?php
declare(strict_types=1);
$historyResult = request_result($request);
$historySource = ($request['request_source'] ?? 'form') === 'email' ? 'email' : 'form';
$historyStatus = in_array($historyResult['status'], ['processing', 'pending', 'success', 'failed'], true)
    ? $historyResult['status']
    : 'processing';
?>
<li class="history-item" data-request-id="<?= e($historyResult['id']) ?>"
    data-request-complete="<?= $historyResult['complete'] ? 'true' : 'false' ?>">
    <a href="/request-history/<?= e($historyResult['id']) ?>">
        <span><?= e($request['applicant_name'] ?? 'Cancellation request') ?></span>
        <small><?= e($historySource === 'email' ? 'EMAIL' : 'FORM') ?></small>
    </a>
    <span class="status-pill <?= e($historyStatus) ?>" data-request-status><?= e($historyResult['message']) ?></span>
    <span class="sr-only" data-request-message><?= e($historyResult['message']) ?></span>
</li>
