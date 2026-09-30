<?php

function vehicle_document_allowed_mimes(): array
{
    return [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
}

function validate_vehicle_document_upload(array $upload): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Select an OR/CR or supporting vehicle document.');
    }
    if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        throw new InvalidArgumentException('The vehicle document upload is invalid.');
    }
    $size = (int)($upload['size'] ?? 0);
    if ($size < 1 || $size > 8 * 1024 * 1024) {
        throw new InvalidArgumentException('The vehicle document must be 8 MB or smaller.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extensions = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
    ];
    $extension = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset($extensions[$mime]) || !in_array($extension, $extensions[$mime], true)) {
        throw new InvalidArgumentException('Only PDF, JPG, JPEG, and PNG vehicle documents are allowed.');
    }
    return ['mime' => $mime, 'extension' => $extension, 'size' => $size];
}

function validate_optional_vehicle_document_upload(array $upload): ?array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    return validate_vehicle_document_upload($upload);
}

function normalize_vehicle_plate(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]+/', '-', $value);
    return trim((string)$value, '-');
}

function normalize_vehicle_identifier(string $value): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($value)));
}

function extract_vehicle_identifiers_from_text(string $text): array
{
    $identifiers = [];
    $plateValue = vehicle_document_labeled_value($text, ['VEHICLE PLATE NUMBER', 'VEHICLE PLATE NO', 'VEHICLE PLATE #', 'PLATE NUMBER', 'PLATE NO', 'PLATE #']);
    if ($plateValue) {
        $plate = normalize_vehicle_plate($plateValue);
        if ($plate !== '') $identifiers['plate_number'] = $plate;
    }
    foreach ([
        'chassis_number' => ['CHASSIS NUMBER', 'CHASSIS NO', 'CHASSIS #', 'VIN', 'VEHICLE IDENTIFICATION NUMBER'],
        'engine_number' => ['ENGINE NUMBER', 'ENGINE NO', 'ENGINE #'],
        'mv_file_number' => ['MV FILE NUMBER', 'MV FILE NO', 'MV FILE #'],
    ] as $key => $labels) {
        if ($value = vehicle_document_labeled_value($text, $labels)) $identifiers[$key] = $value;
    }
    return $identifiers;
}

function vehicle_document_current_identity(PDO $pdo, string $vehicleId): array
{
    $vehicle = $pdo->prepare('SELECT id, plate_number FROM vehicles WHERE id = ?');
    $vehicle->execute([$vehicleId]);
    $record = $vehicle->fetch(PDO::FETCH_ASSOC);
    if (!$record) return [];

    $identity = ['plate_number' => (string)$record['plate_number']];
    $registration = $pdo->prepare(
        "SELECT extracted_data FROM vehicle_documents
          WHERE vehicle_id = ? AND LOWER(document_type) IN ('registration', 'or/cr', 'registration document')
          ORDER BY uploaded_at DESC, id DESC LIMIT 1"
    );
    $registration->execute([$vehicleId]);
    $stored = json_decode((string)$registration->fetchColumn(), true) ?: [];
    foreach (['chassis_number', 'engine_number', 'mv_file_number'] as $key) {
        if (!empty($stored[$key])) $identity[$key] = (string)$stored[$key];
    }
    return $identity;
}

