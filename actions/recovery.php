<?php
ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/recovery.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if (is_logged_in()) redirect_to(BASE_URL . '/index.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect_to(BASE_URL . '/forgot-password.php');

function recovery_back(string $message): never
{
    $_SESSION['recovery_message'] = $message;
    redirect_to(BASE_URL . '/forgot-password.php');
}
if (!is_string($_POST['csrf'] ?? null) || !hash_equals(recovery_csrf(), $_POST['csrf'])) {
    recovery_back('Your session expired. Please refresh the page and try again.');
}
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$state = $_SESSION['recovery'] ?? [];
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
try {
    if ($action === 'phone') recovery_back('Phone recovery is not yet configured. Please use email recovery.');
    if ($action === 'back') {
        unset($_SESSION['recovery']);
        redirect_to(BASE_URL . '/forgot-password.php');
    }
    if ($action === 'request' || $action === 'resend') {
        $email = $action === 'resend' ? ($state['email'] ?? '') : ($_POST['email'] ?? ($_POST['contact'] ?? ''));
        if (!is_string($email)) recovery_back('Please enter a valid recovery contact.');
        $contact = trim((string)$email);
        $isPhone = (bool)preg_match('/\A\+?[0-9][0-9 ()-]{6,24}\z/', $contact);
        $isEmployeeId = (bool)preg_match('/\A[A-Za-z0-9-]{3,30}\z/', $contact) && str_contains(strtoupper($contact), 'EMP');
        if ($action === 'request' && $isPhone) {
            recovery_back('Phone recovery is not yet configured. Please use your registered email address.');
        }
        if (!is_string($email) || strlen($contact) > 150 || (!$isPhone && !$isEmployeeId && !filter_var($contact, FILTER_VALIDATE_EMAIL))) {
            recovery_back('Please enter a valid email address, phone number, or Employee ID.');
        }
        if (($state['resend_at'] ?? 0) > time()) recovery_back('Please wait for the resend countdown before requesting another code.');
        session_regenerate_id(true);
        $_SESSION['recovery'] = recovery_request(db(), $contact, $ip);
        if ($_SESSION['recovery']['stage'] === 'choose_contact') redirect_to(BASE_URL . '/forgot-password.php');
        recovery_deliver_pending(db(), $_SESSION['recovery']['id']);
        if (recovery_delivery_status(db(), $_SESSION['recovery']['id']) === 'failed') {
            recovery_back('We could not send the code right now. Please wait for the countdown, then select Resend code.');
        }
        recovery_back(RECOVERY_GENERIC);
    }
    if ($action === 'choose_contact' && ($state['stage'] ?? '') === 'choose_contact') {
        if (!is_string($_POST['destination'] ?? null)) recovery_back('Enter your registered recovery email or phone number.');
        $destination = trim($_POST['destination']);
        if (strlen($destination) > 150) recovery_back('Enter your registered recovery email or phone number.');
        $q = db()->prepare('SELECT COALESCE(recovery_email, email) AS recovery_email, recovery_phone FROM users WHERE id = ? AND status = \'Active\'');
        $q->execute([$state['user_id'] ?? 0]);
        $account = $q->fetch();
        $emailMatch = $account && $account['recovery_email'] && strcasecmp($destination, $account['recovery_email']) === 0;
        $phoneDigits = preg_replace('/\D+/', '', $destination);
        $accountPhoneDigits = $account ? preg_replace('/\D+/', '', (string)$account['recovery_phone']) : '';
        $phoneMatch = $account && $accountPhoneDigits !== '' && hash_equals($phoneDigits, $accountPhoneDigits);
        if (!$emailMatch && !$phoneMatch) recovery_back('Enter one of the registered recovery contacts shown above.');
        if ($phoneMatch) recovery_back('Phone recovery is not yet configured. Please enter the registered recovery email address.');
        // Issue a fresh request only after the entered contact has matched.
        // Save the email, not the employee ID, so Resend remains in verification.
        $_SESSION['recovery'] = recovery_request(db(), $account['recovery_email'], $ip);
        recovery_deliver_pending(db(), $_SESSION['recovery']['id']);
        if (recovery_delivery_status(db(), $_SESSION['recovery']['id']) === 'failed') {
            recovery_back('We could not send the code right now. Please wait for the countdown, then select Resend code.');
        }
        recovery_back(RECOVERY_GENERIC);
    }
    if ($action === 'verify' && ($state['stage'] ?? '') === 'verify') {
        $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
        $token = recovery_verify(db(), $state['id'], $code, $ip);
        if (!$token) recovery_back('The code is invalid, expired, or unavailable. Check your email or request a new code after the countdown.');
        session_regenerate_id(true);
        $_SESSION['recovery'] = ['id' => $state['id'], 'token' => $token, 'stage' => 'reset'];
        $_SESSION['recovery_csrf'] = bin2hex(random_bytes(32));
        redirect_to(BASE_URL . '/forgot-password.php');
    }
    if ($action === 'reset' && ($state['stage'] ?? '') === 'reset') {
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmation = is_string($_POST['confirmation'] ?? null) ? $_POST['confirmation'] : '';
        if ($error = recovery_password_error($password, $confirmation)) recovery_back($error);
        if (!recovery_reset(db(), $state['id'], $state['token'], $password)) {
            unset($_SESSION['recovery']);
            recovery_back('This password reset has expired. Please start again.');
        }
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['login_success'] = 'Your password has been successfully reset. You may now log in using your new password.';
        redirect_to(BASE_URL . '/login.php');
    }
    recovery_back('Please start a new password recovery request.');
} catch (Throwable $ex) {
    error_log('Password recovery service failed; check database availability and migrations.');
    recovery_back('Recovery is temporarily unavailable. Please try again later.');
}
