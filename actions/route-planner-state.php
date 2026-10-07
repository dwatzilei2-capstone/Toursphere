<?php
$routeTimingStart=hrtime(true);$routeTimingLast=$routeTimingStart;$routeTimings=[];
if(!defined('TOURSPHERE_JSON_REQUEST'))define('TOURSPHERE_JSON_REQUEST',true);
$routeMark=function(string $stage)use(&$routeTimingLast,&$routeTimings){$now=hrtime(true);$routeTimings[$stage]=round(($now-$routeTimingLast)/1e6,2);$routeTimingLast=$now;};
$routeTimingFinish=function()use(&$routeTimings,$routeTimingStart){
 $routeTimings['total']=round((hrtime(true)-$routeTimingStart)/1e6,2);
 if(!headers_sent())header('Server-Timing: '.implode(', ',array_map(fn($key)=>$key.';dur='.$routeTimings[$key],array_keys($routeTimings))));
 if(getenv('ROUTE_PLANNER_TIMING')==='1')error_log('route_planner_timing '.json_encode($routeTimings));
};
require_once dirname(__DIR__).'/includes/bootstrap.php';require_once ROOT_PATH.'/includes/route_planner_state.php';require_login();
// Authentication/CSRF values remain readable; this endpoint does not write session data.
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$routeMark('bootstrap');
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
try{
 $scope=route_planner_scope(db(),trim((string)($_GET['trip_id']??$_POST['trip_id']??'')),(string)($_GET['phase']??$_POST['phase']??'outbound'));
 $routeMark('scope');
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $row=route_planner_load(db(),$scope['key']);
  if($row && $scope['trip'] && in_array($scope['trip']['status'],['Completed','Cancelled'],true))$row=null;
  $routeMark('load');$routeTimingFinish();echo json_encode(['ok'=>true,'state'=>$row,'trip_status'=>$scope['trip']['status']??null,'navigation_active'=>$scope['trip']['navigation_active']??0,'current_step'=>$scope['trip']['current_step']??null]);exit;
 }
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
 if($scope['trip'] && !has_role('driver')){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Trip routes are read-only for non-Driver users.']);exit;}
 if(!is_string($_POST['csrf']??null)||empty($_SESSION['route_state_csrf'])||!hash_equals($_SESSION['route_state_csrf'],$_POST['csrf']))throw new DomainException('Route Planner session expired.');
 $json=route_planner_decode_state((string)($_POST['state']??''),(string)($_POST['state_encoding']??''));
 $state=json_decode($json,true,128,JSON_THROW_ON_ERROR);route_planner_validate($state);
 $lifecycle=(string)($_POST['lifecycle']??'GENERATED');
 if(!in_array($lifecycle,['GENERATED','APPLIED','NAVIGATING'],true))throw new DomainException('Invalid route state.');
 if($lifecycle!=='GENERATED' && !can('ai.manage') && !can('ai.navigate'))throw new DomainException('You may view routes but cannot apply or navigate.');
 if($lifecycle!=='GENERATED' && empty($state['applied']))throw new DomainException('Apply a complete route before navigation.');
 $routeMark('validate');
 $pdo=db();$pdo->beginTransaction();$pdo->exec("SET LOCAL lock_timeout='5s'; SET LOCAL statement_timeout='10s'");$pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute([$scope['key']]);$existing=route_planner_load($pdo,$scope['key']);
 $routeMark('lock_load');
 if($existing && (int)($_POST['revision']??0)!==(int)$existing['revision'])throw new DomainException('Route state changed in another page. Reload before applying.');
 if($scope['trip']){
  $q=$pdo->prepare('SELECT * FROM trips WHERE id=? FOR UPDATE');$q->execute([$scope['trip']['id']]);$trip=$q->fetch();
  if(in_array($trip['status'],['Completed','Cancelled'],true))throw new DomainException('This trip is no longer active.');
  if($scope['phase']==='return' && $trip['status']!=='Returning to Depot')throw new DomainException('The return leg is not active.');
  $origin=$scope['phase']==='return'?$trip['destination']:$trip['origin'];$destination=$scope['phase']==='return'?(getenv('FLEET_DEPOT_ADDRESS')?:$trip['origin']):$trip['destination'];
  if(strcasecmp(trim($state['inputs']['origin']??''),trim($origin)) || strcasecmp(trim($state['inputs']['destination']??''),trim($destination)) || ($state['inputs']['vehicleId']??'')!==$trip['vehicle_id'])throw new DomainException('Route preparation must match the assigned trip.');
  if($lifecycle==='NAVIGATING' && !(int)$trip['navigation_active'])throw new DomainException('Start the dispatched trip before recording navigation state.');
  if($existing && $existing['lifecycle']==='NAVIGATING' && (int)$trip['navigation_active'] && empty($_POST['reroute']) && ($existing['state_data']['applied']??null)!=($state['applied']??null))throw new DomainException('The active navigation route cannot be replaced.');
 }
 // PostgreSQL jsonb normalizes object key order; compare values, not PHP insertion order.
 $sameInputs=$existing && ($existing['state_data']['inputs']??null)==$state['inputs'];
 $navigationActive=$existing && $existing['lifecycle']==='NAVIGATING' && (!$scope['trip'] || (int)$trip['navigation_active']);
 if($navigationActive && empty($_POST['reroute']) && (!$sameInputs || ($existing['state_data']['applied']??null)!=($state['applied']??null)))throw new DomainException('The active navigation route cannot be replaced.');
 if($existing && $sameInputs && $lifecycle==='GENERATED' && !empty($existing['state_data']['applied'])){
  $state['applied']=$existing['state_data']['applied'];$lifecycle=$existing['lifecycle'];
 }
 if($existing && $existing['lifecycle']==='NAVIGATING' && !empty($_POST['reroute']) && ($existing['state_data']['applied']['mode']??'')!==($state['applied']['mode']??''))throw new DomainException('Rerouting must preserve the applied optimization mode.');
 if(!empty($_POST['reroute'])){
  if(!$existing || $existing['lifecycle']!=='NAVIGATING' || $lifecycle!=='NAVIGATING')throw new DomainException('Rerouting requires an existing active navigation session.');
  $route=$state['applied'];$legs=$route['directions']['routes'][$route['evaluation']['selectedIndex']]['legs'];
  $payload=['mode'=>$route['mode'],'phase'=>$scope['phase'],'routeId'=>$route['routeId'],'directions'=>$route['directions'],'route'=>$route['data'],'selectedCandidate'=>$route['evaluation']['selectedCandidate'],'destinationLocation'=>$legs[array_key_last($legs)]['end_location']];
  if($scope['trip'])$pdo->prepare('UPDATE route_history SET route_data_json=? WHERE log_id=? AND trip_id=?')->execute([json_encode($payload),$trip['route_history_id'],$trip['id']]);
  else $pdo->prepare("UPDATE route_history SET route_data_json=? WHERE created_by=? AND NULLIF(route_data_json,'')::jsonb->>'routeId'=?")->execute([json_encode($payload),current_user()['id'],$existing['state_data']['applied']['routeId']]);
 }
 $revision=($existing['revision']??0)+1;
 $routeMark('trip_checks');
 $pdo->prepare('INSERT INTO route_planner_states(scope_key,trip_id,user_id,phase,lifecycle,revision,state_data) VALUES (?,?,?,?,?,?,?::jsonb) ON CONFLICT(scope_key) DO UPDATE SET user_id=EXCLUDED.user_id,lifecycle=EXCLUDED.lifecycle,revision=EXCLUDED.revision,state_data=EXCLUDED.state_data,updated_at=clock_timestamp()')->execute([$scope['key'],$scope['trip']['id']??null,current_user()['id'],$scope['phase'],$lifecycle,$revision,json_encode($state,JSON_THROW_ON_ERROR)]);
 if(in_array($lifecycle,['APPLIED','NAVIGATING'],true) && ($existing['state_data']['applied']['routeId']??null)!==($state['applied']['routeId']??null)){
  $pdo->prepare('INSERT INTO audit_logs(action,user_id,entity_type,entity_id,details) VALUES (?,?,?,?,?)')->execute([!empty($_POST['reroute'])?'ROUTE_REROUTED':'ROUTE_APPLIED',current_user()['id'],'route',$scope['trip']['id']??$scope['key'],json_encode(['phase'=>$scope['phase'],'mode'=>$state['applied']['mode'],'previous_route_id'=>$existing['state_data']['applied']['routeId']??null,'route_id'=>$state['applied']['routeId'],'distance_km'=>$state['applied']['data']['distanceKm']])]);
 }
 $routeMark('save_audit');$pdo->commit();$routeMark('commit');$routeTimingFinish();echo json_encode(['ok'=>true,'revision'=>$revision,'lifecycle'=>$lifecycle]);
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$routeMark('failed');$routeTimingFinish();http_response_code($e instanceof DomainException?409:422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
