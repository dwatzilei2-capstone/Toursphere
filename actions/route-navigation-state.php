<?php

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=UTF-8');

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
if ($tripId === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'A trip is required.']);
    exit;
}

try {
    $pdo = db();
    $isDriver = (($current_user['role_code'] ?? '') === 'driver');

    if ($isDriver) {
        $stmt = $pdo->prepare(
            "UPDATE trips t
                SET navigation_active = 0
               FROM drivers d
              WHERE t.id = ? AND t.driver_id = d.id AND d.user_id = ?
                AND t.status IN ('In Transit','Returning to Depot')"
        );
        $stmt->execute([$tripId, $current_user['id']]);
    } else {
        $stmt = $pdo->prepare(
            "UPDATE trips SET navigation_active = 0
              WHERE id = ? AND status IN ('In Transit','Returning to Depot')"
        );
        $stmt->execute([$tripId]);
    }

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The active navigation session was not found or is not assigned to you.');
    }

    echo json_encode(['ok' => true, 'trip_id' => $tripId, 'navigation_active' => false]);
} catch (Throwable $error) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
