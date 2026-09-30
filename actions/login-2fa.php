<?php

ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/login-2fa.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

if (is_logged_in()) redirect_to(BASE_URL . '/index.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect_to(BASE_URL . '/login.php');

function login_2fa_back(string $message): never
{
    $_SESSION['login_2fa_message'] = $message;
    redirect_to(BASE_URL . '/verify-login.php');
}

$state = $_SESSION['login_2fa'] ?? [];
if (!$state) redirect_to(BASE_URL . '/login.php');
if (!is_string($_POST['csrf'] ?? null) || !hash_equals(login_2fa_csrf(), $_POST['csrf'])) {
    login_2fa_back('Your verification session expired. Please refresh the page and try again.');
}

$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

try {
    if ($action === 'cancel') {
        login_2fa_cancel(db(), $state);
        unset($_SESSION['login_2fa'], $_SESSION['login_2fa_csrf'], $_SESSION['login_2fa_message']);
        session_regenerate_id(true);
        redirect_to(BASE_URL . '/login.php');
    }

    if ($action === 'resend') {
        if (($state['resend_at'] ?? 0) > time()) {
            login_2fa_back('Please wait for the resend countdown before requesting another code.');
        }
        $q = db()->prepare("SELECT u.*, COALESCE(NULLIF(BTRIM(u.recovery_email), ''), u.email) AS two_factor_email, r.code AS role_code, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.status = 'Active'");
        $q->execute([(int)($state['user_id'] ?? 0)]);
        $user = $q->fetch();
        if (!$user || !login_2fa_required((string)$user['role_code'])) {
            login_2fa_cancel(db(), $state);
            unset($_SESSION['login_2fa'], $_SESSION['login_2fa_csrf']);
            redirect_to(BASE_URL . '/login.php?error=' . rawurlencode('This account is no longer available for verification.'));
        }
        $_SESSION['login_2fa'] = login_2fa_issue(db(), $user, $ip);
        $_SESSION['login_2fa_csrf'] = bin2hex(random_bytes(32));
        login_2fa_back('A new verification code has been sent. The previous code is no longer valid.');
    }

    if ($action === 'verify') {
        $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
        $userId = login_2fa_verify(db(), $state, $code, $ip);
        if (!$userId) {
            login_2fa_back('The code is invalid, expired, already used, or the maximum number of attempts was reached.');
        }
        session_regenerate_id(true);
        $_SESSION = ['user_id' => $userId];
        redirect_to(BASE_URL . '/index.php');
    }

    login_2fa_back('Please enter the verification code sent to your registered email.');
} catch (Throwable $ex) {
    error_log('Login two-factor verification failed; check database and SMTP availability.');
    login_2fa_back($ex instanceof RuntimeException ? $ex->getMessage() : 'Verification is temporarily unavailable. Please try again.');
}
