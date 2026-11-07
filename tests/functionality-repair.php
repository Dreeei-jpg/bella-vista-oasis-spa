<?php
declare(strict_types=1);
// Explicit local QA only. Creates unique disposable records and removes only its own IDs.
if(PHP_SAPI!=='cli')exit;
if(!in_array('--allow-local-qa',$argv,true))exit("Usage: php tests/functionality-repair.php --allow-local-qa\nCreates and cleans temporary QA accounts/staff/bookings on the actual localhost:8088 installation.\n");
$config=require __DIR__.'/../config/config.php';
if(!str_ends_with($config['db_name'],'_test')) { fwrite(STDERR,"This historical suite creates fictional staff and now requires a separate development database ending in _test. For the actual localhost:8088 installation, use tests/live-booking.php --allow-live-qa with existing real staff.\n");exit(1); }
require __DIR__.'/../app/database.php';
require __DIR__.'/../app/modules/catalog.php';require __DIR__.'/../app/modules/booking.php';
require __DIR__.'/legacy-mode.php';
$prefix='repair-qa-'.bin2hex(random_bytes(6));$email=$prefix.'@example.test';$password='Repair-test-'.bin2hex(random_bytes(12));
$userId=null;$staffId=null;$checks=0;$cookies=[];
$baselineUsers=db()->query('SELECT * FROM users ORDER BY id')->fetchAll();
$baselineHealth=db()->query('SELECT * FROM health_declarations ORDER BY id')->fetchAll();
function repair_check(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$label);++$checks;echo "PASS: $label\n";}
function hidden_value(array $response,string $key):string{if(!preg_match('/name="'.preg_quote($key,'/').'" value="([^"]*)"/',$response['body'],$m))throw new RuntimeException('Missing field '.$key);return html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');}
function redirect_path(array $response):string{preg_match('/^Location:\s*(.+)$/mi',$response['headers'],$m);return trim($m[1]??'');}
function web_call(string $path,?array $data=null,int $client=0):array{
    global $cookies;if(!isset($cookies[$client]))$cookies[$client]=tempnam(__DIR__.'/../var','repair-cookie-');
    if(!str_starts_with($path,'/')||str_starts_with($path,'//'))throw new RuntimeException('Local paths only');
    $c=curl_init('http://127.0.0.1:8088'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$cookies[$client],CURLOPT_COOKIEFILE=>$cookies[$client],CURLOPT_TIMEOUT=>15]);
    if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
    $raw=curl_exec($c);if($raw===false)throw new RuntimeException(curl_error($c));$size=curl_getinfo($c,CURLINFO_HEADER_SIZE);$r=['status'=>curl_getinfo($c,CURLINFO_HTTP_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];curl_close($c);return $r;
}
function final_get(string $path,int $client=0):array{
    for($i=0;$i<8;$i++){$r=web_call($path,null,$client);if(!in_array($r['status'],[301,302,303,307,308],true))return $r;$path=redirect_path($r);}throw new RuntimeException('Redirect loop');
}
try{
    $links=[];
    foreach(['/','/auth/login.php','/auth/register.php','/services.php','/about.php','/gallery.php','/terms.php'] as $path){
        $r=web_call($path,null,1);repair_check($r['status']===200,'Actual 8088 page works: '.$path);
        preg_match_all('/(?:href|src)="([^"#]+)"/',$r['body'],$matches);
        foreach($matches[1] as $href){$href=html_entity_decode($href,ENT_QUOTES|ENT_HTML5,'UTF-8');if(str_starts_with($href,'/'))$links[explode('#',$href)[0]]=true;}
    }
    foreach(array_keys($links) as $link){$r=final_get($link,1);repair_check($r['status']===200,'Visible link/asset resolves: '.$link);}
    foreach(['/config/local.php','/app/database.php','/database/schema.sql','/tools/check-installation.php'] as $private)repair_check(web_call($private)['status']===404,'Private file is not served: '.$private);
    repair_check(redirect_path(web_call('/public/auth/login.php'))==='/auth/login.php','Previously shared /public/ URL redirects correctly');
    repair_check(redirect_path(web_call('/public//example.invalid'))==='/example.invalid','Compatibility redirect cannot leave localhost');
    repair_check(web_call('/customer/dashboard.php')['status']===303,'Anonymous dashboard is protected');
    $catalog=services();$bySlug=array_column($catalog,null,'slug');$family=$bySlug['family-lounge'];$couples=$bySlug['couples-suite'];
    repair_check((int)$bySlug['single-room-massage']['base_price']===800 && (int)$couples['base_price']===1800 && (int)$family['base_price']===4000,'Database supplies mandatory service prices');
    repair_check((int)$family['duration_minutes']===90 && (int)$family['max_capacity']===6 && (int)$couples['duration_minutes']===60,'Database supplies correct duration/capacity');
    $servicesPage=web_call('/services.php');
    foreach($catalog as $service)repair_check(str_contains($servicesPage['body'],'/booking.php?service_id='.$service['id']),'Treatment button preserves '.$service['slug']);
    $entry=web_call('/booking.php?service_id='.$family['id']);repair_check(redirect_path($entry)==='/auth/login.php','Treatment booking requires sign-in');
    $form=web_call('/auth/register.php');
    repair_check(str_contains($form['body'],'minlength="12"') && str_contains($form['body'],'data-validate'),'Registration has client-side validation');
    $bad=web_call('/auth/register.php',['csrf'=>hidden_value($form,'csrf'),'full_name'=>'A','email'=>'bad','password'=>'short','password_confirmation'=>'no']);
    repair_check(str_contains($bad['body'],'valid email') && str_contains($bad['body'],'accept the terms'),'Registration rejects invalid data on the server');
    $data=['csrf'=>hidden_value($form,'csrf'),'full_name'=>'Temporary Repair QA','email'=>$email,'phone'=>'+63 912 345 6789','password'=>$password,'password_confirmation'=>$password,'terms'=>'1'];
    $created=web_call('/auth/register.php',$data);
    $q=db()->prepare('SELECT * FROM users WHERE email=?');$q->execute([$email]);$user=$q->fetch();$userId=$user?(int)$user['id']:null;
    repair_check($created['status']===303 && $userId!==null,'Registration persists in the actual MySQL database');
    repair_check(password_verify($password,$user['password_hash']) && $user['password_hash']!==$password,'Registration stores a secure password hash');
    repair_check(str_contains(strtolower($created['headers']),'httponly') && str_contains(strtolower($created['headers']),'samesite=lax'),'Authentication uses protected session cookies');
    repair_check(redirect_path($created)==='/booking.php','Registration resumes the requested booking');
    $health=final_get('/booking.php');
    repair_check(str_contains($health['body'],'name="pregnancy"') && !str_contains($health['body'],'id="availability-form"'),'Health declaration is required before treatment controls');
    repair_check(str_contains($health['body'],'Your account is ready.'),'Registration displays a real success message');
    repair_check(web_call('/api/availability.php')['status']===409,'Availability API also enforces the health gate');
    repair_check(web_call('/customer/health.php',['csrf'=>hidden_value($health,'csrf')])['status']===422,'Incomplete health answers are rejected on the server');
    repair_check(web_call('/customer/health.php',['csrf'=>'invalid'])['status']===403,'Invalid form CSRF tokens are rejected');
    $answers=array_fill_keys(array_keys(HEALTH_QUESTIONS),'no');$answers+=['notes'=>'Temporary local functionality test only','consent'=>'1','csrf'=>hidden_value($health,'csrf')];
    repair_check(web_call('/customer/health.php',$answers)['status']===303,'Health declaration saves before selection');
    $booking=web_call('/booking.php');
    repair_check((bool)preg_match('/name="service_id" value="'.$family['id'].'" required checked/',$booking['body']),'Family treatment survives registration and health navigation');
    repair_check(str_contains($booking['body'],'Confirm an available time to save your appointment') && !str_contains($booking['body'],'Your appointment is confirmed without online payment.'),'Booking form does not claim confirmation before saving');
    $rooms=db()->query('SELECT code,id,service_id FROM rooms')->fetchAll(PDO::FETCH_UNIQUE);
    $date=(new DateTimeImmutable('tomorrow',spa_timezone()))->modify('+100 days')->format('Y-m-d');
    $criteria=['service_id'=>$family['id'],'room_id'=>$rooms['FL-1']['id'],'guests'=>6,'date'=>$date];
    $empty=json_decode(web_call('/api/availability.php?'.http_build_query($criteria))['body'],true,512,JSON_THROW_ON_ERROR);
    if((int)db()->query('SELECT COUNT(*) FROM therapists')->fetchColumn()===0)repair_check(!array_filter($empty['slots'],fn($s)=>$s['available']),'No staff means no fabricated bookable times');
    $q=db()->prepare('INSERT INTO therapists (full_name) VALUES (?)');$q->execute([$prefix.' temporary staff']);$staffId=(int)db()->lastInsertId();
    $q=db()->prepare("INSERT INTO therapist_schedules (therapist_id,starts_at,ends_at,schedule_type) VALUES (?,?,?,'available')");$q->execute([$staffId,utc_time(local_datetime($date,'13:00')),utc_time(local_datetime($date,'21:00'))]);
    $available=json_decode(web_call('/api/availability.php?'.http_build_query($criteria))['body'],true,512,JSON_THROW_ON_ERROR);$slots=array_column($available['slots'],null,'time');
    repair_check($slots['13:00']['available'] && !$slots['20:00']['available'],'Real staff and operating hours govern availability');
    repair_check(count($slots)===16 && !array_filter(array_keys($slots),fn($t)=>!in_array(substr($t,3),['00','30'],true)),'Start times use the 30-minute grid');
    $invalid=$criteria;$invalid['guests']=7;repair_check(web_call('/api/availability.php?'.http_build_query($invalid))['status']===422,'Server rejects excess room capacity');
    $invalid=$criteria;$invalid['room_id']=$rooms['SR-1']['id'];repair_check(web_call('/api/availability.php?'.http_build_query($invalid))['status']===422,'Server rejects a room belonging to another treatment');
    $form=web_call('/booking.php?'.http_build_query($criteria));
    $post=$criteria+['csrf'=>hidden_value($form,'csrf'),'health_id'=>hidden_value($form,'health_id'),'request_key'=>hidden_value($form,'request_key'),'terms'=>'1','time'=>'13:00','total_amount'=>'1','payment_status'=>'paid'];
    $invalid=$post;$invalid['time']='13:15';web_call('/customer/create-booking.php',$invalid);
    $q=db()->prepare('SELECT COUNT(*) FROM appointments WHERE user_id=?');$q->execute([$userId]);repair_check((int)$q->fetchColumn()===0,'Server rejects a forged non-grid booking');
    $confirmed=web_call('/customer/create-booking.php',$post);$details=final_get(redirect_path($confirmed));
    $q=db()->prepare('SELECT * FROM appointments WHERE user_id=?');$q->execute([$userId]);$a=$q->fetch();
    repair_check($confirmed['status']===303 && $a!==false,'Validated booking persists and opens saved details');
    $repeated=web_call('/customer/create-booking.php',$post);$q=db()->prepare('SELECT COUNT(*) FROM appointments WHERE user_id=?');$q->execute([$userId]);
    repair_check(redirect_path($repeated)===redirect_path($confirmed) && (int)$q->fetchColumn()===1,'Repeated submission retrieves the same booking without duplicates');
    repair_check((int)$a['total_amount']===5500 && $a['payment_status']==='unpaid','Server computes ₱5,500 and ignores forged payment success');
    repair_check(local_time($a['ends_at'])->format('H:i')==='14:30' && local_time($a['cleaning_ends_at'])->format('H:i')==='15:00','90-minute treatment plus mandatory 30-minute cleaning is stored');
    repair_check($details['status']===200 && str_contains($details['body'],$a['booking_reference']),'Booking confirmation reads the saved record');
    $after=json_decode(web_call('/api/availability.php?'.http_build_query($criteria))['body'],true);
    repair_check(isset($after['error']),'A new declaration is required for another booking');
    $health=web_call('/customer/health.php');$answers['csrf']=hidden_value($health,'csrf');web_call('/customer/health.php',$answers);
    $after=json_decode(web_call('/api/availability.php?'.http_build_query($criteria))['body'],true);$slots=array_column($after['slots'],null,'time');
    repair_check(!$slots['14:30']['available'] && $slots['15:00']['available'],'Room cleaning blocks 2:30 PM and permits exactly 3 PM');
    $otherRoom=['service_id'=>$couples['id'],'room_id'=>$rooms['CS-1']['id'],'guests'=>2,'date'=>$date];$otherSlots=json_decode(web_call('/api/availability.php?'.http_build_query($otherRoom))['body'],true);$otherSlots=array_column($otherSlots['slots'],null,'time');
    repair_check(!$otherSlots['14:00']['available'] && $otherSlots['14:30']['available'],'Therapist cannot double-book and is released at treatment end');
    $dashboard=web_call('/customer/dashboard.php');repair_check(str_contains($dashboard['body'],$a['booking_reference']),'Customer history retrieves the saved appointment');
    repair_check(web_call('/auth/logout.php')['status']===405,'Logout is POST-only');
    repair_check(web_call('/auth/logout.php',['csrf'=>hidden_value($dashboard,'csrf')])['status']===303,'Logout destroys the authenticated session');
    repair_check(web_call('/customer/dashboard.php')['status']===303,'Protected page rejects logged-out session');
    $duplicate=web_call('/auth/register.php');$data['csrf']=hidden_value($duplicate,'csrf');$data['email']=strtoupper($email);$duplicate=web_call('/auth/register.php',$data);
    repair_check(str_contains($duplicate['body'],'already exists'),'Duplicate emails are prevented case-insensitively');
    web_call('/booking.php?service_id='.$couples['id']);$login=web_call('/auth/login.php');
    $wrong=web_call('/auth/login.php',['csrf'=>hidden_value($login,'csrf'),'email'=>$email,'password'=>'wrong-password']);repair_check(str_contains($wrong['body'],'email address or password is incorrect'),'Incorrect password is rejected meaningfully');
    $login=web_call('/auth/login.php');$signedIn=web_call('/auth/login.php',['csrf'=>hidden_value($login,'csrf'),'email'=>$email,'password'=>$password]);repair_check(redirect_path($signedIn)==='/booking.php','Newly registered account can sign in and resume booking');
    $health=final_get('/booking.php');$answers['csrf']=hidden_value($health,'csrf');web_call('/customer/health.php',$answers);$booking=web_call('/booking.php');
    repair_check((bool)preg_match('/name="service_id" value="'.$couples['id'].'" required checked/',$booking['body']),'Couples treatment survives logout, login and health navigation');
    $ordinaryLogin=web_call('/auth/login.php',null,2);
    $ordinarySignedIn=web_call('/auth/login.php',['csrf'=>hidden_value($ordinaryLogin,'csrf'),'email'=>$email,'password'=>$password],2);
    repair_check(redirect_path($ordinarySignedIn)==='/customer/dashboard.php','Ordinary sign-in redirects to the customer dashboard');
    repair_check(str_contains(final_get('/auth/login.php',2)['body'],'Temporary Repair QA'),'My account entry resolves to the authenticated customer dashboard');
    $q=db()->prepare('SELECT COUNT(*) FROM payments WHERE appointment_id=?');$q->execute([$a['id']]);repair_check((int)$q->fetchColumn()===0,'No financial transaction is fabricated');
    echo "\n$checks actual-port-8088 functionality checks passed.\n";
} finally {
    if($userId){
        foreach(['appointment_changes','refund_requests','cancellation_records','payment_reconciliation_cases','payments','payment_attempts','appointment_therapists'] as $table){$q=db()->prepare("DELETE x FROM $table x JOIN appointments a ON a.id=x.appointment_id WHERE a.user_id=?");$q->execute([$userId]);}
        foreach(['appointments','health_declarations','users'] as $table){$column=$table==='users'?'id':'user_id';$q=db()->prepare("DELETE FROM $table WHERE $column=?");$q->execute([$userId]);}
    }
    if($staffId){$q=db()->prepare('DELETE FROM therapist_schedules WHERE therapist_id=?');$q->execute([$staffId]);$q=db()->prepare('DELETE FROM therapists WHERE id=?');$q->execute([$staffId]);}
    $q=db()->prepare('DELETE FROM login_attempts WHERE email_hash=?');$q->execute([hash('sha256',$email)]);
    foreach($cookies as $cookie)if(is_file($cookie))unlink($cookie);
    if(db()->query('SELECT * FROM users ORDER BY id')->fetchAll()!==$baselineUsers || db()->query('SELECT * FROM health_declarations ORDER BY id')->fetchAll()!==$baselineHealth)throw new RuntimeException('Existing customer records changed unexpectedly');
    echo "Existing customer/declaration records preserved; temporary QA records removed.\n";
}
