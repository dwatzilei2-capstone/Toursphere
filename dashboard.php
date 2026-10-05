<?php
 



require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once ROOT_PATH . '/includes/reports.php';
if (has_role('customer')) redirect_to(BASE_URL . '/' . home_path());

 
if (($current_user['role_code'] ?? '') === 'driver') {
    redirect_to(BASE_URL . '/modules/driver-portal/driver-dashboard.php');
}

$pdo = db();
$role_code = $current_user['role_code'] ?? '';

 
$fleetStatusCounts = ['Available'=>0, 'Maintenance'=>0, 'Assigned'=>0, 'On Trip'=>0];
foreach ($pdo->query("SELECT status, COUNT(*) AS vehicle_count FROM vehicles GROUP BY status")->fetchAll() as $statusRow) {
    if (array_key_exists($statusRow['status'], $fleetStatusCounts)) {
        $fleetStatusCounts[$statusRow['status']] = (int)$statusRow['vehicle_count'];
    }
}
$total_vehicles    = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE NOT is_archived AND status <> 'Retired'")->fetchColumn();
$available_count   = $fleetStatusCounts['Available'];
$maintenance_count = $fleetStatusCounts['Maintenance'];
$assigned_count    = $fleetStatusCounts['Assigned'];
$on_trip_count     = $fleetStatusCounts['On Trip'];
$pending_orders    = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_orders WHERE status <> 'Completed' AND status <> 'In Repair'")->fetchColumn();

 
$pending_reservations = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'Pending'")->fetchColumn();
$assigned_reservations = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'Assigned' OR status = 'Confirmed'")->fetchColumn();

 
$current_month_key = date('Y-m');
$current_month_label = date('F Y');
$monthlyFuelStmt = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM fuel_transactions WHERE TO_CHAR(transaction_date, 'YYYY-MM') = ?");
$monthlyFuelStmt->execute([$current_month_key]);
$monthly_fuel_cost = (float)$monthlyFuelStmt->fetchColumn();

 
$trips = $pdo->query(
    "SELECT t.*, v.plate_number, v.model AS vehicle_model, v.brand AS vehicle_brand, d.name AS driver_name
       FROM trips t
       LEFT JOIN vehicles v ON v.id = t.vehicle_id
       LEFT JOIN drivers d  ON d.id = t.driver_id
      WHERE t.status IN ('In Transit','Dispatched')
      ORDER BY CASE t.status WHEN 'In Transit' THEN 1 WHEN 'Dispatched' THEN 2 ELSE 3 END, t.scheduled_departure
      LIMIT 6"
)->fetchAll();

 
$maintenance_alerts = $pdo->query(
    "SELECT wo.id, wo.service_type, wo.priority, wo.status, wo.scheduled_date,
            v.id AS vehicle_id, v.plate_number, v.brand, v.model
       FROM maintenance_orders wo
       JOIN vehicles v ON v.id = wo.vehicle_id
      WHERE wo.status <> 'Completed'
      ORDER BY CASE wo.priority WHEN 'Critical' THEN 1 WHEN 'Medium' THEN 2 WHEN 'Low' THEN 3 ELSE 4 END, wo.scheduled_date
      LIMIT 4"
)->fetchAll();

 
$pending_queue = $pdo->query(
    "SELECT r.*, v.plate_number, d.name AS driver_name
       FROM reservations r
       LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
       LEFT JOIN drivers d  ON d.id = r.assigned_driver_id
      WHERE r.status IN ('Pending', 'Assigned')
      ORDER BY r.departure_date, r.departure_time
      LIMIT 4"
)->fetchAll();

