<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_specs.php';
require_once ROOT_PATH . '/includes/vehicle_efficiency.php';
require_once ROOT_PATH . '/includes/vehicle_photo.php';
require_once ROOT_PATH . '/includes/vehicle_document.php';
// Fuel type options feed the Settings fuel price list, so the dependency is exercised here too.
require_once ROOT_PATH . '/includes/trip_funding.php';
require_once __DIR__ . '/support/document_pdf.php';

$checks = 0;
function cargo_check(bool $value, string $label): void
{
    global $checks;
    if (!$value) throw new RuntimeException($label);
    $checks++;
}
function cargo_reject(array $input, string $label): void
{
    try { vehicle_cargo_spec_validate($input); } catch (InvalidArgumentException $e) { cargo_check(true, $label); return; }
    cargo_check(false, $label);
}
function cargo_sql_reject(PDO $pdo, string $sql, array $params, string $label): void
{
    try { $pdo->prepare($sql)->execute($params); } catch (PDOException $e) { cargo_check(true, $label); return; }
    cargo_check(false, $label);
}

$pdo = db();

// ---------------------------------------------------------------------------
// 1. Cargo specification validation
// ---------------------------------------------------------------------------
$specs = vehicle_cargo_spec_validate([
    'payload_capacity_kg' => '1,250.5', 'cargo_volume_m3' => '6.5',
    'cargo_length_m' => '2.5', 'cargo_width_m' => '1.8', 'cargo_height_m' => '1.7',
]);
cargo_check($specs['payload_capacity_kg'] === 1250.5, 'Comma formatted payload accepted');
cargo_check($specs['cargo_volume_m3'] === 6.5, 'Cargo volume accepted');
cargo_check(abs($specs['computed_volume_m3'] - 7.65) < 0.001, 'Length × Width × Height computed');
cargo_check(vehicle_cargo_specs_note($specs) === null, 'Volume inside tolerance accepted without a note');
$mismatched = vehicle_cargo_spec_validate(['payload_capacity_kg' => '1500', 'cargo_volume_m3' => '3', 'cargo_length_m' => '2.5', 'cargo_width_m' => '1.8', 'cargo_height_m' => '1.7']);
cargo_check(vehicle_cargo_specs_note($mismatched) !== null, 'Volume cross-check note raised on mismatch');
$close = vehicle_cargo_spec_validate(['payload_capacity_kg' => '1500', 'cargo_volume_m3' => '6.9', 'cargo_length_m' => '2.5', 'cargo_width_m' => '1.8', 'cargo_height_m' => '1.7']);
cargo_check(vehicle_cargo_specs_note($close) === null, 'Close volume accepted without a note');
$noDimensions = vehicle_cargo_spec_validate(['payload_capacity_kg' => '1500', 'cargo_volume_m3' => '6.5']);
cargo_check($noDimensions['cargo_length_m'] === null && vehicle_cargo_specs_note($noDimensions) === null, 'Dimensions remain optional');
cargo_reject(['cargo_volume_m3' => '6.5'], 'Payload is required');
cargo_reject(['payload_capacity_kg' => '1500'], 'Cargo volume is required');
cargo_reject(['payload_capacity_kg' => '0', 'cargo_volume_m3' => '6.5'], 'Zero payload rejected');
cargo_reject(['payload_capacity_kg' => '-50', 'cargo_volume_m3' => '6.5'], 'Negative payload rejected');
cargo_reject(['payload_capacity_kg' => '60001', 'cargo_volume_m3' => '6.5'], 'Payload above maximum rejected');
cargo_reject(['payload_capacity_kg' => 'heavy', 'cargo_volume_m3' => '6.5'], 'Non numeric payload rejected');
cargo_reject(['payload_capacity_kg' => '1500', 'cargo_volume_m3' => '201'], 'Volume above maximum rejected');
cargo_reject(['payload_capacity_kg' => '1500', 'cargo_volume_m3' => '6.5', 'cargo_length_m' => '2.5'], 'Partial dimensions rejected');
cargo_reject(['payload_capacity_kg' => '1500', 'cargo_volume_m3' => '6.5', 'cargo_length_m' => '0', 'cargo_width_m' => '1.8', 'cargo_height_m' => '1.7'], 'Zero dimension rejected');

