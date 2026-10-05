<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_compliance.php';
require_login();
require_permission('vehicles.assign');

$pdo = db();

$preselected_vehicle = $_GET['vehicle'] ?? '';

 
$vehicles = $pdo->query(
    "SELECT v.id, v.plate_number, v.brand, v.model, v.type, v.capacity, v.status,
            (v.status = 'Maintenance' OR EXISTS (
                SELECT 1 FROM maintenance_orders active_mo
                 WHERE active_mo.vehicle_id = v.id AND active_mo.status = 'In Repair'
            )) AS is_under_maintenance,
            d.name AS assigned_driver_name,
            EXISTS (SELECT 1 FROM trips active_t WHERE active_t.status IN ('In Transit','Returning to Depot') AND (active_t.driver_id=v.assigned_driver_id OR active_t.vehicle_id=v.id)) AS assignment_has_active_trip
       FROM vehicles v
       LEFT JOIN drivers d ON d.id = v.assigned_driver_id
      WHERE NOT v.is_archived AND v.status <> 'Retired' AND (v.status IN ('Available','Assigned','Maintenance')
         OR EXISTS (
            SELECT 1 FROM maintenance_orders active_mo
             WHERE active_mo.vehicle_id = v.id AND active_mo.status = 'In Repair'
         ))
      ORDER BY v.created_at DESC NULLS LAST, v.id DESC"
)->fetchAll();
foreach ($vehicles as &$vehicle) {
  $vehicle['operational_compliance'] = vehicle_operational_compliance($pdo, $vehicle['id']);
}
unset($vehicle);

function required_license_class(array $vehicle): string
{
    $type = strtolower((string)($vehicle['type'] ?? ''));
    return ((int)$vehicle['capacity'] > 30 || str_contains($type, 'bus') || str_contains($type, 'coach')) ? 'Class 3' : 'Class 2';
}

 
$drivers = $pdo->query(
    "SELECT id, name, license_class, safety_score, status
       FROM drivers
      WHERE status IN ('Active','Assigned')
      ORDER BY safety_score DESC"
)->fetchAll();

