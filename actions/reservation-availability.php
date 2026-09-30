<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_compliance.php';
require_once ROOT_PATH . '/includes/schedules.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_login();

if (!can('dispatch.view')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to view reservations.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET method required.']);
    exit;
}

$reservationId = trim($_GET['id'] ?? '');
if ($reservationId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A reservation ID is required.']);
    exit;
}

try {
    $pdo = db();
    $reservationStmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
    $reservationStmt->execute([$reservationId]);
    $reservation = $reservationStmt->fetch();
    if (!$reservation) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Reservation not found.']);
        exit;
    }

    $data = [
        'ok' => true,
        'can_review' => has_role(['fleet_admin', 'dispatcher']),
        'reservation' => [
            'id' => (string)$reservation['id'],
            'client_name' => (string)$reservation['client_name'],
            'contact_person' => (string)($reservation['contact_person'] ?? ''),
            'contact_phone' => (string)($reservation['contact_phone'] ?? ''),
            'origin' => (string)$reservation['origin'],
            'destination' => (string)$reservation['destination'],
            'departure_date' => (string)$reservation['departure_date'],
            'departure_time' => (string)($reservation['departure_time'] ?? ''),
            'departure_schedule_name' => (string)($reservation['departure_schedule_name'] ?? ''),
            'return_date' => (string)($reservation['return_date'] ?? ''),
            'return_time' => (string)($reservation['return_time'] ?? ''),
            'return_schedule_name' => (string)($reservation['return_schedule_name'] ?? ''),
            'passenger_count' => (int)$reservation['passenger_count'],
            'vehicle_requested' => (string)($reservation['vehicle_requested'] ?? ''),
            'required_capacity' => $reservation['required_capacity'] === null ? null : (int)$reservation['required_capacity'],
            'trip_type' => (string)($reservation['trip_type'] ?? ''),
            'estimated_cost' => $reservation['estimated_cost'] === null ? '' : money($reservation['estimated_cost']),
            'base_fare_used' => $reservation['base_fare_used'] === null ? '' : money($reservation['base_fare_used']),
            'rate_per_km_used' => $reservation['rate_per_km_used'] === null ? '' : money($reservation['rate_per_km_used']),
            'route_distance_km' => $reservation['route_distance_km'] === null ? '' : number_format((float)$reservation['route_distance_km'], 2) . ' km',
            'distance_charge' => $reservation['distance_charge'] === null ? '' : money($reservation['distance_charge']),
            'fare_per_person' => $reservation['fare_per_person'] === null ? '' : money($reservation['fare_per_person']),
            'total_booking_fare' => $reservation['total_booking_fare'] === null ? '' : money($reservation['total_booking_fare']),
            'fare_status' => (string)($reservation['fare_status'] ?? ''),
            'notes' => (string)($reservation['notes'] ?? ''),
            'status' => (string)$reservation['status'],
        ],
    ];
    if ($reservation['departure_schedule_instance_id']) {
        $instanceStmt = $pdo->prepare('SELECT capacity FROM scheduled_departures WHERE id=?');
        $instanceStmt->execute([$reservation['departure_schedule_instance_id']]);
        $instanceCapacity = $instanceStmt->fetchColumn();
        $data['reservation']['scheduled_passenger_demand'] = schedule_reserved_demand($pdo, (int)$reservation['departure_schedule_instance_id']);
        $data['reservation']['scheduled_capacity'] = $instanceCapacity === false ? null : (int)$instanceCapacity;
    } else {
        $data['reservation']['scheduled_passenger_demand'] = null;
        $data['reservation']['scheduled_capacity'] = null;
    }

    if ($reservation['status'] !== 'Pending Approval') {
        $data['availability'] = ['applicable' => false];
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $start = new DateTimeImmutable($reservation['departure_date'] . ' ' . ($reservation['departure_time'] ?: '00:00'));
    $end = $reservation['return_date'] && $reservation['return_time']
        ? new DateTimeImmutable($reservation['return_date'] . ' ' . $reservation['return_time'])
        : $start->modify('+4 hours');
    $bufferHours = max(1, min(72, (int)fleet_setting('dispatch.buffer_hours', '4')));

    $tripConflictsStmt = $pdo->prepare(
        "SELECT DISTINCT vehicle_id, driver_id FROM trips
          WHERE reservation_id <> ? AND status <> 'Completed'
            AND scheduled_departure BETWEEN (?::timestamp - (? || ' hours')::interval)
                                        AND (?::timestamp + (? || ' hours')::interval)"
    );
    $tripConflictsStmt->execute([
        $reservationId,
        $start->format('Y-m-d H:i:s'), $bufferHours,
        $start->format('Y-m-d H:i:s'), $bufferHours,
    ]);
    $tripConflictVehicles = [];
    $tripConflictDrivers = [];
    foreach ($tripConflictsStmt->fetchAll() as $conflict) {
        if ($conflict['vehicle_id'] !== null) $tripConflictVehicles[(string)$conflict['vehicle_id']] = true;
        if ($conflict['driver_id'] !== null) $tripConflictDrivers[(string)$conflict['driver_id']] = true;
    }

    $reservationConflictsStmt = $pdo->prepare(
        "SELECT DISTINCT assigned_vehicle_id FROM reservations
          WHERE id <> ? AND assigned_vehicle_id IS NOT NULL
            AND status IN ('Assigned','Confirmed','Dispatched','In Transit')
            AND departure_date::timestamp + COALESCE(NULLIF(departure_time,''),'00:00')::time
                < ?::timestamp + (? || ' hours')::interval
            AND COALESCE(return_date::timestamp + COALESCE(NULLIF(return_time,''),'23:59')::time,
                departure_date::timestamp + COALESCE(NULLIF(departure_time,''),'00:00')::time + interval '4 hours')
                > ?::timestamp - (? || ' hours')::interval"
    );
    $reservationConflictsStmt->execute([
        $reservationId,
        $end->format('Y-m-d H:i:s'), $bufferHours,
        $start->format('Y-m-d H:i:s'), $bufferHours,
    ]);
    $reservationConflictVehicles = array_fill_keys(
        array_map('strval', $reservationConflictsStmt->fetchAll(PDO::FETCH_COLUMN)),
        true
    );

    $vehicleBlockers = [];
    $addBlocker = static function (array &$blockers, string $reason): void {
        $blockers[$reason] = ($blockers[$reason] ?? 0) + 1;
    };
    $vehiclesStmt = $pdo->query(
        "SELECT v.id, v.plate_number, v.type, v.capacity, v.status,
                EXISTS (SELECT 1 FROM maintenance_orders m WHERE m.vehicle_id = v.id AND m.status = 'In Repair') AS in_repair
           FROM vehicles v ORDER BY v.type, v.plate_number"
    );
    $availableVehicles = [];
    $typeMatchCount = 0;
    $capacityMatchCount = 0;
    foreach ($vehiclesStmt->fetchAll() as $vehicle) {
        if ($reservation['customer_id'] && $vehicle['type'] !== $reservation['vehicle_requested']) continue;
        $typeMatchCount++;
        if ((int)$vehicle['capacity'] < (int)$reservation['passenger_count']) {
            $addBlocker($vehicleBlockers, 'Below requested passenger capacity');
            continue;
        }
        $capacityMatchCount++;
        if (in_array($vehicle['status'], ['Maintenance', 'On Trip'], true)) {
            $addBlocker($vehicleBlockers, $vehicle['status'] === 'Maintenance' ? 'Vehicle marked for maintenance' : 'Vehicle currently on a trip');
            continue;
        }
        if (in_array($vehicle['in_repair'], [true, 1, '1', 't', 'true'], true)) {
            $addBlocker($vehicleBlockers, 'Active maintenance repair');
            continue;
        }
        if (isset($tripConflictVehicles[(string)$vehicle['id']])) {
            $addBlocker($vehicleBlockers, 'Conflicting trip within the dispatch buffer');
            continue;
        }
        if (isset($reservationConflictVehicles[(string)$vehicle['id']])) {
            $addBlocker($vehicleBlockers, 'Overlapping assigned reservation within the dispatch buffer');
            continue;
        }
        $compliance = vehicle_operational_compliance($pdo, (string)$vehicle['id']);
        if (!$compliance['operational']) {
            $addBlocker($vehicleBlockers, 'Compliance: ' . $compliance['reason']);
            continue;
        }
        $availableVehicles[] = $vehicle['plate_number'] . ' · ' . $vehicle['type'] . ' · ' . (int)$vehicle['capacity'] . ' pax';
    }
    if ($typeMatchCount === 0) {
        $addBlocker($vehicleBlockers, $reservation['customer_id'] ? 'No vehicle matches the requested type' : 'No vehicles are registered');
    }

    $driverBlockers = [];
    $driversStmt = $pdo->query('SELECT id, name, status, license_expiration FROM drivers ORDER BY name');
    $availableDrivers = [];
    foreach ($driversStmt->fetchAll() as $driver) {
        if ($driver['status'] === 'On Trip') {
            $addBlocker($driverBlockers, 'Driver currently on a trip');
            continue;
        }
        if (!empty($driver['license_expiration']) && $driver['license_expiration'] < date('Y-m-d')) {
            $addBlocker($driverBlockers, 'Expired driver license');
            continue;
        }
        if (isset($tripConflictDrivers[(string)$driver['id']])) {
            $addBlocker($driverBlockers, 'Conflicting trip within the dispatch buffer');
            continue;
        }
        $availableDrivers[] = (string)$driver['name'];
    }
    if (!$availableDrivers && !$driverBlockers) $addBlocker($driverBlockers, 'No drivers are registered');

    $formatBlockers = static function (array $blockers): array {
        $formatted = [];
        foreach ($blockers as $reason => $count) {
            $formatted[] = ['reason' => $reason, 'count' => $count];
        }
        return $formatted;
    };
    $data['availability'] = [
        'applicable' => true,
        'buffer_hours' => $bufferHours,
        'vehicles' => [
            'matched_count' => $capacityMatchCount,
            'available_count' => count($availableVehicles),
            'available_items' => array_slice($availableVehicles, 0, 5),
            'blockers' => $formatBlockers($vehicleBlockers),
        ],
        'drivers' => [
            'available_count' => count($availableDrivers),
            'available_items' => array_slice($availableDrivers, 0, 5),
            'blockers' => $formatBlockers($driverBlockers),
        ],
    ];
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Availability could not be checked right now.']);
}
