<?php
expire_payment_holds();
$daily = admin_daily($date);
if ($view === 'overview') $counts = array_count_values(array_column(admin_room_states(),'state'));
?>
<form method="get" class="inline-actions"><div class="field"><label for="report-date">Report date</label><input id="report-date" type="date" name="date" value="<?= e($date) ?>" required></div><button class="button" type="submit">Show daily summary ↗</button></form>
<?php if ($view === 'overview'): ?>
<div class="admin-metrics"><?php foreach(['Appointments on selected date'=>$daily['total'],'Confirmed on selected date'=>$daily['confirmed'],'Cancelled on selected date'=>$daily['cancelled'],'Upcoming active appointments'=>$daily['upcoming'],'Active therapists scheduled that day'=>$daily['scheduled_therapists']] as $label=>$value): ?><article><p><?= e($label) ?></p><strong><?= e($value) ?></strong></article><?php endforeach; ?></div>
<h2>Rooms, right now.</h2><p class="small-note">Current local time: <?= e((new DateTimeImmutable('now',spa_timezone()))->format('F j · g:i A')) ?>. Closed hours, inactive rooms and maintenance blocks are reported separately.</p>
<div class="admin-metrics"><?php foreach(['available'=>'Available','occupied'=>'Occupied / reserved','cleaning'=>'Cleaning','closed'=>'Closed','blocked'=>'Blocked','inactive'=>'Inactive'] as $state=>$label): ?><article><p><?= e($label) ?></p><strong><?= e($counts[$state]??0) ?></strong></article><?php endforeach; ?></div>
<div class="inline-actions"><a class="button" href="<?= e(url('/admin/appointments.php')) ?>">Manage appointments ↗</a><a class="text-link" href="<?= e(url('/admin/calendar.php?date='.$date)) ?>">View reservation calendar ↗</a></div>
<?php endif; ?>
<h2>Financial Overview</h2>
<p class="checkout-disclosure" role="note">Test checkout — No actual funds will be transferred.</p>
<div class="admin-metrics">
    <article><p>Test Amount Recorded</p><strong><?= peso_amount($daily['demo_revenue']) ?></strong></article>
    <article><p>Outstanding Test Balance</p><strong><?= peso_amount($daily['demo_outstanding']) ?></strong></article>
    <article><p>Verified Provider Receipts (PHP)</p><strong><?= peso_amount($daily['verified_receipts']) ?></strong></article>
</div>
<p class="small-note">Recorded amounts use the payment date. Outstanding test balances use the appointment date and exclude cancelled or expired reservations and incomplete checkouts. Verified provider receipts exclude refunds and payments under review.</p>
<a class="text-link" href="<?= e(url('/admin/payments.php?date='.$date)) ?>">View payment history for this date ↗</a>
