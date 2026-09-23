<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('driver.portal');

$pdo = db();

$driver = $pdo->prepare('SELECT * FROM drivers WHERE user_id = ?');
$driver->execute([$current_user['id']]);
$driver = $driver->fetch();

$vehicle = null;
$myTrips = [];
$driver_on_time_rate = 0.0;
$driver_measurable_trips = 0;
$driver_completed_trips = 0;
if ($driver) {
    $vehicle = $pdo->prepare('SELECT * FROM vehicles WHERE assigned_driver_id = ?');
    $vehicle->execute([$driver['id']]);
    $vehicle = $vehicle->fetch() ?: null;

    $tripsStmt = $pdo->prepare(
        'SELECT r.*, v.plate_number, t.id AS trip_id, t.route_history_id FROM reservations r
          LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
          LEFT JOIN trips t ON t.reservation_id = r.id
         WHERE r.assigned_driver_id = ? ORDER BY r.departure_date, r.departure_time'
    );
    $tripsStmt->execute([$driver['id']]);
    $myTrips = $tripsStmt->fetchAll();

    $performanceStmt = $pdo->prepare(
        "SELECT COUNT(*) completed,
                COUNT(*) FILTER (WHERE actual_departure IS NOT NULL AND scheduled_departure IS NOT NULL) measurable,
                COUNT(*) FILTER (WHERE actual_departure IS NOT NULL AND scheduled_departure IS NOT NULL
                  AND actual_departure <= scheduled_departure + INTERVAL '15 minutes') on_time
           FROM trips WHERE driver_id=? AND status='Completed'"
    );
    $performanceStmt->execute([$driver['id']]);
    $performance = $performanceStmt->fetch();
    $driver_completed_trips = (int)($performance['completed'] ?? 0);
    $driver_measurable_trips = (int)($performance['measurable'] ?? 0);
    $driver_on_time_rate = $driver_measurable_trips > 0 ? ((int)$performance['on_time'] / $driver_measurable_trips) * 100 : 0;
}
$upcoming = array_values(array_filter($myTrips, fn($t) => !in_array($t['status'], ['Completed','Cancelled'], true)));
$nextTrip = $upcoming[0] ?? null;

 
 
$route_preview = null;
if ($nextTrip && !empty($nextTrip['route_history_id']) && !empty($nextTrip['trip_id'])) {
    $savedRouteStmt = $pdo->prepare('SELECT * FROM route_history WHERE log_id=? AND trip_id=? LIMIT 1');
    $savedRouteStmt->execute([$nextTrip['route_history_id'], $nextTrip['trip_id']]);
    if ($savedRoute = $savedRouteStmt->fetch()) {
        $payload = json_decode($savedRoute['route_data_json'] ?? '', true) ?: [];
        $route_preview = ['history'=>$savedRoute, 'route'=>$payload['route'] ?? []];
    }
}

$active_page = 'driver-dashboard';
$page_title  = 'Driver Dashboard';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h1 class="mb-1">Driver Portal</h1>
    <p class="text-muted-custom mb-0">Welcome back, <strong id="driver-welcome-name"><?= e($driver['name'] ?? '') ?></strong> — here is your day at a glance.</p>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/fuel-management/fuel-transactions.php"><i class="bi bi-fuel-pump"></i> Log Fuel Refill</a>
    <a class="tc-btn tc-btn-primary tc-btn-sm <?= !empty($nextTrip['trip_id']) ? '' : 'disabled' ?>" href="<?= !empty($nextTrip['trip_id']) ? BASE_URL . '/modules/ai-route-optimization/ai-route-planner.php?trip_id=' . urlencode($nextTrip['trip_id']) : '#' ?>"><i class="bi bi-compass"></i> Navigate Assigned Trip</a>
    <span class="badge bg-light text-dark border px-3 py-2"><i class="bi bi-person-badge me-1"></i><?= e($driver['status'] ?? 'Unavailable') ?></span>
  </div>
</div>

<?php if (!$driver): ?>
  <div class="alert alert-light border">
    <i class="bi bi-info-circle me-2 text-primary-custom"></i>
    Your account is not linked to a driver profile in the Toursphere HRMS registry. Please contact the Fleet Operations team.
  </div>
