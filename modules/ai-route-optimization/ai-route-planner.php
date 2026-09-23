<?php
 



require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('ai.view');

 
 
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$pdo = db();

 
 
$is_driver_user = (($current_user['role_code'] ?? '') === 'driver');
$route_context = null;
$requested_trip_id = trim($_GET['trip_id'] ?? '');
$requested_reservation_id = trim($_GET['reservation_id'] ?? '');
$return_mode_requested = $is_driver_user && ($_GET['return'] ?? '') === '1';

if ($is_driver_user) {
    $driverTripSql =
        "SELECT t.id AS trip_id, t.reservation_id, t.origin, t.destination, t.waypoints,
                t.vehicle_id, t.driver_id, t.status AS trip_status, t.route_history_id, t.navigation_active,
                r.passenger_count, r.departure_date, r.departure_time
           FROM drivers d
           JOIN trips t ON t.driver_id = d.id
           LEFT JOIN reservations r ON r.id = t.reservation_id
          WHERE d.user_id = ? AND t.status IN ('Scheduled','Assigned','Dispatched','In Transit','Returning to Depot')";
    $driverTripParams = [$current_user['id']];
    if ($requested_trip_id !== '') {
        $driverTripSql .= ' AND t.id = ?';
        $driverTripParams[] = $requested_trip_id;
    }
    $driverTripSql .= " ORDER BY CASE WHEN t.status = 'In Transit' THEN 0 ELSE 1 END,
                       t.scheduled_departure NULLS LAST, t.created_at LIMIT 1";
    $contextStmt = $pdo->prepare($driverTripSql);
    $contextStmt->execute($driverTripParams);
    $route_context = $contextStmt->fetch() ?: null;
} elseif ($requested_trip_id !== '') {
    $contextStmt = $pdo->prepare(
        "SELECT t.id AS trip_id, t.reservation_id, t.origin, t.destination, t.waypoints,
                t.vehicle_id, t.driver_id, t.status AS trip_status, t.route_history_id, t.navigation_active,
                r.passenger_count, r.departure_date, r.departure_time
           FROM trips t LEFT JOIN reservations r ON r.id = t.reservation_id
          WHERE t.id = ? LIMIT 1"
    );
    $contextStmt->execute([$requested_trip_id]);
    $route_context = $contextStmt->fetch() ?: null;
} elseif ($requested_reservation_id !== '') {
    $contextStmt = $pdo->prepare(
        "SELECT t.id AS trip_id, r.id AS reservation_id, r.origin, r.destination,
                COALESCE(t.waypoints, '') AS waypoints, r.assigned_vehicle_id AS vehicle_id,
                r.assigned_driver_id AS driver_id, COALESCE(t.status, r.status) AS trip_status,
                t.route_history_id, t.navigation_active, r.passenger_count, r.departure_date, r.departure_time
           FROM reservations r LEFT JOIN trips t ON t.reservation_id = r.id
          WHERE r.id = ? LIMIT 1"
    );
    $contextStmt->execute([$requested_reservation_id]);
    $route_context = $contextStmt->fetch() ?: null;
}

$is_return_mode = $return_mode_requested && ($route_context['trip_status'] ?? '') === 'Returning to Depot';
$configured_depot_address = trim((string)(getenv('FLEET_DEPOT_ADDRESS') ?: ''));
$return_destination = $configured_depot_address !== '' ? $configured_depot_address : ($route_context['origin'] ?? '');
$context_origin = $is_return_mode ? ($route_context['destination'] ?? '') : ($route_context['origin'] ?? ($_GET['origin'] ?? ''));
$context_destination = $is_return_mode ? $return_destination : ($route_context['destination'] ?? ($_GET['destination'] ?? ''));
$context_vehicle_id = $route_context['vehicle_id'] ?? ($_GET['vehicle'] ?? '');
$context_waypoints = $is_return_mode
    ? []
    : array_values(array_filter(array_map('trim', preg_split('/[;\n]+/', $route_context['waypoints'] ?? '') ?: [])));
$driver_has_active_trip = !$is_driver_user || $route_context !== null;
$context_request_failed = ($requested_trip_id !== '' || $requested_reservation_id !== '') && $route_context === null;

