<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$cases=['generated','applied','read_only','view_preview','other_driver','stale_revision','navigation_replace','navigation_progress','reroute_mode','reroute_same_mode','free_reroute_same_mode','changed_inputs','start_admin','start_dispatcher','start_driver','start_no_apply','start_resume','start_changed_assignment','start_free_resume','start_return','custom_role_write','prepare_applied','prepare_no_apply'];
if(!isset($argv[1])){
 foreach($cases as $case){$output=[];exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case),$output,$code);if($code!==0)throw new RuntimeException($case.': '.implode("\n",$output));}
 echo count($cases)." route persistence and navigation action cases passed using isolated temporary tables.\n";exit;
}
require dirname(__DIR__).'/includes/bootstrap.php';require ROOT_PATH.'/includes/route_planner_state.php';
$case=$argv[1];$pdo=db();
foreach(['route_planner_states','route_history','ai_route_candidates_log','trips','reservations','vehicles','drivers','maintenance_orders','vehicle_documents','trip_timeline','audit_logs'] as $table){
 $pdo->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES INCLUDING IDENTITY)");
 $defaults=$pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=? AND column_default LIKE 'nextval%' AND is_identity='NO'");$defaults->execute([$table]);
 foreach($defaults->fetchAll(PDO::FETCH_COLUMN) as $column){$sequence='test_'.$table.'_'.$column;$pdo->exec("CREATE TEMP SEQUENCE $sequence; ALTER TABLE $table ALTER COLUMN $column SET DEFAULT nextval('pg_temp.$sequence')");}
}
$fixture=json_decode(file_get_contents(ROOT_PATH.'/tmp/route-planner-evaluations.json'),true);
function action_route($fixture,$mode){$evaluation=$fixture['modeEvaluations'][$mode];$candidate=$evaluation['selectedCandidate'];$id='test-generation:'.$mode.':'.$evaluation['selectedIndex'];return ['routeId'=>$id,'mode'=>$mode,'directions'=>$fixture['directions'],'evaluation'=>$evaluation,'data'=>['routeId'=>$id,'mode'=>$mode,'distanceKm'=>$candidate['distanceKm'],'durationMins'=>$candidate['durationMins'],'fuelEstimateLiters'=>$candidate['fuelEstimateLiters'],'routeScore'=>'99 / 100','modelVersion'=>'v0-kinematic']];}
$route=action_route($fixture,'fastest');$state=['inputs'=>['origin'=>'Origin','destination'=>'Destination','vehicleId'=>'TEST-V','vehicle'=>'Test vehicle','waypoints'=>[]],'directions'=>$fixture['directions'],'selected'=>$route,'applied'=>$route];
$pdo->exec("INSERT INTO drivers(id,name,status,user_id,license_class,license_expiration) VALUES('TEST-D','Test driver','Active',1,'Class 3','2099-12-31'); INSERT INTO vehicles(id,plate_number,type,brand,model,year,capacity,fuel_type,avg_fuel_km) VALUES('TEST-V','TEST','Van','Test','Vehicle',2026,15,'Diesel','9.5 km/L'); INSERT INTO reservations(id,client_name,passenger_count,origin,destination,departure_date,departure_time,status,assigned_vehicle_id,assigned_driver_id) VALUES('TEST-R','Test',5,'Origin','Destination','2030-01-10','07:00','Dispatched','TEST-V','TEST-D'); INSERT INTO trips(id,reservation_id,origin,destination,vehicle_id,driver_id,passengers,status) VALUES('TEST-T','TEST-R','Origin','Destination','TEST-V','TEST-D',5,'Dispatched')");
$doc=$pdo->prepare("INSERT INTO vehicle_documents(id,vehicle_id,document_type,extracted_data,extraction_status,stored_filename,original_filename,mime_type,file_size) VALUES(md5(random()::text),'TEST-V',?,?,'extracted',md5(random()::text),'test.pdf','application/pdf',1)");
foreach(['registration','insurance','ltfrb_permit'] as $type)$doc->execute([$type,json_encode(['vehicle_match_status'=>'MATCHED','document_type_status'=>'MATCHED','expiration_date'=>'2099-12-31'])]);
$current_user=['id'=>1,'role_code'=>'fleet_admin'];$current_permissions=['ai.view','ai.manage'];
$_SESSION['route_state_csrf']='test-csrf';$_SERVER['REQUEST_METHOD']='POST';$_GET=[];
$key='trip:TEST-T:outbound';$life='APPLIED';$tripId='TEST-T';$phase='outbound';$existing=!in_array($case,['generated','applied'],true);
if($case==='read_only'){$current_user['role_code']='fleet_manager';$current_permissions=['ai.view'];}
if(in_array($case,['start_driver','other_driver'],true)){$current_user['role_code']='driver';$current_permissions=['ai.view','ai.navigate'];if($case==='other_driver')$current_user['id']=2;}
if(!in_array($case,['start_admin','start_dispatcher','start_free_resume','changed_inputs','free_reroute_same_mode','read_only','view_preview','custom_role_write','prepare_applied','prepare_no_apply'],true))$current_user['role_code']='driver';
if($case==='custom_role_write'){$current_user['role_code']='custom_route_viewer';$current_permissions=['dispatch.view','ai.view','ai.manage','ai.navigate'];}
if($case==='start_dispatcher')$current_user['role_code']='dispatcher';
if(in_array($case,['changed_inputs','start_free_resume','free_reroute_same_mode'],true)){$tripId='';$key='account:1:outbound';}
if(in_array($case,['navigation_replace','navigation_progress','reroute_mode','reroute_same_mode','free_reroute_same_mode','start_resume','start_free_resume'],true))$life='NAVIGATING';
if(in_array($case,['start_no_apply','prepare_no_apply'],true)){$state['applied']=null;$life='GENERATED';}
if($case==='start_return'){
 $phase='return';$key='trip:TEST-T:return';$state['inputs']['origin']='Destination';$state['inputs']['destination']=getenv('FLEET_DEPOT_ADDRESS')?:'Origin';
 $pdo->exec("UPDATE trips SET status='Returning to Depot',route_history_id='TEST-OUTBOUND',current_step='Returning to Depot'; UPDATE reservations SET status='Returning to Depot'");
}
if($existing)$pdo->prepare('INSERT INTO route_planner_states(scope_key,trip_id,user_id,phase,lifecycle,revision,state_data) VALUES (?,?,1,?,?,1,?::jsonb)')->execute([$key,$tripId?:null,$phase,$life,json_encode($state)]);
if($life==='NAVIGATING' && $tripId!=='')$pdo->exec("UPDATE trips SET status='In Transit',route_history_id='TEST-LOG',navigation_active=1; UPDATE reservations SET status='In Transit'");
if(in_array($case,['start_resume','start_free_resume','reroute_same_mode','free_reroute_same_mode'],true)){
 $pdo->prepare("INSERT INTO route_history(log_id,route_title,vehicle,generated_date,trip_id,created_by,route_data_json) VALUES('TEST-LOG','Test','Test',NOW(),?,1,?)")->execute([$tripId?:null,json_encode(['routeId'=>$route['routeId'],'phase'=>'outbound'])]);
}
$_POST=['trip_id'=>$tripId,'phase'=>$phase,'csrf'=>'test-csrf','lifecycle'=>'APPLIED','revision'=>$existing?'1':'0','state'=>json_encode($state)];
if($case==='navigation_progress'){$_POST['lifecycle']='NAVIGATING';$state['navigation']=['leg'=>0,'step'=>1,'hasReachedPickup'=>true];}
if($case==='view_preview'){$current_user['role_code']='fleet_manager';$current_permissions=['ai.view'];$_POST['lifecycle']='GENERATED';$state['selected']=action_route($fixture,'shortest');$state['applied']=null;}
if($case==='generated'){$_POST['lifecycle']='GENERATED';$state['applied']=null;}
if($case==='stale_revision')$_POST['revision']='0';
if(in_array($case,['navigation_replace','reroute_mode'],true)){$state['applied']=$state['selected']=action_route($fixture,'shortest');$_POST['lifecycle']='NAVIGATING';}
if(in_array($case,['reroute_mode','reroute_same_mode','free_reroute_same_mode'],true))$_POST['reroute']='1';
if(in_array($case,['reroute_same_mode','free_reroute_same_mode'],true)){$_POST['lifecycle']='NAVIGATING';$state['applied']['routeId']=$state['applied']['data']['routeId']='rerouted:fastest:0';$state['selected']=$state['applied'];}
if($case==='changed_inputs'){$state['inputs']['origin']='New origin';$state['applied']=null;$_POST['lifecycle']='GENERATED';}
$_POST['state']=json_encode($state);
$isStart=str_starts_with($case,'start_');
if($isStart){$_POST=['trip_id'=>$tripId,'reservation_id'=>$tripId?'TEST-R':'','route_phase'=>$phase,'state_csrf'=>'test-csrf','route_id'=>$route['routeId'],'mode'=>'shortest','distance_km'=>'9999','duration_mins'=>'9999','fuel_liters'=>'9999','vehicle'=>'Forged preview'];}
if($case==='start_changed_assignment')$pdo->exec("UPDATE trips SET destination='Changed destination'");
if(str_starts_with($case,'prepare_')){
 $pdo->exec("UPDATE trips SET status='Assigned'; UPDATE reservations SET status='Assigned'; CREATE TEMP TABLE trip_funding_requests (LIKE public.trip_funding_requests INCLUDING DEFAULTS INCLUDING IDENTITY)");
 $_SESSION['route_prepare_csrf']='test-csrf';$_POST=['trip_id'=>'TEST-T','csrf'=>'test-csrf','vehicle_id'=>'TEST-V','origin'=>'Origin','destination'=>'Destination','route_id'=>$route['routeId'],'distance_km'=>'9999','strategy'=>'shortest'];
}
$reject=in_array($case,['read_only','view_preview','custom_role_write','start_admin','start_dispatcher','other_driver','stale_revision','navigation_replace','reroute_mode','start_no_apply','start_changed_assignment','prepare_no_apply'],true);
ob_start();register_shutdown_function(function()use($pdo,$case,$reject,$key,$isStart,$route){
 $body=ob_get_clean();$result=json_decode($body,true);
 try{
  if(!$result)throw new RuntimeException('Invalid response: '.$body);
  if((bool)($result['ok']??false)===$reject)throw new RuntimeException($body);
  if(!$reject){
   $saved=route_planner_load($pdo,$key);
   if($isStart){
    if($saved['lifecycle']!=='NAVIGATING')throw new RuntimeException('Navigation state not saved');
    $rows=$pdo->query('SELECT * FROM route_history')->fetchAll();
    if(count($rows)!==1)throw new RuntimeException('Duplicate/missing navigation history');
    if(!in_array($case,['start_resume','start_free_resume'],true)){
     $snapshot=json_decode($rows[0]['route_data_json'],true);
     if($snapshot['mode']!=='fastest'||$snapshot['route']['distanceKm']!==$route['data']['distanceKm']||(int)$rows[0]['predicted_duration_mins']!==$route['data']['durationMins'])throw new RuntimeException('Navigation trusted forged preview metrics');
    }
   }elseif($case==='view_preview' && ($saved['lifecycle']!=='APPLIED'||$saved['state_data']['applied']['mode']!=='fastest'))throw new RuntimeException('Read-only preview discarded the applied route');
   elseif($case==='navigation_progress' && ($saved['lifecycle']!=='NAVIGATING'||$saved['state_data']['navigation']['step']!==1))throw new RuntimeException('Navigation progress not preserved');
   elseif($case==='changed_inputs' && (!empty($saved['state_data']['applied'])||$saved['lifecycle']!=='GENERATED'))throw new RuntimeException('Changed inputs retained stale applied route');
   elseif(in_array($case,['reroute_same_mode','free_reroute_same_mode'],true)){
    $payload=json_decode($pdo->query("SELECT route_data_json FROM route_history WHERE log_id='TEST-LOG'")->fetchColumn(),true);
    if($payload['routeId']!=='rerouted:fastest:0'||$payload['mode']!=='fastest')throw new RuntimeException('Reroute snapshot not synchronized');
   }elseif($case==='prepare_applied'){
    if((float)$pdo->query("SELECT distance_km FROM trips WHERE id='TEST-T'")->fetchColumn()!==50.0)throw new RuntimeException('Funding preparation trusted forged preview distance');
   }
  }elseif((int)$pdo->query('SELECT count(*) FROM route_history')->fetchColumn()!==0)throw new RuntimeException('Rejected navigation changed records');
 }catch(Throwable $e){fwrite(STDERR,$case.': '.$e->getMessage()."\n");exit(1);}
});
require ROOT_PATH.'/actions/'.($isStart?'route-start.php':(str_starts_with($case,'prepare_')?'route-preparation.php':'route-planner-state.php'));
