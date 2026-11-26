<?php $latestMessage = ticket_latest_message($emailMessages ?? []); ?>
<section class="tm-card tm-latest-email"><div class="tm-card-heading tm-teal"><h2>Latest email</h2></div><div class="tm-card-body">
<?php if ($latestMessage): ?>
  <div class="tm-message-head"><span class="tm-source"><?= e(strtoupper($latestMessage['source_system'] ?? 'Email')) ?></span><span class="tm-message-time"><?= e(ticket_date($latestMessage['sent_at'] ?? null)) ?></span></div>
  <h3><?= e($latestMessage['subject'] ?: 'No subject') ?></h3>
  <?php ticket_facts(['Sender name'=>$latestMessage['sender_name'] ?? null,'Participant name'=>$latestMessage['participant_name'] ?? null,'Participant address'=>$latestMessage['participant_address'] ?? null]); ?>
  <div class="tm-message-body tm-email-body"><?= ticket_email_html($latestMessage) ?></div>
<?php else: ?><p class="tm-empty-inline">No email is available for this record.</p><?php endif; ?>
</div></section>
