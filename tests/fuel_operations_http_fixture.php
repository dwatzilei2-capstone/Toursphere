<?php
// Static theme assets only; application actions remain protected by the test token.
if(PHP_SAPI==='cli-server'&&preg_match('~^/(css|js|assets)/[^?]+\.(css|js|png|jpg|jpeg|webp|svg|woff2?|ttf|ico)$~i',parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)))return false;
$token=getenv('TOURSPHERE_FUEL_TEST_TOKEN');
if(PHP_SAPI!=='cli-server'||!$token||!hash_equals($token,(string)($_SERVER['HTTP_X_TEST_TOKEN']??$_GET['token']??$_COOKIE['fuel_ui_fixture']??''))){http_response_code(404);exit;}
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/health'){echo 'ok';exit;}
$fixture=file_get_contents(__DIR__.'/fuel_operations_test.php');
$fixture=substr($fixture,0,strpos($fixture,'$files=[];'));
$fixture=str_replace("if(PHP_SAPI!=='cli')exit;",'',$fixture);
$fixture=str_replace("require __DIR__.'/logistics_requests_test.php';", "eval(substr(str_replace(\"if(PHP_SAPI!=='cli')exit;\",'',file_get_contents(__DIR__.'/logistics_requests_test.php')),5));",$fixture);
ob_start();eval(substr($fixture,5));ob_end_clean();
$role=(string)($_SERVER['HTTP_X_TEST_ROLE']??$_GET['role']??$_COOKIE['fuel_ui_role']??'driver');
$current_user=['id'=>1,'name'=>'Isolated Fuel Test','role_code'=>$role,'role_name'=>'Isolated Test','emp_id'=>'TEST','avatar'=>null];
$current_permissions=['fuel.view'];if(in_array($role,['fleet_admin','dispatcher'],true))$current_permissions=array_merge($current_permissions,['dispatch.view','dispatch.manage']);
if($role==='customer')$current_permissions=[];
$_SESSION['last_valid_activity']=time();$_SESSION['fuel_csrf']='fuel-http-test';
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/page'){
 $_SERVER['REQUEST_METHOD']='GET';$page=($_GET['overview']??'')==='1'?'fuel-overview.php':'fuel-transactions.php';
 require ROOT_PATH.'/modules/fuel-management/'.$page;exit;
}
$publicBefore=$pdo->query('SELECT COUNT(*) FROM public.fuel_transactions')->fetchColumn();
if(in_array(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),['/review','/receipt','/review-page','/actions/fuel-receipt.php'],true)){
 $name=bin2hex(random_bytes(24)).'.png';$path=ROOT_PATH.'/storage/private/fuel-receipts/'.$name;
 file_put_contents($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
 $file=['filename'=>$name,'name'=>'isolated-review.png','mime'=>'image/png','size'=>filesize($path),'sha256'=>hash_file('sha256',$path)];
 $values=['liters'=>8,'price_per_liter'=>60,'total_cost'=>480,'odometer'=>110,'station'=>'HTTP review test','receipt_no'=>'HTTP-REVIEW','fuel_type'=>'Diesel','transaction_date'=>date('Y-m-d\TH:i')];
 $tx=ops_tx(fn()=>fuel_ops_submission($pdo,$authorization,$values,$driver,$file));
 if(in_array(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),['/receipt','/actions/fuel-receipt.php'],true)){
  register_shutdown_function(function()use($path){if(is_file($path))unlink($path);});
  if(!empty($_GET['other']))$current_user['id']=999999;
  if(!empty($_GET['tamper']))file_put_contents($path,'changed');
  $_GET['id']=!empty($_GET['missing'])?'MISSING':$tx;$_SERVER['REQUEST_METHOD']='GET';
  require ROOT_PATH.'/actions/fuel-receipt.php';exit;
 }
 if($role!=='dispatcher')ops_tx(fn()=>fuel_ops_review($pdo,$tx,'confirm','Isolated Dispatcher review',1,$dispatcher));
 if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/review-page'){
  setcookie('fuel_ui_fixture',$token,['path'=>'/','httponly'=>true,'samesite'=>'Strict']);setcookie('fuel_ui_role',$role,['path'=>'/','httponly'=>true,'samesite'=>'Strict']);
  register_shutdown_function(function()use($path,$pdo,$publicBefore){if(is_file($path))unlink($path);if($pdo->query('SELECT COUNT(*) FROM public.fuel_transactions')->fetchColumn()!==$publicBefore)throw new RuntimeException('Public records changed.');});
  if(!empty($_GET['receipt_missing'])&&is_file($path))unlink($path);
  $_SERVER['REQUEST_METHOD']='GET';$_GET=['fuel_trip'=>'T-OPS','fuel_review'=>$tx];
  ob_start();require ROOT_PATH.'/modules/fuel-management/fuel-transactions.php';$pageHtml=ob_get_clean();
  $pageHtml=preg_replace('/<script src="[^"]*\/(?:session-timeout|notifications)\.js[^"]*"><\/script>/','',$pageHtml);
  echo preg_replace('/<form\b/','<form onsubmit="return false"',$pageHtml);exit;
 }
 $_POST+=['action'=>'review','csrf'=>'fuel-http-test','transaction_id'=>$tx,'decision'=>$role==='dispatcher'?'confirm':'verify','revision'=>$role==='dispatcher'?1:2,'reason'=>'Isolated review'];
}
$_POST+=['action'=>'submit','csrf'=>'fuel-http-test','authorization_id'=>$authorization,'liters'=>'8','price_per_liter'=>'60','total_cost'=>'480','odometer'=>'110','station'=>'HTTP isolated test','receipt_no'=>'HTTP-TEST-1','fuel_type'=>'Diesel','transaction_date'=>date('Y-m-d\TH:i')];
register_shutdown_function(function()use($pdo,$publicBefore){
 $row=$pdo->query('SELECT * FROM fuel_transactions')->fetch();$path=$row?fuel_receipt_path($row['receipt_filename']):null;
 echo json_encode(['saved'=>(bool)$row,'status'=>$row['verification_status']??null,'total'=>$row['total_cost']??null,'owner'=>$row['driver_id']??null,'vehicle'=>$row['vehicle_id']??null,'hash_valid'=>$path&&hash_equals($row['receipt_sha256'],hash_file('sha256',$path)),'verified_cost'=>funding_actual_cost($pdo,'T-OPS'),'public_unchanged'=>$pdo->query('SELECT COUNT(*) FROM public.fuel_transactions')->fetchColumn()===$publicBefore]);
 if($path)unlink($path);
});
require ROOT_PATH.'/actions/fuel.php';
