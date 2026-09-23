<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('dispatch.view');

$pdo = db();

$reservations = $pdo->query(
    "SELECT r.*, v.plate_number, d.name AS driver_name, d.id AS driver_id_code,
            cv.plate_number AS cancelled_vehicle_plate,
            cd.name AS cancelled_driver_name,
            u.name AS cancelled_by_name,
            t.id AS trip_id
       FROM reservations r
       LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
       LEFT JOIN drivers d  ON d.id = r.assigned_driver_id
       LEFT JOIN vehicles cv ON cv.id = r.cancelled_vehicle_id
       LEFT JOIN drivers cd ON cd.id = r.cancelled_driver_id
       LEFT JOIN users u ON u.id = r.cancelled_by
       LEFT JOIN LATERAL (
           SELECT trip.id
             FROM trips trip
            WHERE trip.reservation_id = r.id
            ORDER BY trip.created_at DESC
            LIMIT 1
       ) t ON TRUE
      ORDER BY r.departure_date, r.departure_time"
)->fetchAll();

 
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

$active_page = 'reservations';
$page_title  = 'Reservations — Vehicle Reservation & Dispatch System';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h1 class="mb-1">Vehicle Reservation & Dispatch System (VRDS)</h1>
    <p class="text-muted-custom mb-0">Manage tour client bookings, passenger capacity matching, vehicle allocation, and dispatch lifecycles.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/dispatch-board.php"><i class="bi bi-kanban"></i> Open Dispatch Board</a>
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/trip-schedule.php"><i class="bi bi-calendar3"></i> View Trip Schedule</a>
    <?php if (can('dispatch.manage')): ?>
      <button class="tc-btn tc-btn-primary tc-btn-sm" onclick="App.openModal('modal-add-reservation')"><i class="bi bi-plus-lg"></i> New Reservation</button>
    <?php endif; ?>
  </div>
</div>

<div class="tc-card mb-4">
  <div class="tc-card-header">
    <h4 class="mb-0 fw-bold fs-6">Tour & Group Reservations Queue</h4>
  </div>
  <div class="tc-table-container border-0">
    <table class="tc-table">
      <thead>
        <tr>
          <th>Reservation ID</th>
          <th>Client / Tour Group</th>
          <th>Origin & Destination</th>
          <th>Departure Date & Time</th>
          <th>Pax</th>
          <th>Status</th>
          <th>Allocated Resource</th>
          <th class="text-end reservation-actions-heading">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reservations)): ?>
          <tr><td colspan="8" class="text-center text-muted-custom py-4">No reservations on file.</td></tr>
        <?php else: foreach ($reservations as $r): ?>
          <tr data-reservation="<?= e(json_encode([
              'id' => $r['id'],
              'clientName' => $r['client_name'],
              'contactPhone' => $r['contact_phone'],
              'passengerCount' => (int)$r['passenger_count'],
              'origin' => $r['origin'],
              'destination' => $r['destination'],
              'departureDate' => $r['departure_date'],
              'departureTime' => $r['departure_time'],
              'assignedVehicle' => $r['assigned_vehicle_id'] ? $r['assigned_vehicle_id'] . ' (' . $r['plate_number'] . ')' : 'Pending',
              'assignedDriver' => $r['driver_name'] ?? 'Pending',
              'status' => $r['status'],
              'tripType' => $r['trip_type'],
              'notes' => $r['notes'],
              'estimatedCost' => money($r['estimated_cost']),
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
            <td><span class="fw-semibold text-primary-custom"><?= e($r['id']) ?></span></td>
            <td>
              <div class="fw-semibold"><?= e($r['client_name']) ?></div>
              <div class="text-muted-custom small"><i class="bi bi-telephone me-1"></i><?= e($r['contact_phone']) ?></div>
            </td>
            <td>
              <div class="small fw-medium"><i class="bi bi-geo-alt text-success me-1"></i><?= e($r['origin']) ?></div>
              <div class="small fw-medium"><i class="bi bi-flag text-danger me-1"></i><?= e($r['destination']) ?></div>
            </td>
            <td>
              <div><?= e($r['departure_date']) ?></div>
              <div class="text-muted-custom small"><?= e($r['departure_time']) ?></div>
            </td>
            <td><span class="badge bg-light text-dark border"><?= (int)$r['passenger_count'] ?> pax</span></td>
            <td><span class="status-badge <?= status_badge_class($r['status']) ?>"><?= e($r['status']) ?></span></td>
            <td>
              <div class="small fw-medium"><?= $r['assigned_vehicle_id'] ? e($r['assigned_vehicle_id']) . ' (' . e($r['plate_number']) . ')' : '<span class="text-muted-custom">Pending</span>' ?></div>
              <div class="text-muted-custom small"><?= e($r['driver_name'] ?? 'Pending') ?></div>
            </td>
            <td class="reservation-actions-cell">
              <div class="reservation-actions">
              <?php if (can('ai.view') && $r['assigned_vehicle_id']): ?>
                <a class="tc-btn tc-btn-secondary tc-btn-sm reservation-action-btn" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php?reservation_id=<?= urlencode($r['id']) ?>" onclick="event.stopPropagation()">
                  <i class="bi bi-signpost-split"></i> Route
                </a>
              <?php endif; ?>
              <?php if (can('dispatch.manage') && in_array($r['status'], ['Pending', 'Assigned', 'Confirmed'], true)): ?>
                <button class="tc-btn tc-btn-outline-danger tc-btn-sm reservation-action-btn" type="button" onclick="App.openReservationCancellationModal('<?= e($r['id']) ?>', 'cancel_reservation')">
                  <i class="bi bi-x-circle"></i> Cancel
                </button>
                <button class="tc-btn tc-btn-primary tc-btn-sm reservation-action-btn" type="button" onclick="App.openDispatchModal('<?= e($r['id']) ?>')">
                  <i class="bi bi-send"></i> Dispatch
                </button>
              <?php elseif (can('dispatch.manage') && $r['status'] === 'Dispatched'): ?>
                <button class="tc-btn tc-btn-outline-danger tc-btn-sm reservation-action-btn reservation-action-wide" type="button" onclick="App.openReservationCancellationModal('<?= e($r['id']) ?>', 'recall_dispatch')">
                  <i class="bi bi-arrow-counterclockwise"></i> Recall Dispatch
                </button>
              <?php elseif ($r['status'] === 'Cancelled'): ?>
                <button class="tc-btn tc-btn-secondary tc-btn-sm reservation-action-btn reservation-action-wide" type="button" onclick="App.openCancelledReservationDetails('<?= e($r['id']) ?>')">
                  <i class="bi bi-eye"></i> Cancellation Details
                </button>
              <?php else: ?>
                <span class="text-muted-custom small">—</span>
              <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
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

