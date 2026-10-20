<?php
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../includes/bootstrap.php';
require_once ROOT_PATH.'/includes/dashboard-operations.php';
function check_dashboard(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n";}
if(in_array('--sessions',$argv,true)){
    $path=ROOT_PATH.'/tmp/dashboard-sessions';
    if(!is_dir($path))mkdir($path,0700,true);
    ini_set('session.save_path',$path);
    $sessions=[];
    foreach(['fleet_admin','dispatcher','fleet_manager','customer','driver'] as $role){
        $q=db()->prepare("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code=? AND u.status='Active' LIMIT 1");
        $q->execute([$role]);$id=$q->fetchColumn();if(!$id)continue;
        session_id(bin2hex(random_bytes(24)));session_start();$_SESSION=['user_id'=>(int)$id,'last_valid_activity'=>time()];$sessions[$role]=session_id();session_write_close();
    }
    file_put_contents(ROOT_PATH.'/tmp/dashboard-sessions.json',json_encode($sessions));
    echo "Created isolated test sessions.\n";exit;
}
if(in_array('--snapshot',$argv,true)){
    $snapshot=[];
    foreach(['reservations','trips','vehicles','drivers','maintenance_orders'] as $table)$snapshot[$table]=db()->query("SELECT md5(COALESCE(string_agg(row_to_json(s)::text,'' ORDER BY id),'')) FROM $table s")->fetchColumn();
    echo json_encode($snapshot);exit;
}
$date=new DateTimeImmutable('today');
foreach(['day'=>24,'month'=>(int)$date->format('t'),'year'=>12] as $period=>$count){
    $data=dashboard_trend(db(),$date,$period);
    check_dashboard(count($data['buckets'])===$count,"$period bucket count");
    foreach($data['buckets'] as $bucket)check_dashboard($bucket['on_time']<=$bucket['completed'],"$period on-time subset: ".$bucket['label']);
}
$empty=dashboard_trend(db(),new DateTimeImmutable('1900-01-01'),'year');
check_dashboard(array_sum(array_map(fn($r)=>$r['completed']+$r['scheduled']+$r['cancelled'],$empty['buckets']))===0,'Empty period has no fabricated values');
$events=dashboard_calendar(db(),'1900-01-01','2101-01-01');
$ids=array_column($events['events'],'reservation_id');
check_dashboard(count($ids)===count(array_unique($ids)),'Calendar reservations are deduplicated');
foreach($events['events'] as $event){
    $q=db()->prepare('SELECT origin,destination FROM reservations WHERE id=?');$q->execute([$event['reservation_id']]);$record=$q->fetch();
    check_dashboard($record['origin']===$event['origin']&&$record['destination']===$event['destination'],'Calendar event matches existing reservation');
}
$row=['status'=>'Completed','reservation_status'=>'Completed','current_step'=>'Verified Completed','delivery_verified'=>true,'driver_id'=>'D','vehicle_id'=>'V','actual_arrival'=>'2026-10-20 10:00:00','return_date'=>'2026-10-20','return_time'=>'11:00:00'];
check_dashboard(dashboard_delivery_on_time($row)===true,'Verified arrival before promise is on time');
check_dashboard(dashboard_delivery_on_time(array_replace($row,['actual_arrival'=>'2026-10-20 12:00:00']))===false,'Late arrival is not on time');
check_dashboard(dashboard_delivery_on_time(array_replace($row,['delivery_verified'=>false]))===null,'Unverified completion excluded');
check_dashboard(dashboard_delivery_on_time(array_replace($row,['return_time'=>null]))===null,'Missing promise is unknown');
check_dashboard(dashboard_delivery_on_time(array_replace($row,['finalized_scores'=>'{"on_time":{"score":90,"delivery_outcome":"late"}}']))===false,'Finalized warehouse outcome takes precedence');
check_dashboard(count(dashboard_trend(db(),new DateTimeImmutable('2024-02-15'),'month')['buckets'])===29,'Leap-year month buckets');
$year=dashboard_trend(db(),$date,'year');
$q=db()->prepare("SELECT COUNT(DISTINCT r.id) FROM reservations r JOIN trips t ON t.reservation_id=r.id
 WHERE r.request_kind='Cargo' AND r.status='Completed' AND t.status='Completed' AND t.current_step='Verified Completed'
 AND t.driver_id IS NOT NULL AND t.vehicle_id IS NOT NULL AND t.actual_arrival>=?::timestamp AND t.actual_arrival<?::timestamp
 AND t.id=(SELECT id FROM trips WHERE reservation_id=r.id ORDER BY created_at DESC,id DESC LIMIT 1)
 AND EXISTS(SELECT 1 FROM reservation_events ev WHERE ev.reservation_id=r.id AND ev.status='Verified Completed')
 AND (COALESCE(r.cargo_type,'')||' '||COALESCE(r.notes,'')||' '||COALESCE(t.completion_notes,'')) !~* 'DEVELOPMENT (TEST|SIMULATION)'");
$q->execute([$year['start'],$year['end']]);
check_dashboard(array_sum(array_column($year['buckets'],'completed'))===(int)$q->fetchColumn(),'Live completed totals match independent SQL count');
