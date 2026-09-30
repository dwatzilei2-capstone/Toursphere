<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/schedules.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
require_login();
if (!has_role('customer')) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'GET required']); exit; }
try {
    $date = trim((string)($_GET['date'] ?? ''));
    $passengers = filter_var($_GET['passengers'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>1000]]);
    if (!$passengers) throw new RuntimeException('Enter a valid passenger count first.');
    echo json_encode(['ok'=>true,'schedules'=>schedule_templates_for_date(db(), $date, (int)$passengers)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
