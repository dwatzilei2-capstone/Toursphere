<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('driver.portal');

$pdo = db();

$driver = $pdo->prepare('SELECT * FROM drivers WHERE user_id = ?');
$driver->execute([$current_user['id']]);
$driver = $driver->fetch();

$vehicle = null;
$activeTrip = null;
$issueReports = [];
if ($driver) {
    $vehicle = $pdo->prepare('SELECT * FROM vehicles WHERE assigned_driver_id = ?');
    $vehicle->execute([$driver['id']]);
    $vehicle = $vehicle->fetch() ?: null;
    if ($vehicle) {
        $tripStmt = $pdo->prepare(
            "SELECT t.id FROM trips t JOIN reservations r ON r.id=t.reservation_id
              WHERE t.vehicle_id=? AND r.assigned_driver_id=?
                AND t.status IN ('Scheduled','Assigned','Dispatched','In Transit')
              ORDER BY CASE WHEN t.status='In Transit' THEN 0 ELSE 1 END, t.created_at DESC LIMIT 1"
        );
        $tripStmt->execute([$vehicle['id'], $driver['id']]);
        $activeTrip = $tripStmt->fetchColumn() ?: null;
        $reportStmt = $pdo->prepare(
            "SELECT id, service_type, priority, status, created_at FROM maintenance_orders
              WHERE vehicle_id=? AND notes LIKE 'Driver issue report:%' ORDER BY created_at DESC LIMIT 5"
        );
        $reportStmt->execute([$vehicle['id']]);
        $issueReports = $reportStmt->fetchAll();
    }
}

$active_page = 'driver-vehicle';
$page_title  = 'My Vehicle';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">My Vehicle</h1>
    <p class="text-muted-custom mb-0">Vehicle information and safety issue reporting.</p>
  </div>
  <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/driver-portal/driver-dashboard.php">← Back to Dashboard</a>
</div>

<?php if (!$vehicle): ?>
  <div class="tc-card p-4 text-center text-muted-custom">
    <i class="bi bi-truck fs-1 d-block mb-2 text-primary-custom"></i>
    No vehicle is currently assigned to you. Please contact your dispatcher.
  </div>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="tc-card h-100">
      <div class="tc-card-header">
        <h3 class="mb-0 fs-6 fw-bold"><i class="bi bi-truck me-2 text-primary-custom"></i>Vehicle Profile</h3>
      </div>
      <div class="tc-card-body" id="driver-vehicle-profile">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="stat-icon-wrapper" style="background-color: var(--tc-primary-light); color: var(--tc-primary); border-radius: 50%;">
            <i class="bi bi-truck"></i>
          </div>
          <div>
            <div class="fw-bold fs-5"><?= e($vehicle['brand']) ?> <?= e($vehicle['model']) ?></div>
            <span class="badge bg-light text-dark border mt-1"><?= e($vehicle['plate_number']) ?> • <?= e($vehicle['id']) ?></span>
          </div>
        </div>
        <table class="table table-sm border-0 small mb-0">
          <tr><td class="text-muted-custom">Vehicle Type:</td><td class="fw-semibold"><?= e($vehicle['type']) ?></td></tr>
          <tr><td class="text-muted-custom">Passenger Capacity:</td><td class="fw-semibold"><?= (int)$vehicle['capacity'] ?> Persons</td></tr>
          <tr><td class="text-muted-custom">Current Status:</td><td><span class="status-badge status-available"><?= e($vehicle['status']) ?></span></td></tr>
          <tr><td class="text-muted-custom">Odometer:</td><td class="fw-semibold"><?= number_format((int)$vehicle['odometer']) ?> km</td></tr>
          <tr><td class="text-muted-custom">Fuel Tank / Level:</td><td class="fw-semibold"><?= (int)$vehicle['fuel_capacity'] ?> L (<?= (int)$vehicle['current_fuel'] ?>% filled)</td></tr>
          <tr><td class="text-muted-custom">Next Maintenance:</td><td class="fw-semibold"><?= e($vehicle['next_maintenance']) ?></td></tr>
          <tr><td class="text-muted-custom">Current Location:</td><td class="fw-semibold"><?= e($vehicle['location']) ?></td></tr>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="tc-card h-100">
      <div class="tc-card-header">
        <h3 class="mb-0 fs-6 fw-bold"><i class="bi bi-file-earmark-text me-2 text-primary-custom"></i>Documents & Compliance</h3>
      </div>
      <div class="tc-card-body" id="driver-vehicle-docs">
        <div class="p-2 bg-light rounded small mb-3">
          <div><i class="bi bi-file-earmark-text me-2 text-primary-custom"></i><strong>Registration:</strong> <?= e($vehicle['reg_document']) ?></div>
          <div class="mt-2"><i class="bi bi-shield-check me-2 text-success"></i><strong>Insurance:</strong> <?= e($vehicle['insurance_document']) ?></div>
          <div class="mt-2"><i class="bi bi-patch-check me-2 text-warning"></i><strong>LTFRB Permit:</strong> <?= e($vehicle['ltfrb_permit']) ?></div>
        </div>
        <div class="alert alert-light border small mb-0">
          <i class="bi bi-info-circle me-1 text-primary-custom"></i>
          Vehicle maintenance and repair scheduling is managed by the Fleet Operations team.
        </div>
      </div>
    </div>
  </div>
