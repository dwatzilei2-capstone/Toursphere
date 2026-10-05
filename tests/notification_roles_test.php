<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';

// Render the actual shared layout using each role's active database account.
// No login bypass routes or persistent test notifications are created.
foreach (['fleet_admin', 'dispatcher', 'driver', 'customer'] as $role) {
    $stmt = db()->prepare("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code=? AND u.status='Active' ORDER BY u.id LIMIT 1");
    $stmt->execute([$role]);
    $uid = $stmt->fetchColumn();
    if (!$uid) throw new RuntimeException("No active account available for $role verification");
    $_SESSION = ['user_id' => $uid];
    load_current_user();
    if (!$current_user || !can('notifications.view')) throw new RuntimeException("Notification access failed for $role");
    $active_page = 'notifications';
    $page_title = 'Notifications';
    ob_start();
    require ROOT_PATH . '/includes/header.php';
    require ROOT_PATH . '/includes/footer.php';
    $html = ob_get_clean();
    foreach (['id="header-notif-btn"', 'id="notification-preview"', 'class="dropdown notification-anchor"', '/js/notifications.js?', 'aria-live="polite"', 'data-user-id="' . $uid . '"', 'data-csrf="'] as $required) {
        if (!str_contains($html, $required)) throw new RuntimeException("Missing $required for $role");
    }
    if (substr_count($html, 'id="notification-preview"') !== 1) throw new RuntimeException("Duplicate preview for $role");
    foreach ($header_notifications as $notification) {
        if ((int)$notification['user_id'] !== (int)$uid) throw new RuntimeException("Inbox isolation failed for $role");
    }
    echo "PASS $role: shared bell, preview, live script, CSRF, access and private inbox\n";
}
