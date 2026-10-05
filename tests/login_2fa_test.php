<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/login-2fa.php';

$pdo = db();
$suffix = bin2hex(random_bytes(5));
$users = [];
$limitBuckets = [];
$passed = 0;

function check_2fa(bool $condition, string $label): void
{
    global $passed;
    if (!$condition) throw new RuntimeException('FAILED: ' . $label);
    $passed++;
    echo "PASS: {$label}\n";
}

function add_limit_bucket(string $key): void
{
    global $limitBuckets;
    $limitBuckets[] = hash('sha256', $key);
}

function create_test_user(PDO $pdo, string $roleCode, string $suffix, string $label): array
{
    $role = $pdo->prepare('SELECT id, name FROM roles WHERE code = ?');
    $role->execute([$roleCode]);
    $roleRow = $role->fetch();
    if (!$roleRow) throw new RuntimeException("Missing role: {$roleCode}");
    $q = $pdo->prepare("INSERT INTO users (emp_id, name, email, password_hash, role_id, department, status) VALUES (?, ?, ?, ?, ?, 'Automated security test', 'Active') RETURNING *");
    $q->execute(['T2FA-' . strtoupper($label) . '-' . $suffix, '2FA Test ' . $label, "2fa-{$label}-{$suffix}@example.test", password_hash('Test password only', PASSWORD_DEFAULT), $roleRow['id']]);
    $user = $q->fetch();
    $user['role_code'] = $roleCode;
    $user['role_name'] = $roleRow['name'];
    return $user;
}

