<?php
require __DIR__.'/phase3-fixtures.php';
$job=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
file_put_contents($job['ready'],'ready');$end=microtime(true)+10;
while(!is_file($job['barrier'])){if(microtime(true)>$end)exit('Barrier timed out');usleep(10000);}
try{
    $result=reschedule_appointment((int)$job['user'],$job['reference'],$job['date'],$job['time'],$job['revision'],$job['key']);
    echo json_encode(['status'=>'changed','id'=>$result['id']]);
}catch(BookingValidationException $e){echo json_encode(['status'=>'rejected','reason'=>$e->getMessage()]);}
