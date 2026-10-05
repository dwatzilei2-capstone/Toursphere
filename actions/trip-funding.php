<?php
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/trip_funding.php';
require_login();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
try {
 if(!funding_can_view()){http_response_code(403);throw new DomainException('You do not have permission to view Trip Funding.');}
 $method=$_SERVER['REQUEST_METHOD'];
 if(!in_array($method,['GET','POST'],true)){http_response_code(405);throw new DomainException('Method not allowed.');}
 if($method==='POST' && !funding_can_manage()){http_response_code(403);throw new DomainException('Only Admin and Dispatcher may send funding requests.');}
 $id=trim((string)($method==='POST'?($_POST['trip_id']??''):($_GET['trip_id']??'')));
 if(!$id && $method==='GET' && !empty($_GET['reservation_id'])){
  $q=db()->prepare('SELECT id FROM trips WHERE reservation_id=? ORDER BY created_at DESC LIMIT 1');$q->execute([$_GET['reservation_id']]);$id=(string)$q->fetchColumn();
 }
 $t=funding_trip(db(),$id);if(!$t){http_response_code(404);throw new DomainException('Assign a vehicle and driver to create the trip first.');}
 if($method==='POST'){
  if(!is_string($_POST['csrf']??null) || empty($_SESSION['funding_csrf']) || !hash_equals($_SESSION['funding_csrf'],$_POST['csrf'])){http_response_code(403);throw new DomainException('Funding session expired. Refresh the page.');}
  if(($_POST['action']??'request')==='revise') {
   funding_revise(db(),$id);echo json_encode(['ok'=>true,'data'=>funding_payload(db(),funding_trip(db(),$id))],JSON_UNESCAPED_UNICODE);exit;
  }
  if(($_POST['action']??'request')!=='request')throw new DomainException('Invalid funding action.');
  $f=funding_create(db(),$id,(string)($_POST['funding_method']??''),(string)($_POST['requested_amount']??''));
  session_write_close();ignore_user_abort(true);
  if($f['status']==='Pending Finance Approval'){
   // PostgreSQL's deadline prevents early approval even across concurrent/replayed requests.
   $wait=db()->prepare('SELECT pg_sleep(GREATEST(0,EXTRACT(EPOCH FROM (approval_due_at-clock_timestamp())))) FROM trip_funding_requests WHERE id=?');$wait->execute([$f['id']]);
   funding_mock_response(db(),(int)$f['id']);
  }
  $t=funding_trip(db(),$id);
 }
 echo json_encode(['ok'=>true,'data'=>funding_payload(db(),$t)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if(http_response_code()<400)http_response_code($e instanceof DomainException?422:500);if(!($e instanceof DomainException))error_log('Trip Funding: '.$e->getMessage());echo json_encode(['ok'=>false,'error'=>$e instanceof DomainException?$e->getMessage():'Trip Funding is temporarily unavailable. Try again.']);}
