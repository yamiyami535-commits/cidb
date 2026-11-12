<?php $flash ??= null; $requests ??= []; $errors ??= []; $values ??= []; $activeTicketPage='service'; $mainClass='portal-main'; ob_start(); ?>
<div class="portal-workspace">
<?php require __DIR__.'/ticket-sidebar.php'; ?>
<div class="portal-workspace-content">
<button class="sidebar-toggle" type="button" data-sidebar-toggle aria-expanded="true" aria-controls="ticket-sidebar"><span aria-hidden="true">☰</span><span class="sidebar-toggle-label">Hide menu</span></button>
<section class="hero"><div class="hero-text"><div class="eyebrow"><span class="eyebrow-line"></span>CIDB SERVICE REQUEST</div><h1>Lets get your<br><em>request started.</em></h1><p>Share a few details and our digital service team will take it from here.</p><div class="hero-trust"><span class="trust-icon">✓</span> Secure submission <b></b> Takes less than a minute</div></div><div class="hero-visual" aria-hidden="true"><div class="visual-card"><span class="visual-label">YOUR NEXT STEP</span><div class="visual-number">01</div><div class="visual-rule"></div><strong>A smoother<br>service experience</strong><span class="visual-orb"></span></div><span class="visual-spark spark-a">✳</span><span class="visual-spark spark-b">✦</span></div></section>
<section class="content-grid"><div class="form-card"><div class="card-head"><div><div class="panel-kicker">REQUEST DETAILS</div><h2>Submit your information</h2></div><div class="step-pill"><span>1</span> of 1</div></div><p class="muted intro">Complete the fields below. We'll securely pass your request to the relevant service process.</p>
<?php if (!empty($flash)): ?><div class="notice <?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></div><?php endif; ?>
<form method="post" action="/submit" class="request-form" data-submit-form novalidate><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="submission_key" value="<?= e($_SESSION['submission_key'] ??= new_uuid()) ?>">
<?php $fields=[['name','Name','text','e.g. Aisyah binti Abdullah','Enter your full name as shown on your ID',200],['id_number','ID Number','text','e.g. 900101-14-5678','Enter your Malaysian ID or other valid ID number',40],['email','Email','email','e.g. name@example.com','Enter your email address',254],['crm','CRM','text','Enter your CRM','Provide the CRM reference for this request',120]]; foreach($fields as [$key,$label,$type,$placeholder,$help,$maxlength]): ?>
<div class="field"><label for="<?= e($key) ?>"><?= e($label) ?><span class="required" aria-label="required">*</span></label><input id="<?= e($key) ?>" name="<?= e($key) ?>" type="<?= e($type) ?>" value="<?= e($values[$key]??'') ?>" placeholder="<?= e($placeholder) ?>" required aria-describedby="<?= e($key) ?>-help <?= e($key) ?>-error" maxlength="<?= $maxlength ?>"><small id="<?= e($key) ?>-help" class="help"><?= e($help) ?></small><small id="<?= e($key) ?>-error" class="field-error" role="alert"><?= e($errors[$key]??'') ?></small></div>
<?php endforeach; ?><div class="field"><label for="language">Language</label><select id="language" name="language" aria-describedby="language-help language-error"><option value="" <?= (($values['language']??'')==='')?'selected':'' ?>>Select language (optional)</option><option value="en" <?= (($values['language']??'')==='en')?'selected':'' ?>>English</option><option value="ms" <?= (($values['language']??'')==='ms')?'selected':'' ?>>Malay</option></select><small id="language-help" class="help">Defaults to Malay if no language is selected.</small><small id="language-error" class="field-error" role="alert"><?= e($errors['language']??'') ?></small></div><button class="primary submit-button" type="submit"><span class="button-label">Submit request</span><span class="button-arrow" aria-hidden="true">→</span><span class="button-spinner" aria-hidden="true"></span></button><div class="submit-assurance"><span>◈</span> Your details are encrypted in transit</div></form></div>
<aside class="side-column"><div class="process-card"><div class="process-icon">✦</div><div class="panel-kicker">WHAT HAPPENS NEXT</div><h3>One simple request.<br>Handled with care.</h3><div class="process-step"><span>01</span><div><strong>We receive your details</strong><small>Your request is recorded securely.</small></div></div><div class="process-step"><span>02</span><div><strong>Our service process begins</strong><small>The relevant team is notified.</small></div></div><div class="process-step"><span>03</span><div><strong>Track your result here</strong><small>Your latest status appears below.</small></div></div></div>
<div class="status-card">
  <div class="status-heading">
    <div><div class="panel-kicker">REQUEST HISTORY</div><h3>Recent activity</h3></div>
    <a class="history-link-overlay" href="/request-history" aria-label="View request history"></a>
    <span class="history-icon" aria-hidden="true">↗</span>
  </div>
  <?php if (empty($requests)) { ?>
    <div class="empty-state"><span class="empty-icon" aria-hidden="true">⌁</span><strong>No requests yet</strong><small>Your submitted requests will appear here.</small></div>
  <?php } else { $labels=['processing'=>'In progress','pending'=>'In progress','success'=>'Success','failed'=>'Failed']; foreach ($requests as $request) { $result=request_result($request); ?>
    <a class="request-row request-row-link" href="/request-history/<?= e($request['id']) ?>" data-request-id="<?= e($request['id']) ?>" data-request-complete="<?= $result['complete'] ? 'true' : 'false' ?>">
      <span class="status-dot <?= e($result['status']) ?>" data-request-status-dot></span>
      <div class="request-meta">
        <span class="request-source"><?= e(strtoupper($request['request_source'] ?? 'form')) ?></span>
        <strong><?= e(substr($request['id'], 0, 8)) ?></strong>
        <small><?= e(date('d M Y · H:i', strtotime($request['created_at']))) ?><?= !empty($request['rpa_reference_id']) ? ' · RPA ' . e($request['rpa_reference_id']) : '' ?></small>
        <small class="response-message" data-request-message aria-live="polite"><?= e($result['message']) ?></small>
      </div>
      <span class="status-label <?= e($result['status']) ?>" data-request-status><?= e($labels[$result['status']]) ?></span>
    </a>
  <?php } } ?>
</div></aside></section>
</div></div>
<?php $content=ob_get_clean(); $title='Service request Â· CIDB Digital Services'; require __DIR__.'/layout.php'; ?>
