<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_compliance.php';
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
    "SELECT r.*, v.plate_number, d.name AS driver_name, d.id AS driver_id_code,
            CASE WHEN r.departure_schedule_instance_id IS NULL THEN r.passenger_count ELSE (
              SELECT COALESCE(SUM(sr.passenger_count),0) FROM reservations sr
               WHERE (sr.departure_schedule_instance_id=r.departure_schedule_instance_id OR sr.return_schedule_instance_id=r.departure_schedule_instance_id)
                 AND sr.status IN ('Pending Approval','Approved','Pending','Assigned','Confirmed','Dispatched','In Transit')
            ) END AS scheduled_passenger_demand,
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
      ORDER BY r.created_at DESC, r.id DESC"
)->fetchAll();

 
$dispatch_data = [
  'vehicles' => $dispatchVehicles,
    'drivers'  => $pdo->query("SELECT d.id,d.name,d.status,d.safety_score,
        EXISTS (SELECT 1 FROM trips active_t WHERE active_t.driver_id=d.id AND active_t.status IN ('In Transit','Returning to Depot')) AS has_active_trip
        FROM drivers d ORDER BY d.name")->fetchAll(),
];

$active_page = 'reservations';
$body_class = trim(($body_class ?? '') . ' reservation-dispatch-module reservations-page');
$page_title  = 'Reservations — Vehicle Reservation & Dispatch System';
require ROOT_PATH . '/includes/header.php';
?>

<div class="reservation-dispatch-page-shell">
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h1 class="mb-1">Vehicle Reservation & Dispatch System (VRDS)</h1>
    <p class="text-muted-custom mb-0">Manage tour client bookings, passenger capacity matching, vehicle allocation, and dispatch lifecycles.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/dispatch-board.php"><i class="bi bi-kanban"></i> Open Dispatch Board</a>
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/trip-schedule.php"><i class="bi bi-calendar3"></i> View Trip Schedule</a>
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
          <tr id="record-<?= e($r['id']) ?>" data-reservation="<?= e(json_encode([
              'id' => $r['id'],
              'clientName' => $r['client_name'],
              'contactPhone' => $r['contact_phone'],
              'passengerCount' => (int)$r['passenger_count'],
              'requiredVehicleType' => $r['vehicle_requested'],
              'requiredCapacity' => $r['required_capacity'] === null ? null : (int)$r['required_capacity'],
              'scheduledPassengerDemand' => (int)$r['scheduled_passenger_demand'],
              'origin' => $r['origin'],
              'destination' => $r['destination'],
              'departureScheduleInstanceId' => $r['departure_schedule_instance_id'], 'departureDate' => $r['departure_date'],
              'departureTime' => $r['departure_time'],
              'assignedVehicle' => $r['assigned_vehicle_id'] ? $r['assigned_vehicle_id'] . ' (' . $r['plate_number'] . ')' : 'Pending',
              'assignedDriver' => $r['driver_name'] ?? 'Pending',
              'status' => $r['status'],
              'tripType' => $r['trip_type'],
              'notes' => $r['notes'],
              'estimatedCost' => money($r['estimated_cost']),
              'totalBookingFare' => $r['total_booking_fare'] !== null ? money($r['total_booking_fare']) : '',
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
            <td><span class="status-badge <?= status_badge_class($r['status']) ?>"><?= e($r['status']) ?></span><?php if ($r['rejection_reason']): ?><div class="small text-danger mt-1">Reason: <?= e($r['rejection_reason']) ?></div><?php endif; ?><?php if ($r['cancellation_reason']): ?><div class="small text-muted-custom mt-1">Reason: <?= e($r['cancellation_reason']) ?></div><?php endif; ?></td>
            <td>
              <div class="small fw-medium"><?= $r['assigned_vehicle_id'] ? e($r['assigned_vehicle_id']) . ' (' . e($r['plate_number']) . ')' : '<span class="text-muted-custom">Pending</span>' ?></div>
              <div class="text-muted-custom small"><?= e($r['driver_name'] ?? 'Pending') ?></div>
            </td>
            <td class="reservation-actions-cell">
              <div class="reservation-actions">
              <button class="tc-btn tc-btn-secondary tc-btn-sm reservation-action-btn" type="button" onclick="openReservationDetails('<?= e($r['id']) ?>')">
                <i class="bi bi-eye"></i> Details
              </button>
              <?php if (can('ai.view') && $r['assigned_vehicle_id']): ?>
                <a class="tc-btn tc-btn-secondary tc-btn-sm reservation-action-btn" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php?<?= !empty($r['trip_id']) ? 'trip_id=' . urlencode($r['trip_id']) : 'reservation_id=' . urlencode($r['id']) ?>" onclick="event.stopPropagation()">
                  <i class="bi bi-signpost-split"></i> Route
                </a>
              <?php endif; ?>
              <?php if (has_role('fleet_manager') && !empty($r['trip_id'])): ?>
                <button type="button" class="tc-btn tc-btn-secondary tc-btn-sm" onclick="TripFunding.open('<?= e($r['id']) ?>')">View Trip Funding</button>
              <?php endif; ?>
              <?php if (has_role(['fleet_admin','dispatcher']) && $r['status'] === 'Pending Approval'): ?>
                <form method="post" action="<?= BASE_URL ?>/actions/reservation-review.php" class="d-inline"><input type="hidden" name="reservation_id" value="<?= e($r['id']) ?>"><input type="hidden" name="decision" value="approve"><button class="tc-btn tc-btn-primary tc-btn-sm" type="submit">Approve</button></form>
                <button class="tc-btn tc-btn-outline-danger tc-btn-sm" type="button" onclick="openReservationReject('<?= e($r['id']) ?>')">Reject</button>
              <?php elseif (can('dispatch.manage') && in_array($r['status'], ['Approved', 'Assigned', 'Confirmed'], true)): ?>
                <?php if (has_role(['fleet_admin','dispatcher']) && in_array($r['status'],['Assigned','Confirmed'],true)): ?>
                <button type="button" class="tc-btn tc-btn-secondary tc-btn-sm reservation-action-btn" onclick="TripFunding.open('<?= e($r['id']) ?>')"><i class="bi bi-wallet2"></i> Trip Funding</button>
                <?php endif; ?>
                <button class="tc-btn tc-btn-outline-danger tc-btn-sm reservation-action-btn" type="button" onclick="App.openReservationCancellationModal('<?= e($r['id']) ?>', 'cancel_reservation')">
                  <i class="bi bi-x-circle"></i> Cancel
                </button>
                <button class="tc-btn tc-btn-primary tc-btn-sm reservation-action-btn" type="button" onclick="App.openDispatchModal('<?= e($r['id']) ?>', 'assign')">
                  <i class="bi bi-person-check"></i> <?= in_array($r['status'], ['Assigned', 'Confirmed'], true) ? 'Update Assignment' : 'Assign' ?>
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

<div id="modal-reservation-details" class="tc-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="reservation-details-title">
  <div class="tc-modal tc-modal-lg">
    <div class="tc-card-header d-flex justify-content-between align-items-center">
      <h4 class="mb-0 fw-bold fs-6" id="reservation-details-title"><i class="bi bi-clipboard2-check me-2 text-primary-custom"></i>Reservation Details</h4>
      <button type="button" class="btn-close" aria-label="Close" onclick="App.closeModal('modal-reservation-details')"></button>
    </div>
    <div class="tc-card-body" id="reservation-details-body" aria-live="polite"></div>
    <div class="p-3 border-top d-flex justify-content-end gap-2" id="reservation-details-actions">
      <button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-reservation-details')">Close</button>
    </div>
  </div>
</div>

<div id="modal-reservation-reject" class="tc-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="reservation-reject-title">
  <div class="tc-modal">
    <div class="tc-card-header d-flex justify-content-between align-items-center">
      <h4 class="mb-0 fw-bold fs-6" id="reservation-reject-title"><i class="bi bi-x-circle me-2 text-danger"></i>Reject Reservation</h4>
      <button type="button" class="btn-close" aria-label="Close" onclick="App.closeModal('modal-reservation-reject')"></button>
    </div>
    <form method="post" action="<?= BASE_URL ?>/actions/reservation-review.php" id="reservation-reject-form">
      <div class="tc-card-body">
        <input type="hidden" name="reservation_id" id="reservation-reject-id">
        <input type="hidden" name="decision" value="reject">
        <p class="small text-muted-custom">Provide a reason for rejecting <strong id="reservation-reject-reference"></strong>. The customer will see this reason.</p>
        <label class="tc-form-label" for="reservation-reject-reason">Rejection Reason *</label>
        <textarea class="tc-form-control" id="reservation-reject-reason" name="reason" rows="3" maxlength="2000" required></textarea>
      </div>
      <div class="p-3 border-top d-flex justify-content-end gap-2">
        <button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-reservation-reject')">Cancel</button>
        <button type="submit" class="tc-btn tc-btn-outline-danger">Confirm Reject</button>
      </div>
    </form>
  </div>
</div>
<script>
function openReservationDetails(reservationId) {
  const body = document.getElementById('reservation-details-body');
  const actions = document.getElementById('reservation-details-actions');
  body.classList.add('rd-loading-content');
  body.textContent = 'Loading reservation details and checking current availability...';
  actions.replaceChildren(actions.firstElementChild);
  App.openModal('modal-reservation-details');

  fetch('<?= BASE_URL ?>/actions/reservation-availability.php?id=' + encodeURIComponent(reservationId), {
    cache: 'no-store',
    credentials: 'same-origin',
  }).then(async (response) => {
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || 'Availability could not be checked.');
    renderReservationDetails(data);
  }).catch((error) => {
    body.classList.remove('rd-loading-content');
    body.textContent = error.message || 'Reservation details could not be loaded.';
  });
}

