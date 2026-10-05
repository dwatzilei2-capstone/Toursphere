<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/vehicle_compliance.php';
$vehicleId = db()->query("SELECT vehicle_id FROM vehicle_documents WHERE extracted_data::jsonb->>'test_fixture' = 'true' LIMIT 1")->fetchColumn();
if (!$vehicleId) throw new RuntimeException('No existing test document available for verification.');
$original = $_ENV['APP_ENV'] ?? null;
$process = getenv('APP_ENV');
try {
    putenv('APP_ENV');
    foreach (['development', 'testing', ' DEVELOPMENT '] as $mode) {
        $_ENV['APP_ENV'] = $mode;
        $result = vehicle_operational_compliance(db(), $vehicleId);
        if (!$result['operational']) throw new RuntimeException('Configured test mode failed: ' . $mode);
    }
    $_ENV['APP_ENV'] = 'production';
    putenv('APP_ENV=development');
    $result = vehicle_operational_compliance(db(), $vehicleId);
    if ($result['operational'] || !str_contains($result['reason'], 'TEST ONLY')) throw new RuntimeException('Production must reject test documents.');
    unset($_ENV['APP_ENV']);
    putenv('APP_ENV');
    if (vehicle_operational_compliance(db(), $vehicleId)['operational']) throw new RuntimeException('Missing configuration must fail closed.');
    echo "PASS: 5 environment compliance checks; no database records changed.\n";
} finally {
    if ($original === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $original;
    putenv($process === false ? 'APP_ENV' : 'APP_ENV=' . $process);
}