$saved_route_mode = null;
if (!empty($route_context['route_history_id'])) {
    $savedRouteStmt = $pdo->prepare(
        'SELECT selected_mode, route_data_json FROM route_history WHERE log_id = ? LIMIT 1'
    );
    $savedRouteStmt->execute([$route_context['route_history_id']]);
    if ($savedRoute = $savedRouteStmt->fetch()) {
        $savedRouteData = json_decode((string)($savedRoute['route_data_json'] ?? ''), true);
        $candidateMode = is_array($savedRouteData) ? ($savedRouteData['mode'] ?? null) : null;
        if (in_array($candidateMode, ['balanced', 'fastest', 'fuelEfficient', 'shortest'], true)) {
            $saved_route_mode = $candidateMode;
        } else {
            $savedModeLabels = [
                'Balanced (ROUTETHINK)' => 'balanced',
                'Fastest Express Corridor' => 'fastest',
                'Eco-Optimized Fuel Efficient' => 'fuelEfficient',
                'Shortest Distance' => 'shortest',
            ];
            $saved_route_mode = $savedModeLabels[$savedRoute['selected_mode'] ?? ''] ?? null;
        }
    }
}

 
$fleet_vehicles = $pdo->query(
    "SELECT v.id, v.plate_number, v.type, v.brand, v.model, v.capacity, v.fuel_type, v.avg_fuel_km
       FROM vehicles v
      WHERE v.status <> 'Maintenance'
        AND NOT EXISTS (
            SELECT 1 FROM maintenance_orders mo
             WHERE mo.vehicle_id = v.id AND mo.status = 'In Repair'
        )
      ORDER BY v.type, v.brand, v.model"
)->fetchAll();

 
$presets = $pdo->query('SELECT * FROM route_presets ORDER BY id')->fetchAll();
$route_data = [];
foreach ($presets as $p) {
    $wps = $pdo->prepare('SELECT * FROM route_waypoints WHERE preset_id = ? ORDER BY sort_order');
    $wps->execute([$p['id']]);
    $waypoints = array_map(fn($w) => ['name' => $w['name'], 'coords' => [(float)$w['lat'], (float)$w['lng']]], $wps->fetchAll());

    $alts = $pdo->prepare('SELECT * FROM route_alternatives WHERE preset_id = ?');
    $alts->execute([$p['id']]);
    $alternatives = [];
    foreach ($alts->fetchAll() as $a) {
        $alternatives[$a['mode_key']] = [
            'title' => $a['title'],
            'distance' => $a['distance'],
            'duration' => $a['duration'],
            'fuelEstimate' => $a['fuel_estimate'],
            'tollEstimate' => $a['toll_estimate'],
            'totalTripCost' => $a['total_trip_cost'],
            'routeScore' => $a['route_score'],
            'reason' => $a['reason'],
        ];
    }

    $route_data[] = [
        'id' => $p['id'],
        'name' => $p['name'],
        'origin' => $p['origin'],
        'originCoords' => [(float)$p['origin_lat'], (float)$p['origin_lng']],
        'destination' => $p['destination'],
        'destinationCoords' => [(float)$p['dest_lat'], (float)$p['dest_lng']],
        'defaultWaypoints' => $waypoints,
        'passengerCount' => (int)$p['passenger_count'],
        'recommendedVehicle' => $p['recommended_vehicle'],
        'alternatives' => $alternatives,
    ];
}

 
$tourist_destinations = [
    [
        'id' => 'DEST-BAGUIO',
        'name' => 'Baguio City (Summer Capital)',
        'category' => 'Mountain & Heritage',
        'province' => 'Benguet',
        'coords' => [16.4023, 120.6080],
        'address' => 'Baguio Country Club, Baguio City, Benguet',
        'description' => 'Cool pine retreat, Mines View Park, Camp John Hay & Burnham Park.',
    ],
    [
        'id' => 'DEST-TAGAYTAY',
        'name' => 'Tagaytay Scenic Ridge & Taal Lake',
        'category' => 'Scenic & Leisure',
        'province' => 'Cavite',
        'coords' => [14.0950, 120.9320],
        'address' => 'Tagaytay Taal Vista Hotel, Tagaytay City, Cavite',
        'description' => 'Overlooking Taal Volcano with fresh climate and premier dining.',
    ],
    [
        'id' => 'DEST-INTRAMUROS',
        'name' => 'Intramuros Heritage District',
        'category' => 'Historical Heritage',
        'province' => 'Metro Manila',
        'coords' => [14.5895, 120.9747],
        'address' => 'Fort Santiago, Intramuros, Manila',
        'description' => 'Historic Spanish colonial walled city, San Agustin Church & Fort Santiago.',
    ],
    [
        'id' => 'DEST-VIGAN',
        'name' => 'Vigan Heritage Village (Calle Crisologo)',
        'category' => 'UNESCO World Heritage',
        'province' => 'Ilocos Sur',
        'coords' => [17.5747, 120.3869],
        'address' => 'Calle Crisologo, Vigan City, Ilocos Sur',
        'description' => 'Preserved 16th-century Spanish colonial architecture and cobblestone streets.',
    ],
    [
        'id' => 'DEST-BANAUE',
        'name' => 'Banaue Rice Terraces',
        'category' => 'Mountain & Nature',
        'province' => 'Ifugao',
        'coords' => [16.9130, 121.0594],
        'address' => 'Banaue Viewpoint, Banaue, Ifugao',
        'description' => '2,000-year-old terraces carved into the mountains by indigenous ancestors.',
    ],
    [
        'id' => 'DEST-SUBIC',
        'name' => 'Subic Bay Freeport Zone',
        'category' => 'Eco-Tourism & Coast',
        'province' => 'Zambales',
        'coords' => [14.8219, 120.2818],
        'address' => 'Ocean Adventure, Subic Bay Freeport, Zambales',
        'description' => 'Marine parks, jungle safari tours, duty-free shopping and coastal harbor.',
    ],
    [
        'id' => 'DEST-CLARK',
        'name' => 'Clark Freeport Zone & Aqua Planet',
        'category' => 'Leisure & Aviation',
        'province' => 'Pampanga',
        'coords' => [15.1840, 120.5360],
        'address' => 'Clark Freeport Zone, Mabalacat, Pampanga',
        'description' => 'Aviation gateway, water theme parks, golf courses and culinary dining.',
    ],
    [
        'id' => 'DEST-HUNDRED-ISLANDS',
        'name' => 'Hundred Islands National Park',
        'category' => 'Island & Marine',
        'province' => 'Pangasinan',
        'coords' => [16.2045, 120.0430],
        'address' => 'Lucap Wharf, Alaminos, Pangasinan',
        'description' => '124 mushroom-shaped islands with limestone caves, ziplining and snorkeling.',
    ],
    [
        'id' => 'DEST-MAYON',
        'name' => 'Mount Mayon & Cagsawa Ruins',
        'category' => 'Eco-Adventure',
        'province' => 'Albay',
        'coords' => [13.2570, 123.6850],
        'address' => 'Cagsawa Ruins Park, Daraga, Albay',
        'description' => 'World-renowned perfect cone volcano with ATV adventure trails.',
    ],
    [
        'id' => 'DEST-ANILAO',
        'name' => 'Anilao Marine Sanctuary & Dive Camp',
        'category' => 'Diving & Coast',
        'province' => 'Batangas',
        'coords' => [13.7580, 120.8930],
        'address' => 'Mabini, Anilao, Batangas',
        'description' => 'Premier coral reef diving and macro underwater photography sanctuary.',
    ],
    [
        'id' => 'DEST-SAGADA',
        'name' => 'Sagada Eco-Valley & Sumaguing Cave',
        'category' => 'Mountain & Cultural',
        'province' => 'Mountain Province',
        'coords' => [17.0833, 120.9022],
        'address' => 'Sagada Town Center, Mountain Province',
        'description' => 'Hanging coffins, spelunking, orange orchards and sea of clouds in Kiltepan.',
    ],
    [
        'id' => 'DEST-LAUNION',
        'name' => 'San Juan Surf & Beach Strip',
        'category' => 'Beach & Lifestyle',
        'province' => 'La Union',
        'coords' => [16.6667, 120.3250],
        'address' => 'Urbiztondo Beach, San Juan, La Union',
        'description' => 'Surfing capital of the North with trendy beachfront dining & resorts.',
    ]
];

