<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = require_role('customer');
$reference = is_string($_GET['reference'] ?? null) ? $_GET['reference'] : '';
$appointment = null;
foreach (customer_appointments((int)$user['id']) as $record) {
    if ($record['booking_reference'] === $reference) { $appointment = $record; break; }
}
if (!$appointment) {
    http_response_code(404);
    $pageTitle = 'Appointment not found';
    require __DIR__ . '/../../app/components/header.php';
    echo '<section class="container page-intro"><p class="eyebrow">404 / Appointment unavailable</p><h1>A private<br><em>moment.</em></h1><p>This appointment could not be found in your account.</p><a class="text-link" href="'.e(url('/customer/dashboard.php')).'">Return to my account ↗</a></section>';
    require __DIR__ . '/../../app/components/footer.php'; exit;
}
$declaration = health_declaration((int)$user['id'], (int)$appointment['health_declaration_id']);
$answers = $declaration ? json_decode($declaration['responses_json'], true, 512, JSON_THROW_ON_ERROR) : [];
$start = local_time($appointment['starts_at']);
$end = local_time($appointment['ends_at']);
$arrival = $start->modify('-'.(int)settings()['arrival_minutes'].' minutes');
$summary=payment_summary($appointment);$demoAppointmentId=(int)$appointment['id'];
$eligibility=change_eligibility($appointment);
$paymentRecords=customer_payment_records((int)$user['id'],(int)$appointment['id']);
$q=db()->prepare('SELECT * FROM appointment_changes WHERE appointment_id=? ORDER BY id DESC');$q->execute([$appointment['id']]);$changes=$q->fetchAll();
$q=db()->prepare('SELECT * FROM cancellation_records WHERE appointment_id=?');$q->execute([$appointment['id']]);$cancellation=$q->fetch();
$q=db()->prepare('SELECT * FROM refund_requests WHERE appointment_id=? ORDER BY id DESC LIMIT 1');$q->execute([$appointment['id']]);$refund=$q->fetch();
$pageTitle = 'Your appointment / '.$reference;
require __DIR__ . '/../../app/components/header.php';
?>
<section class="container booking-heading confirmation-heading">
    <p class="eyebrow">Your appointment</p>
    <h1><?= $appointment['status'] === 'confirmed' ? 'Reservation' : 'Your visit,' ?><br><em><?= $appointment['status'] === 'confirmed' ? 'Confirmed' : 'in view.' ?></em></h1>
    <p><?= $appointment['status'] === 'confirmed' ? 'Your room and therapist are reserved.' : 'Your appointment details are saved in your account.' ?> You can review your payment status and visit details below.</p>
