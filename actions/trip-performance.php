<?php
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/trip_performance.php';
require_login();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Method not allowed');}
$return=BASE_URL.'/modules/driver-trip-performance/trip-performance.php';$storedPath=null;
try{
 if(!performance_access($current_user))throw new RuntimeException('Access denied.');
 if(!is_string($_POST['csrf']??null)||empty($_SESSION['trip_performance_csrf'])||!hash_equals($_SESSION['trip_performance_csrf'],$_POST['csrf']))throw new RuntimeException('Session expired. Refresh the page.');
 $pdo=db();$pdo->beginTransaction();$op=(string)($_POST['operation']??'');$tripId=trim((string)($_POST['trip_id']??''));
 if($op==='weights')throw new RuntimeException('Configure evaluation weights in System Settings → Trip Performance Evaluation.');
 {
  performance_trip($pdo,$tripId,$current_user,true);$return.='?trip='.rawurlencode($tripId);
  if($op==='add_evidence'){
   $file=null;
   if(($_POST['source']??'')==='upload'){
    $file=fuel_receipt_upload($_FILES['evidence_file']??null);if(!$file)throw new RuntimeException('Choose a POD or supporting document.');
    $file['sha256']=hash_file('sha256',$file['tmp_name']);$dir=ROOT_PATH.'/storage/private/trip-evidence';
    if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Cannot create private evidence storage.');
    $storedPath=$dir.'/'.$file['filename'];if(!move_uploaded_file($file['tmp_name'],$storedPath))throw new RuntimeException('Could not store evidence.');
   }
   performance_add_evidence($pdo,$tripId,$_POST,$current_user,$file);
  }elseif($op==='remove_upload')performance_remove_upload($pdo,$tripId,(int)($_POST['evidence_id']??0),$current_user);
  elseif($op==='verify_evidence')performance_verify_evidence($pdo,$tripId,(int)($_POST['evidence_id']??0),(string)($_POST['verification_notes']??''),$current_user);
  elseif(in_array($op,['submit','adjust','finalize'],true))performance_evaluate($pdo,$tripId,$_POST,$current_user);
  elseif(in_array($op,['dispute','resolve'],true))performance_dispute($pdo,$tripId,$_POST,$current_user);
  else throw new RuntimeException('Invalid action.');
 }
 $pdo->commit();
 // Completed evaluation/review actions return to the list; evidence work stays in the modal.
 if(in_array($op,['submit','adjust','finalize','dispute','resolve'],true))$return=BASE_URL.'/modules/driver-trip-performance/trip-performance.php';
 redirect_with_toast($return,'Trip Performance updated.','success');
}catch(Throwable $e){
 if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();if($storedPath&&is_file($storedPath))unlink($storedPath);
 $message=$e instanceof PDOException?'Could not save. A duplicate dispute or conflicting update may exist; refresh and try again.':$e->getMessage();
 redirect_with_toast($return,$message,'danger');
}
