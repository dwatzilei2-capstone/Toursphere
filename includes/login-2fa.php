<?php

require_once __DIR__ . '/recovery-delivery.php';

const LOGIN_2FA_EXPIRY_SECONDS = 300;
const LOGIN_2FA_RESEND_SECONDS = 60;
const LOGIN_2FA_MAX_ATTEMPTS = 5;

function login_2fa_required(string $roleCode): bool
{
    return in_array($roleCode, ['fleet_admin', 'driver'], true);
}

function login_2fa_csrf(): string
{
    return $_SESSION['login_2fa_csrf'] ??= bin2hex(random_bytes(32));
}

function login_2fa_mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') return 'your registered email address';
    $local = $parts[0];
    $visibleLength = min(2, max(1, mb_strlen($local)));
    $visible = mb_substr($local, 0, $visibleLength);
    return $visible . str_repeat('*', max(3, min(8, mb_strlen($local) - $visibleLength))) . '@' . $parts[1];
}

function login_2fa_limit(PDO $pdo, string $key, int $maximum, int $windowSeconds, int $cooldownSeconds = 0): bool
{
    $bucket = hash('sha256', $key);
    $pdo->prepare('INSERT INTO login_2fa_limits (bucket) VALUES (?) ON CONFLICT DO NOTHING')->execute([$bucket]);
    $q = $pdo->prepare('SELECT *, EXTRACT(EPOCH FROM (clock_timestamp() - window_started_at)) AS age, EXTRACT(EPOCH FROM (clock_timestamp() - last_requested_at)) AS elapsed FROM login_2fa_limits WHERE bucket = ? FOR UPDATE');
    $q->execute([$bucket]);
    $row = $q->fetch();
    if ((float)$row['age'] >= $windowSeconds) {
        $pdo->prepare('UPDATE login_2fa_limits SET hits = 0, window_started_at = clock_timestamp() WHERE bucket = ?')->execute([$bucket]);
        $row['hits'] = 0;
    }
    if ((int)$row['hits'] >= $maximum || ((int)$row['hits'] > 0 && (float)$row['elapsed'] < $cooldownSeconds)) return false;
    $pdo->prepare('UPDATE login_2fa_limits SET hits = hits + 1, last_requested_at = clock_timestamp() WHERE bucket = ?')->execute([$bucket]);
    return true;
}