// ---------------------------------------------------------------------------
// 2. Cargo catalog and configurable fuel options
// ---------------------------------------------------------------------------
$cargoTypes = array_column(vehicle_cargo_types($pdo), 'name');
$expectedTypes = ['Box Truck', 'Cargo Van', 'Delivery Van', 'Heavy Truck', 'Light Truck', 'Medium Truck', 'Pickup Truck'];
cargo_check($cargoTypes === $expectedTypes, 'Add Vehicle offers exactly the seven cargo vehicle types');
cargo_check((int)$pdo->query("SELECT COUNT(*) FROM vehicle_types WHERE service_category = 'Passenger' AND status = 'Active'")->fetchColumn() === 5, 'Passenger vehicle types retained');
foreach ($cargoTypes as $name) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicle_models m JOIN vehicle_types t ON t.id = m.vehicle_type_id
                            WHERE t.name = ? AND t.service_category = 'Cargo' AND m.status = 'Active'");
    $stmt->execute([$name]);
    cargo_check((int)$stmt->fetchColumn() > 0, 'Cargo type has an active catalog model: ' . $name);
}
$fuelOptions = vehicle_fuel_type_options($pdo);
foreach (['Diesel', 'Gasoline', 'LPG', 'CNG', 'LNG', 'Biodiesel'] as $fuel) {
    cargo_check(in_array($fuel, $fuelOptions, true), 'Configurable fuel option available: ' . $fuel);
}
cargo_check(vehicle_fuel_type_supported($pdo, 'Diesel'), 'Configured fuel type accepted');
cargo_check(!vehicle_fuel_type_supported($pdo, 'Coal'), 'Unconfigured fuel type rejected');
cargo_check(!vehicle_fuel_type_supported($pdo, ''), 'Empty fuel type rejected');
$pdo->exec("UPDATE fuel_types SET status = 'Inactive' WHERE name = 'Biodiesel'");
cargo_check(!vehicle_fuel_type_supported($pdo, 'Biodiesel'), 'Retired fuel option no longer accepted');
$pdo->exec("UPDATE fuel_types SET status = 'Active' WHERE name = 'Biodiesel'");

