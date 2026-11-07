<?php
declare(strict_types=1);

function owned_appointment(int $userId, string $reference): array
{
    $query=db()->prepare('SELECT a.*,u.full_name AS customer_name,s.name AS catalog_name,r.code AS room_code,
        (SELECT GROUP_CONCAT(t.full_name ORDER BY t.id SEPARATOR ", ") FROM appointment_therapists atp JOIN therapists t ON t.id=atp.therapist_id WHERE atp.appointment_id=a.id) AS therapist_names
        FROM appointments a JOIN users u ON u.id=a.user_id JOIN services s ON s.id=a.service_id JOIN rooms r ON r.id=a.room_id WHERE a.user_id=? AND a.booking_reference=?');
    $query->execute([$userId,$reference]);
    $row=$query->fetch();
    if (!$row) throw new BookingValidationException('This appointment is not available in your account.');
    return $row;
}
function appointment_deadline(array $appointment): DateTimeImmutable
{
    return local_time($appointment['starts_at'])->modify('-'.(int)settings()['cancellation_notice_hours'].' hours');
}
function change_eligibility(array $appointment, ?DateTimeImmutable $now=null): array
{
    $now ??= new DateTimeImmutable('now',spa_timezone());
    if (!in_array($appointment['status'],['confirmed','pending'],true)) return ['eligible'=>false,'reason'=>'Only upcoming confirmed appointments can be changed.'];
    if ($now > appointment_deadline($appointment)) return ['eligible'=>false,'reason'=>($appointment['booking_mode']??'')==='demo'?'The six-hour notice deadline has passed. Contact the administrator to review a cancellation or reschedule. Refunds require review.':'The six-hour notice deadline has passed. Please contact the administrator. Late cancellations forfeit any applicable downpayment; full-payment refund terms require review.'];
    return ['eligible'=>true,'reason'=>'Changes are allowed up to and including the six-hour notice deadline.'];
}
function checked_revision(array $appointment, mixed $revision): void
{
    if (!is_scalar($revision) || !preg_match('/^(0|[1-9][0-9]*)$/D',(string)$revision) || (string)$appointment['revision'] !== (string)$revision) {
        throw new BookingValidationException('This appointment has changed since you opened the form. Reload its details and try again.');
    }
}
function action_key(string $key): void
{
    if (!preg_match('/^[a-f0-9]{64}$/D',$key)) throw new BookingValidationException('The action form has expired. Reload and try again.');
}
function action_already_applied(int $id,string $key,string $action): bool
{
    $q=db()->prepare('SELECT id FROM appointment_changes WHERE appointment_id=? AND request_key=? AND action=?');
    $q->execute([$id,$key,$action]); return (bool)$q->fetchColumn();
}
function audit_appointment(int $id,?int $actor,string $action,array $before,array $after,?string $key=null): void
{
    $q=db()->prepare('INSERT INTO appointment_changes (appointment_id,actor_user_id,action,request_key,before_json,after_json) VALUES (?,?,?,?,?,?)');
    $q->execute([$id,$actor,$action,$key,json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR)]);
}
/** A late cancellation retains only the applicable recorded downpayment. */
function late_downpayment_forfeiture(array $appointment): int
{
    if ($appointment['booking_mode'] === 'demo') {
        $query = db()->prepare("SELECT COALESCE(SUM(amount_centavos),0) FROM demo_payments WHERE appointment_id=? AND is_demo=1 AND status='completed' AND payment_option='downpayment'");
        $query->execute([$appointment['id']]);
        return (int)$query->fetchColumn();
    }
    $query = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE appointment_id=? AND status='verified' AND allocation_status='allocated' AND payment_type='downpayment' AND currency='PHP'");
    $query->execute([$appointment['id']]);
    return min(decimal_centavos((string)$query->fetchColumn()),payment_summary($appointment)['paid']);
}

