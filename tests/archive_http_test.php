<?php
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/bootstrap.php';
$sessionFile=ROOT_PATH.'/tmp/archive-test-sessions.json';
if(in_array('--clean',$argv,true)) {
    foreach(json_decode(@file_get_contents($sessionFile)?:'[]',true) as $role=>$id) {
        session_id($id); session_start(); $_SESSION=[]; session_destroy();
    }
    if(is_file($sessionFile)) unlink($sessionFile);
    foreach(['archive-test-populated.html','archive-test-details.html'] as $name) if(is_file(ROOT_PATH.'/tmp/'.$name)) unlink(ROOT_PATH.'/tmp/'.$name);
    echo "Temporary Archive test sessions and rendered fixtures removed.\n"; exit;
}
$sessions=[]; $passed=0;
function archive_http_check(bool $ok,string $label): void { global $passed; if(!$ok) throw new RuntimeException($label); $passed++; }
function archive_http_request(string $session,string $path,?array $post=null): array {
    $c=curl_init('http://localhost/fleet/'.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIE=>session_name().'='.$session,CURLOPT_TIMEOUT=>15]);
    if($post!==null) curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post),CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $body=curl_exec($c); if($body===false) throw new RuntimeException('Local HTTP unavailable.');
    $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c); return [$status,$body];
}
try {
 foreach(['fleet_admin','fleet_manager','dispatcher','driver','customer'] as $role) {
    $q=db()->prepare("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code=? AND u.status='Active' LIMIT 1"); $q->execute([$role]); $uid=(int)$q->fetchColumn();
    if(!$uid) throw new RuntimeException('An active account is required to verify '.$role);
    $id=bin2hex(random_bytes(24)); session_id($id); session_start();
    $_SESSION=['user_id'=>$uid,'last_valid_activity'=>time(),'archive_csrf'=>'archive-test-csrf']; session_write_close(); $sessions[$role]=$id;
    [$status,$body]=archive_http_request($id,'archive.php?category=completed');
    $allowed=in_array($role,['fleet_admin','fleet_manager','dispatcher'],true);
    archive_http_check($status===($allowed?200:403),$role.' page authorization');
    if($allowed) archive_http_check(strpos($body,'>Reports</span>')<strpos($body,'>Archive</span>'),'Sidebar Archive follows Reports');
    [$status,$body]=archive_http_request($id,'archive.php?category=vehicles');
    archive_http_check($status===(in_array($role,['fleet_admin','fleet_manager'],true)?200:403),$role.' vehicle category authorization');
    [$status,$body]=archive_http_request($id,'actions/archive.php',['action'=>'retire','id'=>'DOES-NOT-EXIST','csrf'=>'archive-test-csrf','confirmed'=>'1','reason'=>'Sold/Disposed']);
    archive_http_check($status===($role==='fleet_admin'?422:403),$role.' backend retirement authorization');
    [$status,$body]=archive_http_request($id,'actions/archive.php',['action'=>'trip','id'=>'DOES-NOT-EXIST','csrf'=>'archive-test-csrf','confirmed'=>'1']);
    archive_http_check($status===(in_array($role,['fleet_admin','dispatcher'],true)?422:403),$role.' backend trip archive authorization');
    if($allowed) {
      [$status,$body]=archive_http_request($id,'actions/archive.php',['action'=>'trip','id'=>'DOES-NOT-EXIST','csrf'=>'invalid','confirmed'=>'1']);
      archive_http_check($status===403,'CSRF enforced');
      [$status,$body]=archive_http_request($id,'archive-details.php?category=completed&id=DOES-NOT-EXIST');
      archive_http_check($status===404,'Details missing record handled');
      [$status,$body]=archive_http_request($id,'modules/vehicle-reservation-dispatch/dispatch-board.php');
      archive_http_check($status===200 && str_contains($body,'Closed Trips'),'Board integrates closed records');
      [$status,$body]=archive_http_request($id,'modules/fleet-vehicle-management/vehicle-directory.php');
      archive_http_check($status===200 && ($role!=='fleet_admin' || str_contains($body,'data-retire-vehicle')),'Vehicle directory integrates retirement');
    }
 }
 file_put_contents($sessionFile,json_encode($sessions));
} finally {
 if(!in_array('--browser',$argv,true)) foreach($sessions as $id) { session_id($id); session_start(); $_SESSION=[]; session_destroy(); }
}
echo "$passed Archive HTTP authorization, CSRF and integration checks passed. No operational records changed.\n";
