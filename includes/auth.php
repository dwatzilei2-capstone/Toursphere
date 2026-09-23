<?php
 




if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

 
$current_user = null;

 
$current_permissions = [];

 
$unread_count = 0;

 
$header_notifications = [];

 



function load_current_user(): void
{
    global $current_user, $current_permissions, $unread_count, $header_notifications;

    if (empty($_SESSION['user_id'])) {
        $current_user = null;
        return;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            "SELECT u.*, r.code AS role_code, r.name AS role_name
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.id = ? AND u.status = 'Active'"
        );
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            session_unset();
            session_destroy();
            $current_user = null;
            return;
        }
        $current_user = $user;

         
        $stmt = $pdo->prepare(
            'SELECT p.code FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = ?'
        );
        $stmt->execute([$user['role_id']]);
        $current_permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);

         
        $notifCount = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND (user_id IS NULL OR user_id = ?)');
        $notifCount->execute([$user['id']]);
        $unread_count = (int)$notifCount->fetchColumn();
        $notifStmt = $pdo->prepare(
            'SELECT * FROM notifications WHERE user_id IS NULL OR user_id = ? ORDER BY is_read ASC, id DESC LIMIT 6'
        );
        $notifStmt->execute([$user['id']]);
        $header_notifications = $notifStmt->fetchAll();
    } catch (Exception $ex) {
        $current_user = null;
    }
}

 
function is_logged_in(): bool
{
    global $current_user;
    return $current_user !== null;
}

 
function require_login(): void
{
    if (!is_logged_in()) {
        redirect_to(BASE_URL . '/login.php');
    }
}

 
function can(string $permission): bool
{
    global $current_permissions, $current_user;
    if (!$current_user) {
        return false;
    }
    if ($current_user['role_code'] === 'fleet_admin') {
        return true;
    }
    return in_array($permission, $current_permissions, true);
}

 
function has_role($roles): bool
{
    global $current_user;
    if (!$current_user) {
        return false;
    }
    $role_code = $current_user['role_code'] ?? '';
    if (is_array($roles)) {
        return in_array($role_code, $roles, true);
    }
    return $role_code === (string)$roles;
}

 
function require_permission(string $permission): void
{
    if (!can($permission)) {
        redirect_with_toast(BASE_URL . '/' . home_path(), 'You do not have permission to access that module or feature.', 'warning');
    }
}

 
function home_path(): string
{
    global $current_user;
    if ($current_user && $current_user['role_code'] === 'driver') {
        return 'modules/driver-portal/driver-dashboard.php';
    }
    return 'dashboard.php';
}

 
function current_user(): ?array
{
    global $current_user;
    return $current_user;
}
