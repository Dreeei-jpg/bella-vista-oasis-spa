<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$config=require __DIR__.'/../config/config.php';
if(!str_ends_with($config['db_name'],'_test'))exit("Use a separate database ending in _test.\n");
require_once __DIR__.'/../app/database.php';require_once __DIR__.'/../app/modules/catalog.php';require_once __DIR__.'/../app/modules/booking.php';
require __DIR__.'/legacy-mode.php';
final class Phase3Fixtures
{
    public array $users=[],$health=[],$staff=[],$emails=[];
    public string $password,$prefix;
    public DateTimeImmutable $day;
    public array $rooms;
    public function __construct(){
        $this->prefix='phase3-qa-'.bin2hex(random_bytes(5));$this->password='Disposable-'.bin2hex(random_bytes(12));
        $this->day=(new DateTimeImmutable('tomorrow',spa_timezone()))->modify('+10 days');
        $this->rooms=db()->query('SELECT code,id,service_id FROM rooms')->fetchAll(PDO::FETCH_UNIQUE);
        for($i=0;$i<2;$i++){
            $email=$this->prefix.'-'.$i.'@example.test';$this->emails[]=$email;
            $q=db()->prepare('INSERT INTO users (full_name,email,password_hash,terms_accepted_at) VALUES (?,?,?,UTC_TIMESTAMP())');$q->execute(['Disposable Phase 3 QA '.$i,$email,password_hash($this->password,PASSWORD_DEFAULT)]);$this->users[]=(int)db()->lastInsertId();
            $answers=array_fill_keys(array_keys(HEALTH_QUESTIONS),'no');$answers+=['notes'=>'Test fixture only','consent'=>'1'];$this->health[]=save_health_declaration($this->users[$i],$answers);
        }
        for($i=0;$i<2;$i++){
            $q=db()->prepare('INSERT INTO therapists (full_name) VALUES (?)');$q->execute([$this->prefix.' staff '.$i]);$id=(int)db()->lastInsertId();$this->staff[]=$id;
            for($offset=0;$offset<20;$offset++){
                $date=$this->date($offset);$q=db()->prepare("INSERT INTO therapist_schedules (therapist_id,starts_at,ends_at,schedule_type) VALUES (?,?,?,'available')");$q->execute([$id,utc_time(local_datetime($date,'13:00')),utc_time(local_datetime($date,'21:00'))]);
            }
        }
    }
    public function date(int $offset):string{return $this->day->modify("+$offset days")->format('Y-m-d');}
    public function book(string $room,int $offset,string $time='13:00',int $owner=0,int $guests=1):array{
        $result=create_booking($this->users[$owner],['service_id'=>$this->rooms[$room]['service_id'],'room_id'=>$this->rooms[$room]['id'],'guests'=>$guests,'date'=>$this->date($offset),'time'=>$time,'terms'=>'1','health_id'=>$this->health[$owner],'request_key'=>bin2hex(random_bytes(32))]);
        return owned_appointment($this->users[$owner],$result['booking_reference']);
    }
    public function legacyPayment(array $a,string $amount,string $type='downpayment'):array{
        $q=db()->prepare("INSERT INTO payments (appointment_id,provider,provider_reference,idempotency_key,amount,currency,payment_type,status,verified_at) VALUES (?,'legacy-test-fixture',?,?,?,'PHP',?,'verified',UTC_TIMESTAMP())");
        $q->execute([$a['id'],'historical-fixture-'.bin2hex(random_bytes(6)),bin2hex(random_bytes(32)),$amount,$type]);
        $id=(int)db()->lastInsertId();refresh_payment_status((int)$a['id']);$q=db()->prepare('SELECT * FROM payments WHERE id=?');$q->execute([$id]);return $q->fetch();
    }
    public function cleanup():void{
        foreach($this->users as $id){
            $q=db()->prepare('DELETE e FROM payment_events e JOIN payments p ON p.id=e.payment_id JOIN appointments a ON a.id=p.appointment_id WHERE a.user_id=?');$q->execute([$id]);
            $q=db()->prepare('DELETE e FROM payment_webhook_events e JOIN payment_attempts p ON p.id=e.attempt_id JOIN appointments a ON a.id=p.appointment_id WHERE a.user_id=?');$q->execute([$id]);
            foreach(['demo_payments','appointment_changes','refund_requests','cancellation_records','payment_reconciliation_cases','payments','payment_attempts','appointment_therapists'] as $table){$q=db()->prepare("DELETE x FROM $table x JOIN appointments a ON a.id=x.appointment_id WHERE a.user_id=?");$q->execute([$id]);}
            foreach(['appointments','health_declarations','users'] as $table){$col=$table==='users'?'id':'user_id';$q=db()->prepare("DELETE FROM $table WHERE $col=?");$q->execute([$id]);}
        }
        foreach($this->staff as $id){$q=db()->prepare('DELETE FROM therapist_schedules WHERE therapist_id=?');$q->execute([$id]);$q=db()->prepare('DELETE FROM therapists WHERE id=?');$q->execute([$id]);}
        foreach($this->emails as $email){$q=db()->prepare('DELETE FROM login_attempts WHERE email_hash=?');$q->execute([hash('sha256',$email)]);}
    }
}
