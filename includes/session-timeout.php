<?php

const SESSION_IDLE_SECONDS = 300;
const SESSION_IDLE_MESSAGE = 'Your session expired due to inactivity. Please log in again.';

function session_timeout_applies(string $role): bool
{
    return in_array($role, ['fleet_admin', 'dispatcher', 'driver'], true);
}

function session_timeout_expired(array $state, int $now): bool
{
    return isset($state['last_valid_activity']) && $now - (int)$state['last_valid_activity'] >= SESSION_IDLE_SECONDS;
}

function session_request_is_passive(): bool
{
    $path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (!str_contains($path, '/actions/')) return false;
    $script = basename($path);
    return $script === 'session.php' || $script === 'route-navigation-state.php'
        || ($script === 'notifications.php' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET');
}

function session_invalidate(): void
{
    global $current_user, $current_permissions, $header_notifications, $unread_count;
    $_SESSION = [];
    $current_user = null; $current_permissions = []; $header_notifications = []; $unread_count = 0;
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        if (ini_get('session.use_cookies')) setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $params['path'], 'domain' => $params['domain'],
            'secure' => $params['secure'], 'httponly' => true, 'samesite' => $params['samesite'] ?? 'Lax',
        ]);
        session_destroy();
    }
}

function session_timeout_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent() || !isset($_SESSION['last_valid_activity'])) return;
    header('Cache-Control: no-store');
    header('X-TourSphere-Session-Remaining: ' . max(0, SESSION_IDLE_SECONDS - (time() - (int)$_SESSION['last_valid_activity'])));
}

function session_unauthorized(): never
{
    $expired = !empty($GLOBALS['session_idle_expired']);
    $url = BASE_URL . '/login.php' . ($expired ? '?error=' . rawurlencode(SESSION_IDLE_MESSAGE) : '');
    $ajax = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'session.php'
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || (isset($_SERVER['HTTP_SEC_FETCH_MODE']) && $_SERVER['HTTP_SEC_FETCH_MODE'] !== 'navigate');
    if ($ajax) {
        http_response_code(401); header('Content-Type: application/json'); header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'session_expired' => $expired, 'error' => $expired ? SESSION_IDLE_MESSAGE : 'Please log in again.', 'login_url' => $url]);
        exit;
    }
    redirect_to($url);
}
