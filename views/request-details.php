<?php
declare(strict_types=1);
$pageTitle = 'Request details';
require __DIR__ . '/_layout-top.php';
$result = request_result($request);
$source = ($request['request_source'] ?? 'form') === 'email' ? 'email' : 'form';
$statusClass = in_array($result['status'], ['processing', 'pending', 'success', 'failed'], true)
    ? $result['status']
    : 'processing';
$crmId = null;
if ($source === 'email' && is_string($request['rpa_request_payload'] ?? null)) {
    $payload = json_decode($request['rpa_request_payload'], true);
    if (is_array($payload) && is_array($payload['fields'] ?? null)
        && is_scalar($payload['fields']['sCRMID'] ?? null)) {
        $crmId = (string)$payload['fields']['sCRMID'];
    }
}
?>
<section class="form-card">
    <a href="/request-history">← Request history</a>
    <span class="panel-kicker"><?= $source === 'email' ? 'EMAIL REQUEST' : 'FORM REQUEST' ?></span>
    <h1>Request details</h1>
    <h2>Your request fields</h2>
    <dl class="request-summary">
        <dt>Applicant</dt><dd><?= e($request['applicant_name'] ?? '') ?></dd>
        <dt>Email</dt><dd><?= e($request['applicant_email'] ?? '') ?></dd>
        <?php if ($source === 'email' && $crmId !== null): ?><dt>CRM</dt><dd><?= e($crmId) ?></dd><?php endif; ?>
        <?php if ($source === 'email' && !empty($request['email_location_area'])): ?><dt>Location</dt><dd><?= e($request['email_location_area']) ?></dd><?php endif; ?>
        <dt>Submitted</dt><dd><?= e($request['created_at'] ?? '') ?></dd>
    </dl>
    <section class="status-card" data-request-id="<?= e($result['id']) ?>"
        data-request-complete="<?= $result['complete'] ? 'true' : 'false' ?>">
        <div class="status-heading">
            <h2>Status</h2>
            <span class="status-pill <?= e($statusClass) ?>" data-request-status><?= e(match ($result['status']) {
                'success' => 'Success',
                'failed' => 'Failed',
                default => 'In progress',
            }) ?></span>
        </div>
        <p data-request-message><?= e($result['message']) ?></p>
    </section>
</section>
<?php require __DIR__ . '/_layout-bottom.php'; ?>
