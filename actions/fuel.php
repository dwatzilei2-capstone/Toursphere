<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/driver_fuel_context.php';
require_once ROOT_PATH . '/includes/trip_funding.php';
require_once ROOT_PATH . '/includes/fuel_receipts.php';
require_login();
require_permission('fuel.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/dashboard.php');
}
$return = $_POST['return'] ?? (BASE_URL . '/modules/fuel-management/fuel-transactions.php');

$receiptSavedPath = null;
try {
    $pdo = db();

    if (($_POST['action'] ?? '') === 'create') {
        if(!is_string($_POST['csrf']??null) || empty($_SESSION['fuel_csrf']) || !hash_equals($_SESSION['fuel_csrf'],$_POST['csrf'])) throw new RuntimeException('Fuel log session expired. Refresh the page.');
        $vehicle_id = trim($_POST['vehicle_id'] ?? '');
        $trip_id    = trim($_POST['trip_id'] ?? '');
        $driver_id  = !empty($_POST['driver_id']) ? trim($_POST['driver_id']) : null;
        if (($current_user['role_code'] ?? '') === 'driver') {
            $context = driver_fuel_context($pdo, (string)$current_user['id']);
            if (!$context) {
                redirect_with_toast($return, 'Your account is not linked to a Driver profile.', 'danger');
            }
            if (empty($context['trip_id']) || empty($context['vehicle_id']) || empty($context['plate_number'])) {
                redirect_with_toast($return, 'No active or assigned trip with a vehicle is available. Ask Dispatch to assign a trip before logging fuel.', 'danger');
            }
            $driver_id = $context['driver_id'];
            $trip_id = $context['trip_id'] ?? '';
            $vehicle_id = $context['vehicle_id'];
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
        $vehicleFuel=$pdo->prepare('SELECT fuel_type FROM vehicles WHERE id=?');$vehicleFuel->execute([$vehicle_id]);
        $fuel_type=(string)$vehicleFuel->fetchColumn();
        if($fuel_type==='')throw new RuntimeException('Assigned vehicle fuel type is unavailable.');
        $systemPrice=fuel_current_price($pdo,$fuel_type);
        if(!empty($_POST['system_fuel_price_id'])) {
            $savedPrice=$pdo->prepare('SELECT * FROM fuel_price_history WHERE id=? AND lower(fuel_type)=lower(?) AND effective_date<=CURRENT_DATE');
            $savedPrice->execute([(int)$_POST['system_fuel_price_id'],$fuel_type]);
            $systemPrice=$savedPrice->fetch() ?: $systemPrice;
        }
        $funding=$trip_id!==''?funding_request($pdo,$trip_id):null;
        $verifiedTripConsumption = ($_POST['verified_trip_consumption'] ?? '') === '1';

        if ($vehicle_id === '' || !is_finite($liters) || !is_finite($price) || $liters <= 0 || $price <= 0 || $station === '') {
            redirect_with_toast($return, 'Please fill in the required fuel transaction fields.', 'danger');
        }

        $receiptUpload = fuel_receipt_upload($_FILES['receipt'] ?? null);
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
            null,
            $efficiency,
        ]);
        if ($receiptUpload) {
            $receiptSavedPath = fuel_receipt_store($receiptUpload);
            $pdo->prepare('UPDATE fuel_transactions SET receipt_filename=?,receipt_original_name=?,receipt_mime=?,receipt_size=? WHERE id=?')
                ->execute([$receiptUpload['filename'],$receiptUpload['name'],$receiptUpload['mime'],$receiptUpload['size'],$fid]);
        }
        $pdo->prepare('UPDATE fuel_transactions SET system_default_price=?,system_fuel_price_id=?,funding_request_id=?,funding_method=?,funding_safe_reference=? WHERE id=?')
            ->execute([$systemPrice['price_per_liter']??null,$systemPrice['id']??null,$funding['id']??null,$funding['method_name']??null,$funding['safe_reference']??null,$fid]);

         
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
        $receiptSavedPath = null;
        if ($trip_id !== '' && $verifiedTripConsumption) {
            require_once dirname(__DIR__) . '/includes/routethink_engine.php';
            ai_learning_after_trip($pdo, $trip_id);
        }

        redirect_with_toast($return, 'Refill ' . $fid . ' recorded: ' . number_format($liters, 1) . ' L (' . money($total) . ').', 'success');
    }

    redirect_with_toast($return, 'Unknown fuel action.', 'danger');
} catch (Exception $ex) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($receiptSavedPath && is_file($receiptSavedPath)) unlink($receiptSavedPath);
    redirect_with_toast($return, 'Could not record the fuel transaction: ' . $ex->getMessage(), 'danger');
}
