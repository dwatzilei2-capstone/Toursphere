<?php
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/fuel_receipts.php';
require_login();
if (!can('fuel.view')) { http_response_code(403); exit('Forbidden'); }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$stmt = db()->prepare('SELECT f.*,v.plate_number,d.name driver_name,d.user_id driver_user_id FROM fuel_transactions f LEFT JOIN vehicles v ON v.id=f.vehicle_id LEFT JOIN drivers d ON d.id=f.driver_id WHERE f.id=?');
$stmt->execute([$id]);$record=$stmt->fetch();
if (!$record) { http_response_code(404); exit('Receipt not found'); }
if (has_role('driver') && (string)$record['driver_user_id'] !== (string)current_user()['id']) { http_response_code(403); exit('Forbidden'); }
$path = fuel_receipt_path($record['receipt_filename'] ?? null);
if (!$path || !in_array($record['receipt_mime'],['image/jpeg','image/png','application/pdf'],true)) { http_response_code(404); exit('Receipt not uploaded'); }
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
if (($_GET['info'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['id'=>$record['id'],'vehicle'=>$record['plate_number'].' ('.$record['vehicle_id'].')','trip'=>$record['trip_id'],'date'=>date('M j, Y · g:i A',strtotime($record['transaction_date'])),'fuel_type'=>$record['fuel_type'],'liters'=>number_format((float)$record['liters'],2).' L','price'=>money($record['price_per_liter']),'total'=>money($record['total_cost']),'driver'=>$record['driver_name']?:'Unknown','efficiency'=>$record['efficiency']?:'Unknown','station'=>$record['station'],'mime'=>$record['receipt_mime']],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
session_write_close();
header('Content-Type: '.$record['receipt_mime']);
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; sandbox");
header('X-Frame-Options: SAMEORIGIN');
$extension=pathinfo($path,PATHINFO_EXTENSION);
header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="fuel-receipt.'. $extension .'"');
header('Content-Length: '.filesize($path));readfile($path);
