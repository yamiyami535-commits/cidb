<?php
declare(strict_types=1);
$pageTitle = 'CIDB Cancellation Portal';
require __DIR__ . '/_layout-top.php';
$request ??= null;
$flash ??= null;
$requests ??= [];
$errors ??= [];
$values ??= [];
?>
<section class="hero">
    <div class="hero-text">
        <span class="eyebrow"><span class="eyebrow-line"></span> CANCELLATION REQUESTS</span>
        <h1>Submit your<br><em>information securely.</em></h1>
        <p>Provide your details to start a cancellation request. The status shown here is updated from the processing system.</p>
        <div class="hero-trust"><span class="trust-icon" aria-hidden="true">✓</span> Your information is handled securely</div>
    </div>
    <div class="hero-visual" aria-hidden="true">
        <div class="visual-card"><span class="visual-label">CIDB REQUEST</span><div class="visual-number">C</div><div class="visual-rule"></div><strong>Secure processing<br>and status tracking</strong><span class="visual-orb"></span></div>
    </div>
</section>
<?php if ($flash): ?>
    <div class="notice <?= e($flash['kind'] ?? '') ?>"><?= e($flash['message'] ?? '') ?></div>
<?php endif; ?>
<?php if ($request): ?>
    <?php require __DIR__ . '/_request-status.php'; ?>
<?php endif; ?>
<section class="content-grid">
    <div class="form-card">
        <div class="card-head"><div><span class="panel-kicker">REQUEST DETAILS</span><h2>Cancellation request</h2></div></div>
        <p class="muted intro">Enter the applicant and CRM details associated with the cancellation.</p>
        <form class="request-form" method="post" action="/submit" data-submit-form>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="submission_key" value="<?= e($_SESSION['submission_key'] ??= new_uuid()) ?>">
            <?php foreach ([
                'name' => ['Applicant name', 'text', 200],
                'id_number' => ['Identification number', 'text', 40],
                'email' => ['Email address', 'email', 254],
                'crm' => ['CRM ID', 'text', 120],
            ] as $key => [$label, $type, $maxLength]): ?>
                <div class="field">
                    <label for="<?= e($key) ?>"><?= e($label) ?><span class="required">*</span></label>
                    <input id="<?= e($key) ?>" name="<?= e($key) ?>" type="<?= e($type) ?>"
                        maxlength="<?= (int)$maxLength ?>" required autocomplete="off"
                        value="<?= e($values[$key] ?? '') ?>" aria-invalid="<?= isset($errors[$key]) ? 'true' : 'false' ?>">
                    <small class="field-error"><?= e($errors[$key] ?? '') ?></small>
                </div>
            <?php endforeach; ?>
            <div class="field">
                <label for="language">Language</label>
                <select id="language" name="language">
                    <option value="ms" <?= ($values['language'] ?? 'ms') === 'ms' ? 'selected' : '' ?>>Bahasa Melayu</option>
                    <option value="en" <?= ($values['language'] ?? '') === 'en' ? 'selected' : '' ?>>English</option>
                </select>
            </div>
            <button class="primary submit-button" type="submit"><span class="button-label">Submit request</span><span aria-hidden="true">→</span></button>
        </form>
    </div>
    <aside class="process-card">
        <span class="panel-kicker">TRACK YOUR REQUEST</span>
        <h2>Recent activity</h2>
        <?php if ($requests === []): ?>
            <p class="muted">No requests yet.</p>
        <?php else: ?>
            <ul class="history-list">
                <?php foreach ($requests as $item): ?>
                    <?php $request = $item; require __DIR__ . '/_history-item.php'; ?>
                <?php endforeach; ?>
            </ul>
            <a href="/request-history">View request history</a>
        <?php endif; ?>
    </aside>
</section>
<?php require __DIR__ . '/_layout-bottom.php'; ?>
