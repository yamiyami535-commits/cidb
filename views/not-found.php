<?php
ob_start();
?>

<section class="error-page">
    <div class="error-symbol">404</div>

    <h1>Oops! This page took a wrong turn. 😅</h1>

    <p>
        Looks like the page you're looking for has gone missing.
        We've checked everywhere... even the usual hiding spots.
    </p>

    <a class="primary link-button" href="/">
        Take me back to the portal <span>→</span>
    </a>

    <p class="error-hint">
        Don't worry, nothing is broken. You just found a page that doesn't exist. 👀
    </p>
</section>

<?php
$content = ob_get_clean();
$title = '404 · CIDB';
require __DIR__ . '/layout.php';
?>