<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/includes/bootstrap.php';
require ROOT_PATH.'/includes/maintenance.php';
$pdo=db();$n=0;
function maintenance_check($ok,$message){global $n;if(!$ok)throw new RuntimeException($message);$n++;}
function maintenance_reject(callable $fn,$message){try{$fn();}catch(RuntimeException $e){maintenance_check(true,$message);return;}throw new RuntimeException($message);}
// All data and sequential counters are isolated in connection-local tables.
foreach(['vehicles','drivers','maintenance_orders','trips','reservations','vehicle_cost_ledger','system_settings'] as $table)$pdo->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES INCLUDING IDENTITY)");
$pdo->exec("INSERT INTO drivers(id,name,status) VALUES ('TEST-D','Development Driver','Active')");
$pdo->exec("INSERT INTO vehicles(id,plate_number,type,brand,model,year,capacity,service_category,payload_capacity_kg,status,assigned_driver_id) VALUES ('TEST-V','TEST-V','Light Truck','Isuzu','NLR Closed Body',2026,0,'Cargo',2500,'Assigned','TEST-D')");
$base=['vehicle_id'=>'TEST-V','service_type'=>'Cargo truck PMS','priority'=>'Medium','scheduled_date'=>date('Y-m-d'),'status'=>'Scheduled','estimated_cost'=>'1000.00','notes'=>''];
$status=fn()=>$pdo->query("SELECT status FROM vehicles WHERE id='TEST-V'")->fetchColumn();
$cost=fn()=>(float)$pdo->query("SELECT COALESCE(sum(maintenance_cost),0) FROM vehicle_cost_ledger WHERE vehicle_id='TEST-V'")->fetchColumn();
$pdo->beginTransaction();try {
 $one=maintenance_create($pdo,$base);maintenance_check($status()==='Assigned','Routine scheduled PMS preserves assignment');
 maintenance_transition($pdo,$one,'In Repair');maintenance_check($status()==='Maintenance','Starting repair blocks vehicle');
 $two=maintenance_create($pdo,array_replace($base,['status'=>'In Repair','estimated_cost'=>'500.00']));
 maintenance_transition($pdo,$one,'Completed');maintenance_check($status()==='Maintenance','Other repair keeps vehicle in maintenance');maintenance_check($cost()===1000.0,'First completion creates missing cost ledger');
 maintenance_transition($pdo,$one,'Completed');maintenance_check($cost()===1000.0,'Repeated completion does not double charge');
 maintenance_reject(fn()=>maintenance_transition($pdo,$one,'In Repair'),'Completed order cannot reopen');
 maintenance_transition($pdo,$two,'Completed');maintenance_check($status()==='Assigned','Last completion restores default assignment');maintenance_check($cost()===1500.0,'All costs recorded once');
 maintenance_check($pdo->query("SELECT assigned_driver_id FROM vehicles WHERE id='TEST-V'")->fetchColumn()==='TEST-D','Driver pairing preserved');
 maintenance_create($pdo,array_replace($base,['status'=>'Completed','estimated_cost'=>'250.25']));maintenance_check($cost()===1750.25,'Direct completed creation records cost');
 $pending=maintenance_create($pdo,array_replace($base,['notes'=>'Driver issue report: brake fault']));maintenance_check($status()==='Maintenance','Pending reported issue blocks vehicle');
 maintenance_transition($pdo,$pending,'In Repair');maintenance_transition($pdo,$pending,'Completed');
 $future=maintenance_create($pdo,$base);refresh_vehicle_operational_status($pdo,'TEST-V');maintenance_check($status()==='Assigned','Status refresh ignores routine future PMS');
 maintenance_reject(fn()=>maintenance_transition($pdo,$future,'Completed'),'Scheduled order cannot skip repair');
 $pdo->exec("UPDATE vehicles SET status='On Trip' WHERE id='TEST-V'");
 $pdo->exec('SAVEPOINT active_trip');maintenance_reject(fn()=>maintenance_transition($pdo,$future,'In Repair'),'On-trip vehicle cannot start repair');$pdo->exec('ROLLBACK TO SAVEPOINT active_trip');
 $pdo->exec("UPDATE vehicles SET status='Retired' WHERE id='TEST-V'");maintenance_reject(fn()=>maintenance_create($pdo,$base),'Retired vehicle excluded');
 foreach(['estimated_cost'=>['-1','abc','1.001'],'scheduled_date'=>['2026-02-30'],'priority'=>['Urgent'],'status'=>['Unknown']] as $field=>$values)foreach($values as $value)maintenance_reject(fn()=>maintenance_validate(array_replace($base,[$field=>$value])),'Reject invalid '.$field);
}finally{$pdo->rollBack();}
echo "$n maintenance checks passed; no operational records saved.\n";
