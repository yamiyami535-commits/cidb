<?php
declare(strict_types=1);
$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'CIDB Cancellation Portal';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">
        <span class="brand-mark" aria-hidden="true">C</span>
        <span><strong>CIDB Cancellation Portal</strong><small>Secure request processing</small></span>
    </a>
    <?php if (current_user()): ?>
        <nav class="account" aria-label="Account">
            <a href="/request-history">Request history</a>
            <span><?= e(current_user()['username'] ?? '') ?></span>
            <form method="post" action="/logout">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <button class="signout" type="submit">Sign out</button>
            </form>
        </nav>
    <?php endif; ?>
</header>
<main>
