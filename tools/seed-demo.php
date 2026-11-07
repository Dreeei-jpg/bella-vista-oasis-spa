<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$config=require __DIR__.'/../config/config.php';
if(!in_array('--development-only',$argv,true)||empty($config['development_mode'])||!in_array($config['db_host'],['127.0.0.1','localhost'],true)) {
    fwrite(STDERR,"Development-only fixture seed. Enable development_mode in local configuration and pass --development-only on a local database. No credentials or real employee records are created.\n");exit(1);
}
require __DIR__.'/../app/database.php';require __DIR__.'/../app/modules/catalog.php';require __DIR__.'/../app/modules/booking.php';
$p=db();$p->beginTransaction();
try {
    lock_scheduling($p);
    $catalog=[['single-room-massage','Single Room Massage','Private massage room.',800,60,1,1,0,'single.jpg',1],['couples-suite','Couples Suite','Private suite for two.',1800,60,2,2,0,'couples.jpg',2],['family-lounge','Barkada / Family Spa Lounge','Private lounge for up to six; four included.',4000,90,4,6,750,'lounge.jpg',3]];
    foreach($catalog as $service){$q=$p->prepare('SELECT id FROM services WHERE slug=?');$q->execute([$service[0]]);$id=$q->fetchColumn();if(!$id){$q=$p->prepare('INSERT INTO services (slug,name,description,base_price,duration_minutes,base_capacity,max_capacity,extra_person_fee,image_filename,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)');$q->execute($service);$id=(int)$p->lastInsertId();}
        $q=$p->prepare('INSERT INTO service_staffing_requirements (service_id,therapists_required) VALUES (?,1) ON DUPLICATE KEY UPDATE service_id=VALUES(service_id)');$q->execute([$id]);
        $codes=match($service[0]){'single-room-massage'=>['SR-1','SR-2','SR-3'],'couples-suite'=>['CS-1','CS-2'],default=>['FL-1','FL-2']};
        foreach($codes as $code){$q=$p->prepare('SELECT id,service_id FROM rooms WHERE code=?');$q->execute([$code]);$room=$q->fetch();if($room&&(int)$room['service_id']!==(int)$id)throw new RuntimeException('A required room has a conflicting service mapping; review it before seeding.');
            if(!$room){$q=$p->prepare('INSERT INTO rooms (service_id,code,capacity,is_active) VALUES (?,?,?,1)');$q->execute([$id,$code,$service[6]]);}
            elseif(in_array('--enable-rooms',$argv,true)){$q=$p->prepare('UPDATE rooms SET is_active=1 WHERE id=?');$q->execute([$room['id']]);}
        }
    }
    $staff=[];$active=(int)$p->query('SELECT COUNT(*) FROM therapists WHERE is_active=1')->fetchColumn();
    if($active===0||in_array('--add-demo-staff',$argv,true)) {
        foreach(['A','B','C'] as $suffix){$name='[DEMO] Fictional Therapist '.$suffix;$q=$p->prepare('SELECT id FROM therapists WHERE full_name=? AND is_demo=1 ORDER BY id LIMIT 1');$q->execute([$name]);$id=$q->fetchColumn();
            if(!$id){$q=$p->prepare('INSERT INTO therapists (full_name,is_active,is_demo) VALUES (?,1,1)');$q->execute([$name]);$id=(int)$p->lastInsertId();}else{$q=$p->prepare('UPDATE therapists SET is_active=1 WHERE id=?');$q->execute([$id]);}$staff[]=(int)$id;
        }
    } else $staff=array_map('intval',$p->query('SELECT id FROM therapists WHERE is_demo=1 AND is_active=1')->fetchAll(PDO::FETCH_COLUMN));
    $created=0;$today=new DateTimeImmutable('today',spa_timezone());
    foreach($staff as $id)for($offset=0;$offset<30;$offset++){$date=$today->modify("+$offset days")->format('Y-m-d');$from=utc_time(local_datetime($date,'13:00'));$to=utc_time(local_datetime($date,'21:00'));$q=$p->prepare("SELECT id FROM therapist_schedules WHERE therapist_id=? AND starts_at=? AND ends_at=? AND schedule_type='available'");$q->execute([$id,$from,$to]);if(!$q->fetch()){$q=$p->prepare("INSERT INTO therapist_schedules (therapist_id,starts_at,ends_at,schedule_type) VALUES (?,?,?,'available')");$q->execute([$id,$from,$to]);$created++;}}
    $p->commit();echo "Development catalog checked. Fictional staff are explicitly marked [DEMO] / is_demo=1.\nNew fictional-staff shifts: $created. Existing real employee schedules and accounts were not invented or overwritten.\n";
}catch(Throwable $e){if($p->inTransaction())$p->rollBack();fwrite(STDERR,'Demo seed failed: '.$e->getMessage().PHP_EOL);exit(1);}
