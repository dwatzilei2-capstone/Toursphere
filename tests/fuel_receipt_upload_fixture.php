<?php
$tokenFile=dirname(__DIR__).'/tmp/fuel-receipt-test-token';
if(!is_file($tokenFile) || !hash_equals(trim(file_get_contents($tokenFile)),(string)($_GET['token']??''))){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/bootstrap.php';require_once ROOT_PATH.'/includes/trip_funding.php';
require_login();
$pdo=db();foreach(['vehicles','drivers','reservations','trips','fuel_transactions','fuel_price_history','trip_funding_requests','trip_cost_estimates','vehicle_cost_ledger'] as $table)$pdo->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING IDENTITY)");
$u=$pdo->query("SELECT u.*,r.code role_code FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='driver' AND u.status='Active' LIMIT 1")->fetch();$current_user=$u;$current_permissions=['fuel.manage','fuel.view'];
$pdo->prepare("INSERT INTO drivers(id,name,status,user_id) VALUES ('LOG-D','Temporary fuel driver','On Trip',?)")->execute([$u['id']]);
$pdo->exec("INSERT INTO vehicles(id,plate_number,type,brand,model,year,capacity,fuel_type,avg_fuel_km) VALUES ('LOG-V','TESTLOG','Van','Test','Fuel',2026,14,'Diesel','8 km/L')");
$pdo->exec("INSERT INTO trips(id,origin,destination,vehicle_id,driver_id,passengers,status) VALUES ('LOG-T','Origin','Destination','LOG-V','LOG-D',10,'In Transit')");
$pdo->prepare("INSERT INTO fuel_price_history(fuel_type,price_per_liter,effective_date,source,updated_by) VALUES ('Diesel',95,CURRENT_DATE,'Temporary isolated test',?)")->execute([$u['id']]);
$_SESSION['fuel_csrf']='test-csrf';$_SERVER['REQUEST_METHOD']='POST';
$_POST=['action'=>'create','csrf'=>'test-csrf','vehicle_id'=>'FORGED','trip_id'=>'FORGED','driver_id'=>'FORGED','liters'=>'30','price_per_liter'=>'96.20','odometer'=>'5000','station'=>'Isolated test station','fuel_type'=>'Gasoline'];
register_shutdown_function(function()use($pdo){
 $row=$pdo->query('SELECT * FROM fuel_transactions')->fetch();
 $result=['last_error'=>error_get_last(),'headers'=>headers_list(),'saved'=>(bool)$row,'receipt'=>$row['receipt_filename']??null,'mime'=>$row['receipt_mime']??null,'total'=>$row['total_cost']??null,'default'=>$row['system_default_price']??null,'vehicle'=>$row['vehicle_id']??null];
 if(!empty($row['receipt_filename'])){$path=fuel_receipt_path($row['receipt_filename']);$result['file_exists']=(bool)$path;if($path)unlink($path);}
 echo json_encode($result);
});
require ROOT_PATH.'/actions/fuel.php';
