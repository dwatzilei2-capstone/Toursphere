<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('costs.view');

$pdo = db();

$month_start = date('Y-m-01');
$month_end = date('Y-m-01', strtotime('+1 month'));
$month_label = date('F Y');
$monthly_budget = (float)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'cost.monthly_budget'")->fetchColumn();

$categorySql = "SELECT 'Fuel Consumption' name, COALESCE(SUM(total_cost),0) amount FROM fuel_transactions WHERE transaction_date >= ? AND transaction_date < ?
 UNION ALL SELECT 'Vehicle Maintenance & Parts', COALESCE(SUM(estimated_cost),0) FROM maintenance_orders WHERE status='Completed' AND scheduled_date >= ? AND scheduled_date < ?
 UNION ALL SELECT 'Driver Allowances & Per Diem', COALESCE(SUM(driver_allowance),0) FROM trips WHERE status='Completed' AND actual_arrival >= ? AND actual_arrival < ?
 UNION ALL SELECT 'Toll Fees & Parking', COALESCE(SUM(toll_fee),0) FROM trips WHERE status='Completed' AND actual_arrival >= ? AND actual_arrival < ?";
$categoryStmt = $pdo->prepare($categorySql);
$categoryStmt->execute([$month_start,$month_end,$month_start,$month_end,$month_start,$month_end,$month_start,$month_end]);
$rawCategories = $categoryStmt->fetchAll();
$monthly_total = array_sum(array_map('floatval', array_column($rawCategories, 'amount')));
$categoryColors = ['#2F80ED','#F2994A','#27AE60','#56CCF2'];
$categories = [];
foreach ($rawCategories as $i => $row) {
    $amount = (float)$row['amount'];
    if ($amount <= 0) continue;
    $categories[] = ['name'=>$row['name'], 'amount'=>$amount,
        'pct'=>$monthly_total > 0 ? ($amount/$monthly_total)*100 : 0,
        'color'=>$categoryColors[$i] ?? '#9CA3AF'];
}
$variance_pct = $monthly_budget > 0 ? (($monthly_budget - $monthly_total) / $monthly_budget) * 100 : 0;

$vehicleCategoryStmt = $pdo->prepare(
    "WITH fuel AS (SELECT vehicle_id,SUM(total_cost) cost FROM fuel_transactions WHERE transaction_date>=? AND transaction_date<? GROUP BY vehicle_id),
     maint AS (SELECT vehicle_id,SUM(estimated_cost) cost FROM maintenance_orders WHERE status='Completed' AND scheduled_date>=? AND scheduled_date<? GROUP BY vehicle_id),
     trip_cost AS (SELECT vehicle_id,SUM(COALESCE(driver_allowance,0)+COALESCE(toll_fee,0)) cost,SUM(COALESCE(distance_km,0)) km FROM trips WHERE status='Completed' AND actual_arrival>=? AND actual_arrival<? GROUP BY vehicle_id)
     SELECT v.type category_name,COUNT(*) FILTER (WHERE v.status<>'Maintenance') active_units,
            SUM(COALESCE(fuel.cost,0)+COALESCE(maint.cost,0)+COALESCE(trip_cost.cost,0)) monthly_expense,
            SUM(COALESCE(trip_cost.km,0)) total_km
       FROM vehicles v LEFT JOIN fuel ON fuel.vehicle_id=v.id LEFT JOIN maint ON maint.vehicle_id=v.id LEFT JOIN trip_cost ON trip_cost.vehicle_id=v.id
      GROUP BY v.type ORDER BY v.type"
);
$vehicleCategoryStmt->execute([$month_start,$month_end,$month_start,$month_end,$month_start,$month_end]);
$by_vehicle = $vehicleCategoryStmt->fetchAll();

 
$recommendations = [];
if ($monthly_total <= 0) {
    $recommendations[] = ['title'=>'No Current-Month Cost Data', 'body'=>'Record fuel refills, completed maintenance, tolls, and Driver allowances to generate optimization recommendations.'];
} else {
    usort($categories, fn($a,$b) => $b['amount'] <=> $a['amount']);
    $largest = $categories[0];
    $recommendations[] = ['title'=>'Largest Cost Driver', 'body'=>$largest['name'].' accounts for '.number_format($largest['pct'],1).'% ('.money0($largest['amount']).') of '.$month_label.' operating cost.'];
    $recommendations[] = ['title'=>'Budget Position', 'body'=>$monthly_budget > 0
        ? 'Current costs are '.number_format(abs($variance_pct),1).'% '.($variance_pct >= 0 ? 'under' : 'over').' the configured monthly budget.'
        : 'Set a monthly operating budget to enable variance monitoring.'];
    $completedThisMonthStmt = $pdo->prepare("SELECT COUNT(*) FROM trips WHERE status='Completed' AND actual_arrival>=? AND actual_arrival<?");
    $completedThisMonthStmt->execute([$month_start,$month_end]);
    $completedThisMonth = (int)$completedThisMonthStmt->fetchColumn();
    $recommendations[] = ['title'=>'Cost per Completed Trip', 'body'=>$completedThisMonth > 0
        ? money0($monthly_total/$completedThisMonth).' average recorded operating cost across '.$completedThisMonth.' completed trip(s).'
        : 'No completed trips this month; current costs cannot yet be normalized per trip.'];
}

 
$vehicle_expenses = array_map('floatval', array_column($by_vehicle, 'monthly_expense'));
$max_expense = $vehicle_expenses ? max($vehicle_expenses) : 1.0;

