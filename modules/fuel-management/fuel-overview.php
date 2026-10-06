<?php

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/driver_fuel_context.php';
require_once ROOT_PATH . '/includes/trip_funding.php';
require_login();
require_permission('fuel.view');

$pdo = db();

$current_year = (int)date('Y');
$report_year = (int)($_GET['year'] ?? $current_year);
if ($report_year < 2000 || $report_year > $current_year) $report_year = $current_year;
$current_month_key = date('Y-m');
$current_month_label = date('F Y');

$monthlyStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(liters),0) liters, COALESCE(SUM(total_cost),0) cost
       FROM fuel_transactions WHERE TO_CHAR(transaction_date, 'YYYY-MM') = ?"
);
$monthlyStmt->execute([$current_month_key]);
$monthlyTotals = $monthlyStmt->fetch();
$monthly_liters = (float)($monthlyTotals['liters'] ?? 0);
$monthly_cost = (float)($monthlyTotals['cost'] ?? 0);
$current_diesel_price = fuel_current_price($pdo, 'Diesel');

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-01', strtotime('+1 month'));
$distanceStmt = $pdo->prepare("SELECT COALESCE(SUM(distance_km),0) FROM trips WHERE status='Completed' AND actual_arrival>=? AND actual_arrival<?");
$distanceStmt->execute([$monthStart,$monthEnd]);
$fleet_km = (float)$distanceStmt->fetchColumn();
$operatingCostStmt = $pdo->prepare(
    "SELECT COALESCE((SELECT SUM(total_cost) FROM fuel_transactions WHERE transaction_date>=? AND transaction_date<?),0)
          + COALESCE((SELECT SUM(estimated_cost) FROM maintenance_orders WHERE status='Completed' AND scheduled_date>=? AND scheduled_date<?),0)
          + COALESCE((SELECT SUM(COALESCE(driver_allowance,0)+COALESCE(toll_fee,0)) FROM trips WHERE status='Completed' AND actual_arrival>=? AND actual_arrival<?),0)"
);
$operatingCostStmt->execute([$monthStart,$monthEnd,$monthStart,$monthEnd,$monthStart,$monthEnd]);
$fleet_exp = (float)$operatingCostStmt->fetchColumn();
$avg_cost_km = $fleet_km > 0 ? $fleet_exp / $fleet_km : 0;

$fuelTrend = array_fill(1, 12, 0.0);
$trendStmt = $pdo->prepare(
    'SELECT EXTRACT(MONTH FROM transaction_date)::int month_number, COALESCE(SUM(total_cost),0) expense
       FROM fuel_transactions
      WHERE EXTRACT(YEAR FROM transaction_date)::int = ?
      GROUP BY EXTRACT(MONTH FROM transaction_date)
      ORDER BY month_number'
);
$trendStmt->execute([$report_year]);
foreach ($trendStmt->fetchAll() as $monthRow) {
    $fuelTrend[(int)$monthRow['month_number']] = (float)$monthRow['expense'];
}

$chart_data = [
    'chart-fuel-monthly' => [
        'type'   => 'line',
        'label'  => 'Fuel Expense (' . currency_code() . ')',
        'labels' => ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
        'data'   => array_values($fuelTrend),
        'color'  => '#2F80ED',
        'fill'   => true,
        'legend' => false,
        'money'  => true,
    ],
];

$active_page = 'fuel-dashboard';
$body_class = trim(($body_class ?? '') . ' fuel-management-module fuel-overview-page');
$page_title  = 'Fuel Management System';
$include_chart = true;
require ROOT_PATH . '/includes/header.php';
?>

<div class="fuel-management-page-shell">
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2 fuel-page-heading">
  <div>
    <h1 class="mb-1">Fuel Management System</h1>
    <p class="text-muted-custom mb-0">Monitor fleet fuel transactions, diesel prices per liter, consumption efficiency, and odometer metrics.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/fuel-management/fuel-transactions.php"><i class="bi bi-card-list"></i> Transactions Log</a>

