<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/routethink_engine.php';
$checks = 0;
function learning_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++; echo "PASS: $message\n";
}
$pdo = db();
// Connection-local tables isolate test fixtures from all production trip/learning records.
foreach (['route_history', 'trips', 'ai_learning_samples', 'ai_learning_stats', 'ai_model_registry'] as $table) {
    $pdo->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS)");
}
$pdo->exec('ALTER TABLE ai_learning_samples ADD PRIMARY KEY (log_id, target)');
$pdo->exec('ALTER TABLE ai_learning_stats ADD PRIMARY KEY (target, context_key)');
$f = ['distance_km'=>40, 'base_duration_mins'=>60, 'traffic_delay_ratio'=>1.2, 'baseline_km_per_liter'=>8,
    'vehicle_weight_class'=>1, 'passenger_load_ratio'=>0.5, 'waypoint_count'=>0, 'highway_ratio'=>0.5];
$pdo->prepare("INSERT INTO route_history (id,log_id,route_title,vehicle,generated_date,pre_trip_features_json,actual_duration_mins,actual_fuel_liters,actual_fuel_verified,trip_id,vehicle_id) VALUES (1,'TEST-R','Test','Test',NOW(),?,90,8,1,'TEST-T','TEST-V')")->execute([json_encode($f)]);
$pdo->exec("INSERT INTO trips (id,origin,destination,status,vehicle_id,route_history_id,actual_departure,actual_arrival) VALUES ('TEST-T','A','B','Completed','TEST-V','TEST-R',NOW()-interval '90 minutes',NOW())");
learning_check(ai_learning_sync($pdo) === 2, 'First valid completed trip learns both targets without a 30-record gate');
learning_check(ai_learning_sync($pdo) === 0, 'Repeated sync does not duplicate learning');
$stats = ai_learning_stats($pdo);
$estimate = ai_learning_adjust(72, 'duration_mins', $f, $stats);
learning_check($estimate > 72 && $estimate < 74, 'One sample influences estimates slightly while baseline dominates');
$pdo->exec("UPDATE route_history SET actual_duration_mins = 100 WHERE log_id = 'TEST-R'");
learning_check(ai_learning_sync($pdo) === 1, 'Corrected outcome replaces only its prior contribution');
learning_check((int)$pdo->query("SELECT SUM(samples) FROM ai_learning_stats WHERE target='duration_mins'")->fetchColumn() === 1, 'Correction preserves sample count');
$pdo->exec("UPDATE route_history SET actual_fuel_verified = 0 WHERE log_id = 'TEST-R'");
learning_check(ai_learning_sync($pdo) === 1, 'Revoked fuel verification removes ineligible contribution');
learning_check(empty(ai_learning_stats($pdo)['fuel_liters']), 'Unverified refill is not learned');
$invalid = ['pre_trip_features_json'=>json_encode(array_replace($f,['distance_km'=>0]))];
learning_check(ai_learning_observations($invalid) === [], 'Invalid distance is rejected');
$context = ai_learning_context($f);
$small = ['duration_mins'=>[$context=>['samples'=>1,'ratio_sum'=>1.5]]];
$large = ['duration_mins'=>[$context=>['samples'=>60,'ratio_sum'=>90]]];
learning_check(ai_learning_adjust(60,'duration_mins',$f,$large) > ai_learning_adjust(60,'duration_mins',$f,$small), 'Historical influence grows smoothly with evidence');
$vehicle=['baseline_km_per_liter'=>8,'capacity'=>10,'weight_class'=>1,'name'=>'Test Vehicle'];
$candidates=[
 ['distanceKm'=>50,'baseDurationMins'=>30,'trafficDurationMins'=>30,'durationSecs'=>1800,'highwayRatio'=>1],
 ['distanceKm'=>30,'baseDurationMins'=>40,'trafficDurationMins'=>100,'durationSecs'=>6000,'highwayRatio'=>0],
 ['distanceKm'=>36,'baseDurationMins'=>45,'trafficDurationMins'=>45,'durationSecs'=>2700,'highwayRatio'=>0]
];
$winners=[];
foreach(['fastest','shortest','fuelEfficient','balanced'] as $mode) {
    $r=RouteThinkEngine::evaluateCandidates($candidates,$vehicle,$mode,5);
    $winners[$mode]=$r['selectedIndex'] ?? $r['selected_index'] ?? null;
}
learning_check($winners['fastest'] === 0, 'Fastest selects minimum estimated travel time');
learning_check($winners['shortest'] === 1, 'Shortest selects minimum distance');
learning_check($winners['fuelEfficient'] === 2, 'Fuel-efficient selects minimum estimated fuel rather than shortest distance');
learning_check($winners['balanced'] === 2, 'Balanced selects the best combined time/fuel/distance/cost trade-off');
$lightContext = 'weight:1:traffic:light';
$pdo->prepare("INSERT INTO ai_learning_stats (target,context_key,samples,ratio_sum) VALUES ('duration_mins',?,100,500),('fuel_liters',?,100,500)")->execute([$lightContext,$lightContext]);
$learnedFastest = RouteThinkEngine::evaluateCandidates($candidates,$vehicle,'fastest',5);
$learnedFuel = RouteThinkEngine::evaluateCandidates($candidates,$vehicle,'fuelEfficient',5);
learning_check($learnedFastest['selectedIndex'] === 1, 'Learned travel-time estimates can change the recommended road');
learning_check($learnedFuel['selectedIndex'] === 1, 'Learned fuel estimates can change the recommended road');
learning_check($learnedFastest['candidates'][0]['modelVersion'] === 'v2-continuous', 'Evaluated route reports continuous learning when its context is learned');
$pdo->exec("UPDATE trips SET status='In Transit' WHERE id='TEST-T'");
learning_check(ai_learning_sync($pdo) === 0, 'Uncompleted trips are not added to learning');
echo "$checks continuous-learning checks passed.\n";
