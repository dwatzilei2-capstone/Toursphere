<?php
/** Shared visual identity only. Never used to determine operational eligibility. */
function vehicle_photo_storage(): string { return ROOT_PATH . '/storage/private/vehicle-photos'; }
function vehicle_photo_placeholder(): string { return BASE_URL . '/assets/images/vehicle-placeholder.svg'; }

function vehicle_photo_records(PDO $pdo, array $ids): array
{
    if (!$ids) return [];
    // Optional visuals remain safe during staged deployment and isolated operational tests.
    if (!$pdo->query("SELECT to_regclass('vehicle_photos')")->fetchColumn()) return [];
    $stmt = $pdo->prepare('SELECT * FROM vehicle_photos WHERE vehicle_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    $records = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $records[$row['vehicle_id']] = $row;
    return $records;
}

function vehicle_photo_actual_path(?string $filename): ?string
{
    if (!$filename || !preg_match('/^[a-f0-9]{48}\.(jpg|png|webp)$/D', $filename)) return null;
    $path = vehicle_photo_storage() . '/' . $filename;
    return is_file($path) && is_readable($path) ? $path : null;
}

function vehicle_photo_sample_path(?string $filename): ?string
{
    if (!$filename || !preg_match('/^[a-f0-9]{20}\.png$/D', $filename)) return null;
    $path = ROOT_PATH . '/assets/images/vehicle-samples/' . $filename;
    return is_file($path) && is_readable($path) ? $path : null;
}

function vehicle_photo_present(string $id, array $record = []): array
{
    $actual = vehicle_photo_actual_path($record['actual_filename'] ?? null);
    $sample = vehicle_photo_sample_path($record['sample_filename'] ?? null);
    $sampleUrl = $sample ? BASE_URL . '/assets/images/vehicle-samples/' . $record['sample_filename'] : null;
    $source = $actual ? 'actual' : ($sample ? 'sample' : 'placeholder');
    return [
        'vehicleId' => $id, 'source' => $source,
        'hasActual' => !empty($record['actual_filename']),
        'src' => $actual ? BASE_URL . '/actions/vehicle-photo.php?vehicle=' . rawurlencode($id) . '&v=' . rawurlencode($record['actual_filename']) : ($sampleUrl ?: vehicle_photo_placeholder()),
        'sampleSrc' => $sampleUrl, 'placeholderSrc' => vehicle_photo_placeholder(),
        'label' => $source === 'actual' ? 'Actual vehicle photo' : ($source === 'sample' ? 'Sample illustration — not actual fleet' : 'Vehicle photo unavailable'),
    ];
}

function vehicle_photo_html(array $photo, string $name, string $class = ''): string
{
    return '<span class="vehicle-photo ' . e($class) . '" data-photo-source="' . e($photo['source']) . '">' .
        '<img src="' . e($photo['src']) . '" alt="' . e($name . ' — ' . $photo['label']) . '" loading="lazy" decoding="async" onload="this.parentElement.dataset.photoLoaded=1" data-vehicle-photo data-source="' . e($photo['source']) . '" data-sample-src="' . e($photo['sampleSrc'] ?? '') . '" data-placeholder-src="' . e($photo['placeholderSrc']) . '" onerror="if(typeof App!==\'undefined\') App.handleVehiclePhotoError(this)">' .
        '<span class="vehicle-photo-label" title="' . e($photo['label']) . '">' . ($photo['source'] === 'sample' ? 'Sample' : ($photo['source'] === 'placeholder' ? 'No photo' : '')) . '</span></span>';
}

/** Validate the raster independently so tests do not need to bypass HTTP upload provenance. */
function vehicle_photo_validate_file(string $path, string $name): array
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $formats = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
    if (!isset($formats[$extension])) throw new InvalidArgumentException('Vehicle Photo must be JPG, JPEG, PNG, or WebP.');
    $size = is_file($path) ? filesize($path) : false;
    if (!$size || $size > 5 * 1024 * 1024) throw new InvalidArgumentException('Vehicle Photo must be no larger than 5 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $dimensions = @getimagesize($path);
    if ($mime !== $formats[$extension] || !$dimensions || ($dimensions['mime'] ?? '') !== $mime) throw new InvalidArgumentException('Vehicle Photo content does not match its image format.');
    if ($dimensions[0] > 12000 || $dimensions[1] > 12000 || $dimensions[0] * $dimensions[1] > 20000000) throw new InvalidArgumentException('Vehicle Photo dimensions are too large.');
    return ['extension'=>$extension === 'jpeg' ? 'jpg' : $extension, 'mime'=>$mime, 'size'=>$size, 'width'=>$dimensions[0], 'height'=>$dimensions[1]];
}

function vehicle_photo_validate_upload(array $upload, bool $optional = false): ?array
{
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($optional && $error === UPLOAD_ERR_NO_FILE) return null;
    if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException($error === UPLOAD_ERR_NO_FILE ? 'Select a Vehicle Photo.' : 'Vehicle Photo upload failed. Select an image up to 5 MB and try again.');
    if (!is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) throw new InvalidArgumentException('Vehicle Photo is not a valid upload.');
    return vehicle_photo_validate_file($upload['tmp_name'], (string)($upload['name'] ?? ''));
}

function vehicle_photo_store_upload(array $upload, array $validated): array
{
    $dir = vehicle_photo_storage();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Secure photo storage is unavailable.');
    $filename = bin2hex(random_bytes(24)) . '.' . $validated['extension'];
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $path)) throw new RuntimeException('Vehicle Photo could not be stored.');
    chmod($path, 0640);
    return $validated + ['filename'=>$filename, 'path'=>$path];
}

