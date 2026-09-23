<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
// Worker does not need a browser session.
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/recovery.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$once = in_array('--once', $argv, true);
do {
    try {
        while (recovery_deliver_pending(db())) {}
        db()->exec("DELETE FROM password_recovery WHERE expires_at < clock_timestamp() - interval '1 day' AND (reset_expires_at IS NULL OR reset_expires_at < clock_timestamp() - interval '1 day')");
        db()->exec("DELETE FROM password_recovery_limits WHERE last_requested_at < clock_timestamp() - interval '1 day'");
    } catch (Throwable $ex) {
        error_log('Recovery worker unavailable; check database and migrations.');
        if ($once) exit(1);
    }
    if (!$once) sleep(2);
} while (!$once);