$requested_mode = ($is_driver_user && $saved_route_mode !== null)
    ? $saved_route_mode
    : ($_GET['mode'] ?? 'balanced');
$initial_mode   = in_array($requested_mode, ['balanced', 'fastest', 'fuelEfficient', 'shortest'], true) ? $requested_mode : 'balanced';
 
 
 
$initial_preset = $is_driver_user ? '' : ($_GET['preset'] ?? '');

$google_maps_key = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : 'YOUR_GOOGLE_MAPS_API_KEY';
$is_key_placeholder = ($google_maps_key === 'YOUR_GOOGLE_MAPS_API_KEY' || empty($google_maps_key));

$active_page = 'ai-route-planner';
$page_title  = 'AI-Driven Route Planning & Optimization';
$body_class  = 'ai-route-planner-page';
$include_leaflet = false;
$include_map_route = true;
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <div class="d-flex align-items-center gap-2">
      <h1 class="mb-0">AI-Driven Route Planning & Optimization</h1>
      <span class="badge bg-primary-subtle text-primary border">Module 6</span>
      <span class="badge bg-dark-subtle text-dark border"><i class="bi bi-cpu me-1 text-primary"></i>ROUTETHINK Engine</span>
    </div>
    <p class="text-muted-custom mb-0">Multi-criteria route solver optimizing travel time, fuel burn, terrain gradients, toll tariffs, and real-world traffic via Google Maps.</p>
  </div>
  <div class="d-flex gap-2">
    <?php if (!$is_driver_user): ?>
    <?php if (has_role('fleet_admin')): ?>
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/settings.php?tab=ai-model-training"><i class="bi bi-cpu"></i> AI Model Training</a>
    <?php endif; ?>
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/ai-route-optimization/route-comparison.php"><i class="bi bi-table"></i> Compare Routes</a>
    <?php endif; ?>
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/ai-route-optimization/route-history.php"><i class="bi bi-clock-history"></i> Route History</a>
  </div>