$active_page = 'vehicle-assignment';
$body_class = trim(($body_class ?? '') . ' fleet-vehicles-module fleet-vehicle-assignment-page');
$page_title  = 'Vehicle & Driver Assignment';
require ROOT_PATH . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/css/vehicle-assignment.css?v=<?= (int)filemtime(ROOT_PATH . '/css/vehicle-assignment.css') ?>">
<section class="vehicle-assignment fleet-vehicles-page-shell" aria-labelledby="assignment-title">
  <div class="va-heading">
    <div>
      <h1 id="assignment-title">Vehicle Assignment</h1>
      <p>Select a vehicle, choose a driver, and review the assignment before saving.</p>
    </div>
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Vehicle directory</a>
  </div>

  <form method="post" action="<?= BASE_URL ?>/actions/assign-vehicle.php" id="va-form">
    <input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/fleet-vehicle-management/vehicle-assignment.php') ?>">
    <div class="va-layout">
      <section class="va-panel" aria-labelledby="va-fleet-title">
        <div class="va-panel-heading">
          <div><h2 id="va-fleet-title">Choose a vehicle</h2><p>Vehicles with maintenance issues or incomplete/expired compliance remain visible but cannot be selected.</p></div>
          <span class="va-count" id="va-result-count" aria-live="polite"><?= count($vehicles) ?> vehicles</span>
        </div>
        <div class="va-toolbar">
          <div class="va-search-field"><label for="va-search">Find a vehicle</label><div class="va-search-box"><input type="search" id="va-search" class="tc-form-control" placeholder="Plate, vehicle, or driver" autocomplete="off"><button type="button" id="va-search-button" aria-label="Search vehicles" title="Search vehicles"><i class="bi bi-search" aria-hidden="true"></i></button></div></div>
          <div><label for="va-status-filter">Status</label><select id="va-status-filter" class="tc-form-select"><option value="">All statuses</option><option value="Available">Available</option><option value="Assigned">Assigned</option><option value="Maintenance">Maintenance</option></select></div>
          <button type="button" class="tc-btn tc-btn-secondary tc-btn-sm" id="va-clear-filters">Clear filters</button>
        </div>
        <div class="va-table-wrap" role="region" aria-label="Vehicle list" tabindex="0">
          <table class="va-table">
            <caption class="visually-hidden">Select one vehicle to assign a driver. Vehicles in transit or maintenance are excluded.</caption>
            <thead><tr><th scope="col">Select</th><th scope="col">Plate No.</th><th scope="col">Vehicle</th><th scope="col">Capacity</th><th scope="col">Required License</th><th scope="col">Default Driver</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
            <tbody>
            <?php if (empty($vehicles)): ?>
              <tr><td colspan="8"><div class="va-empty"><i class="bi bi-truck" aria-hidden="true"></i><h3>No vehicles available for assignment</h3><p>Vehicles will appear here when their status is Available or Assigned.</p></div></td></tr>
            <?php else: foreach ($vehicles as $v): $underMaintenance = filter_var($v['is_under_maintenance'], FILTER_VALIDATE_BOOLEAN); $operational = $v['operational_compliance']['operational']; $unavailable = $underMaintenance || !$operational; ?>
              <tr class="va-vehicle-row <?= $unavailable ? 'opacity-75' : '' ?>" data-status="<?= $underMaintenance ? 'Maintenance' : e($v['status']) ?>">
                <td><label class="va-select"><input type="radio" name="vehicle_id" value="<?= e($v['id']) ?>" required <?= (!$unavailable && $preselected_vehicle === $v['id']) ? 'checked' : '' ?> <?= $unavailable ? 'disabled' : '' ?> data-plate="<?= e($v['plate_number']) ?>" data-model="<?= e($v['brand'] . ' ' . $v['model']) ?>" data-capacity="<?= (int)$v['capacity'] ?>" data-current-driver="<?= e($v['assigned_driver_name'] ?? '') ?>"><span class="visually-hidden"><?= $unavailable ? 'Unavailable' : 'Select' ?><span> <?= e($v['plate_number']) ?></span></span></label></td>
                <td><strong><?= e($v['plate_number']) ?></strong></td><td><?= e($v['brand'] . ' ' . $v['model']) ?><span class="va-reference"><?= e($v['id']) ?></span></td>
                <td><?= (int)$v['capacity'] ?><span class="va-secondary">passengers</span></td>
                <td><span class="va-license-badge"><?= e(required_license_class($v)) ?></span></td>
                <td><?= e($v['assigned_driver_name'] ?: 'Unassigned') ?></td>
                <td><?php if ($underMaintenance): ?><span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-tools me-1"></i>Under Maintenance</span><?php else: ?><span class="va-status <?= $v['status'] === 'Available' ? 'va-available' : 'va-assigned' ?>"><?= e($v['status']) ?></span><?php endif; ?><?php if (!$operational): ?><div class="small text-danger mt-1" title="<?= e($v['operational_compliance']['reason']) ?>">Not operational: <?= e($v['operational_compliance']['reason']) ?></div><?php else: ?><div class="small text-success mt-1"><?= e($v['operational_compliance']['status']) ?></div><?php endif; ?></td>
                <td><?php if ($v['assigned_driver_name']): $unassignBlocked = filter_var($v['assignment_has_active_trip'], FILTER_VALIDATE_BOOLEAN); ?><button type="submit" class="tc-btn tc-btn-outline-danger tc-btn-sm va-unassign" name="unassign_vehicle_id" value="<?= e($v['id']) ?>" formaction="<?= BASE_URL ?>/actions/unassign-vehicle.php" formmethod="post" formnovalidate data-confirm="Are you sure you want to unassign <?= e($v['assigned_driver_name']) ?> from <?= e($v['plate_number']) ?>?" <?= $unassignBlocked ? 'disabled title="Complete or cancel the active trip first."' : '' ?>>Unassign</button><?php else: ?><span class="text-muted-custom">—</span><?php endif; ?></td>

              </tr>
            <?php endforeach; endif; ?>
            <tr id="va-no-results" hidden><td colspan="8" class="va-no-results">No matching vehicles. Try another search or clear the filters.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="va-table-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Vehicles under maintenance or without valid required documents remain visible but cannot be selected.</div>
      </section>

      <aside class="va-panel va-composer" aria-labelledby="va-assignment-title">
        <div class="va-panel-heading"><div><h2 id="va-assignment-title">Assignment details</h2><p>Review your selection before saving.</p></div></div>
        <div class="va-composer-body">
          <div class="va-field-label" id="va-selected-label">Selected vehicle</div>
          <div class="va-selection" aria-labelledby="va-selected-label" aria-live="polite">
            <strong id="va-selected-plate">No vehicle selected</strong>
            <span id="va-selected-detail">Choose a vehicle from the list.</span>
          </div>
          <label class="va-field-label" for="va-driver">Driver <span class="va-required">(required)</span></label>
          <select class="tc-form-select va-driver-native" id="va-driver" name="driver_id" required aria-describedby="va-driver-help" tabindex="-1" aria-hidden="true">
            <option value="">Choose a driver</option>
            <?php foreach ($drivers as $d): ?>
              <option value="<?= e($d['id']) ?>" data-license="<?= e($d['license_class']) ?>" data-score="<?= (int)$d['safety_score'] ?>" data-status="<?= e($d['status']) ?>"><?= e($d['name']) ?><?= $d['status'] === 'Assigned' ? ' — currently assigned' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <div class="va-driver-picker" id="va-driver-picker">
            <div class="va-driver-trigger-wrap"><input type="search" class="tc-form-control va-driver-trigger" placeholder="Search or choose a driver" autocomplete="off" aria-haspopup="listbox" aria-expanded="false"><i class="bi bi-search" aria-hidden="true"></i></div>
            <div class="va-driver-menu" role="listbox" hidden>
              <?php foreach ($drivers as $d): ?><button type="button" role="option" data-value="<?= e($d['id']) ?>" data-license="<?= e($d['license_class']) ?>" data-score="<?= (int)$d['safety_score'] ?>" data-status="<?= e($d['status']) ?>"><?= e($d['name']) ?><?= $d['status'] === 'Assigned' ? ' <span>Currently assigned</span>' : '' ?></button><?php endforeach; ?>
            </div>
          </div>
          <p class="va-help" id="va-driver-help"><?= empty($drivers) ? 'No eligible drivers are available. Check driver availability before assigning a vehicle.' : 'Drivers with Active or Assigned status are listed.' ?></p>
          <dl class="va-driver-details" id="va-driver-details" hidden><div><dt>License class</dt><dd id="va-license"></dd></div><div><dt>Safety score</dt><dd id="va-score"></dd></div></dl>
          <div class="va-notice" id="va-reassignment" role="status" hidden></div>
          <div class="va-checklist"><h3>Before you assign</h3><p>Check that the driver’s license covers the vehicle class and that availability matches the planned trip.</p><p>A driver can only be assigned to one vehicle at a time. Saving updates the vehicle assignment; it does not dispatch a trip.</p></div>
          <button type="submit" class="tc-btn tc-btn-primary va-submit" <?= empty($vehicles) || empty($drivers) ? 'disabled' : '' ?>>Save assignment <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
          <p class="va-save-hint">Changes apply only when you save.</p>
        </div>
      </aside>
    </div>
  </form>
