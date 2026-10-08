<?php

if(!defined('TOURSPHERE_JSON_REQUEST'))define('TOURSPHERE_JSON_REQUEST',true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($current_user['role_code'] ?? '') !== 'driver') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Driver access required.']);
        exit;
    }
    $stmt = db()->prepare('SELECT t.id,t.status FROM trips t JOIN drivers d ON d.id=t.driver_id WHERE d.user_id=?');
    $stmt->execute([$current_user['id']]);
    echo json_encode(['ok' => true, 'trips' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!can('ai.manage') && !can('ai.navigate')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to control navigation.']);
    exit;
}

$tripId = trim($_POST['trip_id'] ?? '');
if (!has_role('driver')) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Trip navigation is controlled by the assigned Driver.']);
    exit;
}
if ($tripId === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'A trip is required.']);
    exit;
}

try {
    $pdo = db();
    $isDriver = (($current_user['role_code'] ?? '') === 'driver');

    $lat = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $accuracy = filter_var($_POST['accuracy'] ?? null, FILTER_VALIDATE_FLOAT);
    $timestamp = filter_var($_POST['timestamp'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || $accuracy === false || $timestamp === false
        || abs($lat) > 90 || abs($lng) > 180 || $accuracy <= 0 || $accuracy > 25
        || abs(microtime(true) * 1000 - $timestamp) > 15000) {
        throw new RuntimeException('A recent, precise GPS location is required to record arrival.');
    }
    $pdo->beginTransaction();
    $pdo->exec("SET LOCAL lock_timeout='5s'; SET LOCAL statement_timeout='10s'");
    $stmt = $pdo->prepare("SELECT t.*, h.route_data_json FROM trips t
        LEFT JOIN route_history h ON h.log_id=t.route_history_id
        WHERE t.id=?" . ($isDriver ? " AND t.driver_id IN (SELECT id FROM drivers WHERE user_id=?)" : '') . " FOR UPDATE OF t");
    $stmt->execute($isDriver ? [$tripId, $current_user['id']] : [$tripId]);
    $trip = $stmt->fetch();
    if (!$trip || !in_array($trip['status'], ['In Transit', 'Returning to Depot'], true)) {
        throw new RuntimeException('The active trip was not found or is not assigned to you.');
    }
    $route = json_decode($trip['route_data_json'] ?? '', true) ?: [];
    $destination = $route['destinationLocation'] ?? null;
    if (!is_array($destination) || !isset($destination['lat'], $destination['lng'])) {
        throw new RuntimeException('The saved route has no destination coordinates. Reload and generate the route to enable arrival detection.');
    }
    $latDelta = deg2rad((float)$destination['lat'] - $lat);
    $lngDelta = deg2rad((float)$destination['lng'] - $lng);
    $haversine = sin($latDelta / 2) ** 2 + cos(deg2rad($lat))
        * cos(deg2rad((float)$destination['lat'])) * sin($lngDelta / 2) ** 2;
    $distance = 6371000 * 2 * asin(sqrt(min(1, max(0, $haversine))));
    if ($distance + $accuracy > 50) {
        throw new RuntimeException('You have not reached the destination radius.');
    }
    if ((int)$trip['navigation_active'] !== 1 && $trip['current_step'] !== 'Arrived') {
        throw new RuntimeException('No active navigation session was found.');
    }
    // Passenger arrival is distinct from completing the depot return and vehicle handover.
    $pdo->prepare("UPDATE trips SET navigation_active=0, current_step='Arrived',
        progress_pct=CASE WHEN status='Returning to Depot' THEN 100 ELSE 90 END,
        actual_arrival=COALESCE(actual_arrival,NOW()) WHERE id=?")->execute([$tripId]);
    $pdo->prepare('UPDATE trip_timeline SET active_step=0 WHERE trip_id=?')->execute([$tripId]);
    $event = $pdo->prepare("SELECT 1 FROM trip_timeline WHERE trip_id=? AND title='Arrived' LIMIT 1");
    $event->execute([$tripId]);
    if (!$event->fetchColumn()) {
        $pdo->prepare("INSERT INTO trip_timeline (trip_id,title,event_time,completed,active_step,sort_order)
            SELECT ?, 'Arrived', TO_CHAR(NOW(),'YYYY-MM-DD HH24:MI'),1,0,COALESCE(MAX(sort_order),0)+1
            FROM trip_timeline WHERE trip_id=?")->execute([$tripId,$tripId]);
    }
    $pdo->commit();

    echo json_encode(['ok' => true, 'trip_id' => $tripId, 'navigation_active' => false]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
