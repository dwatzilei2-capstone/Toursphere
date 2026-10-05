<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
try {
    db()->exec(file_get_contents(ROOT_PATH . '/database/migrations/2026_10_05_audit_log.sql'));
    echo "Audit logging migration applied.\n";
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    fwrite(STDERR, "Audit migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
