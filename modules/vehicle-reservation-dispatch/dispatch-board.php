<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('dispatch.view');

$pdo = db();

$reservations = $pdo->query(
    "SELECT r.*, v.plate_number, d.name AS driver_name
       FROM reservations r
       LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
       LEFT JOIN drivers d  ON d.id = r.assigned_driver_id
      ORDER BY r.departure_date, r.departure_time"
)->fetchAll();

 
$columns = ['pending' => [], 'assigned' => [], 'dispatched' => [], 'transit' => [], 'completed' => []];
foreach ($reservations as $r) {
    switch ($r['status']) {
        case 'Pending':             $columns['pending'][] = $r; break;
        case 'Assigned':
        case 'Confirmed':           $columns['assigned'][] = $r; break;
        case 'Dispatched':          $columns['dispatched'][] = $r; break;
        case 'In Transit':          $columns['transit'][] = $r; break;
        default:                    $columns['completed'][] = $r; break;
    }
}

$dispatch_data = [
    'vehicles' => $pdo->query(
        "SELECT v.id, v.plate_number, v.brand, v.model, v.capacity, v.status,
                (v.status = 'Maintenance' OR EXISTS (
                    SELECT 1 FROM maintenance_orders active_mo
                     WHERE active_mo.vehicle_id = v.id AND active_mo.status = 'In Repair'
                )) AS is_under_maintenance
           FROM vehicles v
          WHERE v.status <> 'On Trip'
          ORDER BY v.id"
    )->fetchAll(),
    'drivers'  => $pdo->query("SELECT id, name, status, safety_score FROM drivers WHERE status <> 'On Trip' ORDER BY name")->fetchAll(),
];

$active_page = 'dispatch-board';
$page_title  = 'Operational Dispatch Board';
require ROOT_PATH . '/includes/header.php';

 
function kanban_card(array $r): string
{
    $short_id = preg_replace('/^RES-2026-/', 'RES-', $r['id']);
    $vehicle  = $r['assigned_vehicle_id'] ? $r['assigned_vehicle_id'] : 'No Vehicle';
    $dispatchLocked = !in_array($r['status'], ['Pending', 'Assigned', 'Confirmed'], true);
    $cardClass = $dispatchLocked ? 'kanban-card opacity-75' : 'kanban-card';
    $cardAction = $dispatchLocked
        ? ' aria-disabled="true" title="This reservation has already been dispatched or is locked" style="cursor:default"'
        : ' onclick="App.openDispatchModal(\'' . e($r['id']) . '\')"';
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
          <span class="text-primary-custom fw-semibold">' . money($r['estimated_cost']) . '</span>
        </div>
      </div>';
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Operational Dispatch Board</h1>
    <p class="text-muted-custom mb-0">Workflow: Reservation → Vehicle Selection → Driver Assignment → Dispatch → In Transit → Completed.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/reservations.php">Reservations List</a>
  </div>
</div>

 
<div class="dispatch-kanban-board">
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
      <span><i class="bi bi-check-all me-1 text-success"></i> Completed</span>
      <span class="badge bg-success-subtle text-success"><?= count($columns['completed']) ?></span>
    </div>
    <div id="kanban-completed">
      <?php foreach ($columns['completed'] as $r) echo kanban_card($r); ?>
      <?php if (empty($columns['completed'])): ?><div class="text-muted-custom small text-center py-3">Empty</div><?php endif; ?>
    </div>
  </div>
</div>

 
<div id="modal-dispatch" class="tc-modal-backdrop">
  <div class="tc-modal">
    <div class="tc-card-header">
      <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-send me-2 text-primary-custom"></i>Dispatch Vehicle & Driver</h4>
      <button type="button" class="btn-close" onclick="App.closeModal('modal-dispatch')"></button>
    </div>
    <div class="tc-card-body" id="modal-dispatch-body">
       
    </div>
  </div>
</div>

<script>
  window.TC_CAN_DISPATCH = <?= can('dispatch.manage') ? 'true' : 'false' ?>;
  window.TC_DISPATCH_DATA = <?= json_encode($dispatch_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.TC_KANBAN_RESERVATIONS = <?= json_encode(array_map(function ($r) {
      return [
          'id' => $r['id'], 'clientName' => $r['client_name'], 'contactPhone' => $r['contact_phone'],
          'passengerCount' => (int)$r['passenger_count'], 'origin' => $r['origin'], 'destination' => $r['destination'],
          'departureDate' => $r['departure_date'], 'departureTime' => $r['departure_time'],
          'assignedVehicle' => $r['assigned_vehicle_id'] ? $r['assigned_vehicle_id'] . ' (' . $r['plate_number'] . ')' : 'Pending',
          'assignedDriver' => $r['driver_name'] ?? 'Pending', 'status' => $r['status'],
          'tripType' => $r['trip_type'], 'notes' => $r['notes'], 'estimatedCost' => money($r['estimated_cost']),
      ];
  }, $reservations), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
