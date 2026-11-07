<?php
require __DIR__.'/../../app/bootstrap.php';$user=require_role('customer');
$reference=post_string('reference') ?: (is_string($_GET['reference']??null)?$_GET['reference']:'');
try{$a=owned_appointment((int)$user['id'],$reference);}catch(BookingValidationException $e){http_response_code(404);$pageTitle='Appointment not found';require __DIR__.'/../../app/components/header.php';echo '<section class="container page-intro"><h1>Appointment<br><em>not found.</em></h1></section>';require __DIR__.'/../../app/components/footer.php';exit;}
if($a['booking_mode']==='demo'){require __DIR__.'/../../app/components/demo-checkout.php';exit;}
$choice=post_string('payment_choice') ?: (is_string($_GET['payment_choice']??null)?$_GET['payment_choice']:'full');$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        if(post_string('terms')!=='1')throw new BookingValidationException('Accept the payment and booking terms before continuing.');
        $attempt=initiate_payment((int)$user['id'],$reference,$choice,post_string('revision'),post_string('request_key'));
        if($attempt['status']==='pending' && $attempt['checkout_url'] && safe_checkout_url($attempt['checkout_url'])){header('Location: '.$attempt['checkout_url'],true,303);exit;}
        redirect('/customer/payment-result.php?attempt='.$attempt['public_id']);
    } catch (BookingValidationException $e) {
        $errors[]=$e->getMessage(); http_response_code(422);
    } catch (PaymentUnavailable $e) {
        error_log('Checkout unavailable: '.$e->getMessage());
        $errors[]='Online payment is currently unavailable. Please try again later.'; http_response_code(422);
    }
}
$summary=payment_summary($a);$readiness=payment_readiness();$rules=payment_rules();$downpayment=null;
try{$downpayment=payment_plan($a,'downpayment');}catch(BookingValidationException $e){$downReason=$e->getMessage();}
try{$plan=payment_plan($a,$choice);}catch(BookingValidationException $e){$plan=null;$errors[]=$e->getMessage();}
$key=$_SESSION['payment_keys'][$a['id']][$a['revision']]??=bin2hex(random_bytes(32));
$canPay=in_array($a['status'],['confirmed','awaiting_payment'],true) && local_time($a['starts_at'])>new DateTimeImmutable('now',spa_timezone()) && ($a['status']!=='awaiting_payment' || ($a['hold_expires_at'] && local_time($a['hold_expires_at'])>new DateTimeImmutable('now',spa_timezone())));
$pageTitle='Payment for your visit';require __DIR__.'/../../app/components/header.php';
?>
<section class="container booking-heading"><p class="eyebrow">Your visit / Payment</p><h1>A clear plan.<br>A calm <em>arrival.</em></h1><p>Review the price and payment status for your reservation.</p></section>
<div class="container booking-layout"><article class="booking-sheet"><p class="eyebrow">Booking reference</p><?php $copyReference=$reference; require __DIR__.'/../../app/components/booking-reference.php'; ?><h2><?= e($a['service_name_snapshot'] ?: $a['catalog_name']) ?></h2><dl class="details-grid"><div><dt>Room & guests</dt><dd><?= e($a['room_code']) ?> · <?= e($a['guest_count']) ?> guest(s)</dd></div><div><dt>Appointment</dt><dd><?= e(local_time($a['starts_at'])->format('F j, Y · g:i A')) ?><small>Philippine time · <?= e($a['duration_minutes_snapshot']) ?> minutes</small></dd></div></dl>
<dl class="summary-details"><div><dt>Base amount</dt><dd><?= peso_amount(decimal_centavos($a['base_amount']??$a['total_amount'])) ?></dd></div><div><dt>Additional guests</dt><dd><?= peso_amount(decimal_centavos($a['extra_amount']??'0')) ?></dd></div><div><dt>Total</dt><dd><?= peso_amount($summary['total']) ?></dd></div><div><dt>Verified amount paid</dt><dd><?= peso_amount($summary['paid']) ?></dd></div><div><dt>Remaining balance</dt><dd><?= peso_amount($summary['balance']) ?></dd></div></dl>
<?php form_errors($errors); ?>
<?php if(!$readiness['ready']): ?><div class="notice"><strong>Online payment is currently unavailable.</strong> You can still review your reservation and any payments already recorded.</div><?php endif; ?>
<?php if($a['status']==='awaiting_payment' && $a['hold_expires_at']): ?><p class="notice">This reservation is held until <?= e(local_time($a['hold_expires_at'])->format('F j, Y · g:i:s A')) ?> Philippine time. An expired hold cannot be confirmed through a late payment automatically.</p><?php endif; ?>
<?php if ($readiness['ready'] && $canPay): ?><form method="get" class="payment-options"><input type="hidden" name="reference" value="<?= e($reference) ?>"><fieldset><legend class="field-heading">Choose a payment option.</legend><label><input type="radio" name="payment_choice" value="full" <?= $choice!=='downpayment'?'checked':'' ?>> <?= $summary['paid']>0?'Remaining balance':'Full payment' ?> · <?= peso_amount($summary['balance']) ?></label><label><input type="radio" name="payment_choice" value="downpayment" <?= !$downpayment?'disabled':'' ?> <?= $choice==='downpayment'?'checked':'' ?>> Downpayment <?= $downpayment?'· '.e($rules['downpayment_percentage']).'% · '.peso_amount($downpayment['amount']):'— unavailable' ?></label><?php if(!$downpayment): ?><p class="small-note"><?= e($downReason) ?></p><?php endif; ?></fieldset><button type="submit" class="text-link">Update payment summary <span>↗</span></button></form>
<form method="post"><?= csrf_field() ?><?php foreach(['reference'=>$reference,'revision'=>$a['revision'],'request_key'=>$key,'payment_choice'=>$choice] as $key=>$value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?><label class="check-label"><input type="checkbox" name="terms" value="1" required><span>I accept the <a href="<?= e(url('/terms.php')) ?>" target="_blank" rel="noopener">booking and payment terms</a>. Refund eligibility remains subject to the published policy and administrator review.</span></label><button class="button full-width" type="submit" <?= !$readiness['ready'] || !$canPay || !$plan?'disabled':'' ?>>Continue to secure payment <span>↗</span></button></form>
<p class="small-note">Your payment status updates after the payment provider verifies the transaction.</p><?php endif; ?>
</article><aside class="booking-aside summary-panel"><p class="eyebrow">Your payment summary</p><h2>Before you<br><em>continue.</em></h2><div class="summary-total"><span>Amount due now</span><strong><?= $readiness['ready']&&$canPay&&$plan?peso_amount($plan['amount']):'—' ?></strong></div><div class="summary-total"><span>Balance after verified payment</span><strong><?= $readiness['ready']&&$canPay&&$plan?peso_amount($plan['remaining']):'—' ?></strong></div><p class="small-note">Your recorded amount and outstanding balance are shown in the reservation details.</p><a class="text-link" href="<?= e(url('/customer/appointment.php?reference='.rawurlencode($reference))) ?>">Appointment details <span>↗</span></a></aside></div>
<?php require __DIR__.'/../../app/components/footer.php'; ?>
