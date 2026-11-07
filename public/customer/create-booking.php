<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = require_role('customer');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Use the booking form to confirm your appointment.'); }
verify_csrf();
$input = [];
foreach (['service_id','room_id','guests','date','time','health_id','request_key','terms','payment_choice'] as $key) $input[$key] = post_string($key);
$input['therapist_id'] = $_POST['therapist_id'] ?? '';
try {
    // A repeated successful submit returns its original appointment, even after draft cleanup.
    $existing = preg_match('/^[a-f0-9]{64}$/D', $input['request_key']) ? booking_for_request((int)$user['id'], $input['request_key']) : null;
    if ($existing) redirect(($existing['status']==='awaiting_payment'?'/customer/payment.php':'/customer/appointment.php').'?reference=' . rawurlencode($existing['booking_reference']).($existing['status']==='awaiting_payment'?'&payment_choice='.($input['payment_choice']==='downpayment'?'downpayment':'full'):''));
    if (!hash_equals($_SESSION['booking_request_key'] ?? '', $input['request_key']) || $input['request_key'] === '' || (string)($_SESSION['booking_health_id'] ?? '') !== $input['health_id']) {
        throw new BookingValidationException('Your booking draft has changed. Please complete your health declaration and choose your treatment again.');
    }
    $booking = create_booking((int)$user['id'], $input);
    unset($_SESSION['booking_health_id'], $_SESSION['booking_request_key'], $_SESSION['booking_service_id']);
    redirect(($booking['status']==='awaiting_payment'?'/customer/payment.php':'/customer/appointment.php').'?reference=' . rawurlencode($booking['booking_reference']).($booking['status']==='awaiting_payment'?'&payment_choice='.($input['payment_choice']==='downpayment'?'downpayment':'full'):''));
} catch (BookingValidationException $exception) {
    $_SESSION['booking_errors'] = [$exception->getMessage()];
    $query = http_build_query(array_intersect_key($input, array_flip(['service_id','room_id','guests','date'])));
    redirect('/booking.php?' . $query);
} catch (PDOException $exception) {
    error_log('Booking could not be saved: ' . $exception->getMessage());
    $_SESSION['booking_errors'] = ['We could not save your appointment right now. Refresh the available times and try again.'];
    redirect('/booking.php');
}
