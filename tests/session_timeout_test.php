<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$passed = 0;
function timeout_check(bool $ok, string $message): void {
    global $passed;
    if (!$ok) throw new RuntimeException($message);
    $passed++; echo "PASS: $message\n";
}
foreach (['fleet_admin','dispatcher','driver'] as $role) timeout_check(session_timeout_applies($role), "$role timeout enabled");
timeout_check(!session_timeout_applies('customer'), 'Customer timeout behavior remains unchanged');
timeout_check(!session_timeout_expired(['last_valid_activity'=>1000],1299), 'Session valid before five-minute boundary');
timeout_check(session_timeout_expired(['last_valid_activity'=>1000],1300), 'Session expired at exactly five minutes');
foreach (['/fleet/actions/session.php','/fleet/actions/notifications.php','/fleet/actions/route-navigation-state.php'] as $path) {
    $_SERVER['SCRIPT_NAME']=$path; $_SERVER['REQUEST_METHOD']='GET';
    timeout_check(session_request_is_passive(), "$path polling cannot renew session");
}
$_SERVER['SCRIPT_NAME']='/fleet/notifications.php';
timeout_check(!session_request_is_passive(), 'Navigating to notification center counts as activity');
$_SERVER['SCRIPT_NAME']='/fleet/actions/notifications.php'; $_SERVER['REQUEST_METHOD']='POST';
timeout_check(!session_request_is_passive(), 'Marking a notification read counts as activity');
foreach (['fleet_admin','dispatcher','driver'] as $role) {
    $q=db()->prepare("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code=? AND u.status='Active' LIMIT 1"); $q->execute([$role]); $uid=$q->fetchColumn();
    if (!$uid) throw new RuntimeException("No account for $role");
    $_SERVER['SCRIPT_NAME']='/fleet/actions/session.php';
    $_SESSION=['user_id'=>$uid,'last_valid_activity'=>time()-250];
    load_current_user();
    timeout_check(is_logged_in() && $_SESSION['last_valid_activity'] <= time()-250, "$role passive request preserves inactivity age");
    $_SERVER['SCRIPT_NAME']='/fleet/dashboard.php'; load_current_user();
    timeout_check(is_logged_in() && $_SESSION['last_valid_activity'] >= time()-1, "$role valid navigation renews server timer");
    $_SESSION['last_valid_activity']=time()-301;
    load_current_user();
    timeout_check(!is_logged_in() && empty($_SESSION) && !can('dispatch.manage'), "$role expiration destroys authentication and permissions before renewal");
}
$_SESSION=['user_id'=>42,'login_2fa'=>['challenge_id'=>'pending']]; load_current_user();
timeout_check(!is_logged_in() && !isset($_SESSION['last_valid_activity']), 'Pending 2FA is not treated as an authenticated idle session');
$_SESSION=[];
echo "$passed server-side timeout checks passed.\n";
