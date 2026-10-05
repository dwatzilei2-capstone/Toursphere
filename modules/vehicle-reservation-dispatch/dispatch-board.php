<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_compliance.php';
require_once ROOT_PATH . '/includes/archive.php';
require_login();
require_permission('dispatch.view');

$pdo = db();

$dispatchVehicles = $pdo->query(
  "SELECT v.id, v.plate_number, v.brand, v.model, v.type, v.capacity, v.status,
      EXISTS (SELECT 1 FROM trips active_t WHERE active_t.vehicle_id=v.id AND active_t.status IN ('In Transit','Returning to Depot')) AS has_active_trip,
      (v.status = 'Maintenance' OR EXISTS (
        SELECT 1 FROM maintenance_orders active_mo
         WHERE active_mo.vehicle_id = v.id AND active_mo.status = 'In Repair'
      )) AS is_under_maintenance
     FROM vehicles v WHERE NOT v.is_archived AND v.status NOT IN ('Retired','Inactive') ORDER BY v.id"
)->fetchAll();
foreach ($dispatchVehicles as &$vehicle) {
  $compliance = vehicle_operational_compliance($pdo, $vehicle['id']);
  $vehicle['is_operational'] = $compliance['operational'];
  $vehicle['operational_reason'] = $compliance['reason'];
  $vehicle['is_test_data'] = $compliance['test_data'];
}
unset($vehicle);

$reservations = $pdo->query(
    "SELECT r.*, v.plate_number, d.name AS driver_name,
            EXISTS (SELECT 1 FROM trips linked_t WHERE linked_t.reservation_id=r.id) AS has_trip,
            CASE WHEN r.departure_schedule_instance_id IS NULL THEN r.passenger_count ELSE (
              SELECT COALESCE(SUM(sr.passenger_count),0) FROM reservations sr
               WHERE (sr.departure_schedule_instance_id=r.departure_schedule_instance_id OR sr.return_schedule_instance_id=r.departure_schedule_instance_id)
                 AND sr.status IN ('Pending Approval','Approved','Pending','Assigned','Confirmed','Dispatched','In Transit')
            ) END AS scheduled_passenger_demand
       FROM reservations r
       LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
       LEFT JOIN drivers d  ON d.id = r.assigned_driver_id
      WHERE NOT EXISTS (SELECT 1 FROM trips archived_t WHERE archived_t.reservation_id=r.id AND archived_t.is_archived)
         OR EXISTS (SELECT 1 FROM trips open_t WHERE open_t.reservation_id=r.id AND NOT open_t.is_archived)
      ORDER BY r.departure_date, r.departure_time"
)->fetchAll();

 
$columns = ['approval' => [], 'pending' => [], 'assigned' => [], 'dispatched' => [], 'transit' => [], 'completed' => []];
foreach ($reservations as $r) {
    switch ($r['status']) {
        case 'Pending Approval':    $columns['approval'][] = $r; break;
        case 'Approved':
        case 'Pending':             $columns['pending'][] = $r; break;
        case 'Reserved':
        case 'Ready for Dispatch':  $columns['pending'][] = $r; break;
        case 'Assigned':
        case 'Confirmed':           $columns['assigned'][] = $r; break;
        case 'Dispatched':          $columns['dispatched'][] = $r; break;
        case 'In Transit':
        case 'Returning to Depot':  $columns['transit'][] = $r; break;
        case 'Completed':           $columns['completed'][] = $r; break;
    }
}

