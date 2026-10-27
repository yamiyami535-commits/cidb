<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#063b3d">
  <title><?= e($title ?? 'CIDB Digital Services') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/app.css">
  <link rel="stylesheet" href="/assets/email.css">
  <?php if (($mainClass ?? '') === 'portal-main'): ?><link rel="stylesheet" href="/assets/portal-shell.css"><?php endif; ?>
  <?php if (!empty($ticketWorkspace)): ?><link rel="stylesheet" href="/assets/tickets.css"><?php endif; ?>
</head>
<body<?= !empty($ticketWorkspace) ? ' class="ticket-workspace"' : '' ?>>
<header class="topbar"><a class="brand" href="/" aria-label="CIDB Malaysia Digital Services home"><img class="cidb-logo" src="/assets/images/CIDB-Logo.png" alt="CIDB Malaysia"></a><div class="partner"><img class="daythree-logo daythree-logo-header" src="/assets/images/daythree-logo-1.png" alt="Daythree"></div>
<?php if (current_user()): ?><div class="account"><span><?= e(current_user()['username']) ?></span><form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><button class="signout" type="submit">Sign out</button></form></div><?php endif; ?>
</header>
<main class="<?= e($mainClass ?? '') ?>"><?= $content ?? '' ?></main>
<footer><span class="footer-brand"><img class="cidb-logo cidb-logo-footer" src="/assets/images/CIDB-Logo.png" alt="CIDB Malaysia"><span>© <?= date('Y') ?></span></span><span>Secure digital services <i aria-hidden="true"></i></span></footer>
<script src="/assets/app.js" defer></script>
<?php if (!empty($ticketWorkspace)): ?><script src="/assets/tickets.js" defer></script><?php endif; ?>
</body></html>
