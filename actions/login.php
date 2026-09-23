<?php
 



require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect_to(BASE_URL . '/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/login.php');
}

$email    = trim($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    redirect_to(BASE_URL . '/login.php?error=' . rawurlencode('Please enter both your email and password.'));
}

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT u.*, r.code AS role_code, r.name AS role_name
           FROM users u
           JOIN roles r ON r.id = u.role_id
          WHERE (lower(u.email) = lower(?) OR upper(u.emp_id) = upper(?)) AND u.status = 'Active'
          LIMIT 1"
    );
    $stmt->execute([$email, $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        redirect_to(BASE_URL . '/login.php?error=' . rawurlencode('Invalid email or password. Please try again.'));
    }

     
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_name'] = $user['name'];

    redirect_to(BASE_URL . '/index.php');
} catch (Exception $ex) {
    redirect_to(BASE_URL . '/login.php?error=' . rawurlencode('Authentication service unavailable. Please try again.'));
}