function verify_vehicle_document_match(
    array $vehicleIdentity,
    array $documentData,
    string $documentType,
    ?string $manualPlate = null,
    float $confidence = 1.0
): array {
    $labels = [
        'plate_number' => 'Plate Number',
        'chassis_number' => 'Chassis Number',
        'engine_number' => 'Engine Number',
        'mv_file_number' => 'MV File Number',
    ];
    $matched = [];
    $conflicts = [];
    $uncertain = false;
    foreach ($labels as $key => $label) {
        $expected = normalize_vehicle_identifier((string)($vehicleIdentity[$key] ?? ''));
        if ($expected === '') continue;
        $candidates = [];
        if (!empty($documentData[$key])) $candidates[] = ['value' => (string)$documentData[$key], 'manual' => false];
        if ($key === 'plate_number' && trim((string)$manualPlate) !== '') $candidates[] = ['value' => (string)$manualPlate, 'manual' => true];
        foreach ($candidates as $candidate) {
            if ($confidence < 0.8 && !$candidate['manual']) {
                $uncertain = true;
                continue;
            }
            $actual = normalize_vehicle_identifier($candidate['value']);
            if ($actual === '') continue;
            if (hash_equals($expected, $actual)) {
                $matched[$key] = $label;
            } else {
                $conflicts[$key] = $label;
            }
        }
    }

    if ($matched && $conflicts) {
        $status = 'NEEDS_REVIEW';
        $reason = 'Conflicting vehicle identifiers need review: ' . implode(', ', array_values($conflicts)) . '.';
    } elseif ($conflicts) {
        $status = 'MISMATCHED';
        $reason = 'Uploaded ' . ($documentType === 'insurance' ? 'Insurance' : 'LTFRB Permit') . ' document does not match this vehicle (' . implode(', ', array_values($conflicts)) . ').';
    } elseif ($matched && (!$uncertain || trim((string)$manualPlate) !== '')) {
        $status = 'MATCHED';
        $reason = (trim((string)$manualPlate) !== '' ? 'Manually confirmed ' : 'Matched ')
            . implode(', ', array_values($matched)) . '.';
    } else {
        $status = 'NEEDS_REVIEW';
        $reason = $uncertain
            ? 'Vehicle identifier confidence is too low to verify this document.'
            : 'TourSphere could not reliably verify that this document belongs to this vehicle.';
    }

    return [
        'status' => $status,
        'reason' => $reason,
        'matched_identifiers' => array_values($matched),
        'conflicting_identifiers' => array_values($conflicts),
    ];
}

function read_vehicle_document_text(string $path, string $mime): ?string
{
    if ($mime !== 'application/pdf') return null;

    require_once ROOT_PATH . '/vendor/autoload.php';
    try {
        return (new Smalot\PdfParser\Parser())->parseFile($path)->getText();
    } catch (Throwable $e) {
        return '';
    }
}

function vehicle_document_is_test_fixture(string $text): bool
{
    return preg_match('/\b(?:FICTIONAL|NON[- ]OFFICIAL|NOT VALID FOR .* USE|TEST(?:ING)? ONLY|TEST COPY|NO INSURANCE COVERAGE)\b/i', $text) === 1;
}

