<?php
 



require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/routethink_engine.php';
require_login();
require_permission('ai.manage');

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    $pdo = db();

    $preset_id     = trim($_POST['preset_id'] ?? '');
    $mode          = in_array($_POST['mode'] ?? '', ['balanced', 'fastest', 'fuelEfficient', 'shortest'], true) ? $_POST['mode'] : 'balanced';
    $custom_title  = trim($_POST['route_title'] ?? '');
    $vehicleInput  = trim($_POST['vehicle'] ?? '');
    $distanceKm    = (float)($_POST['distance_km'] ?? 0.0);
    $durationMins  = (int)($_POST['duration_mins'] ?? 0);
    $fuelLiters    = (float)($_POST['fuel_liters'] ?? 0.0);
    $routeScore    = trim($_POST['route_score'] ?? '');
    $modelVersion  = trim($_POST['model_version'] ?? 'v0-kinematic');
    $featuresJson  = $_POST['features_json'] ?? null;
    $candidatesRaw = $_POST['candidates_json'] ?? '[]';

    $candidates = json_decode($candidatesRaw, true) ?: [];

     
    $vehicleSpecs = RouteThinkEngine::getVehicleSpecs($vehicleInput);
    $vehicleTitle = $vehicleSpecs['name'];
    $vehicleId    = $vehicleSpecs['id'];

    $route_title = '';
    if (!empty($preset_id)) {
        $presetStmt = $pdo->prepare('SELECT * FROM route_presets WHERE id = ?');
        $presetStmt->execute([$preset_id]);
        $preset = $presetStmt->fetch();
        if ($preset) {
            $route_title = $preset['name'];
        }
    }

    if (empty($route_title)) {
        $route_title = $custom_title ?: 'Custom ROUTETHINK Optimized Route';
    }

    $mode_labels = [
        'balanced'      => 'Balanced (AI Preferred)',
        'fastest'       => 'Fastest Express Corridor',
        'fuelEfficient' => 'Eco-Optimized Fuel Efficient',
        'shortest'      => 'Shortest Distance',
    ];

    $log_id = next_sequential_id($pdo, 'route_history', 'log_id', 'LOG-AIR-');

     
    $varianceAccuracy = !empty($routeScore) ? "{$routeScore} Score" : "Optimal Alignment";

     
    $stmt = $pdo->prepare(
        'INSERT INTO route_history (
            log_id, route_title, vehicle, vehicle_id, generated_date, 
            selected_mode, variance_accuracy, model_version, 
            predicted_duration_mins, predicted_fuel_liters, 
            pre_trip_features_json, created_by
         ) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $log_id,
        $route_title,
        $vehicleTitle,
        $vehicleId,
        $mode_labels[$mode] ?? $mode,
        $varianceAccuracy,
        $modelVersion,
        $durationMins > 0 ? $durationMins : null,
        $fuelLiters > 0 ? $fuelLiters : null,
        is_string($featuresJson) ? $featuresJson : json_encode($featuresJson),
        $current_user['id'] ?? null,
    ]);

     
    if (!empty($candidates) && is_array($candidates)) {
        $candStmt = $pdo->prepare(
            'INSERT INTO ai_route_candidates_log (
                log_id, candidate_index, summary_title, distance_km, 
                duration_mins, traffic_duration_mins, estimated_fuel_l, 
                toll_cost_est, composite_score, is_selected
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $selectedIndex = (int)($_POST['selected_index'] ?? 0);

        foreach ($candidates as $k => $c) {
            $candStmt->execute([
                $log_id,
                $k,
                $c['summary'] ?? ($c['title'] ?? "Route " . ($k + 1)),
                (float)($c['distanceKm'] ?? 0.0),
                (int)($c['durationMins'] ?? 0),
                (int)($c['trafficDurationMins'] ?? ($c['durationMins'] ?? 0)),
                (float)($c['fuelEstimateLiters'] ?? 0.0),
                (float)($c['tollEstimate'] ?? 0.0),
                (int)($c['compositeScore'] ?? 0),
                ($k === $selectedIndex) ? 1 : 0
            ]);
        }
    }

    echo json_encode([
        'ok'      => true,
        'log_id'  => $log_id,
        'message' => "Route applied and logged to route history ({$log_id}) with model version {$modelVersion}.",
    ]);
} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
}
