<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/includes/bootstrap.php';
require ROOT_PATH.'/includes/trip_route_snapshot.php';
$p=db();
$p->exec('CREATE TEMP TABLE route_planner_states(scope_key text,state_data text); CREATE TEMP TABLE route_history(log_id text,trip_id text,generated_date timestamp,route_data_json text)');
$outbound=['phase'=>'outbound','mode'=>'fastest','route'=>['title'=>'Driver outbound'],'selectedCandidate'=>['index'=>0],'directions'=>['routes'=>[['overview_path'=>[['lat'=>14.6,'lng'=>121.0]]]]]];
$return=$outbound; $return['phase']='return'; $return['mode']='shortest';
$insert=$p->prepare('INSERT INTO route_history VALUES (?,?,?::timestamp,?)');
$insert->execute(['OUT','TEST','2026-10-08 08:00',json_encode($outbound)]);
$insert->execute(['RETURN','TEST','2026-10-08 09:00',json_encode($return)]);
$trip=['trip_id'=>'TEST','route_history_id'=>'RETURN'];
function snapshot_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$saved=trip_route_snapshot($p,$trip);
snapshot_check($saved['mode']==='fastest' && $saved['data']['title']==='Driver outbound','Completed trip must show outbound even when pointer is return and planner state is missing');
$trip['route_history_id']=null;
snapshot_check(trip_route_snapshot($p,$trip)['mode']==='fastest','Outbound history must load without a trip pointer');
$applied=['mode'=>'fuelEfficient','data'=>['title'=>'Applied outbound'],'evaluation'=>['selectedIndex'=>0],'directions'=>$outbound['directions']];
$p->prepare('INSERT INTO route_planner_states VALUES (?,?)')->execute(['trip:TEST:outbound',json_encode(['applied'=>$applied])]);
snapshot_check(trip_route_snapshot($p,$trip)['mode']==='fuelEfficient','Applied outbound snapshot must be preserved exactly');
$p->exec('DELETE FROM route_planner_states; DELETE FROM route_history WHERE log_id=\'OUT\'');
snapshot_check(trip_route_snapshot($p,$trip)===null,'Return-only trip must not masquerade as outbound');
echo "4 historical outbound route checks passed using temporary tables.\n";
