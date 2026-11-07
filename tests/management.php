<?php
require __DIR__.'/phase3-fixtures.php';
$f=new Phase3Fixtures();$checks=0;$originalRules=payment_rules();
function assert_case(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);++$checks;echo "PASS: $label\n";}
function rejected(callable $fn,string $label):void{try{$fn();}catch(BookingValidationException|PaymentUnavailable $e){assert_case(true,$label);return;}throw new RuntimeException('FAIL: '.$label);}
function reloaded(array $a):array{return owned_appointment((int)$a['user_id'],$a['booking_reference']);}
function change_race(array $jobs):array{
    $barrier=__DIR__.'/../var/management-race-'.bin2hex(random_bytes(6));$running=[];
    try{
        foreach($jobs as $i=>$job){$ready=$barrier.'-'.$i.'.ready';$process=proc_open([PHP_BINARY,__DIR__.'/management-worker.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('Worker could not start');$job+=['ready'=>$ready,'barrier'=>$barrier];fwrite($pipes[0],json_encode($job));fclose($pipes[0]);$running[]=[$process,$pipes,$ready];}
        $end=microtime(true)+8;while(!is_file($running[0][2])||!is_file($running[1][2])){if(microtime(true)>$end)throw new RuntimeException('Workers not ready');usleep(10000);}file_put_contents($barrier,'go');$results=[];
        foreach($running as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($err);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}return $results;
    }finally{if(is_file($barrier))unlink($barrier);foreach($running as $row)if(is_file($row[2]))unlink($row[2]);}
}
try{
    assert_case(decimal_centavos('1234.56')===123456 && decimal_amount(123456)==='1234.56','Exact centavo conversion round trip');
    assert_case(percentage_centavos(550000,'12.50')===68750,'Configured percentage uses integer arithmetic');
    assert_case(percentage_centavos(101,'50.00')===51,'Half-cent rounds half up without floating point');
    try{decimal_centavos('1.001');throw new RuntimeException('Accepted subcentavo');}catch(InvalidArgumentException $e){assert_case(true,'Sub-centavo input rejected');}
    $a=$f->book('SR-1',0,'16:00');$deadline=appointment_deadline($a);
    assert_case(change_eligibility($a,$deadline->modify('-1 second'))['eligible'],'Cancellation before six-hour deadline eligible');
    assert_case(change_eligibility($a,$deadline)['eligible'],'Exactly six hours before start is eligible');
    assert_case(!change_eligibility($a,$deadline->modify('+1 second'))['eligible'],'One second after deadline is too late');
    rejected(fn()=>cancel_appointment($f->users[0],$a['booking_reference'],'Too late',0,bin2hex(random_bytes(32)),$deadline->modify('+1 second')),'Late cancellation is rejected');
    assert_case(reloaded($a)['status']==='confirmed','Late rejection preserves original appointment');
    rejected(fn()=>cancel_appointment($f->users[1],$a['booking_reference'],'Not mine',0,bin2hex(random_bytes(32))),'Another customer cannot cancel');
    $historical=$f->legacyPayment($a,'200.00');$key=bin2hex(random_bytes(32));
    $cancelled=cancel_appointment($f->users[0],$a['booking_reference'],'Disposable timely cancellation',0,$key,$deadline);
    assert_case($cancelled['status']==='cancelled' && $cancelled['cancelled_at']!==null,'Eligible cancellation persisted with timestamp');
    $again=cancel_appointment($f->users[0],$a['booking_reference'],'Same cancellation',0,$key,$deadline);
    assert_case($again['id']===$cancelled['id'],'Cancellation retry is idempotent');
    $q=db()->prepare('SELECT * FROM payments WHERE id=?');$q->execute([$historical['id']]);assert_case($q->fetch()===$historical,'Cancellation leaves historical payment record unchanged');
    $q=db()->prepare('SELECT * FROM refund_requests WHERE appointment_id=?');$q->execute([$a['id']]);$refund=$q->fetch();
    assert_case($refund['status']==='pending_policy_review' && $refund['requested_amount_centavos']===null,'Refund eligibility and amount remain pending, not invented');
    assert_case($cancelled['payment_status']==='refund_pending_review','Paid cancellation is clearly marked for review');
    $window=['start'=>local_time($a['starts_at']),'end'=>local_time($a['ends_at']),'cleaning_end'=>local_time($a['cleaning_ends_at'])];
    assert_case(room_available((int)$a['room_id'],$window),'Cancelled room becomes available');
    assert_case(count(available_therapists($window))===2,'Cancellation releases active therapist allocations');
    $q=db()->prepare('SELECT COUNT(*) FROM appointment_therapists WHERE appointment_id=?');$q->execute([$a['id']]);assert_case((int)$q->fetchColumn()===1,'Historical therapist assignment retained');
    $q=db()->prepare('SELECT COUNT(*) FROM appointment_changes WHERE appointment_id=?');$q->execute([$a['id']]);assert_case((int)$q->fetchColumn()===1,'Exactly one cancellation audit record');
    $b=$f->book('SR-1',1,'13:00');$ledger=$f->legacyPayment($b,'800.00','full');
    $changed=reschedule_appointment($f->users[0],$b['booking_reference'],$f->date(1),'13:30',0,$key=bin2hex(random_bytes(32)));
    assert_case($changed['id']===$b['id'] && local_time($changed['starts_at'])->format('H:i')==='13:30','Rescheduling keeps ID and allows overlap with its own old slot');
    assert_case($changed['total_amount']===$b['total_amount'] && $changed['service_id']===$b['service_id'] && $changed['guest_count']===$b['guest_count'],'Service, guests and price preserved');
    $q=db()->prepare('SELECT * FROM payments WHERE id=?');$q->execute([$ledger['id']]);assert_case($q->fetch()===$ledger,'Rescheduling preserves payment history');
    assert_case(reschedule_appointment($f->users[0],$b['booking_reference'],$f->date(1),'13:30',0,$key)['revision']===$changed['revision'],'Rescheduling retry does not apply twice');
    rejected(fn()=>reschedule_appointment($f->users[1],$b['booking_reference'],$f->date(2),'13:00',1,bin2hex(random_bytes(32))),'Another customer cannot reschedule');
    rejected(fn()=>reschedule_appointment($f->users[0],$b['booking_reference'],$f->date(2),'13:00',0,bin2hex(random_bytes(32))),'Stale revision cannot overwrite a newer change');
    rejected(fn()=>reschedule_appointment($f->users[0],$b['booking_reference'],$f->date(2),'13:00',1,bin2hex(random_bytes(32)),appointment_deadline($changed)->modify('+1 second')),'Late rescheduling rejected');
    $occupied=$f->book('SR-1',2,'13:00');
    rejected(fn()=>reschedule_appointment($f->users[0],$b['booking_reference'],$f->date(2),'14:00',1,bin2hex(random_bytes(32))),'Replacement room cleaning blocks invalid change');
    assert_case(reloaded($b)['starts_at']===$changed['starts_at'],'Failed replacement leaves old booking unchanged');
    rejected(fn()=>reschedule_appointment($f->users[0],$b['booking_reference'],'2020-01-01','13:00',1,bin2hex(random_bytes(32))),'Past replacement date rejected');
    // Both candidates race for the same replacement room, preserving the losing original.
    $one=$f->book('SR-1',3,'13:00');$two=$f->book('SR-1',3,'16:00',1);
    $jobs=[];foreach([$one,$two] as $candidate)$jobs[]=['user'=>(int)$candidate['user_id'],'reference'=>$candidate['booking_reference'],'date'=>$f->date(3),'time'=>'19:00','revision'=>0,'key'=>bin2hex(random_bytes(32))];
    $results=change_race($jobs);assert_case(count(array_filter($results,fn($r)=>$r['status']==='changed'))===1,'Concurrent reschedules cannot double-book a replacement room');
    $remaining=[reloaded($one),reloaded($two)];assert_case(count(array_filter($remaining,fn($r)=>(int)$r['revision']===0))===1,'Losing concurrent request retains its original allocation');
    // A shared last therapist also serializes replacements in different rooms.
    $three=$f->book('SR-1',4,'13:00');$four=$f->book('SR-2',4,'16:00',1);
    $q=db()->prepare('UPDATE therapists SET is_active=0 WHERE id=?');$q->execute([$f->staff[1]]);
    $jobs=[];foreach([$three,$four] as $candidate)$jobs[]=['user'=>(int)$candidate['user_id'],'reference'=>$candidate['booking_reference'],'date'=>$f->date(5),'time'=>'13:00','revision'=>0,'key'=>bin2hex(random_bytes(32))];
    $results=change_race($jobs);assert_case(count(array_filter($results,fn($r)=>$r['status']==='changed'))===1,'Concurrent reschedules cannot double-book the last therapist');
    $q=db()->prepare('UPDATE therapists SET is_active=0 WHERE id=?');$q->execute([$f->staff[0]]);
    rejected(fn()=>reschedule_appointment($f->users[0],$b['booking_reference'],$f->date(9),'13:00',1,bin2hex(random_bytes(32))),'Unavailable therapists reject a replacement appointment');
    assert_case(reloaded($b)['starts_at']===$changed['starts_at'],'Therapist rejection retains the original schedule');
    $q=db()->prepare('UPDATE therapists SET is_active=1 WHERE id=?');$q->execute([$f->staff[0]]);
    assert_case(!payment_readiness()['ready'],'Unselected provider keeps checkout disabled');
    rejected(fn()=>initiate_payment($f->users[0],$b['booking_reference'],'full',1,bin2hex(random_bytes(32))),'Forged checkout POST cannot bypass disabled provider');
    rejected(fn()=>receive_payment_webhook('{"paid":true}',[]),'Unsigned JSON cannot create a payment when gateway is unconfigured');
    assert_case((int)db()->query('SELECT COUNT(*) FROM payment_attempts')->fetchColumn()===0,'Disabled checkout creates no financial attempts');
    $unpaid=$f->book('FL-1',6,'13:00',0,6);$plan=payment_plan($unpaid,'full');assert_case($plan['amount']===550000 && $plan['remaining']===0,'Full-payment proposal uses exact outstanding amount');
    rejected(fn()=>payment_plan($unpaid,'downpayment'),'Unconfigured downpayment is disabled');
    db()->exec("UPDATE business_settings SET downpayment_percentage=12.50 WHERE id=1");$plan=payment_plan($unpaid,'downpayment');assert_case($plan['amount']===68750 && $plan['remaining']===481250,'Configured proposal computes exact due and remaining balance');
    $summary=payment_summary($b);assert_case($summary['paid']===80000 && $summary['balance']===0,'Existing verified ledger entries determine paid and remaining totals');
    // Expired hold visibility and cleanup need no payment provider or simulated payment.
    $hold=$f->book('SR-1',7,'13:00');$q=db()->prepare("UPDATE appointments SET status='awaiting_payment',booking_mode='online',hold_expires_at=UTC_TIMESTAMP()-INTERVAL 1 MINUTE WHERE id=?");$q->execute([$hold['id']]);
    $window=['start'=>local_time($hold['starts_at']),'end'=>local_time($hold['ends_at']),'cleaning_end'=>local_time($hold['cleaning_ends_at'])];assert_case(room_available((int)$hold['room_id'],$window),'Expired holds do not block room availability even before cleanup');
    assert_case(expire_payment_holds()===1 && reloaded($hold)['status']==='expired','Reconciliation cleanup persists hold expiration');
    echo "\n$checks Phase 3 management/boundary checks passed. Gateway integration scenarios remain pending.\n";
}finally{
    $q=db()->prepare('UPDATE business_settings SET downpayment_percentage=?,checkout_hold_minutes=? WHERE id=1');$q->execute([$originalRules['downpayment_percentage'],$originalRules['checkout_hold_minutes']]);$f->cleanup();
}
