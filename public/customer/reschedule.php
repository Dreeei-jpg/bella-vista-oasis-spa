<?php
require __DIR__.'/../../app/bootstrap.php';$user=require_role('customer');
$reference=post_string('reference') ?: (is_string($_GET['reference']??null)?$_GET['reference']:'');
try{$a=owned_appointment((int)$user['id'],$reference);}catch(BookingValidationException $e){http_response_code(404);$pageTitle='Appointment not found';require __DIR__.'/../../app/components/header.php';echo '<section class="container page-intro"><h1>Appointment<br><em>not found.</em></h1></section>';require __DIR__.'/../../app/components/footer.php';exit;}
$errors=[];$result=null;$proposal=null;$date=post_string('date') ?: (is_string($_GET['date']??null)?$_GET['date']:'');$time=post_string('time') ?: (is_string($_GET['time']??null)?$_GET['time']:'');
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{reschedule_appointment((int)$user['id'],$reference,$date,$time,post_string('revision'),post_string('request_key'));redirect('/customer/appointment.php?reference='.rawurlencode($reference));}
    catch(BookingValidationException $e){$errors[]=$e->getMessage();http_response_code(422);}
}
$eligibility=change_eligibility($a);
if($date!=='' && $eligibility['eligible']){
    try{
        $result=reschedule_availability((int)$user['id'],$reference,$date);
        if($time!==''){
            $slot=array_column($result['slots'],null,'time')[$time]??null;
            if(!$slot || !$slot['available'])throw new BookingValidationException('The proposed time is unavailable. Your original appointment is unchanged.');
            $proposal=appointment_window(reschedule_service($a),$date,$time);
        }
    }catch(BookingValidationException $e){$errors[]=$e->getMessage();}
}
$pageTitle='Reschedule your visit';require __DIR__.'/../../app/components/header.php';
?>
<section class="container booking-heading"><p class="eyebrow">Your plans / Rescheduling</p><h1>The same care.<br>A new <em>moment.</em></h1><p>Change only the date and time. Your service, room, guest count, price and payment history stay with this appointment.</p></section>
<div class="container booking-layout"><article class="booking-sheet"><p class="eyebrow">Booking reference</p><?php $copyReference=$reference; require __DIR__.'/../../app/components/booking-reference.php'; ?><h2><?= e($a['service_name_snapshot'] ?: $a['catalog_name']) ?></h2><p>Currently <?= e(local_time($a['starts_at'])->format('F j, Y · g:i A')) ?> · <?= e($a['room_code']) ?> · Philippine time</p><p class="small-note">Change deadline: <?= e(appointment_deadline($a)->format('F j, Y · g:i A')) ?> Philippine time. Service, guest or price changes require a separate payment-adjustment workflow.</p>
<?php form_errors($errors); ?>
<?php if($eligibility['eligible']): ?>
<form method="get"><input type="hidden" name="reference" value="<?= e($reference) ?>"><div class="field"><label for="new-date">New date · Philippine time</label><input type="date" id="new-date" name="date" min="<?= (new DateTimeImmutable('now',spa_timezone()))->format('Y-m-d') ?>" value="<?= e($date) ?>" required></div><button class="text-link" type="submit">Find replacement times <span>↗</span></button></form>
<?php if($result): ?><form method="get" class="replacement-form"><input type="hidden" name="reference" value="<?= e($reference) ?>"><input type="hidden" name="date" value="<?= e($date) ?>"><fieldset class="slot-fieldset"><legend class="field-heading">Choose a replacement time.</legend><div class="time-slots"><?php foreach($result['slots'] as $slot): ?><label class="time-slot <?= !$slot['available']?'unavailable':'' ?>"><input type="radio" name="time" value="<?= e($slot['time']) ?>" required <?= $time===$slot['time']?'checked':'' ?> <?= !$slot['available']?'disabled':'' ?>><span><?= e($slot['label']) ?><small><?= e($slot['reason'] ?: 'Available') ?></small></span></label><?php endforeach; ?></div></fieldset><button class="button" type="submit">Review proposed change <span>↗</span></button></form><?php endif; ?>
<?php if($proposal): ?><section class="proposal-review"><p class="eyebrow">Review before confirming</p><h3><?= e($proposal['start']->format('F j, Y · g:i A')) ?></h3><p>Until <?= e($proposal['end']->format('g:i A')) ?>, Philippine time · Same room and price: <?= peso_amount(decimal_centavos($a['total_amount'])) ?></p><p>Your original time stays reserved until the replacement is saved successfully.</p><form method="post"><?= csrf_field() ?><?php foreach(['reference'=>$reference,'date'=>$date,'time'=>$time,'revision'=>$a['revision'],'request_key'=>bin2hex(random_bytes(32))] as $key=>$value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?><button class="button" type="submit">Confirm reschedule <span>↗</span></button></form></section><?php endif; ?>
<?php else: ?><div class="notice"><?= e($eligibility['reason']) ?></div><?php endif; ?>
</article><aside class="booking-aside summary-panel"><p class="eyebrow">A considered change</p><h2>One visit.<br><em>Rearranged.</em></h2><p>We recheck room preparation time and therapist availability when you confirm. If a time is taken, your existing appointment stays unchanged.</p><a class="text-link" href="<?= e(url('/customer/appointment.php?reference='.rawurlencode($reference))) ?>">Back to my appointment <span>↗</span></a></aside></div>
<?php require __DIR__.'/../../app/components/footer.php'; ?>
