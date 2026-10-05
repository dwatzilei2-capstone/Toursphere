<?php
require_once dirname(__DIR__).'/includes/bootstrap.php';require_once ROOT_PATH.'/includes/trip_funding.php';require_login();
header('Content-Type: application/json');
try {
 if(!funding_can_manage() || !can('ai.view')){http_response_code(403);throw new DomainException('Route preparation is restricted to Admin and Dispatcher.');}
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);throw new DomainException('POST required.');}
 if(!is_string($_POST['csrf']??null) || empty($_SESSION['route_prepare_csrf']) || !hash_equals($_SESSION['route_prepare_csrf'],$_POST['csrf'])){http_response_code(403);throw new DomainException('Route preparation session expired.');}
 $id=(string)($_POST['trip_id']??'');$pdo=db();$pdo->beginTransaction();$t=funding_trip($pdo,$id);if(!$t)throw new DomainException('Trip not found.');
 $pdo->prepare('SELECT id FROM reservations WHERE id=? FOR UPDATE')->execute([$t['reservation_id']]);$pdo->prepare('SELECT id FROM trips WHERE id=? FOR UPDATE')->execute([$id]);$t=funding_trip($pdo,$id);
 if(!in_array($t['status'],['Assigned','Confirmed','Scheduled'],true))throw new DomainException('Only an assigned trip can save its pre-trip route.');
 $f=funding_request($pdo,$id);if($f && in_array($f['status'],['Pending Finance Approval','Funding Confirmed'],true))throw new DomainException('The route is locked by its funding request. Revise the assignment before preparing a new funded route.');
 if((string)($_POST['vehicle_id']??'')!==$t['vehicle_id'] || trim((string)($_POST['origin']??''))!==trim($t['origin']) || trim((string)($_POST['destination']??''))!==trim($t['destination']))throw new DomainException('Use the assigned trip origin, destination and vehicle.');
 $distance=filter_var($_POST['distance_km']??null,FILTER_VALIDATE_FLOAT);
 if(!$distance || !is_finite($distance) || $distance<=0 || $distance>100000)throw new DomainException('Generate a valid driving route first.');
 $pdo->prepare('UPDATE trips SET distance_km=? WHERE id=?')->execute([$distance,$id]);
 $pdo->prepare('INSERT INTO audit_logs(action,user_id,entity_type,entity_id,details) VALUES (?,?,?,?,?)')->execute(['TRIP_ROUTE_PREPARED',current_user()['id'],'trip',$id,json_encode(['distance_km'=>$distance,'source'=>'Existing AI Route Planner','strategy'=>(string)($_POST['strategy']??'')])]);
 $pdo->commit();echo json_encode(['ok'=>true]);
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if(http_response_code()<400)http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e instanceof DomainException?$e->getMessage():'Route preparation failed.']);}
