<?php
require_once __DIR__.'/dispatch_board.php';

/** Reuse the reservation's time window and its existing trip; caller owns transaction. */
function trip_schedule_reschedule(PDO $pdo,string $id,array $input,array $actor): void {
 if(!$pdo->inTransaction())throw new RuntimeException('Rescheduling requires a transaction.');
 if(!in_array($actor['role_code']??'',['fleet_admin','dispatcher'],true))throw new RuntimeException('Only Admin/Dispatcher may reschedule deliveries.');
 $reason=trim((string)($input['reason']??''));
 if($reason===''||mb_strlen($reason)>2000)throw new RuntimeException('Enter a rescheduling reason (up to 2,000 characters).');
 $tz=new DateTimeZone(company_timezone());$dates=[];
 foreach(['pickup','delivery'] as $key){
  $value=(string)($input[$key]??'');$date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$tz);
  if(!$date||$date->format('Y-m-d\TH:i')!==$value)throw new RuntimeException('Enter valid pickup and estimated delivery times.');
  $dates[$key]=$date;
 }
 if($dates['pickup']<=new DateTimeImmutable('now',$tz)||$dates['delivery']<=$dates['pickup'])throw new RuntimeException('Pickup must be in the future and estimated delivery must follow pickup.');
 // Same lock order as assignment: serialize allocation and schedule edits.
 $pdo->exec("SELECT pg_advisory_xact_lock(hashtext('fleet-designated-assignment'))");
 $q=$pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');$q->execute([$id]);$r=$q->fetch();
 if(!$r||($r['request_kind']??'')!=='Cargo'||!in_array($r['status'],['Approved','Assigned','Confirmed','Ready for Dispatch'],true))throw new RuntimeException('Only approved cargo deliveries awaiting dispatch may be rescheduled.');
 $q=$pdo->prepare('SELECT * FROM trips WHERE reservation_id=? FOR UPDATE');$q->execute([$id]);$trips=$q->fetchAll();
 foreach($trips as $trip)if(!in_array($trip['status'],['Scheduled','Assigned','Confirmed'],true)||!empty($trip['actual_departure']))throw new RuntimeException('This trip has already started dispatch or is closed.');
 $candidate=array_replace($r,['departure_date'=>$dates['pickup']->format('Y-m-d'),'departure_time'=>$dates['pickup']->format('H:i'),'return_date'=>$dates['delivery']->format('Y-m-d'),'return_time'=>$dates['delivery']->format('H:i')]);
 $departure=$dates['pickup']->format('Y-m-d H:i:s');
 foreach(['vehicle_id','driver_id'] as $column){
  $resource=$r['assigned_'.$column];
  if($resource&&assignment_resource_conflict($pdo,$candidate,$column,$resource,$departure))throw new RuntimeException('The assigned '.str_replace('_id','',$column).' has a conflicting schedule or active trip.');
 }
 if($r['assigned_vehicle_id']){
  $options=assignment_options($pdo,$candidate,$departure);$choice=null;
  foreach($options['vehicles'] as $v)if($v['id']===$r['assigned_vehicle_id'])$choice=$v;
  if(!$choice||!$choice['eligible'])throw new RuntimeException('Assigned vehicle is unavailable for this schedule: '.implode('; ',$choice['reasons']??['Vehicle missing']));
  $drivers=array_merge($choice['drivers'],$choice['driver']?[$choice['driver']]:[]);$eligible=false;
  foreach($drivers as $d)if($d['id']===$r['assigned_driver_id']&&$d['reason']==='')$eligible=true;
  if($r['assigned_driver_id']&&!$eligible)throw new RuntimeException('Assigned driver is not eligible for the new schedule.');
 }
 $pdo->prepare('UPDATE reservations SET departure_date=?,departure_time=?,return_date=?,return_time=? WHERE id=?')->execute([$candidate['departure_date'],$candidate['departure_time'],$candidate['return_date'],$candidate['return_time'],$id]);
 $pdo->prepare('UPDATE trips SET scheduled_departure=? WHERE reservation_id=?')->execute([$departure,$id]);
 $history='Delivery rescheduled from '.$r['departure_date'].' '.$r['departure_time'].' (estimated delivery '.($r['return_date']?:'not set').' '.($r['return_time']??'').') to '.$departure.' (estimated delivery '.$dates['delivery']->format('Y-m-d H:i').'). Reason: '.$reason;
 $pdo->prepare('INSERT INTO reservation_events(reservation_id,actor_id,status,reason) VALUES (?,?,?,?)')->execute([$id,$actor['id'],$r['status'],$history]);
 foreach($trips as $trip)$pdo->prepare("INSERT INTO trip_timeline(trip_id,title,event_time,completed,active_step,sort_order) SELECT ?,'Delivery rescheduled',?,1,0,COALESCE(MAX(sort_order),0)+1 FROM trip_timeline WHERE trip_id=?")->execute([$trip['id'],date('Y-m-d H:i:s'),$trip['id']]);
}
