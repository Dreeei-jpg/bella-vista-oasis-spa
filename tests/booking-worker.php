<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$config = require __DIR__ . '/../config/config.php';
if (!str_ends_with($config['db_name'], '_test')) exit('A separate _test database is required.');
require __DIR__ . '/../app/database.php';
require __DIR__ . '/../app/modules/catalog.php';
require __DIR__ . '/../app/modules/booking.php';
$job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($job['ready'], 'ready');
$deadline = microtime(true) + 10;
while (!is_file($job['barrier'])) {
    if (microtime(true) > $deadline) exit('Barrier timed out');
    usleep(10000);
}
try {
    $result = create_booking((int)$job['user_id'], $job['input']);
    echo json_encode(['status'=>'confirmed','reference'=>$result['booking_reference']]);
} catch (BookingValidationException $exception) {
    echo json_encode(['status'=>'rejected','reason'=>$exception->getMessage()]);
}
