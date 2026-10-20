<?php
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../includes/bootstrap.php';
$file=ROOT_PATH.'/tmp/cost-overview-session.json';
if(in_array('--clean',$argv,true)){if(is_file($file)){session_id(json_decode(file_get_contents($file),true)['fleet_admin']);session_start();$_SESSION=[];session_destroy();unlink($file);}exit;}
$id=db()->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='fleet_admin' AND u.status='Active' LIMIT 1")->fetchColumn();
if(!$id)throw new RuntimeException('No active administrator for UI validation');
$sid=bin2hex(random_bytes(24));session_id($sid);
if(!session_start())throw new RuntimeException('Unable to create local validation session');
$_SESSION=['user_id'=>(int)$id,'last_valid_activity'=>time()];session_write_close();
file_put_contents($file,json_encode(['fleet_admin'=>$sid]));echo "Temporary Cost Overview validation session created.\n";
