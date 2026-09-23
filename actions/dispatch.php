<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('dispatch.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/dashboard.php');
}
$return = $_POST['return'] ?? (BASE_URL . '/modules/vehicle-reservation-dispatch/reservations.php');

$reservation_id = trim($_POST['reservation_id'] ?? '');
$vehicle_id     = trim($_POST['vehicle_id'] ?? '');
$driver_id      = trim($_POST['driver_id'] ?? '');
$departure      = trim($_POST['departure'] ?? '');
$notes          = trim($_POST['notes'] ?? '');

if ($reservation_id === '' || $vehicle_id === '' || $driver_id === '') {
    redirect_with_toast($return, 'Please select a vehicle and a driver to dispatch.', 'danger');
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $resStmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? FOR UPDATE');
    $resStmt->execute([$reservation_id]);
    $r = $resStmt->fetch();
    if (!$r) {
        throw new RuntimeException('Reservation not found.');
    }
    if (!in_array($r['status'], ['Pending', 'Assigned', 'Confirmed'], true)) {
        throw new RuntimeException("A {$r['status']} reservation is locked and cannot be dispatched again.");
    }

    $vehicleStmt = $pdo->prepare('SELECT * FROM vehicles WHERE id = ? FOR UPDATE');
    $vehicleStmt->execute([$vehicle_id]);
    $vehicle = $vehicleStmt->fetch();
    $driverStmt = $pdo->prepare('SELECT * FROM drivers WHERE id = ? FOR UPDATE');
    $driverStmt->execute([$driver_id]);
    $driver = $driverStmt->fetch();
    if (!$vehicle || !$driver) throw new RuntimeException('Selected vehicle or Driver was not found.');
    if (in_array($vehicle['status'], ['Maintenance', 'On Trip'], true)) {
        throw new RuntimeException('The selected vehicle is not available for dispatch.');
    }
    $maintenanceStmt = $pdo->prepare(
        "SELECT 1 FROM maintenance_orders WHERE vehicle_id = ? AND status = 'In Repair' LIMIT 1"
    );
    $maintenanceStmt->execute([$vehicle_id]);
    if ($maintenanceStmt->fetchColumn()) {
        throw new RuntimeException('The selected vehicle has an active maintenance repair and cannot be dispatched.');
    }
    if ((int)$vehicle['capacity'] < (int)$r['passenger_count']) {
        throw new RuntimeException('The selected vehicle does not have enough passenger capacity.');
    }
    if ($driver['status'] === 'On Trip') throw new RuntimeException('The selected Driver is already on a trip.');
    if (!empty($driver['license_expiration']) && strtotime($driver['license_expiration']) < strtotime(date('Y-m-d'))) {
        throw new RuntimeException('The selected Driver has an expired license.');
    }

    $departure_dt = ($departure !== '' && strtotime($departure) !== false)
        ? date('Y-m-d H:i:s', strtotime($departure))
        : date('Y-m-d H:i:s', strtotime($r['departure_date'] . ' ' . $r['departure_time']));
    $conflictStmt = $pdo->prepare(
        "SELECT id FROM trips WHERE reservation_id <> ? AND status <> 'Completed'
          AND (vehicle_id = ? OR driver_id = ?)
          AND scheduled_departure BETWEEN (?::timestamp - (? || ' hours')::interval) AND (?::timestamp + (? || ' hours')::interval)
          LIMIT 1"
    );
    $bufferHours = max(1, min(72, (int)fleet_setting('dispatch.buffer_hours', '4')));
    $conflictStmt->execute([$reservation_id, $vehicle_id, $driver_id, $departure_dt, $bufferHours, $departure_dt, $bufferHours]);
    if ($conflictStmt->fetchColumn()) throw new RuntimeException('Vehicle or Driver has a conflicting trip schedule.');

     
    $pdo->prepare(
        "UPDATE reservations SET assigned_vehicle_id = ?, assigned_driver_id = ?, status = 'Dispatched', notes = ? WHERE id = ?"
    )->execute([$vehicle_id, $driver_id, $notes !== '' ? $notes : $r['notes'], $reservation_id]);

     
    $pdo->prepare("UPDATE vehicles SET status = 'Assigned', assigned_driver_id = ?, location = ? WHERE id = ?")
        ->execute([$driver_id, 'Scheduled: ' . $r['origin'] . ' → ' . $r['destination'], $vehicle_id]);
    $pdo->prepare("UPDATE drivers SET status = 'Assigned' WHERE id = ?")->execute([$driver_id]);

     
     
    $tripStmt = $pdo->prepare('SELECT id, status FROM trips WHERE reservation_id = ? FOR UPDATE');
    $tripStmt->execute([$reservation_id]);
    $existingTrip = $tripStmt->fetch();
    if (!$existingTrip) {
        $tid = next_sequential_id($pdo, 'trips', 'id', 'TRP-');
        $pdo->prepare(
            "INSERT INTO trips (id, reservation_id, origin, destination, waypoints, vehicle_id, driver_id,
                                passengers, scheduled_departure, status, progress_pct, current_step)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Dispatched', 10, 'Dispatched')"
        )->execute([
            $tid, $reservation_id, $r['origin'], $r['destination'], '',
            $vehicle_id, $driver_id, $r['passenger_count'], $departure_dt,
        ]);
    } else {
        if (in_array($existingTrip['status'], ['In Transit', 'Returning to Depot', 'Completed'], true)) {
            throw new RuntimeException('An active or completed trip cannot be reassigned.');
        }
        $pdo->prepare(
            "UPDATE trips
                SET origin = ?, destination = ?, vehicle_id = ?, driver_id = ?, passengers = ?,
                    scheduled_departure = ?, status = 'Dispatched', progress_pct = 10,
                    current_step = 'Dispatched'
              WHERE id = ?"
        )->execute([
            $r['origin'], $r['destination'], $vehicle_id, $driver_id,
            $r['passenger_count'], $departure_dt, $existingTrip['id'],
        ]);
    }

    if (!empty($driver['user_id'])) {
        $pdo->prepare(
            "INSERT INTO notifications (title,body,time_label,type,category,target,is_read,user_id)
             VALUES ('New Trip Assignment',?,'Just now','info','Dispatch','driver-trips',0,?)"
        )->execute(["Reservation {$reservation_id} has been assigned to you. Open My Trips for route and schedule details.", $driver['user_id']]);
    }

    $pdo->commit();

    redirect_with_toast($return, 'Reservation ' . $reservation_id . ' dispatched to the assigned vehicle & driver.', 'success');
} catch (Exception $ex) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    redirect_with_toast($return, 'Dispatch failed: ' . $ex->getMessage(), 'danger');
}