function renderReservationDetails(data) {
  const body = document.getElementById('reservation-details-body');
  const actions = document.getElementById('reservation-details-actions');
  const reservation = data.reservation;
  const availability = data.availability;
  body.classList.remove('rd-loading-content');
  body.replaceChildren();

  const heading = document.createElement('div');
  heading.className = 'mb-3';
  const title = document.createElement('h5');
  title.className = 'mb-1';
  title.textContent = reservation.id + ' · ' + reservation.client_name;
  const subtitle = document.createElement('div');
  subtitle.className = 'small text-muted-custom';
  subtitle.textContent = reservation.status;
  heading.append(title, subtitle);
  body.append(heading);

  const fields = [
    ['Contact person', reservation.contact_person || '—'],
    ['Contact number', reservation.contact_phone || '—'],
    ['Route', reservation.origin + ' → ' + reservation.destination],
    ['Departure schedule', reservation.departure_date + ' ' + (reservation.departure_time || '') + (reservation.departure_schedule_name ? ' · ' + reservation.departure_schedule_name : '')],
    ['Return schedule', reservation.return_date ? reservation.return_date + ' ' + (reservation.return_time || '') + (reservation.return_schedule_name ? ' · ' + reservation.return_schedule_name : '') : '—'],
    ['Passengers', String(reservation.passenger_count)],
    ['Required vehicle type', reservation.vehicle_requested || 'No type specified'],
    ['Required capacity', reservation.required_capacity ? reservation.required_capacity + ' passengers' : 'Not recorded'],
    ['Scheduled passenger demand', reservation.scheduled_passenger_demand !== null ? reservation.scheduled_passenger_demand + ' of ' + reservation.scheduled_capacity + ' seats held' : 'Legacy reservation'],
    ['Trip type', reservation.trip_type || '—'],
    ['Route distance', reservation.route_distance_km || '—'],
    ['Base fare used', reservation.base_fare_used || '—'],
    ['Rate / km used', reservation.rate_per_km_used ? reservation.rate_per_km_used + ' / km' : '—'],
    ['Distance charge', reservation.distance_charge || '—'],
    ['Fare per person', reservation.fare_per_person || '—'],
    ['Total booking fare', reservation.total_booking_fare || reservation.estimated_cost || '—'],
    ['Fare status', reservation.fare_status || '—'],
    ['Special requests', reservation.notes || 'None'],
  ];
  const grid = document.createElement('div');
  grid.className = 'row g-3 small mb-4';
  fields.forEach(([label, value]) => {
    const column = document.createElement('div');
    column.className = label === 'Special requests' ? 'col-12' : 'col-sm-6';
    const caption = document.createElement('div');
    caption.className = 'text-muted-custom';
    caption.textContent = label;
    const content = document.createElement('div');
    content.className = 'fw-semibold';
    content.textContent = value;
    column.append(caption, content);
    grid.append(column);
  });
  body.append(grid);

  if (!availability.applicable) {
    const notice = document.createElement('div');
    notice.className = 'alert alert-secondary py-2 mb-0';
    notice.textContent = 'Live availability checking is shown only while a reservation is Pending Approval.';
    body.append(notice);
    return;
  }

  const ready = availability.vehicles.available_count > 0 && availability.drivers.available_count > 0;
  const summary = document.createElement('div');
  summary.className = 'alert ' + (ready ? 'alert-success' : 'alert-warning') + ' py-2';
  summary.textContent = ready
    ? 'A matching vehicle and driver are currently available, using a ' + availability.buffer_hours + '-hour dispatch buffer.'
    : 'A suitable vehicle or driver is unavailable, or there is a schedule conflict. Review the breakdown before deciding.';
  body.append(summary);

  const resourceGrid = document.createElement('div');
  resourceGrid.className = 'row g-3';
  appendAvailabilityGroup(resourceGrid, 'Vehicle', availability.vehicles);
  appendAvailabilityGroup(resourceGrid, 'Driver', availability.drivers);
  body.append(resourceGrid);

  const note = document.createElement('p');
  note.className = 'small text-muted-custom mt-3 mb-0';
  note.textContent = 'This is an availability snapshot for vehicles, drivers, and schedules using a ' + availability.buffer_hours + '-hour dispatch buffer. Route coverage and customer details are not checked. Resources will be rechecked at dispatch. The reservation is not automatically approved or rejected.';
  body.append(note);

  if (reservation.status === 'Pending Approval' && data.can_review) {
    const approveForm = document.createElement('form');
    approveForm.method = 'post';
    approveForm.action = '<?= BASE_URL ?>/actions/reservation-review.php';
    approveForm.className = 'd-inline';
    [['reservation_id', reservation.id], ['decision', 'approve']].forEach(([name, value]) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      approveForm.append(input);
    });
    const approveButton = document.createElement('button');
    approveButton.type = 'submit';
    approveButton.className = 'tc-btn tc-btn-primary';
    approveButton.textContent = 'Approve';
    approveForm.append(approveButton);
    const rejectButton = document.createElement('button');
    rejectButton.type = 'button';
    rejectButton.className = 'tc-btn tc-btn-outline-danger';
    rejectButton.textContent = 'Reject';
    rejectButton.addEventListener('click', () => {
      App.closeModal('modal-reservation-details');
      openReservationReject(reservation.id);
    });
    actions.append(approveForm, rejectButton);
  }
}

