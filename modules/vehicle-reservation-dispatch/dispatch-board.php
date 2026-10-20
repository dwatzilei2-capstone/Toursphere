<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_compliance.php';
require_once ROOT_PATH . '/includes/archive.php';
require_once ROOT_PATH . '/includes/dispatch_board.php';
require_login();
require_permission('dispatch.view');

$pdo = db();
$_SESSION['dispatch_board_csrf'] ??= bin2hex(random_bytes(32));

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
    "SELECT r.*, v.plate_number, v.odometer AS board_odometer, d.name AS driver_name, t.id AS board_trip_id, t.status AS board_trip_status, t.current_step AS board_trip_step,
            EXISTS (SELECT 1 FROM trips linked_t WHERE linked_t.reservation_id=r.id) AS has_trip,
            CASE WHEN r.departure_schedule_instance_id IS NULL THEN r.passenger_count ELSE (
              SELECT COALESCE(SUM(sr.passenger_count),0) FROM reservations sr
               WHERE (sr.departure_schedule_instance_id=r.departure_schedule_instance_id OR sr.return_schedule_instance_id=r.departure_schedule_instance_id)
                 AND sr.status IN ('Pending Approval','Approved','Pending','Assigned','Confirmed','Dispatched','In Transit')
            ) END AS scheduled_passenger_demand
       FROM reservations r
       LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
       LEFT JOIN drivers d  ON d.id = r.assigned_driver_id
       LEFT JOIN LATERAL (SELECT id,status,current_step FROM trips WHERE reservation_id=r.id AND NOT is_archived ORDER BY created_at DESC,id DESC LIMIT 1) t ON true
      WHERE NOT EXISTS (SELECT 1 FROM trips archived_t WHERE archived_t.reservation_id=r.id AND archived_t.is_archived)
         OR EXISTS (SELECT 1 FROM trips open_t WHERE open_t.reservation_id=r.id AND NOT open_t.is_archived)
      ORDER BY r.departure_date, r.departure_time"
)->fetchAll();

 
$columns = ['approval' => [], 'pending' => [], 'assigned' => [], 'ready'=>[], 'dispatched' => [], 'transit' => [], 'delivered'=>[], 'completed' => []];
foreach ($reservations as &$r) {
    $ready=false;
    if(in_array($r['status'],['Assigned','Confirmed','Ready for Dispatch'],true) && $r['board_trip_id']) {
        $trip=funding_trip($pdo,$r['board_trip_id']);
        $readiness=$trip ? funding_readiness($pdo,$trip) : ['ready'=>false];
        $ready=$readiness['ready'];
    }
    $r['board_stage']=dispatch_board_stage($r,$r['board_trip_id']?['status'=>$r['board_trip_status'],'current_step'=>$r['board_trip_step']]:null,$ready);
    switch ($r['board_stage']) {
        case 'Pending Approval':    $columns['approval'][] = $r; break;
        case 'Approved':
        case 'Pending':             $columns['pending'][] = $r; break;
        case 'Reserved':
                                   $columns['pending'][] = $r; break;
        case 'Ready for Dispatch':  $columns['ready'][]=$r; break;
        case 'Assignment / Preparation': $columns['assigned'][]=$r; break;
        case 'Assigned':
        case 'Confirmed':           $columns['assigned'][] = $r; break;
        case 'Dispatched':          $columns['dispatched'][] = $r; break;
        case 'In Transit':
        case 'Returning to Depot':  $columns['transit'][] = $r; break;
        case 'Delivered':           $columns['delivered'][]=$r; break;
        case 'Verified Completed':
        case 'Cancelled':
        case 'Rejected':
        case 'Incomplete':
        case 'Completed':           $columns['completed'][] = $r; break;
    }
}
unset($r);

