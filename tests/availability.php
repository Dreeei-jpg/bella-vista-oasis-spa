<?php
declare(strict_types=1);
require __DIR__.'/phase3-fixtures.php';
$pdo=db();$checks=0;$blocks=[];$fixture=null;
if((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() || (int)$pdo->query('SELECT COUNT(*) FROM therapists')->fetchColumn() || (int)$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn())exit("Use a fresh, empty development-only test database.\n");
function availability_check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);++$checks;echo "PASS: $label\n";}
function matrix(string $room,int $day=0,int $guests=1):array {global $fixture;return service_availability(['service_id'=>$fixture->rooms[$room]['service_id'],'guests'=>$guests,'date'=>$fixture->date($day)]);}
function at_time(array $matrix,string $time):array {return array_column($matrix['slots'],null,'time')[$time];}
function room_at(array $matrix,string $time,string $code):array {return array_column(at_time($matrix,$time)['rooms'],null,'code')[$code];}
try {
    $roomSeed=preg_replace('/^USE bella_vista_spa;\s*$/m','',file_get_contents(__DIR__.'/../database/seeds/required_rooms.sql'));
    $originalRoomIds=$pdo->query('SELECT code,id FROM rooms ORDER BY code')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pdo->exec($roomSeed);$pdo->exec($roomSeed);
    availability_check($pdo->query('SELECT code,id FROM rooms ORDER BY code')->fetchAll(PDO::FETCH_KEY_PAIR)===$originalRoomIds,'Repeated room seed preserves existing room IDs and avoids duplicates');
    $pdo->exec("DELETE FROM rooms WHERE code='SR-3'");$pdo->exec($roomSeed);
    availability_check((int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE code='SR-3' AND capacity=1 AND is_active=1")->fetchColumn()===1,'Room seed safely restores a missing required room');
    $fixture=new Phase3Fixtures();
    $fresh=matrix('SR-1');
    availability_check($fresh['status']==='available' && count(at_time($fresh,'13:00')['rooms'])===3,'Future date exposes all three active single rooms');
    availability_check(array_column(matrix('CS-1',0,2)['rooms'],'code')===['CS-1','CS-2'],'Couples requests expose only couples rooms');
    availability_check(array_column(matrix('FL-1',0,6)['rooms'],'code')===['FL-1','FL-2'],'Family requests expose only family rooms');
    availability_check(count($fresh['slots'])===16 && !array_filter(array_column($fresh['slots'],'time'),fn($time)=>!in_array(substr($time,3),['00','30'],true)),'Aggregate timeline retains the half-hour grid');
    $first=$fixture->book('SR-1',0);
    availability_check($first['status']==='confirmed' && $first['payment_status']==='unpaid','First opening-time booking is saved and stays unpaid');
    $busy=matrix('SR-1');
    availability_check(!room_at($busy,'13:30','SR-1')['available'] && room_at($busy,'13:30','SR-2')['available'],'Occupied room is excluded while another genuinely free room remains selectable');
    availability_check(room_at($busy,'14:00','SR-1')['reason_code']==='room_occupied','Existing cleaning interval blocks the earlier room');
    availability_check(room_at($busy,'14:00','SR-2')['available'],'Therapist can treat in another room during previous-room cleaning');
    availability_check(room_at($busy,'14:30','SR-1')['available'],'Room reopens at the exact end of cleaning');
    $second=$fixture->book('SR-1',0,'14:30');
    availability_check((int)$second['id']!==(int)$first['id'],'Second appointment after cleaning is saved');
    $fixture->book('SR-1',1,'14:30');$later=matrix('SR-1',1);
    availability_check(!room_at($later,'13:30','SR-1')['available'],'Proposed cleaning interval cannot collide with a later booking');
    availability_check(room_at($later,'13:00','SR-1')['available'],'Proposed cleaning ending exactly at a later start is allowed');
    $pdo->exec('UPDATE therapists SET is_active=0');
    $none=matrix('SR-1',2);availability_check($none['status']==='no_therapists' && !at_time($none,'13:00')['available'],'Missing active therapists is reported separately from room occupancy');
    $pdo->exec('UPDATE therapists SET is_active=1');
    foreach($pdo->query("SELECT id FROM rooms WHERE code LIKE 'SR-%'")->fetchAll(PDO::FETCH_COLUMN) as $room){$q=$pdo->prepare('INSERT INTO room_blocks (room_id,starts_at,ends_at,reason) VALUES (?,?,?,?)');$q->execute([$room,utc_time(local_datetime($fixture->date(3),'13:00')),utc_time(local_datetime($fixture->date(3),'21:30')),'Development-only availability test']);$blocks[]=(int)$pdo->lastInsertId();}
    availability_check(matrix('SR-1',3)['status']==='room_occupied','Fully blocked rooms produce the room-occupied outcome');
    $pdo->exec("UPDATE rooms SET is_active=0 WHERE code LIKE 'SR-%'");
    availability_check(matrix('SR-1',3)['status']==='no_rooms','No active eligible rooms is distinct from staffing or occupied rooms');
    $pdo->exec("UPDATE rooms SET is_active=1 WHERE code LIKE 'SR-%'");
    foreach([4=>4000,5=>4750,6=>5500] as $guests=>$price)availability_check(matrix('FL-1',4,$guests)['total']===$price,"Family total for $guests guests is $price");
    try{matrix('FL-1',4,7);throw new RuntimeException('Capacity was accepted');}catch(BookingValidationException $e){availability_check($e->reasonCode==='invalid_guest_count','Invalid guest count has its own validation code');}
    try{service_availability(['service_id'=>$fixture->rooms['SR-1']['service_id'],'guests'=>1,'date'=>'2020-01-01']);throw new RuntimeException('Past date was accepted');}catch(BookingValidationException $e){availability_check($e->reasonCode==='past_date','Past dates are rejected explicitly');}
    $closing=matrix('FL-1',5,6);
    availability_check(at_time($closing,'19:30')['available'] && at_time($closing,'20:00')['reason_code']==='outside_hours','Last family start is 7:30 PM and later treatment exceeds closing');
    $final=$fixture->book('FL-1',5,'19:30',0,6);
    availability_check(local_time($final['ends_at'])->format('H:i')==='21:00' && local_time($final['cleaning_ends_at'])->format('H:i')==='21:30','Family treatment may finish at 9 PM with cleaning until 9:30');
    $cancel=$fixture->book('SR-1',6);$q=$pdo->prepare("UPDATE appointments SET status='cancelled' WHERE id=?");$q->execute([$cancel['id']]);
    availability_check(room_at(matrix('SR-1',6),'13:00','SR-1')['available'],'Cancelled appointments do not block rooms or therapists');
    $expired=$fixture->book('SR-1',7);$q=$pdo->prepare("UPDATE appointments SET status='awaiting_payment',hold_expires_at=UTC_TIMESTAMP()-INTERVAL 1 MINUTE WHERE id=?");$q->execute([$expired['id']]);
    availability_check(room_at(matrix('SR-1',7),'13:00','SR-1')['available'],'Expired payment holds do not block availability');
    availability_check($pdo->query('SELECT @@session.time_zone')->fetchColumn()==='+00:00' && $fresh['timezone']==='Asia/Manila','Database UTC storage and Philippine display timezone are explicit');
    availability_check((int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn()===0,'No gateway payment success or payment rows are fabricated');
    echo "\n$checks aggregate-availability checks passed.\n";
} finally {
    $pdo->exec("UPDATE rooms SET is_active=1 WHERE code LIKE 'SR-%'");
    foreach($blocks as $id){$q=$pdo->prepare('DELETE FROM room_blocks WHERE id=?');$q->execute([$id]);}
    if($fixture)$fixture->cleanup();
}
