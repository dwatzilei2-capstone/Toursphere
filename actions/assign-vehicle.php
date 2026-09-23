<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
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

    $vehicle = $pdo->prepare('SELECT * FROM vehicles WHERE id = ?');
    $vehicle->execute([$vehicle_id]);
    $v = $vehicle->fetch();

    $driver = $pdo->prepare('SELECT * FROM drivers WHERE id = ?');
    $driver->execute([$driver_id]);
    $d = $driver->fetch();

    if (!$v || !$d) {
        redirect_with_toast($return, 'Selected vehicle or driver no longer exists.', 'danger');
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

    $existing = $pdo->prepare('SELECT id, plate_number FROM vehicles WHERE assigned_driver_id = ? AND id <> ? LIMIT 1');
    $existing->execute([$driver_id, $vehicle_id]);
    if ($existing->fetch() || (strtolower((string)($d['status'] ?? '')) === 'assigned' && (string)($v['assigned_driver_id'] ?? '') !== (string)$driver_id)) {
        redirect_with_toast($return, 'This driver is currently assigned to another vehicle. Unassign that vehicle first before continuing.', 'danger');
    }

    $vehicle_type = strtolower((string)($v['type'] ?? ''));
    $required_class = ((int)$v['capacity'] > 30 || str_contains($vehicle_type, 'bus') || str_contains($vehicle_type, 'coach')) ? 3 : 2;
    $license_text = strtolower((string)($d['license_class'] ?? ''));
    $has_required_class = preg_match('/(?:class|restriction)\s*' . $required_class . '\b/', $license_text) === 1;
    // A Class 3 authorization also covers the lighter Class 2 vehicle category.
    if ($required_class === 2) {
        $has_required_class = $has_required_class || preg_match('/(?:class|restriction)\s*3\b/', $license_text) === 1;
    }
    if (!$has_required_class) {
        redirect_with_toast($return, $d['name'] . ' does not have the required Class ' . $required_class . ' license for this vehicle.', 'danger');
    }

     
    if ($v['assigned_driver_id']) {
        $pdo->prepare("UPDATE drivers SET status = 'Active' WHERE id = ?")->execute([$v['assigned_driver_id']]);
    }
     
    $pdo->prepare("UPDATE vehicles SET assigned_driver_id = ?, status = 'Assigned' WHERE id = ?")->execute([$driver_id, $vehicle_id]);
    $pdo->prepare("UPDATE drivers SET status = 'Assigned' WHERE id = ?")->execute([$driver_id]);

    redirect_with_toast($return, $d['name'] . ' assigned to ' . $v['id'] . ' (' . $v['plate_number'] . ').', 'success');
} catch (Exception $ex) {
    redirect_with_toast($return, 'Assignment failed: ' . $ex->getMessage(), 'danger');
}
