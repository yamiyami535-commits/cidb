<?php
declare(strict_types=1);
$statusResult = request_result($request);
$statusClass = in_array($statusResult['status'], ['processing', 'pending', 'success', 'failed'], true)
    ? $statusResult['status']
    : 'processing';
?>
<section class="status-card" data-request-id="<?= e($statusResult['id']) ?>"
    data-request-complete="<?= $statusResult['complete'] ? 'true' : 'false' ?>">
    <div class="status-heading">
        <h2>Request status</h2>
        <span class="status-pill <?= e($statusClass) ?>" data-request-status><?= e($statusResult['message']) ?></span>
    </div>
    <p class="muted" data-request-message><?= e($statusResult['message']) ?></p>
</section>