function cancel_appointment(int $userId,string $reference,string $reason,mixed $revision,string $key,?DateTimeImmutable $now=null): array
{
    action_key($key);
    $reason=trim($reason);
    if (mb_strlen($reason)<3 || mb_strlen($reason)>1000) throw new BookingValidationException('Enter a cancellation reason using 3–1,000 characters.');
    $pdo=db(); $pdo->beginTransaction();
    try {
        lock_scheduling($pdo);
        $a=owned_appointment($userId,$reference);
        if (action_already_applied((int)$a['id'],$key,'cancelled')) { $pdo->commit(); return $a; }
        checked_revision($a,$revision);
        $eligibility=change_eligibility($a,$now);
        if (!$eligibility['eligible']) throw new BookingValidationException($eligibility['reason']);
        $summary=payment_summary($a);
        $at=utc_time($now ?? new DateTimeImmutable('now',spa_timezone()));
        $q=$pdo->prepare('INSERT INTO cancellation_records (appointment_id,actor_user_id,reason,notice_deadline,cancelled_at,is_late,known_forfeiture_centavos) VALUES (?,?,?,?,?,0,0)');
        $q->execute([$a['id'],$userId,$reason,utc_time(appointment_deadline($a)),$at]);
        $cancellationId=(int)$pdo->lastInsertId();
        if ($summary['paid']>0 || $summary['review']) {
            $q=$pdo->prepare("INSERT INTO refund_requests (appointment_id,cancellation_id,reason,is_demo) VALUES (?,?, 'Cancellation: refund policy and eligibility require administrator review',?)");
            $q->execute([$a['id'],$cancellationId,$summary['is_demo']?1:0]);
        }
        $paymentStatus=($summary['paid']>0 || $summary['review'])?'refund_pending_review':'cancelled';
        $q=$pdo->prepare("UPDATE appointments SET status='cancelled',payment_status=?,cancelled_at=?,hold_expires_at=NULL,revision=revision+1 WHERE id=?");
        $q->execute([$paymentStatus,$at,$a['id']]);
        $q=$pdo->prepare("UPDATE payment_attempts SET status='cancelled' WHERE appointment_id=? AND status IN ('initiated','pending')"); $q->execute([$a['id']]);
        // Assignment rows remain as history. Cancelled state releases their active allocations.
        audit_appointment((int)$a['id'],$userId,'cancelled',['status'=>$a['status']],['status'=>'cancelled','refund_review'=>$summary['paid']>0 || $summary['review']],$key);
        $pdo->commit(); return owned_appointment($userId,$reference);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function reschedule_service(array $a): array
{
    $service=booking_selection(['service_id'=>$a['service_id'],'room_id'=>$a['room_id'],'guests'=>$a['guest_count']]);
    $service['duration_minutes']=(int)($a['duration_minutes_snapshot'] ?? ((strtotime($a['ends_at'])-strtotime($a['starts_at']))/60));
    $service['name']=$a['service_name_snapshot'] ?: $a['catalog_name'];
    $service['total']=$a['total_amount'];
    $service['preferred_therapist_id']=$a['preferred_therapist_id'] ?? null;
    return $service;
}
function reschedule_availability(int $userId,string $reference,string $date): array
{
    $a=owned_appointment($userId,$reference); $e=change_eligibility($a);
    if (!$e['eligible']) throw new BookingValidationException($e['reason']);
    return availability(['date'=>$date],(int)$a['id'],reschedule_service($a));
}
function reschedule_appointment(int $userId,string $reference,string $date,string $time,mixed $revision,string $key,?DateTimeImmutable $now=null): array
{
    action_key($key); $pdo=db(); $pdo->beginTransaction();
    try {
        lock_scheduling($pdo); $a=owned_appointment($userId,$reference);
        if (action_already_applied((int)$a['id'],$key,'rescheduled')) { $pdo->commit(); return $a; }
        checked_revision($a,$revision); $e=change_eligibility($a,$now);
        if (!$e['eligible']) throw new BookingValidationException($e['reason']);
        $window=appointment_window(reschedule_service($a),$date,$time,$now);
        if (!room_available((int)$a['room_id'],$window,(int)$a['id'])) throw new BookingValidationException('The replacement time overlaps a reservation or room cleaning. Your original appointment is unchanged.');
        $therapists=staff_for_existing_appointment($a,$window);
        if ($a['starts_at']===utc_time($window['start'])) { $pdo->commit(); return $a; }
        $q=$pdo->prepare('SELECT therapist_id FROM appointment_therapists WHERE appointment_id=?');$q->execute([$a['id']]);$oldStaff=$q->fetchAll(PDO::FETCH_COLUMN);
        $q=$pdo->prepare('UPDATE appointments SET starts_at=?,ends_at=?,cleaning_ends_at=?,revision=revision+1 WHERE id=?');
        $q->execute([utc_time($window['start']),utc_time($window['end']),utc_time($window['cleaning_end']),$a['id']]);
        $q=$pdo->prepare('DELETE FROM appointment_therapists WHERE appointment_id=?');$q->execute([$a['id']]);
        $q=$pdo->prepare('INSERT INTO appointment_therapists (appointment_id,therapist_id) VALUES (?,?)');
        $newStaff=array_column($therapists,'id'); foreach ($newStaff as $id) $q->execute([$a['id'],$id]);
        audit_appointment((int)$a['id'],$userId,'rescheduled',['starts_at'=>$a['starts_at'],'ends_at'=>$a['ends_at'],'cleaning_ends_at'=>$a['cleaning_ends_at'],'therapists'=>$oldStaff],['starts_at'=>utc_time($window['start']),'ends_at'=>utc_time($window['end']),'cleaning_ends_at'=>utc_time($window['cleaning_end']),'therapists'=>$newStaff],$key);
        $pdo->commit(); return owned_appointment($userId,$reference);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