$dispatch_data = [
  'vehicles' => $dispatchVehicles,
    'drivers'  => $pdo->query("SELECT d.id,d.name,d.status,d.safety_score,
        EXISTS (SELECT 1 FROM trips active_t WHERE active_t.driver_id=d.id AND active_t.status IN ('In Transit','Returning to Depot')) AS has_active_trip
        FROM drivers d ORDER BY d.name")->fetchAll(),
];

$active_page = 'dispatch-board';
$closedTotal=(int)$pdo->query("SELECT COUNT(*) FROM trips WHERE NOT is_archived AND status IN ('Completed','Cancelled','Incomplete')")->fetchColumn();
$closedPages=max(1,(int)ceil($closedTotal/15));
$closedPage=min($closedPages,max(1,(int)($_GET['closed_page']??1))); $closedOffset=($closedPage-1)*15;
$closedTrips=$pdo->query("SELECT t.*,v.plate_number,d.name AS driver_name FROM trips t LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN drivers d ON d.id=t.driver_id
 WHERE NOT t.is_archived AND t.status IN ('Completed','Cancelled','Incomplete') ORDER BY t.created_at DESC,t.id DESC LIMIT 15 OFFSET $closedOffset")->fetchAll();
$body_class = trim(($body_class ?? '') . ' reservation-dispatch-module dispatch-board-page');
$page_title  = 'Operational Dispatch Board';
require ROOT_PATH . '/includes/header.php';

 
function kanban_card(array $r): string
{
    $short_id = preg_replace('/^RES-2026-/', 'RES-', $r['id']);
    $vehicle  = $r['assigned_vehicle_id'] ? $r['assigned_vehicle_id'] : 'No Vehicle';
    $dispatchLocked = !in_array($r['status'], ['Approved', 'Assigned', 'Confirmed'], true);
    $cardClass = $dispatchLocked ? 'kanban-card opacity-75' : 'kanban-card';
    $cardAction = $dispatchLocked
        ? ' aria-disabled="true" title="This reservation has already been dispatched or is locked" style="cursor:default"'
        : (in_array($r['status'],['Assigned','Confirmed'],true) && has_role(['fleet_admin','dispatcher','fleet_manager'])
            ? ' onclick="TripFunding.open(\'' . e($r['id']) . '\')"'
            : ' onclick="App.openDispatchModal(\'' . e($r['id']) . '\')"');
    return '
      <div class="' . $cardClass . '"' . $cardAction . '>
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="badge bg-primary-subtle text-primary border">' . e($short_id) . '</span>
          <span class="small fw-semibold text-muted-custom"><i class="bi bi-people me-1"></i>' . (int)$r['passenger_count'] . ' pax</span>
        </div>
        <div class="fw-semibold text-truncate mb-1" style="font-size:13px;">' . e($r['client_name']) . '</div>
        <div class="small text-muted-custom mb-2">
          <div><i class="bi bi-arrow-right-short text-primary"></i> ' . e(explode(',', $r['origin'])[0]) . ' → ' . e(explode(',', $r['destination'])[0]) . '</div>
          <div class="mt-1"><i class="bi bi-clock me-1"></i> ' . e($r['departure_date']) . ' (' . e($r['departure_time']) . ')</div>
        </div>
        <div class="border-top pt-2 d-flex justify-content-between align-items-center small">
          <span class="text-muted-custom">' . e($vehicle) . '</span>
          <span class="text-primary-custom fw-semibold">' . ($r['status'] === 'Pending Approval' ? 'Awaiting review' : money($r['estimated_cost'])) . '</span>
        </div>
      </div>';
}
?>

<div class="reservation-dispatch-page-shell">
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Operational Dispatch Board</h1>
    <p class="text-muted-custom mb-0">Workflow: Reservation → Assignment → Trip Funding → Ready → Dispatch → In Transit → Completed.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/reservations.php">Reservations List</a>
  </div>
</div>

 
<div class="dispatch-kanban-board">
  <div class="kanban-col">
    <div class="kanban-col-header">
      <span><i class="bi bi-hourglass-split me-1 text-warning"></i> Pending Approval</span>
      <span class="badge bg-warning-subtle text-dark"><?= count($columns['approval']) ?></span>
    </div>
    <div id="kanban-approval">
      <?php foreach ($columns['approval'] as $r) echo kanban_card($r); ?>
      <?php if (empty($columns['approval'])): ?><div class="text-muted-custom small text-center py-3">Empty</div><?php endif; ?>
    </div>
  </div>
  <div class="kanban-col">
    <div class="kanban-col-header">
      <span><i class="bi bi-hourglass me-1 text-warning"></i> Pending Assignment</span>
      <span class="badge bg-warning-subtle text-dark"><?= count($columns['pending']) ?></span>
    </div>
    <div id="kanban-pending">
      <?php foreach ($columns['pending'] as $r) echo kanban_card($r); ?>
      <?php if (empty($columns['pending'])): ?><div class="text-muted-custom small text-center py-3">Empty</div><?php endif; ?>
    </div>
  </div>

  <div class="kanban-col">
    <div class="kanban-col-header">
      <span><i class="bi bi-check2-circle me-1 text-primary"></i> Assigned / Ready</span>
      <span class="badge bg-primary-subtle text-primary"><?= count($columns['assigned']) ?></span>
    </div>
    <div id="kanban-assigned">
      <?php foreach ($columns['assigned'] as $r) echo kanban_card($r); ?>
      <?php if (empty($columns['assigned'])): ?><div class="text-muted-custom small text-center py-3">Empty</div><?php endif; ?>
    </div>
  </div>

  <div class="kanban-col">
    <div class="kanban-col-header">
      <span><i class="bi bi-send me-1 text-info"></i> Dispatched</span>
      <span class="badge bg-info-subtle text-info"><?= count($columns['dispatched']) ?></span>
    </div>
    <div id="kanban-dispatched">
      <?php foreach ($columns['dispatched'] as $r) echo kanban_card($r); ?>
      <?php if (empty($columns['dispatched'])): ?><div class="text-muted-custom small text-center py-3">Empty</div><?php endif; ?>
    </div>
  </div>

  <div class="kanban-col">
    <div class="kanban-col-header">
      <span><i class="bi bi-truck me-1 text-primary"></i> In Transit</span>
      <span class="badge bg-primary"><?= count($columns['transit']) ?></span>
    </div>
    <div id="kanban-transit">
      <?php foreach ($columns['transit'] as $r) echo kanban_card($r); ?>
      <?php if (empty($columns['transit'])): ?><div class="text-muted-custom small text-center py-3">Empty</div><?php endif; ?>
    </div>
  </div>

  <div class="kanban-col">
    <div class="kanban-col-header">
      <span><i class="bi bi-check-all me-1 text-success"></i> Closed Trips</span>
      <span class="badge bg-success-subtle text-success"><?= $closedTotal ?></span>
    </div>
    <div id="kanban-completed">
      <p class="small text-muted-custom px-2">Completed, Cancelled, and Incomplete trips remain here until manually archived.</p>
      <?php foreach ($closedTrips as $t): ?>
      <div class="kanban-card">
        <div class="d-flex flex-wrap gap-2 justify-content-between mb-2"><strong class="text-primary-custom"><?= e($t['id']) ?></strong><span class="status-badge <?= status_badge_class($t['status']) ?>"><?= e($t['status']) ?></span></div>
        <div class="small mb-2" style="overflow-wrap:anywhere"><?= e($t['origin'].' → '.$t['destination']) ?></div>
        <div class="small text-muted-custom"><?= e($t['driver_name']??'Unassigned') ?> · <?= e($t['vehicle_id']??'No vehicle') ?></div>
        <div class="small text-muted-custom mb-2"><?= e($t['scheduled_departure']??'No scheduled date') ?></div>
        <div class="d-flex flex-wrap gap-2 border-top pt-2"><a class="tc-btn tc-btn-light tc-btn-sm" href="<?= BASE_URL ?>/trip-details.php?id=<?= urlencode($t['id']) ?>">Details</a>
        <?php if(archive_can_trips()): ?><button type="button" class="tc-btn tc-btn-secondary tc-btn-sm" data-archive-trip="<?= e($t['id']) ?>" data-status="<?= e($t['status']) ?>"><i class="bi bi-archive" aria-hidden="true"></i> Archive</button><?php endif; ?></div>
      </div>
      <?php endforeach; ?>
      <?php foreach($columns['completed'] as $r) if(!$r['has_trip']) echo kanban_card($r); ?>
      <?php if (!$closedTotal && empty($columns['completed'])): ?><div class="text-muted-custom small text-center py-3">No closed trips awaiting Archive.</div><?php endif; ?>
      <?php if($closedPages>1): ?><nav class="d-flex flex-wrap align-items-center gap-2 p-2" aria-label="Closed trip pages"><?php if($closedPage>1): ?><a class="tc-btn tc-btn-light tc-btn-sm" href="?closed_page=<?= $closedPage-1 ?>">Previous</a><?php endif; ?><span class="small"><?= $closedPage ?> / <?= $closedPages ?></span><?php if($closedPage<$closedPages): ?><a class="tc-btn tc-btn-light tc-btn-sm" href="?closed_page=<?= $closedPage+1 ?>">Next</a><?php endif; ?></nav><?php endif; ?>
    </div>
  </div>
</div>
</div>

 
<div id="modal-dispatch" class="tc-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="assignment-modal-title">
  <div class="tc-modal assignment-modal">
    <div class="tc-card-header">
      <h4 id="assignment-modal-title" class="mb-0 fw-bold fs-6"><i class="bi bi-person-check me-2 text-primary-custom"></i>Assign Vehicle & Driver</h4>
      <button type="button" class="btn-close" onclick="App.closeModal('modal-dispatch')" aria-label="Close assignment"></button>
    </div>
    <div class="tc-card-body" id="modal-dispatch-body">
       
    </div>
  </div>
</div>

<script>
  window.TC_CAN_DISPATCH = <?= can('dispatch.manage') ? 'true' : 'false' ?>;
  window.TC_ASSIGNMENT_CSRF = <?= json_encode($_SESSION['assignment_csrf'] ??= bin2hex(random_bytes(32))) ?>;
  window.TC_DISPATCH_DATA = <?= json_encode($dispatch_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.TC_KANBAN_RESERVATIONS = <?= json_encode(array_map(function ($r) {
      return [
          'id' => $r['id'], 'clientName' => $r['client_name'], 'contactPhone' => $r['contact_phone'],
          'passengerCount' => (int)$r['passenger_count'], 'scheduledPassengerDemand' => (int)$r['scheduled_passenger_demand'],
          'requiredVehicleType' => $r['vehicle_requested'], 'requiredCapacity' => $r['required_capacity'] === null ? null : (int)$r['required_capacity'], 'origin' => $r['origin'], 'destination' => $r['destination'],
          'departureScheduleInstanceId' => $r['departure_schedule_instance_id'], 'departureDate' => $r['departure_date'], 'departureTime' => $r['departure_time'],
          'assignedVehicle' => $r['assigned_vehicle_id'] ? $r['assigned_vehicle_id'] . ' (' . $r['plate_number'] . ')' : 'Pending',
          'assignedVehicleId' => $r['assigned_vehicle_id'], 'assignedDriverId' => $r['assigned_driver_id'],
          'assignedDriver' => $r['driver_name'] ?? 'Pending', 'status' => $r['status'],
          'tripType' => $r['trip_type'], 'notes' => $r['notes'], 'estimatedCost' => money($r['estimated_cost']),
      ];
  }, $reservations), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<script src="<?= BASE_URL ?>/js/reservation-dispatch.js?v=<?= (int)filemtime(ROOT_PATH . '/js/reservation-dispatch.js') ?>"></script>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
