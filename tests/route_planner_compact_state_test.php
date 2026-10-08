<?php
require dirname(__DIR__).'/includes/route_planner_state.php';
$fixture=json_decode(file_get_contents(dirname(__DIR__).'/tmp/route-planner-evaluations.json'),true);
$points=[];
for($i=0;$i<12000;$i++)$points[]=['lat'=>14.5+$i/1000000,'lng'=>121.0+$i/1000000];
foreach($fixture['directions']['routes'] as &$candidate)$candidate['overview_path']=$points;
unset($candidate);
$evaluation=$fixture['modeEvaluations']['fastest'];$metrics=$evaluation['selectedCandidate'];
$route=['routeId'=>'compact:fastest:0','mode'=>'fastest','directions'=>$fixture['directions'],'evaluation'=>$evaluation,
 'data'=>['routeId'=>'compact:fastest:0','mode'=>'fastest','distanceKm'=>$metrics['distanceKm'],'durationMins'=>$metrics['durationMins'],'fuelEstimateLiters'=>$metrics['fuelEstimateLiters']],
 'geometry'=>$points,'prePickupPath'=>array_slice($points,0,1000)];
$state=['version'=>1,'inputs'=>['origin'=>'Origin','destination'=>'Destination','vehicleId'=>'TEST-V','waypoints'=>[]],
 'directions'=>$fixture['directions'],'selected'=>$route,'applied'=>$route];
$large=json_encode($state,JSON_THROW_ON_ERROR);
if(strlen($large)<=3000000)throw new RuntimeException('Regression fixture does not exceed old limit');
$compact=route_planner_compact_state($state);$encoded=json_encode($compact,JSON_THROW_ON_ERROR);
if(strlen($encoded)>=3000000)throw new RuntimeException('Shared geometry was not compacted');
$decoded=json_decode(route_planner_decode_state(base64_encode(gzencode($encoded)),'gzip-base64'),true);
$expanded=route_planner_expand_state($decoded);route_planner_validate($expanded);
if($expanded['selected']!=$route || $expanded['applied']!=$route)throw new RuntimeException('Route identity, metrics, or geometry changed');
if(route_planner_expand_state($state)!=$state)throw new RuntimeException('Legacy route snapshot changed');
$bad=$compact;$bad['applied']['geometryRef']='applied';
try{route_planner_expand_state($bad);throw new RuntimeException('Invalid reference accepted');}catch(DomainException $e){}
echo 'Large route round-trip passed: '.strlen($large).' → '.strlen($encoded)." bytes, exact snapshots preserved.\n";
