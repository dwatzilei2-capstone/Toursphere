<?php

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_document.php';
require_once ROOT_PATH . '/includes/vehicle_photo.php';
require_login();
require_permission('vehicles.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/dashboard.php');
}

$return = $_POST['return'] ?? (BASE_URL . '/modules/fleet-vehicle-management/vehicle-directory.php');
$storedFiles = [];

try {
    if (($_POST['action'] ?? '') !== 'create') {
        throw new InvalidArgumentException('Unknown vehicle action.');
    }
    if (!is_string($_POST['csrf_token'] ?? null)
        || !hash_equals($_SESSION['vehicle_csrf_token'] ?? '', $_POST['csrf_token'])) {
        throw new RuntimeException('Your form session expired. Please reopen the Add Vehicle form and try again.');
    }

    $upload = $_FILES['registration_document'] ?? [];
    $file = validate_vehicle_document_upload($upload);
    $extraction = extract_vehicle_plate($upload['tmp_name'], $file['mime']);
    $registrationTypeVerification = verify_vehicle_document_type(
        read_vehicle_document_text($upload['tmp_name'], $file['mime']) ?? '',
        'registration'
    );
    if ($registrationTypeVerification['status'] !== 'MATCHED') {
        throw new InvalidArgumentException($registrationTypeVerification['reason'] . ' Upload a clearer searchable Vehicle Registration Document; manual confirmation cannot replace type verification.');
    }
    $plate = (string)($extraction['plate_number'] ?? '');
    if ($plate === '') {
        throw new InvalidArgumentException('Plate number could not be detected from the uploaded document. Upload a clearer copy.');
    }
    $submittedPlate = normalize_vehicle_plate((string)($_POST['plate_number'] ?? ''));
    if (!hash_equals($plate, $submittedPlate)) {
        throw new InvalidArgumentException('The plate number does not match the uploaded registration document.');
    }
    $registrationStatus = $extraction['extraction_status'];
    $registrationReviewed = ($_POST['registration_manual_review_confirm'] ?? '') === '1';
    if ($registrationStatus !== 'extracted' && !$registrationReviewed) {
        throw new InvalidArgumentException('Review the registration document and confirm it before continuing.');
    }
    $registrationTypeStatus = 'MATCHED';
    $registrationData = $extraction['document_data'];
    $registrationData['document_type_status'] = $registrationTypeStatus;
    $registrationData['document_type_reason'] = $registrationTypeVerification['reason'];
    if ($registrationReviewed) $registrationData['manual_reviewed'] = true;

    $documents = [[
        'id' => 'VDOC-' . strtoupper(bin2hex(random_bytes(8))),
        'type' => 'registration',
        'upload' => $upload,
        'file' => $file,
        'data' => $registrationData,
        'status' => $registrationStatus,
        'confidence' => $extraction['confidence'],
        'manually_reviewed' => $registrationReviewed,
        'document_type_status' => $registrationTypeStatus,
        'document_type_reason' => $registrationTypeVerification['reason'],
    ]];
    foreach ([
        'insurance_document' => 'insurance',
        'ltfrb_permit_document' => 'ltfrb_permit',
    ] as $field => $type) {
        $optionalUpload = $_FILES[$field] ?? [];
        $optionalFile = validate_optional_vehicle_document_upload($optionalUpload);
        if (!$optionalFile) continue;
        $optionalExtraction = extract_vehicle_document_data($optionalUpload['tmp_name'], $optionalFile['mime'], $type);
        $documentTypeVerification = verify_vehicle_document_type(
            read_vehicle_document_text($optionalUpload['tmp_name'], $optionalFile['mime']) ?? '',
            $type
        );
        $manualReviewField = $type === 'insurance' ? 'insurance_manual_review_confirm' : 'ltfrb_manual_review_confirm';
        $manualPlateField = $type === 'insurance' ? 'insurance_manual_vehicle_plate' : 'ltfrb_manual_vehicle_plate';
        $manuallyReviewed = ($_POST[$manualReviewField] ?? '') === '1';
        $manualPlate = trim((string)($_POST[$manualPlateField] ?? ''));
        if (($optionalExtraction['status'] !== 'extracted' || $manualPlate !== '') && !$manuallyReviewed) {
            throw new InvalidArgumentException('Review each optional document and confirm it before registering.');
        }
        if ($documentTypeVerification['status'] !== 'MATCHED') {
            throw new InvalidArgumentException($documentTypeVerification['reason'] . ' A plate number or manual confirmation cannot replace document-type verification. Upload a clearer searchable document.');
        }
        $referenceIdentity = $extraction['document_data'];
        $referenceIdentity['plate_number'] = $plate;
        $vehicleMatch = verify_vehicle_document_match(
            $referenceIdentity,
            $optionalExtraction['data'],
            $type,
            $manualPlate !== '' ? $manualPlate : null,
            $optionalExtraction['confidence']
        );
        if ($vehicleMatch['status'] === 'MISMATCHED') {
            throw new InvalidArgumentException('The uploaded ' . ($type === 'insurance' ? 'Insurance' : 'LTFRB Permit') . ' document does not match this vehicle.');
        }
        if ($vehicleMatch['status'] !== 'MATCHED') {
            throw new InvalidArgumentException($vehicleMatch['reason'] . ' Enter the plate number shown on the document and confirm your review.');
        }
        $optionalExtraction['data']['vehicle_match_status'] = $vehicleMatch['status'];
        $optionalExtraction['data']['vehicle_match_reason'] = $vehicleMatch['reason'];
        $optionalExtraction['data']['matched_identifiers'] = $vehicleMatch['matched_identifiers'];
        $optionalExtraction['data']['conflicting_identifiers'] = $vehicleMatch['conflicting_identifiers'];
        $optionalExtraction['data']['document_type_status'] = 'MATCHED';
        $optionalExtraction['data']['document_type_reason'] = $documentTypeVerification['reason'];
        if ($manuallyReviewed) $optionalExtraction['data']['manual_reviewed'] = true;
        $documents[] = [
            'id' => 'VDOC-' . strtoupper(bin2hex(random_bytes(8))),
            'type' => $type,
            'upload' => $optionalUpload,
            'file' => $optionalFile,
            'data' => $optionalExtraction['data'],
            'status' => $optionalExtraction['status'],
            'confidence' => $optionalExtraction['confidence'],
            'manually_reviewed' => $manuallyReviewed,
            'vehicle_match_status' => $vehicleMatch['status'],
            'vehicle_match_reason' => $vehicleMatch['reason'],
            'document_type_status' => $optionalExtraction['data']['document_type_status'],
            'document_type_reason' => $documentTypeVerification['reason'],
        ];
    }

    $typeId = filter_var($_POST['vehicle_type_id'] ?? null, FILTER_VALIDATE_INT);
    $brandId = filter_var($_POST['brand_id'] ?? null, FILTER_VALIDATE_INT);
    $modelId = filter_var($_POST['model_id'] ?? null, FILTER_VALIDATE_INT);
    $variantId = filter_var($_POST['variant_id'] ?? null, FILTER_VALIDATE_INT);
    $variantId = $variantId ?: null;
    $year = filter_var($_POST['year'] ?? null, FILTER_VALIDATE_INT);
    $capacity = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT);
    $fuelCapacity = null;
    $fuelType = trim((string)($_POST['fuel_type'] ?? ''));
    $currentYear = (int)date('Y');
    if (!$typeId || !$brandId || !$modelId) {
        throw new InvalidArgumentException('Select a supported vehicle type, brand, and model.');
    }
    if (!$year || $year < 1980 || $year > $currentYear + 1) {
        throw new InvalidArgumentException('Enter a valid vehicle model year.');
    }
    if (!$capacity || $capacity < 1 || $capacity > 100) {
        throw new InvalidArgumentException('Passenger capacity must be between 1 and 100.');
    }
    $allowedFuelTypes = ['Diesel', 'Gasoline', 'Hybrid', 'Electric'];
    if (!in_array($fuelType, $allowedFuelTypes, true)) {
        throw new InvalidArgumentException('Select a supported fuel type.');
    }

    $pdo = db();
    $catalog = $pdo->prepare(
        "SELECT vt.name AS type_name, b.name AS brand_name, m.model_name
           FROM vehicle_models m
           JOIN vehicle_types vt ON vt.id = m.vehicle_type_id AND vt.status = 'Active'
           JOIN vehicle_brands b ON b.id = m.brand_id AND b.status = 'Active'
          WHERE m.id = ? AND b.id = ? AND vt.id = ? AND m.status = 'Active'"
    );
    $catalog->execute([$modelId, $brandId, $typeId]);
    $selection = $catalog->fetch();
    if (!$selection) {
        throw new InvalidArgumentException('The selected vehicle catalog combination is unsupported or inactive.');
    }
    $modelName = $selection['model_name'];
    $extractedModel = trim((string)($extraction['series_model'] ?? ''));
    $extractedBrand = trim((string)($extraction['brand_make'] ?? ''));
    if ($extractedModel !== '' && $extractedBrand !== ''
        && str_contains(strtolower($extractedModel), strtolower($selection['model_name']))
        && (str_contains(strtolower($extractedBrand), strtolower($selection['brand_name']))
            || str_contains(strtolower($selection['brand_name']), strtolower($extractedBrand)))) {
        $modelName = sanitize_text($extractedModel, 100);
    }
    $variantName = null;
    if ($variantId) {
        $variant = $pdo->prepare(
            "SELECT variant_name, fuel_tank_capacity FROM vehicle_variants
              WHERE id = ? AND model_id = ? AND model_year = ? AND status = 'Active'"
        );
        $variant->execute([$variantId, $modelId, $year]);
        $variantData = $variant->fetch();
        $variantName = $variantData['variant_name'] ?? null;
        if (!$variantName) {
            throw new InvalidArgumentException('The selected variant is not available for this model and year.');
        }
        $fuelCapacity = $variantData['fuel_tank_capacity'] !== null
            ? (int)$variantData['fuel_tank_capacity']
            : null;
    }

    $duplicate = $pdo->prepare('SELECT 1 FROM vehicles WHERE plate_number = ?');
    $duplicate->execute([$plate]);
    if ($duplicate->fetchColumn()) {
        throw new InvalidArgumentException('This plate number is already registered in the fleet.');
    }

    $photoUpload = $_FILES['vehicle_photo'] ?? [];
    $photoValidated = vehicle_photo_validate_upload($photoUpload, true);
    $photoStored = $photoValidated ? vehicle_photo_store_upload($photoUpload, $photoValidated) : null;
    if ($photoStored) $storedFiles[] = $photoStored['path'];

    $uploadDir = ROOT_PATH . '/storage/private/vehicle-documents';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Secure document storage is unavailable.');
    }
    foreach ($documents as &$document) {
        $storedName = bin2hex(random_bytes(24)) . '.' . $document['file']['extension'];
        $storedPath = $uploadDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($document['upload']['tmp_name'], $storedPath)) {
            throw new RuntimeException('A vehicle compliance document could not be stored securely.');
        }
        $storedFiles[] = $storedPath;
        $document['stored_filename'] = $storedName;
    }
    unset($document);

    $documentIds = ['registration' => null, 'insurance' => null, 'ltfrb_permit' => null];
    foreach ($documents as $document) $documentIds[$document['type']] = $document['id'];

    $pdo->beginTransaction();
    $vid = next_available_sequential_id($pdo, 'vehicles', 'id', 'VEH-');
    $vehicle = $pdo->prepare(
        'INSERT INTO vehicles (id, plate_number, type, brand, model, year, capacity, status,
                               fuel_type, fuel_capacity, current_fuel, odometer, maintenance_status,
                               location, total_trips, total_km, avg_fuel_km, operating_cost_km,
                               variant_id, created_by, reg_document)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 100, 0, ?, ?, 0, 0, ?, ?, ?, ?, ?)'
    );
    $vehicle->execute([
        $vid, $plate, $selection['type_name'], $selection['brand_name'],
        trim($modelName . ' ' . ($variantName ?? '')), $year, $capacity,
        'Available', $fuelType, $fuelCapacity, 'Healthy', 'Central Depot', '—', '—',
        $variantId, $current_user['id'], $documentIds['registration'],
    ]);

    $document = $pdo->prepare(
        'INSERT INTO vehicle_documents
           (id, vehicle_id, document_type, stored_filename, original_filename, mime_type, file_size,
            extracted_plate_number, extraction_confidence, extracted_data, extraction_status, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?)'
    );
    foreach ($documents as $uploadedDocument) {
        $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', basename((string)$uploadedDocument['upload']['name']));
        $originalName = function_exists('mb_substr') ? mb_substr($originalName, 0, 255) : substr($originalName, 0, 255);
        $extractedData = $uploadedDocument['data'];
        if ($uploadedDocument['manually_reviewed']) $extractedData['manual_reviewed'] = true;
        $document->execute([
            $uploadedDocument['id'], $vid, $uploadedDocument['type'], $uploadedDocument['stored_filename'],
            $originalName ?: 'vehicle-document.' . $uploadedDocument['file']['extension'],
            $uploadedDocument['file']['mime'], $uploadedDocument['file']['size'],
            $uploadedDocument['data']['plate_number'] ?? null, $uploadedDocument['confidence'] ?: null,
            json_encode($extractedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $uploadedDocument['status'], $current_user['id'],
        ]);
    }

    $pdo->prepare('UPDATE vehicles SET insurance_document = ?, ltfrb_permit = ? WHERE id = ?')
        ->execute([$documentIds['insurance'], $documentIds['ltfrb_permit'], $vid]);

    $audit = $pdo->prepare(
        'INSERT INTO audit_logs (action, user_id, entity_type, entity_id, details)
         VALUES (?, ?, ?, ?, ?)'
    );
    $audit->execute([
        'VEHICLE_REGISTERED', $current_user['id'], 'vehicle', $vid,
        json_encode([
            'vehicle' => trim($selection['brand_name'] . ' ' . $modelName . ' ' . ($variantName ?? '')),
            'plate_number' => $plate,
            'document_id' => $documentIds['registration'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    foreach ($documents as $uploadedDocument) {
        $event = [
            'registration' => 'VEHICLE_REGISTRATION_DOCUMENT_UPLOADED',
            'insurance' => 'VEHICLE_INSURANCE_UPLOADED',
            'ltfrb_permit' => 'VEHICLE_LTFRB_PERMIT_UPLOADED',
        ][$uploadedDocument['type']];
        $audit->execute([
            $event, $current_user['id'], 'vehicle', $vid,
            json_encode([
                'document_type' => $uploadedDocument['type'],
                'status' => $uploadedDocument['status'],
                'manually_reviewed' => $uploadedDocument['manually_reviewed'],
                'vehicle_match_status' => $uploadedDocument['vehicle_match_status'] ?? null,
                'vehicle_match_reason' => $uploadedDocument['vehicle_match_reason'] ?? null,
                'document_type_status' => $uploadedDocument['document_type_status'] ?? null,
                'document_type_reason' => $uploadedDocument['document_type_reason'] ?? null,
            ], JSON_UNESCAPED_SLASHES),
        ]);
        if (!empty($uploadedDocument['vehicle_match_status'])) {
            $audit->execute([
                'DOCUMENT_VEHICLE_MATCHED', $current_user['id'], 'vehicle', $vid,
                json_encode([
                    'document_type' => $uploadedDocument['type'],
                    'verification_result' => $uploadedDocument['vehicle_match_status'],
                    'reason' => $uploadedDocument['vehicle_match_reason'],
                ], JSON_UNESCAPED_SLASHES),
            ]);
        }
        if (!empty($uploadedDocument['document_type_status'])) {
            $audit->execute([
                'DOCUMENT_TYPE_MATCHED', $current_user['id'], 'vehicle', $vid,
                json_encode([
                    'document_type' => $uploadedDocument['type'],
                    'verification_result' => $uploadedDocument['document_type_status'],
                    'reason' => $uploadedDocument['document_type_reason'],
                ], JSON_UNESCAPED_SLASHES),
            ]);
        }
    }
    $audit->execute([
        'VEHICLE_COMPLIANCE_UPDATED', $current_user['id'], 'vehicle', $vid,
        json_encode([
            'document_types' => array_column($documents, 'type'),
            'manually_reviewed_types' => array_column(array_filter($documents, static fn(array $document): bool => $document['manually_reviewed']), 'type'),
            'vehicle_match_statuses' => array_column(array_filter($documents, static fn(array $document): bool => isset($document['vehicle_match_status'])), 'vehicle_match_status', 'type'),
        ], JSON_UNESCAPED_SLASHES),
    ]);
    $photoVehicle = ['id'=>$vid, 'brand'=>$selection['brand_name'], 'model'=>trim($modelName . ' ' . ($variantName ?? '')), 'type'=>$selection['type_name'], 'year'=>$year];
    $photoCatalogPath = ROOT_PATH . '/assets/images/vehicle-samples/manifest.json';
    $photoCatalog = is_file($photoCatalogPath) ? (json_decode(file_get_contents($photoCatalogPath), true) ?: []) : [];
    vehicle_photo_seed_sample($pdo, $photoVehicle, $photoCatalog);
    if ($photoStored) vehicle_photo_save_actual($pdo, $vid, $photoStored, (string)$current_user['id']);
    $pdo->commit();
    $storedFiles = [];
    $_SESSION['vehicle_csrf_token'] = bin2hex(random_bytes(32));

    redirect_with_toast($return, 'Vehicle ' . $vid . ' (' . $plate . ') registered with its supporting document.', 'success');
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($storedFiles as $storedPath) {
        if (is_file($storedPath)) @unlink($storedPath);
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
