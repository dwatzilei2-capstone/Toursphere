<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
if (!has_role('customer')) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
$stmt = db()->prepare('SELECT name, phone FROM users WHERE id=? AND status=?');
$stmt->execute([$current_user['id'], 'Active']);
$profile = $stmt->fetch();
if (!$profile) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}
echo json_encode(['ok' => true, 'name' => $profile['name'], 'phone' => $profile['phone'] ?? '']);
