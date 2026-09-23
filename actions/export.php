<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('reports.view');

$report = $_GET['report'] ?? 'fleet';
$pdo = db();

$filename = 'toursphere-' . $report . '-' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");  

switch ($report) {
    case 'fuel':
        fputcsv($out, ['Log ID', 'Vehicle', 'Plate', 'Date & Time', 'Fuel Type', 'Liters', 'Price/L', 'Total Cost', 'Driver', 'Odometer', 'Station', 'Receipt', 'Efficiency']);
        foreach ($pdo->query(
            "SELECT f.*, v.plate_number, d.name AS driver_name
               FROM fuel_transactions f
               LEFT JOIN vehicles v ON v.id = f.vehicle_id
               LEFT JOIN drivers d  ON d.id = f.driver_id
              ORDER BY f.transaction_date"
        )->fetchAll() as $f) {
            fputcsv($out, [
                $f['id'], $f['vehicle_id'], $f['plate_number'], $f['transaction_date'], $f['fuel_type'],
                $f['liters'], $f['price_per_liter'], $f['total_cost'], $f['driver_name'],
                $f['odometer'], $f['station'], $f['receipt_no'], $f['efficiency'],
            ]);
        }
        break;

    case 'drivers':
        fputcsv($out, ['Driver ID', 'Name', 'Emp ID', 'Status', 'License No', 'License Expiry', 'Safety Score', 'On-Time %', 'Rating', 'Completed Trips', 'Cancelled Trips', 'Department']);
        foreach ($pdo->query('SELECT * FROM drivers ORDER BY id')->fetchAll() as $d) {
            fputcsv($out, [
                $d['id'], $d['name'], $d['emp_id'], $d['status'], $d['license_no'],
                $d['license_expiration'], $d['safety_score'], $d['on_time_rate'], $d['rating'],
                $d['completed_trips'], $d['cancelled_trips'], $d['department'],
            ]);
        }
        break;

    case 'route':
        fputcsv($out, ['Log ID', 'Route Title', 'Vehicle', 'Generated Date', 'Selected Mode', 'Variance Accuracy']);
        foreach ($pdo->query('SELECT * FROM route_history ORDER BY generated_date')->fetchAll() as $h) {
            fputcsv($out, [$h['log_id'], $h['route_title'], $h['vehicle'], $h['generated_date'], $h['selected_mode'], $h['variance_accuracy']]);
        }
        break;

    case 'fleet':
    default:
        fputcsv($out, ['Vehicle ID', 'Plate', 'Type', 'Brand', 'Model', 'Year', 'Capacity', 'Status', 'Assigned Driver', 'Odometer', 'Fuel Level %', 'Next Maintenance', 'Location']);
        foreach ($pdo->query(
            "SELECT v.*, d.name AS driver_name FROM vehicles v LEFT JOIN drivers d ON d.id = v.assigned_driver_id ORDER BY v.id"
        )->fetchAll() as $v) {
            fputcsv($out, [
                $v['id'], $v['plate_number'], $v['type'], $v['brand'], $v['model'], $v['year'],
                $v['capacity'], $v['status'], $v['driver_name'], $v['odometer'],
                $v['current_fuel'], $v['next_maintenance'], $v['location'],
            ]);
        }
        break;
}

fclose($out);
exit;
