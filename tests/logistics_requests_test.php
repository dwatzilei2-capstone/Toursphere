<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/includes/bootstrap.php';
require ROOT_PATH.'/includes/logistics_requests.php';
require ROOT_PATH.'/includes/reservation-assignment.php';
require ROOT_PATH.'/includes/cargo_availability.php';
$pdo=db();$n=0;
function logistics_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function logistics_reject(callable $fn,$label){try{$fn();}catch(RuntimeException|DomainException $e){logistics_check(true,$label);return;}throw new RuntimeException($label);}
// Isolate every table, including identities and sequential ID counters, from operational data.
$tables=$pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $table)$pdo->exec('CREATE TEMP TABLE "'.$table.'" (LIKE public."'.$table.'" INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES INCLUDING IDENTITY)');
foreach($pdo->query("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='public' AND column_default LIKE 'nextval(%'")->fetchAll() as $serial){
 $sequence='isolated_'.$serial['table_name'].'_'.$serial['column_name'];
 $pdo->exec('CREATE TEMP SEQUENCE "'.$sequence.'" START 1000000');
 $pdo->exec('ALTER TABLE "'.$serial['table_name'].'" ALTER COLUMN "'.$serial['column_name'].'" SET DEFAULT nextval(\'pg_temp."'.$sequence.'"\')');
}
foreach(['system_settings','roles','permissions','role_permissions','users'] as $table)$pdo->exec('INSERT INTO "'.$table.'" SELECT * FROM public."'.$table.'"');
$actor=['id'=>1,'name'=>'Test Requester','role_code'=>'customer'];
$input=['request_purpose'=>'Cargo Transport','cargo_type'=>'Packaged goods','cargo_weight_kg'=>'1000.000','cargo_volume_m3'=>'5.000','cargo_quantity'=>'10','origin'=>'Manila Warehouse','destination'=>'Batangas Depot','departure_date'=>date('Y-m-d',strtotime('+2 days')),'departure_time'=>'09:00','contact_person'=>'Test Contact','contact_phone'=>'09170000000','special_handling'=>'Keep dry','notes'=>''];
$id=logistics_create_mock_request($pdo,$input+['source_system'=>'Fake','integration_mode'=>'API','assigned_vehicle_id'=>'HACK','customer_id'=>999,'status'=>'Approved'],$actor);
$q=$pdo->prepare('SELECT * FROM reservations WHERE id=?');$q->execute([$id]);$r=$q->fetch();
logistics_check($r['request_kind']==='Cargo'&&$r['passenger_count']===0,'Cargo request uses no passenger demand');
logistics_check($r['source_system']==='Logistics 1'&&$r['integration_mode']==='Mock','Server sets honest mock source');
logistics_check((int)$r['customer_id']===1&&$r['status']==='Pending Approval','Owner and review state cannot be forged');
logistics_check(!$r['assigned_vehicle_id']&&!$r['assigned_driver_id'],'Requester cannot allocate resources');
logistics_check(!$r['departure_schedule_instance_id']&&$r['total_booking_fare']===null,'No passenger schedules or fares');
logistics_check((int)$pdo->query('SELECT count(*) FROM reservation_events')->fetchColumn()===1,'Submission records event history');
logistics_reject(fn()=>logistics_create_mock_request($pdo,$input,['id'=>1,'name'=>'Admin','role_code'=>'fleet_admin']),'Role is enforced');
foreach(['cargo_weight_kg'=>['0','-1','abc','1.0001'],'cargo_volume_m3'=>['0','-1'],'cargo_quantity'=>['0','1.5'],'departure_date'=>['2000-01-01','2026-02-30'],'request_purpose'=>['Tour'],'origin'=>['',str_repeat('X',201)]] as $key=>$values)foreach($values as $value)logistics_reject(fn()=>logistics_request_validate(array_replace($input,[$key=>$value])),'Reject '.$key);
$vehicle=['service_category'=>'Cargo','payload_capacity_kg'=>1000,'cargo_volume_m3'=>5];
logistics_check(logistics_vehicle_issue($r,$vehicle)==='','Exact weight and volume boundary qualifies');
logistics_check(logistics_vehicle_issue($r,array_replace($vehicle,['service_category'=>'Passenger']))!=='','Passenger vehicle rejected');
logistics_check(logistics_vehicle_issue($r,array_replace($vehicle,['payload_capacity_kg'=>999]))!=='','Overweight load rejected');
logistics_check(logistics_vehicle_issue($r,array_replace($vehicle,['cargo_volume_m3'=>4]))!=='','Oversized load rejected');
$role=$pdo->query("SELECT name FROM roles WHERE code='customer'")->fetchColumn();logistics_check($role==='Logistics 1 Requester','Existing role renamed without changing authentication code');
$permissions=$pdo->query("SELECT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id JOIN roles r ON r.id=rp.role_id WHERE r.code='customer'")->fetchAll(PDO::FETCH_COLUMN);
logistics_check(in_array('logistics.requests.own',$permissions,true)&&!array_intersect(['dispatch.manage','dispatch.view','vehicles.assign'],$permissions),'Requester permissions isolated');
// Real chooser rules, operating on connection-local data.
$pdo->exec("INSERT INTO drivers(id,name,license_no,status,license_type,authorized_dl_codes,license_verification_status,license_expiration,license_document_filename,license_verified_by,license_verified_at,license_verification_notes) VALUES ('D-CARGO','Test Driver','TEST-LICENSE','Active','Professional','[\"C\"]','Verified','2099-12-31','test.pdf',1,CURRENT_TIMESTAMP,'Temporary test evidence only')");
$pdo->exec("INSERT INTO vehicles(id,plate_number,type,brand,model,year,capacity,status,service_category,payload_capacity_kg,cargo_volume_m3) VALUES ('V-CARGO','TEST-CARGO','Heavy Truck','Isuzu','Cargo Truck',2026,0,'Available','Cargo',5000,30)");
$doc=$pdo->prepare("INSERT INTO vehicle_documents(id,vehicle_id,document_type,extracted_data,extraction_status,stored_filename,original_filename,mime_type,file_size) VALUES (md5(random()::text),'V-CARGO',?,?,'extracted',md5(random()::text),'test.pdf','application/pdf',1)");
foreach(['registration','insurance','ltfrb_permit'] as $type)$doc->execute([$type,json_encode(['vehicle_match_status'=>'MATCHED','document_type_status'=>'MATCHED','expiration_date'=>'2099-12-31'])]);
$options=assignment_options($pdo,$r,$r['departure_date'].' '.$r['departure_time']);logistics_check($options['recommended_id']==='V-CARGO','Cargo vehicle and verified driver are eligible without passenger seats');
$availability=cargo_reservation_availability($pdo,$r);
logistics_check($availability['vehicles']['available_count']===1 && $availability['drivers']['available_count']===1,'Detailed cargo availability includes vehicle and driver counts');
logistics_check(str_contains($availability['vehicles']['available_items'][0],'kg payload') && str_contains($availability['drivers']['available_items'][0],'Test Driver'),'Detailed cargo lists contain payload, volume and driver name');
$duplicate=$options;$duplicate['vehicles'][]=array_replace($options['vehicles'][0],['id'=>'SECOND-CARGO','plate'=>'SECOND-CARGO']);
logistics_check(cargo_reservation_availability($pdo,$r,$duplicate)['drivers']['available_count']===1,'Driver is counted once across compatible vehicles');
$pdo->exec("UPDATE vehicles SET assigned_driver_id='D-CARGO' WHERE id='V-CARGO'");
$pdo->exec("INSERT INTO drivers(id,name,license_no,status,license_type,authorized_dl_codes,license_verification_status,license_expiration,license_document_filename,license_verified_by,license_verified_at,license_verification_notes) VALUES ('D-FREE','Unassigned Active Driver','TEST-FREE','Active','Professional','[\"C\"]','Verified','2099-12-31','test.pdf',1,CURRENT_TIMESTAMP,'Temporary test evidence only')");
$independent=cargo_reservation_availability($pdo,$r);
logistics_check($independent['drivers']['available_count']===2 && in_array('Unassigned Active Driver',$independent['drivers']['available_items'],true),'Unassigned eligible Active driver remains available when vehicle has a designated driver');
$chooser=assignment_options($pdo,$r,$r['departure_date'].' '.$r['departure_time']);
logistics_check($chooser['vehicles'][0]['driver']['id']==='D-CARGO' && $chooser['vehicles'][0]['drivers']===[],'Independent availability does not change designated assignment rules');
$pdo->exec("DELETE FROM drivers WHERE id='D-FREE'; UPDATE vehicles SET assigned_driver_id=NULL WHERE id='V-CARGO'");
$pdo->exec("UPDATE drivers SET authorized_dl_codes='[]' WHERE id='D-CARGO'");
$unlicensed=cargo_reservation_availability($pdo,$r);
logistics_check($unlicensed['drivers']['available_count']===0 && count($unlicensed['drivers']['blockers'])>0,'Existing license restrictions produce detailed driver blockers');
$pdo->exec("UPDATE drivers SET authorized_dl_codes='[\"C\"]' WHERE id='D-CARGO'");
$pdo->prepare("INSERT INTO trips(id,origin,destination,vehicle_id,driver_id,status,scheduled_departure,passengers) VALUES('AVAIL-CONFLICT','A','B','V-CARGO','D-CARGO','Dispatched',?,0)")->execute([$r['departure_date'].' '.$r['departure_time']]);
$conflicted=cargo_reservation_availability($pdo,$r);
logistics_check($conflicted['vehicles']['available_count']===0 && $conflicted['drivers']['available_count']===0 && count($conflicted['drivers']['blockers'])>0,'Active schedule conflict blocks both resources with reasons');
$pdo->exec("DELETE FROM trips WHERE id='AVAIL-CONFLICT'");
$pdo->exec("UPDATE vehicles SET payload_capacity_kg=100 WHERE id='V-CARGO'");$options=assignment_options($pdo,$r,$r['departure_date'].' '.$r['departure_time']);logistics_check($options['recommended_id']===null,'Chooser rejects overweight cargo');
$overweight=cargo_reservation_availability($pdo,$r,$options);
logistics_check($overweight['vehicles']['available_count']===0 && str_contains(json_encode($overweight['vehicles']['blockers']),'payload'),'Cargo payload blocker retains affected vehicle details');
logistics_reject(fn()=>logistics_review_request($pdo,$id,'approve','',$actor),'Requester cannot approve own request');
$pdo->beginTransaction();$status=logistics_review_request($pdo,$id,'approve','',['id'=>1,'role_code'=>'dispatcher']);$pdo->commit();logistics_check($status==='Approved','Dispatcher approval uses existing workflow');
logistics_reject(fn()=>logistics_review_request($pdo,$id,'reject','Too late',['id'=>1,'role_code'=>'fleet_admin']),'Review cannot overwrite prior decision');
$rejected=logistics_create_mock_request($pdo,$input,$actor);logistics_reject(fn()=>logistics_review_request($pdo,$rejected,'reject','',['id'=>1,'role_code'=>'fleet_admin']),'Rejection reason required');
$pdo->beginTransaction();$status=logistics_review_request($pdo,$rejected,'reject','No suitable handling equipment',['id'=>1,'role_code'=>'fleet_admin']);$pdo->commit();logistics_check($status==='Rejected','Admin can reject with reason');
if(($argv[1]??'')==='render') {
 $current_user=['id'=>1,'name'=>'Logistics Requester','role_code'=>'customer','role_name'=>'Logistics 1 Requester','phone'=>'','email'=>'requester@example.invalid','emp_id'=>'TEST','avatar'=>null];
 $current_permissions=['logistics.requests.own','notifications.view'];$_SESSION['last_valid_activity']=time();
 $pdo->exec('DELETE FROM reservations');
 $_GET=[];ob_start();require ROOT_PATH.'/modules/customer-portal/reservations.php';$html=ob_get_clean();
 logistics_check(strpos($html,'Logistics 1 Requester')!==false&&strpos($html,'name="cargo_weight_kg"')!==false,'Requester UI renders cargo fields and role name');
 logistics_check(strpos($html,'name="passenger_count"')===false&&strpos($html,'name="vehicle_id"')===false&&strpos($html,'name="driver_id"')===false,'Requester form exposes no passenger or assignment inputs');
 echo "Requester template render checks passed with isolated data.\n";
}
if(($argv[1]??'')==='assign') {
 $pdo->exec("UPDATE vehicles SET payload_capacity_kg=5000 WHERE id='V-CARGO'");
 $current_user=['id'=>1,'role_code'=>'fleet_admin'];$current_permissions=['dispatch.manage'];$_SESSION['assignment_csrf']='isolated-test';$_SERVER['REQUEST_METHOD']='POST';
 $_POST=['csrf'=>'isolated-test','reservation_id'=>$id,'vehicle_id'=>'V-CARGO','driver_id'=>'D-CARGO','dispatch_action'=>'assign','departure'=>$input['departure_date'].'T09:00','notes'=>'Review handling: keep dry'];
 register_shutdown_function(function()use($pdo,$id){$q=$pdo->prepare('SELECT * FROM reservations WHERE id=?');$q->execute([$id]);$saved=$q->fetch();if($saved['status']!=='Assigned'||$saved['assigned_vehicle_id']!=='V-CARGO') {fwrite(STDERR,'Assignment failed: '.($GLOBALS['logistics_assignment_error']??'Unknown')."\n");exit(1);} $q=$pdo->prepare('SELECT * FROM trips WHERE reservation_id=?');$q->execute([$id]);$trip=$q->fetch();if(!$trip||$trip['status']!=='Assigned'||(int)$trip['passengers']!==0){fwrite(STDERR,"Cargo trip relationship incorrect\n");exit(1);}echo "Full cargo assignment action passed; only temporary records used.\n";});
 $action=file_get_contents(ROOT_PATH.'/actions/dispatch.php');$action=str_replace('} catch (Exception $ex) {','} catch (Exception $ex) { $GLOBALS["logistics_assignment_error"]=$ex->getMessage();',$action);eval(substr($action,5));
}
if(($argv[1]??'')==='dispatch') {
 require_once ROOT_PATH.'/includes/trip_funding.php';
 $current_user=['id'=>1,'role_code'=>'fleet_admin'];$current_permissions=['dispatch.manage','dispatch.view'];
 $pdo->exec("UPDATE vehicles SET payload_capacity_kg=5000,assigned_driver_id='D-CARGO',status='Assigned',fuel_type='Diesel',avg_fuel_km='8 km/L' WHERE id='V-CARGO'");
 $pdo->prepare("UPDATE reservations SET assigned_vehicle_id='V-CARGO',assigned_driver_id='D-CARGO',status='Assigned' WHERE id=?")->execute([$id]);
 $pdo->prepare("INSERT INTO trips(id,reservation_id,origin,destination,vehicle_id,driver_id,status,scheduled_departure,passengers,distance_km) VALUES ('T-CARGO',?,'Manila Warehouse','Batangas Depot','V-CARGO','D-CARGO','Assigned',?,0,100)")->execute([$id,$input['departure_date'].' 09:00']);
 $pdo->exec('INSERT INTO trip_funding_methods SELECT * FROM public.trip_funding_methods');
 $pdo->exec("INSERT INTO fuel_price_history(fuel_type,price_per_liter,effective_date,source,updated_by) VALUES ('Diesel',60,CURRENT_DATE,'Isolated test',1)");
 logistics_reject(fn()=>funding_assert_dispatch($pdo,$id),'Cargo dispatch still requires funding');
 $fund=funding_create($pdo,'T-CARGO','company_card','1000');
 // Advance only the isolated mock clock rather than waiting for a test response.
 $pdo->exec("UPDATE trip_funding_requests SET requested_at=clock_timestamp()-interval '10 seconds',approval_due_at=clock_timestamp()-interval '5 seconds'");
 funding_mock_response($pdo,(int)$fund['id']);funding_assert_dispatch($pdo,$id);
 $pdo->prepare('UPDATE reservations SET cargo_weight_kg=1100 WHERE id=?')->execute([$id]);
 logistics_check(!funding_confirmed($pdo,funding_trip($pdo,'T-CARGO')),'Changing cargo invalidates pre-dispatch funding');
 $pdo->prepare('UPDATE reservations SET cargo_weight_kg=1000 WHERE id=?')->execute([$id]);
 $_SESSION['assignment_csrf']='isolated-test';$_SERVER['REQUEST_METHOD']='POST';
 $_POST=['csrf'=>'isolated-test','reservation_id'=>$id,'vehicle_id'=>'V-CARGO','driver_id'=>'D-CARGO','dispatch_action'=>'dispatch','departure'=>$input['departure_date'].'T09:00','notes'=>'Keep dry'];
 register_shutdown_function(function()use($pdo,$id){$q=$pdo->prepare('SELECT status FROM reservations WHERE id=?');$q->execute([$id]);if($q->fetchColumn()!=='Dispatched'||$pdo->query("SELECT status FROM trips WHERE id='T-CARGO'")->fetchColumn()!=='Dispatched'){fwrite(STDERR,'Cargo dispatch failed: '.($GLOBALS['logistics_assignment_error']??'Unknown')."\n");exit(1);}echo "Full cargo dispatch action passed with confirmed mock funding; only temporary records used.\n";});
 $action=file_get_contents(ROOT_PATH.'/actions/dispatch.php');$action=str_replace('} catch (Exception $ex) {','} catch (Exception $ex) { $GLOBALS["logistics_assignment_error"]=$ex->getMessage();',$action);eval(substr($action,5));
}
echo "$n logistics request checks passed; only connection-local test records used.\n";