function login_2fa_issue(PDO $pdo, array $user, string $ip, ?callable $sender = null): array
{
    $userId = (int)($user['id'] ?? 0);
    $roleCode = (string)($user['role_code'] ?? '');
    $email = trim((string)($user['two_factor_email'] ?? $user['recovery_email'] ?? $user['email'] ?? ''));
    if ($userId < 1 || !login_2fa_required($roleCode)) throw new RuntimeException('Two-factor verification is not available for this account.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('This account does not have a valid registered email address. Contact an administrator before signing in.');

    $challengeId = bin2hex(random_bytes(32));
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($code, PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    try {
        $ipAllowed = login_2fa_limit($pdo, 'issue-ip:' . $ip, 20, 3600);
        $userAllowed = login_2fa_limit($pdo, 'issue-user:' . $userId, 6, 3600, LOGIN_2FA_RESEND_SECONDS);
        if (!$ipAllowed || !$userAllowed) {
            $pdo->commit();
            throw new RuntimeException('Please wait before requesting another verification code.');
        }
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
        $pdo->prepare("UPDATE login_2fa_challenges SET used_at = clock_timestamp(), otp_hash = '' WHERE user_id = ? AND used_at IS NULL")->execute([$userId]);
        $pdo->prepare("INSERT INTO login_2fa_challenges (id, user_id, purpose, destination, otp_hash, delivery_status, expires_at) VALUES (?, ?, 'login_2fa', ?, ?, 'pending', clock_timestamp() + interval '5 minutes')")
            ->execute([$challengeId, $userId, $email, $hash]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        unset($code);
        throw $ex;
    }

    try {
        ($sender ?? 'login_2fa_send_email')($email, (string)$user['name'], $code);
        unset($code);
        $pdo->prepare("UPDATE login_2fa_challenges SET delivery_status = 'sent', sent_at = clock_timestamp(), expires_at = clock_timestamp() + interval '5 minutes' WHERE id = ? AND delivery_status = 'pending' AND used_at IS NULL")
            ->execute([$challengeId]);
    } catch (Throwable $ex) {
        unset($code);
        $pdo->prepare("UPDATE login_2fa_challenges SET delivery_status = 'failed', used_at = clock_timestamp(), otp_hash = '' WHERE id = ? AND delivery_status = 'pending'")->execute([$challengeId]);
        error_log('Login verification delivery failed; check SMTP configuration and connectivity.');
        throw new RuntimeException('We could not send the verification code right now. Please try again later.');
    }

    return [
        'challenge_id' => $challengeId,
        'user_id' => $userId,
        'role_code' => $roleCode,
        'masked_email' => login_2fa_mask_email($email),
        'expires_at' => time() + LOGIN_2FA_EXPIRY_SECONDS,
        'resend_at' => time() + LOGIN_2FA_RESEND_SECONDS,
    ];
}

function login_2fa_verify(PDO $pdo, array $state, string $code, string $ip): ?int
{
    $challengeId = (string)($state['challenge_id'] ?? '');
    $userId = (int)($state['user_id'] ?? 0);
    $dummyHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        if (!login_2fa_limit($pdo, 'verify-ip:' . $ip, 30, 900)) {
            $pdo->commit();
            return null;
        }
        $q = $pdo->prepare("SELECT c.*, r.code AS role_code, u.status, (c.expires_at > clock_timestamp()) AS alive FROM login_2fa_challenges c JOIN users u ON u.id = c.user_id JOIN roles r ON r.id = u.role_id WHERE c.id = ? AND c.user_id = ? AND c.purpose = 'login_2fa' FOR UPDATE");
        $q->execute([$challengeId, $userId]);
        $row = $q->fetch();
        $candidateHash = $row && $row['delivery_status'] === 'sent' && $row['otp_hash'] !== '' ? $row['otp_hash'] : $dummyHash;
        $matches = password_verify($code, $candidateHash);
        if (!$row || !$row['alive'] || $row['used_at'] || $row['attempts'] >= LOGIN_2FA_MAX_ATTEMPTS || $row['delivery_status'] !== 'sent' || $row['status'] !== 'Active' || !login_2fa_required($row['role_code'])) {
            $pdo->commit();
            return null;
        }
        $pdo->prepare('UPDATE login_2fa_challenges SET attempts = attempts + 1 WHERE id = ?')->execute([$challengeId]);
        if (!preg_match('/\A[0-9]{6}\z/D', $code) || !$matches) {
            $pdo->commit();
            return null;
        }
        $pdo->prepare("UPDATE login_2fa_challenges SET used_at = clock_timestamp(), otp_hash = '', verified_at = clock_timestamp() WHERE id = ?")->execute([$challengeId]);
        $pdo->commit();
        return $userId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

function login_2fa_cancel(PDO $pdo, array $state): void
{
    $challengeId = (string)($state['challenge_id'] ?? '');
    $userId = (int)($state['user_id'] ?? 0);
    if ($challengeId !== '' && $userId > 0) {
        $pdo->prepare("UPDATE login_2fa_challenges SET used_at = COALESCE(used_at, clock_timestamp()), otp_hash = '' WHERE id = ? AND user_id = ? AND used_at IS NULL")
            ->execute([$challengeId, $userId]);
    }
}

function login_2fa_state_is_active(PDO $pdo, array $state): bool
{
    return login_2fa_status($pdo, $state)['active'];
}

function login_2fa_status(PDO $pdo, array $state): array
{
    $q = $pdo->prepare("SELECT delivery_status, used_at, verified_at, attempts, CEIL(EXTRACT(EPOCH FROM (expires_at - clock_timestamp()))) AS remaining FROM login_2fa_challenges WHERE id = ? AND user_id = ? AND purpose = 'login_2fa'");
    $q->execute([(string)($state['challenge_id'] ?? ''), (int)($state['user_id'] ?? 0)]);
    $row = $q->fetch();
    $remaining = max(0, (int)($row['remaining'] ?? 0));
    $message = '';
    if (!$row) $message = 'This verification code is no longer available. Request a new code.';
    elseif ($row['verified_at']) $message = 'This code has already been used. Return to login to continue.';
    elseif ($row['delivery_status'] !== 'sent') $message = 'This code could not be delivered. Request a new code.';
    elseif ($row['used_at']) $message = 'This code was replaced by a new login or resend, or cancelled. Use the latest code in its verification page, or request a new code here.';
    elseif ($remaining <= 0) $message = 'The 5-minute validity has ended. Request a new code.';
    elseif ((int)$row['attempts'] >= LOGIN_2FA_MAX_ATTEMPTS) $message = 'The maximum number of incorrect attempts was reached. Request a new code.';
    return ['active' => $message === '', 'remaining' => $remaining, 'message' => $message];
}