</div>

<?php if ($is_key_placeholder): ?>
 
<div class="alert alert-warning alert-dismissible fade show d-flex align-items-center justify-content-between p-2 px-3 mb-3 border" role="alert">
  <div class="d-flex align-items-center gap-2 small">
    <i class="bi bi-key-fill fs-5 text-warning"></i>
    <div>
      <strong>Google Maps API Key Placeholder Active:</strong>
      To enable live Google Maps tiles, Places Autocomplete, and live traffic routing, open <code class="bg-white px-2 py-0 border rounded">config/maps.php</code> and set <code class="bg-white px-2 py-0 border rounded">GOOGLE_MAPS_API_KEY</code> to your Google Cloud API key.
    </div>
  </div>
  <button type="button" class="btn-close btn-sm p-2" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($is_driver_user): ?>
<div class="alert <?= $route_context ? 'alert-info' : 'alert-warning' ?> py-2 px-3 mb-3 border small" role="status">
  <i class="bi <?= $route_context ? 'bi-shield-lock-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
  <?php if ($route_context): ?>
    <?php if ($is_return_mode): ?>
      Return-to-Depot mode is active for <strong><?= e($route_context['trip_id']) ?></strong>. ROUTETHINK will plan from the passenger destination back to <?= $configured_depot_address !== '' ? 'the configured depot' : 'the official trip origin (depot address is not configured)' ?>.
    <?php else: ?>
      Driver trip mode is active for <strong><?= e($route_context['trip_id']) ?></strong>. Origin, destination, waypoints, and assigned vehicle are preloaded from Dispatch. No AI route is generated until you press <strong>Generate ROUTETHINK Route</strong>.
    <?php endif; ?>
  <?php else: ?>
    No active assigned trip is available. Route generation and navigation are disabled until Dispatch assigns a trip.
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($context_request_failed): ?>
<div class="alert alert-danger py-2 px-3 mb-3 border small" role="alert">
  <i class="bi bi-exclamation-octagon-fill me-2"></i>
  The selected trip could not be opened. It may be completed, cancelled, unassigned, or unavailable to this account.
