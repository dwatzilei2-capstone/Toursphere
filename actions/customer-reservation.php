<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/customer_reservations.php';
require_once ROOT_PATH . '/includes/schedules.php';
require_login();
if (!has_role('customer')) { http_response_code(403); exit('Forbidden'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$return = BASE_URL . '/modules/customer-portal/reservations.php';
$id = trim($_POST['reservation_id'] ?? '');
try {
    $pdo = db(); $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? AND customer_id=? FOR UPDATE');
    $stmt->execute([$id, $current_user['id']]); $r = $stmt->fetch();
    if (!$r) throw new RuntimeException('Reservation not found.');
    $tripStmt = $pdo->prepare('SELECT * FROM trips WHERE reservation_id=? ORDER BY created_at DESC LIMIT 1 FOR UPDATE');
    $tripStmt->execute([$id]); $trip = $tripStmt->fetch() ?: null;
    if (($_POST['action'] ?? '') === 'cancel') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') throw new RuntimeException('Cancellation reason is required.');
        if (in_array($r['status'], ['Cancelled','Completed','In Transit','Returning to Depot'], true) || customer_cancellation_blocked($trip)) throw new RuntimeException('Cancellation is unavailable because the trip is already underway or completed.');
        $vehicle = $r['assigned_vehicle_id']; $driver = $r['assigned_driver_id'];
        $pdo->prepare("UPDATE reservations SET status='Cancelled', cancellation_type='Customer Cancellation', cancellation_reason=?, cancelled_by=?, cancelled_at=NOW(), cancelled_vehicle_id=?, cancelled_driver_id=?, assigned_vehicle_id=NULL, assigned_driver_id=NULL WHERE id=?")
            ->execute([mb_substr($reason, 0, 120), $current_user['id'], $vehicle, $driver, $id]);
        if ($trip) $pdo->prepare("UPDATE trips SET status='Cancelled', navigation_active=0, completion_notes=? WHERE id=?")->execute([$reason, $trip['id']]);
        if ($vehicle) {
            $pdo->prepare("UPDATE vehicles SET status=CASE WHEN EXISTS(SELECT 1 FROM trips WHERE vehicle_id=? AND status IN ('In Transit','Returning to Depot')) THEN 'On Trip' WHEN EXISTS(SELECT 1 FROM reservations WHERE assigned_vehicle_id=? AND status IN ('Assigned','Confirmed','Dispatched')) OR assigned_driver_id IS NOT NULL THEN 'Assigned' ELSE 'Available' END WHERE id=?")
                ->execute([$vehicle, $vehicle, $vehicle]);
        }
        if ($driver) $pdo->prepare("UPDATE drivers SET status=CASE WHEN EXISTS(SELECT 1 FROM trips WHERE driver_id=? AND status IN ('In Transit','Returning to Depot')) THEN 'On Trip' WHEN EXISTS(SELECT 1 FROM vehicles WHERE assigned_driver_id=?) THEN 'Assigned' ELSE 'Active' END WHERE id=?")
            ->execute([$driver, $driver, $driver]);
        $pdo->prepare("INSERT INTO reservation_events(reservation_id,actor_id,status,reason) VALUES (?,?,'Cancelled',?)")->execute([$id, $current_user['id'], $reason]);
        if ($r['departure_schedule_instance_id']) schedule_refresh_required_vehicle($pdo, (int)$r['departure_schedule_instance_id']);
        if ($r['return_schedule_instance_id'] && $r['return_schedule_instance_id'] !== $r['departure_schedule_instance_id']) schedule_refresh_required_vehicle($pdo, (int)$r['return_schedule_instance_id']);
        $message = 'Cancellation recorded.';
    } elseif (($_POST['action'] ?? '') === 'rate') {
        $stars = filter_var($_POST['stars'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>5]]);
        if (!$stars) throw new RuntimeException('Choose a rating from 1 to 5 stars.');
        if (!$trip || $trip['status'] !== 'Completed' || !$trip['driver_id']) throw new RuntimeException('Only a completed trip with an assigned driver can be rated.');
        $feedback = mb_substr(trim($_POST['feedback'] ?? ''), 0, 2000);
        $pdo->prepare('INSERT INTO driver_ratings(trip_id,reservation_id,customer_id,driver_id,stars,feedback) VALUES (?,?,?,?,?,?)')
            ->execute([$trip['id'], $id, $current_user['id'], $trip['driver_id'], $stars, $feedback]);
        $pdo->prepare('UPDATE drivers SET rating=(SELECT ROUND(AVG(stars)::numeric,2) FROM driver_ratings WHERE driver_id=?) WHERE id=?')
            ->execute([$trip['driver_id'], $trip['driver_id']]);
        $message = 'Driver rating saved.';
    } else throw new RuntimeException('Unknown action.');
    $pdo->commit(); redirect_with_toast($return, $message, 'success');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    redirect_with_toast($return, $e instanceof PDOException && $e->getCode() === '23505' ? 'This trip has already been rated.' : $e->getMessage(), 'danger');
}
