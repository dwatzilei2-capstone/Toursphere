<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('vehicles.view');

$pdo = db();

$orders = $pdo->query(
    "SELECT wo.*, v.plate_number, v.brand, v.model, v.type AS vehicle_type
       FROM maintenance_orders wo
       JOIN vehicles v ON v.id = wo.vehicle_id
      ORDER BY wo.scheduled_date"
)->fetchAll();

 
$vehicles = $pdo->query('SELECT id, plate_number, brand, model FROM vehicles ORDER BY id')->fetchAll();

$active_page = 'maintenance';
$page_title  = 'Maintenance Information & Work Orders';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Maintenance Information & Work Orders</h1>
    <p class="text-muted-custom mb-0">Driver issue reports, inspection queue, preventative maintenance, and repair records.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/fleet-vehicle-management/vehicle-directory.php">← Vehicle Directory</a>
    <?php if (can('vehicles.manage')): ?>
      <button class="tc-btn tc-btn-primary tc-btn-sm" onclick="App.openModal('modal-add-work-order')"><i class="bi bi-plus-lg"></i> Create Work Order</button>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-12">
    <div class="tc-card">
      <div class="tc-card-header">
        <h4 class="mb-0 fw-bold fs-6">Scheduled Maintenance & Service Records</h4>
      </div>
      <div class="tc-table-container border-0">
        <table class="tc-table">
          <thead>
            <tr>
              <th>Order ID</th>
              <th>Vehicle</th>
              <th>Service Type</th>
              <th>Priority</th>
              <th>Scheduled Date</th>
              <th>Status</th>
              <th>Est. Cost</th>
              <?php if (can('vehicles.manage')): ?><th class="text-end">Action</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($orders)): ?>
              <tr><td colspan="<?= can('vehicles.manage') ? 8 : 7 ?>" class="text-center text-muted-custom py-4">No maintenance work orders on file.</td></tr>
            <?php else: foreach ($orders as $o): ?>
              <tr>
                <td><strong><?= e($o['id']) ?></strong></td>
                <td><?= e($o['vehicle_id']) ?> (<?= e($o['plate_number']) ?>) • <?= e($o['brand']) ?> <?= e($o['model']) ?></td>
                <td><?= e($o['service_type']) ?></td>
                <td>
                  <span class="badge <?= $o['priority'] === 'Critical' ? 'bg-danger' : ($o['priority'] === 'Medium' ? 'bg-warning text-dark' : 'bg-info text-dark') ?>">
                    <?= e($o['priority']) ?>
                  </span>
                </td>
                <td><?= e($o['scheduled_date']) ?>
                <?php if ($o['status'] === 'Scheduled' && strtotime($o['scheduled_date']) <= strtotime('+' . (int)fleet_setting('maintenance.reminder_days', '7') . ' days', strtotime('today'))): ?>
                  <span class="badge bg-warning text-dark ms-1"><?= strtotime($o['scheduled_date']) < strtotime('today') ? 'Overdue' : 'Due soon' ?></span>
                <?php endif; ?></td>
                <td><span class="status-badge <?= status_badge_class($o['status']) ?>"><?= e($o['status']) ?></span></td>
                <td><?= money($o['estimated_cost']) ?></td>
                <?php if (can('vehicles.manage')): ?>
                <td class="text-end">
                  <?php if ($o['status'] === 'Scheduled'): ?>
                  <form method="post" action="<?= BASE_URL ?>/actions/work-order.php" class="d-inline">
                    <input type="hidden" name="action" value="update_status"><input type="hidden" name="work_order_id" value="<?= e($o['id']) ?>">
                    <input type="hidden" name="status" value="In Repair"><input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/fleet-vehicle-management/maintenance.php') ?>">
                    <button class="tc-btn tc-btn-secondary tc-btn-sm" type="submit"><?= str_starts_with((string)$o['notes'], 'Driver issue report:') ? 'Confirm & Start Repair' : 'Start Repair' ?></button>
                  </form>
                  <?php elseif ($o['status'] === 'In Repair'): ?>
                  <form method="post" action="<?= BASE_URL ?>/actions/work-order.php" class="d-inline">
                    <input type="hidden" name="action" value="update_status"><input type="hidden" name="work_order_id" value="<?= e($o['id']) ?>">
                    <input type="hidden" name="status" value="Completed"><input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/fleet-vehicle-management/maintenance.php') ?>">
                    <button class="tc-btn tc-btn-accent tc-btn-sm" type="submit">Complete</button>
                  </form>
                  <?php else: ?><span class="text-muted-custom small">Closed</span><?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php if (can('vehicles.manage')): ?>
 
<div id="modal-add-work-order" class="tc-modal-backdrop">
  <div class="tc-modal">
    <div class="tc-card-header">
      <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-tools me-2 text-primary-custom"></i>Create Maintenance Work Order</h4>
      <button type="button" class="btn-close" onclick="App.closeModal('modal-add-work-order')"></button>
    </div>
    <div class="tc-card-body">
      <form method="post" action="<?= BASE_URL ?>/actions/work-order.php">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="return" value="<?= e(BASE_URL . '/modules/fleet-vehicle-management/maintenance.php') ?>">
        <div class="row g-2 mb-3">
          <div class="col-12">
            <label class="tc-form-label">Vehicle</label>
            <select class="tc-form-select" name="vehicle_id" required>
              <option value="">— Choose vehicle —</option>
              <?php foreach ($vehicles as $v): ?>
                <option value="<?= e($v['id']) ?>"><?= e($v['id']) ?> (<?= e($v['plate_number']) ?>) - <?= e($v['brand']) ?> <?= e($v['model']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="tc-form-label">Service Type</label>
            <input type="text" name="service_type" class="tc-form-control" placeholder="e.g. 40,000 km Major PMS, Engine Oil & Filter" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Priority</label>
            <select class="tc-form-select" name="priority">
              <option value="Medium">Medium</option>
              <option value="Critical">Critical</option>
              <option value="Low">Low</option>
            </select>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Scheduled Date</label>
            <input type="date" name="scheduled_date" class="tc-form-control" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Estimated Cost (<?= e(currency_symbol()) ?>)</label>
            <input type="number" step="0.01" name="estimated_cost" class="tc-form-control" placeholder="18500.00" required>
          </div>
          <div class="col-6">
            <label class="tc-form-label">Status</label>
            <select class="tc-form-select" name="status">
              <option value="Scheduled">Scheduled</option>
              <option value="In Repair">In Repair</option>
              <option value="Completed">Completed</option>
            </select>
          </div>
          <div class="col-12">
            <label class="tc-form-label">Notes</label>
            <textarea name="notes" class="tc-form-control" rows="2" placeholder="Service notes / bay allocation"></textarea>
          </div>
        </div>
        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-add-work-order')">Cancel</button>
          <button type="submit" class="tc-btn tc-btn-primary">Create Work Order</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
