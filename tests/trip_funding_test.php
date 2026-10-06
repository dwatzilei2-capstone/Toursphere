<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/bootstrap.php';require_once ROOT_PATH.'/includes/trip_funding.php';
$pdo=db();$checks=0;function funding_check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
foreach(['vehicles','drivers','reservations','trips','maintenance_orders','vehicle_documents','fuel_transactions','fuel_price_history','trip_cost_estimates','trip_funding_requests','trip_funding_methods','audit_logs','notifications'] as $table)$pdo->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING IDENTITY)");
$pdo->exec('INSERT INTO trip_funding_methods SELECT * FROM public.trip_funding_methods');
$admin=$pdo->query("SELECT u.*,r.code role_code,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='fleet_admin' AND u.status='Active' LIMIT 1")->fetch();
$current_user=$admin;$current_permissions=['dispatch.view','dispatch.manage','fuel.manage','fuel.view','costs.view'];audit_set_actor($pdo,$admin);
foreach(['fleet_admin'=>true,'dispatcher'=>true,'fleet_manager'=>false,'driver'=>false,'customer'=>false,'staff'=>false] as $role=>$allowed){$current_user['role_code']=$role;funding_check(funding_can_manage()===$allowed,'Manage role '.$role);funding_check(funding_can_view()===in_array($role,['fleet_admin','dispatcher','fleet_manager'],true),'View role '.$role);}
$current_user=$admin;
$pdo->exec("INSERT INTO drivers(id,name,status,license_class,license_expiration) VALUES ('FUND-D','Funding test driver','Assigned','Class 3','2099-12-31')");
$pdo->exec("INSERT INTO vehicles(id,plate_number,type,brand,model,year,capacity,status,assigned_driver_id,fuel_type,avg_fuel_km) VALUES ('FUND-V','TESTFUND','Van','Test','Funding',2026,14,'Assigned','FUND-D','Diesel','8.0 km/L')");
$pdo->exec("INSERT INTO reservations(id,client_name,passenger_count,origin,destination,departure_date,departure_time,status,assigned_vehicle_id,assigned_driver_id,route_distance_km,vehicle_requested) VALUES ('FUND-R','Isolated funding test',10,'Origin','Destination','2030-01-10','07:00','Assigned','FUND-V','FUND-D',180,'Van')");
$pdo->exec("INSERT INTO trips(id,reservation_id,origin,destination,vehicle_id,driver_id,passengers,scheduled_departure,status) VALUES ('FUND-T','FUND-R','Origin','Destination','FUND-V','FUND-D',10,'2030-01-10 07:00','Assigned')");
$doc=$pdo->prepare("INSERT INTO vehicle_documents(id,vehicle_id,document_type,extracted_data,extraction_status,stored_filename,original_filename,mime_type,file_size) VALUES (md5(random()::text),'FUND-V',?,?,'extracted',md5(random()::text),'test.pdf','application/pdf',1)");
foreach(['registration','insurance','ltfrb_permit'] as $type)$doc->execute([$type,json_encode(['vehicle_match_status'=>'MATCHED','document_type_status'=>'MATCHED','expiration_date'=>'2099-12-31'])]);
$t=funding_trip($pdo,'FUND-T');funding_check(count(funding_estimate($pdo,$t)['issues'])===1,'Missing fuel price explicitly blocked');
fuel_save_price($pdo,'Diesel','95',date('Y-m-d'),'Isolated test price');$e=funding_estimate($pdo,$t);
funding_check($e['estimated_liters']===22.5 && $e['estimated_fuel_cost']===2137.5,'Database route and efficiency formula');
funding_check($e['toll']==='Not Included in Estimate','No fabricated toll');
$missing=$t;$missing['avg_fuel_km']='—';funding_check(in_array('Vehicle fuel efficiency data unavailable.',funding_estimate($pdo,$missing)['issues'],true),'Missing efficiency blocked');
$gas=$t;$gas['fuel_type']='Gasoline';funding_check(funding_estimate($pdo,$gas)['estimated_total_cost']===null,'No Diesel price for Gasoline');
$blocked=false;try{funding_assert_dispatch($pdo,'FUND-R');}catch(DomainException $ex){$blocked=true;}funding_check($blocked,'Backend dispatch blocked without funding');
$started=microtime(true);$f=funding_create($pdo,'FUND-T','company_card','2300');
funding_check($f['status']==='Pending Finance Approval','Persisted pending state');
$duplicate=funding_create($pdo,'FUND-T','cash_advance','3000');funding_check($duplicate['id']===$f['id'] && $duplicate['method_name']===$f['method_name'],'Duplicate returns same unchanged request');
funding_mock_response($pdo,(int)$f['id']);funding_check(funding_request($pdo,'FUND-T')['status']==='Pending Finance Approval','Cannot approve before five seconds');
$blocked=false;try{funding_assert_dispatch($pdo,'FUND-R');}catch(DomainException $ex){$blocked=true;}funding_check($blocked,'Pending request blocks dispatch');
$q=$pdo->prepare('SELECT pg_sleep(GREATEST(0,EXTRACT(EPOCH FROM (approval_due_at-clock_timestamp())))) FROM trip_funding_requests WHERE id=?');$q->execute([$f['id']]);funding_mock_response($pdo,(int)$f['id']);$f=funding_request($pdo,'FUND-T');
funding_check(microtime(true)-$started>=5,'Five-second backend delay');funding_check($f['status']==='Funding Confirmed' && $f['approval_status']==='APPROVED' && (float)$f['approved_amount']===2300.0,'Mock automatic response');
funding_check($f['finance_source']==='MOCK_FINANCE','Clearly mock source');
$timing=$pdo->query('SELECT EXTRACT(EPOCH FROM (approved_at-requested_at)) delay,approved_at>=approval_due_at valid FROM trip_funding_requests')->fetch();funding_check((float)$timing['delay']>=5 && (float)$timing['delay']<5.1,'Stored five-second approval deadline');
funding_check(funding_readiness($pdo,funding_trip($pdo,'FUND-T'))['ready'],'Full dispatch readiness');funding_assert_dispatch($pdo,'FUND-R');$checks++;
fuel_save_price($pdo,'Diesel','100',date('Y-m-d'),'Updated isolated test price');$after=funding_request($pdo,'FUND-T');funding_check((float)$after['reference_price']===95.0 && (float)$after['estimated_total_cost']===2137.5,'Historical estimate unchanged after price update');
$comparison=funding_comparison_rows($pdo,'2029-01-01','2031-01-01')[0];funding_check($comparison['actual_recorded_cost']===null,'Missing actual cost is not zero');
$pdo->exec("UPDATE trips SET status='In Transit',distance_km=181 WHERE id='FUND-T'");$active=funding_payload($pdo,funding_trip($pdo,'FUND-T'));funding_check($active['status']==='Funding Confirmed' && (float)$active['estimate']['reference_price']===95.0,'Active trip retains historical funding after navigation distance changes');
$displayTrip=funding_trip($pdo,'FUND-T');$displayTrip['distance_km']=null;$displayTrip['fuel_estimate']=null;$display=funding_trip_summary($pdo,$displayTrip);funding_check($display['distance']==='180.0 km' && $display['fuel']==='22.50 L','Driver summary uses stored funding route and liters when legacy trip fields are blank');
$pdo->exec("UPDATE trips SET status='Assigned',distance_km=180 WHERE id='FUND-T'");
$pdo->exec("UPDATE trips SET driver_id=NULL WHERE id='FUND-T'");funding_check(!funding_confirmed($pdo,funding_trip($pdo,'FUND-T')),'Changed assignment invalidates eligibility');
$pdo->exec("UPDATE trips SET driver_id='FUND-D' WHERE id='FUND-T'");funding_revise($pdo,'FUND-T');funding_check(funding_request($pdo,'FUND-T')['status']==='Superseded','Legitimate pre-dispatch revision preserves old request');
$pdo->exec("UPDATE reservations SET status='Cancelled' WHERE id='FUND-R'");$blocked=false;try{funding_create($pdo,'FUND-T','company_card','3000');}catch(DomainException $ex){$blocked=true;}funding_check($blocked,'Cancelled reservations cannot request funding');
echo "PASS: $checks Trip Funding role, formula, pending, duplicate, exact deadline, readiness, snapshot, revision and missing-actual checks. All fixtures use temporary tables.\n";
