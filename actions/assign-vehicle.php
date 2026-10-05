<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_compliance.php';
require_once ROOT_PATH . '/includes/driver_vehicle_assignment.php';
require_once ROOT_PATH . '/includes/reservation-assignment.php';
require_login();
require_permission('vehicles.assign');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/dashboard.php');
}
$return = $_POST['return'] ?? (BASE_URL . '/modules/fleet-vehicle-management/vehicle-assignment.php');

$vehicle_id = trim($_POST['vehicle_id'] ?? '');
$driver_id  = trim($_POST['driver_id'] ?? '');

if ($vehicle_id === '' || $driver_id === '') {
    redirect_with_toast($return, 'Please select both a vehicle and a driver.', 'danger');
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec("SELECT pg_advisory_xact_lock(hashtext('fleet-designated-assignment'))");

    $vehicle = $pdo->prepare('SELECT * FROM vehicles WHERE id = ? FOR UPDATE');
    $vehicle->execute([$vehicle_id]);
    $v = $vehicle->fetch();

    $driver = $pdo->prepare('SELECT * FROM drivers WHERE id = ? FOR UPDATE');
    $driver->execute([$driver_id]);
    $d = $driver->fetch();

    if (!$v || !$d) {
        throw new RuntimeException('Selected vehicle or driver no longer exists.');
    }
    if (driver_has_active_trip($pdo, $driver_id) || vehicle_has_active_trip($pdo, $vehicle_id)) {
        throw new RuntimeException('Default assignment cannot be changed while the selected driver or vehicle has an active trip.');
    }
    $compliance = vehicle_operational_compliance($pdo, $vehicle_id);
    if (!$compliance['operational']) {
        redirect_with_toast($return, 'Vehicle cannot be assigned. ' . $compliance['reason'], 'danger');
    }
    if (strtolower($v['status']) === 'maintenance') {
        redirect_with_toast($return, 'Vehicles undergoing maintenance are locked from driver assignment.', 'danger');
    }
    $maintenance = $pdo->prepare(
        "SELECT 1 FROM maintenance_orders WHERE vehicle_id = ? AND status = 'In Repair' LIMIT 1"
    );
    $maintenance->execute([$vehicle_id]);
    if ($maintenance->fetchColumn()) {
        redirect_with_toast($return, 'This vehicle has an active maintenance repair and cannot be assigned.', 'danger');
    }

    $existing = $pdo->prepare("SELECT id, plate_number FROM vehicles WHERE assigned_driver_id = ? AND id <> ? LIMIT 1");
    $existing->execute([$driver_id, $vehicle_id]);
    if ($existing->fetch()) {
        redirect_with_toast($return, 'This driver is currently assigned to another vehicle. Unassign that vehicle first before continuing.', 'danger');
    }

    if (!assignment_driver_class_matches($v,$d)) throw new RuntimeException('Driver qualification incompatible with this vehicle.');
    if (!in_array($d['status'], ['Active','Assigned'],true)) throw new RuntimeException('Driver unavailable.');
    if (!in_array($v['status'], ['Available','Assigned'],true)) throw new RuntimeException('Vehicle unavailable.');
    if (!empty($d['license_expiration']) && $d['license_expiration'] < date('Y-m-d')) throw new RuntimeException('Driver license expired.');

    $pending = $pdo->prepare("SELECT 1 FROM trips WHERE (vehicle_id=? OR driver_id=? OR driver_id=?) AND status IN ('Scheduled','Assigned','Confirmed','Dispatched','In Transit','Returning to Depot') LIMIT 1");
    $pending->execute([$vehicle_id,$driver_id,$v['assigned_driver_id']]);
    if ($pending->fetchColumn()) throw new RuntimeException('Complete or cancel the existing trip assignment before changing the designated pairing.');
    $previousDriverId = $v['assigned_driver_id'] ?: null;
     
    $pdo->prepare("UPDATE vehicles SET assigned_driver_id = ?, status = 'Assigned' WHERE id = ?")->execute([$driver_id, $vehicle_id]);
    $pdo->prepare("UPDATE drivers SET status = 'Assigned' WHERE id = ?")->execute([$driver_id]);
    if ($previousDriverId && $previousDriverId !== $driver_id) refresh_driver_operational_status($pdo, $previousDriverId);
    $pdo->commit();

    redirect_with_toast($return, $d['name'] . ' assigned to ' . $v['id'] . ' (' . $v['plate_number'] . ').', 'success');
} catch (Exception $ex) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    redirect_with_toast($return, 'Assignment failed: ' . $ex->getMessage(), 'danger');
}
