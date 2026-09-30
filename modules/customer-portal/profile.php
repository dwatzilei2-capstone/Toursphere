<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
if (!has_role('customer')) { http_response_code(403); exit('Forbidden'); }

$_SESSION['customer_profile_csrf'] ??= bin2hex(random_bytes(32));
$errors = [];
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';
$editing = $_SERVER['REQUEST_METHOD'] === 'POST' || ($_GET['edit'] ?? '') === '1';
$values = [
    'name' => $current_user['name'],
    'email' => $current_user['email'],
    'phone' => $current_user['phone'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = [
        'name' => trim((string)($_POST['name'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'phone' => trim((string)($_POST['phone'] ?? '')),
    ];

    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['customer_profile_csrf'], $_POST['csrf'])) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    }
    if ($values['name'] === '' || mb_strlen($values['name']) > 120) {
        $errors['name'] = 'Enter your name (up to 120 characters).';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($values['email']) > 150) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($values['phone'] === '' || mb_strlen($values['phone']) > 40 || !preg_match('/^[0-9+()\s.-]{7,40}$/', $values['phone'])) {
        $errors['phone'] = 'Enter a valid contact phone number.';
    }

    if (!$errors) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $duplicate = $pdo->prepare('SELECT 1 FROM users WHERE lower(email)=lower(?) AND id<>? LIMIT 1');
            $duplicate->execute([$values['email'], $current_user['id']]);
            if ($duplicate->fetchColumn()) {
                $errors['email'] = 'This email is already used by another account.';
            } else {
                $sql = 'UPDATE users SET name=?, email=?, phone=?';
                $params = [$values['name'], $values['email'], $values['phone']];
                $sql .= ' WHERE id=? AND role_id=(SELECT id FROM roles WHERE code=?)';
                $params[] = $current_user['id'];
                $params[] = 'customer';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('Your profile could not be updated.');
                $pdo->commit();
                $_SESSION['user_name'] = $values['name'];
                $_SESSION['customer_profile_csrf'] = bin2hex(random_bytes(32));
                redirect_to(BASE_URL . '/modules/customer-portal/profile.php?saved=1');
            }
            $pdo->rollBack();
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23505') {
                $errors['email'] = 'This email is already used by another account.';
            } else {
                $errors['form'] = 'Could not save your profile. Please try again.';
            }
        }
    }
}

$active_page = 'customer-profile';
$page_title = 'My Profile';
require ROOT_PATH . '/includes/header.php';
?>
<div class="mb-4"><h1 class="mb-1">My Profile</h1><p class="text-muted-custom mb-0">Keep your contact details current for reservations and trip updates.</p></div>
<div class="tc-card p-4" style="max-width:720px">
  <?php if ($saved): ?><div class="alert alert-success" role="status">Your profile has been updated.</div><?php endif; ?>
  <?php if (isset($errors['form'])): ?><div class="alert alert-danger" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
  <div id="customer-profile-view" <?= $editing ? 'hidden' : '' ?>>
    <div class="row g-3 mb-4">
      <div class="col-md-6"><div class="small text-muted-custom">Full Name</div><div class="fw-semibold"><?= e($current_user['name']) ?></div></div>
      <div class="col-md-6"><div class="small text-muted-custom">Contact Phone</div><div class="fw-semibold"><?= e($current_user['phone'] ?: 'Not provided') ?></div></div>
      <div class="col-12"><div class="small text-muted-custom">Email Address</div><div class="fw-semibold"><?= e($current_user['email']) ?></div></div>
    </div>
    <button type="button" class="tc-btn tc-btn-primary" id="customer-edit-profile-button" aria-controls="customer-profile-form" aria-expanded="false"><i class="bi bi-pencil-square me-1"></i>Edit Profile</button>
  </div>
  <form id="customer-profile-form" method="post" action="<?= BASE_URL ?>/modules/customer-portal/profile.php" <?= $editing ? '' : 'hidden' ?>>
    <input type="hidden" name="csrf" value="<?= e($_SESSION['customer_profile_csrf']) ?>">
    <div class="row g-3">
      <div class="col-md-6"><label class="tc-form-label" for="profile-name">Full Name *</label><input class="tc-form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" id="profile-name" name="name" maxlength="120" autocomplete="name" required value="<?= e($values['name']) ?>"><?php if (isset($errors['name'])): ?><div class="invalid-feedback d-block"><?= e($errors['name']) ?></div><?php endif; ?></div>
      <div class="col-md-6"><label class="tc-form-label" for="profile-phone">Contact Phone *</label><input class="tc-form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>" id="profile-phone" name="phone" type="tel" maxlength="40" autocomplete="tel" required value="<?= e($values['phone']) ?>"><?php if (isset($errors['phone'])): ?><div class="invalid-feedback d-block"><?= e($errors['phone']) ?></div><?php endif; ?></div>
      <div class="col-12"><label class="tc-form-label" for="profile-email">Email Address *</label><input class="tc-form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" id="profile-email" name="email" type="email" maxlength="150" autocomplete="email" required value="<?= e($values['email']) ?>"><?php if (isset($errors['email'])): ?><div class="invalid-feedback d-block"><?= e($errors['email']) ?></div><?php endif; ?><div class="small text-muted-custom mt-1">This email is used for sign-in and password recovery.</div></div>
    </div>
    <div class="d-flex justify-content-end gap-2 mt-4"><a class="tc-btn tc-btn-secondary" href="<?= BASE_URL ?>/modules/customer-portal/profile.php">Cancel</a><button type="submit" class="tc-btn tc-btn-primary">Save Profile</button></div>
  </form>
</div>
<script>
  document.getElementById('customer-edit-profile-button')?.addEventListener('click', function () {
    document.getElementById('customer-profile-view').hidden = true;
    document.getElementById('customer-profile-form').hidden = false;
    this.setAttribute('aria-expanded', 'true');
    document.getElementById('profile-name').focus();
  });
</script>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
