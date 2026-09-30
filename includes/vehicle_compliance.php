<?php

function vehicle_operational_compliance(PDO $pdo, string $vehicleId, ?string $today = null): array
{
    $requiredDocuments = [
        'registration' => 'Registration document',
        'insurance' => 'Insurance document',
        'ltfrb_permit' => 'LTFRB Permit',
    ];
    $documentsStmt = $pdo->prepare(
        'SELECT document_type, extracted_data, extraction_status
           FROM vehicle_documents
          WHERE vehicle_id = ?
          ORDER BY uploaded_at DESC, id DESC'
    );
    $documentsStmt->execute([$vehicleId]);
    $documents = [];
    foreach ($documentsStmt->fetchAll() as $document) {
        $type = strtolower((string)$document['document_type']);
        if (in_array($type, ['or/cr', 'registration document'], true)) $type = 'registration';
        if (!isset($requiredDocuments[$type]) || isset($documents[$type])) continue;
        $documents[$type] = [
            'data' => json_decode((string)$document['extracted_data'], true) ?: [],
            'status' => (string)$document['extraction_status'],
        ];
    }

    $today ??= date('Y-m-d');
    $reasons = [];
    $hasTestFixtures = false;
    $isDevelopment = in_array(strtolower((string)(getenv('APP_ENV') ?: 'production')), ['development', 'testing'], true);

    foreach ($requiredDocuments as $type => $label) {
        if (!isset($documents[$type])) {
            $reasons[] = $label . ' is missing.';
            continue;
        }

        $document = $documents[$type];
        $data = $document['data'];
        if (!empty($data['test_fixture'])) {
            $hasTestFixtures = true;
            if (!$isDevelopment) {
                $reasons[] = $label . ' is TEST ONLY and cannot authorize operation.';
                continue;
            }
        } else {
            if (in_array($type, ['insurance', 'ltfrb_permit'], true)
                && ($data['vehicle_match_status'] ?? '') !== 'MATCHED') {
                $reasons[] = $label . ' has not been verified against this vehicle.';
                continue;
            }
            if (($data['document_type_status'] ?? '') !== 'MATCHED') {
                $reasons[] = $label . ' document type has not been verified.';
                continue;
            }
        }

        $reviewed = $document['status'] === 'extracted'
            || ($document['status'] === 'test_fixture' && !empty($data['test_fixture']) && $isDevelopment)
            || !empty($data['manual_reviewed']);
        if (!$reviewed) {
            $reasons[] = $label . ' needs review.';
            continue;
        }

        foreach ([
            'effective_date' => 'is not yet effective',
            'expiration_date' => 'has expired',
        ] as $field => $failure) {
            if (empty($data[$field])) continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$data[$field]);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$date || $date->format('Y-m-d') !== $data[$field]
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                $reasons[] = $label . ' validity date needs review.';
                continue;
            }
            if ($field === 'effective_date' && $date->format('Y-m-d') > $today) {
                $reasons[] = $label . ' is not yet effective.';
            } elseif ($field === 'expiration_date' && $date->format('Y-m-d') < $today) {
                $reasons[] = $label . ' has expired.';
            }
        }
    }

    $reasons = array_values(array_unique($reasons));
    $operational = !$reasons;
    return [
        'operational' => $operational,
        'status' => $operational ? ($hasTestFixtures ? 'OPERATIONAL (TEST DATA)' : 'OPERATIONAL') : 'NOT OPERATIONAL',
        'reason' => $operational ? '' : implode(' ', $reasons),
        'reasons' => $reasons,
        'test_data' => $hasTestFixtures,
    ];
}