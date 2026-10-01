<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!function_exists('curl_init')) throw new RuntimeException('cURL extension required for local HTTP checks.');
$q=db()->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='fleet_admin' AND u.status='Active' LIMIT 1");
$uid=(int)$q->fetchColumn();
$csrf=bin2hex(random_bytes(32));
$passed=0; $sessions=[];
function seed_timeout_session(int $age): string {
    global $uid,$csrf,$sessions;
    $id=bin2hex(random_bytes(24)); session_id($id); session_start();
    if (session_status() !== PHP_SESSION_ACTIVE) throw new RuntimeException('Temporary PHP session storage is unavailable.');
    $_SESSION=['user_id'=>$uid,'last_valid_activity'=>time()-$age,'session_activity_csrf'=>$csrf];
    session_write_close(); $sessions[]=$id; return $id;
}
function timeout_http(string $id,string $path,?array $post=null): array {
    $ch=curl_init('http://localhost/fleet/'.$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIE=>session_name().'='.$id,CURLOPT_TIMEOUT=>10]);
    if ($post!==null) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post),CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $output=curl_exec($ch); if($output===false) throw new RuntimeException('Local HTTP unavailable.');
    $result=['status'=>curl_getinfo($ch,CURLINFO_RESPONSE_CODE),'headers'=>substr($output,0,curl_getinfo($ch,CURLINFO_HEADER_SIZE)),
        'body'=>substr($output,curl_getinfo($ch,CURLINFO_HEADER_SIZE))]; curl_close($ch); return $result;
}
function http_timeout_check(bool $ok,string $message): void { global $passed; if(!$ok)throw new RuntimeException($message); $passed++; }
try {
    $id=seed_timeout_session(250);
    $r=timeout_http($id,'actions/session.php'); $d=json_decode($r['body'],true);
    http_timeout_check($r['status']===200 && $d['remaining']<=50, 'Status does not extend session');
    $r=timeout_http($id,'actions/session.php',['action'=>'activity','csrf'=>'incorrect']);
    http_timeout_check($r['status']===403, 'Invalid CSRF cannot extend session');
    $r=timeout_http($id,'actions/session.php',['action'=>'activity','csrf'=>$csrf]); $d=json_decode($r['body'],true);
    http_timeout_check($r['status']===200 && $d['remaining']>=299, 'Stay Logged In resets valid session to five minutes');
    $r=timeout_http($id,'actions/session.php'); $d=json_decode($r['body'],true);
    http_timeout_check($r['status']===200 && $d['remaining']>=298, 'Another tab using the same session sees the renewed timer');
    $r=timeout_http($id,'actions/session.php',['action'=>'logout','csrf'=>$csrf]);
    http_timeout_check($r['status']===200 && !empty(json_decode($r['body'],true)['logged_out']), 'Secure logout succeeds');
    $r=timeout_http($id,'actions/session.php'); http_timeout_check($r['status']===401,'Logged-out session no longer authenticates');
    $id=seed_timeout_session(301);
    $r=timeout_http($id,'actions/session.php',['action'=>'activity','csrf'=>$csrf]); $d=json_decode($r['body'],true);
    http_timeout_check($r['status']===401 && !empty($d['session_expired']), 'Expired session cannot be revived by Stay Logged In');
    $id=seed_timeout_session(301);
    $r=timeout_http($id,'dashboard.php');
    http_timeout_check($r['status']===302 && str_contains($r['headers'],rawurlencode(SESSION_IDLE_MESSAGE)), 'Expired protected page redirects with inactivity message');
    $id=seed_timeout_session(250);
    $r=timeout_http($id,'actions/notifications.php');
    http_timeout_check($r['status']===200 && preg_match('/X-TourSphere-Session-Remaining: ([0-9]+)/i',$r['headers'],$m) && (int)$m[1]<=50, 'Notification polling does not renew session');
    $r=timeout_http($id,'dashboard.php');
    http_timeout_check($r['status']===200 && str_contains($r['body'],'id="session-warning"') && str_contains($r['body'],'session-timeout.js'), 'Protected layout renders warning and controller');
    $id=seed_timeout_session(301);
    $r=timeout_http($id,'actions/notifications.php',null);
    // curl sends no browser fetch metadata, so explicitly AJAX checks use JSON Accept above.
    http_timeout_check(in_array($r['status'],[302,401],true),'Expired polling is blocked');
} finally {
    foreach($sessions as $id) { session_id($id); session_start(); $_SESSION=[]; session_destroy(); }
}
echo "$passed local HTTP timeout checks passed. Temporary test sessions destroyed.\n";
