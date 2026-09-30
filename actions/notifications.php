<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    $pdo = db(); $uid = (int)$current_user['id'];
    $action = $_POST['action'] ?? 'poll';
    if (in_array($action, ['read','read_all'], true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        if (!hash_equals($_SESSION['notification_csrf'] ?? '', (string)($_POST['csrf_token'] ?? '')) || empty($_SESSION['notification_csrf'])) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin && parse_url($origin, PHP_URL_HOST) !== explode(':', $_SERVER['HTTP_HOST'])[0]) { http_response_code(403); exit; }
        $sql = 'UPDATE notifications SET is_read=1 WHERE user_id=?'; $params=[$uid];
        if ($action==='read') { $sql.=' AND id=?'; $params[]=(int)($_POST['id'] ?? 0); }
        $pdo->prepare($sql)->execute($params);
    }
    $pdo->beginTransaction();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $count=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0'); $count->execute([$uid]);
    $max=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM notifications WHERE user_id=?'); $max->execute([$uid]); $cursor=(int)$max->fetchColumn();
    $after=max(0,(int)($_GET['after'] ?? $cursor));
    $serialize=function($n) { $n['url']=notification_target_url($n['target']); $n['timestamp']=notification_time($n); $n['id']=(int)$n['id']; $n['is_read']=(int)$n['is_read']; return $n; };
    $recent=$pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY is_read ASC,id DESC LIMIT 6'); $recent->execute([$uid]);
    $new=$pdo->prepare('SELECT * FROM notifications WHERE user_id=? AND id>? AND id<=? ORDER BY id LIMIT 30'); $new->execute([$uid,$after,$cursor]); $events=$new->fetchAll();
    $next=count($events)===30 ? (int)end($events)['id'] : $cursor;
    $result=['ok'=>true,'unread'=>(int)$count->fetchColumn(),'cursor'=>$next,'items'=>array_map($serialize,$recent->fetchAll()),'events'=>array_map($serialize,$events)];
    if (!empty($_GET['center'])) { $all=$pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC'); $all->execute([$uid]); $result['all_items']=array_map($serialize,$all->fetchAll()); }
    $pdo->commit(); echo json_encode($result);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Notifications: '.$e->getMessage()); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Unable to load notifications.']);
}
