<?php
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/reservation-assignment.php';
require_login();
if (!can('dispatch.manage')) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error'=>'Assignment permission required.']); exit; }
header('Content-Type: application/json');
try {
    $stmt=db()->prepare('SELECT * FROM reservations WHERE id=?');
    $stmt->execute([trim($_GET['reservation_id'] ?? '')]);
    $r=$stmt->fetch();
    if (!$r || !in_array($r['status'],['Approved','Assigned','Confirmed'],true)) throw new RuntimeException('An approved reservation is required.');
    $departure=trim($_GET['departure'] ?? '') ?: $r['departure_date'].' '.$r['departure_time'];
    if (strtotime($departure)===false) throw new RuntimeException('Enter a valid departure time.');
    echo json_encode(assignment_options(db(),$r,date('Y-m-d H:i:s',strtotime($departure))));
} catch (Throwable $ex) {
    http_response_code(422);
    echo json_encode(['error'=>$ex->getMessage()]);
}
