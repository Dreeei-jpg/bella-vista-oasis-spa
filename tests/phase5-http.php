<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$config=require __DIR__.'/../config/config.php';
$isTest=str_ends_with($config['db_name'],'_test');
if(!$isTest&&!in_array('--allow-live-qa',$argv,true))exit("Use a separate _test database, or explicitly pass --allow-live-qa for local real-staff verification.\n");
$base=rtrim($argv[1]??'http://127.0.0.1:8088','/');
if(!in_array(parse_url($base,PHP_URL_HOST),['localhost','127.0.0.1'],true)||!in_array($config['db_host'],['localhost','127.0.0.1'],true))exit('Local servers/databases only.');
require __DIR__.'/../app/database.php';require __DIR__.'/../app/modules/catalog.php';require __DIR__.'/../app/modules/booking.php';require __DIR__.'/../app/modules/admin.php';
$p=db();$checks=0;$users=[];$emails=[];$testStaff=[];$passed=false;$stateFile=__DIR__.'/../var/phase5-http-fixtures.json';
function p5h_check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);++$checks;echo "PASS: $label\n";}
function p5h_hidden(array $r,string $name):string {if(!preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/',$r['body'],$m))throw new RuntimeException('Missing field '.$name);return html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');}
function p5h_location(array $r):string {preg_match('/^Location:\s*(.+)$/mi',$r['headers'],$m);return trim($m[1]??'');}
function p5h_snapshot(): array
{
    $orders = array_fill_keys(['users','services','rooms','therapists','therapist_schedules','appointments','health_declarations',
        'payments','payment_attempts','payment_events','demo_payments','business_settings','refund_requests','cancellation_records','appointment_changes'], 'id');
    $orders['therapist_service_exclusions'] = 'therapist_id,service_id';
    $orders['appointment_therapists'] = 'appointment_id,therapist_id';
    $snapshot = [];
    foreach ($orders as $table => $order) {
        $snapshot[$table] = hash('sha256',serialize(db()->query("SELECT * FROM $table ORDER BY $order")->fetchAll()));
    }
    return $snapshot;
}
class Phase5Http {
    public int $userId=0;private string $cookie;
    function __construct(){$this->cookie=tempnam(__DIR__.'/../var','phase5-http-cookie-');}
    function call(string $path,?array $data=null):array {global $base;if(!str_starts_with($path,'/')||str_starts_with($path,'//'))throw new RuntimeException('Local paths only.');$c=curl_init($base.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$this->cookie,CURLOPT_COOKIEFILE=>$this->cookie,CURLOPT_TIMEOUT=>20]);if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);$raw=curl_exec($c);if($raw===false)throw new RuntimeException(curl_error($c));$n=curl_getinfo($c,CURLINFO_HEADER_SIZE);$r=['status'=>curl_getinfo($c,CURLINFO_HTTP_CODE),'headers'=>substr($raw,0,$n),'body'=>substr($raw,$n)];curl_close($c);return $r;}
    function __destruct(){if(is_file($this->cookie))unlink($this->cookie);}
}
function p5h_cleanup(array $ids,array $emails):void {
    global $testStaff;
    foreach($ids as $index=>$id){$q=db()->prepare("SELECT id FROM users WHERE id=? AND email=? AND full_name LIKE 'Phase5 QA %'");$q->execute([$id,$emails[$index]]);if(!$q->fetch()||!preg_match('/^phase5-http-[a-f0-9]+-[0-9]+@example\.test$/D',$emails[$index]))throw new RuntimeException('Refusing unrecognized fixture cleanup.');
        $q=db()->prepare('SELECT COUNT(*) FROM payments pay JOIN appointments a ON a.id=pay.appointment_id WHERE a.user_id=?');$q->execute([$id]);if((int)$q->fetchColumn())throw new RuntimeException('Real payment records require manual review; cleanup stopped.');
    }
    foreach($ids as $id){foreach(['demo_payments','refund_requests','cancellation_records','appointment_changes','payment_reconciliation_cases','payment_attempts','appointment_therapists'] as $table){$q=db()->prepare("DELETE x FROM $table x JOIN appointments a ON a.id=x.appointment_id WHERE a.user_id=?");$q->execute([$id]);}$q=db()->prepare('DELETE FROM appointments WHERE user_id=?');$q->execute([$id]);$q=db()->prepare('DELETE FROM health_declarations WHERE user_id=?');$q->execute([$id]);}
    foreach($ids as $index=>$id){$q=db()->prepare('DELETE FROM users WHERE id=?');$q->execute([$id]);$q=db()->prepare('DELETE FROM login_attempts WHERE email_hash=?');$q->execute([hash('sha256',$emails[$index])]);}
    foreach($testStaff as $id){
        foreach(['therapist_schedules','therapist_service_exclusions'] as $table) {
            $q=db()->prepare("DELETE x FROM $table x JOIN therapists t ON t.id=x.therapist_id WHERE t.id=? AND t.full_name LIKE 'Phase5 QA Fictional Staff%'");
            $q->execute([$id]);
        }
        $q=db()->prepare("DELETE FROM therapists WHERE id=? AND full_name LIKE 'Phase5 QA Fictional Staff%'");$q->execute([$id]);}
}
if(in_array('--cleanup',$argv,true)){$state=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);if($state['database']!==$config['db_name'])exit('Fixture database mismatch.');$testStaff=$state['staff']??[];p5h_cleanup($state['users'],$state['emails']);p5h_check(p5h_snapshot()===$state['baseline'],'Original accounts, appointments, staff, settings and payment records preserved');unlink($stateFile);echo "Phase 5 HTTP/browser fixtures removed.\n";exit;}
if(is_file($stateFile))exit('Clean up the retained Phase 5 browser fixture before rerunning.');
function p5h_health(Phase5Http $client):void {$r=$client->call('/customer/health.php');$answers=array_fill_keys(array_keys(HEALTH_QUESTIONS),'no');$answers+=['notes'=>'FICTIONAL-PHASE5-MEDICAL-QA','consent'=>'1','csrf'=>p5h_hidden($r,'csrf')];p5h_check($client->call('/customer/health.php',$answers)['status']===303,'Health declaration saves through the customer handler');}
function p5h_hold(Phase5Http $client,array $criteria,string $time='13:00',string $choice='full'):array {
    p5h_health($client);$form=$client->call('/booking.php?'.http_build_query($criteria));
    $input=$criteria+['time'=>$time,'csrf'=>p5h_hidden($form,'csrf'),'health_id'=>p5h_hidden($form,'health_id'),'request_key'=>p5h_hidden($form,'request_key'),'terms'=>'1','payment_choice'=>$choice,'total_amount'=>'1','payment_status'=>'paid'];
    $response=$client->call('/customer/create-booking.php',$input);$a=booking_for_request($client->userId,$input['request_key']);
    p5h_check($response['status']===303&&str_contains(p5h_location($response),'/customer/payment.php?reference=')&&$a&&$a['status']==='awaiting_payment','Booking creates a real hold and redirects to demo checkout');
    p5h_check(str_contains(p5h_location($response),'payment_choice='.$choice)&&p5h_location($client->call('/customer/create-booking.php',$input))===p5h_location($response),'Repeated booking submission preserves the same hold and payment choice');return [owned_appointment($client->userId,$a['booking_reference']),$input];
}
function p5h_pay(Phase5Http $client,array $a,string $choice,string $method):array {
    $page=$client->call('/customer/payment.php?reference='.$a['booking_reference'].'&payment_choice='.$choice);
    p5h_check($page['status']===200&&substr_count($page['body'],'Test checkout — No actual funds will be transferred.')===1
        &&!preg_match('/\b(?:prototype|demo|simulat\w*)\b/i',strip_tags($page['body'])),'Checkout uses one payment disclosure and professional labels');
    p5h_check(!preg_match('/name="(?:card_number|cvv|pin|wallet_password)"/',$page['body']),'Checkout collects no real card or wallet credentials');
    $post=['reference'=>$a['booking_reference'],'payment_choice'=>$choice,'method'=>$method,'action'=>'complete','terms'=>'1'];foreach(['csrf','revision','request_key','expected_amount'] as $name)$post[$name]=p5h_hidden($page,$name);
    p5h_check($client->call('/customer/payment.php',array_replace($post,['csrf'=>'invalid']))['status']===403,'Demo payment rejects invalid CSRF');
    $response=$client->call('/customer/payment.php',$post);p5h_check($response['status']===303&&str_contains(p5h_location($response),'appointment.php?reference='),'Demo completion redirects to actual saved confirmation');
    $repeat=$client->call('/customer/payment.php',$post);p5h_check($repeat['status']===303&&p5h_location($repeat)===p5h_location($response),'Repeated HTTP completion is idempotent');
    return owned_appointment($client->userId,$a['booking_reference']);
}
$baseline=p5h_snapshot();$prefix='phase5-http-'.bin2hex(random_bytes(6));$password='Phase5-QA-'.bin2hex(random_bytes(15));$clients=[new Phase5Http(),new Phase5Http(),new Phase5Http()];[$customer,$other,$admin]=$clients;
try {
    p5h_check(demo_enabled(),'The installation has explicitly enabled simulated checkout');
    p5h_check($customer->call('/booking.php')['status']===303,'Anonymous reservation requires customer authentication');
    foreach (['/','/about.php','/services.php','/gallery.php','/terms.php','/auth/login.php','/auth/register.php'] as $route) {
        $public=$customer->call($route);
        p5h_check($public['status']===200&&!preg_match('/\b(?:prototype|demo|simulat\w*|school project|college project)\b/i',strip_tags($public['body'])),'Public copy reviewed at '.$route);
    }
    foreach (['/config/local.php','/database/schema.sql','/var/phase51-before-migration.sql'] as $route) {
        p5h_check(in_array($customer->call($route)['status'],[403,404],true),'Private path is unavailable over HTTP: '.$route);
    }
    foreach($clients as $index=>$client){$email=$prefix.'-'.$index.'@example.test';$form=$client->call('/auth/register.php');$r=$client->call('/auth/register.php',['csrf'=>p5h_hidden($form,'csrf'),'full_name'=>'Phase5 QA '.['Customer','Other Customer','Administrator'][$index],'email'=>$email,'password'=>$password,'password_confirmation'=>$password,'terms'=>'1']);$q=$p->prepare('SELECT id,password_hash FROM users WHERE email=?');$q->execute([$email]);$row=$q->fetch();if($row){$users[]=$client->userId=(int)$row['id'];$emails[]=$email;}p5h_check($r['status']===303&&$row&&password_verify($password,$row['password_hash']),'Registration stores a securely hashed QA account '.$index);}
    $q=$p->prepare("UPDATE users SET role='admin' WHERE id=?");$q->execute([$admin->userId]);
    foreach([$customer,$admin] as $index=>$client){$dashboard=$client->call($index?'/admin/index.php':'/customer/dashboard.php');p5h_check($client->call('/auth/logout.php',['csrf'=>p5h_hidden($dashboard,'csrf')])['status']===303,'Logout destroys QA session '.$index);$login=$client->call('/auth/login.php');if($index===0){$wrong=$client->call('/auth/login.php',['csrf'=>p5h_hidden($login,'csrf'),'email'=>$emails[0],'password'=>'wrong-password']);p5h_check(str_contains($wrong['body'],'email address or password is incorrect'),'Incorrect login password is rejected');}$login=$client->call('/auth/login.php');$signed=$client->call('/auth/login.php',['csrf'=>p5h_hidden($login,'csrf'),'email'=>$emails[$index?2:0],'password'=>$password]);p5h_check($signed['status']===303&&str_contains(p5h_location($signed),$index?'/admin/index.php':'/customer/'),'Customer/admin login redirects by the actual database role');}
    foreach(['/admin/index.php','/admin/appointments.php','/admin/calendar.php','/admin/rooms.php','/admin/payments.php','/admin/reports.php','/admin/settings.php','/admin/therapists.php','/admin/customers.php'] as $route)p5h_check($customer->call($route)['status']===403,'Customer cannot access '.$route);
    p5h_check($customer->call('/api/availability.php')['status']===409,'Health declaration is required before selecting a treatment');
    $rooms=$p->query('SELECT code,id,service_id FROM rooms')->fetchAll(PDO::FETCH_UNIQUE);$date=null;
    for($offset=1;$offset<=30;$offset++){$candidate=(new DateTimeImmutable('today',spa_timezone()))->modify("+$offset days")->format('Y-m-d');$check=availability(['service_id'=>$rooms['SR-1']['service_id'],'room_id'=>$rooms['SR-1']['id'],'guests'=>1,'date'=>$candidate]);if(array_column($check['slots'],null,'time')['13:00']['available']??false){$date=$candidate;break;}}
    p5h_check($date!==null,'Real scheduled staff and SR-1 offer a future opening-time booking');
    $criteria=['service_id'=>$rooms['SR-1']['service_id'],'room_id'=>$rooms['SR-1']['id'],'guests'=>1,'date'=>$date];$dailyBefore=admin_daily($date);$today=(new DateTimeImmutable('today',spa_timezone()))->format('Y-m-d');$revenueBefore=admin_daily($today)['demo_revenue'];$realBefore=(int)$p->query('SELECT COUNT(*) FROM payments')->fetchColumn();$attemptsBefore=(int)$p->query('SELECT COUNT(*) FROM payment_attempts')->fetchColumn();
    p5h_health($customer);
    $matrixCriteria=array_diff_key($criteria,['room_id'=>true]);
    $response=$customer->call('/api/availability.php?'.http_build_query($matrixCriteria));
    $matrix=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);
    $availableStaff=array_column($matrix['slots'],null,'time')['13:00']['therapists'];
    $preferred=end($availableStaff);
    p5h_check($response['status']===200 && $preferred && array_keys($preferred)===['id','full_name'],'Available time exposes only staff identifiers and names for selection');
    [$full,$fullInput]=p5h_hold($customer,$criteria+['therapist_id'=>$preferred['id']]);
    p5h_check((int)$full['preferred_therapist_id']===(int)$preferred['id']&&assigned_therapist_ids((int)$full['id'])==[$preferred['id']],'Specific therapist preference and assignment are persisted on the hold');
    $full=p5h_pay($customer,$full,'full','demo_gcash');
    p5h_check(assigned_therapist_ids((int)$full['id'])==[$preferred['id']],'Confirmed checkout preserves the specifically requested therapist');
    $fullReceipt=customer_demo_payments($customer->userId,(int)$full['id'])[0];
    p5h_check(count(customer_demo_payments($customer->userId,(int)$full['id']))===1,'Repeated full checkout left exactly one stored transaction');
    p5h_check($full['status']==='confirmed'&&$full['payment_status']==='paid'&&demo_summary($full)['paid']===80000&&demo_summary($full)['balance']===0,'Single ₱800 full simulation confirms with zero demo balance');
    $details=$customer->call('/customer/appointment.php?reference='.$full['booking_reference']);p5h_check(str_contains($details['body'],$full['booking_reference'])&&str_contains($details['body'],'Payment: Recorded')&&str_contains($details['body'],htmlspecialchars($preferred['full_name'],ENT_QUOTES))&&str_contains($details['body'],'Copy reference'),'Customer sees saved confirmation and explicit demo payment status');
    $history=$customer->call('/customer/dashboard.php');p5h_check(str_contains($history['body'],$full['booking_reference'])&&str_contains($history['body'],'Test checkout — No actual funds will be transferred.')&&str_contains($history['body'],test_payment_reference($fullReceipt['transaction_reference']))&&!str_contains($history['body'],$fullReceipt['transaction_reference']),'Customer history reads the real appointment and separate demo ledger');
    p5h_check($other->call('/customer/appointment.php?reference='.$full['booking_reference'])['status']===404&&$other->call('/customer/payment.php?reference='.$full['booking_reference'])['status']===404,'Another customer cannot open booking details or checkout');
    foreach(['/admin/appointments.php?search='.$full['booking_reference'],'/admin/calendar.php?date='.$date,'/admin/payments.php?search='.$full['booking_reference']] as $route){$r=$admin->call($route);p5h_check($r['status']===200&&str_contains($r['body'],$full['booking_reference']),'Newly confirmed booking appears in '.$route);}
    $calendar=$admin->call('/admin/calendar.php?date='.$date);
    p5h_check(str_contains($calendar['body'],substr($full['booking_reference'],-6))&&str_contains($calendar['body'],'Occupied')&&str_contains($calendar['body'],'Cleaning')&&str_contains($calendar['body'],htmlspecialchars($preferred['full_name'],ENT_QUOTES)),'Calendar shows compact reference, therapist, and explicit occupancy and cleaning labels');
    $paymentPage=$admin->call('/admin/payments.php?search='.$fullReceipt['transaction_reference']);
    p5h_check($paymentPage['status']===200&&str_contains($paymentPage['body'],test_payment_reference($fullReceipt['transaction_reference']))&&!str_contains(strip_tags($paymentPage['body']),$fullReceipt['transaction_reference'])&&str_contains($paymentPage['body'],'₱800.00')&&str_contains($paymentPage['body'],'Test Mode'),'Administrator can search by the stored reference and sees the updated reference and mode');
    $adminDetails=$admin->call('/admin/appointment.php?reference='.$full['booking_reference']);p5h_check($adminDetails['status']===200&&!str_contains($adminDetails['body'],'FICTIONAL-PHASE5-MEDICAL-QA')&&str_contains($adminDetails['body'],htmlspecialchars($preferred['full_name'],ENT_QUOTES)),'Administrator details omit private medical answers');
    $customerRecord=$admin->call('/admin/customers.php?search='.rawurlencode($emails[0]));
    p5h_check($customerRecord['status']===200&&str_contains($customerRecord['body'],$emails[0])&&!str_contains($customerRecord['body'],$emails[1])&&str_contains($customerRecord['body'],'customer_id='.$customer->userId)&&!str_contains($customerRecord['body'],'FICTIONAL-PHASE5-MEDICAL-QA'),'Customer Records search is scoped, links to bookings, and omits health answers');
    $customerReservations=$admin->call('/admin/appointments.php?customer_id='.$customer->userId);
    p5h_check($customerReservations['status']===200&&str_contains($customerReservations['body'],$full['booking_reference']),'Customer Records links to the correct reservation list');
    $finance=$admin->call('/admin/reports.php?date='.$today);
    p5h_check($finance['status']===200&&str_contains($finance['body'],'Test Amount Recorded')&&str_contains($finance['body'],'Verified Provider Receipts')&&substr_count($finance['body'],'Test checkout — No actual funds will be transferred.')===1,'Financial Reports distinguish test amounts and verified receipts with one disclosure');
    $staff=$p->prepare('SELECT therapist_id FROM appointment_therapists WHERE appointment_id=?');$staff->execute([$full['id']]);$staffId=(int)$staff->fetchColumn();
    $assignment=$admin->call('/admin/appointment.php',['csrf'=>p5h_hidden($adminDetails,'csrf'),'reference'=>$full['booking_reference'],'revision'=>$full['revision'],'request_key'=>bin2hex(random_bytes(32)),'action'=>'assign','therapist_ids'=>[$staffId]]);$full=p5h_reload($full);
    p5h_check($assignment['status']===303&&(int)$full['revision']>0,'Administrator assignment persists after refreshing the same booking');
    $dailyAfter=admin_daily($date);p5h_check($dailyAfter['total']===$dailyBefore['total']+1&&$dailyAfter['confirmed']===$dailyBefore['confirmed']+1,'Daily appointment and confirmed counts include the new booking');
    p5h_check(admin_daily($today)['demo_revenue']===$revenueBefore+80000,'Daily simulated revenue includes the saved ₱800 transaction');
    p5h_health($other);$blocked=$other->call('/api/availability.php?'.http_build_query($criteria));$blocked=json_decode($blocked['body'],true,512,JSON_THROW_ON_ERROR);$blockedSlots=array_column($blocked['slots'],null,'time');p5h_check(!$blockedSlots['13:00']['available']&&!$blockedSlots['14:00']['available'],'Other customers see treatment and cleaning intervals blocked');
    $staleForm=$other->call('/booking.php?'.http_build_query($criteria));$stale=$criteria+['time'=>'13:00','terms'=>'1','csrf'=>p5h_hidden($staleForm,'csrf'),'health_id'=>p5h_hidden($staleForm,'health_id'),'request_key'=>p5h_hidden($staleForm,'request_key')];$other->call('/customer/create-booking.php',$stale);$q=$p->prepare('SELECT COUNT(*) FROM appointments WHERE user_id=?');$q->execute([$other->userId]);p5h_check((int)$q->fetchColumn()===0,'Server refuses another customer’s stale conflicting reservation');
    $downDate=null;for($offset=1;$offset<=30;$offset++){$candidate=(new DateTimeImmutable('today',spa_timezone()))->modify("+$offset days")->format('Y-m-d');if($candidate===$date)continue;$check=availability(['service_id'=>$rooms['SR-2']['service_id'],'room_id'=>$rooms['SR-2']['id'],'guests'=>1,'date'=>$candidate]);if(array_column($check['slots'],null,'time')['13:00']['available']??false){$downDate=$candidate;break;}}
    p5h_check($downDate!==null,'A second real future slot is available for downpayment verification');
    $downCriteria=['service_id'=>$rooms['SR-2']['service_id'],'room_id'=>$rooms['SR-2']['id'],'guests'=>1,'date'=>$downDate];[$down]=p5h_hold($customer,$downCriteria,'13:00','downpayment');$down=p5h_pay($customer,$down,'downpayment','demo_maya');$expected=percentage_centavos(80000,(string)demo_rules()['demo_downpayment_percentage']);
    p5h_check($down['preferred_therapist_id']===null&&count(assigned_therapist_ids((int)$down['id']))===1&&$down['therapist_names'],'Any Available Therapist confirms with one eligible persisted assignment');
    $downReceipts=customer_demo_payments($customer->userId,(int)$down['id']);
    p5h_check(count($downReceipts)===1&&(int)$downReceipts[0]['amount_centavos']===$expected&&(int)$downReceipts[0]['remaining_centavos']===80000-$expected,'Downpayment retry leaves one receipt with exact recorded and remaining amounts');
    $visibleDownReference=test_payment_reference($downReceipts[0]['transaction_reference']);
    $downHistory=$admin->call('/admin/payments.php?search='.$visibleDownReference.'&mode=test');
    p5h_check($downHistory['status']===200&&str_contains($downHistory['body'],$visibleDownReference)&&str_contains($downHistory['body'],'Downpayment'),'Administrator history displays the saved downpayment after refresh');
    p5h_check(demo_summary($down)['paid']===$expected&&demo_summary($down)['balance']===80000-$expected&&$down['payment_status']==='partially_paid','Demo downpayment and remaining balance persist correctly');
    $history=$customer->call('/customer/dashboard.php');p5h_check(str_contains($history['body'],$full['booking_reference'])&&str_contains($history['body'],$down['booking_reference']),'Both confirmed appointments persist in customer history after refresh');
    foreach(['/admin/index.php','/admin/rooms.php','/admin/reports.php?date='.$date,'/admin/settings.php','/admin/therapists.php'] as $route)p5h_check($admin->call($route)['status']===200,'Administrator can use '.$route);
    if($isTest) {
        $settings=$admin->call('/admin/settings.php');$originalRules=demo_rules();$settingsPost=['csrf'=>p5h_hidden($settings,'csrf'),'percentage'=>'33.33','hold_minutes'=>'15','enabled'=>'1'];
        p5h_check($admin->call('/admin/settings.php',array_replace($settingsPost,['percentage'=>'0']))['status']===422,'Administrator settings reject an invalid demo percentage');
        p5h_check($admin->call('/admin/settings.php',$settingsPost)['status']===303&&str_contains($admin->call('/admin/settings.php')['body'],'33.33'),'Editable prototype percentage persists after refresh');
        $settingsPost['percentage']=$originalRules['demo_downpayment_percentage'];$settingsPost['hold_minutes']=$originalRules['demo_hold_minutes'];$settingsPost['enabled']=$originalRules['demo_payments_enabled'];$admin->call('/admin/settings.php',$settingsPost);
        $staffForm=$admin->call('/admin/therapists.php');$oldName='Phase5 QA Fictional Staff '.$prefix;$admin->call('/admin/therapists.php',['csrf'=>p5h_hidden($staffForm,'csrf'),'action'=>'add_therapist','full_name'=>$oldName]);$q=$p->prepare('SELECT id FROM therapists WHERE full_name=?');$q->execute([$oldName]);$editId=(int)$q->fetchColumn();$testStaff[]=$editId;$p->prepare('UPDATE therapists SET is_demo=1 WHERE id=?')->execute([$editId]);$edit=['csrf'=>p5h_hidden($staffForm,'csrf'),'action'=>'edit_therapist','therapist_id'=>$editId,'full_name'=>$oldName.' Edited'];
        p5h_check($admin->call('/admin/therapists.php',$edit)['status']===303&&str_contains($admin->call('/admin/therapists.php')['body'],$oldName.' Edited'),'Administrator therapist edits persist without replacing staff');$edit['full_name']=$oldName;$admin->call('/admin/therapists.php',$edit);
        $staffDate=local_datetime($downDate,'00:00')->modify('+35 days')->format('Y-m-d');
        $shift=['csrf'=>p5h_hidden($staffForm,'csrf'),'action'=>'add_shift','therapist_id'=>$editId,'date'=>$staffDate,'schedule_type'=>'available'];
        foreach ([['08:00','12:00'],['13:00','12:00'],['12:30','21:00'],['20:00','22:00'],['20:00','01:00'],['13:15','17:00'],['16:00','16:00']] as [$from,$until]) {
            p5h_check($admin->call('/admin/therapists.php',$shift+['starts_at'=>$from,'ends_at'=>$until])['status']===422,"Shift rejects {$from}–{$until}");
        }
        p5h_check($admin->call('/admin/therapists.php',$shift+['starts_at'=>'13:00','ends_at'=>'21:00'])['status']===303,'Full 1–9 PM staff shift saves');
        p5h_check($admin->call('/admin/therapists.php',$shift+['starts_at'=>'15:30','ends_at'=>'20:30'])['status']===422,'Overlapping working shift is rejected');
        $q=$p->prepare('SELECT id FROM therapist_schedules WHERE therapist_id=?');$q->execute([$editId]);$shiftId=(int)$q->fetchColumn();
        $editedShift=array_replace($shift,['action'=>'edit_shift','shift_id'=>$shiftId,'starts_at'=>'13:00','ends_at'=>'17:00']);
        p5h_check($admin->call('/admin/therapists.php',$editedShift)['status']===303,'Shorter afternoon shift saves through the edit form');
        $window=['start'=>local_datetime($staffDate,'16:30'),'end'=>local_datetime($staffDate,'17:30')];
        p5h_check(!in_array($editId,array_column(available_therapists($window,null,(int)$criteria['service_id']),'id')),'Treatment crossing shift end excludes that therapist');
        $window=['start'=>local_datetime($staffDate,'16:00'),'end'=>local_datetime($staffDate,'17:00')];
        p5h_check(in_array($editId,array_column(available_therapists($window,null,(int)$criteria['service_id']),'id')),'Treatment may end exactly at shift end while the room is cleaned afterward');
        $qual=['csrf'=>p5h_hidden($staffForm,'csrf'),'action'=>'save_services','therapist_id'=>$editId,'service_ids'=>[$rooms['CS-1']['service_id']]];
        p5h_check($admin->call('/admin/therapists.php',$qual)['status']===303&&!in_array($editId,array_column(available_therapists($window,null,(int)$criteria['service_id']),'id'))&&in_array($editId,array_column(available_therapists($window,null,(int)$rooms['CS-1']['service_id']),'id')),'Stored qualifications restrict availability by service');
        $move=$customer->call('/customer/reschedule.php?reference='.$full['booking_reference'].'&date='.$date.'&time=15:00');$movePost=['reference'=>$full['booking_reference'],'date'=>$date,'time'=>'15:00'];foreach(['csrf','revision','request_key'] as $field)$movePost[$field]=p5h_hidden($move,$field);
        p5h_check($customer->call('/customer/reschedule.php',$movePost)['status']===303&&local_time(p5h_reload($full)['starts_at'])->format('H:i')==='15:00','Customer rescheduling form saves a valid replacement after rechecking');$full=p5h_reload($full);
        p5h_check(str_contains($customer->call('/customer/dashboard.php')['body'],'Booking change history'),'Rescheduled appointments appear in customer change history');
        p5h_check($other->call('/customer/reschedule.php?reference='.$full['booking_reference'])['status']===404,'Other customers cannot reschedule this booking');
        $cancel=$customer->call('/customer/cancel.php?reference='.$down['booking_reference']);$cancelPost=['reference'=>$down['booking_reference'],'reason'=>'Prototype HTTP cancellation review'];foreach(['csrf','revision','request_key'] as $field)$cancelPost[$field]=p5h_hidden($cancel,$field);
        p5h_check($customer->call('/customer/cancel.php',$cancelPost)['status']===303&&p5h_reload($down)['status']==='cancelled','Customer cancellation form saves an eligible demo cancellation');$down=p5h_reload($down);
        $q=$p->prepare('SELECT is_demo,status FROM refund_requests WHERE appointment_id=?');$q->execute([$down['id']]);$refund=$q->fetch();p5h_check($refund&&$refund['is_demo']==1&&$refund['status']==='pending_policy_review','Demo cancellation creates an explicitly simulated policy-review request');
        $reviewPage=$admin->call('/admin/appointment.php?reference='.$down['booking_reference']);$reviewPost=['csrf'=>p5h_hidden($reviewPage,'csrf'),'reference'=>$down['booking_reference'],'revision'=>$down['revision'],'request_key'=>bin2hex(random_bytes(32)),'action'=>'refund_review','review_status'=>'resolved','note'=>'Demo review complete. No automatic refund or entitlement.'];
        p5h_check($admin->call('/admin/appointment.php',$reviewPost)['status']===303&&str_contains($admin->call('/admin/appointment.php?reference='.$down['booking_reference'])['body'],'Demo review complete.'),'Administrator refund-policy review persists through HTTP');
        $adminPage=$admin->call('/admin/appointment.php?reference='.$full['booking_reference']);$adminCancel=['csrf'=>p5h_hidden($adminPage,'csrf'),'reference'=>$full['booking_reference'],'revision'=>$full['revision'],'request_key'=>bin2hex(random_bytes(32)),'action'=>'cancel','reason'=>'Administrator prototype cancellation'];
        p5h_check($admin->call('/admin/appointment.php',$adminCancel)['status']===303&&p5h_reload($full)['status']==='cancelled','Administrator cancellation saves to the same appointment database');$full=p5h_reload($full);
        [$pending]=p5h_hold($other,$criteria);$pendingPage=$admin->call('/admin/appointment.php?reference='.$pending['booking_reference']);$bypass=['csrf'=>p5h_hidden($pendingPage,'csrf'),'reference'=>$pending['booking_reference'],'revision'=>$pending['revision'],'request_key'=>bin2hex(random_bytes(32)),'action'=>'status','status'=>'confirmed'];
        p5h_check($admin->call('/admin/appointment.php',$bypass)['status']===422,'Administrator cannot bypass demo checkout to confirm a payment hold');
        $pendingPage=$other->call('/customer/payment.php?reference='.$pending['booking_reference']);$abandon=['reference'=>$pending['booking_reference'],'action'=>'cancel','csrf'=>p5h_hidden($pendingPage,'csrf'),'revision'=>$pending['revision'],'request_key'=>bin2hex(random_bytes(32))];
        p5h_check($other->call('/customer/payment.php',$abandon)['status']===303&&p5h_reload($pending)['status']==='expired','Abandoning the HTTP demo checkout releases the pending reservation');
    }
    p5h_check((int)$p->query('SELECT COUNT(*) FROM payments')->fetchColumn()===$realBefore&&(int)$p->query('SELECT COUNT(*) FROM payment_attempts')->fetchColumn()===$attemptsBefore,'Complete demo HTTP flow never creates a real gateway attempt or transaction');
    $passed=true;echo "\n$checks Phase 5 HTTP/customer-to-admin checks passed.\nFull demo booking: ".$full['booking_reference']."\nDownpayment demo booking: ".$down['booking_reference']."\n";
    if(in_array('--keep-browser-fixtures',$argv,true))file_put_contents($stateFile,json_encode(['database'=>$config['db_name'],'base'=>$base,'users'=>$users,'emails'=>$emails,'staff'=>$testStaff,'password'=>$password,'baseline'=>$baseline,'full_reference'=>$full['booking_reference'],'down_reference'=>$down['booking_reference'],'date'=>$date,'down_date'=>$downDate],JSON_THROW_ON_ERROR));
}finally{if(!$passed||!in_array('--keep-browser-fixtures',$argv,true)){p5h_cleanup($users,$emails);p5h_check(p5h_snapshot()===$baseline,'Existing customer/admin accounts, appointments, staff, settings and real payment records preserved');}}
function p5h_reload(array $a):array {return owned_appointment((int)$a['user_id'],$a['booking_reference']);}
