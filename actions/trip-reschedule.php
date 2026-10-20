<?php
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/trip_schedule.php';
require_login();require_permission('dispatch.manage');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Method not allowed');}
$return=BASE_URL.'/modules/vehicle-reservation-dispatch/trip-schedule.php';
try{
 if(!is_string($_POST['csrf']??null)||empty($_SESSION['trip_schedule_csrf'])||!hash_equals($_SESSION['trip_schedule_csrf'],$_POST['csrf']))throw new RuntimeException('Session expired. Refresh the schedule.');
 $pdo=db();$pdo->beginTransaction();trip_schedule_reschedule($pdo,trim((string)($_POST['reservation_id']??'')),$_POST,$current_user);$pdo->commit();
 $pickup=substr((string)$_POST['pickup'],0,10);$return.='?week='.rawurlencode($pickup);
 redirect_with_toast($return,'Delivery schedule updated. Review trip funding before dispatch.','success');
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();redirect_with_toast($return,$e->getMessage(),'danger');}
