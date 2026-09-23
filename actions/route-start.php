<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/routethink_engine.php';
require_login();
header('Content-Type: application/json; charset=UTF-8');

if (!can('ai.manage') && !can('ai.navigate')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to start route navigation.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $isDriver = (($current_user['role_code'] ?? '') === 'driver');
    $tripId = trim($_POST['trip_id'] ?? '');
    $reservationId = trim($_POST['reservation_id'] ?? '');
    $presetId = trim($_POST['preset_id'] ?? '');
    $mode = in_array($_POST['mode'] ?? '', ['balanced','fastest','fuelEfficient','shortest'], true)
        ? $_POST['mode'] : 'balanced';

    $trip = null;
    if ($isDriver) {
        $driverStmt = $pdo->prepare('SELECT id FROM drivers WHERE user_id = ?');
        $driverStmt->execute([$current_user['id']]);
        $authenticatedDriverId = $driverStmt->fetchColumn();
        if (!$authenticatedDriverId) throw new RuntimeException('Your account is not linked to a Driver profile.');
        $tripStmt = $pdo->prepare(
            "SELECT * FROM trips WHERE id = ? AND driver_id = ?
              AND status IN ('Scheduled','Assigned','Dispatched','In Transit','Returning to Depot') FOR UPDATE"
        );
        $tripStmt->execute([$tripId, $authenticatedDriverId]);
        $trip = $tripStmt->fetch();
        if (!$trip) throw new RuntimeException('This trip is not assigned to you or cannot start navigation.');
    } elseif ($tripId !== '') {
        $tripStmt = $pdo->prepare('SELECT * FROM trips WHERE id = ? FOR UPDATE');
        $tripStmt->execute([$tripId]);
        $trip = $tripStmt->fetch();
        if (!$trip) throw new RuntimeException('The selected trip could not be found.');
    }

    if ($trip) {
        $tripId = $trip['id'];
        $reservationId = $trip['reservation_id'] ?? $reservationId;
    }

     
     
    if ($trip && in_array($trip['status'], ['In Transit','Returning to Depot'], true) && !empty($trip['route_history_id'])) {
        $activeRouteStmt = $pdo->prepare('SELECT log_id FROM route_history WHERE log_id = ? AND trip_id = ?');
        $activeRouteStmt->execute([$trip['route_history_id'], $tripId]);
        if ($activeLogId = $activeRouteStmt->fetchColumn()) {
            $pdo->prepare('UPDATE trips SET navigation_active = 1 WHERE id = ?')->execute([$tripId]);
            $pdo->commit();
            echo json_encode([
                'ok' => true,
                'log_id' => $activeLogId,
                'trip_id' => $tripId,
                'already_saved' => true,
                'message' => "Active route {$activeLogId} resumed for trip {$tripId}."
            ]);
            exit;
        }
    }
    $reservation = null;
    if ($reservationId !== '') {
        $resStmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? FOR UPDATE');
        $resStmt->execute([$reservationId]);
        $reservation = $resStmt->fetch();
        if (!$reservation) throw new RuntimeException('The route reservation could not be found.');
    }

     
    $origin = $trip['origin'] ?? $reservation['origin'] ?? trim($_POST['origin'] ?? '');
    $destination = $trip['destination'] ?? $reservation['destination'] ?? trim($_POST['destination'] ?? '');
    $vehicleId = $trip['vehicle_id'] ?? $reservation['assigned_vehicle_id'] ?? '';
    $driverId = $trip['driver_id'] ?? $reservation['assigned_driver_id'] ?? '';
    $waypointsJson = ($trip && !empty($trip['waypoints']))
        ? json_encode(array_values(array_filter(array_map('trim', preg_split('/[;\n]+/', $trip['waypoints']) ?: []))))
        : ($_POST['waypoints_json'] ?? '[]');
    if ($origin === '' || $destination === '') throw new RuntimeException('A valid origin and destination are required.');
    if ($trip && ($vehicleId === '' || $driverId === '')) {
        throw new RuntimeException('The trip must have an assigned vehicle and Driver.');
    }

    $vehicleInput = trim($_POST['vehicle'] ?? '');
    $vehicleTitle = $vehicleInput ?: 'Unassigned Vehicle';
    if ($vehicleId !== '') {
        $vehStmt = $pdo->prepare('SELECT * FROM vehicles WHERE id = ? FOR UPDATE');
        $vehStmt->execute([$vehicleId]);
        $vehicle = $vehStmt->fetch();
        if (!$vehicle) throw new RuntimeException('The assigned vehicle could not be found.');
        if ($vehicle['status'] === 'Maintenance') throw new RuntimeException('The assigned vehicle is under maintenance.');
        $maintenanceStmt = $pdo->prepare(
            "SELECT 1 FROM maintenance_orders WHERE vehicle_id = ? AND status = 'In Repair' LIMIT 1"
        );
        $maintenanceStmt->execute([$vehicleId]);
        if ($maintenanceStmt->fetchColumn()) {
            throw new RuntimeException('The assigned vehicle has an active maintenance repair.');
        }
        $vehicleTitle = trim($vehicle['brand'].' '.$vehicle['model'].' ('.$vehicle['plate_number'].')');
    } else {
        $specs = RouteThinkEngine::getVehicleSpecs($vehicleInput);
        $vehicleTitle = $specs['name'];
        $vehicleId = $specs['id'] ?? null;
    }

    $distanceKm = max(0, (float)($_POST['distance_km'] ?? 0));
    $durationMins = max(0, (int)($_POST['duration_mins'] ?? 0));
    $fuelLiters = max(0, (float)($_POST['fuel_liters'] ?? 0));
    $routeScore = trim($_POST['route_score'] ?? '');
    $modelVersion = trim($_POST['model_version'] ?? 'v0-kinematic');
    $featuresJson = $_POST['features_json'] ?? null;
    $routeDataJson = $_POST['route_data_json'] ?? null;
    $candidates = json_decode($_POST['candidates_json'] ?? '[]', true) ?: [];
    $selectedIndex = max(0, (int)($_POST['selected_index'] ?? 0));

    $routeTitle = trim($_POST['route_title'] ?? '');
    if ($presetId !== '') {
        $presetStmt = $pdo->prepare('SELECT name FROM route_presets WHERE id = ?');
        $presetStmt->execute([$presetId]);
        $routeTitle = $presetStmt->fetchColumn() ?: $routeTitle;
    }
    if ($routeTitle === '') $routeTitle = $origin.' to '.$destination;

    $navigationKey = hash('sha256', implode('|', [
        $tripId, $reservationId, $origin, $destination, $vehicleId, $mode,
        number_format($distanceKm, 2, '.', ''), $durationMins, $selectedIndex,
    ]));
    if ($tripId !== '') {
        $existingStmt = $pdo->prepare('SELECT log_id FROM route_history WHERE trip_id = ? AND navigation_key = ? LIMIT 1');
        $existingStmt->execute([$tripId, $navigationKey]);
        if ($existingLogId = $existingStmt->fetchColumn()) {
            $pdo->commit();
            echo json_encode(['ok'=>true, 'log_id'=>$existingLogId, 'already_saved'=>true,
                'message'=>"Route already saved for this trip ({$existingLogId})."]);
            exit;
        }
    }

    $modeLabels = [
        'balanced'=>'Balanced (ROUTETHINK)', 'fastest'=>'Fastest Express Corridor',
        'fuelEfficient'=>'Eco-Optimized Fuel Efficient', 'shortest'=>'Shortest Distance',
    ];
    $logId = next_sequential_id($pdo, 'route_history', 'log_id', 'LOG-AIR-');
    $varianceAccuracy = $routeScore !== '' ? "{$routeScore} Score" : 'Optimal Alignment';
    $stmt = $pdo->prepare(
        'INSERT INTO route_history (
          log_id, route_title, vehicle, vehicle_id, generated_date, selected_mode,
          variance_accuracy, model_version, predicted_duration_mins, predicted_fuel_liters,
          pre_trip_features_json, created_by, reservation_id, trip_id, origin, destination,
          waypoints_json, route_data_json, navigation_key
        ) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $logId, $routeTitle, $vehicleTitle, $vehicleId ?: null, $modeLabels[$mode],
        $varianceAccuracy, $modelVersion, $durationMins ?: null, $fuelLiters ?: null,
        is_string($featuresJson) ? $featuresJson : json_encode($featuresJson),
        $current_user['id'] ?? null, $reservationId ?: null, $tripId ?: null,
        $origin, $destination, $waypointsJson, $routeDataJson, $navigationKey,
    ]);

    if ($candidates) {
        $candStmt = $pdo->prepare(
            'INSERT INTO ai_route_candidates_log (log_id,candidate_index,summary_title,distance_km,
             duration_mins,traffic_duration_mins,estimated_fuel_l,toll_cost_est,composite_score,is_selected)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($candidates as $index => $candidate) {
            $candStmt->execute([
                $logId, $index, $candidate['summary'] ?? $candidate['title'] ?? ('Route '.($index+1)),
                (float)($candidate['distanceKm'] ?? 0), (int)($candidate['durationMins'] ?? 0),
                (int)($candidate['trafficDurationMins'] ?? $candidate['durationMins'] ?? 0),
                (float)($candidate['fuelEstimateLiters'] ?? 0), (float)($candidate['tollEstimate'] ?? 0),
                (int)($candidate['compositeScore'] ?? 0), $index === $selectedIndex ? 1 : 0,
            ]);
        }
    }

    if ($tripId !== '') {
        $pdo->prepare(
            "UPDATE trips SET route_history_id=?, navigation_active=1, status='In Transit', progress_pct=GREATEST(progress_pct,10),
             current_step='In Transit', actual_departure=COALESCE(actual_departure,NOW()),
             distance_km=CASE WHEN CAST(? AS NUMERIC)>0 THEN CAST(? AS NUMERIC) ELSE distance_km END WHERE id=?"
        )->execute([$logId, $distanceKm, $distanceKm, $tripId]);
        if ($reservationId !== '') {
            $pdo->prepare("UPDATE reservations SET status='In Transit' WHERE id=?")->execute([$reservationId]);
        }
        $pdo->prepare("UPDATE vehicles SET status='On Trip',assigned_driver_id=?,location=? WHERE id=?")
            ->execute([$driverId, 'In Transit: '.$origin.' → '.$destination, $vehicleId]);
        $pdo->prepare("UPDATE drivers SET status='On Trip' WHERE id=?")->execute([$driverId]);

        $check = $pdo->prepare("SELECT 1 FROM trip_timeline WHERE trip_id=? AND title='Navigation Started'");
        $check->execute([$tripId]);
        if (!$check->fetchColumn()) {
            $sort = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM trip_timeline WHERE trip_id=?');
            $sort->execute([$tripId]);
            $pdo->prepare("INSERT INTO trip_timeline (trip_id,title,event_time,completed,active_step,sort_order)
              VALUES (?,'Navigation Started',TO_CHAR(NOW(),'YYYY-MM-DD HH24:MI'),1,1,?)")
                ->execute([$tripId, (int)$sort->fetchColumn()]);
        }

        $du = $pdo->prepare('SELECT user_id FROM drivers WHERE id=?');
        $du->execute([$driverId]);
        if ($driverUserId = $du->fetchColumn()) {
            $pdo->prepare("INSERT INTO notifications (title,body,time_label,type,category,target,is_read,user_id)
              VALUES ('Navigation Started',?,'Just now','info','Trip','driver-trips',0,?)")
                ->execute(["Trip {$tripId} is now In Transit using route {$logId}.", $driverUserId]);
        }
    }

    $pdo->commit();
    echo json_encode(['ok'=>true, 'log_id'=>$logId, 'trip_id'=>$tripId ?: null,
        'message'=>$tripId ? "Route {$logId} saved and trip {$tripId} started." : "Route saved to Route History ({$logId})."]);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['ok'=>false, 'error'=>$ex->getMessage()]);
}