function verify_vehicle_document_type(string $text, string $expectedType): array
{
    $normalized = strtoupper(normalize_vehicle_document_text($text));
    $patterns = [
        'registration' => [
            '/\bCERTIFICATE OF REGISTRATION\b/',
            '/\bMOTOR VEHICLE CERTIFICATE\b/',
            '/\bMOTOR VEHICLE REGISTRATION\b/',
            '/\bREGISTRATION DOCUMENT\b/',
            '/\bOR\s*\/?\s*CR\b/',
        ],
        'insurance' => [
            '/\bMOTOR VEHICLE INSURANCE\b/',
            '/\bINSURANCE CERTIFICATE\b/',
            '/\bINSURANCE PROVIDER\b/',
            '/\bINSURANCE COMPANY\b/',
            '/\bPOLICY\s*(?:NO\.?|NUMBER|#)\b/',
            '/\bPERIOD OF INSURANCE\b/',
            '/\bCOVERAGE TYPE\b/',
        ],
        'ltfrb_permit' => [
            '/\bLTFRB\b/',
            '/\bCPC\s*(?:NO\.?|NUMBER|#)\b/',
            '/\bCPC\s*\/\s*PERMIT\s*(?:NO\.?|NUMBER|#)\b/',
            '/\bCERTIFICATE OF PUBLIC CONVENIENCE\b/',
            '/\bPUBLIC CONVENIENCE\b/',
            '/\bSPECIAL PERMIT\b/',
            '/\bPERMIT NUMBER\b/',
        ],
    ];
    if (!isset($patterns[$expectedType])) {
        throw new InvalidArgumentException('Unsupported vehicle document type.');
    }
    if (trim($normalized) === '') {
        return ['status' => 'NEEDS_REVIEW', 'detected_type' => null, 'reason' => 'TourSphere could not reliably identify this document type.'];
    }

    $signalCounts = [];
    foreach ($patterns as $type => $typePatterns) {
        $signalCounts[$type] = 0;
        foreach ($typePatterns as $pattern) {
            if (preg_match($pattern, $normalized)) $signalCounts[$type]++;
        }
    }
    $expectedSignals = $signalCounts[$expectedType];
    $otherSignals = array_filter($signalCounts, static fn(int $count, string $type): bool => $type !== $expectedType && $count > 0, ARRAY_FILTER_USE_BOTH);
    $strongOtherTypes = array_filter($otherSignals, static fn(int $count): bool => $count >= 2);
    if ($expectedType !== 'registration' && $signalCounts['registration'] > 0) {
        return [
            'status' => 'MISMATCHED',
            'detected_type' => 'registration',
            'reason' => 'This document contains a Registration/OR-CR marker and cannot be accepted as ' . ($expectedType === 'insurance' ? 'Insurance' : 'LTFRB Permit') . '.',
        ];
    }
    if ($expectedType === 'registration' && $signalCounts['ltfrb_permit'] > 0) {
        return [
            'status' => 'MISMATCHED',
            'detected_type' => 'ltfrb_permit',
            'reason' => 'This document contains an LTFRB/CPC/permit marker and cannot be accepted as a Vehicle Registration Document.',
        ];
    }
    if ($expectedType === 'registration' && $signalCounts['insurance'] >= 2) {
        return [
            'status' => 'MISMATCHED',
            'detected_type' => 'insurance',
            'reason' => 'This document contains Insurance certificate/policy markers and cannot be accepted as a Vehicle Registration Document.',
        ];
    }
    $minimumSignals = $expectedType === 'registration' ? 1 : 2;
    $hasConflictingSignals = $expectedType !== 'registration' && (bool)$otherSignals;
    if ($expectedSignals >= $minimumSignals && !$hasConflictingSignals) {
        return ['status' => 'MATCHED', 'detected_type' => $expectedType, 'reason' => 'Document type confirmed.'];
    }
    if ($expectedSignals < $minimumSignals && count($strongOtherTypes) === 1) {
        $detectedType = (string)array_key_first($strongOtherTypes);
        $labels = ['registration' => 'Registration', 'insurance' => 'Insurance', 'ltfrb_permit' => 'LTFRB Permit'];
        return [
            'status' => 'MISMATCHED',
            'detected_type' => $detectedType,
            'reason' => 'This document appears to be ' . $labels[$detectedType] . ', not ' . $labels[$expectedType] . '.',
        ];
    }
    return [
        'status' => 'NEEDS_REVIEW',
        'detected_type' => $otherSignals ? 'ambiguous' : null,
        'reason' => $otherSignals
            ? 'Conflicting document-type indicators need review.'
            : 'TourSphere could not reliably confirm this document type from multiple document-specific indicators.',
    ];
}

function normalize_vehicle_document_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/\t+| {2,}/', "\n", $text);
    return preg_replace('/\n{2,}/', "\n", $text);
}

function vehicle_document_labeled_value(string $text, array $labels): ?string
{
    $labelPattern = implode('|', array_map(static fn(string $label): string => preg_quote($label, '/'), $labels));
    $text = normalize_vehicle_document_text($text);
    if (!preg_match('/^\h*(?:' . $labelPattern . ')\h*\.?\h*(?:NO\.?|NUMBER|#)?\h*[:\-]?\h*(?:\R\h*)?([^\r\n]{2,120})\h*$/im', $text, $match)) {
        return null;
    }
    $value = trim($match[1]);
    return $value !== '' ? $value : null;
}

function vehicle_document_labeled_date(string $text, array $labels): ?string
{
    $value = vehicle_document_labeled_value($text, $labels);
    if (!$value) {
        return null;
    }

    if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $value, $match)) {
        $year = (int)$match[1];
        $month = (int)$match[2];
        $day = (int)$match[3];
    } elseif (preg_match('/\b(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})\b/', $value, $match)) {
        $first = (int)$match[1];
        $second = (int)$match[2];
        $year = (int)$match[3];
        if ($first > 12 && $second <= 12) {
            $day = $first;
            $month = $second;
        } elseif ($second > 12 && $first <= 12) {
            $month = $first;
            $day = $second;
        } else {
            return null;
        }
    } elseif (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]+)\s+(\d{4})\b/i', $value, $match)) {
        $parsed = DateTimeImmutable::createFromFormat('!j F Y', $match[1] . ' ' . $match[2] . ' ' . $match[3]);
        if (!$parsed) $parsed = DateTimeImmutable::createFromFormat('!j M Y', $match[1] . ' ' . $match[2] . ' ' . $match[3]);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) return null;
        return $parsed->format('Y-m-d');
    } else {
        return null;
    }

    return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
}

