<?php
 



require_once __DIR__ . '/includes/bootstrap.php';

// Returning to Login cancels any unfinished recovery attempt so a later
// Forgot Password click always starts from the recovery-method screen.
if (isset($_GET['clear_recovery'])) {
    unset($_SESSION['recovery'], $_SESSION['recovery_message'], $_SESSION['recovery_csrf']);
}

 
if (is_logged_in()) {
    redirect_to(BASE_URL . '/index.php');
}

$login_error = $_GET['error'] ?? null;
$login_success = $_SESSION['login_success'] ?? null;
unset($_SESSION['login_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — <?= e(company_name()) ?></title>
  <meta name="description" content="Secure Enterprise Login Portal for Toursphere Fleet & Transportation Management System.">
  <link rel="icon" type="image/png" href="assets/images/toursphere-logo.png">

   
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

   
  <link href="css/vendor/bootstrap.min.css" rel="stylesheet">
   
  <link href="css/vendor/bootstrap-icons.min.css" rel="stylesheet">

  <link href="css/auth.css" rel="stylesheet">
</head>
<body>

  <div class="login-wrapper">
    <div class="login-card">

       
      <div class="login-header">
        <div class="logo-container">
          <img src="<?= e(company_logo()) ?>" alt="<?= e(company_name()) ?> logo">
        </div>
        <h1 class="brand-name"><?= e(company_name()) ?></h1>
        <p class="brand-sub">Fleet & Transportation Management</p>
        <div class="system-pill">
          <i class="bi bi-globe"></i>
          <span><?= e(company_name()) ?> Travel &amp; Tours System</span>
        </div>
      </div>

       
      <div class="login-body">
        <?php if ($login_success): ?>
        <div class="alert alert-success" role="status"><?= e($login_success) ?></div>
        <?php endif; ?>
        <?php if ($login_error): ?>
        <div class="login-alert">
          <i class="bi bi-exclamation-triangle-fill mt-1 text-danger"></i>
          <div><?= e($login_error) ?></div>
        </div>
        <?php endif; ?>

        <form method="post" action="actions/login.php" autocomplete="on">
           
          <div class="form-group">
            <label for="login-email" class="form-label-custom">Email Address</label>
            <div class="input-wrapper">
              <span class="input-icon"><i class="bi bi-envelope"></i></span>
              <input type="text" id="login-email" name="email" class="input-field" placeholder="admin@travelandtours.com" required autofocus>
            </div>
          </div>

           
          <div class="form-group">
            <label for="login-password" class="form-label-custom">Password</label>
            <div class="input-wrapper">
              <span class="input-icon"><i class="bi bi-lock"></i></span>
              <input type="password" id="login-password" name="password" class="input-field" placeholder="••••••••••••" required>
              <button type="button" class="btn-toggle-eye" onclick="togglePasswordVisibility()" aria-label="Toggle password visibility" title="Toggle password visibility">
                <i class="bi bi-eye" id="toggle-eye-icon"></i>
              </button>
            </div>
          </div>

           
          <div class="options-row">
            <label class="remember-label" for="remember-me">
              <input type="checkbox" id="remember-me" name="remember" class="remember-checkbox" checked>
              <span>Remember Me</span>
            </label>
            <a href="forgot-password.php" class="forgot-link">Forgot Password?</a>
          </div>

           
          <button type="submit" class="btn-signin" id="btn-login-submit">
            <i class="bi bi-box-arrow-in-right"></i>
            <span>Sign In</span>
          </button>
        </form>

         
        <div class="login-footer">
          <i class="bi bi-shield-fill-check"></i>
          <span>Secured with 256-bit encryption</span>
        </div>
      </div>

    </div>
  </div>

  <script>
    function togglePasswordVisibility() {
      const input = document.getElementById("login-password");
      const icon = document.getElementById("toggle-eye-icon");
      if (input.type === "password") {
        input.type = "text";
        icon.classList.remove("bi-eye");
        icon.classList.add("bi-eye-slash");
      } else {
        input.type = "password";
        icon.classList.remove("bi-eye-slash");
        icon.classList.add("bi-eye");
      }
    }
  </script>
</body>
</html>
