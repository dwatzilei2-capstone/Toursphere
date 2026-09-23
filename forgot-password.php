<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/recovery.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if (is_logged_in()) redirect_to(BASE_URL . '/index.php');
$state = $_SESSION['recovery'] ?? [];
$stage = $state['stage'] ?? 'choose';
$message = $_SESSION['recovery_message'] ?? '';
unset($_SESSION['recovery_message']);
if ($stage === 'reset') {
    try { $allowed = recovery_can_reset(db(), $state['id'], $state['token'] ?? ''); }
    catch (Throwable $ex) { $allowed = false; }
    if (!$allowed) {
        unset($_SESSION['recovery']);
        $stage = 'choose';
        $message = 'This password reset has expired. Please start again.';
    }
}
$method = in_array($_GET['method'] ?? '', ['email', 'phone'], true) ? $_GET['method'] : '';
$cooldown = max(0, ($state['resend_at'] ?? 0) - time());
function recovery_fields(string $action): void { ?>
  <input type="hidden" name="csrf" value="<?= e(recovery_csrf()) ?>">
  <input type="hidden" name="action" value="<?= e($action) ?>">
<?php }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Account Recovery — <?= e(company_name()) ?></title>
  <link rel="icon" type="image/png" href="assets/images/toursphere-logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="css/vendor/bootstrap.min.css" rel="stylesheet">
  <link href="css/vendor/bootstrap-icons.min.css" rel="stylesheet">
  <link href="css/auth.css" rel="stylesheet">
  <link href="css/recovery.css?v=20260914" rel="stylesheet">