$weeklyStart = (new DateTimeImmutable('today'))->modify('-6 days');
$weeklyLabels = [];
$weeklyValues = [];
$weeklyIndex = [];
for ($day = $weeklyStart; $day <= new DateTimeImmutable('today'); $day = $day->modify('+1 day')) {
    $key = $day->format('Y-m-d');
    $weeklyIndex[$key] = count($weeklyValues);
    $weeklyLabels[] = $day->format('D, M j');
    $weeklyValues[] = 0;
}
$weeklyStmt = $pdo->prepare(
    "SELECT actual_arrival::date trip_date, COUNT(*) completed
       FROM trips WHERE status='Completed' AND actual_arrival::date BETWEEN ? AND ?
      GROUP BY actual_arrival::date ORDER BY trip_date"
);
$weeklyStmt->execute([$weeklyStart->format('Y-m-d'), date('Y-m-d')]);
foreach ($weeklyStmt->fetchAll() as $row) {
    if (isset($weeklyIndex[$row['trip_date']])) $weeklyValues[$weeklyIndex[$row['trip_date']]] = (int)$row['completed'];
}

 
$chart_data = [
    'chart-weekly-trips' => [
        'type'  => 'bar',
        'label' => 'Trips Completed',
        'labels'=> $weeklyLabels,
        'data'  => $weeklyValues,
        'color' => '#2F80ED',
        'legend'=> false,
    ],
];

$active_page = 'dashboard';
$body_class = trim(($body_class ?? '') . ' toursphere-dashboard');
$page_title  = 'Dashboard — Toursphere Fleet & Transportation Management';
$include_chart = true;
require ROOT_PATH . '/includes/header.php';
?>

<div class="dashboard-page-shell">
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <?php if ($role_code === 'dispatcher'): ?>
      <h1 class="mb-1">Dispatcher Operations Dashboard</h1>
      <p class="text-muted-custom mb-0">Real-time dispatch board, active transit monitoring, vehicle availability, and trip schedules.</p>
    <?php elseif ($role_code === 'fleet_manager'): ?>
      <h1 class="mb-1">Fleet Management & Asset Dashboard</h1>
      <p class="text-muted-custom mb-0">Fleet health, vehicle maintenance schedules, fuel efficiency, and operating expense overview.</p>
    <?php else: ?>
      <h1 class="mb-1">Fleet Operations Dashboard</h1>
      <p class="text-muted-custom mb-0">Live status monitor across vehicles, active trips, dispatch queue, and route performance.</p>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-2">
    <?php if (reports_allowed()): ?>
      <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/reports.php"><i class="bi bi-download"></i> Export Summary</a>
    <?php endif; ?>

    <?php if (can('dispatch.manage')): ?>
      <a class="tc-btn tc-btn-primary tc-btn-sm" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/reservations.php"><i class="bi bi-plus-lg"></i> New Reservation</a>
    <?php elseif (can('vehicles.manage')): ?>
      <a class="tc-btn tc-btn-primary tc-btn-sm" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php"><i class="bi bi-truck"></i> Manage Fleet</a>
    <?php endif; ?>
  </div>