function vehicle_photo_save_actual(PDO $pdo, string $id, array $file, string $userId): void
{
    $pdo->prepare('INSERT INTO vehicle_photos (vehicle_id, actual_filename, actual_mime, actual_size, actual_width, actual_height, uploaded_by, uploaded_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, now()) ON CONFLICT (vehicle_id) DO UPDATE SET actual_filename=EXCLUDED.actual_filename,
        actual_mime=EXCLUDED.actual_mime, actual_size=EXCLUDED.actual_size, actual_width=EXCLUDED.actual_width,
        actual_height=EXCLUDED.actual_height, uploaded_by=EXCLUDED.uploaded_by, uploaded_at=now(), updated_at=now()')
        ->execute([$id,$file['filename'],$file['mime'],$file['size'],$file['width'],$file['height'],$userId]);
    $pdo->prepare("INSERT INTO audit_logs (action,user_id,entity_type,entity_id,details) VALUES ('VEHICLE_PHOTO_UPDATED',?,'vehicle',?,?)")
        ->execute([$userId,$id,json_encode(['mime'=>$file['mime'],'size'=>$file['size']])]);
}

function vehicle_photo_sample_key(array $vehicle): string
{
    return substr(hash('sha256', implode('|', array_map(static fn($field)=>(string)($vehicle[$field] ?? ''), ['brand','model','variant_name','type','year']))), 0, 20);
}

function vehicle_photo_seed_sample(PDO $pdo, array $vehicle, array $catalog): bool
{
    $key = vehicle_photo_sample_key($vehicle);
    foreach ($catalog as $sample) {
        if ($sample['key'] !== $key || !vehicle_photo_sample_path($sample['filename'])) continue;
        $pdo->prepare('INSERT INTO vehicle_photos (vehicle_id,sample_filename,sample_label) VALUES (?,?,?) ON CONFLICT (vehicle_id) DO UPDATE SET sample_filename=EXCLUDED.sample_filename,sample_label=EXCLUDED.sample_label,updated_at=now()')
            ->execute([$vehicle['id'],$sample['filename'],trim($vehicle['brand'].' '.$vehicle['model']).' — sample illustration']);
        return true;
    }
    return false;
}
