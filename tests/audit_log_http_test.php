<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$sessions=[]; $checks=0;
function audit_http(string $sid,string $path,string $method='GET'): array {
    $c=curl_init('http://localhost/fleet/'.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIE=>session_name().'='.$sid,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>$method]);
    $body=curl_exec($c); if ($body===false) throw new RuntimeException(curl_error($c));
    $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c); return [$status,$body];
}
try {
    foreach (['fleet_admin','fleet_manager','dispatcher','driver','customer'] as $role) {
        $q=db()->prepare("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code=? AND u.status='Active' LIMIT 1"); $q->execute([$role]); $uid=$q->fetchColumn();
        if (!$uid) continue;
        $sid=bin2hex(random_bytes(24)); $sessions[]=$sid; session_id($sid); session_start(); $_SESSION=['user_id'=>(int)$uid,'last_valid_activity'=>time()]; session_write_close();
        [$status,$body]=audit_http($sid,'audit-log.php?q=reservation');
        if ($status!==($role==='fleet_admin'?200:403)) throw new RuntimeException('Page authorization failed for '.$role);
        if ($role==='fleet_admin' && (str_contains($body,'Warning:') || substr_count($body,'id="sidebar"')!==1)) throw new RuntimeException('Invalid page rendering');
        $checks++;
        [$status]=audit_http($sid,'audit-log.php','POST');
        if ($status!==($role==='fleet_admin'?405:403)) throw new RuntimeException('Mutation denied for '.$role); $checks++;
        $home=$role==='driver'?'modules/driver-portal/driver-dashboard.php':($role==='customer'?'modules/customer-portal/reservations.php':'dashboard.php');
        [$status,$body]=audit_http($sid,$home);
        if ($status!==200 || str_contains($body,'/audit-log.php')!==($role==='fleet_admin')) throw new RuntimeException('Menu authorization failed for '.$role); $checks++;
    }
} finally {
    foreach ($sessions as $sid) { session_id($sid); session_start(); $_SESSION=[]; session_destroy(); }
}
echo "PASS: $checks HTTP page, menu and mutation authorization checks.\n";
