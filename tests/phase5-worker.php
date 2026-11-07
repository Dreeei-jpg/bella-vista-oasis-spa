<?php
if(PHP_SAPI!=='cli')exit;
$config=require __DIR__.'/../config/config.php';if(!str_ends_with($config['db_name'],'_test'))exit('Separate test database required.');
require __DIR__.'/../app/database.php';require __DIR__.'/../app/modules/catalog.php';require __DIR__.'/../app/modules/booking.php';
$job=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);file_put_contents($job['ready'],'ready');$deadline=microtime(true)+10;
while(!is_file($job['barrier'])){if(microtime(true)>$deadline)exit('Barrier timed out');usleep(10000);}
try {
    $result=$job['action']==='book'?create_booking($job['user_id'],$job['input']):complete_demo_payment($job['user_id'],$job['reference'],$job['choice'],$job['method'],$job['revision'],$job['key'],$job['expected']);
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
}catch(BookingValidationException $e){echo json_encode(['ok'=>false,'reason'=>$e->getMessage()],JSON_THROW_ON_ERROR);}
