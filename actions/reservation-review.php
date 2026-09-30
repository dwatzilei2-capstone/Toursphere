<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/schedules.php';
require_login();
if (!has_role(['fleet_admin', 'dispatcher'])) { http_response_code(403); exit('Forbidden'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$return = BASE_URL . '/modules/vehicle-reservation-dispatch/reservations.php';
$id = trim($_POST['reservation_id'] ?? '');
$decision = trim($_POST['decision'] ?? '');
$reason = trim($_POST['reason'] ?? '');
try {
    if (!in_array($decision, ['approve','reject'], true)) throw new RuntimeException('Choose approve or reject.');
    if ($decision === 'reject' && $reason === '') throw new RuntimeException('A rejection reason is required.');
    $pdo = db(); $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $r = $stmt->fetch();
    if (!$r || $r['status'] !== 'Pending Approval' || !$r['customer_id']) throw new RuntimeException('This customer reservation is no longer pending approval.');
    $status = $decision === 'approve' ? 'Approved' : 'Rejected';
    $pdo->prepare("UPDATE reservations SET status=?, rejection_reason=?, reviewed_by=?, reviewed_at=NOW(), fare_status=CASE WHEN ?='approve' AND total_booking_fare IS NOT NULL THEN 'Confirmed' ELSE fare_status END WHERE id=?")
        ->execute([$status, $decision === 'reject' ? mb_substr($reason, 0, 2000) : null, $current_user['id'], $decision, $id]);
    $pdo->prepare('INSERT INTO reservation_events (reservation_id,actor_id,status,reason) VALUES (?,?,?,?)')
        ->execute([$id, $current_user['id'], $status, $decision === 'reject' ? $reason : null]);
    if ($r['departure_schedule_instance_id']) schedule_refresh_required_vehicle($pdo, (int)$r['departure_schedule_instance_id']);
    if ($r['return_schedule_instance_id'] && $r['return_schedule_instance_id'] !== $r['departure_schedule_instance_id']) schedule_refresh_required_vehicle($pdo, (int)$r['return_schedule_instance_id']);
    $pdo->commit();
    redirect_with_toast($return, "Reservation $id $status.", 'success');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    redirect_with_toast($return, $e->getMessage(), 'danger');
}
