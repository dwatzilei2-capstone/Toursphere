<?php

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('dispatch.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/modules/vehicle-reservation-dispatch/reservations.php');
}

$return = $_POST['return'] ?? (BASE_URL . '/modules/vehicle-reservation-dispatch/reservations.php');
$reservationId = trim($_POST['reservation_id'] ?? '');
$action = trim($_POST['action'] ?? '');
$reason = trim($_POST['cancellation_reason'] ?? '');
$otherReason = trim($_POST['other_reason'] ?? '');
$notes = trim($_POST['cancellation_notes'] ?? '');

$allowedReasons = [
    'Customer Request', 'Trip Cancelled', 'Schedule Changed', 'Duplicate Reservation',
    'Vehicle Availability Issue', 'Booking Information Error', 'Other',
];

if ($reservationId === '' || !in_array($action, ['cancel_reservation', 'recall_dispatch'], true)) {
    redirect_with_toast($return, 'Invalid reservation cancellation request.', 'danger');
}
if (!in_array($reason, $allowedReasons, true)) {
    redirect_with_toast($return, 'Please select a valid cancellation reason.', 'danger');
}
if ($reason === 'Other') {
    if ($otherReason === '') {
        redirect_with_toast($return, 'Please provide the cancellation explanation.', 'danger');
    }
    $reason = 'Other: ' . mb_substr($otherReason, 0, 110);
}
$notes = mb_substr($notes, 0, 2000);

function recalculate_vehicle_availability(PDO $pdo, string $vehicleId): void
{
    $maintenance = $pdo->prepare(
        "SELECT 1 FROM maintenance_orders WHERE vehicle_id = ? AND status = 'In Repair' LIMIT 1"
    );
    $maintenance->execute([$vehicleId]);
    if ($maintenance->fetchColumn()) {
        $pdo->prepare("UPDATE vehicles SET status='Maintenance', assigned_driver_id=NULL, location='Maintenance / Inspection' WHERE id=?")
            ->execute([$vehicleId]);
        return;
    }

    $activeTrip = $pdo->prepare(
        "SELECT driver_id FROM trips
          WHERE vehicle_id=? AND status IN ('In Transit','Returning to Depot')
          ORDER BY created_at DESC LIMIT 1"
    );
    $activeTrip->execute([$vehicleId]);
    if ($driverId = $activeTrip->fetchColumn()) {
        $pdo->prepare("UPDATE vehicles SET status='On Trip', assigned_driver_id=?, location='On Trip' WHERE id=?")
            ->execute([$driverId, $vehicleId]);
        return;
    }

    $assignment = $pdo->prepare(
        "SELECT assigned_driver_id, origin, destination FROM reservations
          WHERE assigned_vehicle_id=? AND status IN ('Assigned','Confirmed','Dispatched')
          ORDER BY departure_date, created_at LIMIT 1"
    );
    $assignment->execute([$vehicleId]);
    if ($active = $assignment->fetch()) {
        $pdo->prepare("UPDATE vehicles SET status='Assigned', assigned_driver_id=?, location=? WHERE id=?")
            ->execute([$active['assigned_driver_id'] ?: null, 'Scheduled: ' . $active['origin'] . ' → ' . $active['destination'], $vehicleId]);
        return;
    }

    $pdo->prepare("UPDATE vehicles SET status='Available', assigned_driver_id=NULL, location='Central Depot' WHERE id=?")
        ->execute([$vehicleId]);
}

