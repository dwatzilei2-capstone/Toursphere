<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/reservation-assignment.php';
$pdo=db(); $checks=0;
function assignment_check(bool $condition,string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; }
foreach (['vehicles','drivers','reservations','trips','maintenance_orders','vehicle_documents'] as $table) $pdo->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS INCLUDING CONSTRAINTS)");
$pdo->beginTransaction();
try {
    $pdo->exec("INSERT INTO drivers(id,name,status,license_class,license_expiration) VALUES ('D1','Designated driver','Active','Class 3','2099-12-31'),('D2','Unassigned driver','Active','Class 2','2099-12-31'),('D3','Other designated driver','Assigned','Class 3','2099-12-31')");
    $pdo->exec("INSERT INTO vehicles(id,plate_number,type,brand,model,year,capacity,assigned_driver_id) VALUES ('V14','TEST14','Van','Test','Best fit',2026,14,'D1'),('V45','TEST45','Van','Test','Large',2026,45,'D3'),('VNONE','TESTNONE','Van','Test','No driver',2026,14,NULL)");
    $doc=$pdo->prepare("INSERT INTO vehicle_documents(id,vehicle_id,document_type,extracted_data,extraction_status,stored_filename,original_filename,mime_type,file_size) VALUES (md5(random()::text),?,?,?,'extracted',md5(random()::text),'test.pdf','application/pdf',1)");
    foreach(['V14','V45','VNONE'] as $id) foreach(['registration','insurance','ltfrb_permit'] as $type) $doc->execute([$id,$type,json_encode(['vehicle_match_status'=>'MATCHED','document_type_status'=>'MATCHED','expiration_date'=>'2099-12-31'])]);
    $r=['id'=>'RTEST','passenger_count'=>13,'required_capacity'=>14,'vehicle_requested'=>'Van','departure_schedule_instance_id'=>null,'return_date'=>null,'return_time'=>null];
    $departure='2030-01-10 07:00:00';
    $options=fn()=>assignment_options($pdo,$r,$departure);
    $find=function(string $id) use ($options):array { foreach($options()['vehicles'] as $v) if($v['id']===$id)return $v; throw new RuntimeException('Missing fixture'); };
    assignment_check($options()['recommended_id']==='V14','Best capacity fit with designated driver ranks first');
    assignment_check($find('V14')['driver']['id']==='D1','Designated driver automatically resolved');
    assignment_check($find('VNONE')['status']==='Driver Required','Unassigned compatible driver permits pairing');
    assignment_check($find('VNONE')['drivers'][0]['id']==='D2','Unassigned driver ranks ahead of reassignment');
    assignment_check(!assignment_driver_class_matches(['type'=>'Bus','capacity'=>45],['license_class'=>'Class 2']),'Class 2 driver blocked from bus');
    assignment_check(assignment_driver_class_matches(['type'=>'Van','capacity'=>14],['license_class'=>'Class 3']),'Class 3 includes lighter vehicles');
    $pdo->exec("UPDATE drivers SET status='Off Duty' WHERE id='D2'");
    assignment_check($find('VNONE')['status']==='Reassignment Required','Fallback requires controlled reassignment');
    assignment_check($find('VNONE')['drivers'][0]['reassignment'],'Previous designation supplied for confirmation');
    $pdo->exec("UPDATE drivers SET status='On Trip' WHERE id='D1'");
    assignment_check(!$find('V14')['eligible'],'On-trip designated driver blocks vehicle');
    assignment_check(!in_array('D1',array_column($find('VNONE')['drivers'],'id'),true),'On-trip driver excluded from reassignment');
    $pdo->exec("UPDATE drivers SET status='Active',license_class='Class 1' WHERE id='D1'");
    assignment_check(!$find('V14')['eligible'],'Existing incompatible pairing flagged');
    $pdo->exec("UPDATE drivers SET license_class='Class 3' WHERE id='D1'; UPDATE vehicles SET status='Maintenance' WHERE id='V14'");
    assignment_check(!$find('V14')['eligible'],'Maintenance visible but blocked');
    $pdo->exec("UPDATE vehicles SET status='Retired' WHERE id='V14'");
    assignment_check(!$find('V14')['eligible'],'Retired visible but blocked');
    $pdo->exec("UPDATE vehicles SET status='Inactive' WHERE id='V14'");
    assignment_check(!$find('V14')['eligible'],'Inactive visible but blocked');
    $pdo->exec("UPDATE vehicles SET status='Available',capacity=12 WHERE id='V14'");
    assignment_check(in_array('Insufficient capacity',$find('V14')['reasons'],true),'Minimum capacity enforced beyond passenger count');
    $pdo->exec("UPDATE vehicles SET capacity=14,type='Bus' WHERE id='V14'");
    assignment_check(in_array('Vehicle type incompatible',$find('V14')['reasons'],true),'Required type enforced');
    $pdo->exec("UPDATE vehicles SET type='Van' WHERE id='V14'; DELETE FROM vehicle_documents WHERE vehicle_id='V14' AND document_type='insurance'");
    assignment_check(!$find('V14')['eligible'],'Missing document enforced');
    $doc->execute(['V14','insurance',json_encode(['vehicle_match_status'=>'MATCHED','document_type_status'=>'MATCHED','expiration_date'=>'2029-01-01'])]);
    assignment_check(!$find('V14')['eligible'],'Expired insurance enforced at target departure');
    $pdo->exec("UPDATE vehicle_documents SET extracted_data=jsonb_set(extracted_data::jsonb,'{expiration_date}','\"2099-12-31\"') WHERE vehicle_id='V14'");
    $pdo->exec("INSERT INTO trips(id,reservation_id,vehicle_id,driver_id,origin,destination,scheduled_departure,status) VALUES ('TTEST','OTHER','V14','D1','A','B','2030-01-10 07:00','Assigned')");
    assignment_check(!$find('V14')['eligible'],'Trip schedule conflict enforced');
    assignment_check(assignment_options($pdo,$r,'2030-01-20 07:00:00')['recommended_id']==='V14','Departure change recalculates recommendation');
    $pdo->exec("UPDATE trips SET status='Dispatched',scheduled_departure='2030-01-01'");
    assignment_check(!$find('V14')['eligible'],'Dispatched trip blocks regardless of departure');
    $pdo->exec("UPDATE trips SET status='Completed'; UPDATE vehicles SET assigned_driver_id='D1' WHERE id='VNONE'");
    assignment_check(!$find('V14')['eligible'],'Duplicate designation flagged');
    $pdo->exec("UPDATE vehicles SET assigned_driver_id=NULL WHERE id='VNONE'; UPDATE drivers SET status='Off Duty'");
    assignment_check($options()['recommended_id']===null,'No-valid-recommendation state');
    assignment_check(count($options()['vehicles'])===3,'All unavailable vehicles retained');
    $pdo->exec("UPDATE drivers SET status='Active'");
    $v=$pdo->query("SELECT * FROM vehicles WHERE id='V14'")->fetch();
    try { assignment_confirm_pairing($pdo,$v,'D2',$find('V14'),'assign',''); throw new RuntimeException('Expected mismatch rejection'); }
    catch(RuntimeException $e) { assignment_check(str_contains($e->getMessage(),'designated driver'),'Mismatched reservation driver rejected'); }
    assignment_check(assignment_confirm_pairing($pdo,$v,'D1',$find('V14'),'assign','')===null,'Existing designation inherited without mutation');
    $empty=$pdo->query("SELECT * FROM vehicles WHERE id='VNONE'")->fetch();
    assignment_confirm_pairing($pdo,$empty,'D2',$find('VNONE'),'assign','');
    assignment_check($pdo->query("SELECT assigned_driver_id FROM vehicles WHERE id='VNONE'")->fetchColumn()==='D2','Unassigned pairing updates authoritative vehicle');
    $pdo->exec("UPDATE vehicles SET assigned_driver_id=NULL WHERE id='VNONE'");
    try { assignment_confirm_pairing($pdo,$empty,'D3',$find('VNONE'),'assign',''); throw new RuntimeException('Expected reassignment rejection'); }
    catch(RuntimeException $e) { assignment_check(str_contains($e->getMessage(),'Explicit confirmation'),'Silent reassignment rejected'); }
    $pdo->exec('SAVEPOINT pairing_test');
    assignment_confirm_pairing($pdo,$empty,'D3',$find('VNONE'),'assign','V45');
    assignment_check($pdo->query("SELECT assigned_driver_id FROM vehicles WHERE id='VNONE'")->fetchColumn()==='D3','Confirmed reassignment sets new designation');
    assignment_check(!$pdo->query("SELECT assigned_driver_id FROM vehicles WHERE id='V45'")->fetchColumn(),'Confirmed reassignment clears old designation');
    $pdo->exec('ROLLBACK TO SAVEPOINT pairing_test');
    assignment_check($pdo->query("SELECT assigned_driver_id FROM vehicles WHERE id='V45'")->fetchColumn()==='D3','Failed transaction restores old pairing');
    assignment_check(!$pdo->query("SELECT assigned_driver_id FROM vehicles WHERE id='VNONE'")->fetchColumn(),'Failed transaction removes partial new pairing');
    $second=new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',DB_HOST,DB_PORT,DB_NAME,DB_SSLMODE),DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SELECT pg_advisory_xact_lock(hashtext('fleet-designated-assignment'))");
    assignment_check(!$second->query("SELECT pg_try_advisory_xact_lock(hashtext('fleet-designated-assignment'))")->fetchColumn(),'Concurrent assignment waits for first transaction');
    $pdo->rollBack();
    assignment_check((bool)$second->query("SELECT pg_try_advisory_xact_lock(hashtext('fleet-designated-assignment'))")->fetchColumn(),'Transaction completion releases concurrent assignment lock');
    echo "$checks isolated database assignment checks passed.\n";
} catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
