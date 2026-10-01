<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$pdo=db(); $pdo->beginTransaction();
try {
    $d=$pdo->query("SELECT d.id,d.user_id FROM drivers d JOIN users u ON u.id=d.user_id JOIN roles r ON r.id=u.role_id WHERE r.code='driver' AND u.status='Active' ORDER BY d.id LIMIT 1")->fetch();
    if (!$d) throw new RuntimeException('No linked driver account.');
    $vehicle=$pdo->query('SELECT id FROM vehicles ORDER BY id LIMIT 1')->fetchColumn();
    $tripId='NT-'.bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO trips(id,origin,destination,status,driver_id,vehicle_id,scheduled_departure) VALUES (?,'Test Origin','Test Destination','Assigned',?,?,NOW()+interval '1 day')")->execute([$tripId,$d['id'],$vehicle]);
    $q=$pdo->prepare('SELECT title,body,is_read FROM notifications WHERE user_id=? AND target=? ORDER BY id DESC LIMIT 1');
    $q->execute([$d['user_id'],'trip-details:'.$tripId]); $n=$q->fetch();
    if (!$n || $n['title']!=='New Trip Assigned to You' || !str_contains($n['body'],'Test Origin to Test Destination') || !str_contains($n['body'],$vehicle) || (int)$n['is_read']!==0) throw new RuntimeException('Assignment message missing or unclear.');
    echo "PASS: New assignment creates a clear unread message for the linked driver account.\n";
    $source=file_get_contents(ROOT_PATH.'/actions/notifications.php');
    preg_match('/\$assignments = \$pdo->prepare\("(SELECT DISTINCT ON .*?)"\);/s',$source,$m);
    $pending=$pdo->prepare($m[1]); $pending->execute([$d['user_id'],$d['user_id']]);
    $rows=$pending->fetchAll();
    if (!array_filter($rows,fn($row)=>$row['target']==='trip-details:'.$tripId)) throw new RuntimeException('Pre-login assignment excluded.');
    echo "PASS: Pre-login unread assignment is included in the initial preview.\n";
    $pdo->prepare("UPDATE trips SET status='Completed' WHERE id=?")->execute([$tripId]);
    $pending->execute([$d['user_id'],$d['user_id']]);
    if (array_filter($pending->fetchAll(),fn($row)=>$row['target']==='trip-details:'.$tripId)) throw new RuntimeException('Completed assignment still offered as new.');
    echo "PASS: Completed trips are excluded from new-assignment login previews.\n";
    $pdo->rollBack();
    echo "All test trip/notification records rolled back.\n";
} catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
