<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/bootstrap.php';
$file=ROOT_PATH.'/tmp/assignment-test-sessions.json';
if(in_array('--clean',$argv,true)) {
 foreach(json_decode(file_get_contents($file),true) as $id){session_id($id);session_start();$_SESSION=[];session_destroy();}
 unlink($file);echo "Test sessions removed.\n";exit;
}
if(is_file($file)) foreach(json_decode(file_get_contents($file),true) as $oldId){session_id($oldId);session_start();$_SESSION=[];session_destroy();}
$sessions=[];
foreach(['fleet_admin','dispatcher','driver','customer'] as $role){
 $q=db()->prepare("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code=? AND u.status='Active' LIMIT 1");$q->execute([$role]);$id=(int)$q->fetchColumn();
 if(!$id)throw new RuntimeException('Required test role missing');
 $sid=bin2hex(random_bytes(24));session_id($sid);session_start();$_SESSION=['user_id'=>$id,'last_valid_activity'=>time()];session_write_close();$sessions[$role]=$sid;
}
file_put_contents($file,json_encode($sessions));echo "Temporary authenticated test sessions created.\n";