<?php if (can('fuel.manage')): ?>
      <button class="tc-btn tc-btn-primary tc-btn-sm" onclick="App.openModal('modal-log-fuel')"><i class="bi bi-plus-lg"></i> Log Fuel Refill</button>
    <?php endif; ?>
  </div>
</div>
</div>



<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <span class="stat-label">Total Monthly Fuel</span>
      <div class="stat-value text-primary-custom"><?= number_format($monthly_liters, 1) ?> L</div>
      <div class="stat-trend text-muted-custom mt-2"><?= e($current_month_label) ?> to date</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <span class="stat-label">Total Monthly Fuel Cost</span>
      <div class="stat-value"><?= money0($monthly_cost) ?></div>
      <div class="stat-trend text-success mt-2"><i class="bi bi-arrow-down-short"></i> Tracked refills</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <span class="stat-label">Current Diesel Price / L</span>
      <div class="stat-value text-accent-custom"><?= $current_diesel_price ? money($current_diesel_price['price_per_liter']) : 'Not configured' ?></div>
      <div class="stat-trend text-muted-custom mt-2">Configured in Settings</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <span class="stat-label">Fleet Operating Cost / Km</span>
      <div class="stat-value text-primary"><?= money($avg_cost_km) ?>/km</div>
      <div class="stat-trend text-muted-custom mt-2">Overall efficiency</div>
    </div>
  </div>
</div>


<div class="row g-3">
  <div class="col-12">
    <div class="tc-card">
      <div class="tc-card-header d-flex justify-content-between align-items-center">
        <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-graph-up me-2 text-primary-custom"></i>Monthly Fuel Spend Trend (<?= $report_year ?>)</h4>
        <div class="btn-group" role="group" aria-label="Fuel trend year navigation">
          <?php if ($report_year > 2000): ?>
            <a class="btn btn-sm btn-light border" href="?year=<?= $report_year - 1 ?>"
               aria-label="Previous year, <?= $report_year - 1 ?>">&larr; Previous (<?= $report_year - 1 ?>)</a>
          <?php else: ?>
            <button type="button" class="btn btn-sm btn-light border" disabled>&larr; Previous</button>
          <?php endif; ?>
          <span class="btn btn-sm btn-primary" aria-current="true"><?= $report_year ?></span>
          <?php if ($report_year < $current_year): ?>
            <a class="btn btn-sm btn-light border" href="?year=<?= $report_year + 1 ?>"
               aria-label="Next year, <?= $report_year + 1 ?>">Next (<?= $report_year + 1 ?>) &rarr;</a>
          <?php else: ?>
            <button type="button" class="btn btn-sm btn-light border" disabled title="Future years are not available">Next &rarr;</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="tc-card-body">
        <div style="height: 240px;">
          <canvas id="chart-fuel-monthly"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require ROOT_PATH.'/includes/trip-fuel-comparison-ui.php'; ?>
<?php if (can('fuel.manage')):
  $driver_fuel_trip = null;
  $current_driver_id = null;
  if (($current_user['role_code'] ?? '') === 'driver') {
      $dRow = driver_fuel_context($pdo, (string)$current_user['id']);
      if ($dRow) {
          $current_driver_id = $dRow['driver_id'];
          if (!empty($dRow['trip_id']) && !empty($dRow['vehicle_id']) && !empty($dRow['plate_number'])) $driver_fuel_trip = $dRow;
      }
  }
?>

