<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
header('Content-Type: application/json');
header('Cache-Control: no-store');
$action = $_POST['action'] ?? 'status';
if ($action !== 'status') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($_POST['csrf'] ?? null)
        || empty($_SESSION['session_activity_csrf']) || !hash_equals($_SESSION['session_activity_csrf'], $_POST['csrf'])) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Refresh the page and try again.']); exit;
    }
    if ($action === 'logout') {
        session_invalidate();
        echo json_encode(['ok'=>true,'logged_out'=>true,'login_url'=>BASE_URL.'/login.php']); exit;
    }
    if ($action !== 'activity') { http_response_code(400); echo json_encode(['ok'=>false]); exit; }
    // Authentication already checked expiration before this timestamp can be renewed.
    if (session_timeout_applies($current_user['role_code'])) $_SESSION['last_valid_activity'] = time();
}
session_timeout_headers();
echo json_encode(['ok'=>true,'applies'=>session_timeout_applies($current_user['role_code']),
    'remaining'=>max(0, SESSION_IDLE_SECONDS - (time() - (int)($_SESSION['last_valid_activity'] ?? time())))]);
