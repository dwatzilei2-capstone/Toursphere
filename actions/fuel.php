<?php
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/fuel_operations.php';
require_login();require_permission('fuel.view');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Method not allowed');}
$return=BASE_URL.'/modules/fuel-management/fuel-transactions.php';$savedPath=null;
try {
 if(!is_string($_POST['csrf']??null)||empty($_SESSION['fuel_csrf'])||!hash_equals($_SESSION['fuel_csrf'],$_POST['csrf']))throw new DomainException('Fuel session expired. Refresh the page.');
 $pdo=db();$actor=current_user();$op=(string)($_POST['action']??'');
 $upload=null;
 if(in_array($op,['submit','create'],true)){
  fuel_ops_role($actor,['driver','dispatcher','fleet_admin']);
  $upload=fuel_receipt_upload($_FILES['receipt']??null);
  if($upload){$upload['sha256']=hash_file('sha256',$upload['tmp_name']);$savedPath=fuel_receipt_store($upload);}
 }
 $pdo->beginTransaction();audit_set_actor($pdo,$actor);
 if($op==='authorize')fuel_ops_authorize($pdo,(string)($_POST['trip_id']??''),$_POST,$actor);
 elseif(in_array($op,['submit','create'],true))fuel_ops_submission($pdo,(int)($_POST['authorization_id']??0),$_POST,$actor,$upload);
 elseif($op==='review')fuel_ops_review($pdo,(string)($_POST['transaction_id']??''),(string)($_POST['decision']??''),(string)($_POST['reason']??''),(int)($_POST['revision']??0),$actor);
 elseif($op==='amend_authorization')fuel_ops_amend($pdo,(int)($_POST['authorization_id']??0),$_POST,$actor);
 elseif($op==='cancel_authorization')fuel_ops_cancel($pdo,(int)($_POST['authorization_id']??0),(string)($_POST['reason']??''),$actor);
 elseif($op==='shortage')fuel_ops_shortage($pdo,(string)($_POST['trip_id']??''),$_POST,$actor);
 elseif($op==='resolve_shortage'){
  fuel_ops_role($actor,['dispatcher']);$reason=fuel_ops_note($_POST['reason']??'','Resolution');
  $q=$pdo->prepare("UPDATE fuel_shortage_reports SET status='Resolved',resolution=?,resolved_by=?,resolved_at=clock_timestamp() WHERE id=? AND status='Open' RETURNING *");$q->execute([$reason,$actor['id'],(int)($_POST['report_id']??0)]);$row=$q->fetch();if(!$row)throw new DomainException('Open shortage report not found.');fuel_ops_audit($pdo,'Fuel Shortage Resolved',(string)$row['id'],$actor,null,$row);
 }else throw new DomainException('Invalid fuel action.');
 $pdo->commit();$savedPath=null;redirect_with_toast($return,'Fuel operation saved.','success');
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();if($savedPath&&is_file($savedPath))unlink($savedPath);if($e instanceof PDOException)error_log('Fuel operation: '.$e->getMessage());redirect_with_toast($return,$e instanceof PDOException?'Could not save. A duplicate receipt or conflicting update may exist; refresh and try again.':$e->getMessage(),'danger');}
