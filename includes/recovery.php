<?php
require_once __DIR__ . '/recovery-delivery.php';

const RECOVERY_GENERIC = 'If an account is associated with this email address, a verification code will be sent.';

function recovery_mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') return 'your registered email address';
    $local = $parts[0];
    $visible = mb_substr($local, 0, min(2, max(1, mb_strlen($local))));
    return $visible . str_repeat('*', max(3, min(8, mb_strlen($local) - mb_strlen($visible)))) . '@' . $parts[1];
}

function recovery_mask_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    return strlen($digits) > 4 ? str_repeat('*', max(4, strlen($digits) - 4)) . substr($digits, -4) : 'your registered phone number';
}

function recovery_csrf(): string
{
    return $_SESSION['recovery_csrf'] ??= bin2hex(random_bytes(32));
}

function recovery_password_error(string $password, string $confirmation): ?string
{
    if (mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        return 'Use at least 12 characters and no more than 72 bytes for your password.';
    }
    return $password === $confirmation ? null : 'The passwords do not match.';
}

// Atomic shared counters work across browsers, sessions and web servers.
// Call inside a transaction. Never trust a caller-supplied forwarding header.
function recovery_limit(PDO $pdo, string $key, int $maximum, int $window, int $cooldown = 0): bool
{
    $bucket = hash('sha256', $key);
    $pdo->prepare('INSERT INTO password_recovery_limits (bucket) VALUES (?) ON CONFLICT DO NOTHING')->execute([$bucket]);
    $q = $pdo->prepare('SELECT *, EXTRACT(EPOCH FROM (clock_timestamp() - window_started_at)) AS age, EXTRACT(EPOCH FROM (clock_timestamp() - last_requested_at)) AS elapsed FROM password_recovery_limits WHERE bucket = ? FOR UPDATE');
    $q->execute([$bucket]);
    $row = $q->fetch();
    if ((float)$row['age'] >= $window) {
        $pdo->prepare('UPDATE password_recovery_limits SET hits = 0, window_started_at = clock_timestamp() WHERE bucket = ?')->execute([$bucket]);
        $row['hits'] = 0;
    }
    if ((int)$row['hits'] >= $maximum || ((int)$row['hits'] > 0 && (float)$row['elapsed'] < $cooldown)) return false;
    $pdo->prepare('UPDATE password_recovery_limits SET hits = hits + 1, last_requested_at = clock_timestamp() WHERE bucket = ?')->execute([$bucket]);
    return true;
}

function recovery_request(PDO $pdo, string $email, string $ip): array
{
    // Identifying an employee must not enqueue or expire a delivery request.
    if (preg_match('/\AEMP[A-Za-z0-9-]{0,27}\z/i', $email)) {
        return recovery_find_employee($pdo, $email, $ip);
    }
    $id = bin2hex(random_bytes(32));
    $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        $ipAllowed = recovery_limit($pdo, 'request-ip:' . $ip, 20, 3600);
        $emailAllowed = recovery_limit($pdo, 'request-email:' . strtolower($email), 5, 3600, 60);
        if ($ipAllowed && $emailAllowed) {
            $q = $pdo->prepare("SELECT id, emp_id, COALESCE(recovery_email, email) AS recovery_email, recovery_phone FROM users WHERE (lower(COALESCE(recovery_email, email)) = lower(?) OR lower(email) = lower(?) OR upper(emp_id) = upper(?)) AND status = 'Active' ORDER BY id LIMIT 2");
            $q->execute([$email, $email, $email]);
            $users = $q->fetchAll();
            $user = count($users) === 1 ? $users[0] : null;
            if ($user) {
                $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$user['id']]);
                $pdo->prepare('UPDATE password_recovery SET used_at = clock_timestamp(), reset_token_hash = NULL WHERE user_id = ? AND used_at IS NULL')->execute([$user['id']]);
            }
            $pdo->prepare("INSERT INTO password_recovery (id, user_id, channel, destination, otp_hash, expires_at, delivery_status) VALUES (?, ?, 'email', ?, ?, clock_timestamp() + interval '5 minutes', ?)")
                ->execute([$id, $user['id'] ?? null, $user['recovery_email'] ?? $email, $hash, $user ? 'pending' : 'skipped']);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    $masked = isset($user) && $user ? recovery_mask_email($user['recovery_email'] ?? $email) : recovery_mask_email($email);
    $isEmployee = (bool)preg_match('/\A[A-Za-z0-9-]{3,30}\z/', $email) && str_contains(strtoupper($email), 'EMP');
    return ['id' => $id, 'email' => $email, 'user_id' => $user['id'] ?? null, 'masked_email' => $masked, 'masked_phone' => isset($user['recovery_phone']) && $user['recovery_phone'] ? recovery_mask_phone($user['recovery_phone']) : null, 'resend_at' => time() + 60, 'stage' => $isEmployee ? 'choose_contact' : 'verify'];
}

