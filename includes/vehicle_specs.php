<?php
/**
 * Logistics 2 cargo specification rules shared by the Add Vehicle action, the vehicle
 * directory, and tests. Cargo registration replaces passenger capacity in the form;
 * passenger vehicles keep their existing seat-count fields for historical display.
 */

const VEHICLE_PAYLOAD_MAX_KG        = 60000.0;
const VEHICLE_CARGO_VOLUME_MAX_M3   = 200.0;
const VEHICLE_CARGO_DIMENSION_MAX_M = 30.0;
const VEHICLE_CARGO_VOLUME_TOLERANCE = 0.20;

/** Normalise one optional/required numeric cargo field from submitted form data. */
function vehicle_specs_number(array $input, string $key, string $label, float $min, float $max, bool $required): ?float
{
    $raw = $input[$key] ?? null;
    if (is_array($raw)) throw new InvalidArgumentException($label . ' must be a single number.');
    $value = is_string($raw) ? str_replace([',', ' '], '', trim($raw)) : $raw;
    if ($value === null || $value === '' || $value === false) {
        if ($required) throw new InvalidArgumentException($label . ' is required.');
        return null;
    }
    if (!is_numeric($value)) throw new InvalidArgumentException($label . ' must be a number.');
    $number = (float)$value;
    if (!is_finite($number) || $number <= 0) throw new InvalidArgumentException($label . ' must be greater than zero.');
    if ($number < $min || $number > $max) {
        throw new InvalidArgumentException(sprintf(
            '%s must be between %s and %s.',
            $label,
            vehicle_specs_format_number($min),
            vehicle_specs_format_number($max)
        ));
    }
    return $number;
}

/** Trim display zeros without losing precision: 1250.00 -> 1,250 and 6.500 -> 6.5. */
function vehicle_specs_format_number(float $value, int $maxDecimals = 3): string
{
    $text = rtrim(rtrim(number_format($value, $maxDecimals, '.', ','), '0'), '.');
    return $text === '' || $text === '-' ? '0' : $text;
}

/**
 * Validate the cargo specifications submitted with Add Vehicle.
 * Payload and cargo volume are required; the three dimensions are optional but
 * must be entered together. Returns the normalised specification values.
 */
function vehicle_cargo_spec_validate(array $input): array
{
    $payload = vehicle_specs_number($input, 'payload_capacity_kg', 'Maximum Payload Capacity (kg)', 0.01, VEHICLE_PAYLOAD_MAX_KG, true);
    $volume  = vehicle_specs_number($input, 'cargo_volume_m3', 'Cargo Volume (m³)', 0.001, VEHICLE_CARGO_VOLUME_MAX_M3, true);
    $length  = vehicle_specs_number($input, 'cargo_length_m', 'Cargo Length (m)', 0.01, VEHICLE_CARGO_DIMENSION_MAX_M, false);
    $width   = vehicle_specs_number($input, 'cargo_width_m', 'Cargo Width (m)', 0.01, VEHICLE_CARGO_DIMENSION_MAX_M, false);
    $height  = vehicle_specs_number($input, 'cargo_height_m', 'Cargo Height (m)', 0.01, VEHICLE_CARGO_DIMENSION_MAX_M, false);
    if ((($length !== null) ? 1 : 0) + (($width !== null) ? 1 : 0) + (($height !== null) ? 1 : 0) === 0) {
        $length = $width = $height = null;
    } elseif ($length === null || $width === null || $height === null) {
        throw new InvalidArgumentException('Entered cargo dimensions must include Length, Width, and Height together.');
    }

    $gvw = vehicle_specs_number($input, 'gross_vehicle_weight_kg', 'Gross Vehicle Weight (kg)', 1, 100000, false);
    if($gvw !== null && $payload >= $gvw) throw new InvalidArgumentException('Payload must be lower than gross vehicle weight.');
    $computed = $length !== null ? $length * $width * $height : null;
    return [
        'payload_capacity_kg' => $payload,
        'cargo_volume_m3'     => $volume,
        'cargo_length_m'      => $length,
        'cargo_width_m'       => $width,
        'cargo_height_m'      => $height,
        'gross_vehicle_weight_kg' => $gvw,
        'requires_trailer_license' => ($input['requires_trailer_license'] ?? '') === '1',
        'computed_volume_m3'  => $computed,
        'volume_variance'     => $computed !== null && $computed > 0 ? abs($volume - $computed) / $computed : null,
    ];
}

