<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_document.php';
require_once ROOT_PATH . '/includes/vehicle_photo.php';
require_once ROOT_PATH . '/includes/vehicle_compliance.php';
require_once ROOT_PATH . '/includes/archive.php';
require_login();
require_permission('vehicles.view');

$pdo = db();

$filter = strtolower($_GET['filter'] ?? 'all');
$q      = trim($_GET['q'] ?? '');

$sql = "SELECT v.*, d.name AS assigned_driver_name
          FROM vehicles v
          LEFT JOIN drivers d ON d.id = v.assigned_driver_id
         WHERE NOT v.is_archived AND v.status <> 'Retired'";
$params = [];
if (in_array($filter, ['available', 'on trip', 'maintenance'], true)) {
    $sql .= ' AND LOWER(v.status) = ?';
    $params[] = $filter;
}
if ($q !== '') {
    $sql .= ' AND (v.plate_number LIKE ? OR v.brand LIKE ? OR v.model LIKE ? OR v.id LIKE ? OR d.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$sql .= ' ORDER BY v.created_at DESC NULLS LAST, v.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll();
$directory_vehicle_count = (int)$pdo->query("SELECT COUNT(*) FROM vehicles WHERE NOT is_archived AND status <> 'Retired'")->fetchColumn();
$photos = vehicle_photo_records($pdo, array_column($vehicles, 'id'));

$documents_by_vehicle = [];
if ($vehicles) {
  $vehicle_ids = array_column($vehicles, 'id');
  $placeholders = implode(',', array_fill(0, count($vehicle_ids), '?'));
  $documentsStmt = $pdo->prepare(
    "SELECT vehicle_id, document_type, extracted_data, extraction_status
       FROM vehicle_documents
      WHERE vehicle_id IN ($placeholders)
      ORDER BY uploaded_at DESC, id DESC"
  );
  $documentsStmt->execute($vehicle_ids);
  foreach ($documentsStmt->fetchAll() as $document) {
    $type = strtolower((string)$document['document_type']);
    if (in_array($type, ['or/cr', 'registration document'], true)) $type = 'registration';
    if (!in_array($type, ['registration', 'insurance', 'ltfrb_permit'], true)) continue;
    if (isset($documents_by_vehicle[$document['vehicle_id']][$type])) continue;
    $data = json_decode((string)$document['extracted_data'], true) ?: [];
    $documents_by_vehicle[$document['vehicle_id']][$type] = [
      'exists' => true,
      'summary' => vehicle_document_compliance_summary($type, $data),
      'status' => $document['extraction_status'],
      'testFixture' => !empty($data['test_fixture']),
      'vehicleMatchStatus' => $data['vehicle_match_status'] ?? null,
    ];
  }
}

$vehicle_types = [];
$next_vehicle_id_preview = null;
if (can('vehicles.manage')) {
    $vehicle_types = $pdo->query("SELECT id, name FROM vehicle_types WHERE status = 'Active' ORDER BY name")->fetchAll();
    $next_vehicle_id_preview = preview_next_available_sequential_id($pdo, 'vehicles', 'id', 'VEH-');
    $_SESSION['vehicle_csrf_token'] ??= bin2hex(random_bytes(32));
}

 
$vehicle_json = [];
$operational_by_vehicle = [];
foreach ($vehicles as $v) {
  $profileDocuments = $documents_by_vehicle[$v['id']] ?? [];
  foreach (['registration', 'insurance', 'ltfrb_permit'] as $documentType) {
    $profileDocuments[$documentType] ??= ['exists' => false, 'summary' => '—', 'status' => 'not_detected'];
  }
  $operational = vehicle_operational_compliance($pdo, $v['id']);
  $operational_by_vehicle[$v['id']] = $operational;
    $vehicle_json[$v['id']] = [
        'id' => $v['id'],
        'photo' => vehicle_photo_present($v['id'], $photos[$v['id']] ?? []),
        'canManagePhoto' => can('vehicles.manage'),
        'plateNumber' => $v['plate_number'],
        'type' => $v['type'],
        'brand' => $v['brand'],
        'model' => $v['model'],
        'year' => (int)$v['year'],
        'capacity' => (int)$v['capacity'],
        'status' => $v['status'],
        'assignedDriver' => $v['assigned_driver_name'] ?? null,
        'driverId' => $v['assigned_driver_id'],
        'fuelType' => $v['fuel_type'],
        'odometer' => (int)$v['odometer'],
        'maintenanceStatus' => $v['maintenance_status'],
        'nextMaintenance' => $v['next_maintenance'],
        'lastMaintenance' => $v['last_maintenance'],
        'location' => $v['location'],
        'documents' => [
          'registration' => $profileDocuments['registration'],
          'insurance' => $profileDocuments['insurance'],
          'ltfrbPermit' => $profileDocuments['ltfrb_permit'],
        ],
        'operational' => $operational,
        'canManageDocuments' => can('vehicles.manage'),
        'csrfToken' => can('vehicles.manage') ? ($_SESSION['vehicle_csrf_token'] ?? '') : '',
        'performance' => [
            'totalTrips' => (int)$v['total_trips'],
            'totalKm' => (int)$v['total_km'],
            'avgFuelKm' => $v['avg_fuel_km'],
            'operatingCostKm' => $v['operating_cost_km'],
        ],
    ];
}

$active_page = 'vehicles';
$body_class = trim(($body_class ?? '') . ' fleet-vehicles-module fleet-vehicle-directory-page');
$page_title  = 'Vehicle Directory — Fleet & Vehicle Management';
$openVehicleId = isset($_GET['vehicle']) && is_string($_GET['vehicle']) && isset($vehicle_json[$_GET['vehicle']])
  ? $_GET['vehicle']
  : null;
require ROOT_PATH . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/css/vehicle-registration.css?v=<?= (int)filemtime(ROOT_PATH . '/css/vehicle-registration.css') ?>">

<div class="fleet-vehicles-page-shell">
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h1 class="mb-1">Fleet & Vehicle Management (FVM)</h1>
    <p class="text-muted-custom mb-0">Comprehensive vehicle directory, capacity details, assigned drivers, and compliance status.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/maintenance.php"><i class="bi bi-tools"></i> Maintenance Info</a>
    <?php if (can('vehicles.manage')): ?>
      <button class="tc-btn tc-btn-primary tc-btn-sm" onclick="App.openModal('modal-add-vehicle')"><i class="bi bi-plus-lg"></i> Add New Vehicle</button>
    <?php endif; ?>
  </div>
</div>

<div class="tc-card mb-4">
  <div class="p-3 border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div class="d-flex align-items-center gap-2">
      <h2 class="fs-6 fw-semibold mb-0">Vehicle Directory</h2>
      <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-2 fs-4"><?= number_format($directory_vehicle_count) ?> vehicles</span>
    </div>
    <?php if (count($vehicles) !== $directory_vehicle_count): ?>
      <span class="small text-muted-custom"><?= number_format(count($vehicles)) ?> matching current filters</span>
    <?php endif; ?>
  </div>
  <div class="p-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2 bg-light">
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <span class="text-muted-custom small fw-semibold">Filter Status:</span>
      <a class="tc-btn tc-btn-light tc-btn-sm <?= $filter === 'all' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php">All</a>
      <a class="tc-btn tc-btn-light tc-btn-sm <?= $filter === 'available' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php?filter=available">Available</a>
      <a class="tc-btn tc-btn-light tc-btn-sm <?= $filter === 'on trip' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php?filter=on%20trip">On Trip</a>
      <a class="tc-btn tc-btn-light tc-btn-sm <?= $filter === 'maintenance' ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php?filter=maintenance">Maintenance</a>
    </div>
    <div class="text-muted-custom small">
      Showing <strong><?= count($vehicles) ?></strong> registered tour fleet vehicles
      <?php if ($q !== ''): ?><span class="ms-1">for "<strong><?= e($q) ?></strong>" <a href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php" class="text-primary text-decoration-none">(clear)</a></span><?php endif; ?>
    </div>
  </div>

  <div class="tc-table-container border-0">
    <table class="tc-table">
      <thead>
        <tr>
          <th>Vehicle ID</th>
          <th>Plate & Type</th>
          <th>Make & Model</th>
          <th>Capacity</th>
          <th>Current Status</th>
          <th>Assigned Driver</th>
          <th>Odometer</th>
          <th>Operational</th>
          <th>Health</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($vehicles)): ?>
          <tr><td colspan="10" class="text-center text-muted-custom py-4">No vehicles match the selected filter.</td></tr>
        <?php else: foreach ($vehicles as $v):
          $operational = $operational_by_vehicle[$v['id']];
          $needs_repair = $v['maintenance_status'] && strpos(strtolower($v['maintenance_status']), 'repair') !== false;
        ?>
          <tr id="record-<?= e($v['id']) ?>" data-vehicle-id="<?= e($v['id']) ?>"
              data-vehicle="<?= e(json_encode($vehicle_json[$v['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
            <td><strong class="text-primary-custom"><?= e($v['id']) ?></strong></td>
            <td>
              <div class="fw-semibold"><?= e($v['plate_number']) ?></div>
              <div class="text-muted-custom small"><?= e($v['type']) ?></div>
            </td>
            <td>
              <div class="vehicle-directory-identity">
                <?= vehicle_photo_html($vehicle_json[$v['id']]['photo'], trim($v['brand'].' '.$v['model']), 'vehicle-directory-thumbnail') ?>
                <div><div><?= e($v['brand']) ?> <?= e($v['model']) ?></div>
                <div class="text-muted-custom small"><?= (int)$v['year'] ?> Model</div></div>
              </div>
            </td>
            <td><span class="badge bg-light text-dark border"><?= (int)$v['capacity'] ?> Seats</span></td>
            <td><span class="status-badge <?= status_badge_class($v['status']) ?>"><?= e($v['status']) ?></span></td>
            <td><?= e($v['assigned_driver_name'] ?? '— Unassigned —') ?></td>
            <td><?= number_format((int)$v['odometer']) ?> km</td>
            <td>
              <span class="badge <?= $operational['operational'] ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?> border"
                    title="<?= e($operational['reason'] ?: ($operational['test_data'] ? 'Test fixture documents in development only.' : 'Required compliance documents are valid.')) ?>">
                <?= e($operational['status']) ?>
              </span>
              <?php if (!$operational['operational']): ?><div class="small text-danger mt-1"><?= e($operational['reason']) ?></div><?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $needs_repair ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?> border">
                <?= $needs_repair ? 'Maintenance Due' : 'Healthy' ?>
              </span>
            </td>
            <td class="text-end">
              <div class="btn-group">
                <?php if (archive_can_retire()): ?>
                <button type="button" class="tc-btn tc-btn-light tc-btn-sm" data-retire-vehicle="<?= e($v['id']) ?>"><i class="bi bi-archive"></i> Retire</button>
                <?php endif; ?>
                <button class="tc-btn tc-btn-secondary tc-btn-sm" onclick="App.viewVehicleDetails('<?= e($v['id']) ?>')">
                  <i class="bi bi-eye"></i> Details
                </button>
                <?php if (can('vehicles.assign')): ?>
                <a class="tc-btn tc-btn-light tc-btn-sm" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-assignment.php?vehicle=<?= e($v['id']) ?>">
                  <i class="bi bi-person-check"></i> Assign
                </a>
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

 
<div id="modal-vehicle-details" class="tc-modal-backdrop">
  <div class="tc-modal tc-modal-lg">
    <div class="tc-card-header">
      <h4 class="mb-0 fw-bold fs-6">Vehicle Profile & Compliance Information</h4>
      <button type="button" class="btn-close" onclick="App.closeModal('modal-vehicle-details')"></button>
    </div>
    <div class="tc-card-body" id="modal-vehicle-details-body">
       
    </div>
    <div class="tc-card-footer text-end">
      <button class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-vehicle-details')">Close</button>
    </div>
  </div>
