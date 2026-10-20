<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/logistics_requests.php';
require_login();
if (!has_role('customer') || !can('logistics.requests.own')) { http_response_code(403); exit('Only Logistics 1 Requesters can submit cargo requests.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
$return=BASE_URL.'/modules/customer-portal/reservations.php';
try {
 if(($_POST['action']??'')!=='create')throw new RuntimeException('Unknown reservation action.');
 if(!is_string($_POST['csrf']??null)||empty($_SESSION['customer_reservation_csrf'])||!hash_equals($_SESSION['customer_reservation_csrf'],$_POST['csrf']))throw new RuntimeException('Session expired. Refresh the form.');
 $pdo=db();$pdo->beginTransaction();
 $id=logistics_create_mock_request($pdo,$_POST,$current_user);
 $pdo->commit();redirect_with_toast($return,'Cargo request '.$id.' submitted for Admin/Dispatcher review.','success');
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();redirect_with_toast($return.'?new=1','Could not submit request: '.$e->getMessage(),'danger');}