</section>
<div id="modal-unassign-confirm" class="tc-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="unassign-confirm-title" aria-describedby="unassign-confirm-message">
  <div class="tc-modal">
    <div class="tc-card-header d-flex align-items-center gap-2">
      <i class="bi bi-exclamation-triangle text-danger" aria-hidden="true"></i>
      <h2 class="mb-0 fw-bold fs-6" id="unassign-confirm-title">Confirm Unassignment</h2>
    </div>
    <div class="tc-card-body">
      <p class="mb-0" id="unassign-confirm-message"></p>
    </div>
    <div class="p-3 border-top d-flex justify-content-end gap-2">
      <button type="button" class="tc-btn tc-btn-secondary" id="unassign-confirm-cancel">Keep Assignment</button>
      <button type="button" class="tc-btn tc-btn-outline-danger" id="unassign-confirm-submit"><i class="bi bi-person-dash me-1" aria-hidden="true"></i>Unassign</button>
    </div>
  </div>
</div>
<script src="<?= BASE_URL ?>/js/vehicle-assignment.js?v=<?= (int)filemtime(ROOT_PATH . '/js/vehicle-assignment.js') ?>"></script>
<script src="<?= BASE_URL ?>/js/fleet-vehicles.js?v=<?= (int)filemtime(ROOT_PATH . '/js/fleet-vehicles.js') ?>"></script>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
