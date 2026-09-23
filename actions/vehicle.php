<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('vehicles.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/dashboard.php');
}
$return = $_POST['return'] ?? (BASE_URL . '/modules/fleet-vehicle-management/vehicle-directory.php');

try {
    $pdo = db();

    if (($_POST['action'] ?? '') === 'create') {
        $plate = strtoupper(trim($_POST['plate_number'] ?? ''));
        $type  = trim($_POST['type'] ?? 'Tour Bus');
        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year  = (int)($_POST['year'] ?? date('Y'));
        $capacity = (int)($_POST['capacity'] ?? 0);
        $fuel_capacity = (int)($_POST['fuel_capacity'] ?? 0);

        if ($plate === '' || $brand === '' || $model === '' || $capacity <= 0) {
            redirect_with_toast($return, 'Please fill in all required vehicle fields.', 'danger');
        }

        $dup = $pdo->prepare('SELECT id FROM vehicles WHERE plate_number = ?');
        $dup->execute([$plate]);
        if ($dup->fetch()) {
            redirect_with_toast($return, 'A vehicle with plate ' . $plate . ' already exists.', 'danger');
        }

        $vid = next_sequential_id($pdo, 'vehicles', 'id', 'VEH-');
        $stmt = $pdo->prepare(
            'INSERT INTO vehicles (id, plate_number, type, brand, model, year, capacity, status,
                                   fuel_type, fuel_capacity, current_fuel, odometer, maintenance_status,
                                   location, total_trips, total_km, avg_fuel_km, operating_cost_km)
             VALUES (?, ?, ?, ?, ?, ?, ?, "Available", "Diesel", ?, 100, 0, "Healthy",
                     "Central Depot", 0, 0, "—", "—")'
        );
        $stmt->execute([$vid, $plate, $type, $brand, $model, $year, $capacity, $fuel_capacity]);

        redirect_with_toast($return, 'Vehicle ' . $vid . ' (' . $plate . ') registered to the fleet directory.', 'success');
    }

    redirect_with_toast($return, 'Unknown vehicle action.', 'danger');
} catch (Exception $ex) {
    redirect_with_toast($return, 'Could not save the vehicle: ' . $ex->getMessage(), 'danger');
}
