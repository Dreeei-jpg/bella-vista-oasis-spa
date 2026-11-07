<?php
declare(strict_types=1);

/** Present the reserved marker on seeded staff without changing saved names. */
function staff_display_name(string $name): string
{
    return str_replace('[DEMO] ', '[Test] ', $name);
}

/** Manila 13:00–21:00 is 05:00–13:00 UTC. Legacy rows remain available for review. */
function valid_staff_shift_sql(string $alias): string
{
    return "(DATE($alias.starts_at)=DATE($alias.ends_at)
        AND TIME($alias.starts_at)>='05:00:00' AND TIME($alias.ends_at)<='13:00:00'
        AND $alias.ends_at>$alias.starts_at
        AND MINUTE($alias.starts_at) IN (0,30) AND MINUTE($alias.ends_at) IN (0,30)
        AND SECOND($alias.starts_at)=0 AND SECOND($alias.ends_at)=0)";
}

function staff_shift_options(): array
{
    $options = [];
    for ($minutes = 13 * 60; $minutes <= 21 * 60; $minutes += 30) {
        $hour = intdiv($minutes, 60);
        $options[sprintf('%02d:%02d', $hour, $minutes % 60)] = sprintf('%d:%02d PM', $hour - 12, $minutes % 60);
    }
    return $options;
}

/** Called inside the shared scheduling transaction. Breaks overlay working shifts. */
function save_staff_shift(PDO $pdo, int $staffId, string $date, string $from, string $until, string $type, ?int $shiftId): void
{
    $options = staff_shift_options();
    if (!isset($options[$from], $options[$until]) || $from >= $until || !in_array($type, ['available', 'unavailable'], true)) {
        throw new BookingValidationException('Choose an end after the start, in 30-minute steps between 1:00 PM and 9:00 PM Philippine time.');
    }
    $start = utc_time(local_datetime($date, $from));
    $end = utc_time(local_datetime($date, $until));
    $query = $pdo->prepare('SELECT id FROM therapists WHERE id=? AND is_active=1');
    $query->execute([$staffId]);
    if (!$query->fetchColumn()) throw new BookingValidationException('Choose an active therapist.');
    if ($shiftId !== null) {
        $query = $pdo->prepare('SELECT id FROM therapist_schedules WHERE id=? AND therapist_id=?');
        $query->execute([$shiftId, $staffId]);
        if (!$query->fetchColumn()) throw new BookingValidationException('Choose an existing shift for this therapist.');
    }
    $query = $pdo->prepare('SELECT id FROM therapist_schedules WHERE therapist_id=? AND schedule_type=? AND starts_at<? AND ends_at>? AND (? IS NULL OR id<>?) LIMIT 1');
    $query->execute([$staffId, $type, $end, $start, $shiftId, $shiftId]);
    if ($query->fetchColumn()) throw new BookingValidationException('This overlaps an existing '.($type === 'available' ? 'working shift' : 'break').'. Edit the existing entry or choose another time.');

    $assigned = 'SELECT a.id FROM appointment_therapists atp JOIN appointments a ON a.id=atp.appointment_id
        WHERE atp.therapist_id=? AND '.blocking_appointment_sql('a.').' AND a.ends_at>UTC_TIMESTAMP()';
    if ($type === 'unavailable') {
        $query = $pdo->prepare($assigned.' AND a.starts_at<? AND a.ends_at>? LIMIT 1');
        $query->execute([$staffId, $end, $start]);
        if ($query->fetchColumn()) throw new BookingValidationException('This break overlaps an existing appointment. Reassign the appointment before blocking this time.');
    }
    if ($shiftId === null) {
        $query = $pdo->prepare('INSERT INTO therapist_schedules (therapist_id,starts_at,ends_at,schedule_type) VALUES (?,?,?,?)');
        $query->execute([$staffId, $start, $end, $type]);
        return;
    }
    $query = $pdo->prepare('UPDATE therapist_schedules SET starts_at=?,ends_at=?,schedule_type=? WHERE id=?');
    $query->execute([$start, $end, $type, $shiftId]);
    $query = $pdo->prepare($assigned." AND NOT EXISTS (
        SELECT 1 FROM therapist_schedules s WHERE s.therapist_id=atp.therapist_id
        AND s.schedule_type='available' AND s.starts_at<=a.starts_at AND s.ends_at>=a.ends_at
        AND ".valid_staff_shift_sql('s').") LIMIT 1");
    $query->execute([$staffId]);
    if ($query->fetchColumn()) throw new BookingValidationException('An existing appointment would fall outside working hours. Reassign it before changing this shift.');
}

function save_staff_services(PDO $pdo, int $staffId, array $selected): void
{
    $query = $pdo->prepare('SELECT id FROM therapists WHERE id=?');
    $query->execute([$staffId]);
    if (!$query->fetchColumn()) throw new BookingValidationException('Choose an existing therapist.');
    $services = array_map('intval', $pdo->query('SELECT id FROM services')->fetchAll(PDO::FETCH_COLUMN));
    $selected = array_map(fn($value) => positive_integer($value, 'service'), $selected);
    if (array_diff($selected, $services)) throw new BookingValidationException('Choose services from the catalog.');
    $excluded = array_diff($services, $selected);
    $query = $pdo->prepare('SELECT DISTINCT a.service_id FROM appointment_therapists atp JOIN appointments a ON a.id=atp.appointment_id
        WHERE atp.therapist_id=? AND '.blocking_appointment_sql('a.').' AND a.ends_at>UTC_TIMESTAMP()');
    $query->execute([$staffId]);
    if (array_intersect($excluded, $query->fetchAll(PDO::FETCH_COLUMN))) {
        throw new BookingValidationException('This therapist has an active appointment for a service you removed. Reassign it before changing qualifications.');
    }
    $query = $pdo->prepare('DELETE FROM therapist_service_exclusions WHERE therapist_id=?');
    $query->execute([$staffId]);
    $query = $pdo->prepare('INSERT INTO therapist_service_exclusions (therapist_id,service_id) VALUES (?,?)');
    foreach ($excluded as $serviceId) $query->execute([$staffId, $serviceId]);
}