</div>

<div class="tc-card mt-3">
  <div class="tc-card-header d-flex justify-content-between align-items-center">
    <div>
      <h3 class="mb-1 fs-6 fw-bold"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Report Vehicle Issue</h3>
      <div class="small text-muted-custom">Send a safety or mechanical concern to Fleet Maintenance for inspection.</div>
    </div>
  </div>
  <div class="tc-card-body">
    <form method="post" action="<?= BASE_URL ?>/actions/driver-vehicle-issue.php" class="row g-3">
      <input type="hidden" name="vehicle_id" value="<?= e($vehicle['id']) ?>">
      <input type="hidden" name="trip_id" value="<?= e($activeTrip) ?>">
      <input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/driver-portal/driver-vehicle.php') ?>">
      <div class="col-md-4">
        <label class="tc-form-label">Issue type</label>
        <select class="tc-form-select" name="issue_type" required>
          <option value="">Select issue</option>
          <option>Engine / Mechanical</option><option>Brakes / Steering</option><option>Tires / Wheels</option>
          <option>Electrical</option><option>Body / Safety Equipment</option><option>Other</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="tc-form-label">Severity</label>
        <select class="tc-form-select" name="severity" required>
          <option value="Critical">Critical — unsafe to continue</option>
          <option value="Medium" selected>Medium — inspect soon</option>
          <option value="Low">Low — minor concern</option>
        </select>
      </div>
      <div class="col-md-5">
        <label class="tc-form-label">What did you observe?</label>
        <textarea class="tc-form-control" name="description" rows="2" maxlength="1000" required placeholder="Describe the symptom, warning light, sound, or damage."></textarea>
      </div>
      <div class="col-12 d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <div class="small text-muted-custom"><i class="bi bi-info-circle me-1"></i>Fleet Maintenance confirms the official repair status. For a critical issue, stop safely and contact Dispatch.</div>
        <button class="tc-btn tc-btn-primary" type="submit"><i class="bi bi-send"></i> Submit Issue Report</button>
      </div>
    </form>
  </div>
</div>

<?php if ($issueReports): ?>
<div class="tc-card mt-3">
  <div class="tc-card-header"><h3 class="mb-0 fs-6 fw-bold">My Recent Issue Reports</h3></div>
  <div class="tc-table-container border-0">
    <table class="tc-table"><thead><tr><th>Report</th><th>Issue</th><th>Priority</th><th>Maintenance Status</th></tr></thead><tbody>
      <?php foreach ($issueReports as $report): ?><tr>
        <td><?= e($report['id']) ?></td><td><?= e($report['service_type']) ?></td>
        <td><?= e($report['priority']) ?></td><td><span class="status-badge <?= status_badge_class($report['status']) ?>"><?= e($report['status']) ?></span></td>
      </tr><?php endforeach; ?>
    </tbody></table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
