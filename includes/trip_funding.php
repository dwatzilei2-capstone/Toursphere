<?php
require_once __DIR__.'/reservation-assignment.php';
const TRIP_FUNDING_MOCK_DELAY_SECONDS = 5;
function funding_can_manage(): bool { return has_role(['fleet_admin','dispatcher']) && can('dispatch.manage'); }
function funding_can_view(): bool { return has_role(['fleet_admin','dispatcher','fleet_manager']) && can('dispatch.view'); }
function fuel_current_price(PDO $pdo,string $type): ?array {
 $q=$pdo->prepare('SELECT * FROM fuel_price_history WHERE lower(fuel_type)=lower(?) AND effective_date<=CURRENT_DATE ORDER BY effective_date DESC,id DESC LIMIT 1');$q->execute([$type]);return $q->fetch() ?: null;
}
function fuel_supported_types(PDO $pdo): array {
 return $pdo->query("SELECT DISTINCT fuel_type FROM (SELECT fuel_type FROM vehicles UNION SELECT fuel_type FROM vehicle_variants UNION SELECT fuel_type FROM fuel_price_history) f WHERE fuel_type IS NOT NULL AND btrim(fuel_type)<>'' ORDER BY fuel_type")->fetchAll(PDO::FETCH_COLUMN);
}
function fuel_save_price(PDO $pdo,string $type,string $price,string $effective,string $source): void {
 if(!has_role('fleet_admin'))throw new DomainException('Only Admin can update current fuel prices.');
 if(!in_array($type,fuel_supported_types($pdo),true))throw new DomainException('Select a supported fuel type.');
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',$effective);
 if(!$date || $date->format('Y-m-d')!==$effective || $effective>date('Y-m-d'))throw new DomainException('Use a valid effective date on or before today.');
 if(!is_numeric($price) || !is_finite((float)$price) || (float)$price<=0 || (float)$price>100000 || trim($source)==='' || mb_strlen($source)>200)throw new DomainException('Enter a positive price and its source.');
 $pdo->prepare('INSERT INTO fuel_price_history(fuel_type,price_per_liter,effective_date,source,updated_by) VALUES (?,?,?,?,?)')->execute([$type,$price,$effective,trim($source),current_user()['id']]);
}
function funding_trip(PDO $pdo,string $id): ?array {
 $q=$pdo->prepare("SELECT t.*,r.status reservation_status,r.assigned_vehicle_id,r.assigned_driver_id,r.route_distance_km,r.departure_schedule_instance_id,
 r.departure_date,r.departure_time,r.return_date,r.return_time,r.passenger_count,r.required_capacity,r.vehicle_requested,r.customer_id,r.notes,
 v.fuel_type,v.avg_fuel_km,v.plate_number,v.brand,v.model,v.status vehicle_status,d.name driver_name
 FROM trips t JOIN reservations r ON r.id=t.reservation_id LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN drivers d ON d.id=t.driver_id WHERE t.id=?");
 $q->execute([$id]);return $q->fetch() ?: null;
}
function funding_request(PDO $pdo,string $tripId): ?array {
 $q=$pdo->prepare('SELECT f.*,e.estimated_total_cost,e.estimated_fuel_cost,e.estimated_liters,e.distance_km,e.distance_source,e.reference_price,e.fuel_type,e.expected_km_per_liter,e.inputs FROM trip_funding_requests f JOIN trip_cost_estimates e ON e.id=f.estimate_id WHERE f.trip_id=? ORDER BY f.id DESC LIMIT 1');$q->execute([$tripId]);return $q->fetch() ?: null;
}
function funding_inputs(array $t): array {
 return ['trip_id'=>$t['id'],'reservation_id'=>$t['reservation_id'],'vehicle_id'=>$t['vehicle_id'],'driver_id'=>$t['driver_id'],'origin'=>$t['origin'],'destination'=>$t['destination'],
  'scheduled_departure'=>$t['scheduled_departure'],'return_date'=>$t['return_date'],'return_time'=>$t['return_time'],'passengers'=>$t['passengers'],
  'distance_km'=>(float)($t['distance_km']>0?$t['distance_km']:$t['route_distance_km'])];
}
function funding_estimate(PDO $pdo,array $t): array {
 $issues=[]; $distance=(float)($t['distance_km']>0?$t['distance_km']:$t['route_distance_km']);
 $efficiency=preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:km\s*\/\s*l)?\s*$/i',(string)$t['avg_fuel_km'],$m)?(float)$m[1]:0;
 $price=fuel_current_price($pdo,(string)$t['fuel_type']);
 if(!$t['vehicle_id'] || !$t['driver_id'])$issues[]='Assign a vehicle and driver first.';
 if($distance<=0)$issues[]='Route distance unavailable. Prepare the route in the existing AI Route Planner.';
 if($efficiency<=0)$issues[]='Vehicle fuel efficiency data unavailable.';
 if(!$price)$issues[]='Current '.($t['fuel_type'] ?: 'vehicle fuel').' price unavailable. Admin must configure it in Settings → Fuel Prices.';
 $liters=$distance>0 && $efficiency>0 ? $distance/$efficiency : null;
 $fuel=$liters!==null && $price ? round($liters*(float)$price['price_per_liter'],2) : null;
 return ['issues'=>$issues,'distance_km'=>$distance,'distance_source'=>$t['distance_km']>0?'Saved trip route':'Saved reservation route','expected_km_per_liter'=>$efficiency,
 'fuel_type'=>$t['fuel_type'],'price'=>$price,'reference_price'=>$price['price_per_liter']??null,'estimated_liters'=>$liters,'estimated_fuel_cost'=>$fuel,'estimated_total_cost'=>$fuel,
 'toll'=>'Not Included in Estimate','parking'=>'Not Included in Estimate'];
}
function funding_matches(array $request,array $t): bool {
 $saved=json_decode($request['inputs'],true);return $saved==funding_inputs($t);
}
function funding_confirmed(PDO $pdo,array $t): bool {
 $f=funding_request($pdo,$t['id']);return $f && $f['status']==='Funding Confirmed' && funding_matches($f,$t);
}
function funding_readiness(PDO $pdo,array $t): array {
 $checks=['Reservation Approved'=>in_array($t['reservation_status'],['Assigned','Confirmed'],true),
 'Vehicle Assigned'=>!empty($t['vehicle_id']) && $t['vehicle_id']===$t['assigned_vehicle_id'],
 'Driver Assigned'=>!empty($t['driver_id']) && $t['driver_id']===$t['assigned_driver_id']];
 $r=$pdo->prepare('SELECT * FROM reservations WHERE id=?');$r->execute([$t['reservation_id']]);$r=$r->fetch();
 $options=assignment_options($pdo,$r,$t['scheduled_departure']);$choice=null;foreach($options['vehicles'] as $v)if($v['id']===$t['vehicle_id'])$choice=$v;
 $compliance=$t['vehicle_id']?vehicle_operational_compliance($pdo,$t['vehicle_id'],substr($t['scheduled_departure'],0,10)):null;
 $checks['Vehicle Operational']=$choice && !array_filter($choice['reasons'],fn($reason)=>!str_contains($reason,'Driver') && !str_contains($reason,'driver') && !str_contains($reason,'conflict'));
 $checks['Required Vehicle Documents Valid']=$compliance && $compliance['operational'];
 $checks['Driver Eligible']=$choice && $choice['driver'] && $choice['driver']['id']===$t['driver_id'] && !$choice['driver']['reason'];
 $checks['No Blocking Schedule Conflict']=$t['vehicle_id'] && $t['driver_id'] && !assignment_resource_conflict($pdo,$r,'vehicle_id',$t['vehicle_id'],$t['scheduled_departure']) && !assignment_resource_conflict($pdo,$r,'driver_id',$t['driver_id'],$t['scheduled_departure']);
 $checks['Trip Funding Confirmed']=funding_confirmed($pdo,$t);
 try { funding_assert_dispatch($pdo,$t['reservation_id']); } catch(DomainException $e) { $checks['Shared Departure Funding Complete']=false; }
 return ['checks'=>$checks,'ready'=>!in_array(false,array_map('boolval',$checks),true),'reasons'=>$choice['reasons']??['Assignment unavailable']];
}
function funding_comparison_rows(PDO $pdo,string $start,string $end,?string $driverId=null,?string $vehicleId=null,int $limit=100): array {
 $q=$pdo->prepare("SELECT t.id,t.vehicle_id,t.status,t.scheduled_departure,t.origin,t.destination,
 e.estimated_total_cost,e.estimated_fuel_cost,e.estimated_liters,e.reference_price,f.approved_amount,f.status funding_status,
 fuel.liters actual_fuel_logged,fuel.cost actual_fuel_cost,CASE WHEN fuel.liters>0 THEN fuel.cost/fuel.liters END average_price,
 CASE WHEN rh.actual_fuel_verified=1 THEN rh.actual_fuel_liters END verified_consumption,
 CASE WHEN fuel.cost IS NOT NULL OR maintenance.cost IS NOT NULL OR t.toll_fee IS NOT NULL
 THEN COALESCE(fuel.cost,0)+COALESCE(maintenance.cost,0)+COALESCE(t.toll_fee,0) END actual_recorded_cost
 FROM trips t
 LEFT JOIN LATERAL(SELECT * FROM trip_funding_requests WHERE trip_id=t.id AND status='Funding Confirmed' ORDER BY id DESC LIMIT 1) f ON true
 LEFT JOIN trip_cost_estimates e ON e.id=f.estimate_id
 LEFT JOIN LATERAL(SELECT SUM(liters) liters,SUM(total_cost) cost FROM fuel_transactions WHERE trip_id=t.id) fuel ON true
 LEFT JOIN LATERAL(SELECT SUM(estimated_cost) cost FROM maintenance_orders WHERE source_trip_id=t.id AND status='Completed') maintenance ON true
 LEFT JOIN route_history rh ON rh.log_id=t.route_history_id
 WHERE COALESCE(t.actual_arrival,t.scheduled_departure)>=?::date AND COALESCE(t.actual_arrival,t.scheduled_departure)<?::date
 AND (?::varchar IS NULL OR t.driver_id=?::varchar) AND (?::varchar IS NULL OR t.vehicle_id=?::varchar)
 ORDER BY COALESCE(t.actual_arrival,t.scheduled_departure) DESC".($limit>0?' LIMIT '.min($limit,1000):''));
 $q->execute([$start,$end,$driverId,$driverId,$vehicleId,$vehicleId]);return $q->fetchAll();
}
function funding_actual_cost(PDO $pdo,string $tripId): ?float {
 $q=$pdo->prepare("SELECT (SELECT SUM(total_cost) FROM fuel_transactions WHERE trip_id=t.id) fuel,(SELECT SUM(estimated_cost) FROM maintenance_orders WHERE source_trip_id=t.id AND status='Completed') maintenance,t.toll_fee FROM trips t WHERE t.id=?");$q->execute([$tripId]);$r=$q->fetch();
 if(!$r || ($r['fuel']===null && $r['maintenance']===null && $r['toll_fee']===null))return null;
 return (float)($r['fuel']??0)+(float)($r['maintenance']??0)+(float)($r['toll_fee']??0);
}
function funding_money($value,string $missing='Not Recorded'): string { return $value===null? $missing:money($value); }
function funding_audit(PDO $pdo,string $action,array $f): void {
 $actor=$action==='TRIP_FUNDING_CONFIRMED'?null:$f['requested_by'];
 $pdo->prepare('INSERT INTO audit_logs(action,user_id,entity_type,entity_id,details) VALUES (?,?,?,?,?)')->execute([$action,$actor,'trip_funding',$f['trip_id'],json_encode(['request_id'=>$f['id'],'requested_by'=>$f['requested_by'],'finance_source'=>$f['finance_source'],'status'=>$f['status'],'requested_amount'=>$f['requested_amount'],'approved_amount'=>$f['approved_amount']??null])]);
}
function funding_create(PDO $pdo,string $tripId,string $method,string $amount): array {
 if(!funding_can_manage())throw new DomainException('Only Admin and Dispatcher can request Trip Funding.');
 if(fleet_setting('funding.finance_mode','mock')!=='mock')throw new DomainException('The real Finance connector is not configured. Mock approval is disabled.');
 if(!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount<=0 || (float)$amount>999999999)throw new DomainException('Enter a valid requested funding amount.');
 $pdo->beginTransaction();
 try {
  $t=funding_trip($pdo,$tripId);if(!$t)throw new DomainException('Trip not found.');
  $pdo->prepare('SELECT id FROM reservations WHERE id=? FOR UPDATE')->execute([$t['reservation_id']]);
  $pdo->prepare('SELECT id FROM trips WHERE id=? FOR UPDATE')->execute([$tripId]);$t=funding_trip($pdo,$tripId);
  if(!in_array($t['status'],['Assigned','Confirmed','Scheduled'],true) || !in_array($t['reservation_status'],['Assigned','Confirmed'],true))throw new DomainException('Funding requires an approved, assigned trip before dispatch.');
  if($t['vehicle_id']!==$t['assigned_vehicle_id'] || $t['driver_id']!==$t['assigned_driver_id'])throw new DomainException('Trip assignment is inconsistent. Refresh the assignment.');
  $existing=funding_request($pdo,$tripId);
  if($existing && in_array($existing['status'],['Pending Finance Approval','Funding Confirmed'],true)) {
   if(funding_matches($existing,$t)){$pdo->commit();return $existing;}
   if($existing['status']==='Pending Finance Approval')throw new DomainException('A request is still being processed.');
   $pdo->prepare("UPDATE trip_funding_requests SET status='Superseded',response_reason='Trip preparation changed' WHERE id=?")->execute([$existing['id']]);
  }
  $m=$pdo->prepare('SELECT name FROM trip_funding_methods WHERE code=? AND enabled');$m->execute([$method]);$name=$m->fetchColumn();if(!$name)throw new DomainException('Select an enabled funding method.');
  $e=funding_estimate($pdo,$t);if($e['issues'])throw new DomainException(implode(' ',$e['issues']));
  if(round((float)$amount,2)<$e['estimated_total_cost'])throw new DomainException('Requested funding must cover the estimated trip cost.');
  $q=$pdo->prepare('INSERT INTO trip_cost_estimates(trip_id,reservation_id,vehicle_id,driver_id,distance_km,distance_source,expected_km_per_liter,fuel_type,fuel_price_id,reference_price,estimated_liters,estimated_fuel_cost,estimated_total_cost,inputs,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?::jsonb,?) RETURNING id');
  $q->execute([$t['id'],$t['reservation_id'],$t['vehicle_id'],$t['driver_id'],$e['distance_km'],$e['distance_source'],$e['expected_km_per_liter'],$e['fuel_type'],$e['price']['id'],$e['reference_price'],$e['estimated_liters'],$e['estimated_fuel_cost'],$e['estimated_total_cost'],json_encode(funding_inputs($t)),current_user()['id']]);$eid=$q->fetchColumn();
  $q=$pdo->prepare('INSERT INTO trip_funding_requests(trip_id,reservation_id,vehicle_id,driver_id,estimate_id,funding_method,method_name,requested_amount,requested_by) VALUES (?,?,?,?,?,?,?,?,?) RETURNING id');$q->execute([$tripId,$t['reservation_id'],$t['vehicle_id'],$t['driver_id'],$eid,$method,$name,round((float)$amount,2),current_user()['id']]);$id=$q->fetchColumn();
  $f=funding_request($pdo,$tripId);funding_audit($pdo,'TRIP_FUNDING_REQUESTED',$f);$pdo->commit();return $f;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function funding_revise(PDO $pdo,string $tripId): void {
 if(!funding_can_manage())throw new DomainException('Only Admin and Dispatcher can revise funding.');
 $pdo->beginTransaction();try {
  $t=funding_trip($pdo,$tripId);if(!$t)throw new DomainException('Trip not found.');
  $pdo->prepare('SELECT id FROM reservations WHERE id=? FOR UPDATE')->execute([$t['reservation_id']]);
  $pdo->prepare('SELECT id FROM trips WHERE id=? FOR UPDATE')->execute([$tripId]);$t=funding_trip($pdo,$tripId);
  if(!in_array($t['status'],['Assigned','Confirmed','Scheduled'],true) || !in_array($t['reservation_status'],['Assigned','Confirmed'],true))throw new DomainException('Funding can only be revised before dispatch.');
  $f=funding_request($pdo,$tripId);if(!$f || $f['status']==='Pending Finance Approval')throw new DomainException('Wait for the pending response before revising.');
  $pdo->prepare("UPDATE trip_funding_requests SET status='Superseded',response_reason='Revised by Fleet before dispatch' WHERE id=? AND status='Funding Confirmed'")->execute([$f['id']]);
  funding_audit($pdo,'TRIP_FUNDING_REVISED',funding_request($pdo,$tripId));$pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
// Backend-only adapter. No Fleet approval endpoint exists. Deadline is set by PostgreSQL.
function funding_mock_response(PDO $pdo,int $id): void {
 if(fleet_setting('funding.finance_mode','mock')!=='mock')return;
 $q=$pdo->prepare("SELECT * FROM trip_funding_requests WHERE id=? AND status='Pending Finance Approval'");$q->execute([$id]);$f=$q->fetch();if(!$f)return;
 audit_set_actor($pdo,null);
 $pdo->beginTransaction();
 try {
  $pdo->prepare('SELECT id FROM reservations WHERE id=? FOR UPDATE')->execute([$f['reservation_id']]);
  $pdo->prepare('SELECT id FROM trips WHERE id=? FOR UPDATE')->execute([$f['trip_id']]);
  $q=$pdo->prepare("SELECT *,clock_timestamp()>=approval_due_at due FROM trip_funding_requests WHERE id=? FOR UPDATE");$q->execute([$id]);$f=$q->fetch();
  if($f['status']!=='Pending Finance Approval' || !filter_var($f['due'],FILTER_VALIDATE_BOOLEAN)){$pdo->commit();return;}
  $t=funding_trip($pdo,$f['trip_id']);$full=funding_request($pdo,$f['trip_id']);
  if(!in_array($t['reservation_status'],['Assigned','Confirmed'],true) || !in_array($t['status'],['Assigned','Confirmed','Scheduled'],true) || !funding_matches($full,$t)) {
   $pdo->prepare("UPDATE trip_funding_requests SET status=?,response_reason='Trip preparation changed during approval',response_at=clock_timestamp() WHERE id=?")->execute([$t['reservation_status']==='Cancelled'?'Cancelled':'Superseded',$id]);
  }else {
   $pdo->prepare("UPDATE trip_funding_requests SET status='Funding Confirmed',approval_status='APPROVED',approved_amount=requested_amount,finance_funding_id=?,approved_at=clock_timestamp(),response_at=clock_timestamp() WHERE id=?")->execute(['MOCK-'.$id,$id]);
   $f=funding_request($pdo,$f['trip_id']);funding_audit($pdo,'TRIP_FUNDING_CONFIRMED',$f);
   $pdo->prepare("INSERT INTO notifications(user_id,title,body,type,category,target) VALUES (?,?,?,'success','dispatch',?)")->execute([$f['requested_by'],'Trip Funding Confirmed','Mock Finance — Test Mode approved funding for '.$f['trip_id'].'. Dispatch requirements will be revalidated.','trip-details:'.$f['trip_id']]);
  }
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 finally { audit_set_actor($pdo,current_user()); }
}
function funding_assert_dispatch(PDO $pdo,string $reservationId): void {
 $q=$pdo->prepare("SELECT t.id FROM trips t JOIN reservations r ON r.id=t.reservation_id WHERE r.id=? OR (r.departure_schedule_instance_id=(SELECT departure_schedule_instance_id FROM reservations WHERE id=?) AND r.status IN ('Assigned','Confirmed')) ORDER BY t.id");$q->execute([$reservationId,$reservationId]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
 if(!$ids)throw new DomainException('Assign a trip before requesting funding.');
 foreach($ids as $id){$t=funding_trip($pdo,$id);if(!funding_confirmed($pdo,$t))throw new DomainException('No Confirmed Trip Funding = Cannot Dispatch. Prepare funding for trip '.$id.'.');}
}
function funding_payload(PDO $pdo,array $t): array {
 $pretrip=in_array($t['status'],['Scheduled','Assigned','Confirmed'],true);
 $f=funding_request($pdo,$t['id']);$e=$f && in_array($f['status'],['Pending Finance Approval','Funding Confirmed'],true) && (!$pretrip || funding_matches($f,$t))?$f:funding_estimate($pdo,$t);
 $status=$f?$f['status']:'Not Requested';if($f && $status==='Funding Confirmed' && $pretrip && !funding_matches($f,$t))$status='Superseded';
 return ['trip'=>['id'=>$t['id'],'reservation_id'=>$t['reservation_id'],'driver'=>$t['driver_name'],'vehicle'=>$t['vehicle_id'].' — '.trim($t['brand'].' '.$t['model']),'vehicle_id'=>$t['vehicle_id'],'driver_id'=>$t['driver_id'],'departure'=>$t['scheduled_departure'],'notes'=>$t['notes'],'status'=>$t['status'],'origin'=>$t['origin'],'destination'=>$t['destination']],
 'estimate'=>$e,'request'=>$f,'status'=>$status,'methods'=>$pdo->query('SELECT code,name FROM trip_funding_methods WHERE enabled ORDER BY code')->fetchAll(),'can_manage'=>funding_can_manage(),
 'readiness'=>funding_readiness($pdo,$t),'csrf'=>$_SESSION['funding_csrf']??=bin2hex(random_bytes(32)), 'assignment_csrf'=>$_SESSION['assignment_csrf']??=bin2hex(random_bytes(32))];
}

/** Read saved preparation values without replacing them with actual expenses. */
function funding_trip_summary(PDO $pdo,array $trip): array {
 $request=funding_request($pdo,$trip['id']);
 $pretrip=in_array($trip['status'],['Scheduled','Assigned','Confirmed'],true);
 $snapshot=$request && in_array($request['status'],['Pending Finance Approval','Funding Confirmed'],true) && (!$pretrip || funding_matches($request,$trip)) ? $request : null;
 $distance=(float)($trip['distance_km']??0);
 if($distance<=0)$distance=(float)($snapshot['distance_km']??$trip['route_distance_km']??0);
 $fuel=$snapshot && $snapshot['estimated_liters']!==null ? number_format((float)$snapshot['estimated_liters'],2).' L' : trim((string)($trip['fuel_estimate']??''));
 return ['distance'=>$distance>0?number_format($distance,1).' km':'Not Prepared','fuel'=>$fuel!=='' && $fuel!=='—'?$fuel:'Not Estimated'];
}
