<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/bootstrap.php';
$file=ROOT_PATH.'/tmp/audit-ui-sessions.json';
if(in_array('--clean',$argv,true)) {
 foreach(json_decode(is_file($file)?file_get_contents($file):'[]',true) as $sid){session_id($sid);session_start();$_SESSION=[];session_destroy();}
 if(is_file($file))unlink($file);echo "Audit UI sessions removed.\n";exit;
}
$q=db()->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='fleet_admin' AND u.status='Active' LIMIT 1");
$id=$q->fetchColumn();if(!$id)throw new RuntimeException('No Admin account available');
$sid=bin2hex(random_bytes(24));session_id($sid);session_start();$_SESSION=['user_id'=>(int)$id,'last_valid_activity'=>time()];session_write_close();
file_put_contents($file,json_encode(['fleet_admin'=>$sid]));echo "Temporary audit UI session created.\n";
