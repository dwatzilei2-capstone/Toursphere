<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('drivers.view');

require_once ROOT_PATH.'/includes/driver_license.php';
require_once ROOT_PATH.'/includes/trip_performance.php';
$pdo = db();

$drivers = $pdo->query(
    "WITH trip_perf AS (
       SELECT driver_id,COUNT(*) live_trip_count,
              COUNT(*) FILTER (WHERE status='Completed') live_completed_trips
         FROM trips GROUP BY driver_id
     ), cancelled AS (
       SELECT COALESCE(assigned_driver_id, cancelled_driver_id) driver_id,COUNT(*) live_cancelled_trips
         FROM reservations
        WHERE status='Cancelled' AND COALESCE(assigned_driver_id, cancelled_driver_id) IS NOT NULL
        GROUP BY COALESCE(assigned_driver_id, cancelled_driver_id)
     ), performance_summary AS (
       SELECT driver_id, COUNT(*) evaluated_trips, ROUND(AVG(overall_score)::numeric, 2) performance_score
         FROM trip_performance_evaluations
        WHERE status='Finalized' AND overall_score IS NOT NULL
        GROUP BY driver_id
     )
     SELECT d.*, v.id AS vehicle_id, v.plate_number, u.avatar AS account_avatar,
            active_vehicle.id AS current_vehicle_id, active_vehicle.plate_number AS current_vehicle_plate,
            COALESCE(tp.live_trip_count,0) live_trip_count,COALESCE(tp.live_completed_trips,0) live_completed_trips,
            COALESCE(c.live_cancelled_trips,0) live_cancelled_trips,
            COALESCE(ps.evaluated_trips,0) evaluated_trips, ps.performance_score
       FROM drivers d
       LEFT JOIN vehicles v ON v.assigned_driver_id = d.id
       LEFT JOIN users u ON u.id = d.user_id
       LEFT JOIN trip_perf tp ON tp.driver_id=d.id
       LEFT JOIN cancelled c ON c.driver_id=d.id
       LEFT JOIN performance_summary ps ON ps.driver_id=d.id
       LEFT JOIN LATERAL (
         SELECT av.id,av.plate_number FROM trips at JOIN vehicles av ON av.id=at.vehicle_id
          WHERE at.driver_id=d.id AND at.status IN ('In Transit','Returning to Depot')
          ORDER BY at.actual_departure DESC NULLS LAST LIMIT 1
       ) active_vehicle ON TRUE
      ORDER BY u.created_at DESC NULLS LAST, d.id DESC"
)->fetchAll();

// Share delivery observations and exclusions with Trip Performance, not departure punctuality.
$deliveryByDriver=performance_delivery_kpis($pdo)['on_time_score_by_driver'];
foreach($drivers as &$driver){
    $delivery=$deliveryByDriver[$driver['id']]??null;
    $driver['live_on_time_rate']=$delivery['average']??null;
    $driver['late_trips']=$delivery['late']??null;
    $driver['on_time_label']=$driver['live_on_time_rate']===null?'No data':number_format($driver['live_on_time_rate'],1).' / 100';
}
unset($driver);

$active_page = 'drivers';
$body_class = trim(($body_class ?? '') . ' driver-trip-monitoring-module driver-performance-page');
$page_title  = 'Driver & Trip Performance Monitoring';
require ROOT_PATH . '/includes/header.php';
?>

<div class="driver-trip-page-shell">
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h1 class="mb-1">Driver &amp; Trip Performance Monitoring</h1>
    <p class="text-muted-custom mb-0">Driver credential tracking, safety scores, on-time delivery rates, and Toursphere HRMS integration.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/driver-trip-performance/trip-performance.php"><i class="bi bi-graph-up"></i> Trip Analytics</a>
  </div>
