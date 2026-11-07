<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = require_role('admin');
$errors = [];
$message = $_SESSION['staff_message'] ?? '';
unset($_SESSION['staff_message']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pdo = db();
    try {
        $pdo->beginTransaction();
        lock_scheduling($pdo);
        if (post_string('action') === 'add_therapist') {
            $name = post_string('full_name');
            if (mb_strlen($name) < 2 || mb_strlen($name) > 100) throw new BookingValidationException('Enter the therapist’s real name using 2–100 characters.');
            $query = $pdo->prepare('INSERT INTO therapists (full_name) VALUES (?)');
            $query->execute([$name]);
            $message = 'Therapist added. Publish an available shift to make them bookable.';
        } elseif (post_string('action') === 'edit_therapist') {
            $id=positive_integer(post_string('therapist_id'),'therapist');$name=post_string('full_name');
            if(mb_strlen($name)<2||mb_strlen($name)>100)throw new BookingValidationException('Enter a therapist name using 2–100 characters.');
            $query=$pdo->prepare('SELECT full_name FROM therapists WHERE id=?');$query->execute([$id]);$savedName=$query->fetchColumn();if($savedName===false)throw new BookingValidationException('Choose an existing therapist.');
            if ($name===staff_display_name($savedName)) $name=$savedName;
            $query=$pdo->prepare('UPDATE therapists SET full_name=? WHERE id=?');$query->execute([$name,$id]);$message='Therapist details saved. Existing schedules and appointments were retained.';
        } elseif (post_string('action') === 'set_active') {
            $id=positive_integer(post_string('therapist_id'),'therapist');
            $active=post_string('is_active');
            if(!in_array($active,['0','1'],true))throw new BookingValidationException('Choose a valid staff status.');
            $query=$pdo->prepare('SELECT id FROM therapists WHERE id=?');$query->execute([$id]);
            if(!$query->fetch())throw new BookingValidationException('Choose an existing therapist.');
            if($active==='0') {
                $query=$pdo->prepare("SELECT a.id FROM appointments a JOIN appointment_therapists atp ON atp.appointment_id=a.id WHERE atp.therapist_id=? AND ".blocking_appointment_sql('a.')." AND a.ends_at>UTC_TIMESTAMP() LIMIT 1");$query->execute([$id]);
                if($query->fetch())throw new BookingValidationException('This therapist has an active upcoming appointment. Resolve or reassign it before deactivating the therapist.');
            }
            $query=$pdo->prepare('UPDATE therapists SET is_active=? WHERE id=?');$query->execute([(int)$active,$id]);
            $message=$active==='1'?'Therapist activated. Publish an available shift to make them bookable.':'Therapist deactivated. Existing records and shifts were preserved.';
        } elseif (post_string('action') === 'save_services') {
            $id = positive_integer(post_string('therapist_id'), 'therapist');
            $selected = $_POST['service_ids'] ?? [];
            if (!is_array($selected)) throw new BookingValidationException('Choose services from the catalog.');
            save_staff_services($pdo, $id, $selected);
            $message = 'Service qualifications saved.';
        } elseif (in_array(post_string('action'), ['add_shift','edit_shift'], true)) {
            $id = positive_integer(post_string('therapist_id'), 'therapist');
            $shiftId = post_string('action') === 'edit_shift' ? positive_integer(post_string('shift_id'), 'shift') : null;
            save_staff_shift($pdo, $id, post_string('date'), post_string('starts_at'), post_string('ends_at'), post_string('schedule_type'), $shiftId);
            $message = 'Therapist shift saved in Philippine time.';
        } else throw new BookingValidationException('Choose a valid staffing action.');
        $pdo->commit();
        $_SESSION['staff_message'] = $message;
        redirect('/admin/therapists.php');
    } catch (BookingValidationException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $exception->getMessage(); http_response_code(422);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (($exception->errorInfo[1] ?? 0) === 1062) { $errors[] = 'That exact shift already exists.'; http_response_code(422); }
        else { error_log('Staffing save failed: '.$exception->getMessage()); $errors[] = 'We could not save this change. Please try again shortly.'; http_response_code(503); }
    }
}
$therapists = db()->query('SELECT id,full_name,is_active,is_demo FROM therapists ORDER BY full_name,id')->fetchAll();
$shiftTimes = staff_shift_options();
$catalog = db()->query('SELECT id,name FROM services ORDER BY id')->fetchAll();
$exclusions = [];
foreach (db()->query('SELECT therapist_id,service_id FROM therapist_service_exclusions') as $item) {
    $exclusions[$item['therapist_id']][$item['service_id']] = true;
}
$valid = valid_staff_shift_sql('s');
$shifts = db()->query("SELECT s.*,t.full_name,t.is_active, NOT $valid AS invalid,
    EXISTS (SELECT 1 FROM therapist_schedules other WHERE other.therapist_id=s.therapist_id
        AND other.schedule_type=s.schedule_type AND other.id<>s.id
        AND other.starts_at<s.ends_at AND other.ends_at>s.starts_at) AS overlaps
    FROM therapist_schedules s JOIN therapists t ON t.id=s.therapist_id
    HAVING invalid OR overlaps OR ends_at>=UTC_TIMESTAMP()
    ORDER BY invalid DESC,overlaps DESC,starts_at,id LIMIT 100")->fetchAll();
$assigned = db()->query("SELECT t.full_name,a.booking_reference,a.starts_at,a.ends_at,a.status,s.name
    FROM appointment_therapists atp JOIN appointments a ON a.id=atp.appointment_id
    JOIN therapists t ON t.id=atp.therapist_id JOIN services s ON s.id=a.service_id
    ORDER BY a.starts_at DESC,a.id DESC LIMIT 100")->fetchAll();
$pageTitle = 'Therapist Management';
require __DIR__ . '/../../app/components/header.php';
require __DIR__ . '/../../app/components/admin-nav.php';
?>
<section class="container booking-heading"><p class="eyebrow">Bella Vista / Management</p><h1 class="admin-title">Therapist Management</h1><p>Manage your team, treatment qualifications, and working hours.</p></section>
<div class="container staff-setup">
    <?php form_errors($errors); ?>
    <?php if ($message): ?><div class="notice" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if(!$therapists): ?><div class="notice" role="status"><strong>No therapists have been added.</strong> Add a therapist and publish a working shift to open reservations.</div><?php endif; ?>
    <div class="staff-form-grid">
        <form method="post" class="booking-sheet"><?= csrf_field() ?><input type="hidden" name="action" value="add_therapist"><p class="eyebrow">01 / Your team</p><h2>Add a therapist.</h2><div class="field"><label for="therapist-name">Full name</label><input id="therapist-name" name="full_name" required minlength="2" maxlength="100" autocomplete="off"></div><button class="button" type="submit">Add therapist <span>↗</span></button></form>
        <form method="post" class="booking-sheet"><?= csrf_field() ?><input type="hidden" name="action" value="add_shift"><p class="eyebrow">02 / Working hours</p><h2>Publish a shift.</h2><div class="field"><label for="therapist-id">Therapist</label><select id="therapist-id" name="therapist_id" required><option value="">Choose a therapist</option><?php foreach ($therapists as $therapist): if (!$therapist['is_active']) continue; ?><option value="<?= e($therapist['id']) ?>"><?= e(staff_display_name($therapist['full_name'])) ?> (#<?= e($therapist['id']) ?>)</option><?php endforeach; ?></select></div><?php require __DIR__.'/../../app/components/staff-shift-fields.php'; ?><button class="button" type="submit">Save shift <span>↗</span></button></form>
    </div>
    <section class="staff-roster"><p class="eyebrow">Your team</p><h2>Therapist status.</h2><?php if($therapists): ?><div class="table-scroll"><table><thead><tr><th>Therapist</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($therapists as $therapist): ?><tr><td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="edit_therapist"><input type="hidden" name="therapist_id" value="<?= e($therapist['id']) ?>"><div class="field"><label for="staff-name-<?= e($therapist['id']) ?>">Name <?= $therapist['is_demo']?'· Fictional test staff':'' ?></label><input id="staff-name-<?= e($therapist['id']) ?>" name="full_name" value="<?= e(staff_display_name($therapist['full_name'])) ?>" minlength="2" maxlength="100" required></div><button class="text-link" type="submit">Save name</button></form>
        <details class="staff-services"><summary>Service qualifications</summary>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_services"><input type="hidden" name="therapist_id" value="<?= e($therapist['id']) ?>">
                <?php foreach ($catalog as $service): ?><label class="check-label"><input type="checkbox" name="service_ids[]" value="<?= e($service['id']) ?>" <?= empty($exclusions[$therapist['id']][$service['id']])?'checked':'' ?>><span><?= e($service['name']) ?></span></label><?php endforeach; ?>
                <button class="text-link" type="submit">Save qualifications</button>
            </form>
        </details></td><td><?= $therapist['is_active']?'Active':'Inactive' ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_active"><input type="hidden" name="therapist_id" value="<?= e($therapist['id']) ?>"><input type="hidden" name="is_active" value="<?= $therapist['is_active']?'0':'1' ?>"><button class="text-link" type="submit"><?= $therapist['is_active']?'Deactivate':'Activate' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?><p class="small-note">Activation does not create a shift. Deactivation is blocked while the therapist has upcoming appointments. Add Unavailable shifts above for breaks or leave; existing bookings must be resolved first.</p></section>
    <section class="staff-roster">
        <p class="eyebrow">Published availability</p><h2>Shifts and conflicts.</h2>
        <p>Working shifts can run from 1:00 PM to 9:00 PM Philippine time. A break can sit inside a working shift. Edit flagged entries after reviewing affected appointments.</p>
        <?php if (!$shifts): ?><p>No upcoming shifts have been published.</p><?php else: ?>
        <div class="table-scroll"><table><thead><tr><th>Therapist</th><th>Date and time</th><th>Status</th><th>Manage shift</th></tr></thead><tbody>
        <?php foreach ($shifts as $shift): ?><tr>
            <td><?= e(staff_display_name($shift['full_name'])) ?></td>
            <td><?= e(local_time($shift['starts_at'])->format('M j, Y · g:i A').' – '.local_time($shift['ends_at'])->format('g:i A')) ?></td>
            <td><?= e(ucfirst($shift['schedule_type'])) ?><?php if ($shift['invalid']): ?><br><strong>Outside allowed hours or time grid</strong><?php endif; ?><?php if ($shift['overlaps']): ?><br><strong>Overlapping entry</strong><?php endif; ?></td>
            <td><?php if ($shift['is_active']): ?><details><summary>Edit shift</summary><form method="post"><?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_shift"><input type="hidden" name="therapist_id" value="<?= e($shift['therapist_id']) ?>"><input type="hidden" name="shift_id" value="<?= e($shift['id']) ?>">
                <?php require __DIR__.'/../../app/components/staff-shift-fields.php'; ?>
                <button class="text-link" type="submit">Save shift</button>
            </form></details><?php else: ?>Inactive staff<?php endif; ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
    <section class="staff-roster"><h2>Assigned appointments.</h2>
        <?php if (!$assigned): ?><p>No assigned appointments yet.</p><?php else: ?>
        <div class="table-scroll"><table><thead><tr><th>Therapist</th><th>Appointment</th><th>Philippine time</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($assigned as $item): ?><tr><td><?= e(staff_display_name($item['full_name'])) ?></td><td><?= e($item['name']) ?><br><a class="text-link booking-reference" href="<?= e(url('/admin/appointment.php?reference='.rawurlencode($item['booking_reference']))) ?>"><?= e($item['booking_reference']) ?></a></td><td><?= e(local_time($item['starts_at'])->format('M j, Y · g:i A').' – '.local_time($item['ends_at'])->format('g:i A')) ?></td><td><?= e(appointment_status_label($item['status'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
    <a class="text-link" href="<?= e(url('/admin/index.php')) ?>">Back to administration <span>↗</span></a>
</div>
<?php require __DIR__ . '/../../app/components/footer.php'; ?>
