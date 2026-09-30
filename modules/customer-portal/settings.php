<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
if (!has_role('customer')) { http_response_code(403); exit('Forbidden'); }

$_SESSION['customer_settings_csrf'] ??= bin2hex(random_bytes(32));
$errors = [];
$saved = ($_GET['saved'] ?? '') === 'theme' ? 'Appearance saved.' : (($_GET['saved'] ?? '') === 'password' ? 'Password changed successfully.' : '');
$selectedTheme = account_theme();
$editingPassword = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['customer_settings_csrf'], $_POST['csrf'])) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    } elseif ($action === 'theme') {
        $selectedTheme = (string)($_POST['theme'] ?? '');
        if (!in_array($selectedTheme, ['light', 'dark'], true)) {
            $errors['theme'] = 'Choose Light or Dark mode.';
        } else {
            try {
                save_fleet_setting('user.' . $current_user['id'] . '.theme', $selectedTheme);
                $_SESSION['customer_settings_csrf'] = bin2hex(random_bytes(32));
                redirect_to(BASE_URL . '/modules/customer-portal/settings.php?saved=theme');
            } catch (Throwable $e) {
                $errors['theme'] = 'Could not save the appearance setting. Please try again.';
            }
        }
    } elseif ($action === 'password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if (!password_verify($currentPassword, (string)$current_user['password_hash'])) {
            $errors['current_password'] = 'The current password is incorrect.';
        }
        if (strlen($newPassword) < 12) {
            $errors['new_password'] = 'Use at least 12 characters for the new password.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors['confirm_password'] = 'The new passwords do not match.';
        }
        if (!$errors) {
            try {
                $stmt = db()->prepare("UPDATE users SET password_hash=? WHERE id=? AND status='Active' AND role_id=(SELECT id FROM roles WHERE code='customer')");
                $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $current_user['id']]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('Account not found.');
                session_regenerate_id(true);
                $_SESSION['customer_settings_csrf'] = bin2hex(random_bytes(32));
                redirect_to(BASE_URL . '/modules/customer-portal/settings.php?saved=password');
            } catch (Throwable $e) {
                $errors['password'] = 'Could not change the password. Please try again.';
            }
        }
    } else {
        $errors['form'] = 'Unknown settings action.';
    }
}

$active_page = 'customer-settings';
$page_title = 'My Settings';
require ROOT_PATH . '/includes/header.php';
?>
<div class="mb-4"><h1 class="mb-1">My Settings</h1><p class="text-muted-custom mb-0">Choose your display mode and manage your account password.</p></div>
<?php if ($saved): ?><div class="alert alert-success" role="status"><?= e($saved) ?></div><?php endif; ?>
<?php if (isset($errors['form'])): ?><div class="alert alert-danger" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
<div class="row g-4" style="max-width:1050px">
  <div class="col-lg-6">
    <div class="tc-card p-4 h-100">
      <h2 class="h5 mb-1"><i class="bi bi-circle-half me-2"></i>Appearance</h2>
      <p class="small text-muted-custom">This choice applies only to your account.</p>
      <form method="post" action="<?= BASE_URL ?>/modules/customer-portal/settings.php">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['customer_settings_csrf']) ?>">
        <input type="hidden" name="action" value="theme">
        <div class="d-flex flex-wrap gap-3 my-3">
          <label class="border rounded p-3 flex-fill" style="min-width:150px"><input type="radio" name="theme" value="light" <?= $selectedTheme === 'light' ? 'checked' : '' ?> required> <i class="bi bi-sun ms-2"></i> Light Mode</label>
          <label class="border rounded p-3 flex-fill" style="min-width:150px"><input type="radio" name="theme" value="dark" <?= $selectedTheme === 'dark' ? 'checked' : '' ?> required> <i class="bi bi-moon-stars ms-2"></i> Dark Mode</label>
        </div>
        <?php if (isset($errors['theme'])): ?><div class="text-danger small mb-2"><?= e($errors['theme']) ?></div><?php endif; ?>
        <button type="submit" class="tc-btn tc-btn-primary">Save Appearance</button>
      </form>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="tc-card p-4 h-100">
      <h2 class="h5 mb-1"><i class="bi bi-shield-lock me-2"></i>Change Password</h2>
      <p class="small text-muted-custom">Update the password used to sign in to your account.</p>
      <?php if (isset($errors['password'])): ?><div class="alert alert-danger" role="alert"><?= e($errors['password']) ?></div><?php endif; ?>
      <button type="button" class="tc-btn tc-btn-secondary" id="customer-change-password-button" aria-controls="customer-password-form" aria-expanded="<?= $editingPassword ? 'true' : 'false' ?>" <?= $editingPassword ? 'hidden' : '' ?>>Change Password</button>
      <form id="customer-password-form" method="post" action="<?= BASE_URL ?>/modules/customer-portal/settings.php" autocomplete="off" <?= $editingPassword ? '' : 'hidden' ?>>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['customer_settings_csrf']) ?>">
        <input type="hidden" name="action" value="password">
        <div class="mb-3"><label class="tc-form-label" for="customer-current-password">Current Password *</label><input class="tc-form-control <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>" id="customer-current-password" name="current_password" type="password" autocomplete="off" required><?php if (isset($errors['current_password'])): ?><div class="invalid-feedback d-block"><?= e($errors['current_password']) ?></div><?php endif; ?></div>
        <div class="mb-3"><label class="tc-form-label" for="customer-new-password">New Password *</label><input class="tc-form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>" id="customer-new-password" name="new_password" type="password" minlength="12" autocomplete="new-password" required><?php if (isset($errors['new_password'])): ?><div class="invalid-feedback d-block"><?= e($errors['new_password']) ?></div><?php endif; ?></div>
        <div class="mb-3"><label class="tc-form-label" for="customer-confirm-password">Confirm New Password *</label><input class="tc-form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" id="customer-confirm-password" name="confirm_password" type="password" minlength="12" autocomplete="new-password" required><?php if (isset($errors['confirm_password'])): ?><div class="invalid-feedback d-block"><?= e($errors['confirm_password']) ?></div><?php endif; ?></div>
        <div class="d-flex flex-wrap gap-2"><a class="tc-btn tc-btn-secondary" href="<?= BASE_URL ?>/modules/customer-portal/settings.php">Cancel</a><button type="submit" class="tc-btn tc-btn-primary">Save New Password</button></div>
      </form>
    </div>
  </div>
</div>
<script>
document.getElementById('customer-change-password-button')?.addEventListener('click', function () {
  this.hidden = true;
  this.setAttribute('aria-expanded', 'true');
  document.getElementById('customer-password-form').hidden = false;
  document.getElementById('customer-current-password').focus();
});
</script>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
