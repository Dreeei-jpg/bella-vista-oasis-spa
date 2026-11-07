<?php
declare(strict_types=1);

require_once __DIR__.'/money.php';
require_once __DIR__.'/payments.php';
require_once __DIR__.'/management.php';
require_once __DIR__.'/demo.php';
require_once __DIR__.'/staffing.php';

class BookingValidationException extends RuntimeException
{
    public function __construct(string $message, public string $reasonCode = 'invalid_selection') { parent::__construct($message); }
}

const HEALTH_QUESTIONS = [
    'pregnancy' => 'Pregnancy',
    'recent_injury' => 'Recent injury',
    'chronic_pain' => 'Chronic pain',
    'high_blood_pressure' => 'High blood pressure',
    'skin_condition' => 'Skin condition',
    'allergies' => 'Allergies',
    'recent_surgery' => 'Recent surgery',
    'other_medical_condition' => 'Other medical condition',
];

function spa_timezone(): DateTimeZone { return new DateTimeZone('Asia/Manila'); }
function utc_time(DateTimeImmutable $date): string { return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
function local_time(string $utc): DateTimeImmutable { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(spa_timezone()); }
function positive_integer(mixed $value, string $label): int
{
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value)) {
        throw new BookingValidationException('Choose a valid ' . $label . '.');
    }
    return (int)$value;
}
function local_datetime(string $date, string $time): DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !preg_match('/^\d{2}:\d{2}$/D', $time)) {
        throw new BookingValidationException('Choose a valid appointment date and time.');
    }
    $result = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", spa_timezone());
    if (!$result || $result->format('Y-m-d H:i') !== "$date $time") {
        throw new BookingValidationException('Choose a real calendar date and valid time.');
    }
    return $result;
}

function validate_health(array $input): array
{
    $errors = [];
    foreach (HEALTH_QUESTIONS as $key => $label) {
        if (!in_array($input[$key] ?? null, ['yes', 'no'], true)) $errors[] = 'Please answer the question about ' . strtolower($label) . '.';
    }
    if (!is_string($input['notes'] ?? null) || mb_strlen($input['notes']) > 2000) $errors[] = 'Additional notes must be 2,000 characters or fewer.';
    if (($input['consent'] ?? null) !== '1') $errors[] = 'Please confirm that you have disclosed relevant health information.';
    return $errors;
}

function health_declaration(int $userId, int $id): ?array
{
    $query = db()->prepare("SELECT * FROM health_declarations WHERE id = ? AND user_id = ? AND declaration_version = 'phase-2'");
    $query->execute([$id, $userId]);
    return $query->fetch() ?: null;
}