/** Non-blocking note when the stated volume differs from Length × Width × Height. */
function vehicle_cargo_specs_note(array $specs): ?string
{
    $variance = $specs['volume_variance'] ?? null;
    if ($variance === null || $variance <= VEHICLE_CARGO_VOLUME_TOLERANCE) return null;
    return 'Stated cargo volume differs from Length × Width × Height by more than '
        . (int)round(VEHICLE_CARGO_VOLUME_TOLERANCE * 100) . '% (computed '
        . vehicle_specs_format_number((float)$specs['computed_volume_m3']) . ' m³).';
}

/** Length × Width × Height label, or null when the vehicle has no recorded dimensions. */
function vehicle_specs_dimensions_label(array $vehicle): ?string
{
    $length = $vehicle['cargo_length_m'] ?? null;
    $width  = $vehicle['cargo_width_m'] ?? null;
    $height = $vehicle['cargo_height_m'] ?? null;
    if ($length === null || $width === null || $height === null) return null;
    return vehicle_specs_format_number((float)$length, 2) . ' × '
        . vehicle_specs_format_number((float)$width, 2) . ' × '
        . vehicle_specs_format_number((float)$height, 2) . ' m';
}

function vehicle_specs_is_cargo(array $vehicle): bool
{
    return ($vehicle['service_category'] ?? 'Passenger') === 'Cargo';
}

/**
 * One-line specification summary for the directory and profile payloads. Cargo units show
 * payload/volume/dimensions; passenger units keep the original seat-count display.
 */
function vehicle_specs_summary(array $vehicle): array
{
    $cargo = vehicle_specs_is_cargo($vehicle) || !empty($vehicle['payload_capacity_kg']);
    if (!$cargo) {
        return [
            'category' => 'Passenger',
            'primary' => (int)($vehicle['capacity'] ?? 0) . ' Seats',
            'secondary' => null,
            'dimensions' => null,
        ];
    }
    $secondary = [];
    if (!empty($vehicle['cargo_volume_m3'])) {
        $secondary[] = vehicle_specs_format_number((float)$vehicle['cargo_volume_m3']) . ' m³';
    }
    $dimensions = vehicle_specs_dimensions_label($vehicle);
    if ($dimensions !== null) $secondary[] = $dimensions;
    return [
        'category' => 'Cargo',
        'primary' => !empty($vehicle['payload_capacity_kg'])
            ? vehicle_specs_format_number((float)$vehicle['payload_capacity_kg']) . ' kg payload'
            : 'Payload not recorded',
        'secondary' => $secondary ? implode(' · ', $secondary) : null,
        'dimensions' => $dimensions,
    ];
}

/**
 * Persist a Logistics 2 cargo vehicle. Kept beside the specification rules so the Add Vehicle
 * action and its tests always save the same columns; passenger capacity is never set here.
 */
function vehicle_cargo_insert(PDO $pdo, array $vehicle): void
{
    $pdo->prepare(
        'INSERT INTO vehicles (id, plate_number, type, brand, model, year, capacity, status,
                               fuel_type, fuel_capacity, current_fuel, odometer, maintenance_status,
                               location, total_trips, total_km, avg_fuel_km, operating_cost_km,
                               variant_id, created_by, reg_document, service_category,
                               payload_capacity_kg, cargo_volume_m3,
                               cargo_length_m, cargo_width_m, cargo_height_m)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 100, 0, ?, ?, 0, 0, ?, ?, ?, ?, ?, \'Cargo\', ?, ?, ?, ?, ?)'
    )->execute([
        $vehicle['id'], $vehicle['plate_number'], $vehicle['type'], $vehicle['brand'],
        $vehicle['model'], $vehicle['year'],
        'Available', $vehicle['fuel_type'], $vehicle['fuel_capacity'] ?? null,
        'Healthy', 'Central Depot', $vehicle['efficiency'] ?? '—', '—',
        $vehicle['variant_id'] ?? null, $vehicle['created_by'] ?? null,
        $vehicle['registration_document'] ?? null,
        $vehicle['specs']['payload_capacity_kg'], $vehicle['specs']['cargo_volume_m3'],
        $vehicle['specs']['cargo_length_m'], $vehicle['specs']['cargo_width_m'],
        $vehicle['specs']['cargo_height_m'],
    ]);
}

