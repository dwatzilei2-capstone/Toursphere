<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/maintenance.php';
require_login();
require_permission('vehicles.manage');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect_to(BASE_URL . '/dashboard.php');
$return = BASE_URL . '/modules/fleet-vehicle-management/maintenance.php';
try {
 if(!hash_equals((string)($_SESSION['maintenance_csrf']??''),(string)($_POST['csrf_token']??'')) || empty($_SESSION['maintenance_csrf']))throw new RuntimeException('Session expired. Refresh the page and try again.');
 $pdo=db();$pdo->beginTransaction();
 if(($_POST['action']??'')==='create'){
  $id=maintenance_create($pdo,$_POST);
  $message='Work order '.$id.' created.';
 }elseif(($_POST['action']??'')==='update_status'){
  $id=trim((string)($_POST['work_order_id']??''));
  maintenance_transition($pdo,$id,(string)($_POST['status']??''));
  $message='Work order '.$id.' updated.';
 }else throw new RuntimeException('Unknown work order action.');
 $pdo->commit();redirect_with_toast($return,$message,'success');
}catch(Exception $ex){
 if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
 redirect_with_toast($return,'Could not save work order: '.$ex->getMessage(),'danger');
}
