<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('driver.portal');

$pdo = db();

$driver = $pdo->prepare('SELECT * FROM drivers WHERE user_id = ?');
$driver->execute([$current_user['id']]);
$driver = $driver->fetch();

$myTrips = [];
if ($driver) {
    $tripsStmt = $pdo->prepare(
        'SELECT r.*, v.plate_number, v.odometer AS vehicle_odometer, t.id AS trip_id, t.route_history_id FROM reservations r
          LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
          LEFT JOIN trips t ON t.reservation_id = r.id
         WHERE r.assigned_driver_id = ? ORDER BY r.departure_date, r.departure_time'
    );
    $tripsStmt->execute([$driver['id']]);
    $myTrips = $tripsStmt->fetchAll();
}

$active_page = 'driver-trips';
$page_title  = 'My Trips';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">My Trips</h1>
    <p class="text-muted-custom mb-0">All trips assigned to you, with status and route details.</p>
  </div>
  <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/driver-portal/driver-dashboard.php">← Back to Dashboard</a>
</div>

<div class="tc-card">
  <div class="tc-table-container border-0">
    <table class="tc-table">
      <thead>
        <tr>
          <th>Trip ID</th>
          <th>Client / Tour Group</th>
          <th>Origin & Destination</th>
          <th>Departure</th>
          <th>Pax</th>
          <th>Status</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($myTrips)): ?>
          <tr><td colspan="7" class="text-center text-muted-custom py-4">No trips assigned to you yet.</td></tr>
        <?php else: foreach ($myTrips as $r): ?>
          <tr>
            <td><strong class="text-primary-custom"><?= e($r['id']) ?></strong></td>
            <td>
              <div class="fw-semibold"><?= e($r['client_name']) ?></div>
              <div class="text-muted-custom small"><?= e($r['trip_type']) ?></div>
            </td>
            <td>
              <div class="small fw-medium"><i class="bi bi-geo-alt text-success me-1"></i><?= e($r['origin']) ?></div>
              <div class="small fw-medium"><i class="bi bi-flag text-danger me-1"></i><?= e($r['destination']) ?></div>
            </td>
            <td><?= e($r['departure_date']) ?><br><span class="text-muted-custom small"><?= e($r['departure_time']) ?></span></td>
            <td><span class="badge bg-light text-dark border"><?= (int)$r['passenger_count'] ?> pax</span></td>
            <td><span class="status-badge <?= status_badge_class($r['status']) ?>"><?= e($r['status']) ?></span></td>
            <td class="text-end">
              <?php if ($r['status'] !== 'Completed' && !empty($r['assigned_vehicle_id'])): ?>
                <details class="d-inline-block text-start me-1">
                  <summary class="tc-btn tc-btn-secondary tc-btn-sm list-unstyled"><i class="bi bi-exclamation-triangle"></i> Report Issue</summary>
                  <form method="post" action="<?= BASE_URL ?>/actions/driver-vehicle-issue.php" class="mt-2 p-2 border rounded bg-light" style="min-width:260px;">
                    <input type="hidden" name="vehicle_id" value="<?= e($r['assigned_vehicle_id']) ?>">
                    <input type="hidden" name="trip_id" value="<?= e($r['trip_id'] ?? '') ?>">
                    <input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/driver-portal/driver-trips.php') ?>">
                    <label class="tc-form-label">Issue type</label>
                    <select class="tc-form-select mb-2" name="issue_type" required>
                      <option value="">Select issue</option><option>Engine / Mechanical</option><option>Brakes / Steering</option>
                      <option>Tires / Wheels</option><option>Electrical</option><option>Body / Safety Equipment</option><option>Other</option>
                    </select>
                    <label class="tc-form-label">Severity</label>
                    <select class="tc-form-select mb-2" name="severity" required>
                      <option value="Critical">Critical</option><option value="Medium" selected>Medium</option><option value="Low">Low</option>
                    </select>
                    <label class="tc-form-label">Description</label>
                    <textarea class="tc-form-control mb-2" name="description" rows="2" maxlength="1000" required></textarea>
                    <button type="submit" class="tc-btn tc-btn-primary tc-btn-sm w-100">Send to Maintenance</button>
                  </form>
                </details>
              <?php endif; ?>
              <?php if ($r['status'] === 'Completed'): ?>
                <span class="text-muted-custom small">Completed</span>
              <?php elseif (!in_array($r['status'], ['In Transit','Returning to Depot'], true) && !empty($r['trip_id'])): ?>
                <a class="tc-btn tc-btn-primary tc-btn-sm" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php?trip_id=<?= urlencode($r['trip_id'] ?? '') ?>">
                  <i class="bi bi-compass"></i> Open Navigation
                </a>
              <?php elseif (!in_array($r['status'], ['In Transit','Returning to Depot'], true)): ?>
                <span class="tc-btn tc-btn-secondary tc-btn-sm disabled" title="Dispatch must create the operational trip before navigation can start">
                  <i class="bi bi-hourglass-split"></i> Awaiting Dispatch
                </span>
              <?php elseif ($r['status'] === 'In Transit'): ?>
                <a class="tc-btn tc-btn-primary tc-btn-sm me-1" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php?trip_id=<?= urlencode($r['trip_id'] ?? '') ?>">
                  <i class="bi bi-compass-fill"></i> Resume Navigation
                </a>
                <form method="post" action="<?= BASE_URL ?>/actions/trip-status.php" class="d-inline-block">
                  <input type="hidden" name="reservation_id" value="<?= e($r['id']) ?>">
                  <input type="hidden" name="status" value="Returning to Depot">
                  <input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/driver-portal/driver-trips.php') ?>">
                  <button type="submit" class="tc-btn tc-btn-primary tc-btn-sm"><i class="bi bi-arrow-return-left"></i> Passenger Drop-off / Return</button>
                </form>
              <?php else: ?>
                <a class="tc-btn tc-btn-primary tc-btn-sm me-1" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php?trip_id=<?= urlencode($r['trip_id'] ?? '') ?>&return=1">
                  <i class="bi bi-compass"></i> Navigate to Depot
                </a>
                <details class="d-inline-block text-start">
                  <summary class="tc-btn tc-btn-accent tc-btn-sm list-unstyled"><i class="bi bi-check2-circle"></i> Complete Trip</summary>
                  <form method="post" action="<?= BASE_URL ?>/actions/trip-status.php" class="mt-2 p-2 border rounded bg-light" style="min-width:220px;">
                    <input type="hidden" name="reservation_id" value="<?= e($r['id']) ?>">
                    <input type="hidden" name="status" value="Completed">
                    <input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/driver-portal/driver-trips.php') ?>">
                    <label class="tc-form-label">Final odometer (km)</label>
                    <input class="tc-form-control mb-2" type="number" name="final_odometer" min="<?= (int)($r['vehicle_odometer'] ?? 0) ?>" value="<?= (int)($r['vehicle_odometer'] ?? 0) ?>" required>
                    <label class="tc-form-label">Vehicle condition</label>
                    <select class="tc-form-select mb-2" name="vehicle_condition" required>
                      <option value="Good">Good</option><option value="Needs Inspection">Needs Inspection</option><option value="Defect Reported">Defect Reported</option>
                    </select>
                    <label class="tc-form-label">Toll / parking (<?= e(currency_symbol()) ?>)</label>
                    <input class="tc-form-control mb-2" type="number" step="0.01" min="0" name="toll_fee" value="0">
                    <label class="tc-form-label">Completion notes</label>
                    <textarea class="tc-form-control mb-2" name="completion_notes" rows="2"></textarea>
                    <button type="submit" class="tc-btn tc-btn-accent tc-btn-sm w-100">Confirm Completion</button>
                  </form>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
