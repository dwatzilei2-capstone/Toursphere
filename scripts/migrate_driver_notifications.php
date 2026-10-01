<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
db()->exec(file_get_contents(ROOT_PATH . '/database/migrations/2026_10_01_driver_assignment_notifications.sql'));
echo "Driver assignment notification wording updated. Existing notifications retained.\n";