</div>

<?php if (can('vehicles.manage')): ?>
 
<div id="modal-add-vehicle" class="tc-modal-backdrop">
  <div class="tc-modal vehicle-registration-modal">
    <div class="tc-card-header">
      <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-plus-circle me-2 text-primary-custom"></i>Register New Tour Vehicle</h4>
      <button type="button" class="btn-close" onclick="App.closeModal('modal-add-vehicle')"></button>
    </div>
    <div class="tc-card-body">
      <form method="post" action="<?= BASE_URL ?>/actions/vehicle.php" enctype="multipart/form-data" id="vehicle-registration-form">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/fleet-vehicle-management/vehicle-directory.php') ?>">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['vehicle_csrf_token']) ?>">

        <div class="alert alert-info py-2 mb-3" role="status">
          <div class="small text-muted-custom">Next Vehicle Number</div>
          <strong class="fs-5"><?= e($next_vehicle_id_preview) ?></strong>
          <div class="small text-muted-custom">This number will be confirmed when registration is successfully saved.</div>
        </div>

        <div class="mb-3">
          <label class="tc-form-label" for="vehicle-photo">Vehicle Photo <span class="fw-normal text-muted-custom">(Optional)</span></label>
          <input id="vehicle-photo" type="file" name="vehicle_photo" class="tc-form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="vehicle-photo-help">
          <div id="vehicle-photo-help" class="vehicle-source-note">Actual vehicle photo, up to 5 MB. JPG, PNG, or WebP. You can add or change it later in Vehicle Details.</div>
        </div>
        <div class="vehicle-step-label">Documents & Compliance</div>
        <label class="tc-form-label" for="vehicle-document">Vehicle Registration Document <span class="text-danger">*</span></label>
        <div id="vehicle-document-drop" class="vehicle-upload-zone" role="button" tabindex="0" aria-controls="vehicle-document">
          <input id="vehicle-document" type="file" name="registration_document" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" required>
          <i class="bi bi-file-earmark-arrow-up fs-3 text-primary-custom d-block mb-2"></i>
          <strong>Drop the vehicle registration document here</strong>
          <div class="text-muted-custom small mt-1">or select a PDF, JPG, JPEG, or PNG file (maximum 8 MB)</div>
        </div>
        <div id="vehicle-upload-status" class="small text-muted-custom mt-2" role="status" aria-live="polite"></div>
        <div id="vehicle-extraction-review" class="small mt-2" hidden></div>
        <div class="row g-3 mt-1">
          <div class="col-md-6">
            <label class="tc-form-label" for="vehicle-insurance-document">Insurance <span class="fw-normal text-muted-custom">(Optional)</span></label>
            <input id="vehicle-insurance-document" type="file" name="insurance_document" class="tc-form-control" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png">
            <div class="vehicle-source-note" id="vehicle-insurance-status">Upload if available; you can add it later.</div>
            <div id="vehicle-insurance-review-label" class="vehicle-source-note" hidden>
              <label><input id="vehicle-insurance-review" name="insurance_manual_review_confirm" value="1" type="checkbox"> I reviewed this document manually.</label>
              <label class="d-block mt-1">Plate number shown on Insurance
                <input name="insurance_manual_vehicle_plate" type="text" class="tc-form-control text-uppercase" maxlength="20" autocomplete="off">
              </label>
            </div>
          </div>
          <div class="col-md-6">
            <label class="tc-form-label" for="vehicle-ltfrb-document">LTFRB Permit <span class="fw-normal text-muted-custom">(Optional)</span></label>
            <input id="vehicle-ltfrb-document" type="file" name="ltfrb_permit_document" class="tc-form-control" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png">
            <div class="vehicle-source-note" id="vehicle-ltfrb-status">Upload if available; you can add it later.</div>
            <div id="vehicle-ltfrb-review-label" class="vehicle-source-note" hidden>
              <label><input id="vehicle-ltfrb-review" name="ltfrb_manual_review_confirm" value="1" type="checkbox"> I reviewed this document manually.</label>
              <label class="d-block mt-1">Plate number shown on LTFRB Permit
                <input name="ltfrb_manual_vehicle_plate" type="text" class="tc-form-control text-uppercase" maxlength="20" autocomplete="off">
              </label>
            </div>
          </div>
        </div>
        <div id="vehicle-document-preview" class="vehicle-document-preview mt-3" hidden>
          <div id="vehicle-document-name" class="small fw-semibold p-2 border-bottom"></div>
          <img id="vehicle-preview-image" alt="Uploaded vehicle document preview" hidden>
          <iframe id="vehicle-preview-pdf" title="Uploaded vehicle document preview" hidden></iframe>
        </div>

        <div id="vehicle-registration-workflow" hidden>
          <div class="vehicle-step">
            <div class="vehicle-step-label">Step 2 — Plate number from document</div>
            <label class="tc-form-label" for="vehicle-plate-number">Plate Number Extracted from Uploaded Document</label>
            <input id="vehicle-plate-number" type="text" name="plate_number" class="tc-form-control text-uppercase" maxlength="20" readonly required>
            <div class="vehicle-source-note" id="vehicle-plate-review">Locked to the value extracted from the uploaded document. OCR is not LTO verification.</div>
            <label id="vehicle-registration-review-label" class="vehicle-source-note" hidden><input id="vehicle-registration-review" name="registration_manual_review_confirm" value="1" type="checkbox"> I reviewed the registration document manually.</label>
          </div>

          <div class="vehicle-step">
            <div class="vehicle-step-label">Steps 3–5 — Select vehicle configuration</div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="tc-form-label" for="vehicle-type">Vehicle Type</label>
                <select id="vehicle-type" class="tc-form-select" name="vehicle_type_id" required>
                  <option value="">Select vehicle type</option>
                  <?php foreach ($vehicle_types as $vehicle_type): ?>
                    <option value="<?= (int)$vehicle_type['id'] ?>"><?= e($vehicle_type['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="vehicle-source-note">Auto-filled from the document/catalog match when available; editable by Admin.</div>
              </div>
              <div class="col-md-6">
                <label class="tc-form-label" for="vehicle-brand">Brand / Make</label>
                <select id="vehicle-brand" class="tc-form-select" name="brand_id" required disabled><option value="">Select brand</option></select>
              </div>
              <div class="col-md-6">
                <label class="tc-form-label" for="vehicle-model">Series / Model</label>
                <select id="vehicle-model" class="tc-form-select" name="model_id" required disabled><option value="">Select model</option></select>
              </div>
              <div class="col-md-6">
                <label class="tc-form-label" for="vehicle-year">Year Model</label>
                <input id="vehicle-year" type="number" name="year" class="tc-form-control" min="1980" max="<?= (int)date('Y') + 1 ?>" required>
              </div>
              <div id="vehicle-variant-field" class="col-md-6" hidden>
                <label class="tc-form-label" for="vehicle-variant">Variant <span class="fw-normal text-muted-custom">(Optional)</span></label>
                <select id="vehicle-variant" class="tc-form-select" name="variant_id" disabled><option value="">Not specified</option></select>
                <div class="vehicle-source-note">Shown only when known configurations exist for the selected model and year.</div>
              </div>
              <div class="col-12 vehicle-source-note">Brand/Make, Series/Model, and Year Model are auto-filled when clearly extracted and matched. These fields remain editable for Admin review.</div>
            </div>
          </div>

          <div id="vehicle-specifications" class="vehicle-step" hidden>
            <div class="vehicle-step-label">Steps 6–7 — Review auto-filled specifications</div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="tc-form-label">Passenger Capacity</label>
                <input type="number" name="capacity" class="tc-form-control" min="1" max="100" required>
              </div>
              <div class="col-md-6">
                <label class="tc-form-label">Fuel Type</label>
                <select name="fuel_type" class="tc-form-select" required>
                  <option value="">Select fuel type</option>
                  <option value="Diesel">Diesel</option>
                  <option value="Gasoline">Gasoline</option>
                  <option value="Hybrid">Hybrid</option>
                  <option value="Electric">Electric</option>
                </select>
              </div>
            </div>
            <div class="vehicle-source-note">Variant details are loaded from the catalog when available. Confirm the remaining unit specifications before registration.</div>
          </div>

          <div id="vehicle-document-validation-warning" class="alert alert-warning small mt-3 mb-0" role="alert" tabindex="-1" hidden></div>
          <div id="vehicle-form-status" class="small text-muted-custom mt-3" role="status" aria-live="polite"></div>
          <div class="d-flex flex-column flex-sm-row justify-content-end gap-2 mt-3">
            <button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-add-vehicle')">Cancel</button>
            <button id="vehicle-register-submit" type="submit" class="tc-btn tc-btn-primary" disabled>Register Vehicle</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  window.TC_VEHICLES_DATA = <?= json_encode($vehicle_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  window.TC_OPEN_VEHICLE_ID = <?= json_encode($openVehicleId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>

<?php $page_scripts = '<script src="' . BASE_URL . '/js/vehicle-registration.js?v=' . (int)filemtime(ROOT_PATH . '/js/vehicle-registration.js') . '"></script><script src="' . BASE_URL . '/js/fleet-vehicles.js?v=' . (int)filemtime(ROOT_PATH . '/js/fleet-vehicles.js') . '"></script>'; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