function extract_vehicle_document_data(string $path, string $mime, string $documentType): array
{
    $text = read_vehicle_document_text($path, $mime);
    if ($text === null || trim($text) === '') {
        return [
            'data' => [],
            'status' => 'needs_review',
            'confidence' => 0,
        ];
    }
    $text = normalize_vehicle_document_text($text);
    $isTestFixture = vehicle_document_is_test_fixture($text);

    $data = [];
    if ($documentType === 'registration') {
        $plateText = strtoupper(preg_replace('/\s+/', ' ', $text));
        foreach ([
            '/\bPLATE\s*(?:NUMBER|NO\.?|#)?\s*[:\-]?\s*([A-Z]{2,4})[\s\-]*([0-9]{3,4})\b/',
            '/\bREGISTRATION\s*(?:NUMBER|NO\.?|#)?\s*[:\-]?\s*([A-Z]{2,4})[\s\-]*([0-9]{3,4})\b/',
        ] as $pattern) {
            if (preg_match($pattern, $plateText, $match)) {
                $data['plate_number'] = normalize_vehicle_plate($match[1] . '-' . $match[2]);
                break;
            }
        }
        $combinedMakeModel = vehicle_document_labeled_value($text, ['MAKE / MODEL', 'MAKE/MODEL']);
        if ($combinedMakeModel) $data['make_model_combined'] = $combinedMakeModel;
        $registrationFields = [
            'mv_file_number' => ['MV FILE NUMBER', 'MV FILE NO', 'MV FILE #'],
            'engine_number' => ['ENGINE NUMBER', 'ENGINE NO', 'ENGINE #'],
            'chassis_number' => ['CHASSIS NUMBER', 'CHASSIS NO', 'CHASSIS #'],
            'brand_make' => ['BRAND / MAKE', 'BRAND/MAKE', 'MAKE / BRAND', 'MAKE/BRAND', 'MAKE', 'BRAND'],
            'series_model' => ['SERIES / MODEL', 'SERIES/MODEL', 'SERIES', 'MODEL'],
            'body_type' => ['BODY TYPE', 'VEHICLE TYPE'],
            'fuel_type' => ['FUEL TYPE', 'FUEL'],
            'passenger_capacity' => ['PASSENGER CAPACITY', 'SEATING CAPACITY', 'NUMBER OF PASSENGERS'],
            'color' => ['COLOR', 'COLOUR'],
            'classification' => ['CLASSIFICATION'],
        ];
        foreach ($registrationFields as $key => $labels) {
            if ($value = vehicle_document_labeled_value($text, $labels)) $data[$key] = $value;
        }
        if ($combinedMakeModel) {
            if (($data['brand_make'] ?? '') === '/ Model') unset($data['brand_make']);
            if (($data['series_model'] ?? '') === '/ Model') unset($data['series_model']);
        }
        if (isset($data['passenger_capacity'])) {
            if (preg_match('/^\s*(\d{1,3})\b/', $data['passenger_capacity'], $capacityMatch)
                && (int)$capacityMatch[1] >= 1 && (int)$capacityMatch[1] <= 100) {
                $data['passenger_capacity'] = (int)$capacityMatch[1];
            } else {
                unset($data['passenger_capacity']);
            }
        }
        if (isset($data['fuel_type'])) {
            $fuelType = strtolower(trim((string)$data['fuel_type']));
            $fuelTypes = [
                'diesel' => 'Diesel',
                'gasoline' => 'Gasoline',
                'petrol' => 'Gasoline',
                'hybrid' => 'Hybrid',
                'electric' => 'Electric',
            ];
            if (isset($fuelTypes[$fuelType])) {
                $data['fuel_type'] = $fuelTypes[$fuelType];
            } else {
                unset($data['fuel_type']);
            }
        }
        if (preg_match('/\b(?:YEAR\s*MODEL|MODEL\s*YEAR|YEAR)\s*[:\-]?\s*(?:\n\s*)?((?:19|20)\d{2})\b/i', $text, $yearMatch)) {
            $data['year_model'] = (int)$yearMatch[1];
        }
        $data['expiration_date'] = vehicle_document_labeled_date($text, ['EXPIRATION DATE', 'EXPIRY DATE', 'VALID UNTIL', 'VALID THROUGH']);
        if ($data['expiration_date'] === null) unset($data['expiration_date']);
    } elseif ($documentType === 'insurance') {
        $data = array_merge($data, extract_vehicle_identifiers_from_text($text));
        foreach ([
            'provider' => ['INSURANCE PROVIDER', 'INSURANCE COMPANY', 'INSURER'],
            'policy_number' => ['POLICY NUMBER', 'POLICY NO', 'POLICY #'],
        ] as $key => $labels) {
            if ($value = vehicle_document_labeled_value($text, $labels)) $data[$key] = $value;
        }
        foreach ([
            'effective_date' => ['EFFECTIVE DATE', 'COVERAGE START', 'POLICY START DATE'],
            'expiration_date' => ['EXPIRATION DATE', 'EXPIRY DATE', 'VALID UNTIL', 'VALID THROUGH', 'COVERAGE END'],
        ] as $key => $labels) {
            if ($value = vehicle_document_labeled_date($text, $labels)) $data[$key] = $value;
        }
    } elseif ($documentType === 'ltfrb_permit') {
        $data = array_merge($data, extract_vehicle_identifiers_from_text($text));
        foreach ([
            'permit_number' => ['CPC NUMBER', 'CPC NO', 'CPC #', 'PERMIT NUMBER', 'PERMIT NO', 'PERMIT #'],
        ] as $key => $labels) {
            if ($value = vehicle_document_labeled_value($text, $labels)) $data[$key] = $value;
        }
        foreach ([
            'effective_date' => ['EFFECTIVE DATE', 'VALID FROM'],
            'expiration_date' => ['EXPIRATION DATE', 'EXPIRY DATE', 'VALID UNTIL', 'VALID THROUGH'],
        ] as $key => $labels) {
            if ($value = vehicle_document_labeled_date($text, $labels)) $data[$key] = $value;
        }
    } else {
        throw new InvalidArgumentException('Unsupported vehicle document type.');
    }
    if ($isTestFixture) $data['test_fixture'] = true;

    $status = !$data ? 'not_detected' : 'extracted';
    if ($documentType === 'registration' && empty($data['plate_number'])) $status = 'needs_review';
    return [
        'data' => $data,
        'status' => $status,
        'confidence' => $data ? 0.95 : 0,
    ];
}

