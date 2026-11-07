<?php
require __DIR__.'/../../app/bootstrap.php';
$user=require_role('customer');$reference=post_string('reference') ?: (is_string($_GET['reference']??null)?$_GET['reference']:'');
try { $a=owned_appointment((int)$user['id'],$reference); } catch(BookingValidationException $e) { http_response_code(404);$pageTitle='Appointment not found';require __DIR__.'/../../app/components/header.php';echo '<section class="container page-intro"><h1>Appointment<br><em>not found.</em></h1></section>';require __DIR__.'/../../app/components/footer.php';exit; }
$errors=[];$reason=post_string('reason');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try { cancel_appointment((int)$user['id'],$reference,$reason,post_string('revision'),post_string('request_key'));redirect('/customer/appointment.php?reference='.rawurlencode($reference)); }
    catch(BookingValidationException $e){$errors[]=$e->getMessage();http_response_code(422);}
}
$eligibility=change_eligibility($a);$summary=payment_summary($a);$key=bin2hex(random_bytes(32));
$pageTitle='Review cancellation';require __DIR__.'/../../app/components/header.php';
?>
<section class="container booking-heading"><p class="eyebrow">Your plans / Cancellation review</p><h1>A change<br>of <em>plans.</em></h1><p>Review your appointment and the cancellation policy before confirming.</p></section>
<div class="container booking-layout">
<article class="booking-sheet"><h2><?= e($a['service_name_snapshot'] ?: $a['catalog_name']) ?></h2><p class="eyebrow">Booking reference</p><?php $copyReference=$reference; require __DIR__.'/../../app/components/booking-reference.php'; ?><dl class="details-grid"><div><dt>Appointment</dt><dd><?= e(local_time($a['starts_at'])->format('F j, Y · g:i A')) ?><small>Philippine time</small></dd></div><div><dt>Cancellation deadline</dt><dd><?= e(appointment_deadline($a)->format('F j, Y · g:i A')) ?><small>Requests at the exact deadline are eligible</small></dd></div><div><dt>Amount recorded</dt><dd><?= peso_amount($summary['paid']) ?></dd></div><div><dt>Refund status</dt><dd><?= $summary['paid']>0 || $summary['review'] ? 'Pending policy / administrator review if cancelled' : 'No verified payment to refund' ?></dd></div></dl>
<?php if($summary['is_demo']): ?><p class="notice"><strong>Test checkout — No actual funds will be transferred.</strong> An administrator reviews any refund request.</p><?php endif; ?><div class="notice"><strong>At least six hours’ notice is required.</strong> <?php if($summary['is_demo']): ?>Late requests require administrator review. A late cancellation forfeits the recorded downpayment in Test Mode.<?php else: ?>Late cancellations forfeit the applicable downpayment. This self-service form rejects late requests. Detailed refund rules are unapproved; no refund is issued automatically, and full payments are not assumed nonrefundable.<?php endif; ?></div>
<?php form_errors($errors); ?>
<?php if($eligibility['eligible']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="reference" value="<?= e($reference) ?>"><input type="hidden" name="revision" value="<?= e($a['revision']) ?>"><input type="hidden" name="request_key" value="<?= e($key) ?>"><div class="field"><label for="cancel-reason">Reason for cancellation</label><textarea id="cancel-reason" name="reason" minlength="3" maxlength="1000" required rows="4"><?= e($reason) ?></textarea></div><button class="button clay-button" type="submit">Confirm cancellation <span>↗</span></button></form><?php else: ?><div class="notice" role="status"><?= e($eligibility['reason']) ?></div><?php endif; ?>
</article><aside class="booking-aside summary-panel"><p class="eyebrow">Your appointment record</p><h2>Carefully<br><em>kept.</em></h2><p>A cancellation releases the room and therapist. Your appointment, payment records and cancellation reason remain in your history.</p><a class="text-link" href="<?= e(url('/customer/appointment.php?reference='.rawurlencode($reference))) ?>">Keep my appointment <span>↗</span></a></aside></div>
<?php require __DIR__.'/../../app/components/footer.php'; ?>