<?php else: ?>

 
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <?php if (can('driver.portal')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/driver-portal/driver-vehicle.php"><?php endif; ?>
      <div class="stat-card">
      <span class="stat-label">My Vehicle</span>
      <div class="stat-value" id="driver-dash-vehicle"><?= e($vehicle['plate_number'] ?? '—') ?></div>
      <div class="stat-trend text-muted-custom mt-2" id="driver-dash-vehicle-model"><?= $vehicle ? e($vehicle['brand'] . ' ' . $vehicle['model']) : 'No vehicle assigned' ?></div>
    
      </div>
      <?php if (can('driver.portal')): ?></a><?php endif; ?>
  </div>
  <div class="col-6 col-md-3">
    <?php if (can('driver.portal')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/driver-portal/driver-trips.php"><?php endif; ?>
      <div class="stat-card">
      <span class="stat-label">My Trips</span>
      <div class="stat-value text-primary-custom" id="driver-dash-trips"><?= count($upcoming) ?></div>
      <div class="stat-trend text-muted-custom mt-2">Assigned & upcoming</div>
    
      </div>
      <?php if (can('driver.portal')): ?></a><?php endif; ?>
  </div>
  <div class="col-6 col-md-3">
    <?php if (can('driver.portal')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/driver-portal/driver-trips.php"><?php endif; ?>
      <div class="stat-card">
      <span class="stat-label">On-Time Rate</span>
      <div class="stat-value text-success" id="driver-dash-ontime"><?= number_format($driver_on_time_rate, 1) ?>%</div>
      <div class="stat-trend text-muted-custom mt-2"><?= $driver_measurable_trips ?> measured completed trip<?= $driver_measurable_trips === 1 ? '' : 's' ?></div>
    
      </div>
      <?php if (can('driver.portal')): ?></a><?php endif; ?>
  </div>
  <div class="col-6 col-md-3">
    <?php if (can('driver.portal')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/driver-portal/driver-trips.php"><?php endif; ?>
      <div class="stat-card">
      <span class="stat-label">Completed Trips</span>
      <div class="stat-value text-accent-custom" id="driver-dash-completed"><?= $driver_completed_trips ?></div>
      <div class="stat-trend text-muted-custom mt-2">Actual completed assignments</div>
    
      </div>
      <?php if (can('driver.portal')): ?></a><?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-5">
    <div class="tc-card h-100">
      <div class="tc-card-header">
        <h3 class="mb-0 fs-6 fw-bold"><a class="dashboard-panel-link" href="<?= BASE_URL ?>/modules/driver-portal/driver-vehicle.php"><i class="bi bi-truck me-2 text-primary-custom"></i>My Assigned Vehicle</a></h3>
      </div>
      <div class="tc-card-body" id="driver-vehicle-card">
        <?php if ($vehicle): ?>
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="stat-icon-wrapper" style="background-color: var(--tc-primary-light); color: var(--tc-primary); border-radius: 50%;">
              <i class="bi bi-truck"></i>
            </div>
            <div>
              <div class="fw-bold"><?= e($vehicle['brand']) ?> <?= e($vehicle['model']) ?> (<?= (int)$vehicle['year'] ?>)</div>
              <span class="badge bg-light text-dark border mt-1"><?= e($vehicle['plate_number']) ?></span>
            </div>
          </div>
          <div class="row g-2 small">
            <div class="col-6"><span class="text-muted-custom">Type:</span> <strong><?= e($vehicle['type']) ?></strong></div>
            <div class="col-6"><span class="text-muted-custom">Capacity:</span> <strong><?= (int)$vehicle['capacity'] ?> pax</strong></div>
            <div class="col-6"><span class="text-muted-custom">Fuel Level:</span> <strong><?= (int)$vehicle['current_fuel'] ?>%</strong></div>
            <div class="col-6"><span class="text-muted-custom">Odometer:</span> <strong><?= number_format((int)$vehicle['odometer']) ?> km</strong></div>
            <div class="col-12"><span class="text-muted-custom">Status:</span> <span class="status-badge status-available"><?= e($vehicle['status']) ?></span></div>
          </div>
        <?php else: ?>
          <p class="text-muted-custom small mb-0">No vehicle currently assigned to you.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="tc-card h-100">
      <div class="tc-card-header">
        <h3 class="mb-0 fs-6 fw-bold"><i class="bi bi-calendar-event me-2 text-primary-custom"></i>Upcoming Trips</h3>
        <a href="<?= BASE_URL ?>/modules/driver-portal/driver-trips.php" class="small text-primary-custom text-decoration-none fw-semibold">View All Trips →</a>
      </div>
      <div class="tc-table-container border-0">
        <table class="tc-table">
          <thead>
            <tr>
              <th>Trip</th>
              <th>Route</th>
              <th>Departure</th>
              <th>Pax</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody id="driver-dash-trips-body">
            <?php if (empty($upcoming)): ?>
              <tr><td colspan="5" class="text-center text-muted-custom py-4">No upcoming trips assigned.</td></tr>
            <?php else: foreach (array_slice($upcoming, 0, 5) as $r): ?>
              <tr>
                <td><strong class="text-primary-custom"><?= e($r['id']) ?></strong></td>
                <td>
                  <div class="small fw-medium"><i class="bi bi-geo-alt text-success me-1"></i><?= e(explode(',', $r['origin'])[0]) ?></div>
                  <div class="small fw-medium"><i class="bi bi-flag text-danger me-1"></i><?= e(explode(',', $r['destination'])[0]) ?></div>
                </td>
                <td><?= e($r['departure_date']) ?> <span class="text-muted-custom small">(<?= e($r['departure_time']) ?>)</span></td>
                <td><span class="badge bg-light text-dark border"><?= (int)$r['passenger_count'] ?> pax</span></td>
                <td><span class="status-badge <?= status_badge_class($r['status']) ?>"><?= e($r['status']) ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="tc-card">
  <div class="tc-card-header">
    <h3 class="mb-0 fs-6 fw-bold"><i class="bi bi-signpost-split me-2 text-primary-custom"></i>Next Trip Route Preview</h3>
    <a href="<?= BASE_URL ?>/modules/driver-portal/driver-route.php" class="small text-primary-custom text-decoration-none fw-semibold">Open Route Info →</a>
  </div>
  <div class="tc-card-body" id="driver-route-preview">
    <?php if ($route_preview): $b = $route_preview['route']; $saved = $route_preview['history']; ?>
      <div class="row g-3 align-items-center">
        <div class="col-lg-4">
          <div class="fw-bold mb-1"><i class="bi bi-geo-alt text-success me-1"></i><?= e(explode(',', $nextTrip['origin'])[0]) ?></div>
          <div class="text-muted-custom small mb-2"><i class="bi bi-arrow-down text-primary-custom"></i></div>
          <div class="fw-bold"><i class="bi bi-flag text-danger me-1"></i><?= e(explode(',', $nextTrip['destination'])[0]) ?></div>
        </div>
        <div class="col-lg-8">
          <div class="row g-2 text-center">
            <div class="col-4 p-2 bg-light rounded"><div class="text-muted-custom" style="font-size:11px;">Distance</div><div class="fw-bold"><?= e($b['distance'] ?? (($b['distanceKm'] ?? null) ? $b['distanceKm'].' km' : '—')) ?></div></div>
            <div class="col-4 p-2 bg-light rounded"><div class="text-muted-custom" style="font-size:11px;">Est. Travel Time</div><div class="fw-bold text-primary"><?= e($b['duration'] ?? (($saved['predicted_duration_mins'] ?? null) ? $saved['predicted_duration_mins'].' min' : '—')) ?></div></div>
            <div class="col-4 p-2 bg-light rounded"><div class="text-muted-custom" style="font-size:11px;">Est. Fuel Burn</div><div class="fw-bold text-success"><?= e($b['fuelEstimate'] ?? (($saved['predicted_fuel_liters'] ?? null) ? $saved['predicted_fuel_liters'].' L' : '—')) ?></div></div>
          </div>
          <div class="small text-muted-custom mt-2"><i class="bi bi-check-circle me-1 text-success"></i>Saved route <?= e($saved['log_id']) ?> • <?= e($saved['selected_mode'] ?? 'Selected strategy') ?></div>
        </div>
      </div>
    <?php else: ?>
      <div class="p-2 bg-light rounded small text-muted-custom">
        <i class="bi bi-info-circle me-1 text-primary-custom"></i>
        <?= $nextTrip ? 'Route details for ' . e($nextTrip['origin']) . ' → ' . e($nextTrip['destination']) . ' will be provided by your dispatcher before departure.' : 'No upcoming trips assigned — route guidance will appear here once you are dispatched.' ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script src="<?= BASE_URL ?>/js/dashboard-cards.js?v=<?= (int)filemtime(ROOT_PATH . '/js/dashboard-cards.js') ?>"></script>
<?php require ROOT_PATH . '/includes/footer.php'; ?>


