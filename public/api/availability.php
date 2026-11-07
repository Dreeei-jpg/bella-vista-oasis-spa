<?php
require __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function availability_response(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); availability_response(405, ['error'=>'Use GET to check availability.']); }
try {
    $user = current_user();
    if (!$user) availability_response(401, ['error'=>'Your session has ended. Please sign in again.']);
    if ($user['role'] !== 'customer') availability_response(403, ['error'=>'A customer account is required.']);
    if (!health_declaration((int)$user['id'], (int)($_SESSION['booking_health_id'] ?? 0))) {
        availability_response(409, ['error'=>'Complete your health declaration before choosing a treatment.']);
    }
    session_write_close();
    availability_response(200, isset($_GET['room_id']) ? availability($_GET) : service_availability($_GET));
} catch (BookingValidationException $exception) {
    availability_response(422, ['error'=>$exception->getMessage(),'code'=>$exception->reasonCode]);
} catch (Throwable $exception) {
    error_log('Availability unavailable: ' . $exception->getMessage());
    availability_response(503, ['error'=>'We could not check availability right now. Please try again shortly.','code'=>'server_error']);
}