</div>
<?php endif; ?>

 
<div class="ai-workspace-container">
   
  <div class="tc-card d-flex flex-column justify-content-between route-config-panel">
    <div>
      <div class="tc-card-header py-2 d-flex justify-content-between align-items-center">
        <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-sliders me-2 text-primary-custom"></i>Route Configuration</h4>
        <span class="badge bg-light text-muted-custom border" style="font-size: 10px;">ROUTETHINK v2.4</span>
      </div>
      <div class="p-3" style="max-height: calc(100vh - 270px); overflow-y: auto;">
         
        <div class="mb-3">
          <label class="tc-form-label d-flex justify-content-between align-items-center">
            <span>Tour Preset / Template</span>
            <span class="text-muted-custom" style="font-size: 10px;">Optional</span>
          </label>
          <select class="tc-form-select" id="route-preset-select" onchange="aiRouteEngine.setPreset(this.value)" <?= $is_driver_user ? 'disabled' : '' ?>>
            <option value="" <?= empty($initial_preset) ? 'selected' : '' ?>>-- Select Preset Template (Optional) --</option>
            <?php foreach ($route_data as $rd): ?>
              <option value="<?= e($rd['id']) ?>" <?= $rd['id'] === $initial_preset ? 'selected' : '' ?>><?= e($rd['name']) ?></option>
            <?php endforeach; ?>
            <option value="custom"><i class="bi bi-plus-circle"></i> Custom Route / Multi-Destination Itinerary</option>
          </select>
        </div>

         
        <div class="mb-3">
          <label class="tc-form-label d-flex justify-content-between align-items-center">
            <span><i class="bi bi-geo-alt-fill text-danger me-1"></i>Tourist Destination Quick-Pick</span>
            <span class="badge bg-secondary-subtle text-dark" style="font-size: 9px;"><?= count($tourist_destinations) ?> Spots</span>
          </label>
          <select class="tc-form-select tc-form-select-sm" id="tourist-destination-quickpick" onchange="aiRouteEngine.selectTouristDestination(this.value)" <?= $is_driver_user ? 'disabled' : '' ?>>
            <option value="">-- Choose a Popular Destination (Optional) --</option>
            <?php foreach ($tourist_destinations as $td): ?>
              <option value="<?= e($td['id']) ?>"><?= e($td['name']) ?> (<?= e($td['category']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

         
        <div class="mb-2">
          <label class="tc-form-label d-flex justify-content-between align-items-center">
            <span>Origin Departure Point <span class="text-danger">*</span></span>
            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small text-success" title="Detect GPS Location" onclick="aiRouteEngine.useCurrentLocationAsOrigin()" <?= $is_driver_user ? 'disabled' : '' ?>>
              <i class="bi bi-crosshair me-1"></i>My Location
            </button>
          </label>
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-light text-success"><i class="bi bi-geo-alt-fill"></i></span>
            <input type="text" class="form-control" id="route-origin-input" placeholder="Enter departure point or address..." value="<?= e($context_origin) ?>" autocomplete="off" <?= $is_driver_user ? 'readonly' : '' ?>>
          </div>
        </div>

         
        <div class="mb-2">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="tc-form-label mb-0">Waypoints & Intermediate Stops</label>
            <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size: 11px;" onclick="aiRouteEngine.addWaypointField()" <?= $is_driver_user ? 'disabled' : '' ?>>
              <i class="bi bi-plus-lg me-1"></i>Add Stop
            </button>
          </div>
          <div id="waypoints-container" class="d-flex flex-column gap-1">
             
          </div>
        </div>

         
        <div class="mb-3">
          <label class="tc-form-label">Final Destination Point <span class="text-danger">*</span></label>
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-light text-danger"><i class="bi bi-geo-fill"></i></span>
            <input type="text" class="form-control" id="route-dest-input" placeholder="Enter destination or address..." value="<?= e($context_destination) ?>" autocomplete="off" <?= $is_driver_user ? 'readonly' : '' ?>>
          </div>
        </div>

         
        <div class="mb-3">
          <label class="tc-form-label">Assigned Vehicle Class <span class="text-danger">*</span></label>
          <select class="tc-form-select" id="route-vehicle-select" onchange="aiRouteEngine.onVehicleChange(this.value)" <?= $is_driver_user ? 'disabled' : '' ?>>
            <option value="">-- Select Assigned Vehicle Class --</option>
            <?php if (!empty($fleet_vehicles)): ?>
              <?php foreach ($fleet_vehicles as $v):
                $val = "{$v['type']} - {$v['brand']} {$v['model']} ({$v['plate_number']})";
                $kmL = $v['avg_fuel_km'] ?: '6.0 km/L';
                $selected = ($context_vehicle_id === $val || $context_vehicle_id === $v['id'] || stripos((string)$context_vehicle_id, $v['plate_number']) !== false) ? 'selected' : '';
              ?>
                <option value="<?= e($val) ?>" data-vehicle-id="<?= e($v['id']) ?>" data-consumption="<?= e($kmL) ?>" <?= $selected ?>>
                  <?= e($v['brand']) ?> <?= e($v['model']) ?> (<?= e($v['plate_number']) ?>) &bull; <?= e($v['type']) ?> &bull; <?= e($kmL) ?> &bull; <?= (int)$v['capacity'] ?> pax
                </option>
              <?php endforeach; ?>
            <?php else: ?>
              <option value="Tour Bus (Hino Grand View 45s)" selected>Tour Bus (Hino Grand View 45s - 3.8 km/L)</option>
              <option value="Executive Van (Toyota HiAce Grandia)">Executive Van (Toyota HiAce Grandia - 9.2 km/L)</option>
              <option value="Coaster Bus (Toyota Coaster Deluxe)">Coaster Bus (Toyota Coaster Deluxe - 6.4 km/L)</option>
              <option value="VIP SUV (Ford Everest Titanium 4x4)">VIP SUV (Ford Everest Titanium 4x4 - 10.5 km/L)</option>
            <?php endif; ?>
          </select>
        </div>

         
        <div class="mb-3 p-2 border rounded bg-light">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-semibold"><i class="bi bi-cone-striped text-dark me-1"></i>Traffic Awareness</span>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" id="route-traffic-aware-toggle" checked onchange="aiRouteEngine.toggleTrafficAwareness(this.checked)">
            </div>
          </div>
          <div class="text-muted-custom" style="font-size: 11px;">Considers real-time congestion delays and expressway bypass options.</div>
        </div>

         
        <div id="route-strategy-locked" class="route-strategy-locked mb-2" role="status">
          <i class="bi bi-stars"></i>
          <span>Route strategies will be available after your plan is generated.</span>
        </div>
        <div id="route-strategy-options" class="route-strategy-options" aria-hidden="true">
          <label class="tc-form-label mb-2">Choose a Generated Route Strategy</label>
          <div class="d-flex flex-column gap-2 mb-2">
          <div class="opt-option-card <?= $initial_mode === 'balanced' ? 'selected' : '' ?>" data-mode="balanced" onclick="aiRouteEngine.selectMode('balanced', this)">
            <div class="d-flex justify-content-between align-items-center">
              <div class="opt-title fw-bold small"><i class="bi bi-stars text-primary me-1"></i> Balanced (ROUTETHINK Recommended)</div>
              <span class="badge bg-primary-subtle text-primary" style="font-size: 10px;">Best Value</span>
            </div>
            <div class="text-muted-custom" style="font-size: 11px;">Optimal balance of expressway speed, fuel efficiency & passenger comfort.</div>
          </div>

          <div class="opt-option-card <?= $initial_mode === 'fuelEfficient' ? 'selected' : '' ?>" data-mode="fuelEfficient" onclick="aiRouteEngine.selectMode('fuelEfficient', this)">
            <div class="d-flex justify-content-between align-items-center">
              <div class="opt-title fw-bold small"><i class="bi bi-fuel-pump text-success me-1"></i> Fuel Efficient (Eco)</div>
              <span class="badge bg-success-subtle text-success" style="font-size: 10px;">Lowest Cost</span>
            </div>
            <div class="text-muted-custom" style="font-size: 11px;">Minimizes RPM spikes and avoids steep thermal elevation grades.</div>
          </div>

          <div class="opt-option-card <?= $initial_mode === 'fastest' ? 'selected' : '' ?>" data-mode="fastest" onclick="aiRouteEngine.selectMode('fastest', this)">
            <div class="d-flex justify-content-between align-items-center">
              <div class="opt-title fw-bold small"><i class="bi bi-lightning-charge text-warning me-1"></i> Fastest Duration</div>
              <span class="badge bg-warning-subtle text-dark" style="font-size: 10px;">Quickest</span>
            </div>
            <div class="text-muted-custom" style="font-size: 11px;">Prioritizes multi-lane expressway corridors (NLEX, SCTEX, TPLEX, SLEX).</div>
          </div>

          <div class="opt-option-card <?= $initial_mode === 'shortest' ? 'selected' : '' ?>" data-mode="shortest" onclick="aiRouteEngine.selectMode('shortest', this)">
            <div class="d-flex justify-content-between align-items-center">
              <div class="opt-title fw-bold small"><i class="bi bi-signpost-2 text-secondary me-1"></i> Shortest Distance</div>
              <span class="badge bg-secondary-subtle text-dark" style="font-size: 10px;">Direct Path</span>
            </div>
            <div class="text-muted-custom" style="font-size: 11px;">Direct geographical routing minimizing total odometer kilometers.</div>
          </div>
          </div>
        </div>
      </div>
    </div>

    <div class="p-3 border-top bg-light">
      <button id="btn-generate-ai-route" class="tc-btn tc-btn-primary w-100 py-2 fw-semibold" onclick="aiRouteEngine.generateRoute()" <?= !$driver_has_active_trip ? 'disabled' : '' ?>>
        <i class="bi bi-stars me-1"></i> Generate ROUTETHINK Route
      </button>
    </div>
  </div>

   
  <div class="map-card-container position-relative" id="map-card-container">
    <div id="map">
      <div id="map-loading-indicator" class="d-flex flex-column align-items-center justify-content-center h-100 p-4 text-center" style="min-height: 520px; background: #F8FAFC;">
        <div class="spinner-border text-primary mb-3" style="width: 2.5rem; height: 2.5rem;" role="status">
          <span class="visually-hidden">Loading Google Maps...</span>
        </div>
        <h6 class="fw-semibold text-dark mb-1">Loading Google Maps...</h6>
        <p class="text-muted-custom small mb-0">Connecting to Google Maps & ROUTETHINK Routing Engine</p>
      </div>
    </div>

     
    <div class="map-control-overlay shadow-sm" id="map-controls-toolbar">
      <button type="button" class="map-control-btn" id="map-btn-type" title="Toggle Map / Satellite Layer" onclick="aiRouteEngine.toggleMapType()">
        <i class="bi bi-layers"></i>
        <span id="map-btn-type-label">Satellite</span>
      </button>
      <button type="button" class="map-control-btn" id="map-btn-traffic" title="Toggle Real-Time Traffic Layer" onclick="aiRouteEngine.toggleTrafficLayer()">
        <i class="bi bi-cone-striped text-dark"></i>
        <span>Traffic</span>
      </button>
      <button type="button" class="map-control-btn" id="map-btn-locate" title="Locate Current Position (GPS)" onclick="aiRouteEngine.locateUser()">
        <i class="bi bi-crosshair text-success"></i>
        <span>My Location</span>
      </button>
      <button type="button" class="map-control-btn" id="map-btn-reset" title="Fit & Center Route Bounds" onclick="aiRouteEngine.fitMapBounds()">
        <i class="bi bi-arrows-angle-contract text-primary"></i>
        <span>Center Route</span>
      </button>
      <div class="map-control-divider"></div>
      <button type="button" class="map-control-btn" id="map-btn-fullscreen" title="Toggle Fullscreen Mode" onclick="aiRouteEngine.toggleFullscreen()">
        <i class="bi bi-fullscreen" id="map-fullscreen-icon"></i>
        <span id="map-fullscreen-label">Fullscreen</span>
      </button>
    </div>

     
    <div class="map-legend-overlay" id="map-legend-overlay">
      <div class="d-flex flex-wrap align-items-center gap-3">
        <div><span class="d-inline-block rounded-circle bg-success me-1" style="width:9px; height:9px;"></span> Origin</div>
        <div><span class="d-inline-block rounded-circle bg-primary me-1" style="width:9px; height:9px;"></span> Waypoints</div>
        <div><span class="d-inline-block rounded-circle bg-danger me-1" style="width:9px; height:9px;"></span> Destination</div>
        <div><span class="d-inline-block rounded-circle bg-warning me-1" style="width:9px; height:9px;"></span> Tourist Spot</div>
        <div class="text-muted-custom border-start ps-2 d-none d-sm-inline-block" style="font-size: 11px;"><i class="bi bi-google me-1"></i>Google Maps</div>
      </div>
    </div>

     
    <div id="nav-hud-overlay" class="nav-hud-container" style="display: none;">
       
      <div class="nav-hud-top shadow">
        <div class="nav-hud-main-row d-flex align-items-center gap-2">
          <div class="nav-hud-turn-icon">
            <i class="bi bi-arrow-up-right-circle-fill text-white" id="nav-hud-icon"></i>
          </div>
          <div class="flex-grow-1 overflow-hidden">
            <div class="nav-hud-eyebrow text-white-50" id="nav-hud-target-label">Next Destination</div>
            <div class="nav-hud-destination fw-bold text-white text-truncate" id="nav-hud-target-name">Final Destination Point</div>
            <div class="nav-hud-instruction text-white-50 text-truncate" id="nav-hud-subtext">Navigating along ROUTETHINK route</div>
          </div>
        </div>
        <div class="nav-hud-badge-container d-flex align-items-center flex-wrap gap-1 mt-1">
          <span class="badge bg-primary text-white" id="nav-hud-selected-route"><i class="bi bi-signpost-split-fill me-1"></i>Selected: Balanced</span>
          <span class="badge bg-success text-white" id="nav-hud-traffic-badge"><i class="bi bi-broadcast me-1"></i>Live GPS</span>
          <span class="badge bg-white text-dark border" id="nav-hud-traffic-info"><i class="bi bi-cone-striped text-dark me-1"></i>Traffic: Live</span>
        </div>
         
        <div class="nav-hud-progress mt-1">
          <div class="d-flex justify-content-between align-items-center">
            <span class="text-white-50">Route Progress</span>
            <span class="text-white-50 fw-bold" id="nav-hud-progress-text">0%</span>
          </div>
          <div class="progress mt-1" style="background: rgba(255,255,255,0.2);">
            <div id="nav-hud-progress-bar" class="progress-bar" role="progressbar" style="width: 0%; background: linear-gradient(90deg, #34D399, #10B981); border-radius: 2px; transition: width 0.5s ease;"></div>
          </div>
        </div>
        <div id="nav-hud-offroute-alert" class="alert alert-warning py-1 px-2 mb-0 mt-2 d-none align-items-center gap-2 small">
          <i class="bi bi-exclamation-triangle-fill text-warning"></i>
          <span>Off-route deviation detected. Re-aligning with planned route.</span>
        </div>
      </div>

       
      <div class="nav-hud-bottom shadow">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div class="nav-hud-metrics d-flex align-items-center">
            <div class="nav-hud-metric">
              <div class="nav-hud-metric-label text-muted-custom">Remaining Distance</div>
              <div class="nav-hud-metric-value text-dark" id="nav-hud-distance">—</div>
            </div>
            <div class="nav-hud-metric border-start">
              <div class="nav-hud-metric-label text-muted-custom">Est. Travel Time</div>
              <div class="nav-hud-metric-value text-primary" id="nav-hud-duration">—</div>
            </div>
            <div class="nav-hud-metric border-start d-none d-sm-block">
              <div class="nav-hud-metric-label text-muted-custom">Estimated Arrival (ETA)</div>
              <div class="nav-hud-metric-value text-success" id="nav-hud-eta">—</div>
            </div>
          </div>
          <div class="nav-hud-actions d-flex align-items-center gap-1">
            <button type="button" class="btn btn-light border btn-sm fw-medium" id="nav-recenter-btn" title="Re-center Map on Driver GPS" onclick="aiRouteEngine.recenterNavigation()">
              <i class="bi bi-crosshair me-1 text-primary"></i> Recenter
            </button>
            <button type="button" class="btn btn-danger btn-sm fw-semibold shadow-sm" onclick="aiRouteEngine.stopNavigation()">
              <i class="bi bi-stop-circle-fill me-1"></i> End Navigation
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

   
  <div class="tc-card d-flex flex-column justify-content-between ai-workspace-results">
    <div>
      <div class="tc-card-header py-2">
        <div class="d-flex justify-content-between align-items-center w-100">
          <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-cpu me-2 text-primary-custom"></i>ROUTETHINK Results</h4>
          <span id="ai-res-badge" class="badge bg-secondary">ROUTETHINK Baseline</span>
        </div>
      </div>

      <div class="p-3" style="max-height: calc(100vh - 320px); overflow-y: auto;">
        <div id="ai-res-title" class="fw-bold small text-primary-custom mb-3">Select a preset or click Generate to calculate the optimal route.</div>

         
        <div class="row g-2 mb-3 text-center">
          <div class="col-6 p-2 bg-light rounded border">
            <div class="text-muted-custom" style="font-size: 11px;">Total Distance</div>
            <div id="ai-res-distance" class="fw-bold fs-6">—</div>
          </div>
          <div class="col-6 p-2 bg-light rounded border">
            <div id="ai-res-duration-label" class="text-muted-custom" style="font-size: 11px;">Google Traffic ETA</div>
            <div id="ai-res-duration" class="fw-bold fs-6 text-primary">—</div>
          </div>
          <div class="col-6 p-2 bg-light rounded border">
            <div id="ai-res-fuel-label" class="text-muted-custom" style="font-size: 11px;">Formula Fuel Estimate</div>
            <div id="ai-res-fuel" class="fw-bold fs-6 text-success">—</div>
          </div>
          <div class="col-6 p-2 bg-light rounded border">
            <div class="text-muted-custom" style="font-size: 11px;">Estimated Trip Cost</div>
            <div id="ai-res-cost" class="fw-bold fs-6 text-primary-custom">—</div>
          </div>
        </div>

         
        <div class="p-2 border rounded bg-light mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span id="ai-res-score-label" class="small fw-semibold">Relative Route Score:</span>
            <span id="ai-res-score" class="badge bg-success">—</span>
          </div>
          <div class="progress" style="height: 6px;">
            <div id="ai-res-score-bar" class="progress-bar bg-success" style="width: 0%;"></div>
          </div>
        </div>

         
        <div class="mb-3">
          <label class="tc-form-label mb-1">ROUTETHINK Optimization Rationale</label>
          <div id="ai-res-reason" class="p-2 border rounded bg-light text-muted-custom small" style="line-height: 1.4; font-size: 12px;">
            Generate a route to view the ROUTETHINK recommendation rationale.
          </div>
        </div>

         
        <div class="mb-2">
          <label class="tc-form-label mb-1 d-flex justify-content-between align-items-center">
            <span>Planned Itinerary & Stops</span>
            <span id="itinerary-stops-count" class="badge bg-light text-dark border" style="font-size: 10px;">0 stops</span>
          </label>
          <div id="ai-itinerary-list" class="d-flex flex-column gap-1">
            <div class="p-2 border rounded bg-light text-center small text-muted-custom">
              Route stops and waypoints will appear here after calculation.
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="p-3 border-top bg-light d-flex flex-column gap-2">
       
      <button type="button" id="btn-start-navigation" class="btn w-100 py-2 fw-semibold text-white" style="display: none;" onclick="aiRouteEngine.startNavigation()">
        <i class="bi bi-compass-fill me-2 fs-6"></i> START NAVIGATION
      </button>
    </div>
  </div>
</div>

<script>
  window.TC_INITIAL_MODE = <?= json_encode($initial_mode) ?>;
  window.TC_INITIAL_PRESET = <?= json_encode($initial_preset) ?>;
  window.TC_TOURIST_DESTINATIONS = <?= json_encode($tourist_destinations, JSON_UNESCAPED_UNICODE) ?>;
  window.TC_ROUTE_CONTEXT = <?= json_encode([
    'isDriver' => $is_driver_user,
    'tripId' => $route_context['trip_id'] ?? null,
    'reservationId' => $route_context['reservation_id'] ?? null,
    'vehicleId' => $route_context['vehicle_id'] ?? null,
    'passengerCount' => (int)($route_context['passenger_count'] ?? 0),
    'waypoints' => $context_waypoints,
    'hasActiveTrip' => $driver_has_active_trip,
    'routePhase' => $is_return_mode ? 'return' : 'outbound',
    'resumeNavigation' => $is_driver_user
        && in_array($route_context['trip_status'] ?? '', ['In Transit', 'Returning to Depot'], true)
        && (int)($route_context['navigation_active'] ?? 0) === 1,
  ], JSON_UNESCAPED_UNICODE) ?>;

  window.initGoogleMapsCallback = function () {
    if (window.aiRouteEngine && typeof window.aiRouteEngine.init === 'function') {
      window.aiRouteEngine.init();
    } else {
      window._googleMapsReady = true;
    }
  };
</script>

<?php if (!$is_key_placeholder): ?>
 
<script src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($google_maps_key) ?>&libraries=places,geometry,marker&loading=async&callback=initGoogleMapsCallback" async defer></script>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
