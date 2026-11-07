<?php
declare(strict_types=1);

function services(): array
{
    return db()->query('SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
}
function remember_booking_service(mixed $id): void
{
    if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id)) return;
    $query=db()->prepare('SELECT id FROM services WHERE id=? AND is_active=1');
    $query->execute([$id]);
    if ($valid=$query->fetchColumn()) $_SESSION['booking_service_id']=(int)$valid;
}
function service_rooms(int $serviceId): array
{
    $query = db()->prepare('SELECT id, service_id, code, capacity FROM rooms WHERE service_id = ? AND is_active = 1 ORDER BY code');
    $query->execute([$serviceId]);
    return $query->fetchAll();
}
function service_total(array $service, int $guests): int
{
    if ($guests < 1 || $guests > (int)$service['max_capacity']) throw new InvalidArgumentException('Guest count exceeds this service’s capacity.');
    return (int)$service['base_price'] + max(0, $guests - (int)$service['base_capacity']) * (int)$service['extra_person_fee'];
}
function settings(): array
{
    static $settings = null;
    if ($settings === null) $settings = db()->query('SELECT * FROM business_settings WHERE id = 1')->fetch();
    return $settings;
}
