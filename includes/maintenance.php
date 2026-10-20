<?php
require_once __DIR__.'/driver_vehicle_assignment.php';

function maintenance_validate(array $input): array {
 $data=[];
 foreach(['vehicle_id','service_type','priority','scheduled_date','status','notes'] as $key)$data[$key]=trim((string)($input[$key]??''));
 if($data['vehicle_id']==='' || $data['service_type']==='' || strlen($data['service_type'])>200)throw new RuntimeException('Select a vehicle and enter a service type of up to 200 characters.');
 if(!in_array($data['priority'],['Low','Medium','Critical'],true))throw new RuntimeException('Invalid maintenance priority.');
 if(!in_array($data['status'],['Scheduled','In Repair','Completed'],true))throw new RuntimeException('Invalid maintenance status.');
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',$data['scheduled_date']);
 if(!$date || $date->format('Y-m-d')!==$data['scheduled_date'])throw new RuntimeException('Enter a valid scheduled date.');
 $cost=trim((string)($input['estimated_cost']??''));
 if(!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$cost))throw new RuntimeException('Estimated cost must be a non-negative amount with up to two decimal places.');
 $data['estimated_cost']=$cost;
 return $data;
}

/** Caller owns the transaction; locking the vehicle serializes repair and cost updates. */
function maintenance_vehicle(PDO $pdo,string $id): array {
 $q=$pdo->prepare('SELECT * FROM vehicles WHERE id=? FOR UPDATE');$q->execute([$id]);$vehicle=$q->fetch();
 if(!$vehicle || $vehicle['status']==='Retired' || filter_var($vehicle['is_archived']??false,FILTER_VALIDATE_BOOLEAN))throw new RuntimeException('Select an existing, non-retired vehicle.');
 return $vehicle;
}

function maintenance_apply_state(PDO $pdo,array $vehicle,array $order,bool $charge): void {
 if($order['status']==='In Repair' && ($vehicle['status']==='On Trip' || vehicle_has_active_trip($pdo,$vehicle['id'])))throw new RuntimeException('A vehicle on an active trip cannot start repair. Schedule the work order until the vehicle returns.');
 if($charge){
  $q=$pdo->prepare('SELECT id FROM vehicle_cost_ledger WHERE vehicle_id=? ORDER BY id FOR UPDATE');$q->execute([$vehicle['id']]);$ledger=$q->fetchColumn();
  if($ledger===false){
   $pdo->prepare('INSERT INTO vehicle_cost_ledger(vehicle_id,plate_number,model,maintenance_cost,total_cost) VALUES (?,?,?,?,?)')->execute([$vehicle['id'],$vehicle['plate_number'],$vehicle['model'],$order['estimated_cost'],$order['estimated_cost']]);
  }else{
   $pdo->prepare('UPDATE vehicle_cost_ledger SET maintenance_cost=maintenance_cost+?,total_cost=total_cost+? WHERE id=?')->execute([$order['estimated_cost'],$order['estimated_cost'],$ledger]);
  }
  $next=(new DateTimeImmutable('today'))->modify('+'.max(1,(int)fleet_setting('maintenance.interval_days','90')).' days')->format('Y-m-d');
  $pdo->prepare('UPDATE vehicles SET last_maintenance=?,next_maintenance=? WHERE id=?')->execute([date('Y-m-d'),$next,$vehicle['id']]);
 }
 $q=$pdo->prepare("SELECT service_type FROM maintenance_orders WHERE vehicle_id=? AND (status='In Repair' OR (status='Scheduled' AND (notes LIKE 'Driver issue report:%' OR source_trip_id IS NOT NULL))) ORDER BY CASE WHEN status='In Repair' THEN 0 ELSE 1 END,scheduled_date,id LIMIT 1");$q->execute([$vehicle['id']]);$repair=$q->fetchColumn();
 if($repair!==false){
  // An issue reported on a trip must not overwrite the current trip status.
  if(!vehicle_has_active_trip($pdo,$vehicle['id']))$pdo->prepare("UPDATE vehicles SET status='Maintenance',maintenance_status=? WHERE id=?")->execute([$repair.' (Service pending)',$vehicle['id']]);
 }else{
  if($charge)$pdo->prepare("UPDATE vehicles SET maintenance_status='Healthy' WHERE id=?")->execute([$vehicle['id']]);
  // Future routine PMS alone does not take an available vehicle out of service.
  if($charge || $order['status']==='In Repair')refresh_vehicle_operational_status($pdo,$vehicle['id']);
 }
}

function maintenance_create(PDO $pdo,array $input): string {
 $data=maintenance_validate($input);$vehicle=maintenance_vehicle($pdo,$data['vehicle_id']);
 $pdo->exec("SELECT pg_advisory_xact_lock(hashtext('maintenance-work-order-id'))");
 $id=next_sequential_id($pdo,'maintenance_orders','id','WO-'.date('Y').'-');
 $pdo->prepare('INSERT INTO maintenance_orders(id,vehicle_id,service_type,priority,scheduled_date,status,estimated_cost,notes) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$data['vehicle_id'],$data['service_type'],$data['priority'],$data['scheduled_date'],$data['status'],$data['estimated_cost'],$data['notes']]);
 maintenance_apply_state($pdo,$vehicle,$data,$data['status']==='Completed');
 return $id;
}

function maintenance_transition(PDO $pdo,string $id,string $status): void {
 // Lock vehicle before order, consistently with creation.
 $q=$pdo->prepare('SELECT vehicle_id FROM maintenance_orders WHERE id=?');$q->execute([$id]);$vehicleId=$q->fetchColumn();
 if(!$vehicleId)throw new RuntimeException('Work order not found.');
 $vehicle=maintenance_vehicle($pdo,$vehicleId);
 $q=$pdo->prepare('SELECT * FROM maintenance_orders WHERE id=? FOR UPDATE');$q->execute([$id]);$order=$q->fetch();
 if(!$order)throw new RuntimeException('Work order not found.');
 if($order['status']===$status)return;
 $allowed=['Scheduled'=>['In Repair'],'In Repair'=>['Completed'],'Completed'=>[]];
 if(!in_array($status,$allowed[$order['status']]??[],true))throw new RuntimeException('Invalid work order transition. Start repair before completing; completed orders cannot be reopened.');
 $pdo->prepare('UPDATE maintenance_orders SET status=? WHERE id=?')->execute([$status,$id]);
 $order['status']=$status;
 maintenance_apply_state($pdo,$vehicle,$order,$status==='Completed');
}
