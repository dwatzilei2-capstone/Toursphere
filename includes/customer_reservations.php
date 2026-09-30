<?php

/** Return customer-facing category/capacity choices, never physical vehicles. */
function customer_vehicle_requirement_options(PDO $pdo): array
{
    require_once ROOT_PATH . '/includes/vehicle_compliance.php';
    $rows = $pdo->query(
        "SELECT v.id, v.type, v.capacity, vt.id AS vehicle_type_id
           FROM vehicles v
           LEFT JOIN vehicle_variants vv ON vv.id = v.variant_id AND vv.status = 'Active'
           LEFT JOIN vehicle_models vm ON vm.id = vv.model_id AND vm.status = 'Active'
           LEFT JOIN vehicle_types vt ON vt.id = vm.vehicle_type_id AND vt.status = 'Active'
          WHERE v.capacity > 0
            AND v.status NOT IN ('Maintenance', 'On Trip')
            AND NOT EXISTS (SELECT 1 FROM maintenance_orders m WHERE m.vehicle_id = v.id AND m.status = 'In Repair')
          ORDER BY v.capacity, v.type, v.id"
    )->fetchAll();
    $options = [];
    foreach ($rows as $row) {
        if (!vehicle_operational_compliance($pdo, (string)$row['id'])['operational']) continue;
        $key = (string)$row['type'] . '|' . (int)$row['capacity'];
        $options[$key] ??= [
            'type' => (string)$row['type'],
            'capacity' => (int)$row['capacity'],
            'vehicle_type_id' => $row['vehicle_type_id'] === null ? null : (int)$row['vehicle_type_id'],
        ];
    }
    $options = array_values($options);
    usort($options, static fn(array $a, array $b): int => $a['capacity'] <=> $b['capacity'] ?: strcasecmp($a['type'], $b['type']));
    return $options;
}

function customer_required_vehicle_type(PDO $pdo, int $passengers): ?array
{
    if ($passengers < 1) return null;
    foreach (customer_vehicle_requirement_options($pdo) as $option) {
        if ($option['capacity'] >= $passengers) return $option;
    }
    return null;
}

function customer_fare_configuration(): array
{
    $baseRaw = fleet_setting('fare.base_fare', '');
    $rateRaw = fleet_setting('fare.rate_per_km', '');
    if ($baseRaw === '' || $rateRaw === '' || !is_numeric($baseRaw) || !is_numeric($rateRaw)) {
        throw new RuntimeException('Fare configuration is not available. Please contact the company before booking.');
    }
    $base = round((float)$baseRaw, 2);
    $rate = round((float)$rateRaw, 2);
    if ($base < 0 || $rate < 0) throw new RuntimeException('Fare configuration is invalid. Please contact the company.');
    return ['base_fare' => $base, 'rate_per_km' => $rate];
}

function customer_route_distance_km(string $origin, string $destination): float
{
    require_once ROOT_PATH . '/config/maps.php';
    $key = defined('GOOGLE_MAPS_SERVER_API_KEY') ? trim((string)GOOGLE_MAPS_SERVER_API_KEY) : '';
    if ($key === '' || $key === 'PASTE_YOUR_SERVER_DISTANCE_MATRIX_KEY_HERE') {
        throw new RuntimeException('Route calculation is temporarily unavailable. Please retry later.');
    }
    $query = http_build_query([
        'origins' => $origin,
        'destinations' => $destination,
        'mode' => 'driving',
        'units' => 'metric',
        'key' => $key,
    ], '', '&', PHP_QUERY_RFC3986);
    $url = 'https://maps.googleapis.com/maps/api/distancematrix/json?' . $query;
    $raw = false;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $raw = curl_exec($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($httpStatus < 200 || $httpStatus >= 300) $raw = false;
    } else {
        $context = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"]]);
        $raw = @file_get_contents($url, false, $context);
    }
    if ($raw === false) throw new RuntimeException('The routing service did not respond. Verify the locations or retry.');
    $data = json_decode($raw, true);
    $element = $data['rows'][0]['elements'][0] ?? null;
    if (($data['status'] ?? '') !== 'OK' || !is_array($element) || ($element['status'] ?? '') !== 'OK') {
        throw new RuntimeException('No valid driving route was found. Verify the pickup location and destination.');
    }
    $meters = filter_var($element['distance']['value'] ?? null, FILTER_VALIDATE_INT);
    if ($meters === false || $meters <= 0) throw new RuntimeException('The routing service returned an invalid distance. Verify the locations.');
    return round($meters / 1000, 2);
}

function customer_calculate_fare(string $origin, string $destination, int $passengers): array
{
    if ($passengers < 1) throw new RuntimeException('Passenger count must be at least 1.');
    $config = customer_fare_configuration();
    $distance = customer_route_distance_km($origin, $destination);
    $distanceCharge = round($distance * $config['rate_per_km'], 2);
    $perPerson = round($config['base_fare'] + $distanceCharge, 2);
    return [
        'base_fare' => $config['base_fare'],
        'rate_per_km' => $config['rate_per_km'],
        'route_distance_km' => $distance,
        'distance_charge' => $distanceCharge,
        'fare_per_person' => $perPerson,
        'passenger_count' => $passengers,
        'total_booking_fare' => round($perPerson * $passengers, 2),
        'currency_code' => currency_code(),
        'currency_symbol' => currency_symbol(),
        'fare_status' => 'Estimated',
    ];
}

function customer_vehicle_available(PDO $pdo, string $type, int $passengers, DateTimeImmutable $departure, ?DateTimeImmutable $return): bool
{
    require_once ROOT_PATH . '/includes/vehicle_compliance.php';
    $buffer = max(1, min(72, (int)fleet_setting('dispatch.buffer_hours', '4')));
    $end = $return ?? $departure->modify('+4 hours');
    $stmt = $pdo->prepare(
        "SELECT v.id FROM vehicles v
         WHERE v.type = ? AND v.capacity >= ? AND v.status NOT IN ('Maintenance', 'On Trip')
           AND NOT EXISTS (SELECT 1 FROM maintenance_orders m WHERE m.vehicle_id=v.id AND m.status='In Repair')
           AND NOT EXISTS (
             SELECT 1 FROM reservations r WHERE r.assigned_vehicle_id=v.id
               AND r.status IN ('Assigned','Confirmed','Dispatched','In Transit')
               AND r.departure_date::timestamp + COALESCE(NULLIF(r.departure_time,''),'00:00')::time < ?::timestamp + (? || ' hours')::interval
               AND COALESCE(r.return_date::timestamp + COALESCE(NULLIF(r.return_time,''),'23:59')::time,
                   r.departure_date::timestamp + COALESCE(NULLIF(r.departure_time,''),'00:00')::time + interval '4 hours') > ?::timestamp - (? || ' hours')::interval
           )
         ORDER BY v.capacity, v.id"
    );
    $stmt->execute([$type, $passengers, $end->format('Y-m-d H:i:s'), $buffer, $departure->format('Y-m-d H:i:s'), $buffer]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $vehicleId) {
        if (vehicle_operational_compliance($pdo, (string)$vehicleId)['operational']) return true;
    }
    return false;
}

function customer_cancellation_blocked(?array $trip): bool
{
    return $trip && ((int)$trip['navigation_active'] === 1 || $trip['actual_departure'] !== null
        || in_array($trip['status'], ['In Transit', 'Returning to Depot', 'Completed'], true));
}
