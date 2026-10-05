<?php
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/includes/bootstrap.php';
require ROOT_PATH.'/includes/reports.php';
require ROOT_PATH.'/includes/report_exports.php';
$passed=0; $tokens=[];
function report_check(bool $ok,string $message): void { global $passed; if(!$ok) throw new RuntimeException($message); $passed++; }
function report_test_role(string $role,array $permissions=['reports.view','dispatch.view']): void { global $current_user,$current_permissions; $current_user=['id'=>1,'role_code'=>$role,'role_name'=>'Test Role','name'=>'Report Test']; $current_permissions=$permissions; }
foreach(['fleet_admin'=>6,'fleet_manager'=>6,'dispatcher'=>2,'driver'=>0,'staff'=>0,'customer'=>0] as $role=>$count) { report_test_role($role); report_check(count(reports_allowed())===$count,'Role categories: '.$role); }
report_test_role('dispatcher',['reports.view']); report_check(!reports_allowed(),'Existing dispatch permission remains required');
report_test_role('fleet_manager',[]); report_check(!reports_allowed(),'Stricter manager permission respected');
$today=new DateTimeImmutable('2026-10-05');
foreach(['today'=>['2026-10-05','2026-10-05'],'week'=>['2026-10-05','2026-10-11'],'month'=>['2026-10-01','2026-10-31'],'previous_month'=>['2026-09-01','2026-09-30'],'quarter'=>['2026-10-01','2026-12-31'],'year'=>['2026-01-01','2026-12-31']] as $period=>$dates) { $r=reports_period(['period'=>$period],$today); report_check([$r['from'],$r['to']]===$dates,'Period '.$period); }
foreach([['period'=>'evil'],['period'=>'custom','from'=>'2026-02-30','to'=>'2026-03-01'],['period'=>'custom','from'=>'2026-09-30','to'=>'2026-09-01'],['period'=>'custom','from'=>'2000-01-01','to'=>'2026-01-01']] as $input) { try { reports_period($input,$today); throw new RuntimeException('Invalid date accepted'); } catch(DomainException $e) { report_check(true,'Invalid period rejected'); } }
report_test_role('fleet_admin'); $pdo=db();
// Temporary tables shadow production tables on this connection; no operational records change.
foreach(['trips','vehicles','reservations','drivers','fuel_transactions','maintenance_orders','driver_ratings','route_history','ai_route_candidates_log','vehicle_documents'] as $table) $pdo->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS INCLUDING CONSTRAINTS)");
$pdo->exec("INSERT INTO drivers(id,name) VALUES ('DRV-00001','José & Sons')");
$pdo->exec("INSERT INTO vehicles(id,plate_number,type,brand,model,year,capacity,status,is_archived,retired_at,retirement_reason) VALUES ('00001','AAA-0001','Van','Toyota','Hiace',2020,12,'Retired',true,'2026-10-01','Sold/Disposed'),('00002','AAA-0002','Van','Toyota','Hiace',2020,12,'Available',false,NULL,NULL)");
$pdo->exec("INSERT INTO reservations(id,client_name,passenger_count,origin,destination,departure_date,status,total_booking_fare,fare_status) VALUES ('RES-00001','José & Co.',4,'Terminal A','Terminal B','2026-09-15','Completed',2000,'Confirmed'),('RES-CANCEL','Cancelled Customer',2,'A','B','2026-09-16','Cancelled',NULL,'Pending')");
$stmt=$pdo->prepare("INSERT INTO trips(id,reservation_id,origin,destination,vehicle_id,driver_id,passengers,status,scheduled_departure,actual_departure,actual_arrival,distance_km,toll_fee,is_archived,archived_at) VALUES (?,'RES-00001','Terminal A','Terminal B','00001','DRV-00001',4,'Completed','2026-09-15 08:00','2026-09-15 08:10','2026-09-15 10:00',10,5,true,'2026-10-02')");
for($i=1;$i<=35;$i++) $stmt->execute(['TRIP-'.str_pad((string)$i,5,'0',STR_PAD_LEFT)]);
$pdo->exec("INSERT INTO trips(id,origin,destination,vehicle_id,driver_id,status,scheduled_departure,completion_notes) VALUES ('TRIP-CANCEL','A','B','00001','DRV-00001','Cancelled','2026-09-16','Customer cancelled'),('TRIP-INCOMPLETE','A','B','00001','DRV-00001','Incomplete','2026-09-17','Breakdown'),('TRIP-OUTSIDE','A','B','00001','DRV-00001','Completed','2026-10-01','Outside')");
$pdo->exec("INSERT INTO fuel_transactions(id,vehicle_id,driver_id,trip_id,transaction_date,liters,price_per_liter,total_cost,station,receipt_no) VALUES ('FUEL-00001','00001','DRV-00001','TRIP-00001','2026-09-15',10,60,600,'=Dangerous & Station','000001')");
$pdo->exec("INSERT INTO maintenance_orders(id,vehicle_id,source_trip_id,service_type,scheduled_date,status,estimated_cost) VALUES ('WO-00001','00001','TRIP-00001','Service','2026-09-15','Completed',100)");
$pdo->exec("INSERT INTO driver_ratings(id,reservation_id,customer_id,trip_id,driver_id,stars,feedback) VALUES (1,'RES-00001',1,'TRIP-00001','DRV-00001',4,'Actual review')");
$input=['period'=>'custom','from'=>'2026-09-01','to'=>'2026-09-30'];
try {
 foreach(['fleet','reservations','trips','fuel','drivers','route'] as $type) {
    $m=reports_get($type,$input,true); $tokens[]=$m['token']; $rows=iterator_to_array(reports_rows($m,'records'));
    $metrics=array_column($m['metrics'],'value','label');
    foreach($m['sections'] as $s) { $covered=array_unique(array_merge(...$s['pdf_groups'])); report_check(!array_diff(array_keys($s['columns']),$covered),'PDF contains every declared column: '.$type); }
    if($type==='trips') {
        report_check(reports_filename($m,'xlsx')==='TourSphere_Trip_Operations_2026-09-01_to_2026-09-30.xlsx','Custom filenames retain both boundaries');
        report_check(count($rows)===37 && $metrics['Archived trips included']===35.0,'September completed, cancelled, incomplete and October-archived records included');
        report_check(count(iterator_to_array(reports_rows($m,'records',2)))===17,'Preview pagination does not change totals');
        report_check($metrics['Completed']===35.0 && $metrics['Completed distance (km)']===350.0,'Complete trip summary');
        report_check($rows[0]['vehicle_id']==='00001','Leading-zero retired vehicle relationship retained');
        $cached=reports_get($type,$input); report_check($cached['token']===$m['token'],'Preview/export cached same snapshot');
        $pdo->exec("UPDATE trips SET distance_km=11 WHERE id='TRIP-00001'");
        report_check(reports_get($type,$input)['metrics']==$m['metrics'],'Snapshot stable after source change');
        $new=reports_get($type,$input,true); $tokens[]=$new['token']; report_check($new['metrics'][6]['value']===351.0,'Refresh sees changes');
        reports_export_pdf($m,ROOT_PATH.'/tmp/reports-validation/multipage.pdf');
    }
    if($type==='fleet') { report_check($metrics['Available — current']===1.0 && $metrics['Retired — current']===1.0,'Retired not available'); report_check($rows[0]['completed']===35 && $rows[0]['retired_at']!==null,'Historical utilization and retirement metadata'); }
    if($type==='reservations') report_check(count($rows)===2,'Multi-trip booking counted once');
    if($type==='fuel') { report_check(abs($metrics['TCAO operational cost']-875)<0.001 && $metrics['Confirmed booking revenue']===2000.0,'Costs and once-only revenue follow TCAO'); report_check(abs(array_sum(array_column($rows,'amount'))-875)<0.001,'Detailed cost ledger reconciles'); }
    if($type==='drivers') report_check(abs($rows[0]['completion_rate']-35/37)<0.00001 && $rows[0]['punctuality']==1.0 && $rows[0]['rating']==4.0,'Measured completion, punctuality and actual rating');
    if($type==='route') report_check(!$rows && $metrics['Saved plans']===0.0,'Empty report stays empty');
    if(in_array($type,['fleet','fuel'],true)) { file_put_contents(ROOT_PATH.'/tmp/reports-validation/fixture-'.$type.'.json',json_encode($m,JSON_UNESCAPED_UNICODE)); foreach(['xlsx','pdf','csv'] as $format) ('reports_export_'.$format)($m,ROOT_PATH.'/tmp/reports-validation/fixture-'.$type.'.'.$format); }
 }
} finally { /* Retain private snapshots only for follow-up file validation; TTL cleanup removes them. */ }
echo "$passed reporting periods, permissions, historical data, formulas, pagination and snapshot checks passed. No operational records changed.\n";