// ---------------------------------------------------------------------------
// 3. Existing passenger vehicles and history remain compatible
// ---------------------------------------------------------------------------
cargo_check((int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE service_category = 'Passenger'")->fetchColumn() >= 15, 'Existing vehicles remain passenger records');
cargo_check(
    $pdo->query("SELECT COUNT(*) FROM vehicles WHERE service_category = 'Passenger'")->fetchColumn()
    === $pdo->query("SELECT COUNT(*) FROM vehicles WHERE service_category = 'Passenger' AND capacity IS NOT NULL")->fetchColumn(),
    'Passenger seat counts preserved'
);
$legacy = $pdo->query("SELECT * FROM vehicles WHERE service_category = 'Passenger' ORDER BY id LIMIT 1")->fetch();
$legacySummary = vehicle_specs_summary($legacy);
cargo_check($legacySummary['category'] === 'Passenger' && str_contains($legacySummary['primary'], 'Seats'), 'Passenger vehicles still display seats');

// ---------------------------------------------------------------------------
// 4. Cargo vehicle creation, constraints, and display values
// ---------------------------------------------------------------------------
$catalog = $pdo->query("SELECT m.id AS model_id, m.model_name, m.brand_id, b.name AS brand_name,
                               t.id AS type_id, t.name AS type_name
                          FROM vehicle_models m
                          JOIN vehicle_types t ON t.id = m.vehicle_type_id AND t.service_category = 'Cargo' AND t.status = 'Active'
                          JOIN vehicle_brands b ON b.id = m.brand_id AND b.status = 'Active'
                         WHERE m.status = 'Active'
                         ORDER BY m.id LIMIT 1")->fetch();
cargo_check($catalog !== false, 'Cargo catalog offers a registerable type, brand, and model');
$variantStmt = $pdo->prepare("SELECT id, payload_capacity_kg, cargo_volume_m3, fuel_tank_capacity FROM vehicle_variants WHERE model_id = ? AND status = 'Active' LIMIT 1");
$variantStmt->execute([$catalog['model_id']]);
$variant = $variantStmt->fetch();
cargo_check($variant !== false && (float)$variant['payload_capacity_kg'] > 0, 'Cargo variant supplies auto-fill specifications');

$vid = 'CARGO-' . strtoupper(bin2hex(random_bytes(4)));
$savedSpecs = vehicle_cargo_spec_validate([
    'payload_capacity_kg' => '2500', 'cargo_volume_m3' => '18.5',
    'cargo_length_m' => '4.2', 'cargo_width_m' => '2.1', 'cargo_height_m' => '2.1',
]);
try {
    vehicle_cargo_insert($pdo, [
        'id' => $vid, 'plate_number' => $vid, 'type' => $catalog['type_name'], 'brand' => $catalog['brand_name'],
        'model' => $catalog['model_name'], 'year' => 2026, 'fuel_type' => 'Diesel',
        'fuel_capacity' => $variant['fuel_tank_capacity'], 'efficiency' => vehicle_efficiency_default($catalog['type_name']),
        'variant_id' => $variant['id'], 'created_by' => null, 'registration_document' => null, 'specs' => $savedSpecs,
    ]);
    $row = $pdo->prepare('SELECT * FROM vehicles WHERE id = ?');
    $row->execute([$vid]);
    $row = $row->fetch();
    cargo_check($row !== false, 'Cargo vehicle saved through the shared insert');
    cargo_check($row['service_category'] === 'Cargo', 'Cargo service category persisted');
    cargo_check($row['capacity'] === null, 'Cargo vehicle stores no passenger seat count');
    cargo_check(abs((float)$row['payload_capacity_kg'] - 2500.0) < 0.001, 'Payload persisted');
    cargo_check(abs((float)$row['cargo_volume_m3'] - 18.5) < 0.001, 'Cargo volume persisted');
    cargo_check(abs((float)$row['cargo_length_m'] - 4.2) < 0.001 && abs((float)$row['cargo_width_m'] - 2.1) < 0.001
        && abs((float)$row['cargo_height_m'] - 2.1) < 0.001, 'Cargo dimensions persisted');
    cargo_check(vehicle_efficiency_default($catalog['type_name']) !== '', 'Cargo planning efficiency configured');
    $summary = vehicle_specs_summary($row);
    cargo_check($summary['category'] === 'Cargo', 'Cargo summary category');
    cargo_check(str_contains($summary['primary'], '2,500 kg payload'), 'Payload formatted for the directory');
    cargo_check(str_contains((string)$summary['secondary'], '18.5 m³'), 'Cargo volume shown beside payload');
    cargo_check($summary['dimensions'] === '4.2 × 2.1 × 2.1 m', 'Dimensions formatted as Length × Width × Height');
    cargo_check(vehicle_photo_present($vid, vehicle_photo_records($pdo, [$vid])[$vid] ?? [])['source'] === 'placeholder', 'Cargo vehicle without an upload shows the clean placeholder');

    try {
        vehicle_cargo_insert($pdo, [
            'id' => $vid . 'X', 'plate_number' => $vid, 'type' => $catalog['type_name'], 'brand' => $catalog['brand_name'],
            'model' => $catalog['model_name'], 'year' => 2026, 'fuel_type' => 'Diesel', 'specs' => $savedSpecs,
        ]);
        cargo_check(false, 'Duplicate plate rejected');
    } catch (PDOException $duplicate) {
        cargo_check(true, 'Duplicate plate rejected');
    }

    $bad = 'CARGO-BAD-' . strtoupper(bin2hex(random_bytes(3)));
    cargo_sql_reject($pdo,
        'INSERT INTO vehicles (id, plate_number, type, brand, model, year, capacity, service_category, payload_capacity_kg) VALUES (?,?,?,?,?,?,NULL,?,?)',
        [$bad, $bad, 'Cargo Van', 'Toyota', 'HiAce Cargo', 2026, 'Cargo', 70000],
        'Payload above the database limit rejected');
    cargo_sql_reject($pdo,
        'INSERT INTO vehicles (id, plate_number, type, brand, model, year, capacity, service_category, cargo_length_m) VALUES (?,?,?,?,?,?,NULL,?,?)',
        [$bad, $bad, 'Cargo Van', 'Toyota', 'HiAce Cargo', 2026, 'Cargo', 4.2],
        'Partial cargo dimensions rejected by the database');
    cargo_sql_reject($pdo,
        'INSERT INTO vehicles (id, plate_number, type, brand, model, year, capacity, service_category) VALUES (?,?,?,?,?,?,NULL,?)',
        [$bad, $bad, 'Cargo Van', 'Toyota', 'HiAce Cargo', 2026, 'Freight'],
        'Unknown service category rejected by the database');
} finally {
    // Keep the append-only audit trail free of fixture rows created by this test.
    $pdo->exec('SET session_replication_role = replica');
    try {
        $pdo->prepare('DELETE FROM vehicles WHERE id = ?')->execute([$vid]);
        $pdo->prepare("DELETE FROM audit_logs WHERE entity_id = ?")->execute([$vid]);
    } finally {
        $pdo->exec('SET session_replication_role = origin');
    }
}

// ---------------------------------------------------------------------------
// 4b. Fuel type extraction, confirmation, and display
// ---------------------------------------------------------------------------
$fuelOptions = vehicle_fuel_type_options($pdo);
foreach (['Diesel', 'Gasoline', 'LPG', 'CNG', 'LNG', 'Biodiesel', 'Ethanol / Flex Fuel', 'Other'] as $expectedFuel) {
    cargo_check(in_array($expectedFuel, $fuelOptions, true), 'Configured fuel option available: ' . $expectedFuel);
}
foreach (['LPG', 'Ethanol / Flex Fuel', 'Other'] as $newFuel) {
    cargo_check(in_array($newFuel, fuel_supported_types($pdo), true), 'New fuel type can be priced before any vehicle uses it: ' . $newFuel);
}
cargo_check(!vehicle_fuel_type_supported($pdo, 'Hydrogen'), 'A fuel outside the configured list is not accepted');
cargo_check(vehicle_fuel_type_label('LPG') === 'LPG / Autogas', 'Autogas wording is shown while the stored value stays LPG');
cargo_check(vehicle_fuel_type_label('Other (Hydrogen)') === 'Other (Hydrogen)', 'A described Other fuel is displayed as stored');
cargo_check(vehicle_fuel_type_label(null) === 'Not recorded', 'A missing fuel type reads as Not recorded');

// Only a fuel the document states outright is extracted; ambiguity returns nothing so the Admin chooses.
$fuelExtractionCases = [
    ['DIESEL', 'Diesel'], ['Diesel (Turbo)', 'Diesel'], ['TURBO DIESEL', 'Diesel'], ['CRDI', 'Diesel'],
    ['BIO-DIESEL', 'Biodiesel'], ['BIODIESEL', 'Biodiesel'], ['B100', 'Biodiesel'],
    ['GASOLINE', 'Gasoline'], ['Petrol', 'Gasoline'], ['UNLEADED', 'Gasoline'], ['GAS / PETROL', 'Gasoline'],
    ['AUTOGAS', 'LPG'], ['AUTO GAS', 'LPG'], ['LPG', 'LPG'], ['PROPANE', 'LPG'],
    ['CNG', 'CNG'], ['COMPRESSED NATURAL GAS', 'CNG'],
    ['LNG', 'LNG'], ['LIQUEFIED NATURAL GAS', 'LNG'],
    ['ETHANOL', 'Ethanol / Flex Fuel'], ['E10', 'Ethanol / Flex Fuel'], ['FLEX FUEL', 'Ethanol / Flex Fuel'],
    ['ELECTRIC', 'Electric'], ['EV', 'Electric'], ['HYBRID', 'Hybrid'],
    ['', null], ['   ', null], ['N/A', null], ['NATURAL GAS', null], ['DUAL FUEL', null],
    ['DIESEL / GASOLINE', null], ['DIESEL AND LPG', null], ['PETROL OR DIESEL', null],
];
foreach ($fuelExtractionCases as [$rawFuel, $expectedFuel]) {
    cargo_check(
        vehicle_document_normalize_fuel_type($rawFuel) === $expectedFuel,
        'Document fuel extraction: "' . $rawFuel . '" -> ' . ($expectedFuel ?? 'nothing, Admin chooses')
    );
}

cargo_check(!vehicle_fuel_type_confirmed([]), 'Fuel type starts unconfirmed');
cargo_check(vehicle_fuel_type_confirmed(['fuel_type_confirmed' => '1']), 'Admin confirmation is detected');
cargo_check(vehicle_fuel_type_source(['fuel_type_source' => 'document']) === 'document', 'Document fuel source recorded for audit');
cargo_check(vehicle_fuel_type_source(['fuel_type_source' => 'made-up']) === 'manual', 'An unknown fuel source falls back to manual');
cargo_check(vehicle_fuel_type_source(['fuel_type_source' => 'variant']) === 'manual', 'A catalog variant is never recorded as the fuel source');

$fuelRejections = [
    'no selection' => [],
    'a fuel outside the configured list' => ['fuel_type' => 'Hydrogen'],
    'an over-long Other description' => ['fuel_type' => 'Other', 'fuel_type_other' => str_repeat('x', VEHICLE_FUEL_OTHER_DETAIL_MAX + 1)],
];
foreach ($fuelRejections as $case => $fuelInput) {
    try {
        vehicle_fuel_type_resolve($pdo, $fuelInput);
        cargo_check(false, 'Fuel submission rejected with ' . $case);
    } catch (InvalidArgumentException $fuelError) {
        cargo_check(true, 'Fuel submission rejected with ' . $case);
    }
}
cargo_check(vehicle_fuel_type_resolve($pdo, ['fuel_type' => 'LPG']) === 'LPG', 'A selected fuel keeps its canonical stored name');
cargo_check(vehicle_fuel_type_resolve($pdo, ['fuel_type' => 'Other', 'fuel_type_other' => 'Hydrogen']) === 'Other (Hydrogen)', 'Other stores the Admin description');
cargo_check(vehicle_fuel_type_resolve($pdo, ['fuel_type' => 'Other', 'fuel_type_other' => '   ']) === 'Other', 'Other without a description stores the plain Other value');
cargo_check(strlen(vehicle_fuel_type_resolve($pdo, ['fuel_type' => 'Other', 'fuel_type_other' => str_repeat('x', VEHICLE_FUEL_OTHER_DETAIL_MAX)])) <= 30, 'A maximum-length Other value fits the fuel column');

// ---------------------------------------------------------------------------
// 5. Form, action, and illustration cleanup wiring
// ---------------------------------------------------------------------------
$action = (string)file_get_contents(ROOT_PATH . '/actions/vehicle.php');
$form = (string)file_get_contents(ROOT_PATH . '/modules/fleet-vehicle-management/vehicle-directory.php');
cargo_check(str_contains($action, 'vehicle_cargo_spec_validate'), 'Add Vehicle action validates cargo specifications');
cargo_check(!str_contains($action, 'Passenger capacity must be between'), 'Action no longer requires passenger capacity');
cargo_check(!str_contains($action, 'vehicle-samples'), 'Action no longer seeds passenger illustrations');
cargo_check(!str_contains($form, 'name="capacity"'), 'Form no longer submits passenger capacity');
cargo_check(str_contains($form, 'name="payload_capacity_kg"') && str_contains($form, 'name="cargo_volume_m3"'), 'Form submits payload and cargo volume');
cargo_check(str_contains($form, 'name="cargo_length_m"') && str_contains($form, 'name="cargo_width_m"') && str_contains($form, 'name="cargo_height_m"'), 'Form submits cargo dimensions');
cargo_check(str_contains($form, 'vehicle_cargo_types($pdo)'), 'Form lists cargo vehicle types only');
cargo_check(str_contains($form, '$fuel_types'), 'Form renders configurable fuel options');
cargo_check(str_contains($form, 'fuel_type_confirmed') && str_contains($form, 'name="fuel_type_other"'), 'Form requires fuel confirmation and offers the Other description');
cargo_check(str_contains($form, 'name="fuel_type_source"'), 'Form reports whether the fuel value was extracted or chosen');
cargo_check(str_contains($action, 'vehicle_fuel_type_confirmed'), 'Add Vehicle action requires the fuel confirmation');
cargo_check(str_contains($action, 'vehicle_fuel_type_resolve'), 'Add Vehicle action resolves the submitted fuel type');
$fuelScript = (string)file_get_contents(ROOT_PATH . '/js/vehicle-registration.js');
cargo_check(!str_contains($fuelScript, 'item.fuel_type') && !str_contains($fuelScript, "'variant'"), 'Fuel type is never pre-filled from the catalog variant, brand, or model');
cargo_check(str_contains($fuelScript, 'fuel_type_confirmed') && str_contains($fuelScript, 'fuel_type_other'), 'Add Vehicle script drives the confirmation and Other controls');
$documentInclude = (string)file_get_contents(ROOT_PATH . '/includes/vehicle_document.php');
cargo_check(str_contains($documentInclude, 'vehicle_document_normalize_fuel_type'), 'Document extraction uses the shared fuel normaliser');
cargo_check(str_contains($documentInclude, "'TYPE OF FUEL'") && str_contains($documentInclude, "'FUEL USED'"), 'Document extraction also reads the alternate fuel labels');
cargo_check(!preg_match('/fuel_type\s*=>\s*\$?vehicle[a-z_]*(brand|model|type)/i', (string)file_get_contents(ROOT_PATH . '/includes/vehicle_specs.php')), 'Fuel type is never derived from brand, model, or vehicle type');
$directoryTable = (string)file_get_contents(ROOT_PATH . '/modules/fleet-vehicle-management/vehicle-directory.php');
cargo_check(substr_count($directoryTable, 'vehicle_fuel_type_label') >= 2, 'Directory and details display the fuel type label');
cargo_check(!str_contains($action, 'sample_filename') && !str_contains((string)file_get_contents(ROOT_PATH . '/includes/vehicle_photo.php'), 'vehicle_photo_seed_sample'), 'Sample illustration code removed');
$assetWarning = is_dir(ROOT_PATH . '/assets/images/vehicle-samples')
    ? 'WARNING: passenger illustration assets still present but unreferenced.'
    : '';

// ---------------------------------------------------------------------------
// 6. Live Add Vehicle submission through the browser-facing interface
// ---------------------------------------------------------------------------
$base = getenv('TOURSPHERE_URL') ?: 'http://localhost/fleet';
$directoryUrl = $base . '/modules/fleet-vehicle-management/vehicle-directory.php';
$httpChecks = 0;
$httpNote = '';

function cargo_http(string $url, ?string $sessionId = null, ?array $post = null): array
{
    $ch = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25];
    if ($sessionId) $options[CURLOPT_COOKIE] = 'PHPSESSID=' . $sessionId;
    if ($post !== null) { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = $post; }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'headers' => substr((string)$raw, 0, $headerSize), 'body' => substr((string)$raw, $headerSize), 'error' => $error];
}

// Registration fixture writer lives in tests/support/document_pdf.php.
function cargo_registration_pdf(array $lines): string
{
    return toursphere_test_document_pdf($lines);
}

$sessionId = null;
$pdfPath = null;
$createdVehicleId = null;
try {
    $probe = cargo_http($base . '/login.php');
    if ($probe['status'] !== 200) {
        $httpNote = 'SKIPPED live submission: ' . $base . ' unreachable (' . ($probe['error'] ?: 'HTTP ' . $probe['status']) . ').';
    } else {
        $roleStmt = $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'fleet_admin' AND u.status = 'Active' LIMIT 1");
        $adminId = (int)$roleStmt->fetchColumn();
        cargo_check($adminId > 0, 'Fleet admin test account available');
        $sessionId = bin2hex(random_bytes(24));
        session_id($sessionId);
        session_start();
        $_SESSION = ['user_id' => $adminId, 'last_valid_activity' => time()];
        session_write_close();

        $page = cargo_http($directoryUrl, $sessionId);
        cargo_check($page['status'] === 200, 'Add Vehicle directory loads for a fleet administrator');
        cargo_check(str_contains($page['body'], 'name="payload_capacity_kg"') && str_contains($page['body'], 'Maximum Payload Capacity (kg)'), 'Live form renders the payload field');
        cargo_check(str_contains($page['body'], 'name="cargo_volume_m3"') && str_contains($page['body'], 'Cargo Volume (m³)'), 'Live form renders the cargo volume field');
        cargo_check(substr_count($page['body'], 'name="cargo_length_m"') === 1 && str_contains($page['body'], 'Cargo Dimensions (Length × Width × Height)'), 'Live form renders cargo dimensions');
        cargo_check(!str_contains($page['body'], 'name="capacity"'), 'Live form no longer renders passenger capacity');
        foreach (['Cargo Van', 'Delivery Van', 'Pickup Truck', 'Light Truck', 'Medium Truck', 'Heavy Truck', 'Box Truck'] as $typeName) {
            cargo_check(str_contains($page['body'], '>' . $typeName . '</option>'), 'Live form vehicle type: ' . $typeName);
        }
        foreach (['Diesel', 'Gasoline', 'LPG', 'CNG', 'LNG', 'Biodiesel', 'Ethanol / Flex Fuel', 'Other'] as $fuel) {
            $optionValue = $fuel;
            $optionText = $fuel === 'LPG' ? 'LPG / Autogas' : $fuel;
            cargo_check(str_contains($page['body'], '<option value="' . $optionValue . '">' . $optionText . '</option>'), 'Live form fuel option: ' . $optionText);
        }
        cargo_check(substr_count($page['body'], 'name="fuel_type_confirmed"') === 1, 'Live form renders exactly one fuel confirmation control');
        cargo_check(substr_count($page['body'], 'name="fuel_type_other"') === 1, 'Live form renders exactly one Other fuel description field');
        cargo_check(str_contains($page['body'], 'vehicle-placeholder.svg'), 'Directory shows the clean placeholder');
        cargo_check(!str_contains($page['body'], 'vehicle-samples'), 'Directory no longer serves built-in illustrations');
        cargo_check(preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page['body'], $csrf) === 1, 'Form CSRF token available');

        // Standard three-letter/four-digit plate so the registration OCR pattern matches.
        // Each case posts a freshly rendered document and returns the redirect plus the stored row.
        $createdVehicleIds = [];
        $fixturePdfs = [];
        $submitVehicle = function (string $plate, string $fuelLine, array $fuelFields) use ($base, $directoryUrl, $sessionId, $catalog, $variant, $pdo, &$createdVehicleIds, &$fixturePdfs): array {
            // The form token is single use, so every submission renders the form again first.
            $form = cargo_http($directoryUrl, $sessionId);
            if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $form['body'], $token)) {
                throw new RuntimeException('Could not obtain a fresh Add Vehicle form token.');
            }
            $pdfPath = ROOT_PATH . '/tmp/cargo-registration-fixture-' . bin2hex(random_bytes(4)) . '.pdf';
            $lines = [
                'REPUBLIC OF THE PHILIPPINES',
                'LAND TRANSPORTATION OFFICE',
                'CERTIFICATE OF REGISTRATION',
                'TEST ONLY - FICTIONAL DOCUMENT FOR TOURSPHERE TESTING',
                'PLATE NUMBER: ' . $plate,
                'MV FILE NUMBER: TEST-MV-99-000009',
                'ENGINE NUMBER: TEST-ENG-CRG-000009',
                'CHASSIS NUMBER: TEST-CHS-CRG-000009',
                'MAKE / MODEL: ' . $catalog['brand_name'] . ' ' . $catalog['model_name'],
                'SERIES / MODEL: ' . $catalog['model_name'],
                'BODY TYPE: CARGO TRUCK',
                'YEAR MODEL: 2026',
                'MAXIMUM PAYLOAD: 2,500 KG',
                'CARGO VOLUME: 18.5 M3',
                'COLOR: WHITE',
                'CLASSIFICATION: CARGO',
            ];
            if ($fuelLine !== '') $lines[] = (str_contains($fuelLine, '/') ? 'TYPE OF FUEL' : 'FUEL TYPE') . ': ' . $fuelLine;
            file_put_contents($pdfPath, cargo_registration_pdf($lines));
            $fixturePdfs[] = $pdfPath;
            $submit = cargo_http($base . '/actions/vehicle.php', $sessionId, array_merge([
                'action' => 'create',
                'return' => $directoryUrl,
                'csrf_token' => $token[1],
                'vehicle_type_id' => (string)$catalog['type_id'],
                'brand_id' => (string)$catalog['brand_id'],
                'model_id' => (string)$catalog['model_id'],
                'variant_id' => (string)$variant['id'],
                'year' => '2026',
                'payload_capacity_kg' => '2500',
                'cargo_volume_m3' => '18.5',
                'cargo_length_m' => '4.2',
                'cargo_width_m' => '2.1',
                'cargo_height_m' => '2.1',
                'plate_number' => $plate,
                'registration_manual_review_confirm' => '1',
                'registration_document' => new CURLFile($pdfPath, 'application/pdf', 'cargo-registration-fixture.pdf'),
            ], $fuelFields));
            cargo_check($submit['status'] === 302, 'Add Vehicle submission is handled through the HTTP interface');
            preg_match('/^Location:\s*(.+)$/mi', $submit['headers'], $locationMatch);
            $redirectQuery = [];
            parse_str((string)parse_url(trim($locationMatch[1] ?? ''), PHP_URL_QUERY), $redirectQuery);
            $found = $pdo->prepare("SELECT * FROM vehicles WHERE plate_number = ? AND service_category = 'Cargo'");
            $found->execute([$plate]);
            $row = $found->fetch();
            if ($row) $createdVehicleIds[] = $row['id'];
            return [$redirectQuery, $row];
        };
        $refusal = static function (array $query): string {
            return rawurldecode((string)($query['msg'] ?? 'no message returned'));
        };

        $before = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE service_category = 'Cargo'")->fetchColumn();

        // Case A: the document states DIESEL and the Admin confirms the selection.
        $plate = 'CRG-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        [$redirectQuery, $createdRow] = $submitVehicle($plate, 'DIESEL', ['fuel_type' => 'Diesel', 'fuel_type_source' => 'manual', 'fuel_type_confirmed' => '1']);
        if (($redirectQuery['type'] ?? '') !== 'success') {
            throw new RuntimeException('Live registration was refused: ' . $refusal($redirectQuery));
        }
        cargo_check(true, 'Add Vehicle submission reported success');
        $createdVehicleId = $createdRow['id'] ?? null;
        cargo_check($createdRow !== false, 'Submitted cargo vehicle stored');
        cargo_check(abs((float)$createdRow['payload_capacity_kg'] - 2500.0) < 0.001
            && abs((float)$createdRow['cargo_volume_m3'] - 18.5) < 0.001
            && abs((float)$createdRow['cargo_height_m'] - 2.1) < 0.001, 'Submitted cargo specifications saved');
        cargo_check($createdRow['capacity'] === null, 'Submitted cargo vehicle has no passenger capacity');
        cargo_check($createdRow['type'] === $catalog['type_name'] && $createdRow['brand'] === $catalog['brand_name'], 'Catalog selection saved');
        cargo_check($createdRow['fuel_type'] === 'Diesel', 'Configured fuel type saved');
        cargo_check($createdRow['reg_document'] !== null, 'Registration document linked to the vehicle');
        $documentStmt = $pdo->prepare('SELECT COUNT(*) FROM vehicle_documents WHERE vehicle_id = ?');
        $documentStmt->execute([$createdVehicleId]);
        cargo_check((int)$documentStmt->fetchColumn() === 1, 'Registration document recorded once');
        $auditStmt = $pdo->prepare("SELECT details FROM audit_logs WHERE entity_type = 'vehicle' AND entity_id = ? AND action = 'VEHICLE_REGISTERED'");
        $auditStmt->execute([$createdVehicleId]);
        $auditDetails = json_decode((string)$auditStmt->fetchColumn(), true) ?: [];
        cargo_check(($auditDetails['service_category'] ?? null) === 'Cargo', 'Audit trail records the cargo registration');
        cargo_check(($auditDetails['fuel_type'] ?? null) === 'Diesel' && ($auditDetails['fuel_type_confirmed'] ?? null) === true, 'Audit trail records the confirmed fuel type');

        // Case B: the document states AUTOGAS; the Admin accepts the extracted value.
        $autogasPlate = 'CRG-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        [$autogasQuery, $autogasRow] = $submitVehicle($autogasPlate, 'AUTOGAS', ['fuel_type' => 'LPG', 'fuel_type_source' => 'document', 'fuel_type_confirmed' => '1']);
        if (($autogasQuery['type'] ?? '') !== 'success') {
            throw new RuntimeException('Autogas registration was refused: ' . $refusal($autogasQuery));
        }
        cargo_check(($autogasRow['fuel_type'] ?? '') === 'LPG', 'Autogas stated on the document is stored as the canonical LPG');
        $autogasDocument = $pdo->prepare('SELECT extracted_data FROM vehicle_documents WHERE vehicle_id = ?');
        $autogasDocument->execute([$autogasRow['id']]);
        $autogasExtraction = json_decode((string)$autogasDocument->fetchColumn(), true) ?: [];
        cargo_check(($autogasExtraction['fuel_type'] ?? null) === 'LPG', 'Uploaded document stores the extracted LPG value');
        $auditStmt->execute([$autogasRow['id']]);
        $autogasAudit = json_decode((string)$auditStmt->fetchColumn(), true) ?: [];
        cargo_check(($autogasAudit['fuel_type_source'] ?? null) === 'document', 'Audit trail records that the fuel type came from the document');

        // Case C: the Admin never confirms, so the vehicle is refused and nothing is stored.
        $unconfirmedPlate = 'CRG-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        [$unconfirmedQuery, $unconfirmedRow] = $submitVehicle($unconfirmedPlate, 'DIESEL', ['fuel_type' => 'Diesel', 'fuel_type_source' => 'manual']);
        cargo_check(($unconfirmedQuery['type'] ?? '') === 'danger', 'Registration without fuel confirmation is refused');
        cargo_check(str_contains(strtolower($refusal($unconfirmedQuery)), 'fuel type'), 'Refusal names the fuel type confirmation');
        cargo_check($unconfirmedRow === false, 'No vehicle is created without the fuel confirmation');

        // Case D: the document is ambiguous and the Admin records the fuel through Other.
        $otherPlate = 'CRG-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        [$otherQuery, $otherRow] = $submitVehicle($otherPlate, 'DIESEL / GASOLINE', ['fuel_type' => 'Other', 'fuel_type_other' => 'Hydrogen', 'fuel_type_source' => 'manual', 'fuel_type_confirmed' => '1']);
        if (($otherQuery['type'] ?? '') !== 'success') {
            throw new RuntimeException('Other fuel registration was refused: ' . $refusal($otherQuery));
        }
        cargo_check(($otherRow['fuel_type'] ?? '') === 'Other (Hydrogen)', 'An unclear document fuel is replaced by the Admin Other entry');
        cargo_check(vehicle_fuel_type_label($otherRow['fuel_type']) === 'Other (Hydrogen)', 'The stored Other fuel is displayed to the Admin');

        $after = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE service_category = 'Cargo'")->fetchColumn();
        cargo_check($after === $before + 3, 'Only the three confirmed live submissions created cargo vehicles');

        $httpChecks = 1;
    }
} finally {
    foreach ($createdVehicleIds ?? [] as $createdVehicleId) {
        $paths = $pdo->prepare('SELECT stored_filename FROM vehicle_documents WHERE vehicle_id = ?');
        $paths->execute([$createdVehicleId]);
        foreach ($paths->fetchAll(PDO::FETCH_COLUMN) as $stored) {
            $file = ROOT_PATH . '/storage/private/vehicle-documents/' . $stored;
            if (is_file($file)) unlink($file);
        }
        // audit_logs is append-only by trigger; replica mode is scoped to this cleanup session so the
        // test removes its own fixture rows without touching any production audit record.
        $pdo->exec('SET session_replication_role = replica');
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM vehicle_documents WHERE vehicle_id = ?')->execute([$createdVehicleId]);
            $pdo->prepare('DELETE FROM vehicle_photos WHERE vehicle_id = ?')->execute([$createdVehicleId]);
            $pdo->prepare('DELETE FROM audit_logs WHERE entity_id = ?')->execute([$createdVehicleId]);
            $pdo->prepare('DELETE FROM vehicles WHERE id = ?')->execute([$createdVehicleId]);
            $pdo->commit();
        } finally {
            $pdo->exec('SET session_replication_role = origin');
        }
    }
    foreach ($fixturePdfs ?? [] as $pdfPath) {
        if (is_file($pdfPath)) unlink($pdfPath);
    }
    if ($sessionId) { session_id($sessionId); session_start(); $_SESSION = []; session_destroy(); }
}

if ($assetWarning !== '') echo $assetWarning . "\n";
echo $httpChecks
    ? "$checks cargo registration checks passed, including a live Add Vehicle submission.\n"
    : "$checks cargo registration checks passed. $httpNote\n";
