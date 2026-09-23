<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('vehicles.view');

$pdo = db();

$filter = strtolower($_GET['filter'] ?? 'all');
$q      = trim($_GET['q'] ?? '');

$sql = "SELECT v.*, d.name AS assigned_driver_name
          FROM vehicles v
          LEFT JOIN drivers d ON d.id = v.assigned_driver_id
         WHERE 1=1";
$params = [];
if (in_array($filter, ['available', 'on trip', 'maintenance'], true)) {
    $sql .= ' AND LOWER(v.status) = ?';
    $params[] = $filter;
}
if ($q !== '') {
    $sql .= ' AND (v.plate_number LIKE ? OR v.brand LIKE ? OR v.model LIKE ? OR v.id LIKE ? OR d.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$sql .= ' ORDER BY v.id';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll();

 
$vehicle_json = [];
foreach ($vehicles as $v) {
    $vehicle_json[$v['id']] = [
        'id' => $v['id'],
        'plateNumber' => $v['plate_number'],
        'type' => $v['type'],
        'brand' => $v['brand'],
        'model' => $v['model'],
        'year' => (int)$v['year'],
        'capacity' => (int)$v['capacity'],
        'status' => $v['status'],
        'assignedDriver' => $v['assigned_driver_name'] ?? null,
        'driverId' => $v['assigned_driver_id'],
        'fuelType' => $v['fuel_type'],
        'fuelCapacity' => (int)$v['fuel_capacity'],
        'currentFuel' => (int)$v['current_fuel'],
        'odometer' => (int)$v['odometer'],
        'maintenanceStatus' => $v['maintenance_status'],
        'nextMaintenance' => $v['next_maintenance'],
        'lastMaintenance' => $v['last_maintenance'],
        'location' => $v['location'],
        'documents' => [
            'registration' => $v['reg_document'],
            'insurance' => $v['insurance_document'],
            'ltfrbPermit' => $v['ltfrb_permit'],
        ],
        'performance' => [
            'totalTrips' => (int)$v['total_trips'],
            'totalKm' => (int)$v['total_km'],
            'avgFuelKm' => $v['avg_fuel_km'],
            'operatingCostKm' => $v['operating_cost_km'],
        ],
    ];
}

$active_page = 'vehicles';
$page_title  = 'Vehicle Directory — Fleet & Vehicle Management';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h1 class="mb-1">Fleet & Vehicle Management (FVM)</h1>
    <p class="text-muted-custom mb-0">Comprehensive vehicle directory, capacity details, assigned drivers, and compliance status.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/maintenance.php"><i class="bi bi-tools"></i> Maintenance Info</a>
    <?php if (can('vehicles.manage')): ?>
      <button class="tc-btn tc-btn-primary tc-btn-sm" onclick="App.openModal('modal-add-vehicle')"><i class="bi bi-plus-lg"></i> Add New Vehicle</button>
    <?php endif; ?>
  </div>
</div>

<div class="tc-card mb-4">
  <div class="p-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2 bg-light">
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <span class="text-muted-custom small fw-semibold">Filter Status:</span>
      <a class="tc-btn tc-btn-light tc-btn-sm <?= $filter === 'all' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php">All</a>
      <a class="tc-btn tc-btn-light tc-btn-sm <?= $filter === 'available' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php?filter=available">Available</a>
      <a class="tc-btn tc-btn-light tc-btn-sm <?= $filter === 'on trip' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php?filter=on%20trip">On Trip</a>
      <a class="tc-btn tc-btn-light tc-btn-sm <?= $filter === 'maintenance' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php?filter=maintenance">Maintenance</a>
    </div>
    <div class="text-muted-custom small">
      Showing <strong><?= count($vehicles) ?></strong> registered tour fleet vehicles
      <?php if ($q !== ''): ?><span class="ms-1">for "<strong><?= e($q) ?></strong>" <a href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php" class="text-primary text-decoration-none">(clear)</a></span><?php endif; ?>
    </div>
  </div>

  <div class="tc-table-container border-0">
    <table class="tc-table">
      <thead>
        <tr>
          <th>Vehicle ID</th>
          <th>Plate & Type</th>
          <th>Make & Model</th>
          <th>Capacity</th>
          <th>Current Status</th>
          <th>Assigned Driver</th>
          <th>Odometer</th>
          <th>Fuel Level</th>
          <th>Health</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($vehicles)): ?>
          <tr><td colspan="10" class="text-center text-muted-custom py-4">No vehicles match the selected filter.</td></tr>
        <?php else: foreach ($vehicles as $v):
          $fuel_pct = $v['fuel_capacity'] > 0 ? round(($v['current_fuel'] / $v['fuel_capacity']) * 100) : 0;
          $needs_repair = $v['maintenance_status'] && strpos(strtolower($v['maintenance_status']), 'repair') !== false;
        ?>
          <tr data-vehicle-id="<?= e($v['id']) ?>"
              data-vehicle="<?= e(json_encode($vehicle_json[$v['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
            <td><strong class="text-primary-custom"><?= e($v['id']) ?></strong></td>
            <td>
              <div class="fw-semibold"><?= e($v['plate_number']) ?></div>
              <div class="text-muted-custom small"><?= e($v['type']) ?></div>
            </td>
            <td>
              <div><?= e($v['brand']) ?> <?= e($v['model']) ?></div>
              <div class="text-muted-custom small"><?= (int)$v['year'] ?> Model</div>
            </td>
            <td><span class="badge bg-light text-dark border"><?= (int)$v['capacity'] ?> Seats</span></td>
            <td><span class="status-badge <?= status_badge_class($v['status']) ?>"><?= e($v['status']) ?></span></td>
            <td><?= e($v['assigned_driver_name'] ?? '— Unassigned —') ?></td>
            <td><?= number_format((int)$v['odometer']) ?> km</td>
            <td>
              <div class="d-flex align-items-center gap-2" style="min-width: 100px;">
                <div class="progress flex-grow-1" style="height: 6px;">
                  <div class="progress-bar <?= $fuel_pct < 30 ? 'bg-danger' : ($fuel_pct < 60 ? 'bg-warning' : 'bg-success') ?>" style="width: <?= $fuel_pct ?>%;"></div>
                </div>
                <span class="small fw-semibold"><?= $fuel_pct ?>%</span>
              </div>
            </td>
            <td>
              <span class="badge <?= $needs_repair ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?> border">
                <?= $needs_repair ? 'Maintenance Due' : 'Healthy' ?>
              </span>
            </td>
            <td class="text-end">
              <div class="btn-group">
                <button class="tc-btn tc-btn-secondary tc-btn-sm" onclick="App.viewVehicleDetails('<?= e($v['id']) ?>')">
                  <i class="bi bi-eye"></i> Details
                </button>
                <?php if (can('vehicles.assign')): ?>
                <a class="tc-btn tc-btn-light tc-btn-sm" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-assignment.php?vehicle=<?= e($v['id']) ?>">
                  <i class="bi bi-person-check"></i> Assign
                </a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

 
