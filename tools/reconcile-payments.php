<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$config=require __DIR__.'/../config/config.php';require __DIR__.'/../app/database.php';require __DIR__.'/../app/modules/catalog.php';require __DIR__.'/../app/modules/booking.php';
try{
    $expired=expire_payment_holds();$checked=0;$errors=0;
    $p=db();$p->beginTransaction();
    try{lock_scheduling($p);$ids=$p->query('SELECT id FROM appointments')->fetchAll(PDO::FETCH_COLUMN);foreach($ids as $id)refresh_payment_status((int)$id);$p->commit();}
    catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
    if(payment_verification_ready()){
        $attempts=db()->query("SELECT * FROM payment_attempts WHERE status IN ('initiated','pending','expired','cancelled','requires_review') ORDER BY id LIMIT 100")->fetchAll();
        foreach($attempts as $attempt){try{reconcile_payment_attempt($attempt);++$checked;}catch(Throwable $e){++$errors;error_log('Reconciliation needs review for attempt ID '.$attempt['id']);}}
    }
    $cases=db()->query("SELECT id,appointment_id,attempt_id,reason_code,status,created_at FROM payment_reconciliation_cases WHERE status='open' ORDER BY id")->fetchAll();
    echo json_encode(['expired_holds'=>$expired,'provider_configured'=>payment_readiness()['ready'],'attempts_checked'=>$checked,'attempts_needing_review'=>$errors,'open_cases'=>$cases],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"Reconciliation could not complete. Check the configured database and server log.\n");exit(1);}