function appendAvailabilityGroup(parent, title, resource) {
  const column = document.createElement('div');
  column.className = 'col-md-6';
  const section = document.createElement('section');
  section.className = 'border rounded p-3 h-100';
  const heading = document.createElement('h6');
  heading.className = 'fw-bold';
  heading.textContent = title + ' · ' + resource.available_count + ' available';
  section.append(heading);

  if (typeof resource.matched_count === 'number') {
    const matched = document.createElement('div');
    matched.className = 'small text-muted-custom mb-2';
    matched.textContent = resource.matched_count + ' match the required vehicle type and passenger capacity.';
    section.append(matched);
  }
  if (resource.available_items.length) {
    const availableHeading = document.createElement('div');
    availableHeading.className = 'small fw-semibold';
    availableHeading.textContent = 'Available';
    section.append(availableHeading);
    const availableList = document.createElement('ul');
    availableList.className = 'small ps-3 mb-2';
    if (title === 'Driver') availableList.classList.add('reservation-available-drivers');
    resource.available_items.forEach((entry) => {
      const item = document.createElement('li');
      item.textContent = entry;
      availableList.append(item);
    });
    section.append(availableList);
  }
  if (resource.blockers.length) {
    const blockerHeading = document.createElement('div');
    blockerHeading.className = 'small fw-semibold';
    blockerHeading.textContent = title === 'Vehicle'
      ? 'Vehicles not eligible for this booking'
      : 'Drivers not currently available for this booking';
    section.append(blockerHeading);
    const blockerList = document.createElement('ul');
    blockerList.className = 'small ps-3 mb-0';
    resource.blockers.forEach((blocker) => {
      const item = document.createElement('li');
      item.textContent = blocker.count + ' · ' + blocker.reason;
      if (Array.isArray(blocker.details) && blocker.details.length) {
        const details = document.createElement('ul');
        details.className = 'text-muted-custom ps-3 mt-1';
        blocker.details.forEach((description) => {
          const detail = document.createElement('li');
          detail.textContent = description;
          details.append(detail);
        });
        item.append(details);
      }
      blockerList.append(item);
    });
    section.append(blockerList);
  }
  if (!resource.available_items.length && !resource.blockers.length) {
    const item = document.createElement('p');
    item.className = 'small mb-0';
    item.textContent = 'No matching resources found.';
    section.append(item);
  }
  column.append(section);
  parent.append(column);
}

