<?php
require __DIR__.'/bootstrap.php';
$user=require_role('admin');require_once __DIR__.'/modules/admin.php';
$view=$adminView??'overview';$errors=[];$notice=$_SESSION['admin_message']??'';unset($_SESSION['admin_message']);
$reference=post_string('reference') ?: (is_string($_GET['reference']??null)?$_GET['reference']:'');
$date=is_string($_GET['date']??null)&&$_GET['date']!==''?$_GET['date']:(new DateTimeImmutable('today',spa_timezone()))->format('Y-m-d');
try{admin_day($date);}catch(BookingValidationException $e){$errors[]=$e->getMessage();$date=(new DateTimeImmutable('today',spa_timezone()))->format('Y-m-d');http_response_code(422);}
if($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        if($view==='settings') {
            $percentage=post_string('percentage');percentage_centavos(10000,$percentage);
            $minutes=positive_integer(post_string('hold_minutes'),'hold duration');$enabled=post_string('enabled');
            if($minutes>60||!in_array($enabled,['0','1'],true))throw new BookingValidationException('Choose enabled/disabled and a hold duration of 1–60 minutes.');
            $p=db();$p->beginTransaction();
            try{lock_scheduling($p);$q=$p->prepare('UPDATE business_settings SET demo_payments_enabled=?,demo_downpayment_percentage=?,demo_hold_minutes=? WHERE id=1');$q->execute([(int)$enabled,$percentage,$minutes]);$p->commit();}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
            $_SESSION['admin_message']='Test payment settings saved.';redirect('/admin/settings.php');
        }
        if($view!=='appointment')throw new BookingValidationException('Choose an action from the appointment details.');
        $input=[];foreach(['revision','request_key','status','reason','date','time','note','review_status'] as $field)$input[$field]=post_string($field);
        $input['therapist_ids']=$_POST['therapist_ids']??[];
        admin_update_appointment((int)$user['id'],$reference,post_string('action'),$input);
        $_SESSION['admin_message']='The change has been saved. Payment records were retained; no automatic refund was issued.';
        redirect('/admin/appointment.php?reference='.rawurlencode($reference));
    }catch(BookingValidationException|InvalidArgumentException $e){$errors[]=$e->getMessage();http_response_code(422);}
}
$titles=[
    'overview'=>'Management Dashboard','appointments'=>'Appointments','appointment'=>'Appointment Details',
    'calendar'=>'Reservation Calendar','rooms'=>'Room Management','customers'=>'Customer Records',
    'payments'=>'Payments','reports'=>'Financial Reports','settings'=>'Business Settings',
];
$introductions=[
    'overview'=>'See upcoming reservations, room occupancy, and therapist coverage.',
    'appointments'=>'Find and manage reservations, assignments, and payment status.',
    'appointment'=>'Review the reservation and manage any changes.',
    'calendar'=>'Review reservations and room preparation by date. All times are Philippine time.',
    'rooms'=>'See room occupancy, cleaning intervals, and the next scheduled visit.',
    'customers'=>'Find customer contact details and reservation histories.',
    'payments'=>'Look up recorded transactions by customer, booking, or reference.',
    'reports'=>'Review recorded test amounts and verified receipts for a selected date.',
    'settings'=>'Manage reservation holds and checkout options.',
];
if(!isset($titles[$view])){http_response_code(404);exit;}
function admin_action_fields(array $a,string $action):void {
    echo csrf_field();
    foreach(['reference'=>$a['booking_reference'],'revision'=>$a['revision'],'request_key'=>bin2hex(random_bytes(32)),'action'=>$action] as $field=>$value)echo '<input type="hidden" name="'.e($field).'" value="'.e($value).'">';
}
// Buffer the view so missing/invalid appointment references retain a proper HTTP error status.
ob_start();$pageTitle=$titles[$view];require __DIR__.'/components/header.php';require __DIR__.'/components/admin-nav.php';
?>
<section class="container booking-heading"><p class="eyebrow">Bella Vista / Management</p><h1 class="admin-title"><?= e($pageTitle) ?></h1><p><?= e($introductions[$view]) ?></p></section>
<div class="container admin-content">
<?php form_errors($errors);if($notice): ?><div class="notice" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php try{require __DIR__.'/components/admin/'.($view==='reports'?'overview':$view).'.php';}catch(BookingValidationException $e){http_response_code($view==='appointment'?404:422);form_errors([$e->getMessage()]);} ?>
<form method="post" action="<?= e(url('/auth/logout.php')) ?>" class="inline-actions"><?= csrf_field() ?><button class="text-link" type="submit">Sign out ↗</button></form></div>
<?php require __DIR__.'/components/footer.php';ob_end_flush(); ?>
