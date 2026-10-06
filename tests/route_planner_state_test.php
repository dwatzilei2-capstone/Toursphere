<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
require ROOT_PATH.'/includes/route_planner_state.php';
require ROOT_PATH.'/includes/routethink_engine.php';
$checks=0;
function route_check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function route_reject($state,$message){try{route_planner_validate($state);}catch(DomainException $e){route_check(true,$message);return;}throw new RuntimeException($message);}
$pdo=db();
foreach(['route_history','trips','ai_learning_samples','ai_learning_stats','ai_model_registry','route_planner_states'] as $table)$pdo->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS)");
$vehicle=['baseline_km_per_liter'=>9.5,'capacity'=>15,'weight_class'=>1,'name'=>'Test vehicle','efficiency_available'=>true];
$candidates=[
 ['distanceMeters'=>50000,'distanceKm'=>50,'baseDurationMins'=>30,'trafficDurationMins'=>30,'durationSecs'=>1800,'summary'=>'Fast road'],
 ['distanceMeters'=>30000,'distanceKm'=>30,'baseDurationMins'=>40,'trafficDurationMins'=>100,'durationSecs'=>6000,'summary'=>'Short congested road'],
 ['distanceMeters'=>36000,'distanceKm'=>36,'baseDurationMins'=>45,'trafficDurationMins'=>45,'durationSecs'=>2700,'summary'=>'Efficient road']
];
$evaluations=[];
foreach(['fastest'=>0,'shortest'=>1,'fuelEfficient'=>2,'balanced'=>2] as $mode=>$winner){
 $result=RouteThinkEngine::evaluateCandidates($candidates,$vehicle,$mode,5);
 route_check($result['selectedIndex']===$winner,"$mode selects its own objective");
 route_check(!empty($result['explanation']),"$mode has its own rationale");
 $result['mode']=$mode;$evaluations[$mode]=$result;
}
$directions=['routes'=>[]];
foreach($candidates as $i=>$candidate){
 $start=['lat'=>14.6,'lng'=>120.9];$end=['lat'=>14.7,'lng'=>121.1];
 $path=[$start,['lat'=>14.65+$i/100,'lng'=>121.0],$end];
 $step=['instructions'=>'Continue along road','path'=>$path,'start_location'=>$start,'end_location'=>$end,'distance'=>['value'=>$candidate['distanceMeters']],'duration'=>['value'=>$candidate['durationSecs']]];
 $leg=['start_address'=>'Origin','end_address'=>'Destination','start_location'=>$start,'end_location'=>$end,'distance'=>['value'=>$candidate['distanceMeters'],'text'=>$candidate['distanceKm'].' km'],'duration'=>['value'=>$candidate['baseDurationMins']*60,'text'=>$candidate['baseDurationMins'].' mins'],'duration_in_traffic'=>['value'=>$candidate['durationSecs'],'text'=>$candidate['trafficDurationMins'].' mins'],'steps'=>[$step]];
 $directions['routes'][]=['summary'=>$candidate['summary'],'overview_path'=>$path,'legs'=>[$leg]];
}
$inputs=['origin'=>'Origin','destination'=>'Destination','vehicleId'=>'TEST-V','vehicle'=>'Test vehicle','waypoints'=>[],'trafficAware'=>true];
foreach($evaluations as $mode=>$evaluation){
 $selected=$evaluation['selectedCandidate'];$routeId='test-generation:'.$mode.':'.$evaluation['selectedIndex'];
 $route=['routeId'=>$routeId,'mode'=>$mode,'directions'=>$directions,'evaluation'=>$evaluation,'data'=>['routeId'=>$routeId,'mode'=>$mode,'distanceKm'=>$selected['distanceKm'],'durationMins'=>$selected['durationMins'],'fuelEstimateLiters'=>$selected['fuelEstimateLiters']]];
 $state=['inputs'=>$inputs,'directions'=>$directions,'selected'=>$route,'applied'=>$route];
 route_planner_validate($state);route_check(true,"$mode complete snapshot accepted");
 $bad=$state;$bad['selected']['data']['distanceKm']++;route_reject($bad,'Cross-route distance rejected');
 $bad=$state;$bad['selected']['data']['durationMins']++;route_reject($bad,'Cross-route ETA rejected');
 $bad=$state;$bad['selected']['data']['fuelEstimateLiters']++;route_reject($bad,'Cross-route fuel rejected');
 $bad=$state;$bad['selected']['evaluation']['mode']='invalid';route_reject($bad,'Mixed mode rejected');
 $bad=$state;unset($bad['selected']['directions']['routes'][$evaluation['selectedIndex']]['overview_path']);route_reject($bad,'Missing path rejected');
 $bad=$state;$bad['selected']['directions']['routes'][$evaluation['selectedIndex']]['legs'][0]['end_location']['lat']=200;route_reject($bad,'Invalid navigation coordinates rejected');
}
file_put_contents(ROOT_PATH.'/tmp/route-planner-evaluations.json',json_encode(['directions'=>$directions,'modeEvaluations'=>$evaluations,'vehicleSpecs'=>$vehicle]));
echo "$checks route objective and snapshot integrity checks passed. No operational records changed.\n";