</div>

 
<div class="row g-3 mb-4">
  <?php if ($role_code === 'dispatcher'): ?>
     
    <div class="col-6 col-md-3">
      <?php if (can('vehicles.view')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php?filter=available"><?php endif; ?>
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="stat-label">Available for Dispatch</span>
            <div class="stat-value text-accent-custom"><?= (int)$available_count ?></div>
          </div>
          <div class="stat-icon-wrapper bg-success-subtle text-success"><i class="bi bi-check2-circle"></i></div>
        </div>
        <div class="stat-trend text-success mt-2"><i class="bi bi-check-lg"></i> Ready for assignment</div>
      
      </div>
      <?php if (can('vehicles.view')): ?></a><?php endif; ?>
    </div>

    <div class="col-6 col-md-3">
      <?php if (can('dispatch.view')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/dispatch-board.php"><?php endif; ?>
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="stat-label">Active Dispatches</span>
            <div class="stat-value text-primary-custom"><?= (int)$on_trip_count ?></div>
          </div>
          <div class="stat-icon-wrapper bg-primary-subtle text-primary"><i class="bi bi-cursor-fill"></i></div>
        </div>
        <div class="stat-trend text-primary mt-2"><i class="bi bi-arrow-repeat"></i> In transit en route</div>
      
      </div>
      <?php if (can('dispatch.view')): ?></a><?php endif; ?>
    </div>

    <div class="col-6 col-md-3">
      <?php if (can('dispatch.view')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/reservations.php"><?php endif; ?>
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="stat-label">Pending Bookings</span>
            <div class="stat-value text-warning-custom"><?= (int)$pending_reservations ?></div>
          </div>
          <div class="stat-icon-wrapper bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
        </div>
        <div class="stat-trend text-muted-custom mt-2">Awaiting allocation</div>
      
      </div>
      <?php if (can('dispatch.view')): ?></a><?php endif; ?>
    </div>

    <div class="col-6 col-md-3">
      <?php if (can('dispatch.view')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/trip-schedule.php"><?php endif; ?>
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="stat-label">Assigned Units</span>
            <div class="stat-value text-info"><?= (int)$assigned_reservations ?></div>
          </div>
          <div class="stat-icon-wrapper bg-info-subtle text-info"><i class="bi bi-calendar2-check"></i></div>
        </div>
        <div class="stat-trend text-muted-custom mt-2">Scheduled for departure</div>
      
      </div>
      <?php if (can('dispatch.view')): ?></a><?php endif; ?>
    </div>

  <?php elseif ($role_code === 'fleet_manager'): ?>
     
    <div class="col-6 col-md-3">
      <?php if (can('vehicles.view')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php"><?php endif; ?>
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="stat-label">Total Fleet Units</span>
            <div class="stat-value"><?= (int)$total_vehicles ?></div>
          </div>
          <div class="stat-icon-wrapper bg-light text-primary"><i class="bi bi-truck"></i></div>
        </div>
        <div class="stat-trend text-success mt-2"><i class="bi bi-check-circle"></i> Commercial tour fleet</div>
      
      </div>
      <?php if (can('vehicles.view')): ?></a><?php endif; ?>
    </div>

    <div class="col-6 col-md-3">
      <?php if (can('vehicles.view')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php?filter=available"><?php endif; ?>
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="stat-label">Available Vehicles</span>
            <div class="stat-value text-accent-custom"><?= (int)$available_count ?></div>
          </div>
          <div class="stat-icon-wrapper bg-success-subtle text-success"><i class="bi bi-check2-circle"></i></div>
        </div>
        <div class="stat-trend text-muted-custom mt-2">Depot idle & inspected</div>
      
      </div>
      <?php if (can('vehicles.view')): ?></a><?php endif; ?>
    </div>

    <div class="col-6 col-md-3">
      <?php if (can('vehicles.view')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/maintenance.php"><?php endif; ?>
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="stat-label">Maintenance & Service</span>
            <div class="stat-value text-danger"><?= (int)$maintenance_count ?> / <?= (int)$pending_orders ?></div>
          </div>
          <div class="stat-icon-wrapper bg-danger-subtle text-danger"><i class="bi bi-wrench-adjustable"></i></div>
        </div>
        <div class="stat-trend text-danger mt-2"><?= (int)$maintenance_count ?> active repairs, <?= (int)$pending_orders ?> queue</div>
      
      </div>
      <?php if (can('vehicles.view')): ?></a><?php endif; ?>
    </div>

    <div class="col-6 col-md-3">
      <?php if (can('fuel.view')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/fuel-management/fuel-overview.php"><?php endif; ?>
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="stat-label">Monthly Fuel Spend</span>
            <div class="stat-value text-primary-custom"><?= money0($monthly_fuel_cost) ?></div>
          </div>
          <div class="stat-icon-wrapper bg-primary-subtle text-primary"><i class="bi bi-fuel-pump"></i></div>
        </div>
        <div class="stat-trend text-muted-custom mt-2"><?= e($current_month_label) ?> fleet total</div>
      
      </div>
      <?php if (can('fuel.view')): ?></a><?php endif; ?>
    </div>

  <?php else: ?>
     
    <div class="col-6 col-md-3">
      <?php if (can('vehicles.view')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php"><?php endif; ?>
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="stat-label">Total Vehicles</span>
            <div class="stat-value"><?= (int)$total_vehicles ?></div>
          </div>
          <div class="stat-icon-wrapper bg-light text-primary"><i class="bi bi-truck"></i></div>
        </div>
        <div class="stat-trend text-success mt-2"><i class="bi bi-arrow-up-short"></i> Operational Fleet</div>
      
      </div>
      <?php if (can('vehicles.view')): ?></a><?php endif; ?>
    </div>

    <div class="col-6 col-md-3">
      <?php if (can('vehicles.view')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php?filter=available"><?php endif; ?>
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="stat-label">Available Vehicles</span>
            <div class="stat-value text-accent-custom"><?= (int)$available_count ?></div>
          </div>
          <div class="stat-icon-wrapper bg-success-subtle text-success"><i class="bi bi-check2-circle"></i></div>
        </div>
        <div class="stat-trend text-muted-custom mt-2">Ready for immediate dispatch</div>
      
      </div>
      <?php if (can('vehicles.view')): ?></a><?php endif; ?>
    </div>

    <div class="col-6 col-md-3">
      <?php if (can('vehicles.view')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php?filter=on%20trip"><?php endif; ?>
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="stat-label">Vehicles On Trip</span>
            <div class="stat-value text-primary-custom"><?= (int)$on_trip_count ?></div>
          </div>
          <div class="stat-icon-wrapper bg-primary-subtle text-primary"><i class="bi bi-cursor-fill"></i></div>
        </div>
        <div class="stat-trend text-primary mt-2"><i class="bi bi-arrow-repeat"></i> Active en route</div>
      
      </div>
      <?php if (can('vehicles.view')): ?></a><?php endif; ?>
    </div>

    <div class="col-6 col-md-3">
      <?php if (can('vehicles.view')): ?><a class="dashboard-card-link" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/maintenance.php"><?php endif; ?>
      <div class="stat-card">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="stat-label">Maintenance / Pending</span>
            <div class="stat-value text-danger"><?= (int)$maintenance_count ?> / <?= (int)$pending_orders ?></div>
          </div>
          <div class="stat-icon-wrapper bg-danger-subtle text-danger"><i class="bi bi-wrench-adjustable"></i></div>
        </div>
        <div class="stat-trend text-danger mt-2"><?= (int)$maintenance_count ?> in repair, <?= (int)$pending_orders ?> pending queue</div>
      
      </div>
      <?php if (can('vehicles.view')): ?></a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

 
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="tc-card h-100">
      <div class="tc-card-header">
        <h3 class="mb-0 fs-6 fw-bold"><i class="bi bi-calendar-event me-2 text-primary-custom"></i>Active Trips & Dispatches</h3>
        <?php if (can('dispatch.view')): ?>
          <a href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/dispatch-board.php" class="small text-primary-custom text-decoration-none fw-semibold">View Full Board →</a>
        <?php endif; ?>
      </div>
      <div class="tc-table-container border-0">
        <table class="tc-table">
          <thead>
            <tr>
              <th>Trip ID</th>
              <th>Origin & Destination</th>
              <th>Vehicle & Driver</th>
              <th>Passengers</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($trips)): ?>
              <tr><td colspan="6" class="text-center text-muted-custom py-4">No trips currently in transit or dispatched.</td></tr>
            <?php else: foreach ($trips as $t): ?>
              <tr>
                <td><strong class="text-primary-custom"><?= e($t['id']) ?></strong></td>
                <td><?= e($t['origin']) ?> → <?= e($t['destination']) ?></td>
                <td>
                  <?php if ($t['plate_number']): ?>
                    <?= e($t['plate_number']) ?> • <?= e($t['driver_name'] ?? '—') ?>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= (int)$t['passengers'] ?> pax</td>
                <td><span class="status-badge <?= status_badge_class($t['status']) ?>"><?= e($t['status']) ?></span></td>
                <td><a class="tc-btn tc-btn-light tc-btn-sm" href="<?= BASE_URL ?>/trip-details.php?id=<?= e($t['id']) ?>">Track</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="row g-3 h-100">
      <div class="col-12 col-sm-6 col-lg-12">
        <div class="tc-card h-100">
          <div class="tc-card-header"><h3 class="mb-0 fs-6 fw-bold"><?php if (can('vehicles.view')): ?><a class="dashboard-panel-link" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php"><?php endif; ?><i class="bi bi-bar-chart me-2 text-primary-custom"></i>Vehicle Availability<?php if (can('vehicles.view')): ?></a><?php endif; ?></h3></div>
          <div class="tc-card-body"><div style="height: 175px; width: 100%;"><canvas id="chart-vehicle-availability" aria-label="Available and maintenance vehicle counts"></canvas></div></div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-lg-12">
        <div class="tc-card h-100">
          <div class="tc-card-header"><h3 class="mb-0 fs-6 fw-bold"><?php if (can('vehicles.view')): ?><a class="dashboard-panel-link" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php"><?php endif; ?><i class="bi bi-bar-chart-steps me-2 text-primary-custom"></i>Vehicle Operations<?php if (can('vehicles.view')): ?></a><?php endif; ?></h3></div>
          <div class="tc-card-body"><div style="height: 150px; width: 100%;"><canvas id="chart-vehicle-operations" aria-label="Assigned and on-trip vehicle counts"></canvas></div></div>
        </div>
      </div>
    </div>
  </div>
</div>

 
<div class="row g-3">
  <div class="col-lg-7">
    <div class="tc-card">
      <div class="tc-card-header">
        <h3 class="mb-0 fs-6 fw-bold"><?php if (can('drivers.view')): ?><a class="dashboard-panel-link" href="<?= BASE_URL ?>/modules/driver-trip-performance/trip-performance.php"><?php endif; ?><i class="bi bi-bar-chart-line me-2 text-primary-custom"></i>Completed Trips — Last 7 Days<?php if (can('drivers.view')): ?></a><?php endif; ?></h3>
      </div>
      <div class="tc-card-body">
        <div style="height: 190px;">
          <canvas id="chart-weekly-trips"></canvas>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <?php if ($role_code === 'dispatcher'): ?>
       
      <div class="tc-card">
        <div class="tc-card-header">
          <h3 class="mb-0 fs-6 fw-bold text-primary"><?php if (can('dispatch.view')): ?><a class="dashboard-panel-link" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/trip-schedule.php"><?php endif; ?><i class="bi bi-send-check me-2"></i>Scheduled Departures Queue<?php if (can('dispatch.view')): ?></a><?php endif; ?></h3>
          <span class="badge bg-primary-subtle text-primary border"><?= count($pending_queue) ?> Queued</span>
        </div>
        <div class="p-3">
          <?php if (empty($pending_queue)): ?>
            <div class="text-center text-muted-custom small py-4">No reservations pending dispatch.</div>
          <?php else: foreach ($pending_queue as $pq): ?>
            <div class="p-2 border rounded mb-2 bg-light d-flex justify-content-between align-items-center">
              <div>
                <div class="fw-bold small text-primary-custom"><?= e($pq['id']) ?> — <?= e($pq['client_name']) ?></div>
                <div class="text-muted-custom" style="font-size: 11px;">
                  <?= e($pq['origin']) ?> → <?= e($pq['destination']) ?> • <?= e($pq['departure_date']) ?> (<?= e($pq['departure_time']) ?>)
                </div>
              </div>
              <div class="text-end">
                <span class="badge <?= $pq['status'] === 'Pending' ? 'bg-warning text-dark' : 'bg-info text-dark' ?>"><?= e($pq['status']) ?></span>
                <div class="small text-muted mt-1" style="font-size: 10px;"><?= (int)$pq['passenger_count'] ?> pax</div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    <?php else: ?>
       
      <div class="tc-card">
        <div class="tc-card-header">
          <h3 class="mb-0 fs-6 fw-bold text-danger"><?php if (can('vehicles.view')): ?><a class="dashboard-panel-link" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/maintenance.php"><?php endif; ?><i class="bi bi-exclamation-octagon me-2"></i>Active Maintenance Alerts<?php if (can('vehicles.view')): ?></a><?php endif; ?></h3>
          <span class="badge bg-danger-subtle text-danger border"><?= count($maintenance_alerts) ?> Requires Action</span>
        </div>
        <div class="p-3">
          <?php if (empty($maintenance_alerts)): ?>
            <div class="text-center text-muted-custom small py-4">No active maintenance alerts.</div>
          <?php else: foreach ($maintenance_alerts as $i => $al): ?>
            <div class="p-2 border rounded mb-2 bg-light d-flex justify-content-between align-items-center">
              <div>
                <div class="fw-bold small <?= $al['priority'] === 'Critical' ? 'text-danger' : '' ?>"><?= e($al['vehicle_id']) ?> (<?= e($al['plate_number']) ?>) • <?= e($al['service_type']) ?></div>
                <div class="text-muted-custom" style="font-size: 11px;"><?= e($al['brand']) ?> <?= e($al['model']) ?> — <?= e($al['status']) ?> (<?= e($al['scheduled_date']) ?>)</div>
              </div>
              <span class="badge <?= $al['priority'] === 'Critical' ? 'bg-danger' : ($al['priority'] === 'Medium' ? 'bg-warning text-dark' : 'bg-info text-dark') ?>"><?= e($al['priority']) ?></span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
</div>

<script src="<?= BASE_URL ?>/js/dashboard-cards.js?v=<?= (int)filemtime(ROOT_PATH . '/js/dashboard-cards.js') ?>"></script>
<?php
$fleetChartPayload = [
    'availability' => [$available_count, $maintenance_count],
    'operations' => [$assigned_count, $on_trip_count],
];
$page_scripts = '<script>(function(){
  if(typeof Chart === "undefined") return;
  const values = '.json_encode($fleetChartPayload, JSON_UNESCAPED_UNICODE).';
  const countLabels = {id:"fleetCountLabels",afterDatasetsDraw(chart){const ctx=chart.ctx;ctx.save();ctx.font="600 13px Poppins, sans-serif";ctx.fillStyle=getComputedStyle(document.documentElement).getPropertyValue("--tc-text-main").trim()||"#1F2937";ctx.textBaseline="middle";chart.getDatasetMeta(0).data.forEach((element,index)=>{const value=chart.data.datasets[0].data[index];const p=element.tooltipPosition();if(chart.options.indexAxis==="y"){ctx.textAlign="left";ctx.fillText(String(value),p.x+8,p.y);}else{ctx.textAlign="center";ctx.textBaseline="bottom";ctx.fillText(String(value),p.x,p.y-7);}});ctx.restore();}};
  function options(horizontal){const reduceMotion=window.matchMedia("(prefers-reduced-motion: reduce)").matches;const largest=Math.max(0,...values.availability,...values.operations);const target=Math.max(5,Math.ceil(largest*1.35));const magnitude=Math.pow(10,Math.floor(Math.log10(target)));const step=Math.max(1,Math.ceil(target/(5*magnitude))*magnitude);const ceiling=Math.ceil(target/step)*step;const countAxis={beginAtZero:true,max:ceiling,border:{display:false},ticks:{precision:0,stepSize:step,maxTicksLimit:6},grid:{color:"rgba(148,163,184,.16)",drawTicks:false}};return {indexAxis:horizontal?"y":"x",responsive:true,maintainAspectRatio:false,animation:{duration:reduceMotion?0:650,easing:"easeOutQuart"},interaction:{mode:"nearest",intersect:false},layout:{padding:horizontal?{right:24,top:8,bottom:8}:{top:24,right:8}},plugins:{legend:{display:false},tooltip:{displayColors:false,backgroundColor:"rgba(15,23,42,.92)",padding:10,cornerRadius:7,callbacks:{label:c=>c.label+": "+c.raw+" vehicle"+(Number(c.raw)===1?"":"s")}}},scales:horizontal?{x:countAxis,y:{grid:{display:false},border:{display:false}}}:{y:countAxis,x:{grid:{display:false},border:{display:false}}}};}
  new Chart(document.getElementById("chart-vehicle-availability"),{type:"bar",data:{labels:["Available","Maintenance"],datasets:[{data:values.availability,backgroundColor:["#27AE60","#EB5757"],borderRadius:5,maxBarThickness:54}]},options:options(false),plugins:[countLabels]});
  new Chart(document.getElementById("chart-vehicle-operations"),{type:"bar",data:{labels:["Assigned","On Trip"],datasets:[{data:values.operations,backgroundColor:["#56CCF2","#2F80ED"],borderRadius:5,maxBarThickness:34}]},options:options(true),plugins:[countLabels]});
})();</script>';
?>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
