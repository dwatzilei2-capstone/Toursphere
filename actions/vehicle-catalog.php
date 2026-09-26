<?php

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (!can('vehicles.manage')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to register vehicles.']);
    exit;
}

$resource = $_GET['resource'] ?? '';
$parentId = filter_input(INPUT_GET, 'parent_id', FILTER_VALIDATE_INT);

try {
    $pdo = db();
    switch ($resource) {
        case 'brands':
            if (!$parentId) throw new InvalidArgumentException('A vehicle type is required.');
            $stmt = $pdo->prepare(
                "SELECT DISTINCT b.id, b.name
                   FROM vehicle_brands b
                   JOIN vehicle_models m ON m.brand_id = b.id
                  WHERE m.vehicle_type_id = ? AND b.status = 'Active' AND m.status = 'Active'
                  ORDER BY b.name"
            );
            break;
        case 'models':
            $typeId = filter_input(INPUT_GET, 'type_id', FILTER_VALIDATE_INT);
            if (!$parentId || !$typeId) throw new InvalidArgumentException('A vehicle type and brand are required.');
            $stmt = $pdo->prepare(
                "SELECT id, model_name AS name
                   FROM vehicle_models
                  WHERE brand_id = ? AND vehicle_type_id = ? AND status = 'Active'
                  ORDER BY model_name"
            );
            $stmt->execute([$parentId, $typeId]);
            echo json_encode(['ok' => true, 'items' => $stmt->fetchAll()]);
            exit;
        case 'variants':
            if (!$parentId) throw new InvalidArgumentException('A vehicle model is required.');
            $stmt = $pdo->prepare(
                "SELECT id, variant_name AS name, model_year, passenger_capacity,
                        fuel_tank_capacity, fuel_type
                   FROM vehicle_variants
                  WHERE model_id = ? AND status = 'Active'
                  ORDER BY variant_name, model_year DESC"
            );
            break;
        default:
            throw new InvalidArgumentException('Unsupported catalog request.');
    }

    $stmt->execute([$parentId]);
    echo json_encode(['ok' => true, 'items' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The vehicle catalog could not be loaded.']);
}
