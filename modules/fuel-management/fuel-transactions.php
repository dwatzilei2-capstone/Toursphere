<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('fuel.view');

$pdo = db();

$transactions = $pdo->query(
    "SELECT f.*, v.plate_number, d.name AS driver_name
       FROM fuel_transactions f
       LEFT JOIN vehicles v ON v.id = f.vehicle_id
       LEFT JOIN drivers d  ON d.id = f.driver_id
      ORDER BY f.transaction_date DESC"
)->fetchAll();

$active_page = 'fuel-transactions';
$page_title  = 'Fuel Transactions Log';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Fuel Transactions Log</h1>
    <p class="text-muted-custom mb-0">Detailed records of all depot and partner gas station refills with odometer readings.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/fuel-management/fuel-overview.php">← Fuel Overview</a>
    <?php if (can('fuel.manage')): ?>
      <button class="tc-btn tc-btn-primary tc-btn-sm" onclick="App.openModal('modal-log-fuel')"><i class="bi bi-plus-lg"></i> Log Fuel Refill</button>
    <?php endif; ?>
  </div>
</div>

<div class="tc-card">
  <div class="tc-table-container border-0">
    <table class="tc-table">
      <thead>
        <tr>
          <th>Log ID</th>
          <th>Vehicle</th>
          <th>Date & Time</th>
          <th>Fuel Type</th>
          <th>Liters</th>
          <th>Price/L</th>
          <th>Total Spend</th>
          <th>Driver</th>
          <th>Efficiency</th>
          <th class="text-end">Receipt</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($transactions)): ?>
          <tr><td colspan="10" class="text-center text-muted-custom py-4">No fuel transactions recorded.</td></tr>
        <?php else: foreach ($transactions as $f): ?>
          <tr>
            <td><span class="fw-semibold text-primary-custom"><?= e($f['id']) ?></span></td>
            <td>
              <div class="fw-semibold"><?= e($f['plate_number']) ?></div>
              <div class="text-muted-custom small"><?= e($f['vehicle_id']) ?></div>
            </td>
            <td>
              <div><?= e(date('Y-m-d', strtotime($f['transaction_date']))) ?></div>
              <div class="text-muted-custom small"><?= e(date('H:i', strtotime($f['transaction_date']))) ?></div>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($f['fuel_type']) ?></span></td>
            <td><span class="fw-semibold"><?= number_format((float)$f['liters'], 1) ?> L</span></td>
            <td><?= money($f['price_per_liter']) ?></td>
            <td><span class="fw-bold text-primary-custom"><?= money($f['total_cost']) ?></span></td>
            <td><?= e($f['driver_name'] ?? '—') ?></td>
            <td><span class="badge bg-success-subtle text-success border"><?= e($f['efficiency'] ?? '—') ?></span></td>
            <td class="text-end">
              <button class="tc-btn tc-btn-light tc-btn-sm" onclick="App.showReceipt('<?= e($f['id']) ?>', '<?= e($f['receipt_no']) ?>')">
                <i class="bi bi-receipt"></i>
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (can('fuel.manage')):
  $current_driver_vehicle_id = null;
  $current_driver_id = null;
  if (($current_user['role_code'] ?? '') === 'driver') {
      $dStmt = $pdo->prepare('SELECT d.id AS driver_id, v.id AS vehicle_id FROM drivers d LEFT JOIN vehicles v ON v.assigned_driver_id = d.id WHERE d.user_id = ?');
      $dStmt->execute([$current_user['id']]);
      $dRow = $dStmt->fetch();
      if ($dRow) {
          $current_driver_id = $dRow['driver_id'];
          $current_driver_vehicle_id = $dRow['vehicle_id'];
      }
  }
?>
 
<div id="modal-log-fuel" class="tc-modal-backdrop">
  <div class="tc-modal">
    <div class="tc-card-header">
      <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-fuel-pump me-2 text-primary-custom"></i>Log Fuel Refill Transaction</h4>
      <button type="button" class="btn-close" onclick="App.closeModal('modal-log-fuel')"></button>
    </div>
    <div class="tc-card-body">
      <form method="post" action="<?= BASE_URL ?>/actions/fuel.php">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/fuel-management/fuel-transactions.php') ?>">
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="tc-form-label">Vehicle</label>
            <select class="tc-form-select" name="vehicle_id" required>
              <option value="">— Choose vehicle —</option>
              <?php
              $fuel_vehicles = $pdo->query('SELECT id, plate_number, brand, model FROM vehicles ORDER BY id')->fetchAll();
              foreach ($fuel_vehicles as $fv):
              ?>
                <option value="<?= e($fv['id']) ?>" <?= $current_driver_vehicle_id === $fv['id'] ? 'selected' : '' ?>><?= e($fv['id']) ?> (<?= e($fv['plate_number']) ?>) - <?= e($fv['brand']) ?> <?= e($fv['model']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Driver</label>
            <select class="tc-form-select" name="driver_id">
              <option value="">— Optional —</option>
              <?php foreach ($pdo->query('SELECT id, name FROM drivers ORDER BY name')->fetchAll() as $fd): ?>
                <option value="<?= e($fd['id']) ?>" <?= $current_driver_id === $fd['id'] ? 'selected' : '' ?>><?= e($fd['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Liters Refilled</label>
            <input type="number" step="0.1" name="liters" class="tc-form-control" placeholder="50.0" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Price per Liter (<?= e(currency_symbol()) ?>)</label>
            <input type="number" step="0.01" name="price_per_liter" class="tc-form-control" value="58.40" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Current Odometer (km)</label>
            <input type="number" name="odometer" class="tc-form-control" placeholder="42350" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Fuel Type</label>
            <select class="tc-form-select" name="fuel_type">
              <option>Diesel</option>
              <option>Diesel Max</option>
              <option>Gasoline</option>
            </select>
          </div>
          <div class="col-12">
            <label class="tc-form-label">Fuel Station & Location</label>
            <input type="text" name="station" class="tc-form-control" placeholder="Petron SLEX Northbound Km 36" required>
          </div>
          <div class="col-12">
            <label class="d-flex gap-2 align-items-start p-2 border rounded bg-light small">
              <input type="checkbox" name="verified_trip_consumption" value="1" class="form-check-input mt-1">
              <span><strong>Verified trip fuel consumption</strong><br><span class="text-muted-custom">Check only for a full-to-full measurement where these liters equal the fuel consumed by the linked trip. Only verified values are used for AI training.</span></span>
            </label>
          </div>
        </div>
        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-log-fuel')">Cancel</button>
          <button type="submit" class="tc-btn tc-btn-primary">Record Transaction</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
