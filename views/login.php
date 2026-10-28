<?php
declare(strict_types=1);
$pageTitle = 'Sign in';
require __DIR__ . '/_layout-top.php';
$flash ??= null;
?>
<section class="login-shell">
    <div class="login-copy"><span class="eyebrow">CIDB SERVICE</span><h1>Secure request<br><em>management.</em></h1><p>Sign in to submit and track cancellation requests.</p></div>
    <form class="login-panel" method="post" action="/login">
        <span class="panel-kicker">WELCOME BACK</span><h2>Sign in</h2>
        <?php if (isset($error) && $error !== null): ?><div class="notice error visible"><?= e($error) ?></div><?php endif; ?>
        <?php if ($flash): ?><div class="notice <?= e($flash['kind'] ?? '') ?> visible"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <div class="form-stack">
            <label>Username or email<input name="username" autocomplete="username" required></label>
            <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="primary" type="submit">Sign in <span aria-hidden="true">→</span></button>
        </div>
    </form>
</section>
<?php require __DIR__ . '/_layout-bottom.php'; ?>
