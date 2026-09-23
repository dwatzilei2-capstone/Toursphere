<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('costs.view');

$pdo = db();
$ledger = $pdo->query(
    "WITH fuel AS (SELECT vehicle_id,SUM(total_cost) fuel_cost FROM fuel_transactions GROUP BY vehicle_id),
     maint AS (SELECT vehicle_id,SUM(estimated_cost) maintenance_cost FROM maintenance_orders WHERE status='Completed' GROUP BY vehicle_id),
     trip_cost AS (SELECT vehicle_id,SUM(COALESCE(driver_allowance,0)) driver_expense,SUM(COALESCE(toll_fee,0)) toll_cost FROM trips WHERE status='Completed' GROUP BY vehicle_id)
     SELECT v.id vehicle_id,v.plate_number,TRIM(v.brand||' '||v.model) model,
            COALESCE(fuel.fuel_cost,0) fuel_cost,COALESCE(maint.maintenance_cost,0) maintenance_cost,
            COALESCE(trip_cost.driver_expense,0) driver_expense,COALESCE(trip_cost.toll_cost,0) toll_cost,
            COALESCE(fuel.fuel_cost,0)+COALESCE(maint.maintenance_cost,0)+COALESCE(trip_cost.driver_expense,0)+COALESCE(trip_cost.toll_cost,0) total_cost
       FROM vehicles v LEFT JOIN fuel ON fuel.vehicle_id=v.id LEFT JOIN maint ON maint.vehicle_id=v.id LEFT JOIN trip_cost ON trip_cost.vehicle_id=v.id
      ORDER BY total_cost DESC,v.id"
)->fetchAll();

$active_page = 'cost-by-vehicle';
$page_title  = 'Cost by Vehicle Unit';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Cost by Vehicle Unit</h1>
    <p class="text-muted-custom mb-0">Detailed vehicle profitability and lifetime operating expense ledger.</p>
  </div>
  <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/cost-analysis/cost-overview.php">← Cost Overview</a>
</div>

<div class="tc-card p-3">
  <p class="text-muted-custom small">Operating cost metrics reflect combined fuel consumption, tire wear, routine PMS, and allocated driver allowances per vehicle unit.</p>
  <div class="tc-table-container border-0">
    <table class="tc-table">
      <thead>
        <tr>
          <th>Vehicle ID</th>
          <th>Plate</th>
          <th>Model</th>
          <th>Fuel Cost</th>
          <th>Maintenance</th>
          <th>Driver Exp.</th>
          <th>Tolls</th>
          <th>Total Operating Cost</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ledger)): ?>
          <tr><td colspan="8" class="text-center text-muted-custom py-4">No vehicles available for cost analysis.</td></tr>
        <?php else: foreach ($ledger as $l): ?>
          <tr>
            <td><strong><?= e($l['vehicle_id']) ?></strong></td>
            <td><?= e($l['plate_number']) ?></td>
            <td><?= e($l['model']) ?></td>
            <td><?= money0($l['fuel_cost']) ?></td>
            <td><?= money0($l['maintenance_cost']) ?></td>
            <td><?= money0($l['driver_expense']) ?></td>
            <td><?= money0($l['toll_cost']) ?></td>
            <td><strong class="text-primary-custom"><?= money0($l['total_cost']) ?></strong></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