function extract_vehicle_plate(string $path, string $mime): array
{
    $result = extract_vehicle_document_data($path, $mime, 'registration');
    $data = $result['data'];
    $patterns = [
        'plate_number' => $data['plate_number'] ?? null,
        'brand_make' => $data['brand_make'] ?? null,
        'series_model' => $data['series_model'] ?? null,
        'year_model' => $data['year_model'] ?? null,
        'vehicle_class' => $data['body_type'] ?? $data['classification'] ?? null,
        'document_data' => $data,
        'extraction_status' => $result['status'],
        'confidence' => $result['confidence'],
    ];
    return $patterns;
}

function vehicle_document_compliance_summary(string $documentType, array $data): string
{
    $isTestFixture = !empty($data['test_fixture']);
    if ($documentType === 'registration') {
        $parts = ['Registered'];
        foreach ([
            'mv_file_number' => 'MV File No.',
            'engine_number' => 'Engine No.',
            'chassis_number' => 'Chassis No.',
            'expiration_date' => 'Exp',
        ] as $key => $label) {
            if (!empty($data[$key])) $parts[] = $label . ' ' . trim((string)$data[$key]);
        }
        $summary = implode(' - ', $parts);
        return $isTestFixture ? 'TEST ONLY — ' . $summary : $summary;
    }

    $parts = [];
    if ($documentType === 'insurance') {
        if (!empty($data['provider'])) $parts[] = trim((string)$data['provider']);
        if (!empty($data['policy_number'])) $parts[] = 'Policy ' . trim((string)$data['policy_number']);
        if (!empty($data['expiration_date'])) $parts[] = 'Exp ' . trim((string)$data['expiration_date']);
    } elseif ($documentType === 'ltfrb_permit') {
        if (!empty($data['permit_number'])) $parts[] = 'CPC No. ' . trim((string)$data['permit_number']);
        if (!empty($data['expiration_date'])) $parts[] = 'Exp ' . trim((string)$data['expiration_date']);
    }
    $summary = $parts ? implode(' - ', $parts) : '—';
    if (!$isTestFixture && ($data['vehicle_match_status'] ?? '') !== 'MATCHED') {
        $summary = $summary === '—' ? 'Needs vehicle match review' : $summary . ' - Needs vehicle match review';
    }
    return $isTestFixture ? 'TEST ONLY — ' . $summary : $summary;
}