<div id="modal-log-fuel" class="tc-modal-backdrop">
  <div class="tc-modal">
    <div class="tc-card-header">
      <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-fuel-pump me-2 text-primary-custom"></i>Log Fuel Refill Transaction</h4>
      <button type="button" class="btn-close" onclick="App.closeModal('modal-log-fuel')"></button>
    </div>
    <div class="tc-card-body">
      <form method="post" action="<?= BASE_URL ?>/actions/fuel.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/fuel-management/fuel-transactions.php') ?>">
        <?php if (($current_user['role_code'] ?? '') === 'driver' && !$driver_fuel_trip): ?>
          <div class="alert alert-warning">No active or assigned trip with a vehicle is available. Ask Dispatch to assign a trip before logging fuel.</div>
        <?php endif; ?>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="tc-form-label">Vehicle</label>
            <?php if (($current_user['role_code'] ?? '') === 'driver'): ?>
              <div class="tc-form-control" aria-label="Assigned trip vehicle"><?= $driver_fuel_trip ? e(trim($driver_fuel_trip['brand'] . ' ' . $driver_fuel_trip['model']) . ' (' . $driver_fuel_trip['plate_number'] . ')') : 'No trip vehicle assigned' ?></div>
            <?php else: ?>
            <select class="tc-form-select" name="vehicle_id" required>
              <option value="">— Choose vehicle —</option>
              <?php
              $fuel_vehicles = $pdo->query('SELECT id, plate_number, brand, model FROM vehicles ORDER BY id')->fetchAll();
              foreach ($fuel_vehicles as $fv):
              ?>
                <option value="<?= e($fv['id']) ?>"><?= e($fv['id']) ?> (<?= e($fv['plate_number']) ?>) - <?= e($fv['brand']) ?> <?= e($fv['model']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Driver</label>
            <select class="tc-form-select" name="driver_id">
              <option value="">— Optional —</option>
              <?php foreach ($pdo->query('SELECT id, name FROM drivers ORDER BY name')->fetchAll() as $fd): ?>
                <option value="<?= e($fd['id']) ?>" <?= $current_driver_id === $fd['id'] ? 'selected' : '' ?>><?= e($fd['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Liters Refilled</label>
            <input type="number" step="0.1" name="liters" class="tc-form-control" placeholder="50.0" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Price per Liter (<?= e(currency_symbol()) ?>)</label>
            <input type="number" step="0.01" name="price_per_liter" class="tc-form-control" value="" min="0.0001" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Current Odometer (km)</label>
            <input type="number" name="odometer" class="tc-form-control" placeholder="42350" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Fuel Type</label>
            <select class="tc-form-select" name="fuel_type">
              <option>Diesel</option>
              <option>Diesel Max</option>
              <option>Gasoline</option>
            </select>
          </div>
          <div class="col-12">
            <label class="tc-form-label">Fuel Station & Location</label>
            <input type="text" name="station" class="tc-form-control" placeholder="Petron SLEX Northbound Km 36" required>
          </div>
          <div class="col-12">
            <label class="tc-form-label" for="fuel-receipt-upload">Receipt (Optional)</label>
            <input id="fuel-receipt-upload" type="file" name="receipt" class="tc-form-control" accept=".jpg,.jpeg,.png,.pdf">
            <div class="small text-muted-custom mt-1">JPG, PNG or PDF, up to 5 MB. You can save without a receipt.</div>
          </div>
          <div class="col-12">
            <label class="tc-form-label" for="fuel-receipt-upload">Receipt (Optional)</label>
            <input id="fuel-receipt-upload" type="file" name="receipt" class="tc-form-control" accept=".jpg,.jpeg,.png,.pdf">
            <div class="small text-muted-custom mt-1">JPG, PNG or PDF, up to 5 MB. You can save without a receipt.</div>
          </div>
          <div class="col-12">
            <label class="d-flex gap-2 align-items-start p-2 border rounded bg-light small">
              <input type="checkbox" name="verified_trip_consumption" value="1" class="form-check-input mt-1">
              <span><strong>Verified trip fuel consumption</strong><br><span class="text-muted-custom">Check only for a full-to-full measurement where these liters equal the fuel consumed by the linked trip. Only verified values are used for AI training.</span></span>
            </label>
          </div>
        </div>
        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-log-fuel')">Cancel</button>
          <button type="submit" class="tc-btn tc-btn-primary" <?= ($current_user['role_code'] ?? '') === 'driver' && !$driver_fuel_trip ? 'disabled' : '' ?>>Record Transaction</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if(can('fuel.manage')) require ROOT_PATH.'/includes/fuel-log-defaults.php'; ?>
<script src="<?= BASE_URL ?>/js/fuel-management.js?v=<?= (int)filemtime(ROOT_PATH . '/js/fuel-management.js') ?>"></script>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
