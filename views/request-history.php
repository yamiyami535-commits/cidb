<?php
declare(strict_types=1);
$pageTitle = 'Request history';
require __DIR__ . '/_layout-top.php';
$requests ??= [];
$source ??= 'all';
$pagination ??= ['page' => 1, 'pages' => 1, 'total' => count($requests), 'per_page' => 15];
$source = in_array($source, ['all', 'form', 'email'], true) ? $source : 'all';
$page = max(1, (int)($pagination['page'] ?? 1));
$pages = max(1, (int)($pagination['pages'] ?? 1));
$historyHref = static fn(string $filter, int $pageNumber): string =>
    '/request-history?source=' . rawurlencode($filter) . '&amp;page=' . $pageNumber;
$pageItems = [1, $pages];
for ($number = max(1, $page - 1); $number <= min($pages, $page + 1); $number++) {
    $pageItems[] = $number;
}
$pageItems = array_values(array_unique($pageItems));
sort($pageItems);
?>
<section class="form-card" data-request-history data-history-source="<?= e($source) ?>" data-history-page="<?= $page ?>">
    <div class="card-head"><div><span class="panel-kicker">REQUESTS</span><h1>Request history</h1></div></div>
    <nav class="history-filters" aria-label="Filter requests">
        <?php foreach (['all' => 'All', 'form' => 'Form', 'email' => 'Email'] as $filter => $label): ?>
            <a href="/request-history?source=<?= e(rawurlencode($filter)) ?>" data-history-link data-history-source="<?= e($filter) ?>"
                <?= $source === $filter ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <p data-history-feedback hidden aria-live="polite"></p>
    <?php if ($requests === []): ?>
        <p class="muted">No requests yet.</p>
    <?php else: ?>
        <ul class="history-list">
            <?php foreach ($requests as $request): ?>
                <?php require __DIR__ . '/_history-item.php'; ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
        <nav class="history-pagination" aria-label="Request history pages">
            <?php if ($page > 1): ?><a rel="prev" href="<?= $historyHref($source, $page - 1) ?>" data-history-link data-history-source="<?= e($source) ?>">Previous</a><?php endif; ?>
            <?php $previous = 0; foreach ($pageItems as $number): ?>
                <?php if ($previous > 0 && $number > $previous + 1): ?><span aria-hidden="true">&hellip;</span><?php endif; ?>
                <?php if ($number === $page): ?>
                    <span aria-current="page" aria-label="Page <?= $number ?>"><?= $number ?></span>
                <?php else: ?>
                    <a href="<?= $historyHref($source, $number) ?>" data-history-link data-history-source="<?= e($source) ?>"><?= $number ?></a>
                <?php endif; ?>
                <?php $previous = $number; ?>
            <?php endforeach; ?>
            <?php if ($page < $pages): ?><a rel="next" href="<?= $historyHref($source, $page + 1) ?>" data-history-link data-history-source="<?= e($source) ?>">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/_layout-bottom.php'; ?>
