<?php
require __DIR__.'/phase3-fixtures.php';
$base=rtrim($argv[1]??'http://127.0.0.1:8093/bella-vista-spa/public','/');
if(!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost','::1'],true))exit('Local test server required');
$f=new Phase3Fixtures();$checks=0;
function web_assert(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);++$checks;echo "PASS: $label\n";}
function field(array $r,string $name):string{if(!preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/',$r['body'],$m))throw new RuntimeException('Missing '.$name);return html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');}
class Phase3Http{
    private string $cookie;
    public function __construct(){$this->cookie=tempnam(__DIR__.'/../var','phase3-http-');}
    public function call(string $path,?array $data=null):array{
        global $base;$c=curl_init($base.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$this->cookie,CURLOPT_COOKIEFILE=>$this->cookie,CURLOPT_TIMEOUT=>15]);if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);$raw=curl_exec($c);if($raw===false)throw new RuntimeException(curl_error($c));$size=curl_getinfo($c,CURLINFO_HEADER_SIZE);$r=['status'=>curl_getinfo($c,CURLINFO_HTTP_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];curl_close($c);return $r;
    }
    public function __destruct(){if(is_file($this->cookie))unlink($this->cookie);}
}
$client=new Phase3Http();$other=new Phase3Http();
try{
    foreach([$client,$other] as $i=>$http){$r=$http->call('/auth/login.php');$login=$http->call('/auth/login.php',['csrf'=>field($r,'csrf'),'email'=>$f->emails[$i],'password'=>$f->password]);web_assert($login['status']===303,'Authenticated customer session '.$i);}
    $a=$f->book('SR-1',0,'16:00');$ref=$a['booking_reference'];$query='?reference='.$ref;
    foreach(['/customer/cancel.php','/customer/reschedule.php','/customer/payment.php'] as $page)web_assert($other->call($page.$query)['status']===404,'Ownership protected: '.$page);
    $pay=$client->call('/customer/payment.php'.$query);
    web_assert($pay['status']===200 && str_contains($pay['body'],'Online payment is currently unavailable.'),'Payment page honestly explains disabled checkout');
    web_assert(str_contains($pay['body'],'₱800.00') && !str_contains($pay['body'],'Continue to secure payment'),'Payment page shows the exact total and omits unavailable payment controls');
    web_assert($client->call('/customer/payment.php',['reference'=>$ref])['status']===403,'Payment initiation CSRF protection');
    $r=$client->call('/customer/payment.php',['reference'=>$ref,'csrf'=>field($client->call('/customer/dashboard.php'),'csrf'),'terms'=>'1','payment_choice'=>'full','revision'=>$a['revision'],'request_key'=>bin2hex(random_bytes(32))]);
    web_assert($r['status']===422 && (int)db()->query('SELECT COUNT(*) FROM payment_attempts')->fetchColumn()===0,'Forged checkout POST cannot open an unconfigured gateway');
    $r=$client->call('/api/payment-webhook.php',['paid'=>'true','signature'=>'fake']);web_assert($r['status']===503 && !str_contains($r['body'],'received'),'Unconfigured webhook fails closed without processing browser data');
    web_assert($client->call('/api/payment-webhook.php')['status']===405,'Webhook requires POST');
    web_assert($client->call('/customer/payment-result.php?attempt=forged&status=verified')['status']===404,'Browser redirect flags cannot fabricate payment verification');
    $cancel=$client->call('/customer/cancel.php'.$query);
    web_assert(str_contains($cancel['body'],'Confirm cancellation') && str_contains($cancel['body'],'Cancellation deadline'),'Cancellation review shows policy and deadline');
    web_assert($client->call('/customer/cancel.php',['reference'=>$ref])['status']===403,'Cancellation CSRF enforced');
    $action=['reference'=>$ref,'csrf'=>field($cancel,'csrf'),'revision'=>field($cancel,'revision'),'request_key'=>field($cancel,'request_key'),'reason'=>'Disposable HTTP cancellation'];
    $r=$client->call('/customer/cancel.php',$action);web_assert($r['status']===303,'Cancellation redirects after persisted success');
    $again=$client->call('/customer/cancel.php',$action);web_assert($again['status']===303,'Duplicate cancellation submission is idempotent');
    $details=$client->call('/customer/appointment.php'.$query);web_assert(str_contains($details['body'],'Disposable HTTP cancellation') && str_contains($details['body'],'Cancellation recorded'),'Details retain cancellation reason and timestamp');
    $q=db()->prepare('SELECT COUNT(*) FROM refund_requests WHERE appointment_id=?');$q->execute([$a['id']]);web_assert((int)$q->fetchColumn()===0,'Unpaid cancellation invents no refund or payment transaction');
    $b=$f->book('CS-1',1,'13:00',0,2);$ref=$b['booking_reference'];
    $r=$client->call('/customer/reschedule.php?reference='.$ref.'&date='.$f->date(2));web_assert($r['status']===200 && str_contains($r['body'],'Review proposed change'),'Replacement slot selection is server-rendered');
    $review=$client->call('/customer/reschedule.php?reference='.$ref.'&date='.$f->date(2).'&time=15%3A00');web_assert(str_contains($review['body'],'Confirm reschedule') && str_contains($review['body'],'original time stays reserved'),'Proposed change requires final review');
    web_assert($client->call('/customer/reschedule.php',['reference'=>$ref])['status']===403,'Rescheduling CSRF enforced');
    $change=['reference'=>$ref,'date'=>$f->date(2),'time'=>'15:00','csrf'=>field($review,'csrf'),'revision'=>field($review,'revision'),'request_key'=>field($review,'request_key'),'total_amount'=>'1','room_id'=>$f->rooms['SR-3']['id'],'service_id'=>'999'];
    $r=$client->call('/customer/reschedule.php',$change);web_assert($r['status']===303,'Reschedule persists and redirects');
    $new=owned_appointment($f->users[0],$ref);web_assert($new['room_id']===$b['room_id'] && $new['total_amount']===$b['total_amount'] && $new['service_id']===$b['service_id'],'Forged service/room/price fields cannot change a date/time-only booking');
    $again=$client->call('/customer/reschedule.php',$change);web_assert($again['status']===303 && (int)owned_appointment($f->users[0],$ref)['revision']===1,'Duplicate reschedule submission does not create another change');
    $change['request_key']=bin2hex(random_bytes(32));$change['time']='16:00';web_assert($client->call('/customer/reschedule.php',$change)['status']===422,'Stale revision rejected through HTTP');
    $details=$client->call('/customer/appointment.php?reference='.$ref);web_assert(str_contains($details['body'],'Rescheduled') && str_contains($details['body'],'→'),'Customer detail shows old/new schedule audit');
    $dashboard=$client->call('/customer/dashboard.php');web_assert(str_contains($dashboard['body'],'Payment history') && str_contains($dashboard['body'],'Outstanding Balance') && str_contains($dashboard['body'],'Disposable HTTP cancellation')===false,'Dashboard shows payment summaries and history without exposing cancellation notes');
    web_assert(str_contains($dashboard['body'],'No payment activity yet.')&&!str_contains($dashboard['body'],'Test checkout — No actual funds will be transferred.'),'Empty payment history has a single neutral message');
    // Move only this disposable fixture near the current time to exercise a late-action UI.
    $q=db()->prepare('UPDATE appointments SET starts_at=UTC_TIMESTAMP()+INTERVAL 2 HOUR,ends_at=UTC_TIMESTAMP()+INTERVAL 3 HOUR,cleaning_ends_at=UTC_TIMESTAMP()+INTERVAL 210 MINUTE WHERE id=?');$q->execute([$b['id']]);
    $late=$client->call('/customer/cancel.php?reference='.$ref);web_assert(str_contains($late['body'],'deadline has passed') && !str_contains($late['body'],'id="cancel-reason"'),'Late cancellation UI directs customer to administrator');
    $late=$client->call('/customer/reschedule.php?reference='.$ref);web_assert(str_contains($late['body'],'deadline has passed') && !str_contains($late['body'],'id="new-date"'),'Late rescheduling UI disables new selection');
    echo "\n$checks Phase 3 HTTP checks passed. Live gateway tests are pending provider selection.\n";
}finally{$f->cleanup();}