<div id="modal-vehicle-details" class="tc-modal-backdrop">
  <div class="tc-modal tc-modal-lg">
    <div class="tc-card-header">
      <h4 class="mb-0 fw-bold fs-6">Vehicle Profile & Compliance Information</h4>
      <button type="button" class="btn-close" onclick="App.closeModal('modal-vehicle-details')"></button>
    </div>
    <div class="tc-card-body" id="modal-vehicle-details-body">
       
    </div>
    <div class="tc-card-footer text-end">
      <button class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-vehicle-details')">Close</button>
    </div>
  </div>
</div>

<?php if (can('vehicles.manage')): ?>
 
<div id="modal-add-vehicle" class="tc-modal-backdrop">
  <div class="tc-modal">
    <div class="tc-card-header">
      <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-plus-circle me-2 text-primary-custom"></i>Register New Tour Vehicle</h4>
      <button type="button" class="btn-close" onclick="App.closeModal('modal-add-vehicle')"></button>
    </div>
    <div class="tc-card-body">
      <form method="post" action="<?= BASE_URL ?>/actions/vehicle.php">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/fleet-vehicle-management/vehicle-directory.php') ?>">
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="tc-form-label">Plate Number</label>
            <input type="text" name="plate_number" class="tc-form-control" placeholder="e.g. NBO-1234" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Vehicle Type</label>
            <select class="tc-form-select" name="type">
              <option>Tour Bus</option>
              <option>Coaster Bus</option>
              <option>Executive Van</option>
              <option>VIP SUV</option>
            </select>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Brand</label>
            <input type="text" name="brand" class="tc-form-control" placeholder="e.g. Toyota / Hino" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Model & Year</label>
            <input type="text" name="model" class="tc-form-control" placeholder="e.g. Grandia Tourer" required>
            <input type="number" name="year" class="tc-form-control mt-2" placeholder="Year e.g. 2024" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Passenger Capacity</label>
            <input type="number" name="capacity" class="tc-form-control" placeholder="14" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Fuel Tank Capacity (L)</label>
            <input type="number" name="fuel_capacity" class="tc-form-control" placeholder="70" required>
          </div>
        </div>
        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-add-vehicle')">Cancel</button>
          <button type="submit" class="tc-btn tc-btn-primary">Register Vehicle</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  window.TC_VEHICLES_DATA = <?= json_encode($vehicle_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