try {
    $admin = create_test_user($pdo, 'fleet_admin', $suffix, 'admin');
    $driver = create_test_user($pdo, 'driver', $suffix, 'driver');
    $customer = create_test_user($pdo, 'customer', $suffix, 'customer');
    $admin['recovery_email'] = "registered-admin-{$suffix}@example.test";
    $admin['two_factor_email'] = $admin['recovery_email'];
    $pdo->prepare('UPDATE users SET recovery_email = ? WHERE id = ?')->execute([$admin['recovery_email'], $admin['id']]);
    $users = [(int)$admin['id'], (int)$driver['id'], (int)$customer['id']];

    check_2fa(login_2fa_required('fleet_admin'), 'Admin role requires login OTP');
    check_2fa(login_2fa_required('driver'), 'Driver role requires login OTP');
    check_2fa(!login_2fa_required('customer') && !login_2fa_required('dispatcher'), 'Other roles keep their existing login behavior');

    $invalid = $admin;
    $invalid['email'] = 'not-an-email';
    $invalid['recovery_email'] = '';
    $invalid['two_factor_email'] = '';
    try {
        login_2fa_issue($pdo, $invalid, 'test-invalid-' . $suffix, static function (): void {});
        check_2fa(false, 'Invalid registered email is rejected');
    } catch (DomainException $ex) {
        check_2fa(true, 'Invalid registered email is rejected without bypass');
    }

    $adminIp = 'test-admin-' . $suffix;
    add_limit_bucket('issue-ip:' . $adminIp);
    add_limit_bucket('issue-user:' . $admin['id']);
    add_limit_bucket('verify-ip:' . $adminIp);
    $deliveredAdminCode = null;
    $adminState = login_2fa_issue($pdo, $admin, $adminIp, static function (string $email, string $name, string $code) use (&$deliveredAdminCode, $admin): void {
        check_2fa($email === $admin['recovery_email'] && $email !== $admin['email'] && $name === $admin['name'], 'OTP uses the registered recovery email instead of the login email');
        $deliveredAdminCode = $code;
    });
    check_2fa((bool)preg_match('/\A\d{6}\z/', (string)$deliveredAdminCode), 'Generated OTP is exactly six digits');
    $q = $pdo->prepare('SELECT * FROM login_2fa_challenges WHERE id = ?');
    $q->execute([$adminState['challenge_id']]);
    $stored = $q->fetch();
    check_2fa($stored && $stored['otp_hash'] !== $deliveredAdminCode && password_verify($deliveredAdminCode, $stored['otp_hash']), 'Database stores only a secure OTP hash');
    check_2fa($stored['destination'] === $admin['recovery_email'], 'Challenge is bound to the registered recovery email');
    check_2fa($stored['purpose'] === 'login_2fa', 'Login OTP purpose is isolated from password recovery');
    $status = login_2fa_status($pdo, $adminState);
    check_2fa($status['active'] && $status['remaining'] >= 298 && $status['remaining'] <= 300, 'Fresh OTP has a full five-minute database countdown');
    $pdo->prepare("UPDATE login_2fa_challenges SET expires_at = clock_timestamp() + interval '1 second' WHERE id = ?")->execute([$adminState['challenge_id']]);
    check_2fa(login_2fa_state_is_active($pdo, $adminState), 'OTP remains active until its expiry boundary');
    $pdo->prepare("UPDATE login_2fa_challenges SET expires_at = clock_timestamp() + interval '5 minutes' WHERE id = ?")->execute([$adminState['challenge_id']]);

    check_2fa(login_2fa_verify($pdo, $adminState, '000000' === $deliveredAdminCode ? '000001' : '000000', $adminIp) === null, 'Incorrect OTP is rejected');
    check_2fa(login_2fa_verify($pdo, $adminState, $deliveredAdminCode, $adminIp) === (int)$admin['id'], 'Correct Admin OTP completes verification');
    check_2fa(login_2fa_verify($pdo, $adminState, $deliveredAdminCode, $adminIp) === null, 'Used OTP cannot be reused');

    $driverIp = 'test-driver-' . $suffix;
    add_limit_bucket('issue-ip:' . $driverIp);
    add_limit_bucket('issue-user:' . $driver['id']);
    add_limit_bucket('verify-ip:' . $driverIp);
    $oldDriverCode = null;
    $oldDriverState = login_2fa_issue($pdo, $driver, $driverIp, static function (string $email, string $name, string $code) use (&$oldDriverCode): void { $oldDriverCode = $code; });
    $pdo->prepare("UPDATE login_2fa_limits SET last_requested_at = clock_timestamp() - interval '61 seconds' WHERE bucket = ?")
        ->execute([hash('sha256', 'issue-user:' . $driver['id'])]);
    $newDriverCode = null;
    $newDriverState = login_2fa_issue($pdo, $driver, $driverIp, static function (string $email, string $name, string $code) use (&$newDriverCode): void { $newDriverCode = $code; });
    check_2fa($oldDriverState['challenge_id'] !== $newDriverState['challenge_id'], 'Resend creates a new challenge');
    $oldStatus = login_2fa_status($pdo, $oldDriverState);
    check_2fa(!$oldStatus['active'] && $oldStatus['remaining'] > 0 && str_contains($oldStatus['message'], 'replaced'), 'Replaced code is not incorrectly reported as expired');
    check_2fa(login_2fa_verify($pdo, $oldDriverState, $oldDriverCode, $driverIp) === null, 'Old OTP is invalid after resend');
    check_2fa(login_2fa_verify($pdo, $newDriverState, $newDriverCode, $driverIp) === (int)$driver['id'], 'New Driver OTP verifies successfully');

    $expiredId = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO login_2fa_challenges (id,user_id,destination,otp_hash,delivery_status,expires_at) VALUES (?,?,?,?, 'sent', clock_timestamp() - interval '1 second')")
        ->execute([$expiredId, $admin['id'], $admin['email'], password_hash('123456', PASSWORD_DEFAULT)]);
    check_2fa(login_2fa_verify($pdo, ['challenge_id' => $expiredId, 'user_id' => $admin['id']], '123456', $adminIp) === null, 'Expired OTP is rejected');
    check_2fa(str_contains(login_2fa_status($pdo, ['challenge_id' => $expiredId, 'user_id' => $admin['id']])['message'], '5-minute'), 'True expiry has a specific message');
    $pdo->prepare("UPDATE login_2fa_challenges SET used_at = clock_timestamp(), otp_hash = '' WHERE id = ?")->execute([$expiredId]);

    $crossId = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO login_2fa_challenges (id,user_id,destination,otp_hash,delivery_status,expires_at) VALUES (?,?,?,?, 'sent', clock_timestamp() + interval '5 minutes')")
        ->execute([$crossId, $admin['id'], $admin['email'], password_hash('654321', PASSWORD_DEFAULT)]);
    check_2fa(login_2fa_verify($pdo, ['challenge_id' => $crossId, 'user_id' => $driver['id']], '654321', $adminIp) === null, 'One account cannot use another account OTP');
    $pdo->prepare("UPDATE login_2fa_challenges SET used_at = clock_timestamp(), otp_hash = '' WHERE id = ?")->execute([$crossId]);

    $attemptId = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO login_2fa_challenges (id,user_id,destination,otp_hash,delivery_status,expires_at) VALUES (?,?,?,?, 'sent', clock_timestamp() + interval '5 minutes')")
        ->execute([$attemptId, $admin['id'], $admin['email'], password_hash('112233', PASSWORD_DEFAULT)]);
    $attemptState = ['challenge_id' => $attemptId, 'user_id' => $admin['id']];
    for ($i = 0; $i < LOGIN_2FA_MAX_ATTEMPTS; $i++) login_2fa_verify($pdo, $attemptState, '999999', $adminIp);
    check_2fa(login_2fa_verify($pdo, $attemptState, '112233', $adminIp) === null, 'Correct OTP is rejected after maximum incorrect attempts');

    $_SESSION = ['user_id' => (int)$admin['id'], 'login_2fa' => ['challenge_id' => 'pending', 'user_id' => (int)$admin['id']]];
    load_current_user();
    check_2fa(empty($_SESSION['user_id']) && !is_logged_in(), 'Pending OTP session cannot access protected pages');

    check_2fa(function_exists('recovery_send_email') && function_exists('login_2fa_send_email') && function_exists('toursphere_mailer'), 'Forgot Password and Login 2FA share the existing PHPMailer infrastructure');
    $mailer = toursphere_mailer();
    check_2fa($mailer instanceof PHPMailer\PHPMailer\PHPMailer && $mailer->Mailer === 'smtp' && $mailer->Host !== '', 'Existing PHPMailer SMTP configuration loads successfully');
    $purposeCount = (int)$pdo->query("SELECT COUNT(*) FROM login_2fa_challenges WHERE purpose <> 'login_2fa'")->fetchColumn();
    check_2fa($purposeCount === 0, 'Login challenges cannot be created with a password-reset purpose');

    echo "\n{$passed} login 2FA security checks passed.\n";
} finally {
    $_SESSION = [];
    if ($users) {
        $marks = implode(',', array_fill(0, count($users), '?'));
        $pdo->prepare("DELETE FROM users WHERE id IN ({$marks})")->execute($users);
    }
    if ($limitBuckets) {
        $limitBuckets = array_values(array_unique($limitBuckets));
        $marks = implode(',', array_fill(0, count($limitBuckets), '?'));
        $pdo->prepare("DELETE FROM login_2fa_limits WHERE bucket IN ({$marks})")->execute($limitBuckets);
    }
}
