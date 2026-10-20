<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
try {
    db()->exec(file_get_contents(ROOT_PATH . '/database/migrations/2026_10_12_logistics_cargo_vehicle_registration.sql'));
    echo "Cargo vehicle registration migration applied. Passenger vehicles, assignments, and history preserved.\n";
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
