<?php
 








if (!defined('TOURSPHERE_JSON_REQUEST')) define('TOURSPHERE_JSON_REQUEST', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/routethink_engine.php';
require_once dirname(__DIR__) . '/includes/route_planner_state.php';
require_login();
require_permission('ai.view');
// Evaluation must not block Apply/state requests from the same driver session.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    $origin          = sanitize_text($_POST['origin'] ?? '');
    $destination     = sanitize_text($_POST['destination'] ?? '');
    $vehicle         = sanitize_text($_POST['vehicle'] ?? 'Tour Bus');
    $mode            = sanitize_text($_POST['mode'] ?? 'balanced');
    $passengerCount  = max(0, (int)($_POST['passenger_count'] ?? 0));
    $routePhase      = ($_POST['route_phase'] ?? '') === 'return' ? 'return' : 'outbound';
    $waypointsRaw    = $_POST['waypoints'] ?? [];
    $candidatesRaw   = $_POST['candidates'] ?? [];

    if (($current_user['role_code'] ?? '') === 'driver' || trim($_POST['trip_id']??'')!=='') {
        $tripId = trim($_POST['trip_id'] ?? '');
        $trustedTrip=route_planner_scope(db(),$tripId,$routePhase)['trip'];
        if (!$trustedTrip || in_array($trustedTrip['status'],['Completed','Cancelled'],true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You may generate routes only for your assigned active trip.']);
            exit;
        }
        $allowedOrigin = $trustedTrip['origin'];
        $allowedDestination = $trustedTrip['destination'];
        if($routePhase==='return' && $trustedTrip['status']!=='Returning to Depot')throw new DomainException('The return leg is not active.');
        if ($routePhase === 'return' && ($trustedTrip['status'] ?? '') === 'Returning to Depot') {
            $allowedOrigin = $trustedTrip['destination'];
            $allowedDestination = trim((string)(getenv('FLEET_DEPOT_ADDRESS') ?: $trustedTrip['origin']));
        }
        if (strcasecmp(trim($origin), trim($allowedOrigin)) !== 0 ||
            strcasecmp(trim($destination), trim($allowedDestination)) !== 0) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Use the assigned trip origin and destination.']);
            exit;
        }
        $origin = $allowedOrigin;
        $destination = $allowedDestination;
        $vehicle = $trustedTrip['vehicle_id'];
        $passengerCount = (int)$trustedTrip['passengers'];
        $waypointsRaw = $routePhase === 'return'
            ? '[]'
            : json_encode(array_values(array_filter(array_map('trim', preg_split('/[;\n]+/', $trustedTrip['waypoints'] ?? '') ?: []))));
    }

     
    if (is_string($waypointsRaw)) {
        $decoded = json_decode($waypointsRaw, true);
        $waypoints = is_array($decoded) ? array_map('sanitize_text', $decoded) : [];
    } elseif (is_array($waypointsRaw)) {
        $waypoints = array_map('sanitize_text', $waypointsRaw);
    } else {
        $waypoints = [];
    }

     
    $candidates = [];
    if (is_string($candidatesRaw)) {
        $decoded = json_decode($candidatesRaw, true);
        if (is_array($decoded)) {
            $candidates = $decoded;
        }
    } elseif (is_array($candidatesRaw)) {
        $candidates = $candidatesRaw;
    }

     
    $vehicleSpecs = RouteThinkEngine::getVehicleSpecs($vehicle);

     
    if (!empty($candidates)) {
        $evaluation = RouteThinkEngine::evaluateCandidates(
            $candidates,
            $vehicleSpecs,
            $mode,
            $passengerCount,
            count($waypoints)
        );

        if (!$evaluation['ok']) {
            http_response_code(422);
            echo json_encode($evaluation, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $selected = $evaluation['selectedCandidate'];
        $modeSelections = [$mode => $evaluation['selectedIndex']];
        $modeEvaluations = [$mode => $evaluation];
        foreach (['balanced', 'fastest', 'shortest', 'fuelEfficient'] as $strategy) {
            if ($strategy === $mode) continue;
            $modeEvaluations[$strategy] = RouteThinkEngine::evaluateCandidates($candidates, $vehicleSpecs, $strategy, $passengerCount, count($waypoints));
            $modeSelections[$strategy] = $modeEvaluations[$strategy]['selectedIndex'];
        }

        echo json_encode([
            'ok'   => true,
            'data' => [
                'title'             => "{$origin} → {$destination} (" . strtoupper($mode) . ")",
                'mode'              => $mode,
                'modeTitle'         => $evaluation['modeTitle'],
                'vehicle'           => $vehicleSpecs['name'],
                'vehicleSpecs'      => $vehicleSpecs,
                'selectedIndex'     => $evaluation['selectedIndex'],
                'modeSelections'    => $modeSelections,
                'modeEvaluations'   => $modeEvaluations,
                'selectedCandidate' => $selected,
                'candidates'        => $evaluation['candidates'],
                'explanation'       => $evaluation['explanation'],
                'modelVersion'      => $evaluation['modelVersion'],
                'isMlActive'        => $evaluation['isMlActive'],
                'isFullyMlActive'   => (bool)($selected['isFullyMlActive'] ?? false),
                'fuelModelVersion'  => $selected['fuelModelVersion'] ?? null,
                'durationModelVersion' => $selected['durationModelVersion'] ?? null,
                'distance'          => $selected['distanceFormatted'],
                'distanceKm'        => $selected['distanceKm'],
                'duration'          => $selected['durationFormatted'],
                'durationMins'      => $selected['durationMins'],
                'fuelEstimate'      => $selected['fuelFormatted'],
                'fuelCost'          => $selected['fuelCost']===null ? 'Current fuel price or efficiency unavailable' : '₱' . number_format($selected['fuelCost']),
                'tollEstimate'      => $selected['tollFormatted'],
                'totalTripCost'     => $selected['costFormatted'],
                'routeScore'        => $selected['compositeScore'],
                'reason'            => $evaluation['explanation'],
                'waypointCount'     => count($waypoints),
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

     
     
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'No verified Google route candidates were supplied. Route evaluation was not performed.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Error evaluating route optimization: ' . $e->getMessage()
    ]);
}