function recalculate_driver_availability(PDO $pdo, string $driverId): void
{
    $activeTrip = $pdo->prepare(
        "SELECT 1 FROM trips WHERE driver_id=? AND status IN ('In Transit','Returning to Depot') LIMIT 1"
    );
    $activeTrip->execute([$driverId]);
    if ($activeTrip->fetchColumn()) {
        $pdo->prepare("UPDATE drivers SET status='On Trip' WHERE id=?")->execute([$driverId]);
        return;
    }

    $assignment = $pdo->prepare(
        "SELECT 1 FROM reservations
          WHERE assigned_driver_id=? AND status IN ('Assigned','Confirmed','Dispatched') LIMIT 1"
    );
    $assignment->execute([$driverId]);
    $status = $assignment->fetchColumn() ? 'Assigned' : 'Active';
    $pdo->prepare('UPDATE drivers SET status=? WHERE id=?')->execute([$status, $driverId]);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $reservationStmt = $pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');
    $reservationStmt->execute([$reservationId]);
    $reservation = $reservationStmt->fetch();
    if (!$reservation) throw new RuntimeException('Reservation not found.');
    if ($reservation['status'] === 'Cancelled') throw new RuntimeException('This reservation has already been cancelled.');

    $tripStmt = $pdo->prepare(
        'SELECT * FROM trips WHERE reservation_id=? ORDER BY created_at DESC LIMIT 1 FOR UPDATE'
    );
    $tripStmt->execute([$reservationId]);
    $trip = $tripStmt->fetch() ?: null;

    if ($action === 'cancel_reservation') {
        if (!in_array($reservation['status'], ['Pending','Reserved','Assigned','Confirmed','Ready for Dispatch'], true)) {
            throw new RuntimeException("A {$reservation['status']} reservation cannot use normal cancellation.");
        }
        $cancellationType = 'Reservation Cancellation';
    } else {
        if ($reservation['status'] !== 'Dispatched' || ($trip && $trip['status'] !== 'Dispatched')) {
            throw new RuntimeException('Dispatch recall is only allowed before the actual trip starts.');
        }
        $cancellationType = 'Dispatch Recall';
    }

    if ($trip && in_array($trip['status'], ['In Transit','Returning to Depot','Completed'], true)) {
        throw new RuntimeException('The actual trip has already started and cannot be cancelled through this workflow.');
    }

    $vehicleId = $reservation['assigned_vehicle_id'] ?: ($trip['vehicle_id'] ?? null);
    $driverId = $reservation['assigned_driver_id'] ?: ($trip['driver_id'] ?? null);
    if ($vehicleId) $pdo->prepare('SELECT id FROM vehicles WHERE id=? FOR UPDATE')->execute([$vehicleId]);
    if ($driverId) $pdo->prepare('SELECT id FROM drivers WHERE id=? FOR UPDATE')->execute([$driverId]);

    $previousStatus = $reservation['status'];
    $pdo->prepare(
        "UPDATE reservations
            SET status='Cancelled', cancellation_type=?, cancellation_reason=?, cancellation_notes=?,
                cancelled_by=?, cancelled_at=NOW(), cancelled_vehicle_id=?, cancelled_driver_id=?,
                assigned_vehicle_id=NULL, assigned_driver_id=NULL
          WHERE id=? AND status=?"
    )->execute([
        $cancellationType, $reason, $notes !== '' ? $notes : null, $current_user['id'],
        $vehicleId, $driverId, $reservationId, $previousStatus,
    ]);

    if ($trip) {
        $pdo->prepare(
            "UPDATE trips SET status='Cancelled', navigation_active=0, progress_pct=0,
                current_step=?, completion_notes=? WHERE id=?"
        )->execute([$cancellationType, $reason . ($notes !== '' ? ' — ' . $notes : ''), $trip['id']]);
        $pdo->prepare(
            "INSERT INTO trip_timeline (trip_id,title,event_time,completed,active_step,sort_order)
             VALUES (?,'Vehicle Reservation Cancelled',TO_CHAR(NOW(),'YYYY-MM-DD HH24:MI'),1,0,
                (SELECT COALESCE(MAX(sort_order),0)+1 FROM trip_timeline WHERE trip_id=?))"
        )->execute([$trip['id'], $trip['id']]);
    }

    if ($vehicleId) recalculate_vehicle_availability($pdo, $vehicleId);
    if ($driverId) recalculate_driver_availability($pdo, $driverId);

    $actor = $current_user['name'] ?? 'Dispatcher';
    $pdo->prepare(
        "INSERT INTO notifications (title,body,time_label,type,category,target,is_read)
         VALUES ('Vehicle Reservation Cancelled',?,'Just now','warning','Dispatch','reservations',0)"
    )->execute(["{$reservationId}: {$previousStatus} → Cancelled by {$actor}. Reason: {$reason}."]);

    $pdo->commit();
    $label = $action === 'recall_dispatch' ? 'Dispatch recalled and reservation cancelled.' : 'Reservation cancelled.';
    redirect_with_toast($return, $label . ' Vehicle and Driver availability were recalculated.', 'success');
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    redirect_with_toast($return, 'Cancellation failed: ' . $error->getMessage(), 'danger');
}
