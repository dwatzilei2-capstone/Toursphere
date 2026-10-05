<?php
require_once dirname(__DIR__).'/includes/bootstrap.php';require_once ROOT_PATH.'/includes/trip_funding.php';require_login();
if(!has_role('fleet_admin')){http_response_code(403);exit('Forbidden');}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Method not allowed');}
$return=BASE_URL.'/settings.php?tab=fuel-prices';
try {
 if(!is_string($_POST['csrf']??null) || empty($_SESSION['fuel_price_csrf']) || !hash_equals($_SESSION['fuel_price_csrf'],$_POST['csrf']))throw new DomainException('Price configuration session expired.');
 fuel_save_price(db(),(string)($_POST['fuel_type']??''),(string)($_POST['price']??''),(string)($_POST['effective_date']??''),(string)($_POST['source']??''));
 redirect_with_toast($return,'Current fuel price updated. Historical estimates and transactions are unchanged.','success');
}catch(DomainException $e){redirect_with_toast($return,$e->getMessage(),'danger');}