</head>
<body class="recovery-page">
<main class="login-wrapper">
  <div class="login-card">
    <div class="login-header">
      <div class="logo-container"><img src="<?= e(company_logo()) ?>" alt="<?= e(company_name()) ?> logo"></div>
      <div><p class="brand-name"><?= e(company_name()) ?></p>
      <p class="brand-sub">Account recovery</p></div>
    </div>
    <div class="login-body">
      <?php if ($message): ?><div class="recovery-notice" role="status"><?= e($message) ?></div><?php endif; ?>
      <?php if ($stage === 'verify'): ?>
        <h2 class="recovery-title">Check your email</h2>
        <p class="recovery-destination">Enter the code for <strong><?= e($state['masked_email'] ?? 'your registered email address') ?></strong>.</p>
        <p class="recovery-help">Your code expires in 5 minutes. Check your spam folder if you don’t see the email.</p>
        <form method="post" action="actions/recovery.php">
          <?php recovery_fields('verify'); ?>
          <div class="form-group">
            <label for="code" class="form-label-custom">Verification code</label>
            <div class="input-wrapper"><span class="input-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><input id="code" name="code" class="input-field recovery-code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required autofocus></div>
          </div>
          <button class="btn-signin" type="submit">Verify code</button>
        </form>
        <form method="post" action="actions/recovery.php" class="recovery-secondary">
          <?php recovery_fields('resend'); ?>
          <button class="recovery-link" type="submit" id="resend" data-cooldown="<?= $cooldown ?>">Resend code</button>
          <span id="resend-countdown" class="recovery-help" role="timer"></span>
        </form>
        <p class="recovery-help">A new code replaces the previous one.</p>
      <?php elseif ($stage === 'choose_contact'): ?>
        <h2 class="recovery-title">Confirm your recovery contact</h2>
        <p class="recovery-help">Use these hints to enter your full recovery email or phone number.</p>
        <div class="recovery-contact-hint"><i class="bi bi-envelope-check" aria-hidden="true"></i><span>Registered email: <strong><?= e($state['masked_email'] ?? '') ?></strong></span></div>
        <?php if (!empty($state['masked_phone'])): ?><div class="recovery-contact-hint"><i class="bi bi-telephone" aria-hidden="true"></i><span>Registered phone: <strong><?= e($state['masked_phone']) ?></strong></span></div><?php endif; ?>
        <form method="post" action="actions/recovery.php" class="recovery-contact-form"><?php recovery_fields('choose_contact'); ?>
          <div class="form-group"><label for="destination" class="form-label-custom">Recovery email or phone number</label><div class="input-wrapper"><input id="destination" name="destination" type="text" class="input-field" autocomplete="off" maxlength="150" required autofocus></div></div>
          <p class="recovery-help">Email recovery is available. SMS recovery is not yet available.</p>
          <button class="btn-signin" type="submit">Send verification code</button>
        </form>
      <?php elseif ($stage === 'reset'): ?>
        <h2 class="recovery-title">Create a new password</h2>
        <p class="recovery-help" id="password-help">Use at least 12 characters. Spaces are welcome. Maximum 72 bytes (some characters use more than one byte).</p>
        <form method="post" action="actions/recovery.php">
          <?php recovery_fields('reset'); ?>
          <?php foreach (['password' => 'New Password', 'confirmation' => 'Confirm New Password'] as $name => $label): ?>
            <div class="form-group"><label for="<?= $name ?>" class="form-label-custom"><?= $label ?></label><div class="input-wrapper"><span class="input-icon"><i class="bi bi-lock" aria-hidden="true"></i></span><input id="<?= $name ?>" name="<?= $name ?>" type="password" class="input-field" autocomplete="new-password" aria-describedby="password-help" minlength="12" maxlength="72" required></div></div>
          <?php endforeach; ?>
          <button class="btn-signin" type="submit">Reset password</button>
        </form>
      <?php elseif (!$method || $method === 'email'): ?>
        <h2 class="recovery-title">Recover your account</h2>
        <p class="recovery-help">Enter your Employee ID to see your recovery contact hints, or use your registered email to request a code.</p>
        <form method="post" action="actions/recovery.php">
          <?php recovery_fields('request'); ?>
          <div class="form-group"><label for="contact" class="form-label-custom">Email, Phone Number, or Employee ID</label><div class="input-wrapper"><span class="input-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span><input id="contact" name="contact" type="text" class="input-field" autocomplete="email tel" maxlength="150" placeholder="Email, phone number, or Employee ID" required autofocus></div></div>
          <button class="btn-signin" type="submit">Continue</button>
        </form>
      <?php elseif ($method === 'phone'): ?>
        <h2 class="recovery-title">Recover using phone number</h2>
        <div class="recovery-notice" role="status">Phone recovery is not yet configured. Please use email recovery.</div>
        <form method="post" action="actions/recovery.php">
          <?php recovery_fields('phone'); ?>
          <div class="form-group"><label for="phone" class="form-label-custom">Registered Phone Number</label><div class="input-wrapper"><span class="input-icon"><i class="bi bi-telephone" aria-hidden="true"></i></span><input id="phone" name="phone" type="tel" class="input-field" autocomplete="tel" placeholder="Not yet available" disabled></div></div>
          <button class="btn-signin" type="submit" disabled>SMS recovery not configured</button>
        </form>
        <p class="recovery-secondary"><a class="forgot-link" href="forgot-password.php?method=email">Use email recovery</a></p>
      <?php else: ?>
        <h2 class="recovery-title">Forgot your password?</h2>
        <p class="recovery-help">Choose how you would like to recover your account.</p>
        <a class="recovery-method" href="forgot-password.php?method=email"><i class="bi bi-envelope" aria-hidden="true"></i><span>Recover using Email<small>Receive a verification code by email</small></span></a>
        <a class="recovery-method" href="forgot-password.php?method=phone"><i class="bi bi-telephone" aria-hidden="true"></i><span>Recover using Phone Number<small>Not yet configured</small></span></a>
      <?php endif; ?>
      <?php if ($stage !== 'choose' || $method): ?>
        <form method="post" action="actions/recovery.php" class="recovery-secondary"><?php recovery_fields('back'); ?><button class="recovery-link" type="submit">Start over</button></form>
      <?php endif; ?>
      <div class="login-footer"><a href="login.php?clear_recovery=1" class="forgot-link">Back to Login</a></div>
    </div>
  </div>
</main>
<script src="js/recovery.js"></script>
</body>
</html>
