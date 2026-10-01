<?php

ini_set('display_errors', '0');
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/login-2fa.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

if (is_logged_in()) redirect_to(BASE_URL . '/index.php');
$state = $_SESSION['login_2fa'] ?? [];
if (!$state) redirect_to(BASE_URL . '/login.php');

try {
    $verificationStatus = login_2fa_status(db(), $state);
    $active = $verificationStatus['active'];
} catch (Throwable $ex) {
    $active = false;
    $verificationStatus = ['remaining' => 0, 'message' => 'Verification is temporarily unavailable. Please refresh the page and try again.'];
}

$message = $_SESSION['login_2fa_message'] ?? '';
unset($_SESSION['login_2fa_message']);
$cooldown = max(0, (int)($state['resend_at'] ?? 0) - time());
$expiresIn = $verificationStatus['remaining'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verify Your Identity — <?= e(company_name()) ?></title>
  <meta name="description" content="Secure login verification for <?= e(company_name()) ?>.">
  <link rel="icon" type="image/png" href="assets/images/toursphere-logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="css/lib/bootstrap.min.css" rel="stylesheet">
  <link href="css/lib/bootstrap-icons.min.css" rel="stylesheet">
  <link href="css/auth.css" rel="stylesheet">
  <link href="css/login-2fa.css?v=<?= (int)filemtime(__DIR__ . '/css/login-2fa.css') ?>" rel="stylesheet">
</head>
<body class="login-2fa-page">
<main class="login-wrapper">
  <div class="login-card">
    <div class="login-header">
      <div class="logo-container"><img src="<?= e(company_logo()) ?>" alt="<?= e(company_name()) ?> logo"></div>
      <h1 class="brand-name"><?= e(company_name()) ?></h1>
      <p class="brand-sub">Secure account verification</p>
      <div class="system-pill"><i class="bi bi-shield-lock"></i><span>Two-factor authentication</span></div>
    </div>
    <div class="login-body">
      <div class="verification-icon" aria-hidden="true"><i class="bi bi-envelope-check"></i></div>
      <h2 class="verification-title">Verify Your Identity</h2>
      <p class="verification-copy">A 6-digit verification code has been sent to your registered email.</p>
      <p class="verification-destination"><?= e($state['masked_email'] ?? 'your registered email address') ?></p>

      <?php if ($message): ?><div class="login-2fa-notice" role="status"><?= e($message) ?></div><?php endif; ?>
      <?php if (!$active): ?><div class="login-alert" role="alert"><i class="bi bi-exclamation-triangle-fill mt-1 text-danger"></i><div><?= e($verificationStatus['message']) ?></div></div><?php endif; ?>

      <form method="post" action="actions/login-2fa.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(login_2fa_csrf()) ?>">
        <input type="hidden" name="action" value="verify">
        <div class="form-group">
          <label for="login-otp" class="form-label-custom">Verification code</label>
          <div class="input-wrapper otp-input-wrapper">
            <span class="input-icon"><i class="bi bi-shield-check"></i></span>
            <input id="login-otp" name="code" class="input-field login-otp-input" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" placeholder="000000" required autofocus>
          </div>
        </div>
        <button class="btn-signin" type="submit" <?= $active ? '' : 'disabled' ?>><i class="bi bi-check2-circle"></i><span>Verify Code</span></button>
      </form>

      <div class="verification-timer"><i class="bi bi-clock"></i><span id="otp-expiration" data-seconds="<?= $expiresIn ?>" data-active="<?= $active ? '1' : '0' ?>"><?= $active ? 'Code expires in ' . floor($expiresIn / 60) . ':' . str_pad((string)($expiresIn % 60), 2, '0', STR_PAD_LEFT) : 'Code unavailable — see the message above' ?></span></div>

      <form method="post" action="actions/login-2fa.php" class="verification-secondary">
        <input type="hidden" name="csrf" value="<?= e(login_2fa_csrf()) ?>">
        <input type="hidden" name="action" value="resend">
        <button class="verification-link" type="submit" id="resend-code" data-cooldown="<?= $cooldown ?>">Resend Code</button>
        <span id="resend-countdown" class="verification-muted" role="timer"></span>
      </form>

      <form method="post" action="actions/login-2fa.php" class="login-footer verification-back">
        <input type="hidden" name="csrf" value="<?= e(login_2fa_csrf()) ?>">
        <input type="hidden" name="action" value="cancel">
        <button class="verification-link" type="submit"><i class="bi bi-arrow-left"></i> Back to Login</button>
      </form>
    </div>
  </div>
</main>
<script src="js/login-2fa.js?v=<?= (int)filemtime(__DIR__ . '/js/login-2fa.js') ?>"></script>
</body>
</html>
