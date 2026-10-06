<?php
$tokenFile=dirname(__DIR__).'/tmp/fuel-receipt-test-token';
if(!is_file($tokenFile)||!hash_equals(trim(file_get_contents($tokenFile)),(string)($_GET['token']??''))){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';require_login();
$p=db();foreach(['fuel_transactions','drivers','vehicles'] as $table)$p->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING IDENTITY)");
$driver=$p->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='driver' AND u.status='Active' LIMIT 1")->fetchColumn();
$p->prepare("INSERT INTO drivers(id,name,status,user_id) VALUES ('RECEIPT-D','Isolated test driver','Active',?)")->execute([$driver]);
$p->exec("INSERT INTO vehicles(id,plate_number,type,brand,model,year,capacity,fuel_type,avg_fuel_km) VALUES ('RECEIPT-V','TEST','Van','Test','Test',2026,10,'Diesel','8 km/L')");
$name=trim(file_get_contents(ROOT_PATH.'/tmp/fuel-receipt-read-name'));
$p->prepare("INSERT INTO fuel_transactions(id,vehicle_id,driver_id,transaction_date,fuel_type,liters,price_per_liter,total_cost,odometer,station,receipt_filename,receipt_mime) VALUES ('RECEIPT-TEST','RECEIPT-V',?,NOW(),'Diesel',2,95.95,191.90,100,'Isolated test',?,'image/png')")->execute([($_GET['other']??'')==='1'?null:'RECEIPT-D',$name]);
$_GET['id']='RECEIPT-TEST';
require ROOT_PATH.'/actions/fuel-receipt.php';