$chart_data = [
    'chart-cost-breakdown' => [
        'type'   => 'pie',
        'labels' => array_column($categories, 'name'),
        'data'   => array_map('floatval', array_column($categories, 'pct')),
        'colors' => array_column($categories, 'color'),
        'legend' => true,
    ],
];

$active_page = 'cost-overview';
$page_title  = 'Transport Cost Analysis & Optimization (TCAO)';
$include_chart = true;
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h1 class="mb-1">Transport Cost Analysis & Optimization (TCAO)</h1>
    <p class="text-muted-custom mb-0">Expense breakdown by fuel, maintenance, driver per diems, tolls, and optimization opportunities.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/cost-analysis/cost-by-vehicle.php">Cost by Vehicle</a>
    <a class="tc-btn tc-btn-primary tc-btn-sm" href="<?= BASE_URL ?>/modules/cost-analysis/cost-trends.php">Cost Trends</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="tc-card p-3 h-100">
      <h4 class="fw-bold mb-3 fs-6"><i class="bi bi-pie-chart me-2 text-primary-custom"></i><?= e($month_label) ?> Expense Categories (<?= money0($monthly_total) ?> Total)</h4>
      <?php if ($categories): ?>
        <div style="height: 230px;">
          <canvas id="chart-cost-breakdown"></canvas>
        </div>
      <?php else: ?>
        <div class="d-flex align-items-center justify-content-center text-center text-muted-custom" style="height:230px;">
          Record fuel, maintenance, toll, or Driver expenses to populate the cost breakdown.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="tc-card p-3 h-100">
      <h4 class="fw-bold mb-3 fs-6"><i class="bi bi-lightbulb me-2 text-warning"></i>Data-Driven Cost Optimization Recommendations</h4>
      <?php if (!$recommendations): ?>
        <div class="text-center text-muted-custom py-5">No cost recommendations are available yet.</div>
      <?php else: foreach ($recommendations as $i => $rec): ?>
        <div class="p-2 border rounded mb-2 bg-light">
          <div class="fw-bold small <?= $i === 0 ? 'text-primary-custom' : ($i === 1 ? 'text-success' : 'text-info') ?>"><?= e($rec['title']) ?></div>
          <div class="small text-muted-custom"><?= e($rec['body']) ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<div class="tc-card">
  <div class="tc-card-header">
    <h4 class="mb-0 fw-bold fs-6">Operating Cost Breakdown by Vehicle Category</h4>
  </div>
  <div class="tc-table-container border-0">
    <table class="tc-table">
      <thead>
        <tr>
          <th>Vehicle Category</th>
          <th>Active Units</th>
          <th>Monthly Expense</th>
          <th>Average Cost / KM</th>
          <th>Expense Share</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($by_vehicle)): ?>
          <tr><td colspan="5" class="text-center text-muted-custom py-4">No cost category data available.</td></tr>
        <?php else: foreach ($by_vehicle as $c): $share = round(((float)$c['monthly_expense'] / ($monthly_total ?: 1)) * 100); ?>
          <tr>
            <td class="fw-semibold"><?= e($c['category_name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= (int)$c['active_units'] ?> Active</span></td>
            <td class="fw-bold text-primary-custom"><?= money0($c['monthly_expense']) ?></td>
            <td><span class="badge bg-info-subtle text-info border"><?= money((float)$c['monthly_expense'] / max(1, (float)$c['total_km'])) ?>/km</span></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height: 6px;">
                  <div class="progress-bar bg-primary" role="progressbar" style="width: <?= round(($c['monthly_expense'] / $max_expense) * 100) ?>%;"></div>
                </div>
                <span class="small text-muted-custom" style="min-width: 38px;"><?= $share ?>%</span>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
