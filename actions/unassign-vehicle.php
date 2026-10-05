<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/driver_vehicle_assignment.php';
require_login();
require_permission('vehicles.assign');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect_to(BASE_URL . '/modules/fleet-vehicle-management/vehicle-assignment.php');

$return = $_POST['return'] ?? (BASE_URL . '/modules/fleet-vehicle-management/vehicle-assignment.php');
$vehicleId = trim($_POST['unassign_vehicle_id'] ?? $_POST['vehicle_id'] ?? '');
try {
    if ($vehicleId === '') throw new RuntimeException('Vehicle is required.');
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec("SELECT pg_advisory_xact_lock(hashtext('fleet-designated-assignment'))");
    $stmt = $pdo->prepare('SELECT v.*,d.name driver_name FROM vehicles v LEFT JOIN drivers d ON d.id=v.assigned_driver_id WHERE v.id=? FOR UPDATE OF v');
    $stmt->execute([$vehicleId]);
    $vehicle = $stmt->fetch();
    if (!$vehicle || !$vehicle['assigned_driver_id']) throw new RuntimeException('This vehicle has no default driver assignment.');
    $driverId = $vehicle['assigned_driver_id'];
    $pdo->prepare('SELECT id FROM drivers WHERE id=? FOR UPDATE')->execute([$driverId]);
    $pending = $pdo->prepare("SELECT 1 FROM trips WHERE (driver_id=? OR vehicle_id=?) AND status IN ('Scheduled','Assigned','Confirmed','Dispatched','In Transit','Returning to Depot') LIMIT 1");
    $pending->execute([$driverId,$vehicleId]);
    if ($pending->fetchColumn()) throw new RuntimeException('Complete or cancel the existing trip assignment before changing the designated driver.');
    if (driver_has_active_trip($pdo, $driverId) || vehicle_has_active_trip($pdo, $vehicleId)) {
        throw new RuntimeException('Cannot unassign this driver while an active trip is in progress. Complete or cancel the trip first.');
    }
    $pdo->prepare('UPDATE vehicles SET assigned_driver_id=NULL WHERE id=?')->execute([$vehicleId]);
    refresh_vehicle_operational_status($pdo, $vehicleId);
    refresh_driver_operational_status($pdo, $driverId);
    $pdo->commit();
    redirect_with_toast($return, $vehicle['driver_name'] . ' was unassigned from ' . $vehicle['plate_number'] . '.', 'success');
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    redirect_with_toast($return, 'Unassign failed: ' . $error->getMessage(), 'danger');
}
