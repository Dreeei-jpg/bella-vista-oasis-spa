<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$config = require __DIR__ . '/../config/config.php';
if (!str_ends_with($config['db_name'], '_test')) exit("Use a separate database ending in _test.\n");
require __DIR__ . '/../app/database.php';
require __DIR__ . '/../app/modules/catalog.php';
require __DIR__ . '/../app/modules/booking.php';
require __DIR__.'/legacy-mode.php';
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8089/bella-vista-spa/public', '/');
if (!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost','::1'],true)) exit("Use a local server.\n");
$checks=0; $prefix='http-qa-'.bin2hex(random_bytes(6)); $users=[]; $staffId=null;
$date=(new DateTimeImmutable('tomorrow',spa_timezone()))->modify('+4 days')->format('Y-m-d');
function verify(bool $condition,string $message): void {
    global $checks;
    if(!$condition) throw new RuntimeException('FAIL: '.$message);
    ++$checks; echo "PASS: $message\n";
}
function hidden(array $response,string $name): string {
    if(!preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/',$response['body'],$matches)) throw new RuntimeException('Missing field: '.$name);
    return html_entity_decode($matches[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
}
function location(array $response): string {
    preg_match('/^Location: (.+)$/mi',$response['headers'],$matches);
    return trim($matches[1]??'');
}
class HttpClient {
    private string $cookie;
    public function __construct() { $this->cookie=tempnam(__DIR__.'/../var','http-qa-'); }
    public function call(string $path,?array $data=null): array {
        global $base;
        $curl=curl_init($base.$path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$this->cookie,CURLOPT_COOKIEFILE=>$this->cookie,CURLOPT_TIMEOUT=>15]);
        if($data!==null) curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
        $raw=curl_exec($curl); if($raw===false) throw new RuntimeException(curl_error($curl));
        $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);
        $response=['status'=>curl_getinfo($curl,CURLINFO_HTTP_CODE),'body'=>substr($raw,$size),'headers'=>substr($raw,0,$size)];
        curl_close($curl); return $response;
    }
    public function __destruct() { if(is_file($this->cookie)) unlink($this->cookie); }
}
$admin=new HttpClient(); $customer=new HttpClient(); $other=new HttpClient();
$clients=[$admin,$customer,$other];
$password='Disposable-test-'.bin2hex(random_bytes(10));
try {
    verify($customer->call('/api/availability.php')['status']===401,'Anonymous API returns JSON 401');
    verify($customer->call('/booking.php')['status']===303,'Booking requires sign-in');
    foreach($clients as $index=>$client) {
        $form=$client->call('/auth/register.php');
        $response=$client->call('/auth/register.php',['csrf'=>hidden($form,'csrf'),'full_name'=>'Disposable HTTP QA '.$index,'email'=>$prefix.'-'.$index.'@example.test','password'=>$password,'password_confirmation'=>$password,'terms'=>'1']);
        verify($response['status']===303,'Test customer registration '.$index);
        $query=db()->prepare('SELECT id FROM users WHERE email=?'); $query->execute([$prefix.'-'.$index.'@example.test']); $users[]=(int)$query->fetchColumn();
    }
    $query=db()->prepare("UPDATE users SET role='admin' WHERE id=?"); $query->execute([$users[0]]);
    verify($customer->call('/admin/therapists.php')['status']===403,'Customer cannot change staffing');
    verify($admin->call('/booking.php')['status']===403,'Administrator cannot create customer bookings');
    $staffForm=$admin->call('/admin/therapists.php');
    verify($staffForm['status']===200,'Administrator can open staffing setup');
    verify($admin->call('/admin/therapists.php',['action'=>'add_therapist','full_name'=>'Unauthorized CSRF'])['status']===403,'Staffing CSRF enforced');
    $staffResponse=$admin->call('/admin/therapists.php',['csrf'=>hidden($staffForm,'csrf'),'action'=>'add_therapist','full_name'=>$prefix.' test therapist']);
    verify($staffResponse['status']===303,'Administrator can add a therapist');
    $query=db()->prepare('SELECT id FROM therapists WHERE full_name=?'); $query->execute([$prefix.' test therapist']); $staffId=(int)$query->fetchColumn();
    $staffForm=$admin->call('/admin/therapists.php');
    $shift=['csrf'=>hidden($staffForm,'csrf'),'action'=>'add_shift','therapist_id'=>$staffId,'date'=>$date,'starts_at'=>'13:00','ends_at'=>'21:00','schedule_type'=>'available'];
    verify($admin->call('/admin/therapists.php',$shift)['status']===303,'Administrator can publish a real shift');
    verify($admin->call('/admin/therapists.php',$shift)['status']===422,'Duplicate shift handled clearly');
    $toggle=['csrf'=>hidden($staffForm,'csrf'),'action'=>'set_active','therapist_id'=>$staffId,'is_active'=>'0'];
    verify($admin->call('/admin/therapists.php',$toggle)['status']===303,'Administrator can deactivate staff without deleting records');
    verify(str_contains($admin->call('/admin/therapists.php')['body'],'Inactive'),'Staff roster clearly displays inactive status');
    $toggle['is_active']='1';verify($admin->call('/admin/therapists.php',$toggle)['status']===303,'Administrator can reactivate existing staff');
    verify(str_contains(location($customer->call('/booking.php')),'/customer/health.php'),'Treatment selection waits for health declaration');
    verify($customer->call('/api/availability.php')['status']===409,'API also requires a completed declaration');
    $health=$customer->call('/customer/health.php');
    verify($customer->call('/customer/health.php',[])['status']===403,'Health CSRF enforced');
    verify($customer->call('/customer/health.php',['csrf'=>hidden($health,'csrf')])['status']===422,'Incomplete health declaration rejected server-side');
    $healthInput=array_fill_keys(array_keys(HEALTH_QUESTIONS),'no');
    $healthInput+=['notes'=>'Disposable health note <script>alert(1)</script>','consent'=>'1','csrf'=>hidden($health,'csrf')];
    verify($customer->call('/customer/health.php',$healthInput)['status']===303,'Complete health declaration is saved');
    $rooms=db()->query('SELECT code,id,service_id FROM rooms')->fetchAll(PDO::FETCH_UNIQUE);
    $criteria=['service_id'=>$rooms['FL-2']['service_id'],'room_id'=>$rooms['FL-2']['id'],'guests'=>6,'date'=>$date];
    $api=$customer->call('/api/availability.php?'.http_build_query($criteria)); $data=json_decode($api['body'],true,512,JSON_THROW_ON_ERROR);
    verify($api['status']===200 && $data['total']===5500 && $data['duration']===90,'Availability returns authoritative family total and duration');
    verify(!str_contains($api['body'],'Disposable health note'),'API never exposes health answers');
    verify(array_column($data['slots'],null,'time')['13:00']['available'],'Published therapist makes valid slot bookable');
    verify(!array_column($data['slots'],null,'time')['20:00']['available'],'Late family start is disabled');
    $allCriteria=$criteria;unset($allCriteria['room_id']);
    $allResponse=$customer->call('/api/availability.php?'.http_build_query($allCriteria));$all=json_decode($allResponse['body'],true,512,JSON_THROW_ON_ERROR);
    verify($allResponse['status']===200 && array_column($all['rooms'],'code')===['FL-1','FL-2'],'Aggregate API returns service-specific room options');
    verify(count(array_column($all['slots'],null,'time')['13:00']['rooms'])===2 && $all['status']==='available','Each start includes actual room availability');
    $invalidAll=$allCriteria;$invalidAll['guests']=7;$invalidAll=json_decode($customer->call('/api/availability.php?'.http_build_query($invalidAll))['body'],true);
    verify($invalidAll['code']==='invalid_guest_count','Aggregate API identifies invalid guest count separately');
    $invalidAll=$allCriteria;$invalidAll['date']='2020-01-01';$invalidAll=json_decode($customer->call('/api/availability.php?'.http_build_query($invalidAll))['body'],true);
    verify($invalidAll['code']==='past_date','Aggregate API identifies past date separately');
    $invalid=$criteria; $invalid['guests']=7;
    verify($customer->call('/api/availability.php?'.http_build_query($invalid))['status']===422,'API rejects excess capacity');
    $invalid=$criteria; $invalid['room_id']=$rooms['SR-1']['id'];
    verify($customer->call('/api/availability.php?'.http_build_query($invalid))['status']===422,'API rejects mismatched room');
    $form=$customer->call('/booking.php?'.http_build_query($criteria));
    verify($form['status']===200 && str_contains($form['body'],'name="time"'),'Server renders slots for no-JavaScript form use');
    $booking=$criteria+['csrf'=>hidden($form,'csrf'),'health_id'=>hidden($form,'health_id'),'request_key'=>hidden($form,'request_key'),'time'=>'13:00','terms'=>'1','total_amount'=>'1','payment_status'=>'paid'];
    verify($customer->call('/customer/create-booking.php',[])['status']===403,'Booking CSRF enforced');
    $bad=$booking; $bad['terms']='';
    verify(str_contains(location($customer->call('/customer/create-booking.php',$bad)),'booking.php'),'Missing terms returns to booking form');
    $bad=$booking; $bad['time']='13:15';
    $customer->call('/customer/create-booking.php',$bad);
    $bad=$booking+['therapist_id'=>'999999'];
    verify(str_contains(location($customer->call('/customer/create-booking.php',$bad)),'booking.php'),'Unknown specific therapist returns to the booking form');
    $query=db()->prepare('SELECT COUNT(*) FROM appointments WHERE user_id=?'); $query->execute([$users[1]]);
    verify((int)$query->fetchColumn()===0,'Failed submissions create no appointment');
    $confirmed=$customer->call('/customer/create-booking.php',$booking);
    verify($confirmed['status']===303 && str_contains(location($confirmed),'appointment.php?reference='),'Valid booking redirects to saved confirmation');
    parse_str(parse_url(location($confirmed),PHP_URL_QUERY),$params); $reference=$params['reference'];
    $details=$customer->call('/customer/appointment.php?reference='.$reference);
    verify($details['status']===200 && str_contains($details['body'],'Payment: Unpaid'),'Confirmation shows separate unpaid status');
    verify(str_contains($details['body'],'₱5,500') && str_contains($details['body'],'FL-2'),'Confirmation displays saved server price and room');
    verify(str_contains($details['body'],$prefix.' test therapist'),'Confirmation shows assigned therapist');
    verify(str_contains($details['body'],'&lt;script&gt;') && !str_contains($details['body'],'<script>alert(1)</script>'),'Saved medical notes are escaped');
    verify(str_contains($details['body'],'cancel.php?reference='),'Eligible appointment exposes a real cancellation review page');
    $again=$customer->call('/customer/create-booking.php',$booking);
    verify(location($again)===location($confirmed),'Repeated POST returns original booking');
    $query=db()->prepare('SELECT COUNT(*) FROM appointments WHERE user_id=?'); $query->execute([$users[1]]);
    verify((int)$query->fetchColumn()===1,'Repeated submit creates only one record');
    verify($other->call('/customer/appointment.php?reference='.$reference)['status']===404,'Other customers cannot read booking or health details');
    $dashboard=$customer->call('/customer/dashboard.php');
    verify(str_contains($dashboard['body'],$reference) && str_contains($dashboard['body'],'View appointment'),'Dashboard links real upcoming appointment');
    $query=db()->prepare('SELECT status,payment_status,total_amount FROM appointments WHERE booking_reference=?'); $query->execute([$reference]); $stored=$query->fetch();
    verify($stored['status']==='confirmed' && $stored['payment_status']==='unpaid' && (int)$stored['total_amount']===5500,'Browser cannot forge payment, status, or price');
    $unavailable=$shift; $unavailable['schedule_type']='unavailable'; $unavailable['starts_at']='13:30'; $unavailable['ends_at']='14:00';
    verify($admin->call('/admin/therapists.php',$unavailable)['status']===422,'Staffing changes cannot invalidate an existing booking');
    $toggle['is_active']='0';verify($admin->call('/admin/therapists.php',$toggle)['status']===422,'Staff deactivation cannot invalidate an upcoming appointment');
    // A second draft sees the now-reserved room and cannot confirm that same slot.
    $health=$customer->call('/customer/health.php'); $healthInput['csrf']=hidden($health,'csrf'); $customer->call('/customer/health.php',$healthInput);
    $api=$customer->call('/api/availability.php?'.http_build_query($criteria)); $data=json_decode($api['body'],true,512,JSON_THROW_ON_ERROR);
    verify(!array_column($data['slots'],null,'time')['14:30']['available'],'API includes room cleaning after confirmed appointment');
    $form=$customer->call('/booking.php?'.http_build_query($criteria));
    $stale=$booking; $stale['health_id']=hidden($form,'health_id'); $stale['request_key']=hidden($form,'request_key');
    $customer->call('/customer/create-booking.php',$stale);
    $query=db()->prepare('SELECT COUNT(*) FROM appointments WHERE user_id=?'); $query->execute([$users[1]]);
    verify((int)$query->fetchColumn()===1,'Server rechecks a stale or forged occupied slot');
    if(in_array('--test-db-failure',$argv,true)) {
        $testConfigPath=__DIR__.'/../config/local.php';$testConfigSource=file_get_contents($testConfigPath);
        try {
            file_put_contents($testConfigPath,'<?php return '.var_export(array_replace($config,['db_port'=>'1']),true).';');
            $failure=$customer->call('/api/availability.php?'.http_build_query($allCriteria));$failureBody=json_decode($failure['body'],true,512,JSON_THROW_ON_ERROR);
            verify($failure['status']===503 && $failureBody['code']==='server_error','Database failure returns JSON 503, never an empty room list');
            verify(!isset($failureBody['slots']) && str_contains($failureBody['error'],'could not check availability'),'Database failure is clearly differentiated from legitimate unavailability');
        } finally { file_put_contents($testConfigPath,$testConfigSource); }
        verify($customer->call('/api/availability.php?'.http_build_query($allCriteria))['status']===200,'API recovers after the test database connection is restored');
    }
    $query=db()->prepare('UPDATE appointments SET starts_at=starts_at-INTERVAL 30 DAY,ends_at=ends_at-INTERVAL 30 DAY,cleaning_ends_at=cleaning_ends_at-INTERVAL 30 DAY WHERE booking_reference=?'); $query->execute([$reference]);
    $dashboard=$customer->call('/customer/dashboard.php');
    verify(str_contains($dashboard['body'],'You have no upcoming appointments.') && str_contains($dashboard['body'],$reference),'Past appointment remains in history with details link');
    echo "\n$checks booking HTTP checks passed.\n";
} finally {
    foreach($users as $id) {
        $query=db()->prepare('DELETE atp FROM appointment_therapists atp JOIN appointments a ON a.id=atp.appointment_id WHERE a.user_id=?'); $query->execute([$id]);
        foreach(['appointments','health_declarations','users'] as $table) { $column=$table==='users'?'id':'user_id'; $query=db()->prepare("DELETE FROM $table WHERE $column=?"); $query->execute([$id]); }
    }
    if($staffId) {
        $query=db()->prepare('DELETE FROM therapist_schedules WHERE therapist_id=?'); $query->execute([$staffId]);
        $query=db()->prepare('DELETE FROM therapists WHERE id=?'); $query->execute([$staffId]);
    }
}
