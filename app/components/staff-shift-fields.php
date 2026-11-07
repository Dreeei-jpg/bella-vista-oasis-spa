<?php
$shiftStart = isset($shift) ? local_time($shift['starts_at']) : null;
$shiftEnd = isset($shift) ? local_time($shift['ends_at']) : null;
$from = $shiftStart?->format('H:i') ?? '13:00';
$until = $shiftEnd?->format('H:i') ?? '21:00';
$from = isset($shiftTimes[$from]) && $from !== '21:00' ? $from : '13:00';
$until = isset($shiftTimes[$until]) && $until !== '13:00' ? $until : '21:00';
$suffix = isset($shift) ? '-'.(int)$shift['id'] : '';
?>
<div class="field"><label for="shift-date<?= e($suffix) ?>">Date · Philippine time</label><input id="shift-date<?= e($suffix) ?>" type="date" name="date" value="<?= e($shiftStart?->format('Y-m-d') ?? '') ?>" required></div>
<div class="booking-fields">
    <div class="field"><label for="shift-start<?= e($suffix) ?>">From</label><select id="shift-start<?= e($suffix) ?>" name="starts_at" required>
        <?php foreach ($shiftTimes as $time => $label): if ($time==='21:00') continue; ?><option value="<?= e($time) ?>" <?= $time===$from?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></div>
    <div class="field"><label for="shift-end<?= e($suffix) ?>">Until</label><select id="shift-end<?= e($suffix) ?>" name="ends_at" required>
        <?php foreach ($shiftTimes as $time => $label): if ($time==='13:00') continue; ?><option value="<?= e($time) ?>" <?= $time===$until?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></div>
</div>
<div class="field"><label for="shift-type<?= e($suffix) ?>">Shift type</label><select id="shift-type<?= e($suffix) ?>" name="schedule_type"><option value="available">Available</option><option value="unavailable" <?= ($shift['schedule_type']??'')==='unavailable'?'selected':'' ?>>Unavailable / break</option></select></div>