<div id="modal-reservation-cancellation" class="tc-modal-backdrop">
  <div class="tc-modal tc-modal-lg">
    <div class="tc-card-header">
      <h4 class="mb-0 fw-bold fs-6" id="reservation-cancellation-title"><i class="bi bi-x-octagon me-2 text-danger"></i>Cancel Reservation</h4>
      <button type="button" class="btn-close" onclick="App.closeModal('modal-reservation-cancellation')"></button>
    </div>
    <div class="tc-card-body" id="reservation-cancellation-body"></div>
  </div>
</div>

<?php if (can('dispatch.manage')): ?>
 
<div id="modal-add-reservation" class="tc-modal-backdrop">
  <div class="tc-modal tc-modal-lg">
    <div class="tc-card-header">
      <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-plus-circle me-2 text-primary-custom"></i>Create Tour Reservation</h4>
      <button type="button" class="btn-close" onclick="App.closeModal('modal-add-reservation')"></button>
    </div>
    <div class="tc-card-body">
      <form method="post" action="<?= BASE_URL ?>/actions/reservation.php">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/vehicle-reservation-dispatch/reservations.php') ?>">
        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <label class="tc-form-label">Client / Tour Group</label>
            <input type="text" name="client_name" class="tc-form-control" required>
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Contact Person</label>
            <input type="text" name="contact_person" class="tc-form-control">
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Contact Phone</label>
            <input type="text" name="contact_phone" class="tc-form-control">
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Passenger Count</label>
            <input type="number" name="passenger_count" class="tc-form-control" min="1" required>
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Origin</label>
            <input type="text" name="origin" class="tc-form-control" required>
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Destination</label>
            <input type="text" name="destination" class="tc-form-control" required>
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Departure Date</label>
            <input type="date" name="departure_date" class="tc-form-control" required>
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Departure Time</label>
            <select class="tc-form-select" name="departure_time">
              <option>05:00 AM</option>
              <option>06:00 AM</option>
              <option>08:00 AM</option>
              <option>09:30 AM</option>
              <option>02:00 PM</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Return Date</label>
            <input type="date" name="return_date" class="tc-form-control">
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Trip Type</label>
            <select class="tc-form-select" name="trip_type">
              <option>Day Tour & Transfer</option>
              <option>Multi-Day Tour</option>
              <option>Educational Heritage Tour</option>
              <option>VIP Executive Airport Transfer</option>
              <option>School Field Trip</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Vehicle Requested</label>
            <select class="tc-form-select" name="vehicle_requested">
              <option>Tour Bus (45-50 pax)</option>
              <option>Coaster Bus (29 pax)</option>
              <option>Executive Van (14-15 pax)</option>
              <option>VIP SUV (7 pax)</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Estimated Cost (<?= e(currency_symbol()) ?>)</label>
            <input type="number" step="0.01" name="estimated_cost" class="tc-form-control">
          </div>
          <div class="col-12">
            <label class="tc-form-label">Notes</label>
            <textarea name="notes" class="tc-form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-add-reservation')">Cancel</button>
          <button type="submit" class="tc-btn tc-btn-primary">Save Reservation</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  window.TC_CAN_DISPATCH = <?= can('dispatch.manage') ? 'true' : 'false' ?>;
  window.TC_DISPATCH_DATA = <?= json_encode($dispatch_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.TC_KANBAN_RESERVATIONS = <?= json_encode(array_map(function ($r) {
      return [
          'id' => $r['id'], 'clientName' => $r['client_name'], 'contactPhone' => $r['contact_phone'],
          'passengerCount' => (int)$r['passenger_count'], 'origin' => $r['origin'], 'destination' => $r['destination'],
          'departureDate' => $r['departure_date'], 'departureTime' => $r['departure_time'],
          'assignedVehicle' => $r['assigned_vehicle_id'] ? $r['assigned_vehicle_id'] . ' (' . $r['plate_number'] . ')' : 'Pending',
          'assignedDriver' => $r['driver_name'] ?? 'Pending', 'status' => $r['status'], 'tripId' => $r['trip_id'],
          'tripType' => $r['trip_type'], 'notes' => $r['notes'], 'estimatedCost' => money($r['estimated_cost']),
          'cancellationType' => $r['cancellation_type'], 'cancellationReason' => $r['cancellation_reason'],
          'cancellationNotes' => $r['cancellation_notes'], 'cancelledBy' => $r['cancelled_by_name'],
          'cancelledAt' => $r['cancelled_at'],
          'cancelledVehicle' => $r['cancelled_vehicle_id'] ? $r['cancelled_vehicle_id'] . ' (' . $r['cancelled_vehicle_plate'] . ')' : null,
          'cancelledDriver' => $r['cancelled_driver_name'],
      ];
  }, $reservations), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
