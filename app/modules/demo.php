<?php
declare(strict_types=1);
const DEMO_METHODS=['demo_gcash'=>'GCash','demo_maya'=>'Maya','demo_card'=>'Card'];

function test_payment_reference(string $reference): string
{
    return str_starts_with($reference, 'DEMO-') ? 'TEST-'.substr($reference, 5) : $reference;
}
function demo_rules():array {return db()->query('SELECT demo_payments_enabled,demo_downpayment_percentage,demo_hold_minutes FROM business_settings WHERE id=1')->fetch();}
function demo_enabled():bool {return (bool)demo_rules()['demo_payments_enabled'];}
function demo_hold_end(DateTimeImmutable $start):DateTimeImmutable {
    $minutes=(int)demo_rules()['demo_hold_minutes'];
    if($minutes<1||$minutes>60)throw new BookingValidationException('Test checkout hold duration is invalid. Contact the administrator.');
    $end=(new DateTimeImmutable('now',spa_timezone()))->modify("+$minutes minutes");return $end<$start?$end:$start;
}
function demo_summary(array $a):array {
    $q=db()->prepare("SELECT COALESCE(SUM(amount_centavos),0) FROM demo_payments WHERE appointment_id=? AND is_demo=1 AND status='completed'");$q->execute([$a['id']]);$paid=(int)$q->fetchColumn();$total=decimal_centavos($a['total_amount']);
    $q=db()->prepare("SELECT COUNT(*) FROM refund_requests WHERE appointment_id=? AND is_demo=1 AND status<>'resolved'");$q->execute([$a['id']]);
    return ['total'=>$total,'paid'=>$paid,'gross_paid'=>$paid,'refunded'=>0,'balance'=>max(0,$total-$paid),'review'=>(int)$q->fetchColumn()>0,'is_demo'=>true];
}
function payment_status_label(array $a): string
{
    if ($a['booking_mode'] === 'demo') {
        if ($a['payment_status'] === 'paid') return 'Recorded';
        if ($a['payment_status'] === 'partially_paid') return 'Partially recorded';
    }
    return ucfirst(str_replace('_',' ',$a['payment_status']));
}
function customer_demo_payments(int $userId,?int $appointmentId=null):array {
    $q=db()->prepare('SELECT d.*,a.booking_reference FROM demo_payments d JOIN appointments a ON a.id=d.appointment_id WHERE d.user_id=? AND a.user_id=? AND (? IS NULL OR a.id=?) ORDER BY d.created_at DESC,d.id DESC');$q->execute([$userId,$userId,$appointmentId,$appointmentId]);return $q->fetchAll();
}
function demo_plan(array $a,string $choice):array {
    if($a['booking_mode']!=='demo')throw new BookingValidationException('Test checkout is unavailable for this appointment.');
    $s=demo_summary($a);
    if($s['review'])throw new BookingValidationException('This payment requires administrator review.');
    if($s['balance']<=0)throw new BookingValidationException('The full amount has already been recorded for this booking.');
    if($choice==='full'||$choice==='balance')return ['amount'=>$s['balance'],'remaining'=>0,'type'=>$s['paid']>0?'balance':'full','percentage'=>null];
    if($choice!=='downpayment'||$s['paid']>0)throw new BookingValidationException('Choose full payment or the remaining balance.');
    $percentage=(string)demo_rules()['demo_downpayment_percentage'];$amount=percentage_centavos($s['total'],$percentage);
    return ['amount'=>$amount,'remaining'=>$s['total']-$amount,'type'=>'downpayment','percentage'=>$percentage];
}
function complete_demo_payment(int $userId,string $reference,string $choice,string $method,mixed $revision,string $key,string $expected):array {
    action_key($key);
    if(!demo_enabled())throw new BookingValidationException('Test checkout is currently disabled by the administrator.');
    if(!isset(DEMO_METHODS[$method]))throw new BookingValidationException('Choose GCash, Maya, or Card. Test Mode requires no payment credentials.');
    $p=db();$p->beginTransaction();
    try {
        lock_scheduling($p);$a=owned_appointment($userId,$reference);
        if($a['booking_mode']!=='demo')throw new BookingValidationException('Test checkout is unavailable for this appointment.');
        $q=$p->prepare('SELECT * FROM demo_payments WHERE request_key=?');$q->execute([$key]);$old=$q->fetch();
        if($old){if((int)$old['appointment_id']!==(int)$a['id']||(int)$old['user_id']!==$userId)throw new BookingValidationException('The payment form does not belong to this booking.');$p->commit();return $old;}
        checked_revision($a,$revision);
        $now=new DateTimeImmutable('now',spa_timezone());
        if($a['status']==='awaiting_payment'&&(!$a['hold_expires_at']||local_time($a['hold_expires_at'])<=$now)) {
            $q=$p->prepare("UPDATE appointments SET status='expired',revision=revision+1 WHERE id=?");$q->execute([$a['id']]);audit_appointment((int)$a['id'],$userId,'demo_hold_expired',['status'=>'awaiting_payment'],['status'=>'expired']);$p->commit();throw new BookingValidationException('The checkout hold expired. No payment was recorded. Please choose a new available appointment.');
        }
        if(!in_array($a['status'],['awaiting_payment','confirmed'],true))throw new BookingValidationException('This appointment cannot accept a test payment.');
        $selection=booking_selection(['service_id'=>$a['service_id'],'room_id'=>$a['room_id'],'guests'=>$a['guest_count']]);
        if(decimal_centavos($selection['total'])!==decimal_centavos($a['total_amount']))throw new BookingValidationException('The treatment price changed. Start a new booking to review its current price.');
        $start=local_time($a['starts_at']);$window=appointment_window(reschedule_service($a),$start->format('Y-m-d'),$start->format('H:i'));
        if(!room_available((int)$a['room_id'],$window,(int)$a['id']))throw new BookingValidationException('The room is no longer available. No payment was recorded.');
        $staff=$a['status']==='awaiting_payment' ? staff_for_existing_appointment($a,$window) : [];
        $plan=demo_plan($a,$choice);
        if($expected!==(string)$plan['amount'])throw new BookingValidationException('The amount changed. Refresh this payment summary before completing it.');
        if ($staff) {
            $q=$p->prepare('DELETE FROM appointment_therapists WHERE appointment_id=?');$q->execute([$a['id']]);
            $q=$p->prepare('INSERT INTO appointment_therapists (appointment_id,therapist_id) VALUES (?,?)');
            foreach ($staff as $t) $q->execute([$a['id'],$t['id']]);
        }
        $transaction='DEMO-'.strtoupper(bin2hex(random_bytes(10)));
        $q=$p->prepare('INSERT INTO demo_payments (appointment_id,user_id,request_key,transaction_reference,payment_option,method,total_centavos,amount_centavos,remaining_centavos,percentage_snapshot,is_demo) VALUES (?,?,?,?,?,?,?,?,?,?,1)');
        $q->execute([$a['id'],$userId,$key,$transaction,$plan['type'],$method,decimal_centavos($a['total_amount']),$plan['amount'],$plan['remaining'],$plan['percentage']]);$id=(int)$p->lastInsertId();
        $q=$p->prepare("UPDATE appointments SET status='confirmed',payment_status=?,hold_expires_at=NULL,revision=revision+1 WHERE id=?");$q->execute([$plan['remaining']===0?'paid':'partially_paid',$a['id']]);
        audit_appointment((int)$a['id'],$userId,'demo_payment_completed',['status'=>$a['status']],['status'=>'confirmed','is_demo'=>true,'transaction'=>$transaction,'amount_centavos'=>$plan['amount']],$key);
        $q=$p->prepare('SELECT * FROM demo_payments WHERE id=?');$q->execute([$id]);$result=$q->fetch();$p->commit();return $result;
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
function cancel_demo_hold(int $userId,string $reference,mixed $revision,string $key):void {
    action_key($key);$p=db();$p->beginTransaction();
    try{lock_scheduling($p);$a=owned_appointment($userId,$reference);if(action_already_applied((int)$a['id'],$key,'demo_checkout_cancelled')){$p->commit();return;}checked_revision($a,$revision);
        if($a['booking_mode']!=='demo'||$a['status']!=='awaiting_payment')throw new BookingValidationException('Only a pending checkout can be abandoned.');
        $q=$p->prepare("UPDATE appointments SET status='expired',payment_status='cancelled',hold_expires_at=UTC_TIMESTAMP(),revision=revision+1 WHERE id=?");$q->execute([$a['id']]);audit_appointment((int)$a['id'],$userId,'demo_checkout_cancelled',['status'=>$a['status']],['status'=>'expired','is_demo'=>true],$key);$p->commit();
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