function openReservationReject(reservationId) {
  document.getElementById('reservation-reject-form').reset();
  document.getElementById('reservation-reject-id').value = reservationId;
  document.getElementById('reservation-reject-reference').textContent = reservationId;
  App.openModal('modal-reservation-reject');
  document.getElementById('reservation-reject-reason').focus();
}
</script>

<div id="modal-reservation-cancellation" class="tc-modal-backdrop">
  <div class="tc-modal tc-modal-lg">
    <div class="tc-card-header">
      <h4 class="mb-0 fw-bold fs-6" id="reservation-cancellation-title"><i class="bi bi-x-octagon me-2 text-danger"></i>Cancel Reservation</h4>
      <button type="button" class="btn-close" onclick="App.closeModal('modal-reservation-cancellation')"></button>
    </div>
    <div class="tc-card-body" id="reservation-cancellation-body"></div>
  </div>
</div>


<script>
  window.TC_CAN_DISPATCH = <?= can('dispatch.manage') ? 'true' : 'false' ?>;
  window.TC_ASSIGNMENT_CSRF = <?= json_encode($_SESSION['assignment_csrf'] ??= bin2hex(random_bytes(32))) ?>;
  window.TC_DISPATCH_DATA = <?= json_encode($dispatch_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.TC_KANBAN_RESERVATIONS = <?= json_encode(array_map(function ($r) {
      return [
          'id' => $r['id'], 'clientName' => $r['client_name'], 'contactPhone' => $r['contact_phone'],
          'passengerCount' => (int)$r['passenger_count'], 'requiredVehicleType' => $r['vehicle_requested'],
          'requiredCapacity' => $r['required_capacity'] === null ? null : (int)$r['required_capacity'], 'origin' => $r['origin'], 'destination' => $r['destination'],
          'scheduledPassengerDemand' => (int)$r['scheduled_passenger_demand'],
          'departureScheduleInstanceId' => $r['departure_schedule_instance_id'], 'departureDate' => $r['departure_date'], 'departureTime' => $r['departure_time'],
          'assignedVehicle' => $r['assigned_vehicle_id'] ? $r['assigned_vehicle_id'] . ' (' . $r['plate_number'] . ')' : 'Pending',
          'assignedVehicleId' => $r['assigned_vehicle_id'], 'assignedDriverId' => $r['assigned_driver_id'],
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

<script src="<?= BASE_URL ?>/js/reservation-dispatch.js?v=<?= (int)filemtime(ROOT_PATH . '/js/reservation-dispatch.js') ?>"></script>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
