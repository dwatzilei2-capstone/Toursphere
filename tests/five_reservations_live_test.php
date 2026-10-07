<?php
// Explicitly requested live test: creates five labelled bookings and uses real approval/assignment handlers.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/schedules.php';
require_once ROOT_PATH . '/includes/customer_reservations.php';
require_once ROOT_PATH . '/includes/reservation-assignment.php';
$p = db();
$current_user = $p->query("SELECT u.*,r.code AS role_code FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='fleet_admin' AND u.status='Active' ORDER BY u.id LIMIT 1")->fetch();
$_SESSION['user_id'] = $current_user['id'];
$current_permissions = ['dispatch.manage','dispatch.view'];
if (($argv[1] ?? '') === 'approve' || ($argv[1] ?? '') === 'assign') {
    $id = $argv[2];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SESSION['assignment_csrf'] = 'five-booking-live-test';
    $_POST = ['reservation_id'=>$id,'decision'=>'approve','csrf'=>$_SESSION['assignment_csrf']];
    if ($argv[1] === 'assign') {
        $s=$p->prepare('SELECT * FROM reservations WHERE id=?'); $s->execute([$id]); $r=$s->fetch();
        $options=assignment_options($p,$r,$r['departure_date'].' '.$r['departure_time']);
        $available=array_values(array_filter($options['vehicles'],fn($v)=>$v['eligible']));
        echo $id.' available vehicles: '.count($available).PHP_EOL;
        if (!$available) { echo json_encode($options).PHP_EOL; exit(1); }
        $v=$available[0]; $d=$v['driver'] ?: $v['drivers'][0];
        $_POST += ['vehicle_id'=>$v['id'],'driver_id'=>$d['id'],'dispatch_action'=>'assign',
          'departure'=>$r['departure_date'].'T'.$r['departure_time'],'notes'=>$r['notes'],
          'reassignment_from'=>$d['reassignment']?implode(',',array_column($d['current_vehicles'],'id')):''];
    }
    register_shutdown_function(function()use($p,$id,$argv){
        $s=$p->prepare('SELECT status,assigned_vehicle_id,assigned_driver_id FROM reservations WHERE id=?');$s->execute([$id]);$r=$s->fetch();
        $expected=$argv[1]==='approve'?'Approved':'Assigned';
        if($r['status']!==$expected){fwrite(STDERR,'FAIL '.$id.' '.json_encode($r).PHP_EOL);exit(1);}
        echo 'PASS '.$id.' '.$argv[1].' '.json_encode($r).PHP_EOL;
    });
    require ROOT_PATH.'/actions/'.($argv[1]==='approve'?'reservation-review.php':'dispatch.php');
    exit;
}
if (($argv[1] ?? '') !== 'run') { exit('Use run to create five real test reservations.'); }
$customer=$p->query("SELECT id FROM users WHERE name='LEI DOMINGO' AND status='Active' LIMIT 1")->fetchColumn();
if (!$customer) throw new RuntimeException('Test customer missing');
$scheduleId=5;
$date='2026-10-14';
while(true){
    $s=$p->prepare('SELECT COUNT(*) FROM scheduled_departures WHERE schedule_id=? AND service_date=?');$s->execute([$scheduleId,$date]);
    if(!$s->fetchColumn() && (int)date('N',strtotime($date))<=5)break;
    $date=date('Y-m-d',strtotime($date.' +1 day'));
}
$p->beginTransaction();
try {
    $instance=schedule_lock_or_create_instance($p,$scheduleId,$date);
    $ids=[];
    for($i=1;$i<=5;$i++){
        schedule_assert_capacity($p,$instance,2);
        $id=next_sequential_id($p,'reservations','id','RES-2026-');$ids[]=$id;
        $p->prepare("INSERT INTO reservations(id,client_name,contact_person,passenger_count,origin,destination,departure_date,departure_time,vehicle_requested,status,trip_type,notes,customer_id,departure_schedule_instance_id,departure_schedule_name,fare_status) VALUES (?,?,?,2,'NAVOTAS','QUEZON CITY',?,?,'Executive Van','Pending Approval','One Way',?,?,?,?,'Estimated')")
          ->execute([$id,'[TEST] Assignment Booking '.$i,'LEI DOMINGO',$date,substr($instance['departure_time_snapshot'],0,5),'TEST: five sequential bookings requested on 2026-10-07; no dispatch.',$customer,$instance['id'],$instance['schedule_name_snapshot']]);
        $p->prepare("INSERT INTO reservation_events(reservation_id,actor_id,status,reason) VALUES (?,?,'Pending Approval','Explicitly requested five-booking assignment test')")->execute([$id,$current_user['id']]);
        schedule_refresh_required_vehicle($p,(int)$instance['id']);
    }
    $p->commit();
}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
echo 'Created: '.implode(', ',$ids).' | '.$date.' | shared schedule '.$instance['id'].PHP_EOL;
foreach($ids as $id)foreach(['approve','assign'] as $action){
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.$action.' '.escapeshellarg($id),$code);
    if($code!==0)throw new RuntimeException('Live test failed at '.$id.' '.$action);
}
echo 'PASS: all five reservations approved and assigned through real action handlers.'.PHP_EOL;
