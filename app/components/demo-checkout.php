<?php
// Rendered through the existing customer/payment.php route after authentication/ownership checks.
$errors=[];$choice=post_string('payment_choice') ?: (is_string($_GET['payment_choice']??null)?$_GET['payment_choice']:'full');
$method=$_SERVER['REQUEST_METHOD']==='POST'?post_string('method'):(is_string($_GET['method']??null)?$_GET['method']:'demo_gcash');
if($_SERVER['REQUEST_METHOD']!=='POST'&&!isset(DEMO_METHODS[$method]))$method='demo_gcash';
if($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        if(post_string('action')==='cancel')cancel_demo_hold((int)$user['id'],$reference,post_string('revision'),post_string('request_key'));
        else {
            if(post_string('terms')!=='1')throw new BookingValidationException('Accept the booking terms before recording the test payment.');
            complete_demo_payment((int)$user['id'],$reference,$choice,$method,post_string('revision'),post_string('request_key'),post_string('expected_amount'));
        }
        redirect('/customer/appointment.php?reference='.rawurlencode($reference));
    }catch(BookingValidationException $e){$errors[]=$e->getMessage();http_response_code(422);}
} else expire_payment_holds();
$a=owned_appointment((int)$user['id'],$reference);$summary=demo_summary($a);$rules=demo_rules();$plan=null;
$canPay=demo_enabled()&&in_array($a['status'],['awaiting_payment','confirmed'],true)&&local_time($a['starts_at'])>new DateTimeImmutable('now',spa_timezone())&&$summary['balance']>0;
if($canPay){try{$plan=demo_plan($a,$choice);}catch(BookingValidationException $e){$errors[]=$e->getMessage();}}
$key=$_SESSION['demo_keys'][$a['id']][$a['revision']]??=bin2hex(random_bytes(32));
$pageTitle = 'Payment Summary';
$pageScripts = ['/assets/js/demo-checkout.js'];
$appointmentStart = local_time($a['starts_at']);
$appointmentEnd = local_time($a['ends_at']);
require __DIR__.'/header.php';
?>
<section class="container booking-heading checkout-heading">
    <p class="eyebrow">Your reservation</p>
    <h1>Payment <em>Summary</em></h1>
    <p>Review your reservation and choose how to record your payment.</p>
</section>
<div class="container booking-layout checkout-layout">
    <aside class="summary-panel checkout-summary" aria-label="Reservation and payment summary">
        <p class="eyebrow">Reservation details</p>
        <h2><?= e($a['service_name_snapshot'] ?: $a['catalog_name']) ?></h2>
        <?php $copyReference=$reference; require __DIR__.'/booking-reference.php'; ?>
        <dl class="summary-details">
            <div><dt>Guest</dt><dd><?= e($user['full_name']) ?></dd></div>
            <div><dt>Room</dt><dd><?= e($a['room_code']) ?> · <?= e($a['guest_count']) ?> <?= (int)$a['guest_count']===1?'guest':'guests' ?></dd></div>
            <div><dt>Appointment</dt><dd><?= e($appointmentStart->format('F j, Y · g:i A').' – '.$appointmentEnd->format('g:i A')) ?><br>Philippine time</dd></div>
            <div><dt>Therapist</dt><dd><?= e(staff_display_name($a['therapist_names'] ?: 'Pending assignment')) ?></dd></div>
            <div><dt>Payment Status</dt><dd><?= e(payment_status_label($a)) ?></dd></div>
            <div><dt>Total Amount</dt><dd><?= peso_amount($summary['total']) ?></dd></div>
            <div><dt>Amount Recorded</dt><dd><?= peso_amount($summary['paid']) ?></dd></div>
            <div><dt>Outstanding Balance</dt><dd><?= peso_amount($summary['balance']) ?></dd></div>
        </dl>
        <?php if ($plan): ?>
            <div class="summary-total"><span>Amount to record</span><strong><?= peso_amount($plan['amount']) ?></strong></div>
            <p class="small-note checkout-balance">Balance after checkout: <?= peso_amount($plan['remaining']) ?></p>
        <?php endif; ?>
    </aside>
    <article class="booking-sheet checkout-payment">
        <p class="checkout-disclosure" role="note">Test checkout — No actual funds will be transferred.</p>
        <?php form_errors($errors); ?>
        <?php if ($a['status']==='awaiting_payment' && $a['hold_expires_at']): ?>
            <p class="small-note checkout-hold">Your room and therapist are held until <?= e(local_time($a['hold_expires_at'])->format('F j, Y · g:i A')) ?> Philippine time. Complete checkout to confirm your reservation.</p>
        <?php endif; ?>
        <?php if ($canPay): ?>
            <form method="get" class="payment-options">
                <input type="hidden" name="reference" value="<?= e($reference) ?>">
                <fieldset>
                    <legend class="field-heading">Payment options</legend>
                    <label class="checkout-option"><input type="radio" name="payment_choice" value="full" <?= $choice!=='downpayment'?'checked':'' ?>>
                        <span><?= $summary['paid']>0?'Outstanding Balance':'Full payment' ?></span><strong><?= peso_amount($summary['balance']) ?></strong>
                    </label>
                    <?php if ($summary['paid']===0): ?>
                        <label class="checkout-option"><input type="radio" name="payment_choice" value="downpayment" <?= $choice==='downpayment'?'checked':'' ?>>
                            <span>Downpayment</span><strong><?= e($rules['demo_downpayment_percentage']) ?>%</strong>
                        </label>
                    <?php endif; ?>
                </fieldset>
                <button class="text-link" type="submit">Update payment summary ↗</button>
            </form>
            <?php if ($plan): ?>
                <form method="post" class="checkout-method">
                    <?= csrf_field() ?>
                    <?php foreach (['reference'=>$reference,'revision'=>$a['revision'],'request_key'=>$key,'payment_choice'=>$choice,'expected_amount'=>$plan['amount']] as $field=>$value): ?>
                        <input type="hidden" name="<?= e($field) ?>" value="<?= e($value) ?>">
                    <?php endforeach; ?>
                    <div class="field"><label for="demo-method">Payment Method</label><select name="method" id="demo-method" required>
                        <?php foreach (DEMO_METHODS as $value=>$label): ?><option value="<?= e($value) ?>" <?= $method===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select></div>
                    <label class="check-label"><input type="checkbox" name="terms" value="1" required><span>I accept the <a href="<?= e(url('/terms.php')) ?>" target="_blank" rel="noopener">booking terms</a>.</span></label>
                    <button class="button full-width" name="action" value="complete" type="submit"><?= $a['status']==='awaiting_payment' ? 'Confirm reservation' : 'Record payment' ?> <span aria-hidden="true">↗</span></button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <div class="notice"><?= $summary['balance']===0?'The full amount has been recorded.':'Checkout is unavailable for this reservation.' ?></div>
        <?php endif; ?>
        <?php if ($a['status']==='awaiting_payment'): ?>
            <form method="post" class="inline-actions">
                <?= csrf_field() ?>
                <?php foreach (['reference'=>$reference,'revision'=>$a['revision'],'request_key'=>bin2hex(random_bytes(32))] as $field=>$value): ?>
                    <input type="hidden" name="<?= e($field) ?>" value="<?= e($value) ?>">
                <?php endforeach; ?>
                <button class="text-link" name="action" value="cancel" type="submit">Release this time</button>
            </form>
        <?php endif; ?>
        <a class="text-link" href="<?= e(url('/customer/appointment.php?reference='.rawurlencode($reference))) ?>">View reservation ↗</a>
    </article>
</div>
<?php require __DIR__.'/footer.php'; ?>
