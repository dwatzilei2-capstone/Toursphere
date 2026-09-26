<?php

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('vehicles.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/dashboard.php');
}

$return = $_POST['return'] ?? (BASE_URL . '/modules/fleet-vehicle-management/vehicle-directory.php');
$storedFile = null;

try {
    if (($_POST['action'] ?? '') !== 'create') {
        throw new InvalidArgumentException('Unknown vehicle action.');
    }
    if (!is_string($_POST['csrf_token'] ?? null)
        || !hash_equals($_SESSION['vehicle_csrf_token'] ?? '', $_POST['csrf_token'])) {
        throw new RuntimeException('Your form session expired. Please reopen the Add Vehicle form and try again.');
    }

    $upload = $_FILES['registration_document'] ?? null;
    if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('A vehicle registration/supporting document is required.');
    }
    if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The vehicle document could not be uploaded. Please try again.');
    }
    $maxFileSize = 8 * 1024 * 1024;
    $fileSize = (int)($upload['size'] ?? 0);
    if ($fileSize < 1 || $fileSize > $maxFileSize) {
        throw new InvalidArgumentException('The vehicle document must be 8 MB or smaller.');
    }
    if (!is_uploaded_file($upload['tmp_name'])) {
        throw new InvalidArgumentException('The uploaded vehicle document is invalid.');
    }

    $allowedMimes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    if (!isset($allowedMimes[$mime])) {
        throw new InvalidArgumentException('Only PDF, JPG, JPEG, and PNG vehicle documents are allowed.');
    }

    $plate = strtoupper(trim((string)($_POST['plate_number'] ?? '')));
    $plate = preg_replace('/[^A-Z0-9]+/', '-', $plate);
    $plate = trim((string)$plate, '-');
    if (!preg_match('/\A[A-Z0-9]{2,5}(?:-[A-Z0-9]{2,5}){1,2}\z/', $plate)) {
        throw new InvalidArgumentException('Enter a valid plate number exactly as shown in the uploaded document.');
    }

    $typeId = filter_var($_POST['vehicle_type_id'] ?? null, FILTER_VALIDATE_INT);
    $brandId = filter_var($_POST['brand_id'] ?? null, FILTER_VALIDATE_INT);
    $modelId = filter_var($_POST['model_id'] ?? null, FILTER_VALIDATE_INT);
    $variantId = filter_var($_POST['variant_id'] ?? null, FILTER_VALIDATE_INT);
    $year = filter_var($_POST['year'] ?? null, FILTER_VALIDATE_INT);
    $capacity = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT);
    $fuelCapacity = filter_var($_POST['fuel_capacity'] ?? null, FILTER_VALIDATE_INT);
    $fuelType = trim((string)($_POST['fuel_type'] ?? ''));
    $currentYear = (int)date('Y');
    if (!$typeId || !$brandId || !$modelId || !$variantId) {
        throw new InvalidArgumentException('Select a supported vehicle type, brand, model, and variant.');
    }
    if (!$year || $year < 1980 || $year > $currentYear + 1) {
        throw new InvalidArgumentException('Enter a valid vehicle model year.');
    }
    if (!$capacity || $capacity < 1 || $capacity > 100) {
        throw new InvalidArgumentException('Passenger capacity must be between 1 and 100.');
    }
    if (!$fuelCapacity || $fuelCapacity < 1 || $fuelCapacity > 1000) {
        throw new InvalidArgumentException('Fuel tank capacity must be between 1 and 1000 liters.');
    }
    $allowedFuelTypes = ['Diesel', 'Gasoline', 'Hybrid', 'Electric'];
    if (!in_array($fuelType, $allowedFuelTypes, true)) {
        throw new InvalidArgumentException('Select a supported fuel type.');
    }

    $pdo = db();
    $catalog = $pdo->prepare(
        "SELECT vt.name AS type_name, b.name AS brand_name, m.model_name, vr.variant_name
           FROM vehicle_variants vr
           JOIN vehicle_models m ON m.id = vr.model_id AND m.status = 'Active'
           JOIN vehicle_types vt ON vt.id = m.vehicle_type_id AND vt.status = 'Active'
           JOIN vehicle_brands b ON b.id = m.brand_id AND b.status = 'Active'
          WHERE vr.id = ? AND m.id = ? AND b.id = ? AND vt.id = ? AND vr.status = 'Active'"
    );
    $catalog->execute([$variantId, $modelId, $brandId, $typeId]);
    $selection = $catalog->fetch();
    if (!$selection) {
        throw new InvalidArgumentException('The selected vehicle catalog combination is unsupported or inactive.');
    }

    $duplicate = $pdo->prepare('SELECT 1 FROM vehicles WHERE plate_number = ?');
    $duplicate->execute([$plate]);
    if ($duplicate->fetchColumn()) {
        throw new InvalidArgumentException('This plate number is already registered in the fleet.');
    }

    $uploadDir = ROOT_PATH . '/storage/private/vehicle-documents';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Secure document storage is unavailable.');
    }
    $storedName = bin2hex(random_bytes(24)) . '.' . $allowedMimes[$mime];
    $storedFile = $uploadDir . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($upload['tmp_name'], $storedFile)) {
        throw new RuntimeException('The vehicle document could not be stored securely.');
    }

    $pdo->beginTransaction();
    $vid = next_sequential_id($pdo, 'vehicles', 'id', 'VEH-');
    $vehicle = $pdo->prepare(
        'INSERT INTO vehicles (id, plate_number, type, brand, model, year, capacity, status,
                               fuel_type, fuel_capacity, current_fuel, odometer, maintenance_status,
                               location, total_trips, total_km, avg_fuel_km, operating_cost_km,
                               variant_id, created_by, reg_document)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 100, 0, ?, ?, 0, 0, ?, ?, ?, ?, ?)'
    );
    $documentId = 'VDOC-' . strtoupper(bin2hex(random_bytes(8)));
    $vehicle->execute([
        $vid, $plate, $selection['type_name'], $selection['brand_name'],
        $selection['model_name'] . ' ' . $selection['variant_name'], $year, $capacity,
        'Available', $fuelType, $fuelCapacity, 'Healthy', 'Central Depot', '—', '—',
        $variantId, $current_user['id'], $documentId,
    ]);

    $document = $pdo->prepare(
        'INSERT INTO vehicle_documents
           (id, vehicle_id, document_type, stored_filename, original_filename, mime_type, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', basename((string)$upload['name']));
    $originalName = function_exists('mb_substr') ? mb_substr($originalName, 0, 255) : substr($originalName, 0, 255);
    $document->execute([
        $documentId, $vid, 'OR/CR', $storedName,
        $originalName ?: 'vehicle-document.' . $allowedMimes[$mime], $mime, $fileSize, $current_user['id'],
    ]);

    $audit = $pdo->prepare(
        'INSERT INTO audit_logs (action, user_id, entity_type, entity_id, details)
         VALUES (?, ?, ?, ?, ?)'
    );
    $audit->execute([
        'VEHICLE_REGISTERED', $current_user['id'], 'vehicle', $vid,
        json_encode([
            'vehicle' => trim($selection['brand_name'] . ' ' . $selection['model_name'] . ' ' . $selection['variant_name']),
            'plate_number' => $plate,
            'document_id' => $documentId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $pdo->commit();
    $_SESSION['vehicle_csrf_token'] = bin2hex(random_bytes(32));

    redirect_with_toast($return, 'Vehicle ' . $vid . ' (' . $plate . ') registered with its supporting document.', 'success');
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($storedFile && is_file($storedFile)) {
        @unlink($storedFile);
    }
    if ($ex instanceof PDOException) {
        $message = $ex->getCode() === '23505'
            ? 'This plate number is already registered in the fleet.'
            : 'The vehicle could not be registered because the database operation failed.';
    } elseif ($ex instanceof InvalidArgumentException || $ex instanceof RuntimeException) {
        $message = $ex->getMessage();
    } else {
        $message = 'The vehicle could not be registered. Please try again.';
    }
    redirect_with_toast($return, $message, 'danger');
}
