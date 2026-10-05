<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_compliance.php';
require_once ROOT_PATH . '/includes/driver_vehicle_assignment.php';
require_once ROOT_PATH . '/includes/schedules.php';
require_once ROOT_PATH . '/includes/dispatch-assignment.php';
require_once ROOT_PATH . '/includes/reservation-assignment.php';
require_once ROOT_PATH . '/includes/trip_funding.php';
require_login();
require_permission('dispatch.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/dashboard.php');
}
$return = $_POST['return'] ?? (BASE_URL . '/modules/vehicle-reservation-dispatch/reservations.php');

$reservation_id = trim($_POST['reservation_id'] ?? '');
$vehicle_id     = trim($_POST['vehicle_id'] ?? '');
$driver_id      = trim($_POST['driver_id'] ?? '');
$dispatch_action = trim($_POST['dispatch_action'] ?? 'assign');
$departure      = trim($_POST['departure'] ?? '');
$notes          = trim($_POST['notes'] ?? '');

if ($reservation_id === '' || $vehicle_id === '' || $driver_id === '' || !in_array($dispatch_action, ['assign', 'dispatch'], true)) {
    redirect_with_toast($return, 'Please select a valid action, vehicle, and driver.', 'danger');
}

try {
    if (!is_string($_POST['csrf'] ?? null) || empty($_SESSION['assignment_csrf']) || !hash_equals($_SESSION['assignment_csrf'],$_POST['csrf'])) throw new RuntimeException('Assignment session expired. Refresh the page.');
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec("SELECT pg_advisory_xact_lock(hashtext('fleet-designated-assignment'))");
    $lockStmt = $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(?)), pg_advisory_xact_lock(hashtext(?))");
    $lockStmt->execute(['dispatch-driver:' . $driver_id, 'dispatch-vehicle:' . $vehicle_id]);

    $resStmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? FOR UPDATE');
    $resStmt->execute([$reservation_id]);
    $r = $resStmt->fetch();
    if (!$r) {
        throw new RuntimeException('Reservation not found.');
    }
    validate_dispatch_assignment($r, $dispatch_action, $vehicle_id, $driver_id);
    if ($dispatch_action === 'dispatch') funding_assert_dispatch($pdo, $reservation_id);
    if ($dispatch_action === 'assign') {
        $pending = $pdo->prepare("SELECT 1 FROM trip_funding_requests f JOIN reservations fr ON fr.id=f.reservation_id WHERE (fr.id=? OR fr.departure_schedule_instance_id=(SELECT departure_schedule_instance_id FROM reservations WHERE id=?)) AND f.status='Pending Finance Approval'");
        $pending->execute([$reservation_id,$reservation_id]);
        if ($pending->fetchColumn()) throw new RuntimeException('Wait for Finance approval before changing this assignment.');
    }
    $scheduleInstance = null;
    $dispatchPassengerDemand = (int)$r['passenger_count'];
    if (!empty($r['departure_schedule_instance_id'])) {
        $scheduleStmt = $pdo->prepare('SELECT * FROM scheduled_departures WHERE id=? FOR UPDATE');
        $scheduleStmt->execute([$r['departure_schedule_instance_id']]);
        $scheduleInstance = $scheduleStmt->fetch();
        if (!$scheduleInstance || in_array($scheduleInstance['status'], ['Closed','Completed','Cancelled'], true)) throw new RuntimeException('The scheduled departure is no longer open for dispatch.');
        $dispatchPassengerDemand = schedule_reserved_demand($pdo, (int)$scheduleInstance['id']);
        $updatingAssignment = $dispatch_action === 'assign' && in_array($r['status'], ['Assigned','Confirmed'], true);
        if ($updatingAssignment) {
            if ($scheduleInstance['status'] === 'Dispatched') throw new RuntimeException('This scheduled departure is already dispatched. Assignment cannot be changed.');
            $sharedReservations = $pdo->prepare("SELECT id,status FROM reservations WHERE departure_schedule_instance_id=? AND status <> 'Cancelled' FOR UPDATE");
            $sharedReservations->execute([$scheduleInstance['id']]);
            foreach ($sharedReservations->fetchAll() as $shared) {
                if (in_array($shared['status'], ['Dispatched','In Transit','Completed'], true)) throw new RuntimeException('This shared departure has already been dispatched or started. Assignment cannot be changed.');
            }
            $sharedTrips = $pdo->prepare('SELECT t.id,t.status FROM trips t JOIN reservations sr ON sr.id=t.reservation_id WHERE sr.departure_schedule_instance_id=? FOR UPDATE OF t');
            $sharedTrips->execute([$scheduleInstance['id']]);
            foreach ($sharedTrips->fetchAll() as $sharedTrip) {
                if (in_array($sharedTrip['status'], ['Dispatched','In Transit','Returning to Depot','Completed'], true)) throw new RuntimeException('A trip on this shared departure has already been dispatched or started. Assignment cannot be changed.');
            }
        }
        if (!$updatingAssignment && (($scheduleInstance['assigned_vehicle_id'] && $scheduleInstance['assigned_vehicle_id'] !== $vehicle_id)
            || ($scheduleInstance['assigned_driver_id'] && $scheduleInstance['assigned_driver_id'] !== $driver_id))) {
            $assignmentStmt = $pdo->prepare('SELECT v.plate_number, d.name FROM scheduled_departures s LEFT JOIN vehicles v ON v.id=s.assigned_vehicle_id LEFT JOIN drivers d ON d.id=s.assigned_driver_id WHERE s.id=?');
            $assignmentStmt->execute([$scheduleInstance['id']]);
            $assigned = $assignmentStmt->fetch();
            $assignedVehicle = $scheduleInstance['assigned_vehicle_id']
                ? $scheduleInstance['assigned_vehicle_id'] . ' (' . ($assigned['plate_number'] ?? 'plate unavailable') . ')'
                : 'no vehicle yet';
            $assignedDriver = $assigned['name'] ?? $scheduleInstance['assigned_driver_id'] ?? 'no driver yet';
            throw new RuntimeException('Scheduled departure #' . $scheduleInstance['id'] . ' is already assigned to '
                . $assignedVehicle . ' and ' . $assignedDriver . '. Your current selections differ from that assignment. '
                . 'No changes were saved; the existing assignment remains. Select the already assigned vehicle and driver to continue.');
        }
    }
    if (!in_array($r['status'], ['Approved', 'Assigned', 'Confirmed'], true)
        || ($r['customer_id'] && $r['status'] === 'Pending')) {
        throw new RuntimeException("A {$r['status']} reservation is locked and cannot be dispatched again.");
    }
    if ($dispatch_action === 'dispatch' && !in_array($r['status'], ['Assigned', 'Confirmed'], true)) {
        throw new RuntimeException('Assign a vehicle and driver before dispatching this reservation.');
    }
    $targetStatus = $dispatch_action === 'dispatch' ? 'Dispatched' : 'Assigned';

    $vehicleStmt = $pdo->prepare('SELECT * FROM vehicles WHERE id = ? FOR UPDATE');
    $vehicleStmt->execute([$vehicle_id]);
    $vehicle = $vehicleStmt->fetch();
    $driverStmt = $pdo->prepare('SELECT * FROM drivers WHERE id = ? FOR UPDATE');
    $driverStmt->execute([$driver_id]);
    $driver = $driverStmt->fetch();
    if (!$vehicle || !$driver) throw new RuntimeException('Selected vehicle or Driver was not found.');
    if ($departure !== '' && strtotime($departure) === false) throw new RuntimeException('Enter a valid departure time.');
    $departure_dt = date('Y-m-d H:i:s', strtotime($departure ?: $r['departure_date'].' '.$r['departure_time']));
    if($dispatch_action==='dispatch' && $departure_dt!==date('Y-m-d H:i:s',strtotime($r['departure_date'].' '.$r['departure_time']))) throw new RuntimeException('Update the assignment and funding before changing the funded departure time.');
    if ($scheduleInstance && $departure_dt !== date('Y-m-d H:i:s',strtotime($r['departure_date'].' '.$r['departure_time']))) {
        throw new RuntimeException('This reservation shares a scheduled departure. Change its schedule through the reservation schedule workflow.');
    }
    $options = assignment_options($pdo, $r, $departure_dt);
    $choice = null;
    foreach ($options['vehicles'] as $option) if ($option['id'] === $vehicle_id) $choice = $option;
    if (!$choice || !$choice['eligible']) throw new RuntimeException(implode('; ', $choice['reasons'] ?? ['Vehicle unavailable']));
    $pairingChange = assignment_confirm_pairing($pdo, $vehicle, $driver_id, $choice, $dispatch_action, (string)($_POST['reassignment_from'] ?? ''));
    $compliance = vehicle_operational_compliance($pdo, $vehicle_id);
    if (!$compliance['operational']) {
        throw new RuntimeException('Vehicle cannot operate because required compliance documents are incomplete or invalid. ' . $compliance['reason']);
    }
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
    if ((int)$vehicle['capacity'] < $dispatchPassengerDemand) {
        throw new RuntimeException('The selected vehicle does not have enough capacity for the scheduled passenger demand.');
    }
    if ($r['customer_id'] && $vehicle['type'] !== $r['vehicle_requested']) {
        throw new RuntimeException('The vehicle must match the customer-requested vehicle type.');
    }
    if (driver_has_active_trip($pdo, $driver_id)) throw new RuntimeException($driver['name'] . ' is already On Trip and cannot be assigned.');
    if (!in_array($driver['status'], ['Active', 'Assigned'], true)) throw new RuntimeException($driver['name'] . ' is not available for dispatch.');
    if (vehicle_has_active_trip($pdo, $vehicle_id)) throw new RuntimeException($vehicle['plate_number'] . ' is already being used by an active trip.');
    if (!empty($driver['license_expiration']) && strtotime($driver['license_expiration']) < strtotime(date('Y-m-d'))) {
        throw new RuntimeException('The selected Driver has an expired license.');
    }

    $pdo->prepare(
        'UPDATE reservations SET assigned_vehicle_id = ?, assigned_driver_id = ?, status = ?, notes = ?, departure_date = ?, departure_time = ? WHERE id = ?'
    )->execute([$vehicle_id, $driver_id, $targetStatus, $notes, substr($departure_dt,0,10), substr($departure_dt,11,5), $reservation_id]);
    if ($scheduleInstance) {
        $scheduleStatusUpdate = $dispatch_action === 'dispatch' ? ",status='Dispatched'" : '';
        $pdo->prepare("UPDATE scheduled_departures SET assigned_vehicle_id=?,assigned_driver_id=?{$scheduleStatusUpdate},updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$vehicle_id, $driver_id, $scheduleInstance['id']]);
        $pdo->prepare("UPDATE reservations SET assigned_vehicle_id=?,assigned_driver_id=?,status=? WHERE departure_schedule_instance_id=? AND status IN ('Approved','Assigned','Confirmed')")
            ->execute([$vehicle_id, $driver_id, $targetStatus, $scheduleInstance['id']]);
        $eventReason = $dispatch_action === 'dispatch' ? 'Shared scheduled departure dispatched' : 'Shared scheduled departure assigned';
        $pdo->prepare('INSERT INTO reservation_events(reservation_id,actor_id,status,reason) SELECT id,?,?,? FROM reservations WHERE departure_schedule_instance_id=? AND status=? AND id<>?')
            ->execute([$current_user['id'], $targetStatus, $eventReason, $scheduleInstance['id'], $targetStatus, $reservation_id]);
    }
    if ($r['customer_id']) {
        $pdo->prepare('INSERT INTO reservation_events(reservation_id,actor_id,status) VALUES (?,?,?)')
            ->execute([$reservation_id, $current_user['id'], $targetStatus]);
    }

     
    $pdo->prepare("UPDATE vehicles SET status = 'Assigned', location = ? WHERE id = ?")
        ->execute(['Scheduled: ' . $r['origin'] . ' → ' . $r['destination'], $vehicle_id]);
    $pdo->prepare("UPDATE drivers SET status = 'Assigned' WHERE id = ?")->execute([$driver_id]);

     
     
    $tripStmt = $pdo->prepare('SELECT id, status FROM trips WHERE reservation_id = ? FOR UPDATE');
    $tripStmt->execute([$reservation_id]);
    $existingTrip = $tripStmt->fetch();
    if (!$existingTrip) {
        $tid = next_sequential_id($pdo, 'trips', 'id', 'TRP-');
        $pdo->prepare(
            "INSERT INTO trips (id, reservation_id, origin, destination, waypoints, vehicle_id, driver_id,
                                passengers, scheduled_departure, status, progress_pct, current_step)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $tid, $reservation_id, $r['origin'], $r['destination'], '',
            $vehicle_id, $driver_id, $dispatchPassengerDemand, $departure_dt,
            $targetStatus, $targetStatus === 'Dispatched' ? 10 : 0, $targetStatus,
        ]);
    } else {
        if (in_array($existingTrip['status'], ['In Transit', 'Returning to Depot', 'Completed'], true) || ($dispatch_action === 'assign' && $existingTrip['status'] === 'Dispatched')) {
            throw new RuntimeException('An active or completed trip cannot be reassigned.');
        }
        $pdo->prepare(
            "UPDATE trips
                SET origin = ?, destination = ?, vehicle_id = ?, driver_id = ?, passengers = ?,
                    scheduled_departure = ?, status = ?, progress_pct = ?, current_step = ?
              WHERE id = ?"
        )->execute([
            $r['origin'], $r['destination'], $vehicle_id, $driver_id,
            $dispatchPassengerDemand, $departure_dt, $targetStatus,
            $targetStatus === 'Dispatched' ? 10 : 0, $targetStatus, $existingTrip['id'],
        ]);
    }


    if ($dispatch_action === 'assign' && $scheduleInstance && in_array($r['status'], ['Assigned','Confirmed'], true)) {
        $pdo->prepare("UPDATE trips t SET vehicle_id=?,driver_id=? FROM reservations sr WHERE sr.id=t.reservation_id AND sr.departure_schedule_instance_id=? AND sr.status IN ('Assigned','Confirmed') AND t.status IN ('Scheduled','Assigned','Confirmed')")
            ->execute([$vehicle_id,$driver_id,$scheduleInstance['id']]);
    }
    if ($dispatch_action === 'dispatch' && $scheduleInstance) {
        $pdo->prepare("UPDATE trips t SET status='Dispatched',current_step='Dispatched',progress_pct=10
            FROM reservations sr WHERE sr.id=t.reservation_id AND sr.departure_schedule_instance_id=?
            AND sr.status='Dispatched' AND t.vehicle_id=? AND t.driver_id=?
            AND t.status IN ('Scheduled','Assigned','Confirmed')")
            ->execute([$scheduleInstance['id'],$vehicle_id,$driver_id]);
    }
    if ($r['assigned_vehicle_id'] && $r['assigned_vehicle_id'] !== $vehicle_id) refresh_vehicle_operational_status($pdo, $r['assigned_vehicle_id']);
    if ($r['assigned_driver_id'] && $r['assigned_driver_id'] !== $driver_id) {
        refresh_driver_operational_status($pdo, $r['assigned_driver_id']);
        $pdo->prepare("UPDATE drivers SET status='Assigned' WHERE id=? AND status='Active' AND EXISTS (SELECT 1 FROM trips WHERE driver_id=? AND status IN ('Scheduled','Assigned','Dispatched'))")
            ->execute([$r['assigned_driver_id'],$r['assigned_driver_id']]);
    }
    $pdo->prepare('INSERT INTO audit_logs(action,user_id,entity_type,entity_id,details) VALUES (?,?,?,?,?)')->execute([
        $dispatch_action === 'assign' ? 'reservation.assignment.confirmed' : 'reservation.dispatched', $current_user['id'], 'reservation', $reservation_id,
        json_encode(['vehicle_id'=>$vehicle_id,'driver_id'=>$driver_id,'recommended_id'=>$options['recommended_id'],
          'selection'=>$vehicle_id===$options['recommended_id'] ? 'recommended' : 'alternative','driver_source'=>$pairingChange ? 'new_designation' : 'inherited',
          'pairing_change'=>$pairingChange,'role'=>$current_user['role_code'] ?? null,'departure'=>$departure_dt])
    ]);
    $pdo->commit();

    $message = $targetStatus === 'Dispatched'
        ? 'Reservation ' . $reservation_id . ' dispatched to the assigned vehicle & driver.'
        : 'Reservation ' . $reservation_id . ' assigned. Prepare Trip Funding before dispatch.';
    redirect_with_toast($return, $message, 'success');
} catch (Exception $ex) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    redirect_with_toast($return, 'Dispatch failed: ' . $ex->getMessage(), 'danger');
}
