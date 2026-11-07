<?php
declare(strict_types=1);
// Real localhost:8088 verification. Uses existing real staff; never inserts therapists or shifts.
if(PHP_SAPI!=='cli')exit;
if(!in_array('--allow-live-qa',$argv,true))exit("Usage: php tests/live-booking.php --allow-live-qa [--keep-browser-fixture | --cleanup]\nCreates a labelled temporary QA customer/booking using existing real staff on localhost:8088.\n");
$config=require __DIR__.'/../config/config.php';
if(!in_array($config['db_host'],['127.0.0.1','localhost'],true))exit("Use a local database only.\n");
require __DIR__.'/../app/database.php';require __DIR__.'/../app/modules/catalog.php';require __DIR__.'/../app/modules/booking.php';
if(demo_enabled()){fwrite(STDERR,"This historical test covers unpaid bookings. Use tests/phase5-http.php http://127.0.0.1:8088 --allow-live-qa for the current simulated-payment workflow.\n");exit(1);}
$pdo=db();$stateFile=__DIR__.'/../var/live-booking-fixture.json';$checks=0;$userId=null;$passed=false;$cookie=tempnam(__DIR__.'/../var','live-availability-cookie-');
function live_check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);++$checks;echo "PASS: $label\n";}
function live_call(string $path,?array $data=null):array {
    global $cookie;if(!str_starts_with($path,'/')||str_starts_with($path,'//'))throw new RuntimeException('Local paths only.');
    $c=curl_init('http://127.0.0.1:8088'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>15]);
    if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
    $raw=curl_exec($c);if($raw===false)throw new RuntimeException(curl_error($c));$n=curl_getinfo($c,CURLINFO_HEADER_SIZE);$r=['status'=>curl_getinfo($c,CURLINFO_HTTP_CODE),'headers'=>substr($raw,0,$n),'body'=>substr($raw,$n)];curl_close($c);return $r;
}
function live_hidden(array $r,string $key):string {if(!preg_match('/name="'.preg_quote($key,'/').'" value="([^"]*)"/',$r['body'],$m))throw new RuntimeException('Missing field '.$key);return html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');}
function live_location(array $r):string {preg_match('/^Location:\s*(.+)$/mi',$r['headers'],$m);return trim($m[1]??'');}
function live_baseline():array { $result=[];foreach(['users','health_declarations','appointments','appointment_therapists','payments','payment_attempts','therapists','therapist_schedules','rooms','services'] as $table)$result[$table]=hash('sha256',serialize(db()->query('SELECT * FROM '.$table.' ORDER BY '.($table==='appointment_therapists'?'appointment_id,therapist_id':'id'))->fetchAll()));return $result; }
function live_cleanup(int $id,string $email):void {
    $q=db()->prepare("SELECT id FROM users WHERE id=? AND email=? AND full_name='Temporary Availability QA'");$q->execute([$id,$email]);
    if(!preg_match('/^availability-live-[a-f0-9]+@example\.test$/D',$email)||!$q->fetch())throw new RuntimeException('Refusing cleanup of an unrecognized account.');
    $q=db()->prepare('SELECT COUNT(*) FROM payments p JOIN appointments a ON a.id=p.appointment_id WHERE a.user_id=?');$q->execute([$id]);
    if((int)$q->fetchColumn())throw new RuntimeException('A financial record exists; manual review is required.');
    foreach(['appointment_changes','refund_requests','cancellation_records','payment_reconciliation_cases','payment_attempts','appointment_therapists'] as $table){$q=db()->prepare("DELETE x FROM $table x JOIN appointments a ON a.id=x.appointment_id WHERE a.user_id=?");$q->execute([$id]);}
    foreach(['appointments','health_declarations','users'] as $table){$column=$table==='users'?'id':'user_id';$q=db()->prepare("DELETE FROM $table WHERE $column=?");$q->execute([$id]);}
    $q=db()->prepare('DELETE FROM login_attempts WHERE email_hash=?');$q->execute([hash('sha256',$email)]);
}
if(in_array('--cleanup',$argv,true)) {
    try{$state=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);live_cleanup($state['user_id'],$state['email']);live_check(live_baseline()===$state['baseline'],'Original customer, booking, payment, room and real-staff records preserved');unlink($stateFile);echo "Temporary live QA fixture removed.\n";}finally{if(is_file($cookie))unlink($cookie);}exit;
}
if(is_file($stateFile))exit("A browser fixture already exists. Clean it up before running again.\n");
$baseline=live_baseline();$email='availability-live-'.bin2hex(random_bytes(6)).'@example.test';$password='Availability-QA-'.bin2hex(random_bytes(10));
try {
    $q=$pdo->query("SELECT id FROM services WHERE slug='family-lounge' AND is_active=1");$serviceId=(int)$q->fetchColumn();
    $criteria=null;$initial=null;
    for($day=1;$day<=14;$day++){$candidate=['service_id'=>$serviceId,'guests'=>6,'date'=>(new DateTimeImmutable('today',spa_timezone()))->modify("+$day days")->format('Y-m-d')];$matrix=service_availability($candidate);if($matrix['status']==='available'){$criteria=$candidate;$initial=$matrix;break;}}
    live_check($criteria!==null,'A future date is genuinely bookable with existing real staff');
    $form=live_call('/auth/register.php');$r=live_call('/auth/register.php',['csrf'=>live_hidden($form,'csrf'),'full_name'=>'Temporary Availability QA','email'=>$email,'password'=>$password,'password_confirmation'=>$password,'terms'=>'1']);
    $q=$pdo->prepare('SELECT id FROM users WHERE email=?');$q->execute([$email]);$userId=(int)$q->fetchColumn();
    live_check($r['status']===303 && $userId>0,'Temporary customer registration persists on the actual installation');
    $form=live_call('/customer/health.php');$health=array_fill_keys(array_keys(HEALTH_QUESTIONS),'no');$health+=['notes'=>'Disposable availability QA only. No real medical information.','consent'=>'1','csrf'=>live_hidden($form,'csrf')];
    live_check(live_call('/customer/health.php',$health)['status']===303,'Health declaration saves through the actual customer handler');
    $api=live_call('/api/availability.php?'.http_build_query($criteria));$matrix=json_decode($api['body'],true,512,JSON_THROW_ON_ERROR);
    live_check($api['status']===200 && $matrix['status']==='available','Actual aggregate API returns available time and room choices');
    $slot=array_values(array_filter($matrix['slots'],fn($slot)=>$slot['available']))[0];$room=array_values(array_filter($slot['rooms'],fn($room)=>$room['available']))[0];
    live_check(in_array($room['code'],['FL-1','FL-2'],true) && $matrix['total']===5500,'Actual room selection and six-person family price come from MySQL');
    $form=live_call('/booking.php?'.http_build_query($criteria));
    $booking=$criteria+['room_id'=>$room['id'],'time'=>$slot['time'],'csrf'=>live_hidden($form,'csrf'),'health_id'=>live_hidden($form,'health_id'),'request_key'=>live_hidden($form,'request_key'),'terms'=>'1','payment_status'=>'paid','total_amount'=>'1'];
    $saved=live_call('/customer/create-booking.php',$booking);$q=$pdo->prepare('SELECT * FROM appointments WHERE user_id=?');$q->execute([$userId]);$appointment=$q->fetch();
    live_check($saved['status']===303 && $appointment!==false,'Complete booking is saved by the real port-8088 server');
    live_check($appointment['status']==='confirmed' && $appointment['payment_status']==='unpaid' && (int)$appointment['total_amount']===5500,'Saved booking stays unpaid and ignores forged price/payment fields');
    $q=$pdo->prepare('SELECT COUNT(*) FROM appointment_therapists a JOIN therapists t ON t.id=a.therapist_id WHERE a.appointment_id=? AND t.is_active=1');$q->execute([$appointment['id']]);
    live_check((int)$q->fetchColumn()===1,'One existing active real therapist is assigned');
    live_check(local_time($appointment['ends_at'])->getTimestamp()-local_time($appointment['starts_at'])->getTimestamp()===90*60 && local_time($appointment['cleaning_ends_at'])->getTimestamp()-local_time($appointment['ends_at'])->getTimestamp()===30*60,'Actual record stores 90-minute treatment and 30-minute room cleaning');
    $details=live_call(live_location($saved));$dashboard=live_call('/customer/dashboard.php');
    live_check($details['status']===200 && str_contains($details['body'],$appointment['booking_reference']),'Confirmation reads the actual saved booking reference');
    live_check(str_contains($dashboard['body'],$appointment['booking_reference']) && str_contains($dashboard['body'],'₱5,500'),'Saved appointment appears in the customer dashboard');
    live_check(live_location(live_call('/customer/create-booking.php',$booking))===live_location($saved),'Repeated actual submission returns the same booking');
    $q=$pdo->prepare('SELECT COUNT(*) FROM appointments WHERE user_id=?');$q->execute([$userId]);live_check((int)$q->fetchColumn()===1,'No duplicate appointment is saved');
    $q=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE appointment_id=?');$q->execute([$appointment['id']]);live_check((int)$q->fetchColumn()===0,'No payment transaction or gateway success is fabricated');
    $form=live_call('/customer/health.php');$health['csrf']=live_hidden($form,'csrf');live_call('/customer/health.php',$health);
    $after=json_decode(live_call('/api/availability.php?'.http_build_query($criteria))['body'],true,512,JSON_THROW_ON_ERROR);$same=array_column($after['slots'],null,'time')[$slot['time']];
    $bookedRoom=array_column($same['rooms'],null,'id')[$room['id']];live_check(!$bookedRoom['available'] && $bookedRoom['reason_code']==='room_occupied','The actual saved appointment immediately blocks its room');
    echo "Actual QA booking reference: ".$appointment['booking_reference']."\n$checks actual-port-8088 booking checks passed.\n";
    $passed=true;
    if(in_array('--keep-browser-fixture',$argv,true))file_put_contents($stateFile,json_encode(['user_id'=>$userId,'email'=>$email,'password'=>$password,'reference'=>$appointment['booking_reference'],'criteria'=>$criteria,'baseline'=>$baseline],JSON_THROW_ON_ERROR));
} finally {
    if($userId && (!$passed||!in_array('--keep-browser-fixture',$argv,true))){live_cleanup($userId,$email);live_check(live_baseline()===$baseline,'Original customer, booking, payment, room and real-staff records preserved');}
    if(is_file($cookie))unlink($cookie);
}
