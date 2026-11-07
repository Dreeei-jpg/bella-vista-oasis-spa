<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$config = require __DIR__ . '/../config/config.php';
if (!str_ends_with($config['db_name'], '_test')) exit("Set DB_NAME to a separate database ending in _test. Import schema.sql there first.\n");
require __DIR__ . '/../app/database.php';
require __DIR__ . '/../app/modules/catalog.php';
require __DIR__ . '/../app/modules/booking.php';
require __DIR__.'/legacy-mode.php';
$pdo = db();
$prefix = 'booking-qa-'.bin2hex(random_bytes(6));
$userIds = []; $therapistIds = []; $blockIds = []; $checks = 0;
$day = (new DateTimeImmutable('tomorrow', spa_timezone()))->modify('+10 days');
$rooms = $pdo->query('SELECT code,id,service_id FROM rooms')->fetchAll(PDO::FETCH_UNIQUE);
function expect(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    ++$checks; echo "PASS: $label\n";
}
function rejects(callable $operation, string $label): void {
    try { $operation(); } catch (BookingValidationException $exception) { expect(true,$label); return; }
    throw new RuntimeException('FAIL: '.$label.' was accepted');
}
function input_for(string $roomCode, int $offset, string $time, int $guests=1, int $owner=0): array {
    global $rooms,$day,$healthIds;
    return ['service_id'=>$rooms[$roomCode]['service_id'],'room_id'=>$rooms[$roomCode]['id'],'guests'=>$guests,'date'=>$day->modify("+$offset days")->format('Y-m-d'),'time'=>$time,'terms'=>'1','health_id'=>$healthIds[$owner],'request_key'=>bin2hex(random_bytes(32))];
}
function save_for(array $input, int $owner=0): array { global $userIds; return create_booking($userIds[$owner],$input); }
function saved(array $booking): array {
    $query=db()->prepare('SELECT * FROM appointments WHERE id=?'); $query->execute([$booking['id']]); return $query->fetch();
}
function shift(int $therapist, int $offset, string $start='13:00', string $end='21:00', string $type='available'): void {
    global $day;
    $date=$day->modify("+$offset days")->format('Y-m-d');
    $query=db()->prepare('INSERT INTO therapist_schedules (therapist_id,starts_at,ends_at,schedule_type) VALUES (?,?,?,?)');
    $query->execute([$therapist,utc_time(local_datetime($date,$start)),utc_time(local_datetime($date,$end)),$type]);
}
function race(array $first,array $second): array {
    global $userIds;
    $barrier=__DIR__.'/../var/race-'.bin2hex(random_bytes(8)); $jobs=[];
    try {
        foreach([$first,$second] as $index=>$input) {
            $ready=$barrier.'-'.$index.'.ready';
            $process=proc_open([PHP_BINARY,__DIR__.'/booking-worker.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
            if(!is_resource($process)) throw new RuntimeException('Could not start concurrency worker');
            fwrite($pipes[0],json_encode(['user_id'=>$userIds[$index],'input'=>$input,'barrier'=>$barrier,'ready'=>$ready])); fclose($pipes[0]);
            $jobs[]=['process'=>$process,'pipes'=>$pipes,'ready'=>$ready];
        }
        $deadline=microtime(true)+8;
        while(!is_file($jobs[0]['ready']) || !is_file($jobs[1]['ready'])) {
            if(microtime(true)>$deadline) throw new RuntimeException('Workers did not reach concurrency barrier');
            usleep(10000);
        }
        file_put_contents($barrier,'go');
        $results=[];
        foreach($jobs as $job) {
            $output=stream_get_contents($job['pipes'][1]); $error=stream_get_contents($job['pipes'][2]);
            fclose($job['pipes'][1]); fclose($job['pipes'][2]);
            if(proc_close($job['process'])!==0) throw new RuntimeException('Worker failed: '.$error);
            $results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        if(is_file($barrier)) unlink($barrier);
        foreach($jobs as $job) if(is_file($job['ready'])) unlink($job['ready']);
    }
}

try {
    for($i=0;$i<2;$i++) {
        $query=$pdo->prepare("INSERT INTO users (full_name,email,password_hash,terms_accepted_at) VALUES (?,?,?,UTC_TIMESTAMP())");
        $query->execute(['Disposable QA customer '.$i,$prefix.'-'.$i.'@example.test',password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
        $userIds[]=(int)$pdo->lastInsertId();
    }
    $health=array_fill_keys(array_keys(HEALTH_QUESTIONS),'no'); $health['notes']='Test only <script>alert(1)</script>'; $health['consent']='1';
    expect(validate_health($health)===[],'All eight explicit health answers and consent accepted');
    $bad=$health; unset($bad['pregnancy']); expect(count(validate_health($bad))===1,'Missing health answer rejected');
    $bad=$health; $bad['consent']=''; expect(count(validate_health($bad))===1,'Missing health consent rejected');
    $bad=$health; $bad['notes']=str_repeat('a',2001); expect(count(validate_health($bad))===1,'Long health notes rejected');
    $healthIds=[save_health_declaration($userIds[0],$health),save_health_declaration($userIds[1],$health)];
    expect(health_declaration($userIds[1],$healthIds[0])===null,'Health declarations are owner-scoped');
    $noStaff=availability(input_for('SR-1',0,'13:00'));
    expect(count($noStaff['slots'])===16 && !array_filter($noStaff['slots'],fn($s)=>$s['available']),'No published therapists means no bookable times');
    for($i=0;$i<2;$i++) {
        $query=$pdo->prepare('INSERT INTO therapists (full_name) VALUES (?)'); $query->execute([$prefix.' staff '.$i]);
        $therapistIds[]=(int)$pdo->lastInsertId();
        for($offset=0;$offset<=12;$offset++) shift($therapistIds[$i],$offset);
    }
    foreach([4=>4000,5=>4750,6=>5500] as $guests=>$price) {
        $input=input_for('FL-1',$guests-4,'13:00',$guests); $input['total_amount']='1'; $input['payment_status']='paid'; $input['status']='pending';
        $result=save_for($input); $record=saved($result);
        expect((int)$record['total_amount']===$price,"Server computes $guests guests at $price, ignoring forged price");
        expect($record['status']==='confirmed' && $record['payment_status']==='unpaid','Confirmation and unpaid status are server-controlled');
        expect(local_time($record['ends_at'])->format('H:i')==='14:30' && local_time($record['cleaning_ends_at'])->format('H:i')==='15:00','90-minute treatment and 30-minute cleaning persisted');
        expect(substr($record['starts_at'],11,5)==='05:00','1 PM Philippine time is stored at 05:00 UTC');
        if($guests===4) {
            $again=save_for($input); expect($again['booking_reference']===$result['booking_reference'],'Idempotent retry returns the same appointment');
        }
    }
    rejects(fn()=>save_for(input_for('FL-1',0,'14:00',4)),'Overlapping treatment rejected');
    rejects(fn()=>save_for(input_for('FL-1',0,'14:30',4)),'Room cleaning interval rejected');
    expect((bool)save_for(input_for('FL-1',0,'15:00',4)),'Exact end-of-cleaning boundary is allowed');
    rejects(fn()=>save_for(input_for('FL-1',0,'13:15',4)),'Non-grid appointment rejected');
    rejects(fn()=>save_for(input_for('FL-1',0,'12:30',4)),'Before-opening appointment rejected');
    rejects(fn()=>save_for(input_for('SR-1',3,'20:30')),'60-minute treatment finishing after close rejected');
    rejects(fn()=>save_for(input_for('FL-1',3,'20:00',4)),'90-minute treatment finishing after close rejected');
    $close=saved(save_for(input_for('SR-1',3,'20:00')));
    expect(local_time($close['ends_at'])->format('H:i')==='21:00' && local_time($close['cleaning_ends_at'])->format('H:i')==='21:30','Treatment may finish at 9 PM with cleaning afterward');
    rejects(fn()=>save_for(input_for('FL-1',3,'13:00',7)),'Family capacity over six rejected');
    rejects(fn()=>save_for(input_for('CS-1',3,'13:00',3)),'Couples capacity over two rejected');
    rejects(fn()=>save_for(input_for('SR-1',3,'13:00',0)),'Zero guests rejected');
    $invalid=input_for('SR-1',3,'13:00'); $invalid['room_id']=$rooms['CS-1']['id'];
    rejects(fn()=>save_for($invalid),'Room belonging to another service rejected');
    $invalid=input_for('SR-1',3,'13:00'); $invalid['health_id']=$healthIds[1];
    rejects(fn()=>save_for($invalid),'Another customer’s declaration rejected during booking');
    $invalid=input_for('SR-1',3,'13:00'); $invalid['terms']='';
    rejects(fn()=>save_for($invalid),'Final terms acceptance required');
    $invalid=input_for('SR-1',3,'13:00'); $invalid['date']='2026-02-30';
    rejects(fn()=>save_for($invalid),'Invalid calendar date rejected');
    $invalid=input_for('SR-1',3,'13:00'); $invalid['date']='2020-01-01';
    rejects(fn()=>save_for($invalid),'Past appointment rejected');
    $invalid=input_for('SR-1',3,'13:00'); $invalid['guests']=['1'];
    rejects(fn()=>save_for($invalid),'Array-shaped guest input rejected');
    save_for(input_for('SR-1',4,'13:00')); save_for(input_for('SR-2',4,'13:00'));
    rejects(fn()=>save_for(input_for('SR-3',4,'13:00')),'Therapists cannot be double-booked across rooms');
    $assignments=$pdo->query('SELECT COUNT(DISTINCT therapist_id) FROM appointment_therapists atp JOIN appointments a ON a.id=atp.appointment_id WHERE a.starts_at="'.utc_time(local_datetime($day->modify('+4 days')->format('Y-m-d'),'13:00')).'"')->fetchColumn();
    expect((int)$assignments===2,'Two simultaneous appointments receive different therapists');
    expect((bool)save_for(input_for('SR-3',4,'14:00')),'Therapist can start after treatment while earlier room is cleaning');
    foreach($therapistIds as $id) shift($id,5,'14:00','14:30','unavailable');
    rejects(fn()=>save_for(input_for('SR-1',5,'13:30')),'Therapist break overlapping part of treatment blocks assignment');
    expect((bool)save_for(input_for('SR-1',5,'14:30')),'Therapist is available at the exact break-end boundary');
    $date=$day->modify('+6 days')->format('Y-m-d');
    $query=$pdo->prepare('INSERT INTO room_blocks (room_id,starts_at,ends_at,reason) VALUES (?,?,?,?)');
    $query->execute([$rooms['SR-1']['id'],utc_time(local_datetime($date,'14:15')),utc_time(local_datetime($date,'15:00')),'Disposable QA block']); $blockIds[]=(int)$pdo->lastInsertId();
    rejects(fn()=>save_for(input_for('SR-1',6,'13:00')),'Cleaning interval cannot run into a room block');
    expect((bool)save_for(input_for('SR-1',6,'15:00')),'Room-block exact end boundary is allowed');
    save_for(input_for('SR-1',7,'16:00'));
    rejects(fn()=>save_for(input_for('SR-1',7,'15:00')),'Earlier booking cleaning cannot overlap a later appointment');
    $slots=availability(input_for('FL-1',8,'13:00',6));
    $byTime=array_column($slots['slots'],null,'time');
    expect($byTime['19:30']['available'] && !$byTime['20:00']['available'],'Availability offers latest 90-minute start at 7:30 PM');
    // Two independent PHP processes, released at a barrier, exercise the DB mutex.
    $outcomes=race(input_for('SR-1',9,'13:00',1,0),input_for('SR-1',9,'13:00',1,1));
    expect(count(array_filter($outcomes,fn($r)=>$r['status']==='confirmed'))===1,'Concurrent same-room requests produce exactly one confirmation');
    $query=$pdo->prepare('UPDATE therapists SET is_active=0 WHERE id=?'); $query->execute([$therapistIds[1]]);
    $outcomes=race(input_for('SR-1',10,'13:00',1,0),input_for('SR-2',10,'13:00',1,1));
    expect(count(array_filter($outcomes,fn($r)=>$r['status']==='confirmed'))===1,'Concurrent different-room requests cannot double-book the last therapist');
    $query=$pdo->prepare('UPDATE therapists SET is_active=0 WHERE id=?'); $query->execute([$therapistIds[0]]);
    rejects(fn()=>save_for(input_for('SR-1',11,'13:00')),'Inactive therapists never satisfy availability');
    $query=$pdo->prepare('UPDATE therapists SET is_active=1 WHERE id=?'); $query->execute([$therapistIds[0]]);
    rejects(fn()=>save_for(input_for('SR-1',13,'13:00')),'Active therapist without a published shift is unavailable');
    expect((int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn()===0,'Booking never fabricates gateway payment records');
    echo "\n$checks booking-engine checks passed.\n";
} finally {
    foreach($userIds as $id) {
        $query=$pdo->prepare('DELETE atp FROM appointment_therapists atp JOIN appointments a ON a.id=atp.appointment_id WHERE a.user_id=?'); $query->execute([$id]);
        foreach(['appointments','health_declarations','users'] as $table) {
            $column=$table==='users'?'id':'user_id'; $query=$pdo->prepare("DELETE FROM $table WHERE $column=?"); $query->execute([$id]);
        }
    }
    foreach($therapistIds as $id) {
        $query=$pdo->prepare('DELETE FROM therapist_schedules WHERE therapist_id=?'); $query->execute([$id]);
        $query=$pdo->prepare('DELETE FROM therapists WHERE id=?'); $query->execute([$id]);
    }
    foreach($blockIds as $id) { $query=$pdo->prepare('DELETE FROM room_blocks WHERE id=?'); $query->execute([$id]); }
}
