<?php
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/dispatch_board.php';
require_login();require_permission('dispatch.manage');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Method not allowed');}
$return=BASE_URL.'/modules/vehicle-reservation-dispatch/dispatch-board.php';
try {
 if(!is_string($_POST['csrf']??null)||empty($_SESSION['dispatch_board_csrf'])||!hash_equals($_SESSION['dispatch_board_csrf'],$_POST['csrf']))throw new RuntimeException('Session expired. Refresh the Dispatch Board.');
 $pdo=db();$pdo->beginTransaction();
 dispatch_board_verify_delivery($pdo,trim((string)($_POST['reservation_id']??'')),trim((string)($_POST['verification_notes']??'')),$current_user);
 $pdo->commit();redirect_with_toast($return,'Delivery verified completed.','success');
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();redirect_with_toast($return,$e->getMessage(),'danger');}