function recovery_find_employee(PDO $pdo, string $employeeId, string $ip): array
{
    $pdo->beginTransaction();
    try {
        if (!recovery_limit($pdo, 'lookup-ip:' . $ip, 20, 3600)) {
            $pdo->commit();
            throw new RuntimeException('Recovery lookup temporarily unavailable.');
        }
        $q = $pdo->prepare("SELECT id, COALESCE(recovery_email, email) AS recovery_email, recovery_phone FROM users WHERE upper(emp_id) = upper(?) AND status = 'Active'");
        $q->execute([$employeeId]);
        $user = $q->fetch();
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    return ['stage' => 'choose_contact', 'user_id' => $user['id'] ?? null,
        'email' => $employeeId, 'lookup_expires_at' => time() + 1800,
        'masked_email' => $user ? recovery_mask_email($user['recovery_email']) : 'Registered recovery email',
        'masked_phone' => !empty($user['recovery_phone']) ? recovery_mask_phone($user['recovery_phone']) : null];
}

function recovery_delivery_status(PDO $pdo, string $id): string
{
    $q = $pdo->prepare('SELECT delivery_status FROM password_recovery WHERE id = ?');
    $q->execute([$id]);
    return $q->fetchColumn() ?: 'unavailable';
}

function recovery_verify(PDO $pdo, string $id, string $code, string $ip): ?string
{
    $dummyHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        if (!recovery_limit($pdo, 'verify-ip:' . $ip, 30, 900)) { $pdo->commit(); return null; }
        $q = $pdo->prepare("SELECT *, (expires_at > clock_timestamp()) AS alive FROM password_recovery WHERE id = ? AND channel = 'email' AND purpose = 'password_reset' FOR UPDATE");
        $q->execute([$id]);
        $row = $q->fetch();
        $matches = password_verify($code, $row && $row['delivery_status'] === 'sent' && $row['otp_hash'] !== '' ? $row['otp_hash'] : $dummyHash);
        if (!$row || !$row['alive'] || $row['used_at'] || $row['verified'] || $row['attempts'] >= 5 || $row['delivery_status'] !== 'sent') {
            $pdo->commit(); return null;
        }
        $pdo->prepare('UPDATE password_recovery SET attempts = attempts + 1 WHERE id = ?')->execute([$id]);
        if (!preg_match('/\A[0-9]{6}\z/D', $code) || !$matches || !$row['user_id']) {
            $pdo->commit(); return null;
        }
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE password_recovery SET verified = TRUE, otp_hash = '', reset_token_hash = ?, reset_expires_at = clock_timestamp() + interval '10 minutes' WHERE id = ?")
            ->execute([hash('sha256', $token), $id]);
        $pdo->commit();
        return $token;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

function recovery_can_reset(PDO $pdo, string $id, string $token): bool
{
    if ($token === '') return false;
    $q = $pdo->prepare("SELECT 1 FROM password_recovery WHERE id = ? AND channel = 'email' AND purpose = 'password_reset' AND verified = TRUE AND used_at IS NULL AND reset_token_hash = ? AND reset_expires_at > clock_timestamp()");
    $q->execute([$id, hash('sha256', $token)]);
    return (bool)$q->fetchColumn();
}

function recovery_reset(PDO $pdo, string $id, string $token, string $password): bool
{
    if ($token === '' || recovery_password_error($password, $password)) return false;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT user_id FROM password_recovery WHERE id = ?');
        $q->execute([$id]);
        $userId = $q->fetchColumn();
        if (!$userId) { $pdo->commit(); return false; }
        // Same lock order as requests and delivery: account, then recovery record.
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
        $pdo->prepare('SELECT id FROM password_recovery WHERE id = ? FOR UPDATE')->execute([$id]);
        if (!recovery_can_reset($pdo, $id, $token)) { $pdo->commit(); return false; }
        $q = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND status = 'Active' AND COALESCE(recovery_email, email) = (SELECT destination FROM password_recovery WHERE id = ?)");
        $q->execute([$hash, $userId, $id]);
        if ($q->rowCount() !== 1) { $pdo->rollBack(); return false; }
        $pdo->prepare("UPDATE password_recovery SET used_at = clock_timestamp(), otp_hash = '', reset_token_hash = NULL WHERE user_id = ? AND used_at IS NULL")->execute([$userId]);
        $pdo->commit();
        return true;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

// CLI worker: codes exist only in memory while composing SMTP mail.
function recovery_deliver_pending(PDO $pdo, ?string $requestedId = null): bool
{
    if ($requestedId !== null) {
        $q = $pdo->prepare("SELECT id, user_id FROM password_recovery WHERE id = ? AND delivery_status = 'pending' AND used_at IS NULL AND expires_at > clock_timestamp()");
        $q->execute([$requestedId]);
    } else {
        $q = $pdo->query("SELECT id, user_id FROM password_recovery WHERE delivery_status = 'pending' AND used_at IS NULL AND expires_at > clock_timestamp() ORDER BY created_at LIMIT 1");
    }
    $job = $q->fetch();
    if (!$job) return false;
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT name, email, recovery_email, status FROM users WHERE id = ? FOR UPDATE');
        $q->execute([$job['user_id']]);
        $user = $q->fetch();
        $q = $pdo->prepare("SELECT * FROM password_recovery WHERE id = ? AND delivery_status = 'pending' AND used_at IS NULL AND expires_at > clock_timestamp() FOR UPDATE");
        $q->execute([$job['id']]);
        $row = $q->fetch();
        if (!$row) { $pdo->commit(); return true; }
        if (!$user || $user['status'] !== 'Active' || ($user['recovery_email'] ?: $user['email']) !== $row['destination']) {
            $pdo->prepare("UPDATE password_recovery SET delivery_status = 'skipped', used_at = clock_timestamp() WHERE id = ?")->execute([$job['id']]);
        } else {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = password_hash($code, PASSWORD_DEFAULT);
            recovery_send_email($row['destination'], $user['name'], $code);
            $pdo->prepare("UPDATE password_recovery SET otp_hash = ?, expires_at = clock_timestamp() + interval '5 minutes', delivery_status = 'sent' WHERE id = ?")->execute([$hash, $job['id']]);
            unset($code);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare("UPDATE password_recovery SET delivery_status = 'failed', used_at = clock_timestamp() WHERE id = ? AND delivery_status = 'pending'")->execute([$job['id']]);
        error_log('Password recovery delivery failed; check SMTP configuration and connectivity.');
    }
    return true;
}
