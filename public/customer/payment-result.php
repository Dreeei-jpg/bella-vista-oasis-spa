<?php
require __DIR__.'/../../app/bootstrap.php';$user=require_role('customer');$publicId=post_string('attempt') ?: (is_string($_GET['attempt']??null)?$_GET['attempt']:'');$errors=[];
try{$attempt=payment_attempt_owned((int)$user['id'],$publicId);}catch(BookingValidationException $e){http_response_code(404);$pageTitle='Payment not found';require __DIR__.'/../../app/components/header.php';echo '<section class="container page-intro"><h1>Payment attempt<br><em>not found.</em></h1></section>';require __DIR__.'/../../app/components/footer.php';exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{if(post_string('action')==='cancel')cancel_checkout((int)$user['id'],$publicId);elseif(post_string('action')==='verify')reconcile_payment_attempt($attempt);else throw new BookingValidationException('Choose a valid payment action.');redirect('/customer/payment-result.php?attempt='.$publicId);}
    catch (BookingValidationException $e) { $errors[]=$e->getMessage(); http_response_code(422); }
    catch (PaymentUnavailable $e) {
        error_log('Payment verification unavailable: '.$e->getMessage());
        $errors[]='We could not refresh the payment status. Please try again later.';
        http_response_code(422);
    }
}
$a=owned_appointment((int)$user['id'],$attempt['booking_reference']);$summary=payment_summary($a);
$state=$summary['review'] || $attempt['status']==='requires_review'?'requires_review':$attempt['status'];
$states=['initiated'=>['A moment of verification.','Your checkout attempt is recorded. No successful payment has been verified.'],'pending'=>['Awaiting verification.','The provider has not yet supplied a verified payment result.'],'verified'=>['Payment verified.','The payment provider verified this transaction. Review your appointment status separately below.'],'failed'=>['Payment failed.','No successful payment has been credited for this attempt.'],'cancelled'=>['Checkout cancelled.','Checkout cancellation does not issue a refund or cancel an already confirmed appointment.'],'expired'=>['Checkout expired.','An expired hold no longer reserves resources. A late received payment requires administrator review.'],'requires_review'=>['A little assistance is needed.','The spa needs to review this payment. No automatic refund or replacement booking has been made.']];
if(in_array($state,['initiated','pending'],true) && local_time($attempt['expires_at'])<=new DateTimeImmutable('now',spa_timezone()))$state='expired';
[$title,$description]=$states[$state]??$states['requires_review'];
$canVerify=payment_verification_ready();
$canCancel=in_array($attempt['status'],['initiated','pending'],true);
$pageTitle='Payment result';require __DIR__.'/../../app/components/header.php';
?>
<section class="container booking-heading"><p class="eyebrow">Payment attempt / <?= e($publicId) ?></p><h1><?= e($title) ?></h1><p><?= e($description) ?></p></section><section class="container booking-sheet result-sheet"><?php form_errors($errors); ?><dl class="details-grid"><div><dt>Payment Status</dt><dd><?= e(str_replace('_',' ',$state)) ?></dd></div><div><dt>Attempt amount</dt><dd><?= peso_amount((int)$attempt['amount_centavos']) ?></dd></div><div><dt>Appointment state</dt><dd><?= e(str_replace('_',' ',$a['status'])) ?></dd></div><?php if($a['status']==='confirmed'): ?><div><dt>Confirmed booking reference</dt><dd><?= e($a['booking_reference']) ?></dd></div><?php endif; ?><div><dt>Verified timestamp</dt><dd><?= $attempt['verified_at']?e(local_time($attempt['verified_at'])->format('F j, Y · g:i A')).' Philippine time':'Not verified' ?></dd></div></dl><?php if ($canVerify || $canCancel): ?><form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="attempt" value="<?= e($publicId) ?>"><?php if ($canVerify): ?><button class="button" name="action" value="verify">Refresh payment status <span>↗</span></button><?php endif; ?><?php if ($canCancel): ?><button class="text-link" name="action" value="cancel">Cancel this checkout</button><?php endif; ?></form><?php endif; ?><a class="text-link" href="<?= e(url('/customer/appointment.php?reference='.rawurlencode($a['booking_reference']))) ?>">View appointment <span>↗</span></a></section>
<?php require __DIR__.'/../../app/components/footer.php'; ?>
