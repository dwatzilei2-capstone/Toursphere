<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/routethink_engine.php';
require_once ROOT_PATH . '/includes/vehicle_compliance.php';
require_once ROOT_PATH . '/includes/driver_vehicle_assignment.php';
require_once ROOT_PATH.'/includes/route_planner_state.php';
require_login();
header('Content-Type: application/json; charset=UTF-8');
if (!has_role('driver') && (!empty($_POST['trip_id']) || !empty($_POST['reservation_id']))) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Trip routes are read-only for non-Driver users.']);
    exit;
}

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

    if(!is_string($_POST['state_csrf']??null)||empty($_SESSION['route_state_csrf'])||!hash_equals($_SESSION['route_state_csrf'],$_POST['state_csrf']))throw new RuntimeException('Navigation session expired. Refresh the page.');
    $phase=(string)($_POST['route_phase']??'outbound');
    $scope=route_planner_scope($pdo,$tripId,$phase);
    $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute([$scope['key']]);
    $plannerState=route_planner_load($pdo,$scope['key']);
    $applied=$plannerState['state_data']['applied']??null;
    if(!$applied || !in_array($plannerState['lifecycle'],['APPLIED','NAVIGATING'],true) || ($applied['routeId']??'')!==($_POST['route_id']??''))throw new RuntimeException('Apply the selected route before starting navigation.');
    route_planner_validate($plannerState['state_data']);
    $mode=$applied['mode'];$isReturn=$phase==='return';
    $chosen=$applied['directions']['routes'][$applied['evaluation']['selectedIndex']];
    $lastLeg=$chosen['legs'][array_key_last($chosen['legs'])];
    $routePayload=['mode'=>$mode,'routeId'=>$applied['routeId'],'phase'=>$phase,'directions'=>$applied['directions'],'route'=>$applied['data'],'selectedCandidate'=>$applied['evaluation']['selectedCandidate'],'destinationLocation'=>$lastLeg['end_location']];
    // Navigation consumes the persisted applied snapshot, never posted/cached preview metrics.
    $_POST['distance_km']=$applied['data']['distanceKm'];$_POST['duration_mins']=$applied['data']['durationMins'];
    $_POST['fuel_liters']=$applied['data']['fuelEstimateLiters']??0;$_POST['route_score']=$applied['data']['routeScore'];
    $_POST['model_version']=$applied['data']['modelVersion'];$_POST['features_json']=json_encode($applied['evaluation']['selectedCandidate']['features']??[]);
    $_POST['candidates_json']=json_encode($applied['evaluation']['candidates']);$_POST['selected_index']=$applied['evaluation']['selectedIndex'];$_POST['route_data_json']=json_encode($routePayload);
    $_POST['origin']=$plannerState['state_data']['inputs']['origin'];$_POST['destination']=$plannerState['state_data']['inputs']['destination'];$_POST['waypoints_json']=json_encode($plannerState['state_data']['inputs']['waypoints']);

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

    if($trip && $trip['current_step']==='Arrived')throw new RuntimeException('This navigation leg has already arrived.');
    if ($trip && !in_array($trip['status'], ['Dispatched', 'In Transit', 'Returning to Depot'], true)) {
        throw new RuntimeException('This trip has not been dispatched yet. Please wait for the Dispatcher/Admin.');
    }
    if ($trip && in_array($trip['status'], ['In Transit', 'Returning to Depot'], true) && empty($trip['route_history_id'])) {
        throw new RuntimeException('An active trip cannot be started again without its saved navigation route.');
    }
    if ($trip) {
        $inputs=$plannerState['state_data']['inputs'];
        $expectedOrigin=$isReturn?$trip['destination']:$trip['origin'];
        $expectedDestination=$isReturn?(getenv('FLEET_DEPOT_ADDRESS')?:$trip['origin']):$trip['destination'];
        if(strcasecmp(trim($inputs['origin']),trim($expectedOrigin)) || strcasecmp(trim($inputs['destination']),trim($expectedDestination)) || ($inputs['vehicleId']??'')!==$trip['vehicle_id'])throw new RuntimeException('The applied route no longer matches this trip assignment. Generate and apply its updated route.');
        $tripId = $trip['id'];
        $reservationId = $trip['reservation_id'] ?? $reservationId;
        $assignmentCheck = $pdo->prepare('SELECT status,assigned_vehicle_id,assigned_driver_id FROM reservations WHERE id=? FOR UPDATE');
        $assignmentCheck->execute([$reservationId]);
        $assignedReservation = $assignmentCheck->fetch();
        if (!$assignedReservation || !in_array($assignedReservation['status'], ['Dispatched','In Transit','Returning to Depot'], true)
            || $assignedReservation['assigned_driver_id'] !== $trip['driver_id']
            || $assignedReservation['assigned_vehicle_id'] !== $trip['vehicle_id']) {
            throw new RuntimeException('This trip has not been dispatched with its current assignment. Please wait for the Dispatcher/Admin.');
        }
    }
    if (!$trip) {
        $resume=$pdo->prepare("SELECT log_id FROM route_history WHERE created_by=? AND route_data_json IS NOT NULL AND NULLIF(route_data_json,'')::jsonb->>'routeId'=? ORDER BY id DESC LIMIT 1");
        $resume->execute([$current_user['id'],$applied['routeId']]);
        if($existingLogId=$resume->fetchColumn()){
            $pdo->prepare("UPDATE route_planner_states SET lifecycle='NAVIGATING',updated_at=clock_timestamp() WHERE scope_key=?")->execute([$scope['key']]);
            $pdo->commit();echo json_encode(['ok'=>true,'log_id'=>$existingLogId,'already_saved'=>true,'message'=>'Saved route resumed.']);exit;
        }
    }

     
     
    if ($trip && in_array($trip['status'], ['In Transit','Returning to Depot'], true) && !empty($trip['route_history_id'])) {
        $activeRouteStmt = $pdo->prepare("SELECT log_id,route_data_json FROM route_history WHERE log_id = ? AND trip_id = ? AND COALESCE(NULLIF(route_data_json,'')::jsonb->>'phase','outbound') = ?");
        $activeRouteStmt->execute([$trip['route_history_id'], $tripId, $phase]);
        if ($activeRoute = $activeRouteStmt->fetch()) {
            $activeLogId=$activeRoute['log_id'];$activeData=json_decode((string)$activeRoute['route_data_json'],true);
            if(!empty($activeData['mode']) && $activeData['mode']!==$mode)throw new RuntimeException('An active navigation session must retain its applied mode.');
            if(($activeData['routeId']??'')!==$applied['routeId'])$pdo->prepare('UPDATE route_history SET route_data_json=? WHERE log_id=?')->execute([json_encode($routePayload),$activeLogId]);
            $pdo->prepare('UPDATE trips SET navigation_active = 1 WHERE id = ?')->execute([$tripId]);
            $pdo->prepare("UPDATE route_planner_states SET lifecycle='NAVIGATING',updated_at=clock_timestamp() WHERE scope_key=?")->execute([$scope['key']]);
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
    if ($trip && $trip['status'] !== 'Dispatched' && !($isReturn && $trip['status']==='Returning to Depot')) {
        throw new RuntimeException('The saved navigation route could not be resumed.');
    }
    $reservation = null;
    if ($reservationId !== '') {
        $resStmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? FOR UPDATE');
        $resStmt->execute([$reservationId]);
        $reservation = $resStmt->fetch();
        if (!$reservation) throw new RuntimeException('The route reservation could not be found.');
        if ($trip && (!in_array($reservation['status'], $isReturn?['Returning to Depot']:['Dispatched'],true)
            || $reservation['assigned_vehicle_id'] !== $trip['vehicle_id']
            || $reservation['assigned_driver_id'] !== $trip['driver_id'])) {
            throw new RuntimeException('This trip has not been dispatched with its current assignment. Please wait for the Dispatcher/Admin.');
        }
    }

     
    $origin = $plannerState['state_data']['inputs']['origin'];
    $destination = $plannerState['state_data']['inputs']['destination'];
    $vehicleId = $trip['vehicle_id'] ?? $reservation['assigned_vehicle_id'] ?? '';
    $driverId = $trip['driver_id'] ?? $reservation['assigned_driver_id'] ?? '';
    if ($tripId !== '' && $vehicleId !== '' && $driverId !== '') {
        $resourceLock = $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(?)), pg_advisory_xact_lock(hashtext(?))");
        $resourceLock->execute(['dispatch-driver:' . $driverId, 'dispatch-vehicle:' . $vehicleId]);
    }
    $waypointsJson = json_encode($plannerState['state_data']['inputs']['waypoints']);
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
        $compliance = vehicle_operational_compliance($pdo, $vehicleId);
        if (!$compliance['operational']) {
            throw new RuntimeException('Vehicle cannot start an actual trip because required compliance documents are incomplete or invalid. ' . $compliance['reason']);
        }
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
        $specs = RouteThinkEngine::getVehicleSpecs($plannerState['state_data']['inputs']['vehicleId'] ?: $vehicleInput);
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
            $pdo->prepare("UPDATE route_planner_states SET lifecycle='NAVIGATING',updated_at=clock_timestamp() WHERE scope_key=?")->execute([$scope['key']]);
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
        if (driver_has_active_trip($pdo, $driverId, $tripId)) throw new RuntimeException('This driver is already operating another active trip.');
        if (vehicle_has_active_trip($pdo, $vehicleId, $tripId)) throw new RuntimeException('This vehicle is already being used by another active trip.');
        $pdo->prepare(
            "UPDATE trips SET route_history_id=?, navigation_active=1, status=CASE WHEN status='Returning to Depot' THEN status ELSE 'In Transit' END, progress_pct=GREATEST(progress_pct,10),
             current_step=CASE WHEN status='Returning to Depot' THEN 'Returning to Depot' ELSE 'In Transit' END, actual_departure=COALESCE(actual_departure,NOW()),
             distance_km=CASE WHEN CAST(? AS NUMERIC)>0 THEN CAST(? AS NUMERIC) ELSE distance_km END WHERE id=?"
        )->execute([$logId, $distanceKm, $distanceKm, $tripId]);
        if ($reservationId !== '') {
            $pdo->prepare("UPDATE reservations SET status=CASE WHEN status='Returning to Depot' THEN status ELSE 'In Transit' END WHERE id=?")->execute([$reservationId]);
        }
        $pdo->prepare("UPDATE vehicles SET status='On Trip',location=? WHERE id=?")
            ->execute(['In Transit: '.$origin.' → '.$destination, $vehicleId]);
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

    }

    $pdo->prepare("UPDATE route_planner_states SET lifecycle='NAVIGATING',updated_at=clock_timestamp() WHERE scope_key=?")->execute([$scope['key']]);
    $pdo->commit();
    echo json_encode(['ok'=>true, 'log_id'=>$logId, 'trip_id'=>$tripId ?: null,
        'message'=>$tripId ? "Route {$logId} saved and trip {$tripId} started." : "Route saved to Route History ({$logId})."]);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['ok'=>false, 'error'=>$ex->getMessage()]);
}
