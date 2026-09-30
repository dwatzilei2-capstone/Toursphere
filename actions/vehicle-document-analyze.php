<?php

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_document.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

try {
    if (!can('vehicles.manage')) {
        http_response_code(403);
        throw new RuntimeException('You do not have permission to register vehicles.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('POST method required.');
    }
    if (!is_string($_POST['csrf_token'] ?? null)
        || !hash_equals($_SESSION['vehicle_csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(419);
        throw new RuntimeException('Your form session expired. Reload the page and try again.');
    }

    $documentType = (string)($_POST['document_type'] ?? 'registration');
    if (!in_array($documentType, ['registration', 'insurance', 'ltfrb_permit'], true)) {
        throw new InvalidArgumentException('Unsupported vehicle document type.');
    }
    $uploadField = $documentType === 'registration' ? 'registration_document' : 'document';
    $upload = $_FILES[$uploadField] ?? [];
    $file = validate_vehicle_document_upload($upload);
    $extraction = extract_vehicle_document_data($upload['tmp_name'], $file['mime'], $documentType);
    if ($documentType === 'registration' && !empty($extraction['data']['make_model_combined'])) {
        $combined = trim((string)$extraction['data']['make_model_combined']);
        $brandCandidates = db()->query("SELECT name FROM vehicle_brands WHERE status = 'Active' ORDER BY LENGTH(name) DESC")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($brandCandidates as $catalogBrand) {
            if (!preg_match('/\b' . preg_quote((string)$catalogBrand, '/') . '\b/i', $combined)) continue;
            $series = trim(preg_replace('/\b' . preg_quote((string)$catalogBrand, '/') . '\b/i', '', $combined, 1));
            if ($series === '') continue;
            $extraction['data']['brand_make'] = (string)$catalogBrand;
            $extraction['data']['series_model'] = $series;
            break;
        }
    }
    $result = [
        'plate_number' => $extraction['data']['plate_number'] ?? null,
        'confidence' => $extraction['confidence'],
        'extraction_status' => $extraction['status'],
        'document_data' => $extraction['data'],
        'brand_make' => $extraction['data']['brand_make'] ?? null,
        'series_model' => $extraction['data']['series_model'] ?? null,
        'year_model' => $extraction['data']['year_model'] ?? null,
        'vehicle_class' => $extraction['data']['body_type'] ?? $extraction['data']['classification'] ?? null,
    ];

    if ($documentType !== 'registration') {
        $documentTypeVerification = verify_vehicle_document_type(
            read_vehicle_document_text($upload['tmp_name'], $file['mime']) ?? '',
            $documentType
        );
        $vehicleMatch = null;
        $vehicleId = trim((string)($_POST['vehicle_id'] ?? ''));
        if ($vehicleId !== '') {
            $identity = vehicle_document_current_identity(db(), $vehicleId);
            if (!$identity) {
                http_response_code(404);
                throw new InvalidArgumentException('The selected vehicle could not be found.');
            }
        } elseif (isset($_FILES['registration_document'])) {
            $registrationFile = validate_vehicle_document_upload($_FILES['registration_document']);
            $registration = extract_vehicle_plate($_FILES['registration_document']['tmp_name'], $registrationFile['mime']);
            $identity = $registration['document_data'];
            $submittedRegistrationPlate = normalize_vehicle_plate((string)($_POST['plate_number'] ?? ''));
            $identity['plate_number'] = $submittedRegistrationPlate !== ''
                ? $submittedRegistrationPlate
                : (string)($registration['plate_number'] ?? '');
        } else {
            $identity = [];
        }
        $manualPlate = trim((string)($_POST['manual_vehicle_plate'] ?? ''));
        $vehicleMatch = verify_vehicle_document_match(
            $identity,
            $result['document_data'],
            $documentType,
            $manualPlate !== '' ? $manualPlate : null,
            $result['confidence']
        );
        if ($vehicleId !== '' && $vehicleMatch['status'] !== 'MATCHED') {
            $event = $vehicleMatch['status'] === 'MISMATCHED'
                ? 'DOCUMENT_VEHICLE_MISMATCH'
                : 'DOCUMENT_VEHICLE_REVIEW_REQUIRED';
            $audit = db()->prepare(
                'INSERT INTO audit_logs (action, user_id, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)'
            );
            $audit->execute([
                $event, $current_user['id'], 'vehicle', $vehicleId,
                json_encode([
                    'document_type' => $documentType,
                    'verification_result' => $vehicleMatch['status'],
                    'reason' => $vehicleMatch['reason'],
                    'conflicting_identifiers' => $vehicleMatch['conflicting_identifiers'],
                ], JSON_UNESCAPED_SLASHES),
            ]);
        }
        if ($vehicleId !== '' && $documentTypeVerification['status'] !== 'MATCHED') {
            $event = $documentTypeVerification['status'] === 'MISMATCHED'
                ? 'DOCUMENT_TYPE_MISMATCH'
                : 'DOCUMENT_TYPE_REVIEW_REQUIRED';
            $audit = db()->prepare(
                'INSERT INTO audit_logs (action, user_id, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)'
            );
            $audit->execute([
                $event, $current_user['id'], 'vehicle', $vehicleId,
                json_encode([
                    'document_type' => $documentType,
                    'verification_result' => $documentTypeVerification['status'],
                    'reason' => $documentTypeVerification['reason'],
                ], JSON_UNESCAPED_SLASHES),
            ]);
        }
        echo json_encode([
            'ok' => true,
            'document_type' => $documentType,
            'extraction_status' => $result['extraction_status'],
            'confidence' => $result['confidence'],
            'document_data' => $result['document_data'],
            'document_type_verification' => $documentTypeVerification,
            'vehicle_match' => $vehicleMatch,
            'message' => $result['document_data']
                ? 'Document fields were extracted. Review them before saving.'
                : 'No reliable fields were detected. Review the document manually before saving.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $catalogMatch = null;
    if ($result['brand_make'] && $result['series_model']) {
        $match = db()->prepare(
            "SELECT vt.id AS vehicle_type_id, b.id AS brand_id, m.id AS model_id,
                    vt.name AS vehicle_type, b.name AS brand, m.model_name
               FROM vehicle_models m
               JOIN vehicle_types vt ON vt.id = m.vehicle_type_id AND vt.status = 'Active'
               JOIN vehicle_brands b ON b.id = m.brand_id AND b.status = 'Active'
              WHERE m.status = 'Active'
                AND (LOWER(?) LIKE '%' || LOWER(b.name) || '%'
                     OR LOWER(b.name) LIKE '%' || LOWER(?) || '%')
                AND (LOWER(?) LIKE '%' || LOWER(m.model_name) || '%'
                     OR LOWER(m.model_name) LIKE '%' || LOWER(?) || '%')
              ORDER BY LENGTH(m.model_name) DESC
              LIMIT 2"
        );
        $match->execute([
            $result['brand_make'], $result['brand_make'],
            $result['series_model'], $result['series_model'],
        ]);
        $matches = $match->fetchAll();
        if (count($matches) === 1) $catalogMatch = $matches[0];
    }

    $partialMatch = [];
    if (!$catalogMatch && $result['brand_make']) {
        $brandStmt = db()->prepare(
            "SELECT id FROM vehicle_brands
              WHERE status = 'Active'
                AND (LOWER(?) LIKE '%' || LOWER(name) || '%'
                     OR LOWER(name) LIKE '%' || LOWER(?) || '%')
              LIMIT 2"
        );
        $brandStmt->execute([$result['brand_make'], $result['brand_make']]);
        $brandMatches = $brandStmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($brandMatches) === 1) $partialMatch['brand_id'] = $brandMatches[0];
    }
    if (!$catalogMatch && $result['vehicle_class']) {
        $normalize = static fn(string $value): string => trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
        $documentClass = $normalize($result['vehicle_class']);
        $typeMatches = [];
        foreach (db()->query("SELECT id, name FROM vehicle_types WHERE status = 'Active'")->fetchAll() as $type) {
            $catalogType = $normalize($type['name']);
            if ($documentClass === $catalogType
                || str_contains($documentClass, $catalogType)
                || (strlen($documentClass) >= 3 && str_contains($catalogType, $documentClass))) {
                $typeMatches[] = $type;
            }
        }
        if (count($typeMatches) === 1) {
            $partialMatch['vehicle_type_id'] = $typeMatches[0]['id'];
        }
    }

    $registrationTypeVerification = verify_vehicle_document_type(
        read_vehicle_document_text($upload['tmp_name'], $file['mime']) ?? '',
        'registration'
    );

    echo json_encode([
        'ok' => true,
        'plate_number' => $result['plate_number'],
        'confidence' => $result['confidence'],
        'extraction_status' => $result['extraction_status'],
        'document_data' => $result['document_data'],
        'document_type_verification' => $registrationTypeVerification,
        'brand_make' => $result['brand_make'],
        'series_model' => $result['series_model'],
        'year_model' => $result['year_model'],
        'vehicle_class' => $result['vehicle_class'],
        'catalog_match' => $catalogMatch,
        'partial_match' => $partialMatch,
        'message' => $result['plate_number']
            ? 'Available vehicle information was extracted. Review the values before registering.'
            : 'Plate number was not detected. Review the document and enter the plate number manually.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
