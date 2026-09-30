<?php
 



require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_compliance.php';
require_once ROOT_PATH . '/includes/driver_vehicle_assignment.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/index.php');
}
$return = $_POST['return'] ?? (BASE_URL . '/modules/driver-portal/driver-trips.php');

$reservation_id = trim($_POST['reservation_id'] ?? '');
$new_status     = $_POST['status'] ?? '';
$final_odometer = (int)($_POST['final_odometer'] ?? 0);
$vehicle_condition = trim($_POST['vehicle_condition'] ?? 'Good');
$completion_notes = trim($_POST['completion_notes'] ?? '');
$actual_toll_fee = max(0, (float)($_POST['toll_fee'] ?? 0));
if (!in_array($new_status, ['In Transit', 'Returning to Depot', 'Completed'], true)) {
    redirect_with_toast($return, 'Invalid trip status transition.', 'danger');
}

try {
    $pdo = db();

    $resStmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
    $resStmt->execute([$reservation_id]);
    $r = $resStmt->fetch();
    if (!$r) {
        redirect_with_toast($return, 'Reservation not found.', 'danger');
    }

     
    $driver = $pdo->prepare('SELECT * FROM drivers WHERE user_id = ?');
    $driver->execute([$current_user['id']]);
    $d = $driver->fetch();
    $is_admin = ($current_user['role_code'] ?? '') === 'fleet_admin';
    $is_assigned_driver = $d && $d['id'] === $r['assigned_driver_id'];
    $can_dispatch = can('dispatch.manage');

    if (!$is_admin && !$is_assigned_driver && !$can_dispatch) {
        redirect_with_toast($return, 'You are not authorized to update this trip.', 'danger');
    }

    if ($new_status === 'Completed' && $is_assigned_driver) {
        $vehicleCheck = $pdo->prepare('SELECT odometer FROM vehicles WHERE id = ?');
        $vehicleCheck->execute([$r['assigned_vehicle_id']]);
        $currentOdometer = (int)$vehicleCheck->fetchColumn();
        if ($final_odometer < $currentOdometer) {
            redirect_with_toast($return, 'Final odometer cannot be lower than the vehicle current odometer.', 'danger');
        }
        if (!in_array($vehicle_condition, ['Good','Needs Inspection','Defect Reported'], true)) {
            redirect_with_toast($return, 'Please select a valid vehicle condition.', 'danger');
        }
    }

    $pdo->beginTransaction();

    if (!empty($r['departure_schedule_instance_id'])) {
        $pdo->prepare('UPDATE reservations SET status=? WHERE departure_schedule_instance_id=? AND assigned_vehicle_id=? AND assigned_driver_id=? AND status NOT IN (\'Cancelled\',\'Rejected\')')
            ->execute([$new_status, $r['departure_schedule_instance_id'], $r['assigned_vehicle_id'], $r['assigned_driver_id']]);
        $pdo->prepare("UPDATE scheduled_departures SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$new_status === 'Completed' ? 'Completed' : 'Dispatched', $r['departure_schedule_instance_id']]);
    } else {
        $pdo->prepare('UPDATE reservations SET status = ? WHERE id = ?')->execute([$new_status, $reservation_id]);
    }

     
    $tripStmt = $pdo->prepare('SELECT * FROM trips WHERE reservation_id = ?');
    $tripStmt->execute([$reservation_id]);
    $trip = $tripStmt->fetch();
    if ($new_status === 'In Transit' && $trip && $trip['driver_id'] && $trip['vehicle_id']) {
        $resourceLock = $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(?)), pg_advisory_xact_lock(hashtext(?))");
        $resourceLock->execute(['dispatch-driver:' . $trip['driver_id'], 'dispatch-vehicle:' . $trip['vehicle_id']]);
        if (driver_has_active_trip($pdo, $trip['driver_id'], $trip['id'])) throw new RuntimeException('This driver is already operating another active trip.');
        if (vehicle_has_active_trip($pdo, $trip['vehicle_id'], $trip['id'])) throw new RuntimeException('This vehicle is already being used by another active trip.');
    }
    if ($new_status === 'Returning to Depot' && (!$trip || $trip['status'] !== 'In Transit')) {
        throw new RuntimeException('Return navigation can start only after an active passenger trip.');
    }
    if ($new_status === 'Completed' && $is_assigned_driver && (!$trip || $trip['status'] !== 'Returning to Depot')) {
        throw new RuntimeException('Navigate back to the depot before completing the trip.');
    }
    if ($new_status === 'In Transit' && (!$trip || empty($trip['vehicle_id']))) {
        throw new RuntimeException('An assigned vehicle is required before a trip can start.');
    }
    if ($new_status === 'In Transit' && $trip['status'] !== 'In Transit') {
        $vehicleLock = $pdo->prepare('SELECT id FROM vehicles WHERE id = ? FOR UPDATE');
        $vehicleLock->execute([$trip['vehicle_id']]);
        if (!$vehicleLock->fetchColumn()) throw new RuntimeException('The trip vehicle could not be found.');
        $compliance = vehicle_operational_compliance($pdo, (string)$trip['vehicle_id']);
        if (!$compliance['operational']) {
            throw new RuntimeException('Vehicle cannot start an actual trip because required compliance documents are incomplete or invalid. ' . $compliance['reason']);
        }
    }
    if ($trip) {
        if ($new_status === 'In Transit') {
            $pdo->prepare("UPDATE trips SET status = 'In Transit', progress_pct = 45, current_step = 'In Transit', actual_departure = NOW() WHERE id = ?")
                ->execute([$trip['id']]);
        } elseif ($new_status === 'Returning to Depot') {
            $pdo->prepare("UPDATE trips SET status='Returning to Depot', progress_pct=90, current_step='Returning to Depot' WHERE id=?")
                ->execute([$trip['id']]);
            if (!empty($trip['route_history_id']) && !empty($trip['actual_departure'])) {
                $pdo->prepare(
                    "UPDATE route_history
                        SET actual_duration_mins=GREATEST(1,ROUND(EXTRACT(EPOCH FROM (NOW()-?::timestamp))/60)::int),
                            variance_pct=CASE WHEN predicted_duration_mins>0
                              THEN ROUND(ABS((EXTRACT(EPOCH FROM (NOW()-?::timestamp))/60)-predicted_duration_mins)
                                   / predicted_duration_mins*100,2)
                              ELSE variance_pct END
                      WHERE log_id=?"
                )->execute([$trip['actual_departure'], $trip['actual_departure'], $trip['route_history_id']]);
            }
            $pdo->prepare('UPDATE trip_timeline SET active_step=0 WHERE trip_id=?')->execute([$trip['id']]);
            $returnEvent = $pdo->prepare("SELECT 1 FROM trip_timeline WHERE trip_id=? AND title='Returning to Depot' LIMIT 1");
            $returnEvent->execute([$trip['id']]);
            if (!$returnEvent->fetchColumn()) {
                $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM trip_timeline WHERE trip_id=?');
                $sortStmt->execute([$trip['id']]);
                $pdo->prepare("INSERT INTO trip_timeline (trip_id,title,event_time,completed,active_step,sort_order)
                    VALUES (?,'Returning to Depot',TO_CHAR(NOW(),'YYYY-MM-DD HH24:MI'),0,1,?)")
                    ->execute([$trip['id'], (int)$sortStmt->fetchColumn()]);
            }
            if (!empty($r['assigned_vehicle_id'])) {
                $pdo->prepare("UPDATE vehicles SET location='Returning to Depot' WHERE id=?")
                    ->execute([$r['assigned_vehicle_id']]);
            }
        } else {
            $pdo->prepare(
            "UPDATE trips SET status='Completed', navigation_active=0, progress_pct=100, current_step='Completed & Returned',
                 actual_arrival=NOW(), final_odometer=CASE WHEN ?>0 THEN ? ELSE final_odometer END,
                 vehicle_condition=?, completion_notes=?, toll_fee=CASE WHEN ?>=0 THEN ? ELSE toll_fee END
                 WHERE id=?"
            )->execute([$final_odometer, $final_odometer, $vehicle_condition, $completion_notes,
                $actual_toll_fee, $actual_toll_fee, $trip['id']]);

             
            $pdo->prepare('UPDATE trip_timeline SET active_step = 0 WHERE trip_id = ?')->execute([$trip['id']]);
            $completedEvent = $pdo->prepare("SELECT 1 FROM trip_timeline WHERE trip_id = ? AND title = 'Trip Completed' LIMIT 1");
            $completedEvent->execute([$trip['id']]);
            if (!$completedEvent->fetchColumn()) {
                $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM trip_timeline WHERE trip_id = ?');
                $sortStmt->execute([$trip['id']]);
                $pdo->prepare(
                    "INSERT INTO trip_timeline (trip_id,title,event_time,completed,active_step,sort_order)
                     VALUES (?,'Trip Completed',TO_CHAR(NOW(),'YYYY-MM-DD HH24:MI'),1,0,?)"
                )->execute([$trip['id'], (int)$sortStmt->fetchColumn()]);
            }

             
            if (!empty($trip['route_history_id'])) {
                $pdo->prepare(
                    "UPDATE route_history
                        SET actual_duration_mins = COALESCE(actual_duration_mins, CASE WHEN ?::timestamp IS NOT NULL
                              THEN GREATEST(1, ROUND(EXTRACT(EPOCH FROM (NOW() - ?::timestamp))/60)::int)
                              ELSE NULL END),
                            variance_pct = CASE WHEN actual_duration_mins IS NULL AND predicted_duration_mins > 0 AND ?::timestamp IS NOT NULL
                              THEN ROUND(ABS((EXTRACT(EPOCH FROM (NOW() - ?::timestamp))/60) - predicted_duration_mins)
                                   / predicted_duration_mins * 100, 2)
                              ELSE variance_pct END
                      WHERE log_id = ?"
                )->execute([
                    $trip['actual_departure'], $trip['actual_departure'],
                    $trip['actual_departure'], $trip['actual_departure'],
                    $trip['route_history_id'],
                ]);
            }
        }
    }

     
    if ($new_status === 'Completed') {
        if (!empty($r['customer_id'])) {
            $pdo->prepare("INSERT INTO reservation_events(reservation_id,actor_id,status) VALUES (?,?,'Completed')")
                ->execute([$reservation_id, $current_user['id']]);
        }
        if ($r['assigned_vehicle_id']) {
            $pendingIssueStmt = $pdo->prepare(
                "SELECT 1 FROM maintenance_orders
                  WHERE vehicle_id = ? AND status IN ('Scheduled','In Repair')
                    AND notes LIKE 'Driver issue report:%' LIMIT 1"
            );
            $pendingIssueStmt->execute([$r['assigned_vehicle_id']]);
            $hasPendingDriverIssue = (bool)$pendingIssueStmt->fetchColumn();
            $defaultDriverStmt = $pdo->prepare('SELECT assigned_driver_id FROM vehicles WHERE id=?');
            $defaultDriverStmt->execute([$r['assigned_vehicle_id']]);
            $hasDefaultDriver = (bool)$defaultDriverStmt->fetchColumn();
            $releaseStatus = ($vehicle_condition === 'Good' && !$hasPendingDriverIssue) ? ($hasDefaultDriver ? 'Assigned' : 'Available') : 'Maintenance';
            $location = in_array($releaseStatus, ['Available','Assigned'], true) ? 'Central Depot' : 'Inspection Bay';
            $pdo->prepare(
                "UPDATE vehicles SET status = ?, location = ?,
                    total_trips = total_trips + 1,
                    total_km = total_km + COALESCE(?,0),
                    odometer = CASE WHEN ? > 0 THEN ? ELSE odometer END,
                    maintenance_status = CASE WHEN ? = 'Good' THEN maintenance_status ELSE ? END
                 WHERE id = ?"
            )->execute([$releaseStatus, $location,
                (int)round((float)($trip ? ($trip['distance_km'] ?? 0) : 0)),
                $final_odometer, $final_odometer, $vehicle_condition,
                $vehicle_condition === 'Good' ? '' : $vehicle_condition . ' after trip',
                $r['assigned_vehicle_id']]);

            if ($trip && $vehicle_condition !== 'Good') {
                $woId = next_sequential_id($pdo, 'maintenance_orders', 'id', 'WO-2026-');
                $pdo->prepare(
                    "INSERT INTO maintenance_orders (id,vehicle_id,service_type,priority,scheduled_date,status,estimated_cost,notes,source_trip_id)
                     VALUES (?,?,?,?::varchar,CURRENT_DATE,'Scheduled',0,?,?)"
                )->execute([$woId, $r['assigned_vehicle_id'], 'Post-trip vehicle inspection',
                    $vehicle_condition === 'Defect Reported' ? 'Critical' : 'Medium',
                    $completion_notes ?: $vehicle_condition, $trip['id']]);
            }
        }
        if ($r['assigned_driver_id']) {
            $pdo->prepare("UPDATE drivers SET status = CASE WHEN EXISTS(SELECT 1 FROM vehicles WHERE assigned_driver_id=?) THEN 'Assigned' ELSE 'Active' END, completed_trips = completed_trips + 1, trip_count = trip_count + 1 WHERE id = ?")
                ->execute([$r['assigned_driver_id'], $r['assigned_driver_id']]);
        }

        $completedTripId = $trip ? $trip['id'] : $reservation_id;

    }

    $pdo->commit();

    $messages = [
        'In Transit' => $reservation_id . ' marked as In Transit. Safe travels!',
        'Returning to Depot' => 'Passenger drop-off recorded. Return navigation is now available.',
        'Completed' => $reservation_id . ' marked as Completed. Well done!',
    ];
    redirect_with_toast($return, $messages[$new_status], $new_status === 'Completed' ? 'success' : 'info');
} catch (Exception $ex) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    redirect_with_toast($return, 'Could not update the trip: ' . $ex->getMessage(), 'danger');
}
