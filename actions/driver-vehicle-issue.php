<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('driver.portal');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/modules/driver-portal/driver-vehicle.php');
}

$return = $_POST['return'] ?? (BASE_URL . '/modules/driver-portal/driver-vehicle.php');
$vehicleId = trim($_POST['vehicle_id'] ?? '');
$tripId = trim($_POST['trip_id'] ?? '');
$issueType = trim($_POST['issue_type'] ?? '');
$severity = $_POST['severity'] ?? 'Medium';
$description = trim($_POST['description'] ?? '');

$allowedIssues = ['Engine / Mechanical', 'Brakes / Steering', 'Tires / Wheels', 'Electrical', 'Body / Safety Equipment', 'Other'];
$allowedSeverity = ['Critical', 'Medium', 'Low'];
if (!in_array($issueType, $allowedIssues, true) || !in_array($severity, $allowedSeverity, true) || $description === '') {
    redirect_with_toast($return, 'Please complete the vehicle issue details.', 'danger');
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $driverStmt = $pdo->prepare('SELECT id FROM drivers WHERE user_id = ?');
    $driverStmt->execute([$current_user['id']]);
    $driver = $driverStmt->fetch();
    if (!$driver) throw new RuntimeException('Driver profile not found.');

     
    $vehicleStmt = $pdo->prepare(
        "SELECT v.* FROM vehicles v
          WHERE v.id = ?
            AND (v.assigned_driver_id = ? OR EXISTS (
                SELECT 1 FROM reservations r
                 WHERE r.assigned_vehicle_id = v.id AND r.assigned_driver_id = ?
                   AND r.status IN ('Approved','Assigned','Dispatched','In Transit')
            ))
          FOR UPDATE"
    );
    $vehicleStmt->execute([$vehicleId, $driver['id'], $driver['id']]);
    $vehicle = $vehicleStmt->fetch();
    if (!$vehicle) throw new RuntimeException('This vehicle is not assigned to you.');

    $sourceTripId = null;
    if ($tripId !== '') {
        $tripStmt = $pdo->prepare(
            "SELECT t.id FROM trips t
               JOIN reservations r ON r.id = t.reservation_id
              WHERE t.id = ? AND t.vehicle_id = ? AND r.assigned_driver_id = ?
                AND t.status IN ('Scheduled','Assigned','Dispatched','In Transit')"
        );
        $tripStmt->execute([$tripId, $vehicleId, $driver['id']]);
        $sourceTripId = $tripStmt->fetchColumn() ?: null;
        if (!$sourceTripId) throw new RuntimeException('The selected trip is not an active assignment.');
    }

    $workOrderId = next_sequential_id($pdo, 'maintenance_orders', 'id', 'WO-2026-');
    $notes = "Driver issue report: {$description}";
    $pdo->prepare(
        'INSERT INTO maintenance_orders
            (id, vehicle_id, service_type, priority, scheduled_date, status, estimated_cost, notes, source_trip_id)
         VALUES (?, ?, ?, ?, CURRENT_DATE, ?, 0, ?, ?)'
    )->execute([$workOrderId, $vehicleId, $issueType, $severity, 'Scheduled', $notes, $sourceTripId]);

     
    if ($vehicle['status'] === 'On Trip') {
        $pdo->prepare('UPDATE vehicles SET maintenance_status = ? WHERE id = ?')
            ->execute(['Issue Reported - Inspection Required', $vehicleId]);
    } else {
        $pdo->prepare("UPDATE vehicles SET status = 'Maintenance', maintenance_status = ? WHERE id = ?")
            ->execute(['Issue Reported - For Inspection', $vehicleId]);
    }

    $pdo->prepare(
        "INSERT INTO notifications (title, body, time_label, type, category, target, is_read)
         VALUES ('Driver Vehicle Issue Report', ?, 'Just now', ?, 'Maintenance', 'maintenance', 0)"
    )->execute(["{$workOrderId}: {$vehicleId} - {$issueType} ({$severity}).", $severity === 'Critical' ? 'danger' : 'warning']);

    $pdo->commit();
    redirect_with_toast($return, "Issue report {$workOrderId} sent to Fleet Maintenance for review.", 'success');
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    redirect_with_toast($return, 'Could not submit the issue report: ' . $ex->getMessage(), 'danger');
}
