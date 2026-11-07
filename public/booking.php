<?php
require __DIR__ . '/../app/bootstrap.php';
remember_booking_service($_GET['service_id'] ?? null);
$user = require_role('customer', 'booking');
$healthId = (int)($_SESSION['booking_health_id'] ?? 0);
if (!$healthId || !health_declaration((int)$user['id'], $healthId)) redirect('/customer/health.php');
$catalog = services();
$allRooms = db()->query('SELECT id, service_id, code, capacity FROM rooms WHERE is_active = 1 ORDER BY code')->fetchAll();
$errors = $_SESSION['booking_errors'] ?? [];
unset($_SESSION['booking_errors']);
$serviceId = is_string($_GET['service_id'] ?? null) ? $_GET['service_id'] : (string)($_SESSION['booking_service_id'] ?? $catalog[0]['id'] ?? '');
$chosenService = null;
foreach ($catalog as $service) if ((string)$service['id'] === $serviceId) $chosenService = $service;
$guests = is_string($_GET['guests'] ?? null) ? $_GET['guests'] : (string)($chosenService['base_capacity'] ?? 1);
$validRooms = array_values(array_filter($allRooms, fn($room) => (string)$room['service_id'] === $serviceId && (int)$room['capacity'] >= (int)$guests));
$date = is_string($_GET['date'] ?? null) ? $_GET['date'] : (new DateTimeImmutable('now', spa_timezone()))->format('Y-m-d');
$result = null;
if (isset($_GET['date'])) {
    try { $result = service_availability(['service_id'=>$serviceId, 'guests'=>$guests, 'date'=>$date]); }
    catch (BookingValidationException $exception) { $errors[] = $exception->getMessage(); }
}
$requestKey = $_SESSION['booking_request_key'] ??= bin2hex(random_bytes(32));
$hasAvailable = $result && (bool)array_filter($result['slots'], fn($slot) => $slot['available']);
$demoCheckout=demo_enabled();$demoPercentage=demo_rules()['demo_downpayment_percentage'];
$onlineCheckout=!$demoCheckout && payment_readiness()['ready'];
$pageTitle = 'Your next moment of calm';
$pageScripts = ['/assets/js/booking.js'];
require __DIR__ . '/../app/components/header.php';
?>
<section class="container booking-heading"><p class="eyebrow">Your visit / 02 — Find your rhythm</p><h1>Make time<br>for <em>yourself.</em></h1><p>A private space, a considered treatment, and an afternoon that belongs to you. All appointment times are Philippine time.</p></section>
<div class="container booking-layout" id="booking-app" data-availability-url="<?= e(url('/api/availability.php')) ?>" data-availability="<?= e(json_encode($result, JSON_THROW_ON_ERROR)) ?>" data-demo-percentage="<?= $demoCheckout?e($demoPercentage):'' ?>" data-submit-label="<?= $demoCheckout?'Continue to Payment ↗':'Confirm my appointment ↗' ?>">
    <div class="booking-sheet">
        <ol class="booking-steps" aria-label="Booking progress"><li class="step-complete"><span>✓</span>Health declaration</li><li aria-current="step"><span>02</span>Your treatment</li><li><span>03</span>Confirmation</li></ol>
        <div class="declaration-complete"><span>Health declaration saved</span><a href="<?= e(url('/customer/health.php')) ?>">Review answers ↗</a></div>
        <?php form_errors($errors); ?>
        <form method="get" id="availability-form">
            <fieldset class="service-options"><legend><span class="eyebrow">01 / The experience</span><span class="field-heading">What feels right today?</span></legend>
            <?php foreach ($catalog as $index => $service): ?>
                <label class="service-option">
                    <input type="radio" name="service_id" value="<?= e($service['id']) ?>" required <?= (string)$service['id'] === $serviceId ? 'checked' : '' ?> data-max="<?= e($service['max_capacity']) ?>" data-base="<?= e($service['base_price']) ?>" data-included="<?= e($service['base_capacity']) ?>" data-extra="<?= e($service['extra_person_fee']) ?>" data-duration="<?= e($service['duration_minutes']) ?>" data-name="<?= e($service['name']) ?>">
                    <span class="service-option-content"><img src="<?= e(url('/assets/images/'.$service['image_filename'])) ?>" alt="" width="160" height="160"><span><small>0<?= $index + 1 ?> / <?= e($service['duration_minutes']) ?> MINUTES</small><strong><?= e($service['name']) ?></strong><span><?= money($service['base_price']) ?> · <?= $service['max_capacity'] == 1 ? '1 person' : 'up to '.e($service['max_capacity']).' persons' ?></span></span><span class="selection-dot" aria-hidden="true"></span></span>
                </label>
            <?php endforeach; ?>
            </fieldset>
            <div class="booking-fields">
                <div class="field"><label for="booking-guests">Number of guests</label><input id="booking-guests" type="number" name="guests" min="1" max="<?= e($chosenService['max_capacity'] ?? 6) ?>" step="1" value="<?= e($guests) ?>" required><small>Family lounge includes 4; each extra guest is ₱750, up to 6.</small></div>
                <div class="field"><label for="booking-date">Appointment date</label><input id="booking-date" type="date" name="date" min="<?= (new DateTimeImmutable('now', spa_timezone()))->format('Y-m-d') ?>" value="<?= e($date) ?>" required><small>Open daily · 1:00 PM–9:00 PM · Philippine time</small></div>
                <button type="submit" class="text-link find-times">Find available times <span>↗</span></button>
            </div>
        </form>
        <form method="post" action="<?= e(url('/customer/create-booking.php')) ?>" id="confirm-booking-form">
            <?= csrf_field() ?>
            <input type="hidden" name="request_key" value="<?= e($requestKey) ?>">
            <input type="hidden" name="health_id" value="<?= e($healthId) ?>">
            <?php foreach (['service_id'=>$serviceId, 'guests'=>$guests, 'date'=>$date] as $key=>$value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
            <fieldset class="slot-fieldset"><legend><span class="eyebrow">02 / Your moment of quiet</span><span class="field-heading">Choose your start time.</span></legend>
                <p class="slot-status" id="slot-status" role="status" aria-live="polite" data-state="<?= e($result['status'] ?? 'idle') ?>"><?= e($result['message'] ?? 'Choose your treatment, guest count and date, then find available times.') ?></p>
                <div class="time-slots" id="time-slots">
                <?php foreach ($result['slots'] ?? [] as $slot): ?><label class="time-slot <?= !$slot['available'] ? 'unavailable' : '' ?>"><input type="radio" name="time" value="<?= e($slot['time']) ?>" required <?= !$slot['available'] ? 'disabled' : '' ?>><span><?= e($slot['label']) ?><small><?= e($slot['reason'] ?: 'Available') ?></small></span></label><?php endforeach; ?>
                </div>
            </fieldset>
            <div class="field"><label for="booking-room">Your private space</label><select name="room_id" id="booking-room" required aria-describedby="room-status"><option value="">Choose a room</option><?php foreach ($validRooms as $room): ?><option value="<?= e($room['id']) ?>"><?= e($room['code']) ?> · <?= e($room['capacity']) ?> <?= $room['capacity'] == 1 ? 'person' : 'persons' ?></option><?php endforeach; ?></select><small id="room-status" role="status">Choose a start time to see rooms available for the whole treatment and cleaning interval.</small></div>
            <fieldset class="therapist-options">
                <legend class="field-heading">Choose Your Therapist</legend>
                <label><input type="radio" name="therapist_id" value="" checked> <span>Any Available Therapist <small>Recommended</small></span></label>
                <div id="therapist-choices"></div>
                <p id="therapist-status" class="small-note" role="status">Choose a time and room to see available therapists.</p>
            </fieldset>
            <noscript><p class="notice">Select an available time and a room for your treatment. Room and therapist availability will be checked again when you save. Enable JavaScript to filter rooms immediately when choosing a time.</p></noscript>
            <?php if($demoCheckout): ?><fieldset class="payment-options"><legend class="field-heading">Payment choice</legend><label><input type="radio" name="payment_choice" value="full" checked> Full Payment</label><label><input type="radio" name="payment_choice" value="downpayment"> <?= e($demoPercentage) ?>% Downpayment</label><p class="small-note">Review the exact amount at checkout.</p></fieldset><?php endif; ?>
            <div class="notice payment-note"><?php if($demoCheckout): ?><strong>Review your payment summary at checkout.</strong> We hold your selected time while you check out.<?php elseif($onlineCheckout): ?><strong>An available time will be held when you submit your booking.</strong> The appointment is confirmed only after verified provider payment and a valid hold.<?php else: ?><strong>Confirm an available time to save your appointment without online payment.</strong> Online payment is currently unavailable.<?php endif; ?></div>
            <label class="check-label"><input type="checkbox" name="terms" value="1" required><span>I accept the <a href="<?= e(url('/terms.php')) ?>" target="_blank" rel="noopener">terms & conditions (opens in a new tab)</a>, including the arrival and six-hour cancellation/rescheduling notice rules.</span></label>
            <button class="button full-width" type="submit" id="confirm-booking" <?= !$hasAvailable ? 'disabled' : '' ?>><?= $demoCheckout?'Continue to Payment':'Confirm my appointment' ?> <span>↗</span></button>
        </form>
    </div>
    <aside class="booking-aside summary-panel" aria-label="Booking summary">
        <p class="eyebrow">A little time, set aside</p><h2>Your <em>visit.</em></h2>
        <dl class="summary-details"><div><dt>Treatment</dt><dd id="summary-service"><?= e($chosenService['name'] ?? 'Choose a treatment') ?></dd></div><div><dt>Duration</dt><dd id="summary-duration"><?= e($chosenService['duration_minutes'] ?? '—') ?> minutes</dd></div><div><dt>Guests</dt><dd id="summary-guests"><?= e($guests) ?></dd></div><div><dt>Room</dt><dd id="summary-room"><?= e($result['room'] ?? 'Choose your room') ?></dd></div><div><dt>Date</dt><dd id="summary-date"><?= e($date) ?></dd></div><div><dt>Start time</dt><dd id="summary-time">Choose a time</dd></div></dl>
        <dl class="summary-details"><div><dt>Customer</dt><dd><?= e($user['full_name']) ?></dd></div><div><dt>Therapist</dt><dd id="summary-therapist">Any Available Therapist</dd></div><div><dt>Ending time</dt><dd id="summary-end">Choose a time</dd></div><div><dt>Payment selection</dt><dd id="summary-payment"><?= $demoCheckout?'Full payment':'Unpaid' ?></dd></div></dl>
        <div class="summary-total"><span>Total treatment price</span><strong id="summary-total"><?= $result ? money($result['total']) : ($chosenService ? money($chosenService['base_price']) : '—') ?></strong></div>
        <p class="small-note"><?= $demoCheckout?'Review the exact amount due at checkout.':'Review your total before confirming your appointment.' ?></p>
        <div class="summary-footnote"><span aria-hidden="true">✳</span><p>Arrive 15 minutes early.<br>We'll make room for the rest.</p></div>
    </aside>
</div>
<?php require __DIR__ . '/../app/components/footer.php'; ?>