</div>

 
<div class="row g-3" id="drivers-grid-container">
  <?php foreach ($drivers as $d): ?>
    <div class="col-md-6 col-xl-4">
      <div class="tc-card p-3 h-100 d-flex flex-column justify-content-between"
           data-driver="<?= e(json_encode([
               'id' => $d['id'],
               'name' => $d['name'],
               'empId' => $d['emp_id'],
               'phone' => $d['phone'],
               'licenseNo' => $d['license_no'],
               'licenseClass' => driver_license_active($d)
                   ? 'Verified Professional - LTO DL Codes: ' . implode(', ', driver_dl_codes($d['authorized_dl_codes']))
                   : (driver_license_dev_display($d) ? 'Verified' : 'Not verified - LTO DL codes pending verification'),
               'licenseDev' => !driver_license_active($d) && driver_license_dev_display($d),
               'expiration' => $d['license_expiration'],
               'status' => $d['status'],
               'assignedVehicle' => $d['vehicle_id'] ? $d['vehicle_id'] . ' (' . $d['plate_number'] . ')' : 'None',
               'currentTripVehicle' => $d['current_vehicle_id'] ? $d['current_vehicle_id'] . ' (' . $d['current_vehicle_plate'] . ')' : 'None',
               'tripCount' => (int)$d['live_trip_count'],
               'completedTrips' => (int)$d['live_completed_trips'],
               'cancelledTrips' => (int)$d['live_cancelled_trips'],
               'performanceScore' => $d['performance_score'] !== null ? (float)$d['performance_score'] : null,
               'evaluatedTrips' => (int)$d['evaluated_trips'],
               'onTimeRate' => $d['on_time_label'],
               'onTimeMetricLabel' => 'On-Time Score',
               'safetyScore' => (int)$d['safety_score'],
               'fuelEfficiencyScore' => (int)$d['fuel_efficiency_score'],
               'department' => $d['department'],
               'hrmsStatus' => $d['hrms_status'],
               'avatar' => ($d['user_id'] && !empty($d['account_avatar'])) ? $d['account_avatar'] : '',
           ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
        <div>
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="d-flex align-items-center gap-3">
              <div class="driver-profile-photo-slot">
                <?php if ($d['user_id'] && !empty($d['account_avatar'])): ?>
                  <img src="<?= e($d['account_avatar']) ?>" alt="<?= e($d['name']) ?> profile photo" class="driver-profile-photo">
                <?php endif; ?>
              </div>
              <div>
                <h4 class="mb-0 fw-bold"><?= e($d['name']) ?></h4>
                <span class="text-muted-custom small"><?= e($d['emp_id']) ?> • <?= e($d['department']) ?></span>
              </div>
            </div>
            <span class="status-badge <?= status_badge_class($d['status']) ?>"><?= e($d['status']) ?></span>
          </div>

          <div class="bg-light p-2 rounded mb-3 small">
            <div class="mb-2"><strong>License qualification:</strong> <?php if(driver_license_active($d)): ?><span class="text-success"><?= e('Verified Professional - DL '.implode(', ',driver_dl_codes($d['authorized_dl_codes']))) ?></span><?php elseif(driver_license_dev_display($d)): ?><span class="text-success">Verified</span> <span class="small text-muted-custom ms-1" title="Development display only; actual LTO qualifications remain unverified.">Dev</span><?php else: ?>Pending verification - unavailable for assignment<?php endif; ?></div>

            <div class="d-flex justify-content-between mb-1">
              <span class="text-muted-custom">License Number:</span>
              <span class="fw-semibold"><?= e($d['license_no']) ?></span>
            </div>
            <div class="d-flex justify-content-between mb-1">
              <span class="text-muted-custom">Vehicle Assigned:</span>
              <span class="fw-semibold text-primary-custom"><?= $d['vehicle_id'] ? e($d['vehicle_id']) . ' (' . e($d['plate_number']) . ')' : 'None' ?></span>
            </div>
            <div class="d-flex justify-content-between">
              <span class="text-muted-custom">Safety Score:</span>
              <span class="fw-semibold text-success"><?= (int)$d['safety_score'] ?>/100</span>
            </div>
          </div>

          <div class="row g-2 text-center small mb-3">
            <div class="col-4 border-end">
              <div class="fw-bold"><?= (int)$d['live_completed_trips'] ?></div>
              <div class="text-muted-custom" style="font-size: 11px;">Completed</div>
            </div>
            <div class="col-4 border-end">
              <div class="fw-bold text-success" title="Average numeric On-Time Delivery score from qualifying finalized evaluations."><?= e($d['on_time_label']) ?></div>
              <div class="text-muted-custom" style="font-size: 11px;">On-Time Score</div>
            </div>
            <div class="col-4">
              <div class="fw-bold text-primary-custom"><?= (int)$d['evaluated_trips'] ?></div>
              <div class="text-muted-custom" style="font-size: 11px;">Evaluated Trips</div>
            </div>
          </div>
          <div class="d-flex justify-content-between align-items-center border-top pt-2 mb-2 small">
            <span class="text-muted-custom">Performance Score</span>
            <?php if ($d['performance_score'] !== null): ?>
              <span class="fw-bold text-primary-custom" title="Average overall score from finalized Trip Performance evaluations."><?= number_format((float)$d['performance_score'], 2) ?>/100</span>
            <?php else: ?>
              <span class="text-muted-custom">Not evaluated</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="d-flex gap-2 border-top pt-2">
          <button class="tc-btn tc-btn-secondary tc-btn-sm w-100" onclick="App.viewDriverDrawer('<?= e($d['id']) ?>')">
            <i class="bi bi-person-lines-fill"></i> HRMS Profile
          </button>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
</div>

 
<div id="drawer-driver-profile" class="tc-drawer">
  <div class="tc-card-header">
    <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-person-badge me-2 text-primary-custom"></i>Driver HRMS Profile</h4>
    <button type="button" class="btn-close" onclick="App.closeDrawer('drawer-driver-profile')"></button>
  </div>
  <div id="drawer-driver-content" class="flex-1 overflow-y-auto">
     
  </div>
  <div class="p-3 border-top bg-light text-end">
    <button class="tc-btn tc-btn-secondary w-100" onclick="App.closeDrawer('drawer-driver-profile')">Close Profile</button>
  </div>
</div>

<script src="<?= BASE_URL ?>/js/driver-trip-monitoring.js?v=<?= (int)filemtime(ROOT_PATH . '/js/driver-trip-monitoring.js') ?>"></script>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
