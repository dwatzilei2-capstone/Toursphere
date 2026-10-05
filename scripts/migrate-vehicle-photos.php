<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_photo.php';
$pdo = db();
try {
    $pdo->beginTransaction();
    $pdo->exec(file_get_contents(ROOT_PATH . '/database/migrations/2026_10_05_vehicle_photos.sql'));
    $catalog = json_decode(file_get_contents(ROOT_PATH . '/assets/images/vehicle-samples/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $vehicles = $pdo->query('SELECT * FROM vehicles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($vehicles as $vehicle) $count += (int)vehicle_photo_seed_sample($pdo, $vehicle, $catalog);
    $pdo->commit();
    echo "Shared photo storage ready. Matching samples associated: $count/" . count($vehicles) . ". Actual photos preserved.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Vehicle photo migration: ' . $error->getMessage());
    exit(1);
}