/** Active cargo vehicle types offered by the Add Vehicle form. */
function vehicle_cargo_types(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, name FROM vehicle_types
          WHERE status = 'Active' AND service_category = 'Cargo'
          ORDER BY name"
    )->fetchAll();
}

/** Configurable fuel options; retire one by setting fuel_types.status = 'Inactive'. */
function vehicle_fuel_type_options(PDO $pdo): array
{
    return $pdo->query(
        "SELECT name FROM fuel_types WHERE status = 'Active' ORDER BY sort_order, name"
    )->fetchAll(PDO::FETCH_COLUMN);
}

function vehicle_fuel_type_supported(PDO $pdo, string $name): bool
{
    if ($name === '') return false;
    $stmt = $pdo->prepare("SELECT 1 FROM fuel_types WHERE name = ? AND status = 'Active'");
    $stmt->execute([$name]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Display wording for a stored fuel value. Canonical names stay unchanged in the database
 * so existing records and fuel-price lookups keep matching; only the label differs.
 */
function vehicle_fuel_type_label(?string $name): string
{
    $name = trim((string)$name);
    if ($name === '') return 'Not recorded';
    $labels = ['LPG' => 'LPG / Autogas', 'Ethanol / Flex Fuel' => 'Ethanol / Flex Fuel'];
    return $labels[$name] ?? $name;
}

/** Longest fuel description allowed beside the "Other" option (vehicles.fuel_type is 30 chars). */
const VEHICLE_FUEL_OTHER_DETAIL_MAX = 22;

/** Whether the Admin confirmed the fuel type on the submitted form. */
function vehicle_fuel_type_confirmed(array $input): bool
{
    return ($input['fuel_type_confirmed'] ?? '') === '1';
}

/**
 * Where the submitted fuel value came from, for the audit trail.
 * Only a document reading or the Admin's own selection counts; a catalog variant never does.
 */
function vehicle_fuel_type_source(array $input): string
{
    $source = trim((string)($input['fuel_type_source'] ?? ''));
    return $source === 'document' ? 'document' : 'manual';
}

/**
 * Resolve the submitted fuel type to the value stored on the vehicle.
 * "Other" stores the Admin's short description, falling back to the literal Other.
 */
function vehicle_fuel_type_resolve(PDO $pdo, array $input): string
{
    $selected = trim((string)($input['fuel_type'] ?? ''));
    if ($selected === '') {
        throw new InvalidArgumentException('Select the fuel type before registering the vehicle.');
    }
    if ($selected === 'Other') {
        $detail = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', '', (string)($input['fuel_type_other'] ?? '')));
        $detail = trim((string)preg_replace('/\s+/', ' ', $detail));
        if ($detail === '') return 'Other';
        // Byte length is what the varchar(30) column stores; "Other (" plus ")" costs 8 characters.
        if (strlen($detail) > VEHICLE_FUEL_OTHER_DETAIL_MAX) {
            throw new InvalidArgumentException('Describe the Other fuel type in ' . VEHICLE_FUEL_OTHER_DETAIL_MAX . ' characters or fewer.');
        }
        return 'Other (' . $detail . ')';
    }
    if (!vehicle_fuel_type_supported($pdo, $selected)) {
        throw new InvalidArgumentException('Select a supported fuel type from the configured fuel list.');
    }
    return $selected;
}