$dispatch_data = [
  'vehicles' => $dispatchVehicles,
    'drivers'  => $pdo->query("SELECT d.id,d.name,d.status,d.safety_score,
        EXISTS (SELECT 1 FROM trips active_t WHERE active_t.driver_id=d.id AND active_t.status IN ('In Transit','Returning to Depot')) AS has_active_trip
        FROM drivers d ORDER BY d.name")->fetchAll(),
];

$active_page = 'dispatch-board';
$closedFilter="NOT t.is_archived AND t.status IN ('Completed','Cancelled','Incomplete') AND (t.status<>'Completed' OR COALESCE(r.request_kind,'Passenger')<>'Cargo' OR t.current_step='Verified Completed')";
$closedTotal=(int)$pdo->query("SELECT COUNT(*) FROM trips t LEFT JOIN reservations r ON r.id=t.reservation_id WHERE $closedFilter")->fetchColumn();
$closedPages=max(1,(int)ceil($closedTotal/15));
$closedPage=min($closedPages,max(1,(int)($_GET['closed_page']??1))); $closedOffset=($closedPage-1)*15;
$closedTrips=$pdo->query("SELECT t.*,r.client_name,r.notes AS request_notes,r.special_handling,r.request_kind,r.cargo_type,r.cargo_weight_kg,r.cargo_volume_m3,r.cargo_quantity,v.plate_number,d.name AS driver_name FROM trips t LEFT JOIN reservations r ON r.id=t.reservation_id LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN drivers d ON d.id=t.driver_id
 WHERE $closedFilter ORDER BY t.created_at DESC,t.id DESC LIMIT 15 OFFSET $closedOffset")->fetchAll();
$body_class = trim(($body_class ?? '') . ' reservation-dispatch-module dispatch-board-page');
$page_title  = 'Operational Dispatch Board';
require ROOT_PATH . '/includes/header.php';

 
// Display-only summaries; original values remain available to the existing details views.
function board_number($value): string {
    return rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.') ?: '0';
}
function board_summary($value): string {
    return trim(preg_replace('/\bDEVELOPMENT\s+TEST(?:\s+ONLY)?\b\s*[-:–—]?\s*/i', '', (string)$value));
}
function board_is_test(array $r): bool {
    return (bool)preg_match('/\bDEVELOPMENT\s+TEST\b/i', implode(' ', array_map(fn($key)=>(string)($r[$key]??''), ['cargo_type','origin','destination','notes','special_handling'])));
}
function board_schedule($date, $time=''): string {
    if (trim((string)$date)==='') return 'Schedule unavailable';
    try { return (new DateTimeImmutable(trim((string)$date.' '.(string)$time)))->format('M j, Y · g:i A'); }
    catch (Exception $e) { return 'Schedule unavailable'; }
}
function kanban_card(array $r): string
{
    $short_id = preg_replace('/^RES-2026-/', 'RES-', $r['id']);
    $vehicle  = $r['assigned_vehicle_id'] ? $r['assigned_vehicle_id'] : 'No Vehicle';
    $dispatchLocked = !in_array($r['status'], ['Approved', 'Assigned', 'Confirmed'], true);
    $cardClass = 'kanban-card';
    $cardAction = $dispatchLocked
        ? ' onclick="if (!event.target.closest(\'button,a,form,details\')) App.openDispatchModal(\'' . e($r['id']) . '\')"'
        : (in_array($r['status'],['Assigned','Confirmed'],true) && has_role(['fleet_admin','dispatcher','fleet_manager'])
            ? ' onclick="if (!event.target.closest(\'button,a,form,details\')) TripFunding.open(\'' . e($r['id']) . '\')"'
            : ' onclick="if (!event.target.closest(\'button,a,form,details\')) App.openDispatchModal(\'' . e($r['id']) . '\')"');
    $cargo = ($r['request_kind']??'Passenger')==='Cargo';
    $test = board_is_test($r);
    return '
      <div class="' . $cardClass . '"' . $cardAction . '>
        <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
          <span class="badge bg-primary-subtle text-primary border">' . e($short_id) . '</span>
          '.($test ? '<span class="badge bg-secondary-subtle text-secondary board-test-badge">Test</span>' : '').'
        </div>
        <div class="board-one-line fw-semibold" style="font-size:13px;">' . e($r['client_name']) . '</div>
        <div class="board-two-lines small">'.($cargo ? e(board_summary($r['cargo_type'])).' · '.(int)$r['cargo_quantity'].' units' : (int)$r['passenger_count'].' passengers').'</div>
        '.($cargo ? '<div class="small text-muted-custom">'.board_number($r['cargo_weight_kg']).' kg · '.board_number($r['cargo_volume_m3']).' m³</div>' : '').'
        <div class="board-two-lines small text-muted-custom">'.e(board_summary(explode(',', $r['origin'])[0])).' → '.e(board_summary(explode(',', $r['destination'])[0])).'</div>
        <div class="small text-muted-custom board-one-line"><i class="bi bi-clock me-1" aria-hidden="true"></i>'.e(board_schedule($r['departure_date'],$r['departure_time'])).'</div>
        <div class="board-assignment border-top pt-2 mt-2 small">
          <span class="board-one-line text-muted-custom">'.e($r['plate_number']??$vehicle).' · '.e($r['driver_name']??'No driver').'</span>
          <span class="text-primary-custom fw-semibold">' . e($r['board_stage']??$r['status']) . '</span>
        </div>
        '.dispatch_board_controls($r).'
      </div>';
}
?>

<div class="reservation-dispatch-page-shell">
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Operational Dispatch Board</h1>
    <p class="text-muted-custom mb-0">Cargo workflow: Ready for Dispatch → Dispatched → In Transit → Delivered → Verified Completed.</p>
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
      <span><i class="bi bi-check2-circle me-1 text-primary"></i> Assignment / Preparation</span>
      <span class="badge bg-primary-subtle text-primary"><?= count($columns['assigned']) ?></span>
    </div>
    <div id="kanban-assigned">
      <?php foreach ($columns['assigned'] as $r) echo kanban_card($r); ?>
      <?php if (empty($columns['assigned'])): ?><div class="text-muted-custom small text-center py-3">Empty</div><?php endif; ?>
    </div>
  </div>

  <div class="kanban-col">
    <div class="kanban-col-header"><span><i class="bi bi-check2-circle me-1 text-success"></i> Ready for Dispatch</span><span class="badge bg-success-subtle text-success"><?= count($columns['ready']) ?></span></div>
    <div id="kanban-ready"><?php foreach($columns['ready'] as $r)echo kanban_card($r); ?><?php if(!$columns['ready']): ?><div class="text-muted-custom small text-center py-3">Empty</div><?php endif; ?></div>
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
    <div class="kanban-col-header"><span><i class="bi bi-box-seam me-1 text-success"></i> Delivered</span><span class="badge bg-success-subtle text-success"><?= count($columns['delivered']) ?></span></div>
    <div id="kanban-delivered"><?php foreach($columns['delivered'] as $r)echo kanban_card($r); ?><?php if(!$columns['delivered']): ?><div class="text-muted-custom small text-center py-3">Empty</div><?php endif; ?></div>
  </div>
  <div class="kanban-col">
    <div class="kanban-col-header">
      <span><i class="bi bi-check-all me-1 text-success"></i> Verified Completed / Closed</span>
      <span class="badge bg-success-subtle text-success"><?= $closedTotal + count(array_filter($columns['completed'],fn($r)=>!$r['has_trip'])) ?></span>
    </div>
    <div id="kanban-completed">
      <p class="small text-muted-custom px-2">Verified cargo deliveries and other closed trips remain here until manually archived.</p>
      <?php foreach ($closedTrips as $t): ?>
      <div class="kanban-card" onclick="if (!event.target.closest('button,a,form,details')) window.location.href=<?= e(json_encode(BASE_URL . '/trip-details.php?id=' . urlencode($t['id']))) ?>">
        <div class="d-flex flex-wrap gap-2 justify-content-between mb-2"><strong class="text-primary-custom"><?= e($t['id']) ?></strong><span class="status-badge <?= status_badge_class($t['status']) ?>"><?= e(($t['request_kind']==='Cargo' && $t['status']==='Completed') ? 'Verified Completed' : $t['status']) ?></span></div>
        <?php if(board_is_test($t + ['notes'=>$t['request_notes']??''])): ?><span class="badge bg-secondary-subtle text-secondary board-test-badge">Test</span><?php endif; ?>
        <div class="board-one-line fw-semibold small"><?= e($t['client_name']??'') ?></div>
        <?php if($t['request_kind']==='Cargo'): ?><div class="board-two-lines small"><?= e(board_summary($t['cargo_type'])) ?> · <?= (int)$t['cargo_quantity'] ?> units</div><div class="small text-muted-custom"><?= board_number($t['cargo_weight_kg']) ?> kg · <?= board_number($t['cargo_volume_m3']) ?> m³</div><?php endif; ?>
        <div class="board-two-lines small text-muted-custom"><?= e(board_summary(explode(',', $t['origin'])[0]).' → '.board_summary(explode(',', $t['destination'])[0])) ?></div>
        <div class="small text-muted-custom board-one-line"><?= e(board_schedule($t['scheduled_departure'])) ?></div>
        <div class="small text-muted-custom board-one-line mt-2"><?= e($t['plate_number']??$t['vehicle_id']??'No vehicle') ?> · <?= e($t['driver_name']??'Unassigned') ?></div>
        <div class="d-flex flex-wrap gap-2 border-top pt-2 mt-2" onclick="event.stopPropagation()">
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
          'requestKind'=>$r['request_kind'],'cargoType'=>$r['cargo_type'],'cargoWeightKg'=>$r['cargo_weight_kg'],'cargoVolumeM3'=>$r['cargo_volume_m3'],'cargoQuantity'=>$r['cargo_quantity'],'specialHandling'=>$r['special_handling'],
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
