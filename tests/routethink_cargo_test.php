<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/includes/bootstrap.php';
require ROOT_PATH.'/includes/routethink_engine.php';
$pdo=db();
foreach (['vehicles','fuel_price_history','ai_model_registry','ai_learning_stats'] as $table) {
    $pdo->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS)");
}
$checks=0;
function cargo_check($ok,$label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; echo "PASS: $label\n"; }
$pdo->exec("INSERT INTO vehicles(id,plate_number,type,brand,model,year,capacity,fuel_type,avg_fuel_km,payload_capacity_kg) VALUES('CARGO-TEST','CARGO-TEST','Cargo Truck','Test','Truck',2026,0,'Diesel','8 km/L',2000)");
$pdo->exec("INSERT INTO fuel_price_history(id,fuel_type,price_per_liter,effective_date,source,updated_by) VALUES(1,'Diesel',60,CURRENT_DATE,'Isolated test',1)");
$spec=RouteThinkEngine::getVehicleSpecs('CARGO-TEST');
cargo_check($spec['payload_capacity_kg']==2000 && $spec['baseline_km_per_liter']==8,'Assigned cargo vehicle maps payload and verified efficiency');
$spec['cargo_load_ratio']=0.25;
$candidates=[
 ['distanceKm'=>50,'distanceMeters'=>50000,'baseDurationMins'=>30,'trafficDurationMins'=>30,'durationSecs'=>1800,'summary'=>'Fast road'],
 ['distanceKm'=>30,'distanceMeters'=>30000,'baseDurationMins'=>40,'trafficDurationMins'=>100,'durationSecs'=>6000,'summary'=>'Congested short road'],
 ['distanceKm'=>36,'distanceMeters'=>36000,'baseDurationMins'=>45,'trafficDurationMins'=>45,'durationSecs'=>2700,'summary'=>'Efficient road'],
];
foreach (['fastest'=>0,'shortest'=>1,'fuelEfficient'=>2,'balanced'=>2] as $mode=>$winner) {
 $r=RouteThinkEngine::evaluateCandidates($candidates,$spec,$mode);
 cargo_check($r['selectedIndex']===$winner,"$mode selects matching candidate");
 $c=$r['selectedCandidate'];
 cargo_check($c['features']['cargo_load_ratio']===0.25 && !isset($c['features']['passenger_load_ratio']),"$mode uses cargo load feature");
 cargo_check($c['fuelCost']===round($c['fuelEstimateLiters']*60) && $c['totalTripCost']===$c['fuelCost'],"$mode transportation estimate counts verified fuel once");
 cargo_check(!isset($c['revenue'],$c['profit']),"$mode has no revenue or profit result");
}
$unknown=RouteThinkEngine::getVehicleSpecs('MISSING');
$r=RouteThinkEngine::evaluateCandidates($candidates,$unknown);
cargo_check($r['selectedCandidate']['fuelEstimateLiters']===null && $r['selectedCandidate']['fuelCost']===null && $r['selectedCandidate']['costFormatted']==='Estimate Unavailable','Missing vehicle data does not fabricate costs or fuel');
$f=['distance_km'=>40,'base_duration_mins'=>60,'traffic_delay_ratio'=>1.2,'baseline_km_per_liter'=>8,'vehicle_weight_class'=>1,'cargo_load_ratio'=>0.25,'waypoint_count'=>0,'highway_ratio'=>0.5];
$row=['pre_trip_features_json'=>json_encode($f),'actual_duration_mins'=>80,'actual_fuel_liters'=>7,'actual_fuel_verified'=>1,'trip_id'=>'CARGO-TEST','vehicle_id'=>'CARGO-TEST'];
$observations=ai_learning_observations($row);
cargo_check(count($observations)===2 && str_starts_with($observations['fuel_liters']['context'],'cargo:'),'Verified cargo outcomes use separate continuous learning context');
$legacy=$f; $legacy['passenger_load_ratio']=$legacy['cargo_load_ratio']; unset($legacy['cargo_load_ratio']);
cargo_check(ai_learning_context($legacy)!==ai_learning_context($f),'Historical passenger calibration is preserved separately');
$pdo->prepare("INSERT INTO ai_model_registry(id,model_id,version,algorithm,target_variable,feature_list_json,weights_json,status,deployed_at) VALUES(1,'LEGACY-TEST','legacy-test','Ridge Regression','fuel_liters',?,?,'Deployed',NOW())")
    ->execute([json_encode(['passenger_load_ratio']),json_encode(['__intercept__'=>999,'passenger_load_ratio'=>100])]);
$r=RouteThinkEngine::evaluateCandidates($candidates,$spec);
cargo_check(!$r['selectedCandidate']['isFuelMlPredicted'],'Cargo inference does not reinterpret a passenger model load feature');
cargo_check($pdo->query("SELECT status FROM ai_model_registry WHERE id=1")->fetchColumn()==='Deployed','Legacy model deployment is retained without replacement');
echo "$checks cargo compatibility checks passed using connection-local temporary tables.\n";