function save_health_declaration(int $userId, array $input): int
{
    $errors = validate_health($input);
    if ($errors) throw new BookingValidationException(implode(' ', $errors));
    $answers = array_intersect_key($input, HEALTH_QUESTIONS);
    $answers['notes'] = trim($input['notes']);
    $query = db()->prepare("INSERT INTO health_declarations (user_id, declaration_version, responses_json, consented_at) VALUES (?, 'phase-2', ?, UTC_TIMESTAMP())");
    $query->execute([$userId, json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    return (int)db()->lastInsertId();
}

/** Resolve service, room and guest count from authoritative database values. */
function booking_selection(array $input): array
{
    $serviceId = positive_integer($input['service_id'] ?? null, 'service');
    $roomId = positive_integer($input['room_id'] ?? null, 'room');
    $guests = positive_integer($input['guests'] ?? null, 'guest count');
    $query = db()->prepare('SELECT s.*, r.code AS room_code, r.capacity AS room_capacity, r.id AS room_id FROM services s JOIN rooms r ON r.service_id = s.id WHERE s.id = ? AND r.id = ? AND s.is_active = 1 AND r.is_active = 1');
    $query->execute([$serviceId, $roomId]);
    $service = $query->fetch();
    if (!$service) throw new BookingValidationException('Choose an active room belonging to the selected service.');
    if ($guests > min((int)$service['max_capacity'], (int)$service['room_capacity'])) {
        throw new BookingValidationException('The guest count exceeds this service or room’s capacity.');
    }
    $service['guests'] = $guests;
    $service['total'] = service_total($service, $guests);
    return $service;
}

/** Validate local business hours; all persisted scheduling windows are UTC. */
function appointment_window(array $service, string $date, string $time, ?DateTimeImmutable $now = null): array
{
    $start = local_datetime($date, $time);
    $now ??= new DateTimeImmutable('now', spa_timezone());
    if ($start <= $now) throw new BookingValidationException('Choose a start time in the future.');
    $query = db()->prepare('SELECT opens_at, closes_at FROM operating_hours WHERE weekday = ?');
    $query->execute([(int)$start->format('N')]);
    $hours = $query->fetch();
    if (!$hours) throw new BookingValidationException('The spa is closed on the selected day.');
    $open = local_datetime($date, substr($hours['opens_at'], 0, 5));
    $close = local_datetime($date, substr($hours['closes_at'], 0, 5));
    $rules = settings();
    $interval = (int)$rules['slot_interval_minutes'];
    if ($interval < 1 || ($start->getTimestamp() - $open->getTimestamp()) % ($interval * 60) !== 0) {
        throw new BookingValidationException('Appointment starts must follow the 30-minute time grid.');
    }
    $end = $start->modify('+' . (int)$service['duration_minutes'] . ' minutes');
    if ($start < $open || $end > $close) throw new BookingValidationException('Your treatment must fit between 1:00 PM and 9:00 PM.');
    $cleaningEnd = $end->modify('+' . (int)$rules['cleaning_gap_minutes'] . ' minutes');
    return ['start' => $start, 'end' => $end, 'cleaning_end' => $cleaningEnd];
}

/** Half-open intervals allow exact boundaries, including the end of cleaning. */
function blocking_appointment_sql(string $prefix = ''): string
{
    return "({$prefix}status IN ('pending','confirmed','ongoing','completed') OR ({$prefix}status='awaiting_payment' AND {$prefix}hold_expires_at > UTC_TIMESTAMP()))";
}

function room_available(int $roomId, array $window, ?int $excludeId = null): bool
{
    $start = utc_time($window['start']);
    $cleaningEnd = utc_time($window['cleaning_end']);
    $query = db()->prepare('SELECT id FROM appointments WHERE room_id = ? AND '.blocking_appointment_sql().' AND starts_at < ? AND cleaning_ends_at > ? AND (? IS NULL OR id <> ?) LIMIT 1');
    $query->execute([$roomId, $cleaningEnd, $start, $excludeId, $excludeId]);
    if ($query->fetch()) return false;
    $query = db()->prepare('SELECT id FROM room_blocks WHERE room_id = ? AND starts_at < ? AND ends_at > ? LIMIT 1');
    $query->execute([$roomId, $cleaningEnd, $start]);
    return !$query->fetch();
}

/** An explicit available shift must cover treatment, with no leave or assignment overlap. */
function available_therapists(array $window, ?int $excludeId = null, ?int $serviceId = null): array
{
    $start = utc_time($window['start']);
    $end = utc_time($window['end']);
    $query = db()->prepare("SELECT t.id, t.full_name FROM therapists t
        WHERE t.is_active = 1
        AND NOT EXISTS (SELECT 1 FROM therapist_service_exclusions q WHERE q.therapist_id=t.id AND q.service_id=?)
        AND EXISTS (SELECT 1 FROM therapist_schedules s WHERE s.therapist_id = t.id AND s.schedule_type = 'available' AND s.starts_at <= ? AND s.ends_at >= ? AND ".valid_staff_shift_sql('s').")
        AND NOT EXISTS (SELECT 1 FROM therapist_schedules s WHERE s.therapist_id = t.id AND s.schedule_type = 'unavailable' AND s.starts_at < ? AND s.ends_at > ?)
        AND NOT EXISTS (SELECT 1 FROM appointment_therapists atp JOIN appointments a ON a.id = atp.appointment_id WHERE atp.therapist_id = t.id AND ".blocking_appointment_sql('a.')." AND a.starts_at < ? AND a.ends_at > ? AND (? IS NULL OR a.id <> ?))
        ORDER BY t.id");
    $query->execute([$serviceId, $start, $end, $end, $start, $end, $start, $excludeId, $excludeId]);
    $staff = $query->fetchAll();
    foreach ($staff as &$therapist) $therapist['full_name'] = staff_display_name($therapist['full_name']);
    return $staff;
}

/** Keep a specific request first and retain valid assignments for the rest of the team. */
function appointment_staff(array $staff, int $required, ?int $preferred = null, array $assigned = []): array
{
    $byId = array_column($staff, null, 'id');
    if ($preferred !== null && !isset($byId[$preferred])) {
        throw new BookingValidationException('Your selected therapist is no longer available for this treatment and time. Please choose another time or Any Available Therapist.');
    }
    if (count($staff) < $required) {
        throw new BookingValidationException('No therapist is available for this treatment period. Please choose another time.');
    }
    $order = array_unique(array_merge($preferred === null ? [] : [$preferred], $assigned, array_keys($byId)));
    $ids = array_slice(array_values(array_intersect($order, array_keys($byId))), 0, $required);
    return array_map(fn($id) => $byId[$id], $ids);
}

function assigned_therapist_ids(int $appointmentId): array
{
    $query = db()->prepare('SELECT therapist_id FROM appointment_therapists WHERE appointment_id=? ORDER BY therapist_id');
    $query->execute([$appointmentId]);
    return $query->fetchAll(PDO::FETCH_COLUMN);
}

function staff_for_existing_appointment(array $appointment, array $window): array
{
    $id = (int)$appointment['id'];
    $serviceId = (int)$appointment['service_id'];
    return appointment_staff(
        available_therapists($window, $id, $serviceId),
        required_therapists($serviceId),
        isset($appointment['preferred_therapist_id']) ? (int)$appointment['preferred_therapist_id'] : null,
        assigned_therapist_ids($id)
    );
}

function required_therapists(int $serviceId): int
{
    $query = db()->prepare('SELECT therapists_required FROM service_staffing_requirements WHERE service_id = ?');
    $query->execute([$serviceId]);
    return max(1, (int)($query->fetchColumn() ?: 1));
}

function availability(array $input, ?int $excludeId = null, ?array $fixedService = null, bool $includeStaff = false): array
{
    $service = $fixedService ?? booking_selection($input);
    $date = is_string($input['date'] ?? null) ? $input['date'] : '';
    $day = local_datetime($date, '00:00');
    if ($day->format('Y-m-d') < (new DateTimeImmutable('now', spa_timezone()))->format('Y-m-d')) {
        throw new BookingValidationException('Choose today or a future date.');
    }
    $query = db()->prepare('SELECT opens_at, closes_at FROM operating_hours WHERE weekday = ?');
    $query->execute([(int)$day->format('N')]);
    $hours = $query->fetch();
    $slots = [];
    if ($hours) {
        $cursor = local_datetime($date, substr($hours['opens_at'], 0, 5));
        $close = local_datetime($date, substr($hours['closes_at'], 0, 5));
        $interval = (int)settings()['slot_interval_minutes'];
        if ($interval < 1) throw new RuntimeException('Invalid slot interval setting.');
        $staffCount = required_therapists((int)$service['id']);
        while ($cursor < $close) {
            $reason = '';
            $staff = [];
            try {
                $window = appointment_window($service, $date, $cursor->format('H:i'));
                if (!room_available((int)$service['room_id'], $window, $excludeId)) $reason = 'Room reserved or being prepared';
                else {
                    $staff = available_therapists($window, $excludeId, (int)$service['id']);
                    $preferred = (int)($service['preferred_therapist_id'] ?? 0);
                    if (count($staff) < $staffCount || ($preferred && !in_array($preferred, array_column($staff, 'id')))) $reason = 'No therapist available';
                }
            } catch (BookingValidationException $exception) { $reason = $exception->getMessage(); }
            $slots[] = ['time' => $cursor->format('H:i'), 'label' => $cursor->format('g:i A'), 'available' => $reason === '', 'reason' => $reason];
            if ($includeStaff && $reason === '') $slots[array_key_last($slots)]['therapists'] = $staff;
            $cursor = $cursor->modify("+$interval minutes");
        }
    }
    return [
        'service' => $service['name'], 'room' => $service['room_code'],
        'guests' => $service['guests'], 'total' => $service['total'],
        'duration' => (int)$service['duration_minutes'], 'date' => $date,
        'timezone' => 'Asia/Manila', 'slots' => $slots,
    ];
}

/** Check every eligible room for each start time. Existing fixed-room callers remain supported. */
function service_availability(array $input): array
{
    $serviceId = positive_integer($input['service_id'] ?? null, 'service');
    $query = db()->prepare('SELECT * FROM services WHERE id=? AND is_active=1');
    $query->execute([$serviceId]);
    $service = $query->fetch();
    if (!$service) throw new BookingValidationException('Choose an active treatment.', 'invalid_service');
    try { $guests = positive_integer($input['guests'] ?? null, 'guest count'); }
    catch (BookingValidationException $e) { throw new BookingValidationException('Enter a valid guest count for this treatment.', 'invalid_guest_count'); }
    if ($guests > (int)$service['max_capacity']) throw new BookingValidationException('The guest count exceeds this treatment’s maximum capacity.', 'invalid_guest_count');
    $date = is_string($input['date'] ?? null) ? $input['date'] : '';
    try { $day = local_datetime($date, '00:00'); }
    catch (BookingValidationException $e) { throw new BookingValidationException('Choose a real appointment date.', 'invalid_date'); }
    if ($date < (new DateTimeImmutable('now', spa_timezone()))->format('Y-m-d')) throw new BookingValidationException('Choose today or a future date.', 'past_date');
    $rooms = array_values(array_filter(service_rooms($serviceId), fn($room) => (int)$room['capacity'] >= $guests));
    $slots = [];
    foreach ($rooms as $room) {
        $result = availability(['service_id'=>$serviceId,'room_id'=>$room['id'],'guests'=>$guests,'date'=>$date], includeStaff: true);
        foreach ($result['slots'] as $index => $slot) {
            $slots[$index] ??= ['time'=>$slot['time'],'label'=>$slot['label'],'rooms'=>[]];
            if ($slot['available']) $slots[$index]['therapists'] ??= $slot['therapists'];
            $code = match ($slot['reason']) {
                '' => 'available',
                'Room reserved or being prepared' => 'room_occupied',
                'No therapist available' => 'no_therapists',
                'Choose a start time in the future.' => 'past_time',
                default => 'outside_hours',
            };
            $slots[$index]['rooms'][] = ['id'=>(int)$room['id'],'code'=>$room['code'],'capacity'=>(int)$room['capacity'],'available'=>$slot['available'],'reason_code'=>$code,'reason'=>$slot['reason']];
        }
    }
    // Even an empty room catalog returns an explicit configuration/capacity outcome, never a server-error disguise.
    if (!$rooms) {
        $query=db()->prepare('SELECT opens_at,closes_at FROM operating_hours WHERE weekday=?');$query->execute([(int)$day->format('N')]);$hours=$query->fetch();
        if ($hours) {
            $cursor=local_datetime($date,substr($hours['opens_at'],0,5));$close=local_datetime($date,substr($hours['closes_at'],0,5));
            $interval=(int)settings()['slot_interval_minutes'];if($interval<1)throw new RuntimeException('Invalid slot interval setting.');
            while($cursor<$close){
                $code='no_rooms';$reason='No active rooms can accommodate this treatment and guest count.';
                try { appointment_window($service,$date,$cursor->format('H:i')); }
                catch(BookingValidationException $e){$reason=$e->getMessage();$code=$reason==='Choose a start time in the future.'?'past_time':'outside_hours';}
                $slots[]=['time'=>$cursor->format('H:i'),'label'=>$cursor->format('g:i A'),'rooms'=>[],'available'=>false,'reason_code'=>$code,'reason'=>$reason];
                $cursor=$cursor->modify("+$interval minutes");
            }
        }
    } else foreach ($slots as &$slot) {
        $free=array_filter($slot['rooms'],fn($room)=>$room['available']);
        $codes=array_column($slot['rooms'],'reason_code');
        $slot['available']=(bool)$free;
        $slot['reason_code']=$free?'available':(in_array('no_therapists',$codes,true)?'no_therapists':$codes[0]);
        $slot['reason']=$free?'':match($slot['reason_code']) {
            'no_therapists'=>'No therapist available',
            'room_occupied'=>'All eligible rooms are reserved or being prepared at this time.',
            default=>$slot['rooms'][0]['reason'],
        };
    }
    unset($slot);
    $hasAvailable=(bool)array_filter($slots,fn($slot)=>$slot['available']);
    $codes=array_column($slots,'reason_code');
    $code=$hasAvailable?'available':(!$slots?'outside_hours':(!$rooms?'no_rooms':(in_array('no_therapists',$codes,true)?'no_therapists':(in_array('room_occupied',$codes,true)?'room_occupied':'outside_hours'))));
    $message=match($code) {
        'available'=>'Choose a start time, then select an available room. All times are Philippine time.',
        'no_therapists'=>'No therapists are available for this treatment on this date. Please choose another date or contact the spa about staff schedules.',
        'no_rooms'=>'No active rooms can accommodate this treatment and guest count. Please contact the spa.',
        'room_occupied'=>'All eligible rooms are reserved or being prepared during the remaining treatment times. Try another date.',
        default=>'No future treatment fits the operating hours for this date. Open daily, 1:00 PM–9:00 PM.',
    };
    return ['service'=>$service['name'],'service_id'=>$serviceId,'guests'=>$guests,'total'=>service_total($service,$guests),'duration'=>(int)$service['duration_minutes'],'date'=>$date,'timezone'=>'Asia/Manila','status'=>$code,'message'=>$message,'rooms'=>array_map(fn($room)=>['id'=>(int)$room['id'],'code'=>$room['code'],'capacity'=>(int)$room['capacity']],$rooms),'slots'=>$slots];
}

function booking_for_request(int $userId, string $requestKey): ?array
{
    $query = db()->prepare('SELECT id, booking_reference, status FROM appointments WHERE request_key = ? AND user_id = ?');
    $query->execute([$requestKey, $userId]);
    return $query->fetch() ?: null;
}

/** All scheduling writers take the same mutex before reading availability. */
function lock_scheduling(PDO $pdo): void
{
    $row = $pdo->query('SELECT id FROM business_settings WHERE id = 1 FOR UPDATE')->fetch();
    if (!$row) throw new RuntimeException('Business settings are missing.');
}

function create_booking(int $userId, array $input): array
{
    $requestKey = is_string($input['request_key'] ?? null) ? $input['request_key'] : '';
    if (!preg_match('/^[a-f0-9]{64}$/D', $requestKey)) throw new BookingValidationException('Your booking form has expired. Please start again.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // A single scheduling mutex is intentionally simple for this seven-room spa.
        // It serializes competing room AND therapist assignments across all sessions.
        lock_scheduling($pdo);
        if ($existing = booking_for_request($userId, $requestKey)) {
            $pdo->commit();
            return $existing;
        }
        if (($input['terms'] ?? null) !== '1') throw new BookingValidationException('Accept the terms and conditions before confirming your visit.');
        $healthId = positive_integer($input['health_id'] ?? null, 'health declaration');
        if (!health_declaration($userId, $healthId)) throw new BookingValidationException('Complete your own health declaration before choosing a treatment.');
        $service = booking_selection($input);
        $date = is_string($input['date'] ?? null) ? $input['date'] : '';
        $time = is_string($input['time'] ?? null) ? $input['time'] : '';
        $window = appointment_window($service, $date, $time);
        if (!room_available((int)$service['room_id'], $window)) throw new BookingValidationException('This room is no longer available at that time, including its cleaning interval. Please choose another slot.');
        $serviceId = (int)$service['id'];
        $preference = $input['therapist_id'] ?? '';
        $preferred = $preference === '' ? null : positive_integer($preference, 'therapist');
        $therapists = appointment_staff(available_therapists($window, null, $serviceId), required_therapists($serviceId), $preferred);
        $reference = 'BV-' . strtoupper(bin2hex(random_bytes(8)));
        $demo = demo_enabled();
        $online = !$demo && payment_readiness()['ready'];
        $status = ($demo || $online) ? 'awaiting_payment' : 'confirmed';
        $hold = $demo ? utc_time(demo_hold_end($window['start'])) : ($online ? utc_time(checkout_hold_end($window['start'])) : null);
        $query = $pdo->prepare("INSERT INTO appointments
            (booking_reference,user_id,service_id,room_id,health_declaration_id,guest_count,starts_at,ends_at,cleaning_ends_at,total_amount,status,payment_status,request_key,terms_accepted_at,terms_version,booking_mode,hold_expires_at,service_name_snapshot,duration_minutes_snapshot,base_amount,extra_amount,preferred_therapist_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'unpaid',?,UTC_TIMESTAMP(),?,?,?,?,?,?,?,?)");
        $query->execute([$reference, $userId, $serviceId, $service['room_id'], $healthId, $service['guests'], utc_time($window['start']), utc_time($window['end']), utc_time($window['cleaning_end']), $service['total'], $status, $requestKey, $demo?'phase-5.1-test':'phase-3', $demo ? 'demo' : ($online ? 'online' : 'unpaid'), $hold, $service['name'], $service['duration_minutes'], $service['base_price'], decimal_amount(decimal_centavos($service['total'])-decimal_centavos($service['base_price'])), $preferred]);
        $id = (int)$pdo->lastInsertId();
        $assign = $pdo->prepare('INSERT INTO appointment_therapists (appointment_id, therapist_id) VALUES (?, ?)');
        foreach ($therapists as $therapist) $assign->execute([$id, $therapist['id']]);
        $pdo->commit();
        return ['id' => $id, 'booking_reference' => $reference, 'status' => $status];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function customer_appointments(int $userId): array
{
    $query = db()->prepare("SELECT a.*, COALESCE(a.service_name_snapshot,s.name) AS service_name, r.code AS room_code,
        (SELECT GROUP_CONCAT(t.full_name ORDER BY t.id SEPARATOR ', ') FROM appointment_therapists atp JOIN therapists t ON t.id = atp.therapist_id WHERE atp.appointment_id = a.id) AS therapist_names
        FROM appointments a JOIN services s ON s.id = a.service_id JOIN rooms r ON r.id = a.room_id
        WHERE a.user_id = ? ORDER BY a.starts_at DESC");
    $query->execute([$userId]);
    return $query->fetchAll();
}
