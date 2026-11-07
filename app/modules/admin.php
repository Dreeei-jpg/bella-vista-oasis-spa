<?php
declare(strict_types=1);
function admin_actor(int $id):void {$q=db()->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND account_status='active'");$q->execute([$id]);if(!$q->fetch())throw new BookingValidationException('Administrator access is required.');}
function admin_day(string $date):array {$day=local_datetime($date,'00:00');return [utc_time($day),utc_time($day->modify('+1 day'))];}
function admin_appointment(string $reference):array {
    $q=db()->prepare('SELECT a.*,u.full_name AS customer_name,u.email AS customer_email,s.name AS catalog_name,r.code AS room_code,(SELECT GROUP_CONCAT(t.full_name ORDER BY t.id SEPARATOR ", ") FROM appointment_therapists atp JOIN therapists t ON t.id=atp.therapist_id WHERE atp.appointment_id=a.id) AS therapist_names FROM appointments a JOIN users u ON u.id=a.user_id JOIN services s ON s.id=a.service_id JOIN rooms r ON r.id=a.room_id WHERE a.booking_reference=?');$q->execute([$reference]);$a=$q->fetch();if(!$a)throw new BookingValidationException('Appointment not found.');return $a;
}
function admin_appointments(array $filters=[]):array {
    $where=[];$params=[];
    if(!empty($filters['date'])){[$from,$to]=admin_day($filters['date']);$where[]='a.starts_at>=? AND a.starts_at<?';array_push($params,$from,$to);}
    if(!empty($filters['status'])){if(!in_array($filters['status'],['pending','awaiting_payment','confirmed','ongoing','completed','cancelled','no_show','expired'],true))throw new BookingValidationException('Choose a valid appointment status.');$where[]='a.status=?';$params[]=$filters['status'];}
    if(!empty($filters['search'])){$term='%'.mb_substr($filters['search'],0,100).'%';$where[]='(a.booking_reference LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';array_push($params,$term,$term,$term);}
    if (($filters['customer_id'] ?? '') !== '') {
        $where[] = 'a.user_id=?';
        $params[] = positive_integer($filters['customer_id'], 'customer');
    }
    $page=max(1,min(1000,(int)($filters['page']??1)));$offset=($page-1)*200;
    $q=db()->prepare('SELECT a.*,u.full_name AS customer_name,s.name AS catalog_name,r.code AS room_code,(SELECT GROUP_CONCAT(t.full_name ORDER BY t.id SEPARATOR ", ") FROM appointment_therapists atp JOIN therapists t ON t.id=atp.therapist_id WHERE atp.appointment_id=a.id) AS therapist_names FROM appointments a JOIN users u ON u.id=a.user_id JOIN services s ON s.id=a.service_id JOIN rooms r ON r.id=a.room_id'.($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY a.created_at DESC,a.id DESC LIMIT 200 OFFSET $offset");$q->execute($params);return $q->fetchAll();
}
function admin_daily(string $date):array {
    [$from,$to]=admin_day($date);
    $q=db()->prepare("SELECT COUNT(*) total,COALESCE(SUM(status='confirmed'),0) confirmed,COALESCE(SUM(status='cancelled'),0) cancelled FROM appointments WHERE starts_at>=? AND starts_at<?");$q->execute([$from,$to]);$r=array_map('intval',$q->fetch());
    $r['upcoming']=(int)db()->query("SELECT COUNT(*) FROM appointments WHERE starts_at>UTC_TIMESTAMP() AND ".blocking_appointment_sql())->fetchColumn();
    $q=db()->prepare("SELECT COALESCE(SUM(amount_centavos),0) FROM demo_payments WHERE is_demo=1 AND status='completed' AND created_at>=? AND created_at<?");$q->execute([$from,$to]);$r['demo_revenue']=(int)$q->fetchColumn();
    $q=db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments
        WHERE verified_at>=? AND verified_at<? AND currency='PHP' AND allocation_status='allocated'
        AND status='verified' AND payment_type<>'refund'");
    $q->execute([$from,$to]);
    $r['verified_receipts']=decimal_centavos((string)$q->fetchColumn());
    $q=db()->prepare("SELECT COALESCE(SUM(GREATEST(0,ROUND(a.total_amount*100)-COALESCE(d.paid,0))),0) FROM appointments a LEFT JOIN (SELECT appointment_id,SUM(amount_centavos) paid FROM demo_payments WHERE is_demo=1 AND status='completed' GROUP BY appointment_id)d ON d.appointment_id=a.id WHERE a.booking_mode='demo' AND a.status IN ('confirmed','ongoing','completed') AND a.starts_at>=? AND a.starts_at<?");$q->execute([$from,$to]);$r['demo_outstanding']=(int)$q->fetchColumn();
    $q=db()->prepare("SELECT COUNT(DISTINCT t.id) FROM therapists t JOIN therapist_schedules s ON s.therapist_id=t.id WHERE t.is_active=1 AND s.schedule_type='available' AND s.starts_at<? AND s.ends_at>?");$q->execute([$to,$from]);$r['scheduled_therapists']=(int)$q->fetchColumn();return $r;
}
function admin_room_states(?DateTimeImmutable $now=null):array {
    $now??=new DateTimeImmutable('now',spa_timezone());$utc=utc_time($now);
    $q=db()->prepare('SELECT opens_at,closes_at FROM operating_hours WHERE weekday=?');$q->execute([(int)$now->format('N')]);$hours=$q->fetch();
    $open=$hours&&$now>=local_datetime($now->format('Y-m-d'),substr($hours['opens_at'],0,5))&&$now<local_datetime($now->format('Y-m-d'),substr($hours['closes_at'],0,5));
    $rooms=db()->query('SELECT r.*,s.name category FROM rooms r JOIN services s ON s.id=r.service_id ORDER BY r.code')->fetchAll();
    foreach($rooms as &$room){
        $q=db()->prepare('SELECT * FROM appointments WHERE room_id=? AND '.blocking_appointment_sql().' AND starts_at<=? AND cleaning_ends_at>? ORDER BY starts_at LIMIT 1');$q->execute([$room['id'],$utc,$utc]);$room['current']=$q->fetch()?:null;
        $q=db()->prepare('SELECT * FROM appointments WHERE room_id=? AND '.blocking_appointment_sql().' AND starts_at>? ORDER BY starts_at LIMIT 1');$q->execute([$room['id'],$utc]);$room['next']=$q->fetch()?:null;
        $q=db()->prepare('SELECT COUNT(*) FROM room_blocks WHERE room_id=? AND starts_at<=? AND ends_at>?');$q->execute([$room['id'],$utc,$utc]);$blocked=(int)$q->fetchColumn()>0;
        $room['state']=!$room['is_active']?'inactive':($room['current']?(local_time($room['current']['ends_at'])<=$now?'cleaning':'occupied'):($blocked?'blocked':($open?'available':'closed')));
    }unset($room);return $rooms;
}
function admin_calendar_day(string $date):array {
    [$from,$to]=admin_day($date);
    $q=db()->prepare('SELECT a.*,r.code room_code,u.full_name customer_name,
        (SELECT GROUP_CONCAT(t.full_name ORDER BY t.id SEPARATOR ", ") FROM appointment_therapists atp JOIN therapists t ON t.id=atp.therapist_id WHERE atp.appointment_id=a.id) AS therapist_names
        FROM appointments a JOIN rooms r ON r.id=a.room_id JOIN users u ON u.id=a.user_id
        WHERE '.blocking_appointment_sql('a.').' AND a.starts_at<? AND a.cleaning_ends_at>? ORDER BY r.code,a.starts_at');
    $q->execute([$to,$from]);$appointments=$q->fetchAll();
    $q=db()->prepare('SELECT * FROM room_blocks WHERE starts_at<? AND ends_at>?');$q->execute([$to,$from]);$blocks=$q->fetchAll();
    $q=db()->prepare('SELECT opens_at,closes_at FROM operating_hours WHERE weekday=?');$q->execute([(int)local_datetime($date,'00:00')->format('N')]);$hours=$q->fetch();$times=[];
    if($hours){$cursor=local_datetime($date,substr($hours['opens_at'],0,5));$close=local_datetime($date,substr($hours['closes_at'],0,5))->modify('+'.(int)settings()['cleaning_gap_minutes'].' minutes');for(;$cursor<$close;$cursor=$cursor->modify('+30 minutes'))$times[]=$cursor;}
    $rooms=db()->query('SELECT * FROM rooms ORDER BY code')->fetchAll();return compact('rooms','times','appointments','blocks');
}
function admin_update_appointment(int $actor,string $reference,string $action,array $input):array {
    admin_actor($actor);$key=$input['request_key']??'';action_key($key);$p=db();$p->beginTransaction();
    try {
        lock_scheduling($p);$a=admin_appointment($reference);$audit='admin_'.$action;
        if(action_already_applied((int)$a['id'],$key,$audit)){$p->commit();return $a;}checked_revision($a,$input['revision']??null);
        $now=new DateTimeImmutable('now',spa_timezone());$before=['status'=>$a['status'],'starts_at'=>$a['starts_at']];$after=[];
        if($action==='status') {
            $status=$input['status']??'';$allowed=['pending'=>['confirmed'],'confirmed'=>['ongoing','completed','no_show'],'ongoing'=>['completed']];
            if(!in_array($status,$allowed[$a['status']]??[],true))throw new BookingValidationException('That status transition is not allowed. Awaiting-payment bookings must finish their checkout.');
            if(in_array($status,['ongoing','no_show'],true)&&$now<local_time($a['starts_at']))throw new BookingValidationException('That status requires the appointment start time to have arrived.');
            if($status==='ongoing'&&$now>=local_time($a['ends_at']))throw new BookingValidationException('This treatment has ended; mark it completed instead.');
            if($status==='completed'&&$now<local_time($a['ends_at']))throw new BookingValidationException('The treatment must finish before it can be marked completed.');
            $q=$p->prepare('UPDATE appointments SET status=?,revision=revision+1 WHERE id=?');$q->execute([$status,$a['id']]);$after=['status'=>$status];
        } elseif($action==='cancel') {
            $reason=trim($input['reason']??'');if(mb_strlen($reason)<3||mb_strlen($reason)>1000)throw new BookingValidationException('Provide a cancellation reason using 3–1,000 characters.');
            if(!in_array($a['status'],['pending','confirmed','ongoing','awaiting_payment'],true))throw new BookingValidationException('This appointment cannot be cancelled again.');
            $s=payment_summary($a);$late=$now>appointment_deadline($a);
            $forfeiture=$late?late_downpayment_forfeiture($a):0;
            $q=$p->prepare('INSERT INTO cancellation_records (appointment_id,actor_user_id,reason,notice_deadline,cancelled_at,is_late,known_forfeiture_centavos) VALUES (?,?,?,?,?,?,?)');$q->execute([$a['id'],$actor,$reason,utc_time(appointment_deadline($a)),utc_time($now),$late?1:0,$forfeiture]);$cancelId=(int)$p->lastInsertId();
            if($s['paid']>0||$s['review']){$q=$p->prepare("INSERT INTO refund_requests (appointment_id,cancellation_id,reason,is_demo) VALUES (?,?, 'Administrator cancellation: refund eligibility requires policy review',?)");$q->execute([$a['id'],$cancelId,$s['is_demo']?1:0]);}
            $q=$p->prepare("UPDATE appointments SET status='cancelled',payment_status=?,cancelled_at=UTC_TIMESTAMP(),hold_expires_at=NULL,revision=revision+1 WHERE id=?");$q->execute([$s['paid']>0||$s['review']?'refund_pending_review':'cancelled',$a['id']]);
            $q=$p->prepare("UPDATE payment_attempts SET status='cancelled' WHERE appointment_id=? AND status IN ('initiated','pending')");$q->execute([$a['id']]);$after=['status'=>'cancelled','reason'=>$reason,'is_demo'=>$s['is_demo'],'late'=>$late];
        } elseif($action==='assign'||$action==='reschedule') {
            if(!in_array($a['status'],['pending','confirmed'],true)||local_time($a['starts_at'])<=$now)throw new BookingValidationException('Only a future confirmed appointment can change its therapist or schedule.');
            $service=reschedule_service($a);$date=$action==='reschedule'?($input['date']??''):local_time($a['starts_at'])->format('Y-m-d');$time=$action==='reschedule'?($input['time']??''):local_time($a['starts_at'])->format('H:i');
            $window=appointment_window($service,$date,$time);
            if(!room_available((int)$a['room_id'],$window,(int)$a['id']))throw new BookingValidationException('Replacement conflicts with a booking or cleaning. The original appointment is unchanged.');
            $available=available_therapists($window,(int)$a['id'],(int)$a['service_id']);$required=required_therapists((int)$a['service_id']);$ids=array_column($available,'id');
            if($action==='assign'){$chosen=$input['therapist_ids']??[];if(!is_array($chosen))throw new BookingValidationException('Choose available therapists.');$chosen=array_map(fn($id)=>positive_integer($id,'therapist'),$chosen);$chosen=array_values(array_unique($chosen));if(count($chosen)!==$required||array_diff($chosen,$ids))throw new BookingValidationException('Select exactly '.$required.' available therapist(s).');}
            else {$chosen=array_column(staff_for_existing_appointment($a,$window),'id');}
            $q=$p->prepare('DELETE FROM appointment_therapists WHERE appointment_id=?');$q->execute([$a['id']]);$q=$p->prepare('INSERT INTO appointment_therapists (appointment_id,therapist_id) VALUES (?,?)');foreach($chosen as $id)$q->execute([$a['id'],$id]);
            $preferred=$a['preferred_therapist_id'];
            if ($action==='assign' && !in_array($preferred,$chosen)) $preferred=null;
            $q=$p->prepare('UPDATE appointments SET starts_at=?,ends_at=?,cleaning_ends_at=?,preferred_therapist_id=?,revision=revision+1 WHERE id=?');$q->execute([utc_time($window['start']),utc_time($window['end']),utc_time($window['cleaning_end']),$preferred,$a['id']]);$after=['starts_at'=>utc_time($window['start']),'therapists'=>$chosen,'preferred_therapist_id'=>$preferred];
        } elseif($action==='refund_review') {
            $note=trim($input['note']??'');$status=$input['review_status']??'';if(mb_strlen($note)<3||mb_strlen($note)>1000||!in_array($status,['pending_admin_review','resolved'],true))throw new BookingValidationException('Enter a review note and a valid review status.');
            $q=$p->prepare('SELECT id FROM refund_requests WHERE appointment_id=? LIMIT 1');$q->execute([$a['id']]);if(!$q->fetch())throw new BookingValidationException('No refund request exists for this appointment.');
            $q=$p->prepare('UPDATE refund_requests SET status=?,admin_note=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE appointment_id=?');$q->execute([$status,$note,$actor,$a['id']]);if($a['booking_mode']==='demo')refresh_payment_status((int)$a['id']);$after=['review_status'=>$status,'note'=>$note,'refund_issued'=>false];
        } elseif($action==='review_reschedule') {
            $q=$p->prepare("SELECT id FROM appointment_changes WHERE appointment_id=? AND action IN ('rescheduled','admin_reschedule') ORDER BY id DESC LIMIT 1");$q->execute([$a['id']]);$change=$q->fetchColumn();if(!$change)throw new BookingValidationException('No rescheduling change is awaiting review.');$after=['reviewed_change_id'=>(int)$change];
        } else throw new BookingValidationException('Choose an implemented administrator action.');
        audit_appointment((int)$a['id'],$actor,$audit,$before,$after,$key);$p->commit();return admin_appointment($reference);
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
