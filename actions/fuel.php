<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('fuel.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/dashboard.php');
}
$return = $_POST['return'] ?? (BASE_URL . '/modules/fuel-management/fuel-transactions.php');

try {
    $pdo = db();

    if (($_POST['action'] ?? '') === 'create') {
        $vehicle_id = trim($_POST['vehicle_id'] ?? '');
        $trip_id    = trim($_POST['trip_id'] ?? '');
        $driver_id  = !empty($_POST['driver_id']) ? trim($_POST['driver_id']) : null;
        if (!$driver_id && ($current_user['role_code'] ?? '') === 'driver') {
            $dStmt = $pdo->prepare('SELECT id FROM drivers WHERE user_id = ?');
            $dStmt->execute([$current_user['id']]);
            $driver_id = $dStmt->fetchColumn() ?: null;
        }
        if (($current_user['role_code'] ?? '') === 'driver') {
            if (!$driver_id) {
                redirect_with_toast($return, 'Your account is not linked to a Driver profile.', 'danger');
            }
            $tripStmt = $pdo->prepare(
                "SELECT id, vehicle_id FROM trips
                  WHERE driver_id = ? AND status IN ('Dispatched','In Transit','Completed')
                  ORDER BY CASE WHEN status = 'In Transit' THEN 0 WHEN status = 'Dispatched' THEN 1 ELSE 2 END,
                           COALESCE(actual_departure, scheduled_departure, created_at) DESC LIMIT 1"
            );
            $tripStmt->execute([$driver_id]);
            $driverTrip = $tripStmt->fetch();
            if (!$driverTrip || !$driverTrip['vehicle_id']) {
                redirect_with_toast($return, 'No assigned trip and vehicle are available for this fuel entry.', 'danger');
            }
            $trip_id = $driverTrip['id'];
            $vehicle_id = $driverTrip['vehicle_id'];
        } elseif ($trip_id !== '') {
            $tripStmt = $pdo->prepare('SELECT vehicle_id, driver_id FROM trips WHERE id = ?');
            $tripStmt->execute([$trip_id]);
            $linkedTrip = $tripStmt->fetch();
            if (!$linkedTrip) redirect_with_toast($return, 'Selected trip was not found.', 'danger');
            $vehicle_id = $linkedTrip['vehicle_id'] ?: $vehicle_id;
            $driver_id = $linkedTrip['driver_id'] ?: $driver_id;
        }
        $liters     = (float)($_POST['liters'] ?? 0);
        $price      = (float)($_POST['price_per_liter'] ?? 0);
        $odometer   = (int)($_POST['odometer'] ?? 0);
        $station    = trim($_POST['station'] ?? '');
        $fuel_type  = trim($_POST['fuel_type'] ?? 'Diesel');
        $verifiedTripConsumption = ($_POST['verified_trip_consumption'] ?? '') === '1';

        if ($vehicle_id === '' || $liters <= 0 || $price <= 0 || $station === '') {
            redirect_with_toast($return, 'Please fill in the required fuel transaction fields.', 'danger');
        }

        $pdo->beginTransaction();

        $total = round($liters * $price, 2);
        $fid = next_sequential_id($pdo, 'fuel_transactions', 'id', 'FL-2026-', 4);

         
        $veh = $pdo->prepare('SELECT avg_fuel_km FROM vehicles WHERE id = ?');
        $veh->execute([$vehicle_id]);
        $efficiency = $veh->fetchColumn() ?: '—';

        $stmt = $pdo->prepare(
            'INSERT INTO fuel_transactions (id, vehicle_id, driver_id, trip_id, transaction_date, fuel_type,
                                            liters, price_per_liter, total_cost, odometer, station,
                                            receipt_no, efficiency)
             VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $fid, $vehicle_id, $driver_id, $trip_id ?: null, $fuel_type, $liters, $price, $total, $odometer, $station,
            'REC-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $station), 0, 3)) . '-' . mt_rand(10000, 99999),
            $efficiency,
        ]);

         
        $pdo->prepare('UPDATE vehicles SET odometer = ?, current_fuel = 100 WHERE id = ?')->execute([$odometer, $vehicle_id]);

         
        if ($trip_id !== '') {
            $routeStmt = $pdo->prepare('SELECT route_history_id FROM trips WHERE id = ?');
            $routeStmt->execute([$trip_id]);
            if (($routeLogId = $routeStmt->fetchColumn()) && $verifiedTripConsumption) {
                $pdo->prepare(
                    'UPDATE route_history SET actual_fuel_liters = ?, actual_fuel_verified = 1,
                     actual_fuel_transaction_id = ?,
                     variance_pct = CASE WHEN predicted_fuel_liters > 0
                       THEN ROUND(ABS(? - predicted_fuel_liters)
                                  / predicted_fuel_liters * 100, 2)
                       ELSE variance_pct END WHERE log_id = ?'
                )->execute([$liters, $fid, $liters, $routeLogId]);
            }
        }
        $pdo->prepare(
            'UPDATE vehicle_cost_ledger SET fuel_cost = fuel_cost + ?, total_cost = total_cost + ? WHERE vehicle_id = ?'
        )->execute([$total, $total, $vehicle_id]);
        $pdo->commit();

        redirect_with_toast($return, 'Refill ' . $fid . ' recorded: ' . number_format($liters, 1) . ' L (' . money($total) . ').', 'success');
    }

    redirect_with_toast($return, 'Unknown fuel action.', 'danger');
} catch (Exception $ex) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    redirect_with_toast($return, 'Could not record the fuel transaction: ' . $ex->getMessage(), 'danger');
}
