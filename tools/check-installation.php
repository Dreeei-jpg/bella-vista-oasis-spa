<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config=require __DIR__.'/../config/config.php';
require __DIR__.'/../app/database.php';
require __DIR__.'/../app/modules/staffing.php';
if (isset($argv[1]) && str_starts_with($argv[1],'--target-port=')) {
    $port=substr($argv[1],14);
    if ($config['db_host']!=='127.0.0.1' || (string)$config['db_port']!==$port || $config['db_name']!=='bella_vista_spa') {
        fwrite(STDERR,"Local configuration points to a different database. Use its configured MySQL server, or update config/local.php before starting the demo.\n");exit(1);
    }
    exit(0);
}
$required=[
    'users'=>['id','full_name','email','password_hash','role','terms_accepted_at','account_status','session_version','last_login_at'],
    'admin_invitations'=>['id','user_id','token_hash','expires_at','used_at','revoked_at','created_by'],
    'admin_security_throttles'=>['user_id','attempts','started_at'],
    'admin_security_events'=>['event_type','actor_user_id','target_user_id','created_at'],
    'services'=>['id','slug','base_price','duration_minutes','base_capacity','max_capacity','extra_person_fee'],
    'rooms'=>['id','service_id','code','capacity'],
    'therapists'=>['id','full_name','is_active'],
    'therapist_schedules'=>['therapist_id','starts_at','ends_at','schedule_type'],
    'therapist_service_exclusions'=>['therapist_id','service_id'],
    'appointments'=>['id','user_id','service_id','room_id','health_declaration_id','starts_at','ends_at','cleaning_ends_at','payment_status','request_key','revision','booking_mode','service_name_snapshot','duration_minutes_snapshot','preferred_therapist_id'],
    'health_declarations'=>['id','user_id','declaration_version','responses_json','consented_at'],
    'business_settings'=>['cleaning_gap_minutes','slot_interval_minutes','checkout_hold_minutes','demo_payments_enabled','demo_downpayment_percentage','demo_hold_minutes'],
    'demo_payments'=>['appointment_id','user_id','request_key','transaction_reference','amount_centavos','remaining_centavos','is_demo'],
    'login_attempts'=>['email_hash','ip_hash','attempted_at'],
    'appointment_therapists'=>['appointment_id','therapist_id'],
    'operating_hours'=>['weekday','opens_at','closes_at'],
    'room_blocks'=>['room_id','starts_at','ends_at'],
    'service_staffing_requirements'=>['service_id','therapists_required'],
    'payments'=>['appointment_id','amount','verified_at','attempt_id','allocation_status'],
    'payment_attempts'=>['appointment_id','public_id','status'],
    'appointment_changes'=>['appointment_id','action'],
    'cancellation_records'=>['appointment_id','reason'],
    'refund_requests'=>['appointment_id','status'],
    'payment_reconciliation_cases'=>['appointment_id','status'],
    'payment_webhook_events'=>['provider_event_id','attempt_id'],
];
$report=['database'=>['host'=>$config['db_host'],'port'=>$config['db_port'],'name'=>$config['db_name']],'connected'=>false,'missing'=>[]];
try {
    $pdo=db();$report['connected']=true;
    foreach($required as $table=>$columns){
        $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');$q->execute([$config['db_name'],$table]);$present=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach($columns as $column)if(!in_array($column,$present,true))$report['missing'][]=$table.'.'.$column;
    }
    $report['healthy']=!$report['missing'];
    if($report['healthy']){
        $report['migrations']=$pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
        $report['counts']=[];foreach(['users','services','rooms','therapists','appointments','health_declarations','demo_payments'] as $table)$report['counts'][$table]=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
        $report['demo']=$pdo->query('SELECT demo_payments_enabled,demo_downpayment_percentage,demo_hold_minutes FROM business_settings WHERE id=1')->fetch();
        $report['active_therapists']=(int)$pdo->query('SELECT COUNT(*) FROM therapists WHERE is_active=1')->fetchColumn();
        $validShift=valid_staff_shift_sql('s');
        $report['future_available_shifts']=(int)$pdo->query("SELECT COUNT(*) FROM therapist_schedules s JOIN therapists t ON t.id=s.therapist_id WHERE t.is_active=1 AND s.schedule_type='available' AND s.ends_at>UTC_TIMESTAMP() AND $validShift")->fetchColumn();
        $report['shift_entries_outside_hours']=(int)$pdo->query("SELECT COUNT(*) FROM therapist_schedules s WHERE NOT $validShift")->fetchColumn();
        $report['overlapping_shift_pairs']=(int)$pdo->query("SELECT COUNT(*) FROM therapist_schedules s JOIN therapist_schedules other
            ON s.therapist_id=other.therapist_id AND s.schedule_type=other.schedule_type AND s.id<other.id
            AND other.starts_at<s.ends_at AND other.ends_at>s.starts_at")->fetchColumn();
        $report['staffing_note']=!$report['active_therapists']||!$report['future_available_shifts']?'Add active staff and publish future Available shifts before accepting appointments.':null;
    }
} catch(Throwable $e) { $report['healthy']=false;$report['error']='Database inspection failed. Check MySQL, connection settings and migrations; no credentials are printed.'; }
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
exit($report['healthy']?0:1);
