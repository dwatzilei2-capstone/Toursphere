<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('vehicles.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/dashboard.php');
}
$return = $_POST['return'] ?? (BASE_URL . '/modules/fleet-vehicle-management/maintenance.php');

try {
    $pdo = db();

    if (($_POST['action'] ?? '') === 'update_status') {
        $workOrderId = trim($_POST['work_order_id'] ?? '');
        $newStatus = in_array($_POST['status'] ?? '', ['Scheduled','In Repair','Completed'], true)
            ? $_POST['status'] : '';
        if ($workOrderId === '' || $newStatus === '') {
            redirect_with_toast($return, 'Invalid maintenance status update.', 'danger');
        }
        $pdo->beginTransaction();
        $woStmt = $pdo->prepare('SELECT * FROM maintenance_orders WHERE id = ? FOR UPDATE');
        $woStmt->execute([$workOrderId]);
        $order = $woStmt->fetch();
        if (!$order) throw new RuntimeException('Work order not found.');
        $pdo->prepare('UPDATE maintenance_orders SET status = ? WHERE id = ?')->execute([$newStatus, $workOrderId]);
        if ($newStatus === 'In Repair') {
            $pdo->prepare("UPDATE vehicles SET status='Maintenance', maintenance_status=? WHERE id=? AND status <> 'On Trip'")
                ->execute([$order['service_type'].' (In Repair)', $order['vehicle_id']]);
        } elseif ($newStatus === 'Completed') {
            $nextDate = (new DateTimeImmutable('today'))->modify('+' . max(1, (int)fleet_setting('maintenance.interval_days', '90')) . ' days')->format('Y-m-d');
            $pdo->prepare("UPDATE vehicles SET status='Available', maintenance_status='Healthy', last_maintenance=?, next_maintenance=? WHERE id=? AND status <> 'On Trip'")
                ->execute([date('Y-m-d'), $nextDate, $order['vehicle_id']]);
            if ($order['status'] !== 'Completed') {
                $cost = (float)$order['estimated_cost'];
                $pdo->prepare('UPDATE vehicle_cost_ledger SET maintenance_cost=maintenance_cost+?, total_cost=total_cost+? WHERE vehicle_id=?')
                    ->execute([$cost, $cost, $order['vehicle_id']]);
            }
        }
        $pdo->commit();
        redirect_with_toast($return, "Work order {$workOrderId} updated to {$newStatus}.", 'success');
    }

    if (($_POST['action'] ?? '') === 'create') {
        $vehicle_id    = trim($_POST['vehicle_id'] ?? '');
        $service_type  = trim($_POST['service_type'] ?? '');
        $priority      = in_array($_POST['priority'] ?? '', ['Critical', 'Medium', 'Low'], true) ? $_POST['priority'] : 'Medium';
        $scheduled_date = trim($_POST['scheduled_date'] ?? '');
        $status        = in_array($_POST['status'] ?? '', ['Scheduled', 'In Repair', 'Completed'], true) ? $_POST['status'] : 'Scheduled';
        $estimated_cost = (float)($_POST['estimated_cost'] ?? 0);
        $notes         = trim($_POST['notes'] ?? '');

        if ($vehicle_id === '' || $service_type === '' || $scheduled_date === '') {
            redirect_with_toast($return, 'Please fill in the required work order fields.', 'danger');
        }

        $wid = next_sequential_id($pdo, 'maintenance_orders', 'id', 'WO-2026-');
        $stmt = $pdo->prepare(
            'INSERT INTO maintenance_orders (id, vehicle_id, service_type, priority, scheduled_date, status, estimated_cost, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$wid, $vehicle_id, $service_type, $priority, $scheduled_date, $status, $estimated_cost, $notes]);

         
        if ($status === 'In Repair') {
            $pdo->prepare("UPDATE vehicles SET status = 'Maintenance', maintenance_status = ? WHERE id = ?")
                ->execute([$service_type . ' (In Repair)', $vehicle_id]);
        }

        redirect_with_toast($return, 'Work order ' . $wid . ' created for ' . $vehicle_id . '.', 'success');
    }

    redirect_with_toast($return, 'Unknown work order action.', 'danger');
} catch (Exception $ex) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    redirect_with_toast($return, 'Could not create the work order: ' . $ex->getMessage(), 'danger');
}

