<?php

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_document.php';
require_login();
require_permission('vehicles.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/modules/fleet-vehicle-management/vehicle-directory.php');
}

$storedPath = null;
$return = BASE_URL . '/modules/fleet-vehicle-management/vehicle-directory.php';

try {
    if (!is_string($_POST['csrf_token'] ?? null)
        || !hash_equals($_SESSION['vehicle_csrf_token'] ?? '', $_POST['csrf_token'])) {
        throw new RuntimeException('Your form session expired. Refresh the vehicle profile and try again.');
    }

    $vehicleId = trim((string)($_POST['vehicle_id'] ?? ''));
    $documentType = (string)($_POST['document_type'] ?? '');
    $events = [
        'insurance' => 'VEHICLE_INSURANCE_UPLOADED',
        'ltfrb_permit' => 'VEHICLE_LTFRB_PERMIT_UPLOADED',
    ];
    if ($vehicleId === '' || !isset($events[$documentType])) {
        throw new InvalidArgumentException('Select a valid vehicle and compliance document type.');
    }
    $return .= '?vehicle=' . rawurlencode($vehicleId);

    $pdo = db();
    $vehicleStmt = $pdo->prepare('SELECT id FROM vehicles WHERE id = ?');
    $vehicleStmt->execute([$vehicleId]);
    if (!$vehicleStmt->fetchColumn()) {
        throw new InvalidArgumentException('The selected vehicle could not be found.');
    }

    $upload = $_FILES['document'] ?? [];
    $file = validate_vehicle_document_upload($upload);
    $extraction = extract_vehicle_document_data($upload['tmp_name'], $file['mime'], $documentType);
    $documentTypeVerification = verify_vehicle_document_type(
        read_vehicle_document_text($upload['tmp_name'], $file['mime']) ?? '',
        $documentType
    );
    $manuallyReviewed = ($_POST['manual_review_confirm'] ?? '') === '1';
    $manualPlate = trim((string)($_POST['manual_vehicle_plate'] ?? ''));
    if (($extraction['status'] !== 'extracted' || $manualPlate !== '') && !$manuallyReviewed) {
        throw new InvalidArgumentException('Review the document manually and confirm before uploading.');
    }
    $storedName = bin2hex(random_bytes(24)) . '.' . $file['extension'];
    $uploadDir = ROOT_PATH . '/storage/private/vehicle-documents';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Secure document storage is unavailable.');
    }
    $storedPath = $uploadDir . DIRECTORY_SEPARATOR . $storedName;
    if ($manuallyReviewed) $extraction['data']['manual_reviewed'] = true;

    $documentId = 'VDOC-' . strtoupper(bin2hex(random_bytes(8)));
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', basename((string)$upload['name']));
    $originalName = function_exists('mb_substr') ? mb_substr($originalName, 0, 255) : substr($originalName, 0, 255);

    $pdo->beginTransaction();
    $vehicleLock = $pdo->prepare('SELECT id FROM vehicles WHERE id = ? FOR UPDATE');
    $vehicleLock->execute([$vehicleId]);
    if (!$vehicleLock->fetchColumn()) throw new InvalidArgumentException('The selected vehicle could not be found.');

    if ($documentTypeVerification['status'] !== 'MATCHED') {
        $event = $documentTypeVerification['status'] === 'MISMATCHED'
            ? 'DOCUMENT_TYPE_MISMATCH'
            : 'DOCUMENT_TYPE_REVIEW_REQUIRED';
        $audit = $pdo->prepare(
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
        $pdo->commit();
        $message = $documentTypeVerification['status'] === 'MISMATCHED'
            ? 'The uploaded document is not an ' . ($documentType === 'insurance' ? 'Insurance' : 'LTFRB Permit') . '.'
            : $documentTypeVerification['reason'] . ' A plate number or manual confirmation cannot replace document-type verification. Upload a clearer searchable document.';
        redirect_with_toast($return, $message, $documentTypeVerification['status'] === 'MISMATCHED' ? 'danger' : 'warning');
    }
    $extraction['data']['document_type_status'] = 'MATCHED';
    $extraction['data']['document_type_reason'] = $documentTypeVerification['reason'];

    $vehicleMatch = verify_vehicle_document_match(
        vehicle_document_current_identity($pdo, $vehicleId),
        $extraction['data'],
        $documentType,
        $manualPlate !== '' ? $manualPlate : null,
        $extraction['confidence']
    );
    if ($vehicleMatch['status'] !== 'MATCHED') {
        $event = $vehicleMatch['status'] === 'MISMATCHED'
            ? 'DOCUMENT_VEHICLE_MISMATCH'
            : 'DOCUMENT_VEHICLE_REVIEW_REQUIRED';
        $audit = $pdo->prepare(
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
        $pdo->commit();
        $message = $vehicleMatch['status'] === 'MISMATCHED'
            ? 'The uploaded ' . ($documentType === 'insurance' ? 'Insurance' : 'LTFRB Permit') . ' document does not match this vehicle.'
            : $vehicleMatch['reason'] . ' Please review the document or upload a clearer copy.';
        redirect_with_toast($return, $message, $vehicleMatch['status'] === 'MISMATCHED' ? 'danger' : 'warning');
    }

    if (!move_uploaded_file($upload['tmp_name'], $storedPath)) {
        throw new RuntimeException('The compliance document could not be stored securely.');
    }
    $extraction['data']['vehicle_match_status'] = $vehicleMatch['status'];
    $extraction['data']['vehicle_match_reason'] = $vehicleMatch['reason'];
    $extraction['data']['matched_identifiers'] = $vehicleMatch['matched_identifiers'];
    $extraction['data']['conflicting_identifiers'] = $vehicleMatch['conflicting_identifiers'];

    $insert = $pdo->prepare(
        'INSERT INTO vehicle_documents
           (id, vehicle_id, document_type, stored_filename, original_filename, mime_type, file_size,
            extraction_confidence, extracted_data, extraction_status, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?)'
    );
    $insert->execute([
        $documentId, $vehicleId, $documentType, $storedName,
        $originalName ?: 'vehicle-document.' . $file['extension'], $file['mime'], $file['size'],
        $extraction['confidence'] ?: null,
        json_encode($extraction['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $extraction['status'], $current_user['id'],
    ]);

    $legacyColumn = $documentType === 'insurance' ? 'insurance_document' : 'ltfrb_permit';
    $update = $pdo->prepare("UPDATE vehicles SET {$legacyColumn} = ? WHERE id = ?");
    $update->execute([$documentId, $vehicleId]);

    $details = json_encode([
        'document_type' => $documentType,
        'status' => $extraction['status'],
        'manually_reviewed' => $manuallyReviewed,
    ], JSON_UNESCAPED_SLASHES);
    $audit = $pdo->prepare(
        'INSERT INTO audit_logs (action, user_id, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)'
    );
    $audit->execute([$events[$documentType], $current_user['id'], 'vehicle', $vehicleId, $details]);
    $audit->execute([
        'DOCUMENT_VEHICLE_MATCHED', $current_user['id'], 'vehicle', $vehicleId,
        json_encode([
            'document_type' => $documentType,
            'verification_result' => $vehicleMatch['status'],
            'reason' => $vehicleMatch['reason'],
            'matched_identifiers' => $vehicleMatch['matched_identifiers'],
        ], JSON_UNESCAPED_SLASHES),
    ]);
    $audit->execute([
        'DOCUMENT_TYPE_MATCHED', $current_user['id'], 'vehicle', $vehicleId,
        json_encode([
            'document_type' => $documentType,
            'verification_result' => $extraction['data']['document_type_status'],
            'reason' => $documentTypeVerification['reason'],
        ], JSON_UNESCAPED_SLASHES),
    ]);
    $audit->execute(['VEHICLE_COMPLIANCE_UPDATED', $current_user['id'], 'vehicle', $vehicleId, $details]);
    $pdo->commit();
    $storedPath = null;

    redirect_with_toast($return, 'Compliance document uploaded and linked to vehicle ' . $vehicleId . '.', 'success');
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if ($storedPath && is_file($storedPath)) @unlink($storedPath);
    $message = $ex instanceof InvalidArgumentException || $ex instanceof RuntimeException
        ? $ex->getMessage()
        : 'The compliance document could not be uploaded. Please try again.';
    redirect_with_toast($return, $message, 'danger');
}