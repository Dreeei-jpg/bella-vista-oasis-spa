<?php
require __DIR__ . '/../../app/bootstrap.php';
$user=require_role('customer');
expire_payment_holds();
$appointments = customer_appointments((int)$user['id']);
$upcoming=[]; $previous=[];
foreach($appointments as $appointment) {
    $date=new DateTimeImmutable($appointment['ends_at'],new DateTimeZone('UTC'));
    $holdExpired=$appointment['status']==='awaiting_payment' && (!$appointment['hold_expires_at'] || local_time($appointment['hold_expires_at'])->getTimestamp()<=time());
    if(!$holdExpired && $date->getTimestamp()>=time() && in_array($appointment['status'],['pending','confirmed','ongoing','awaiting_payment'],true)) $upcoming[]=$appointment; else $previous[]=$appointment;
}
$upcoming=array_reverse($upcoming);
$pageTitle='My Appointments'; require __DIR__ . '/../../app/components/header.php';
?>
<section class="container dashboard-head">
<div>
<p class="eyebrow">Your private corner</p>
<h1>Hello, <em>
<?= e(explode(' ',$user['full_name'])[0]) ?>.</em>
</h1>
<p>Review your upcoming reservations, past visits, and payment history.</p>
</div>
<form action="<?= e(url('/auth/logout.php')) ?>" method="post">
<?= csrf_field() ?>
<button class="text-link" type="submit">Sign out ↗</button>
</form>
</section>
<div class="container dashboard-layout">
<aside class="account-card">
<p class="eyebrow">Your account</p>
<h2>
<?= e($user['full_name']) ?>
</h2>
<dl>
<dt>Email address</dt>
<dd>
<?= e($user['email']) ?>
</dd>
<dt>Phone number</dt>
<dd>
<?= e($user['phone']?:'Not provided') ?>
</dd>
<dt>Member since</dt>
<dd>
<?= e(date('F Y',strtotime($user['created_at']))) ?>
</dd>
</dl>
</aside>
<div class="appointment-sections">
<div class="notice">
<strong>My Appointments</strong> <a class="text-link" href="<?= e(url('/booking.php')) ?>">Book a visit ↗</a></div>
<?php foreach(['Upcoming appointments'=>$upcoming,'Previous appointments'=>$previous] as $label=>$records): ?>
<section>
<h2>
<?= e($label) ?>
</h2>
<?php if(!$records): ?>
<div class="empty-state">
<span aria-hidden="true">◯</span>
<h3>
<?= $label==='Upcoming appointments'?'A moment still to come.':'A fresh beginning.' ?>
</h3>
<p>
<?= $label==='Upcoming appointments'?'You have no upcoming appointments. Make room for your next moment of calm.':'Your past visits will appear here.' ?>
</p>
<?php if($label==='Upcoming appointments'): ?>
<a class="text-link" href="<?= e(url('/services.php')) ?>">Explore treatments <span>↗</span>
</a>
<?php endif; ?>
</div>
<?php else: foreach($records as $record): $local=local_time($record['starts_at']);$summary=payment_summary($record);$eligibility=change_eligibility($record); ?>
<article class="appointment">
<p class="eyebrow">
<span class="booking-reference"><?= e($record['booking_reference']) ?></span> · <?= e(appointment_status_label($record['status'])) ?>
</p>
<h3>
<?= e($record['service_name']) ?>
</h3>
<p>
<?= e($local->format('F j, Y · g:i A').'–'.local_time($record['ends_at'])->format('g:i A')) ?> (Philippine time)</p>
<p>
<?= e($record['room_code']) ?> · <?= e($record['guest_count']) ?> guest(s) · <?= money($record['total_amount']) ?>
</p>
<p>Therapist: <?= e(staff_display_name($record['therapist_names'] ?: 'Not assigned')) ?> · Payment: <?= e(payment_status_label($record)) ?></p>
<div class="payment-summary-line"><div><span>Amount recorded</span><br><?= peso_amount($summary['paid']) ?></div><div><span>Outstanding Balance</span><br><?= peso_amount($summary['balance']) ?></div></div>
<a class="text-link" href="<?= e(url('/customer/appointment.php?reference='.rawurlencode($record['booking_reference']))) ?>">View appointment <span>↗</span></a>
<div class="inline-actions"><?php if($eligibility['eligible']): ?><a class="text-link" href="<?= e(url('/customer/cancel.php?reference='.rawurlencode($record['booking_reference']))) ?>">Cancel</a><a class="text-link" href="<?= e(url('/customer/reschedule.php?reference='.rawurlencode($record['booking_reference']))) ?>">Reschedule</a><?php else: ?><span class="small-note">Changes are unavailable for this reservation.</span><?php endif; ?><a class="text-link" href="<?= e(url('/customer/payment.php?reference='.rawurlencode($record['booking_reference']))) ?>">Payment & balance</a></div>
</article>
<?php endforeach; endif; ?>
</section>
<?php endforeach; ?>
<?php $q=db()->prepare("SELECT c.action,c.created_at,c.before_json,c.after_json,a.booking_reference FROM appointment_changes c JOIN appointments a ON a.id=c.appointment_id WHERE a.user_id=? AND c.action IN ('rescheduled','admin_reschedule','cancelled','admin_cancel') ORDER BY c.created_at DESC,c.id DESC LIMIT 100");$q->execute([$user['id']]);$history=$q->fetchAll();if($history): ?><section class="change-history"><h2>Booking change history</h2><ul><?php foreach($history as $change):$before=json_decode($change['before_json'],true);$after=json_decode($change['after_json'],true); ?><li><a href="<?= e(url('/customer/appointment.php?reference='.$change['booking_reference'])) ?>"><?= e($change['booking_reference']) ?></a> · <?= e(str_replace('_',' ',$change['action'])) ?> · <?= e(local_time($change['created_at'])->format('M j, Y · g:i A')) ?><?php if(isset($before['starts_at'],$after['starts_at'])): ?><br><?= e(local_time($before['starts_at'])->format('M j · g:i A').' → '.local_time($after['starts_at'])->format('M j · g:i A')) ?><?php endif; ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<section><h2>Payment history</h2><?php $paymentRecords=customer_payment_records((int)$user['id']);require __DIR__.'/../../app/components/payment-records.php'; ?></section>
</div>
</div>
<?php require __DIR__ . '/../../app/components/footer.php'; ?>
