<?php
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/schedules.php';
require_once ROOT_PATH.'/includes/logistics_requests.php';
require_login();
if(!has_role(['fleet_admin','dispatcher'])){http_response_code(403);exit('Forbidden');}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Method not allowed');}
$return=BASE_URL.'/modules/vehicle-reservation-dispatch/reservations.php';
try {
 if(!is_string($_POST['csrf']??null)||empty($_SESSION['reservation_review_csrf'])||!hash_equals($_SESSION['reservation_review_csrf'],$_POST['csrf']))throw new RuntimeException('Review session expired. Refresh the page.');
 $pdo=db();$pdo->beginTransaction();
 $id=trim((string)($_POST['reservation_id']??''));
 $status=logistics_review_request($pdo,$id,(string)($_POST['decision']??''),trim((string)($_POST['reason']??'')),$current_user);
 $pdo->commit();redirect_with_toast($return,'Reservation '.$id.' '.$status.'.','success');
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();redirect_with_toast($return,$e->getMessage(),'danger');}