</section>
<div class="container booking-layout appointment-layout">
    <article class="booking-sheet appointment-details">
        <p class="eyebrow">Booking reference</p><?php $copyReference=$reference; require __DIR__.'/../../app/components/booking-reference.php'; ?>
        <div class="appointment-statuses"><span class="status-badge state-<?= e($appointment['status']) ?>"><?= e(appointment_status_label($appointment['status'])) ?></span><span class="status-badge payment-badge">Payment: <?= e(payment_status_label($appointment)) ?></span></div>
        <h2><?= e($appointment['service_name_snapshot'] ?: $appointment['service_name']) ?></h2>
        <dl class="details-grid">
            <div><dt>Your day</dt><dd><?= e($start->format('l, F j, Y')) ?></dd></div>
            <div><dt>Customer</dt><dd><?= e($user['full_name']) ?></dd></div>
            <div><dt>Your time</dt><dd><?= e($start->format('g:i A').' – '.$end->format('g:i A')) ?><small>Philippine time · Asia/Manila</small></dd></div>
            <div><dt>Private room</dt><dd><?= e($appointment['room_code']) ?></dd></div>
            <div><dt>Guests</dt><dd><?= e($appointment['guest_count']) ?> <?= $appointment['guest_count'] == 1 ? 'person' : 'persons' ?></dd></div>
            <div><dt>Your therapist</dt><dd><?= e(staff_display_name($appointment['therapist_names'] ?: 'Not assigned')) ?></dd></div>
            <div><dt>Arrival time</dt><dd><?= e($arrival->format('g:i A')) ?><small>15 minutes before your appointment</small></dd></div>
        </dl>
        <div class="summary-total"><span>Total treatment price</span><strong><?= money($appointment['total_amount']) ?></strong></div>
        <div class="payment-summary-line"><div><span>Amount recorded</span><br><?= peso_amount($summary['paid']) ?></div><div><span>Outstanding Balance</span><br><?= peso_amount($summary['balance']) ?></div><div><span>Refunded</span><br><?= peso_amount($summary['refunded']) ?></div></div>
        <?php if($appointment['status']==='cancelled'): ?><p class="small-note">For a cancelled appointment, this balance records the original treatment amount less verified payments. It is not a new payment request or a refund decision.</p><?php endif; ?>
        <?php if($summary['is_demo']): ?><?php if($appointment['status']==='awaiting_payment'): ?><p><a class="text-link" href="<?= e(url('/customer/payment.php?reference='.rawurlencode($reference))) ?>">Continue to checkout ↗</a></p><?php endif; ?><?php elseif(!payment_readiness()['ready']): ?><div class="notice"><strong>Online payment is currently unavailable.</strong> Your account shows any payments already recorded for this reservation.</div><?php endif; ?>
        <?php if($cancellation): ?><div class="notice"><strong>Cancellation recorded <?= e(local_time($cancellation['cancelled_at'])->format('F j, Y · g:i A')) ?> Philippine time.</strong><?= nl2br(e($cancellation['reason'])) ?><p>Known downpayment forfeiture: <?= $cancellation['known_forfeiture_centavos']===null?'Awaiting review':peso_amount((int)$cancellation['known_forfeiture_centavos']) ?>. <?= $refund?'Refund eligibility: '.e(str_replace('_',' ',$refund['status'])).'. No automatic refund has been issued.':'No verified payment required a refund request at cancellation.' ?></p></div><?php endif; ?>
        <section class="saved-health"><p class="eyebrow">Your saved health declaration</p><h3>A record of your answers.</h3>
            <?php if ($declaration): ?><dl class="health-answer-grid"><?php foreach (HEALTH_QUESTIONS as $key=>$label): ?><div><dt><?= e($label) ?></dt><dd><?= e(ucfirst($answers[$key] ?? 'Not recorded')) ?></dd></div><?php endforeach; ?></dl><p><strong>Additional notes</strong><br><?= nl2br(e($answers['notes'] ?: 'None provided.')) ?></p><p class="small-note">Disclosure consent recorded <?= e(local_time($declaration['consented_at'])->format('F j, Y · g:i A')) ?>, Philippine time.</p><?php else: ?><p>This appointment uses an earlier declaration version.</p><?php endif; ?>
        </section>
        <p class="small-note">Terms accepted: <?= e(local_time($appointment['terms_accepted_at'])->format('F j, Y · g:i A')) ?> Philippine time · version <?= e($appointment['terms_version']) ?>.</p>
        <section class="record-payment-section"><h2>Payment history.</h2><?php require __DIR__.'/../../app/components/payment-records.php'; ?></section>
        <?php if($changes): ?><section class="change-history"><p class="eyebrow">Your appointment record</p><h3>Changes, carefully kept.</h3><ul><?php foreach($changes as $change): $before=json_decode($change['before_json'],true);$after=json_decode($change['after_json'],true); ?><li><strong><?= e(appointment_change_label($change['action'])) ?></strong> · <?= e(local_time($change['created_at'])->format('F j, Y · g:i A')) ?> Philippine time<?php if($change['action']==='rescheduled'): ?><br><?= e(local_time($before['starts_at'])->format('M j · g:i A').' → '.local_time($after['starts_at'])->format('M j · g:i A')) ?><?php endif; ?></li><?php endforeach; ?></ul></section><?php endif; ?>
    </article>
    <aside class="booking-aside summary-panel"><p class="eyebrow">Your visit, in view</p><h2>Room for<br><em>your plans.</em></h2><p>Arrive 15 minutes early. Changes require at least six hours’ notice, including the exact deadline.</p><p class="small-note">Deadline: <?= e(appointment_deadline($appointment)->format('F j, Y · g:i A')) ?> Philippine time.</p><div class="appointment-actions"><?php if($eligibility['eligible']): ?><a href="<?= e(url('/customer/cancel.php?reference='.rawurlencode($reference))) ?>">Cancel appointment ↗</a><a href="<?= e(url('/customer/reschedule.php?reference='.rawurlencode($reference))) ?>">Reschedule appointment ↗</a><?php endif; ?></div><p class="small-note"><?= e($eligibility['reason']) ?></p><a class="text-link" href="<?= e(url('/customer/payment.php?reference='.rawurlencode($reference))) ?>">Payment & balance <span>↗</span></a><a class="text-link" href="<?= e(url('/customer/dashboard.php')) ?>">Back to my account <span>↗</span></a></aside>
</div>
<?php require __DIR__ . '/../../app/components/footer.php'; ?>
