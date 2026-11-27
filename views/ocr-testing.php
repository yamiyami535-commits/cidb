<?php
declare(strict_types=1);
$title = 'OCR Testing | CIDB';
$activeTicketPage = 'ocr-testing';
ob_start();
?>
<link rel="stylesheet" href="/assets/ocr-testing.css">
<section class="ocr-test" aria-labelledby="ocr-title">
  <header class="ocr-heading"><div><p class="eyebrow">DOCUMENT VERIFICATION</p><h1 id="ocr-title">OCR Testing</h1><p>Inspect the document's extracted identity and verification evidence.</p></div><span class="ocr-test-label">Testing only</span></header>
  <p class="ocr-notice">Test uploads do not update tickets or send results to RPA. Documents are processed temporarily and are not retained by this page.</p>
  <form id="ocr-test-form" action="/ocr-testing/run" method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <label for="ocr-mode">Document format</label>
    <select name="mode" id="ocr-mode">
      <option value="mykad">MyKad — front and back images</option>
      <option value="passport">Passport — information-page image</option>
      <option value="image">MyKad — single image</option>
      <option value="form_pdf">Cancellation form — one-page PDF</option>
      <option value="mykad_pdf">MyKad PDF — one or two pages</option>
    </select>
    <div id="ocr-file-fields"></div>
    <p class="ocr-muted">JPEG, PNG or WebP images (up to <?= e(env_value('OCR_MAX_FILE_SIZE_MB', '8')) ?> MB each). Supported PDFs up to <?= e(env_value('OCR_MAX_PDF_SIZE_MB', '16')) ?> MB. Passport PDFs are not supported.</p>
    <button class="primary" type="submit" id="ocr-run">Run OCR test</button>
    <button type="button" id="ocr-clear">Clear documents and results</button>
    <noscript>Enable JavaScript to inspect diagnostic results on this page.</noscript>
  </form>
  <p id="ocr-progress" role="status" aria-live="polite"></p>
  <p id="ocr-error" role="alert" hidden></p>
  <section id="ocr-results" aria-labelledby="ocr-result-title" hidden>
    <h2 id="ocr-result-title" tabindex="-1">Processing results</h2>
    <div class="ocr-summary">
      <div><span>Full name</span><strong id="ocr-name"></strong></div>
      <div><span>NRIC / passport number</span><strong id="ocr-number"></strong></div>
      <div><span>Overall verification</span><strong id="ocr-verification"></strong></div>
      <div><span>Service status</span><strong id="ocr-service-status"></strong></div>
    </div>
    <p id="ocr-reason"></p><p id="ocr-metadata" class="ocr-muted"></p>
    <p id="ocr-diagnostic-notice" class="ocr-notice" hidden></p>
    <div class="ocr-table-scroll"><table><caption>Individual checks</caption><thead><tr><th scope="col">Check</th><th scope="col">Outcome</th><th scope="col">Evidence / reason</th></tr></thead><tbody id="ocr-checks"></tbody></table></div>
    <h3>Document evidence</h3>
    <p class="ocr-muted">Numbered strokes show the selected OpenCV candidates; their explanations appear beside the preview. Other detectors use solid boxes. Neither represents overall approval. Dashed purple regions are expected embedded-signature locations from a matched reference; they do not prove a signature is visible.</p>
    <div id="ocr-pages"></div>
    <details><summary>Actual OCR response</summary><pre id="ocr-json"></pre></details>
  </section>
</section>
<script src="/assets/ocr-testing.js" defer></script>
<?php $content = ob_get_clean(); require __DIR__ . '/layout.php'; ?>
