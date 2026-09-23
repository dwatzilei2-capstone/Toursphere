<?php
 



require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

header('Content-Type: application/json');

try {
    $pdo = db();
    $action = $_POST['action'] ?? ($_GET['action'] ?? '');

    if ($action === 'read') {
        $id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
        if ($id > 0) {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id IS NULL OR user_id = ?)')
                ->execute([$id, $current_user['id']]);
        }
    } elseif ($action === 'read_all') {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id IS NULL OR user_id = ?')
            ->execute([$current_user['id']]);
    }

    $unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND (user_id IS NULL OR user_id = ?)');
    $unreadStmt->execute([$current_user['id']]);
    $unread = (int)$unreadStmt->fetchColumn();
    echo json_encode(['ok' => true, 'unread' => $unread]);
} catch (Exception $ex) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
}
