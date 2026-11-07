<?php $week = ($_GET['view'] ?? 'day') === 'week'; ?>
<form method="get" class="inline-actions">
    <div class="field"><label for="calendar-date">Starting date</label><input id="calendar-date" type="date" name="date" value="<?= e($date) ?>" required></div>
    <div class="field"><label for="calendar-view">Calendar view</label><select id="calendar-view" name="view"><option value="day">Daily</option><option value="week" <?= $week?'selected':'' ?>>Seven days</option></select></div>
    <button class="button" type="submit">Show calendar ↗</button>
</form>
<p class="notice">The room grid shows reservations, cleaning and open intervals in Philippine time. Select an appointment to see its full reference and manage the booking. Scroll the grid to see all rooms.</p>
<?php for ($offset=0; $offset<($week?7:1); $offset++):
    $day = local_datetime($date,'00:00')->modify("+$offset days");
    $calendarDate = $day->format('Y-m-d');
    $calendar = admin_calendar_day($calendarDate);
    $dayRecords = admin_appointments(['date'=>$calendarDate]);
    $bookingsByRoom = $blocksByRoom = [];
    foreach ($calendar['appointments'] as $item) $bookingsByRoom[$item['room_id']][] = $item;
    foreach ($calendar['blocks'] as $item) $blocksByRoom[$item['room_id']][] = $item;
?>
<h2 id="calendar-<?= e($calendarDate) ?>"><?= e($day->format('l, F j')) ?></h2>
<?php if ($dayRecords): ?>
<details class="booking-sheet day-agenda" open>
    <summary>Appointments by time</summary>
    <ul>
    <?php usort($dayRecords, fn($a,$b) => $a['starts_at'] <=> $b['starts_at']); foreach ($dayRecords as $record): ?>
        <li><a class="booking-reference text-link" href="<?= e(url('/admin/appointment.php?reference='.rawurlencode($record['booking_reference']))) ?>"><?= e(short_booking_reference($record['booking_reference'])) ?> <span class="sr-only">· View full appointment details</span></a>
            <span><?= e(local_time($record['starts_at'])->format('g:i A').'–'.local_time($record['ends_at'])->format('g:i A')) ?></span>
            <span><?= e($record['room_code'].' · '.appointment_status_label($record['status'])) ?></span>
            <span><?= e(staff_display_name($record['therapist_names'] ?: 'Therapist not assigned')) ?></span>
        </li>
    <?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>
<div class="table-scroll calendar-scroll" tabindex="0" role="region" aria-labelledby="calendar-<?= e($calendarDate) ?>">
<table class="room-calendar"><thead><tr><th scope="col">Time</th><?php foreach ($calendar['rooms'] as $room): ?><th scope="col"><?= e($room['code']) ?></th><?php endforeach; ?></tr></thead>
<tbody>
<?php foreach ($calendar['times'] as $time): $utc=utc_time($time); ?>
<tr><th scope="row"><?= e($time->format('g:i A')) ?></th>
<?php foreach ($calendar['rooms'] as $room):
    $cell = !$room['is_active'] ? 'Inactive' : ($time->format('H:i') >= '21:00' ? 'Closed' : 'Available');
    $booking = null;
    foreach ($bookingsByRoom[$room['id']] ?? [] as $item) {
        if ($item['starts_at'] <= $utc && $item['cleaning_ends_at'] > $utc) {
            $booking = $item;
            $cell = $item['ends_at'] <= $utc ? 'Cleaning' : (in_array($item['status'],['pending','awaiting_payment'],true) ? 'Pending' : 'Occupied');
            break;
        }
    }
    foreach ($blocksByRoom[$room['id']] ?? [] as $block) {
        if (!$booking && $block['starts_at'] <= $utc && $block['ends_at'] > $utc) { $cell = 'Blocked'; break; }
    }
?>
<td class="calendar-<?= e(strtolower($cell)) ?>">
<?php if ($booking):
    $range = local_time($booking['starts_at'])->format('g:i A').'–'.local_time($booking['ends_at'])->format('g:i A');
    $status = appointment_status_label($booking['status']);
?>
    <a class="calendar-booking" href="<?= e(url('/admin/appointment.php?reference='.rawurlencode($booking['booking_reference']))) ?>" aria-label="<?= e($cell.', '.$status.', '.$range.', '.staff_display_name($booking['therapist_names'] ?: 'unassigned').'. View appointment '.$booking['booking_reference']) ?>">
        <strong><?= e($cell) ?></strong><span><?= e($status) ?></span>
        <span class="booking-reference"><?= e(short_booking_reference($booking['booking_reference'])) ?></span>
        <span><?= e($range) ?></span><span><?= e(staff_display_name($booking['therapist_names'] ?: 'Unassigned')) ?></span>
    </a>
<?php else: ?><?= e($cell) ?><?php endif; ?>
</td>
<?php endforeach; ?></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endfor; ?>
