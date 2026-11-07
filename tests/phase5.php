<?php
declare(strict_types=1);
$config=require __DIR__.'/../config/config.php';if(!str_ends_with($config['db_name'],'_test')){fwrite(STDERR,"Use a separate development-only _test database.\n");exit(1);}require_once __DIR__.'/../app/database.php';$p=db();
if((int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn()||(int)$p->query('SELECT COUNT(*) FROM therapists')->fetchColumn()||(int)$p->query('SELECT COUNT(*) FROM appointments')->fetchColumn()){fwrite(STDERR,"Run Phase 5 service fixtures on a fresh test database before adding demo staff.\n");exit(1);}
$originalDemo=$p->query('SELECT demo_payments_enabled,demo_downpayment_percentage,demo_hold_minutes FROM business_settings WHERE id=1')->fetch();
require __DIR__.'/phase3-fixtures.php';require __DIR__.'/../app/modules/admin.php';
$f=null;$checks=0;$blockIds=[];
function p5check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);++$checks;echo "PASS: $label\n";}
function p5reject(callable $fn,string $label):void {try{$fn();}catch(BookingValidationException $e){p5check(true,$label);return;}throw new RuntimeException('FAIL: '.$label);}
function p5reload(array $a):array {return owned_appointment((int)$a['user_id'],$a['booking_reference']);}
function p5pay(array $a,string $choice='full',string $method='demo_gcash',?string $key=null):array { $plan=demo_plan($a,$choice);return complete_demo_payment((int)$a['user_id'],$a['booking_reference'],$choice,$method,$a['revision'],$key??bin2hex(random_bytes(32)),(string)$plan['amount']); }
function p5input(string $room,int $day,string $time='13:00',int $owner=0):array {global $f;return ['service_id'=>$f->rooms[$room]['service_id'],'room_id'=>$f->rooms[$room]['id'],'guests'=>1,'date'=>$f->date($day),'time'=>$time,'health_id'=>$f->health[$owner],'terms'=>'1','request_key'=>bin2hex(random_bytes(32))];}
function p5race(array $jobs):array {
    $barrier=__DIR__.'/../var/p5-race-'.bin2hex(random_bytes(8));$running=[];
    try {
        foreach($jobs as $index=>$job){$ready=$barrier.'-'.$index.'.ready';$process=proc_open([PHP_BINARY,__DIR__.'/phase5-worker.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('Worker launch failed.');$job+=['barrier'=>$barrier,'ready'=>$ready];fwrite($pipes[0],json_encode($job));fclose($pipes[0]);$running[]=[$process,$pipes,$ready];}
        $deadline=microtime(true)+8;while(!is_file($running[0][2])||!is_file($running[1][2])){if(microtime(true)>$deadline)throw new RuntimeException('Worker readiness timeout');usleep(10000);}file_put_contents($barrier,'go');$results=[];
        foreach($running as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Worker failed: '.$error);$results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR);}return $results;
    }finally{if(is_file($barrier))unlink($barrier);foreach($running as $job)if(is_file($job[2]))unlink($job[2]);}
}
try {
    $f=new Phase3Fixtures();$p->exec('UPDATE business_settings SET demo_payments_enabled=1,demo_downpayment_percentage=30,demo_hold_minutes=15 WHERE id=1');
    $q=$p->prepare("UPDATE users SET role='admin' WHERE id=?");$q->execute([$f->users[1]]);$admin=$f->users[1];
    $full=$f->book('SR-1',0);p5check($full['status']==='awaiting_payment'&&$full['booking_mode']==='demo','Demo booking begins as an actual temporary hold');
    p5check(local_time($full['hold_expires_at'])>new DateTimeImmutable('now',spa_timezone()),'Temporary demo hold has a future expiry');
    p5check(demo_summary($full)['paid']===0,'A hold alone never counts as simulated payment');
    $key=bin2hex(random_bytes(32));$receipt=p5pay($full,'full','demo_gcash',$key);$full=p5reload($full);
    p5check($receipt['is_demo']==1&&str_starts_with($receipt['transaction_reference'],'DEMO-'),'Simulated receipt has an explicit demo flag and reference');
    p5check($full['status']==='confirmed'&&$full['payment_status']==='paid','Demo completion atomically confirms the appointment');
    p5check(demo_summary($full)['paid']===80000&&demo_summary($full)['balance']===0,'Full single demo payment is ₱800 with zero balance');
    $repeat=complete_demo_payment($f->users[0],$full['booking_reference'],'full','demo_gcash',0,$key,'80000');
    p5check($repeat['id']===$receipt['id'],'Repeated completion returns the same receipt despite stale revision');
    p5reject(fn()=>p5pay($full),'Already fully simulated-paid appointment cannot create a second receipt');
    $down=$f->book('SR-2',1);$plan=demo_plan($down,'downpayment');
    p5check($plan['amount']===24000&&$plan['remaining']===56000,'30% single downpayment is ₱240 and balance ₱560');
    $downReceipt=p5pay($down,'downpayment','demo_maya');$down=p5reload($down);
    p5check($down['status']==='confirmed'&&$down['payment_status']==='partially_paid','Demo downpayment confirms with partial demo payment state');
    p5check(demo_summary($down)['paid']===24000&&demo_summary($down)['balance']===56000,'Demo remaining balance reads saved ledger records');
    p5pay($down,'full','demo_card');$down=p5reload($down);p5check(demo_summary($down)['paid']===80000&&demo_summary($down)['balance']===0,'Remaining demo balance can be completed without double charging');
    $family=$f->book('FL-1',2,'13:00',0,6);$familyPlan=demo_plan($family,'downpayment');p5check($familyPlan['amount']===165000&&$familyPlan['remaining']===385000,'Family total and 30% demo downpayment use exact centavos');
    p5reject(fn()=>complete_demo_payment($f->users[0],$family['booking_reference'],'downpayment','demo_maya',$family['revision'],bin2hex(random_bytes(32)),'1'),'Forged displayed demo amount is rejected');
    p5reject(fn()=>complete_demo_payment($f->users[1],$family['booking_reference'],'full','demo_card',0,bin2hex(random_bytes(32)),'550000'),'Another user cannot complete this customer’s payment');
    p5reject(fn()=>complete_demo_payment($f->users[0],$family['booking_reference'],'full','real_card',0,bin2hex(random_bytes(32)),'550000'),'Real/unrecognized payment methods are rejected');
    $expired=$f->book('SR-1',3);$q=$p->prepare('UPDATE appointments SET hold_expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=?');$q->execute([$expired['id']]);
    p5reject(fn()=>p5pay($expired),'Expired hold cannot produce simulated payment');$expired=p5reload($expired);p5check($expired['status']==='expired','Expired hold state is persisted even when checkout is rejected');
    p5check(availability(p5input('SR-1',3))['slots'][0]['available'],'Expired hold releases room and therapist availability');
    $abandoned=$f->book('SR-1',4);cancel_demo_hold($f->users[0],$abandoned['booking_reference'],$abandoned['revision'],bin2hex(random_bytes(32)));
    p5check(p5reload($abandoned)['status']==='expired'&&availability(p5input('SR-1',4))['slots'][0]['available'],'Abandoned checkout immediately releases its resources');
    $staffFail=$f->book('SR-1',5);$p->exec('UPDATE therapists SET is_active=0');p5reject(fn()=>p5pay($staffFail),'Completion rechecks actual therapist availability');$p->exec('UPDATE therapists SET is_active=1');
    $priceFail=$f->book('SR-1',6);$p->exec("UPDATE services SET base_price=801 WHERE slug='single-room-massage'");p5reject(fn()=>p5pay($priceFail),'Completion revalidates the server treatment price');$p->exec("UPDATE services SET base_price=800 WHERE slug='single-room-massage'");
    $roomFail=$f->book('SR-1',7);$q=$p->prepare('INSERT INTO room_blocks (room_id,starts_at,ends_at,reason) VALUES (?,?,?,?)');$q->execute([$roomFail['room_id'],utc_time(local_datetime($f->date(7),'13:00')),utc_time(local_datetime($f->date(7),'13:30')),'Development-only checkout conflict']);$blockIds[]=(int)$p->lastInsertId();
    p5reject(fn()=>p5pay($roomFail),'Completion rechecks room conflicts including new maintenance blocks');$q=$p->prepare('SELECT COUNT(*) FROM demo_payments WHERE appointment_id=?');$q->execute([$roomFail['id']]);p5check((int)$q->fetchColumn()===0,'Failed resource validation creates no simulated payment');
    cancel_demo_hold($f->users[0],$roomFail['booking_reference'],$roomFail['revision'],bin2hex(random_bytes(32)));$q=$p->prepare('DELETE FROM room_blocks WHERE id=?');$q->execute([array_pop($blockIds)]);p5check(availability(p5input('SR-1',7))['slots'][0]['available'],'Abandoning failed checkout frees the room after its separate block is removed');
    $before=$full['starts_at'];$q=$p->prepare('SELECT COUNT(*) FROM demo_payments WHERE appointment_id=?');$q->execute([$full['id']]);$beforeLedger=(int)$q->fetchColumn();
    p5reject(fn()=>reschedule_appointment($f->users[0],$full['booking_reference'],$f->date(0),'13:15',$full['revision'],bin2hex(random_bytes(32))),'Invalid rescheduling preserves the original demo appointment');
    p5check(p5reload($full)['starts_at']===$before,'Failed replacement leaves original time intact');
    $full=reschedule_appointment($f->users[0],$full['booking_reference'],$f->date(0),'15:00',$full['revision'],bin2hex(random_bytes(32)));
    p5check(local_time($full['starts_at'])->format('H:i')==='15:00'&&demo_summary($full)['paid']===80000,'Successful reschedule preserves demo ledger and total');
    $review=admin_update_appointment($admin,$full['booking_reference'],'review_reschedule',['revision'=>$full['revision'],'request_key'=>bin2hex(random_bytes(32))]);p5check($review['id']===$full['id'],'Administrator can record a persistent rescheduling review');
    p5reject(fn()=>admin_update_appointment($f->users[0],$full['booking_reference'],'status',['revision'=>$full['revision'],'request_key'=>bin2hex(random_bytes(32)),'status'=>'completed']),'Customer cannot invoke administrator actions');
    p5reject(fn()=>admin_update_appointment($admin,$full['booking_reference'],'status',['revision'=>$full['revision'],'request_key'=>bin2hex(random_bytes(32)),'status'=>'completed']),'Administrator cannot prematurely complete a future treatment');
    $full=admin_update_appointment($admin,$full['booking_reference'],'assign',['revision'=>$full['revision'],'request_key'=>bin2hex(random_bytes(32)),'therapist_ids'=>[$f->staff[1]]]);
    $q=$p->prepare('SELECT therapist_id FROM appointment_therapists WHERE appointment_id=?');$q->execute([$full['id']]);p5check((int)$q->fetchColumn()===$f->staff[1],'Administrator therapist reassignment persists');
    p5reject(fn()=>admin_update_appointment($admin,$full['booking_reference'],'assign',['revision'=>$full['revision'],'request_key'=>bin2hex(random_bytes(32)),'therapist_ids'=>[99999999]]),'Unavailable or missing therapist cannot be assigned');
    $full=admin_update_appointment($admin,$full['booking_reference'],'reschedule',['revision'=>$full['revision'],'request_key'=>bin2hex(random_bytes(32)),'date'=>$f->date(0),'time'=>'16:30']);p5check(local_time($full['starts_at'])->format('H:i')==='16:30','Administrator can safely reschedule without losing payment');
    $deadline=appointment_deadline($down);p5check(change_eligibility($down,$deadline)['eligible']&&!change_eligibility($down,$deadline->modify('+1 second'))['eligible'],'Six-hour rule includes the exact boundary and rejects one second late');
    $down=cancel_appointment($f->users[0],$down['booking_reference'],'Development cancellation review',$down['revision'],bin2hex(random_bytes(32)),$deadline);
    $q=$p->prepare('SELECT * FROM refund_requests WHERE appointment_id=?');$q->execute([$down['id']]);$refund=$q->fetch();p5check($down['status']==='cancelled'&&$refund['is_demo']==1&&$refund['requested_amount_centavos']===null,'Demo cancellation records policy review without inventing a refund entitlement');
    p5check(demo_summary($down)['paid']===80000&&demo_summary($down)['refunded']===0,'Cancellation preserves simulated payment and issues no refund');
    admin_update_appointment($admin,$down['booking_reference'],'refund_review',['revision'=>$down['revision'],'request_key'=>bin2hex(random_bytes(32)),'note'=>'Prototype policy reviewed; no automatic refund issued.','review_status'=>'resolved']);$q->execute([$down['id']]);p5check($q->fetch()['status']==='resolved','Administrator review decision persists without a refund transaction');
    $race=$f->book('SR-1',8);$raceKey=bin2hex(random_bytes(32));$job=['action'=>'pay','user_id'=>$f->users[0],'reference'=>$race['booking_reference'],'choice'=>'full','method'=>'demo_card','revision'=>0,'key'=>$raceKey,'expected'=>'80000'];$outcomes=p5race([$job,$job]);
    p5check($outcomes[0]['ok']&&$outcomes[1]['ok']&&$outcomes[0]['result']['id']===$outcomes[1]['result']['id'],'Concurrent same-key demo completion creates one shared receipt');
    $race=$f->book('SR-1',9);$job['reference']=$race['booking_reference'];$job['key']=bin2hex(random_bytes(32));$otherJob=$job;$otherJob['key']=bin2hex(random_bytes(32));$outcomes=p5race([$job,$otherJob]);p5check(count(array_filter($outcomes,fn($r)=>$r['ok']))===1,'Concurrent distinct completion keys cannot pay the same hold twice');
    $bookJob=['action'=>'book','user_id'=>$f->users[0],'input'=>p5input('SR-1',10)];$bookOther=['action'=>'book','user_id'=>$f->users[0],'input'=>p5input('SR-1',10)];$outcomes=p5race([$bookJob,$bookOther]);p5check(count(array_filter($outcomes,fn($r)=>$r['ok']))===1,'Concurrent demo bookings cannot acquire the same room');
    $p->prepare('UPDATE therapists SET is_active=0 WHERE id=?')->execute([$f->staff[1]]);$bookJob['input']=p5input('SR-1',11);$bookOther['input']=p5input('SR-2',11);$outcomes=p5race([$bookJob,$bookOther]);p5check(count(array_filter($outcomes,fn($r)=>$r['ok']))===1,'Concurrent room holds cannot double-assign the last therapist');$p->exec('UPDATE therapists SET is_active=1');
    $preferred=$f->staff[1];
    $bookJob['input']=p5input('SR-1',16)+['therapist_id'=>$preferred];
    $bookOther['input']=p5input('SR-2',16)+['therapist_id'=>$preferred];
    $outcomes=p5race([$bookJob,$bookOther]);
    p5check(count(array_filter($outcomes,fn($r)=>$r['ok']))===1,'Two simultaneous requests for the same named therapist cannot double book, even with another therapist free');
    $preferredInput=p5input('SR-1',17)+['therapist_id'=>$preferred];
    $selected=create_booking($f->users[0],$preferredInput);
    $selected=owned_appointment($f->users[0],$selected['booking_reference']);
    $p->prepare('UPDATE therapists SET is_active=0 WHERE id=?')->execute([$preferred]);
    try {
        p5reject(fn()=>p5pay($selected),'Named-therapist checkout refuses to substitute another available therapist');
        p5check(p5reload($selected)['status']==='awaiting_payment'&&!customer_demo_payments($f->users[0],(int)$selected['id']),'Failed therapist revalidation records no transaction');
        p5reject(fn()=>create_booking($f->users[0],p5input('SR-2',18)+['therapist_id'=>$preferred]),'A tampered booking request cannot select an inactive therapist');
    } finally {
        $p->prepare('UPDATE therapists SET is_active=1 WHERE id=?')->execute([$preferred]);
    }
    p5pay($selected);
    p5check(assigned_therapist_ids((int)$selected['id'])==[$preferred],'Checkout can confirm once the same preferred therapist is available again');
    $nowCase=$f->book('SR-1',12);p5pay($nowCase);$during=admin_room_states(local_datetime($f->date(12),'13:15'));$cleaning=admin_room_states(local_datetime($f->date(12),'14:15'));$free=admin_room_states(local_datetime($f->date(12),'14:30'));
    p5check(array_column($during,null,'code')['SR-1']['state']==='occupied','Admin room status accurately shows occupied treatment');p5check(array_column($cleaning,null,'code')['SR-1']['state']==='cleaning','Admin room status accurately shows cleaning');p5check(array_column($free,null,'code')['SR-1']['state']==='available','Admin room status returns to available at cleaning end');
    $calendar=admin_calendar_day($f->date(12));p5check(in_array($nowCase['booking_reference'],array_column($calendar['appointments'],'booking_reference'),true),'Calendar retrieves the actual newly confirmed appointment');
    $counts=admin_daily($f->date(12));p5check($counts['total']===1&&$counts['confirmed']===1&&$counts['cancelled']===0,'Daily booking status counts reflect the actual selected day');
    $completed=$f->book('SR-1',13);p5pay($completed);$completed=p5reload($completed);$q=$p->prepare('UPDATE appointments SET starts_at=starts_at-INTERVAL 30 DAY,ends_at=ends_at-INTERVAL 30 DAY,cleaning_ends_at=cleaning_ends_at-INTERVAL 30 DAY WHERE id=?');$q->execute([$completed['id']]);$completed=p5reload($completed);
    $completed=admin_update_appointment($admin,$completed['booking_reference'],'status',['revision'=>$completed['revision'],'request_key'=>bin2hex(random_bytes(32)),'status'=>'completed']);p5check(p5reload($completed)['status']==='completed','Valid administrator completion persists after treatment end');
    $late=$f->book('SR-1',14);p5pay($late,'downpayment');$late=p5reload($late);$q=$p->prepare('UPDATE appointments SET starts_at=UTC_TIMESTAMP()+INTERVAL 2 HOUR,ends_at=UTC_TIMESTAMP()+INTERVAL 3 HOUR,cleaning_ends_at=UTC_TIMESTAMP()+INTERVAL 210 MINUTE WHERE id=?');$q->execute([$late['id']]);$late=p5reload($late);
    p5reject(fn()=>cancel_appointment($f->users[0],$late['booking_reference'],'Too late for self service',$late['revision'],bin2hex(random_bytes(32))),'Customer cancellation less than six hours before treatment is rejected');
    $late=admin_update_appointment($admin,$late['booking_reference'],'cancel',['revision'=>$late['revision'],'request_key'=>bin2hex(random_bytes(32)),'reason'=>'Administrator review of late demo cancellation']);$q=$p->prepare('SELECT is_late FROM cancellation_records WHERE appointment_id=?');$q->execute([$late['id']]);p5check($late['status']==='cancelled'&&(int)$q->fetchColumn()===1,'Administrator can record a late cancellation with audit and policy review');
    $q=$p->prepare('SELECT known_forfeiture_centavos FROM cancellation_records WHERE appointment_id=?');$q->execute([$late['id']]);
    p5check((int)$q->fetchColumn()===24000&&demo_summary($late)['refunded']===0,'Late cancellation records the applicable ₱240 downpayment forfeiture without issuing any refund');
    $p->exec('UPDATE business_settings SET demo_downpayment_percentage=33.33 WHERE id=1');$round=$f->book('SR-1',15);p5check(demo_plan($round,'downpayment')['amount']===26664,'Editable percentage supports exact fractional-cent rounding');$p->exec('UPDATE business_settings SET demo_downpayment_percentage=30 WHERE id=1');
    $today=(new DateTimeImmutable('today',spa_timezone()))->format('Y-m-d');$daily=admin_daily($today);$sum=(int)$p->query('SELECT COALESCE(SUM(amount_centavos),0) FROM demo_payments')->fetchColumn();p5check($daily['demo_revenue']===$sum,'Daily demo revenue equals saved simulated ledger amounts');
    p5check((int)$p->query('SELECT COUNT(*) FROM payments')->fetchColumn()===0&&(int)$p->query('SELECT COUNT(*) FROM payment_attempts')->fetchColumn()===0,'Demo flows never create real payment transactions or gateway attempts');
    echo "\n$checks Phase 5 service/concurrency checks passed.\n";
} finally {
    $p->exec("UPDATE services SET base_price=800 WHERE slug='single-room-massage'");foreach($blockIds as $id){$q=$p->prepare('DELETE FROM room_blocks WHERE id=?');$q->execute([$id]);}if($f)$f->cleanup();$q=$p->prepare('UPDATE business_settings SET demo_payments_enabled=?,demo_downpayment_percentage=?,demo_hold_minutes=? WHERE id=1');$q->execute(array_values($originalDemo));
}
