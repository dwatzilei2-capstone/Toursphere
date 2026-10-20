<?php
if(PHP_SAPI!=='cli')exit;
$argv[1]='stages';ob_start();require __DIR__.'/logistics_requests_test.php';ob_end_clean();
require_once ROOT_PATH.'/includes/trip_schedule.php';
$checks=0;
function schedule_check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function schedule_change(array $values,array $actor=['id'=>1,'role_code'=>'dispatcher']){global $pdo,$id;$pdo->beginTransaction();try{trip_schedule_reschedule($pdo,$id,$values,$actor);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}}
function schedule_reject(array $values,string $label,array $actor=['id'=>1,'role_code'=>'dispatcher']){try{schedule_change($values,$actor);}catch(RuntimeException $e){schedule_check(true,$label);return;}throw new RuntimeException($label);}
$day=date('Y-m-d',strtotime('+5 days'));$values=['pickup'=>$day.'T09:00','delivery'=>$day.'T18:00','reason'=>'Warehouse loading slot changed'];
schedule_reject($values,'Requester denied',['id'=>1,'role_code'=>'customer']);
schedule_reject(array_replace($values,['reason'=>'']),'Reason required');
schedule_reject(array_replace($values,['pickup'=>'2026-02-30T09:00']),'Invalid date rejected');
schedule_reject(array_replace($values,['pickup'=>'2000-01-01T09:00']),'Past pickup rejected');
schedule_reject(array_replace($values,['delivery'=>$day.'T08:00']),'Delivery before pickup rejected');
schedule_change($values);
schedule_check((int)$pdo->query('SELECT count(*) FROM trips')->fetchColumn()===0,'Approved unassigned reservation creates no duplicate trip');
$pdo->exec("UPDATE vehicles SET payload_capacity_kg=5000,assigned_driver_id='D-CARGO',status='Assigned' WHERE id='V-CARGO'");
$pdo->prepare("UPDATE reservations SET status='Assigned',assigned_vehicle_id='V-CARGO',assigned_driver_id='D-CARGO' WHERE id=?")->execute([$id]);
$pdo->prepare("INSERT INTO trips(id,reservation_id,origin,destination,vehicle_id,driver_id,status,scheduled_departure,passengers) VALUES ('T-SCHEDULE',?,'Manila Warehouse','Batangas Depot','V-CARGO','D-CARGO','Assigned',?,0)")->execute([$id,$day.' 09:00']);
$values['pickup']=$day.'T10:00';schedule_change($values);
$saved=$pdo->query("SELECT * FROM trips WHERE id='T-SCHEDULE'")->fetch();
schedule_check($saved['scheduled_departure']===$day.' 10:00:00'&&$saved['status']==='Assigned','Existing trip synchronized without changing status');
schedule_check((int)$pdo->query('SELECT count(*) FROM trips')->fetchColumn()===1,'No duplicate schedules');
schedule_check((int)$pdo->query("SELECT count(*) FROM trip_timeline WHERE title='Delivery rescheduled'")->fetchColumn()===1,'Trip history retained');
$q=$pdo->prepare('SELECT * FROM reservations WHERE id=?');$q->execute([$id]);$saved=$q->fetch();
schedule_check($saved['return_date']===$day&&substr($saved['return_time'],0,5)==='18:00','Delivery estimate persisted on shared reservation');
$other=logistics_create_mock_request($pdo,$input,$actor);
foreach(['vehicle','driver'] as $resource){
 $pdo->prepare("UPDATE reservations SET status='Assigned',assigned_vehicle_id=?,assigned_driver_id=?,departure_date=?,departure_time='14:00',return_date=?,return_time='20:00' WHERE id=?")->execute([$resource==='vehicle'?'V-CARGO':null,$resource==='driver'?'D-CARGO':null,$day,$day,$other]);
 schedule_reject($values,'Overlapping '.$resource.' rejected');
}
$pdo->prepare("UPDATE reservations SET status='Cancelled' WHERE id=?")->execute([$other]);
schedule_change($values);schedule_check(true,'Cancelled schedule does not block');
$pdo->exec("UPDATE drivers SET license_expiration='2000-01-01' WHERE id='D-CARGO'");schedule_reject($values,'Expired license rejected');
$pdo->exec("UPDATE drivers SET license_expiration='2099-12-31' WHERE id='D-CARGO'");
foreach(['Dispatched','In Transit','Completed','Cancelled','Rejected','Pending Approval'] as $status){$pdo->prepare('UPDATE reservations SET status=? WHERE id=?')->execute([$status,$id]);schedule_reject($values,'Cannot reschedule '.$status);}
$pdo->prepare("UPDATE reservations SET status='Completed' WHERE id=?")->execute([$id]);$pdo->exec("UPDATE trips SET status='Completed',current_step='Verified Completed' WHERE id='T-SCHEDULE'");
$current_user=['id'=>1,'name'=>'Schedule Test','role_code'=>'fleet_admin','role_name'=>'Fleet Administrator','emp_id'=>'TEST','avatar'=>null];$current_permissions=['dispatch.view','dispatch.manage'];$_SESSION['last_valid_activity']=time();$_GET=['week'=>$day];
ob_start();require ROOT_PATH.'/modules/vehicle-reservation-dispatch/trip-schedule.php';$html=ob_get_clean();
foreach(['schedule-times','Pickup</span>','Delivery</span>','kg · ','m³','schedule-cargo-line','Verified Completed','Calendar week navigation'] as $label)schedule_check(strpos($html,$label)!==false,'Calendar renders '.$label);
schedule_check(strpos($html,'name="reason"')===false,'Completed delivery has no reschedule control');
echo "$checks schedule checks passed using temporary tables only.\n";
