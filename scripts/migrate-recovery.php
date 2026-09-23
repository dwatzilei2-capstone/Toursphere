<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';
db()->exec(file_get_contents(dirname(__DIR__) . '/database/migrations/2026_09_13_password_recovery.sql'));
echo "Password recovery migration applied.\n";
