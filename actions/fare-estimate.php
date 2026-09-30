<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/customer_reservations.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!has_role('customer')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only customers can request a fare estimate.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST method required.']);
    exit;
}

try {
    $origin = trim((string)($_POST['origin'] ?? ''));
    $destination = trim((string)($_POST['destination'] ?? ''));
    $passengers = filter_var($_POST['passenger_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($origin === '' || $destination === '') throw new RuntimeException('Enter both the pickup location and destination.');
    if ($passengers === false || $passengers === null) throw new RuntimeException('Enter a valid positive passenger count.');

    $fare = customer_calculate_fare($origin, $destination, (int)$passengers);
    echo json_encode(['ok' => true, 'fare' => $fare], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error instanceof RuntimeException ? $error->getMessage() : 'The route fare could not be calculated. Please retry.']);
}
