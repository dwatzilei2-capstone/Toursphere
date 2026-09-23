<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('costs.view');

$pdo = db();

$report_year = (int)($_GET['year'] ?? date('Y'));
if ($report_year < 2000 || $report_year > 2100) $report_year = (int)date('Y');
$report_month = ($report_year === (int)date('Y')) ? (int)date('n') : 12;
$month_start = sprintf('%04d-%02d-01', $report_year, $report_month);
$month_end = date('Y-m-d', strtotime($month_start . ' +1 month'));
$month_label = date('F Y', strtotime($month_start));
$monthly_budget = (float)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'cost.monthly_budget'")->fetchColumn();
$monthlySql = "SELECT COALESCE((SELECT SUM(total_cost) FROM fuel_transactions WHERE transaction_date>=? AND transaction_date<?),0)
 + COALESCE((SELECT SUM(estimated_cost) FROM maintenance_orders WHERE status='Completed' AND scheduled_date>=? AND scheduled_date<?),0)
 + COALESCE((SELECT SUM(COALESCE(driver_allowance,0)+COALESCE(toll_fee,0)) FROM trips WHERE status='Completed' AND actual_arrival>=? AND actual_arrival<?),0)";
$monthlyStmt = $pdo->prepare($monthlySql);
$monthlyStmt->execute([$month_start,$month_end,$month_start,$month_end,$month_start,$month_end]);
$monthly_total = (float)$monthlyStmt->fetchColumn();
$variance_pct   = $monthly_budget > 0 ? (($monthly_budget - $monthly_total) / $monthly_budget) * 100 : 0;
$under_budget   = $variance_pct >= 0;

$trend = array_fill(1,12,0.0);
$trendSql = "SELECT month_number,SUM(amount) amount FROM (
 SELECT EXTRACT(MONTH FROM transaction_date)::int month_number,total_cost amount FROM fuel_transactions WHERE EXTRACT(YEAR FROM transaction_date)::int=?
 UNION ALL SELECT EXTRACT(MONTH FROM scheduled_date)::int,estimated_cost FROM maintenance_orders WHERE status='Completed' AND EXTRACT(YEAR FROM scheduled_date)::int=?
 UNION ALL SELECT EXTRACT(MONTH FROM actual_arrival)::int,COALESCE(driver_allowance,0)+COALESCE(toll_fee,0) FROM trips WHERE status='Completed' AND EXTRACT(YEAR FROM actual_arrival)::int=?
) costs GROUP BY month_number ORDER BY month_number";
$trendStmt = $pdo->prepare($trendSql);
$trendStmt->execute([$report_year,$report_year,$report_year]);
foreach ($trendStmt->fetchAll() as $row) $trend[(int)$row['month_number']] = (float)$row['amount'];
$chart_data = ['chart-cost-trends'=>[
    'type'=>'line','label'=>'Recorded Operating Cost (' . currency_code() . ')','labels'=>['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
    'data'=>array_values($trend),'color'=>'#2F80ED','fill'=>true,'legend'=>true,'money'=>true,
]];

$active_page = 'cost-trends';
$page_title  = 'Transportation Cost Trends & Forecasting';
$include_chart = true;
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Transportation Cost Trends & Forecasting</h1>
    <p class="text-muted-custom mb-0">Live monthly cost history and variance tracking against the configured operational budget.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="?year=<?= $report_year-1 ?>">← <?= $report_year-1 ?></a>
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="?year=<?= $report_year+1 ?>"><?= $report_year+1 ?> →</a>
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/cost-analysis/cost-overview.php">Cost Overview</a>
  </div>
</div>

<div class="tc-card p-3">
  <div class="alert <?= $under_budget ? 'alert-info' : 'alert-warning' ?> border-0 mb-3 small">
    <i class="bi bi-info-circle-fill me-2"></i><strong>Budget Variance:</strong>
    <?= e($month_label) ?> recorded operational expenses (<?= money0($monthly_total) ?>) are
    <strong><?= number_format(abs($variance_pct), 1) ?>% <?= $under_budget ? 'under' : 'over' ?> budget</strong>
    (budget: <?= money0($monthly_budget) ?>).
  </div>
  <div class="p-3 bg-light rounded">
    <h5 class="fw-bold mb-3 text-center">Monthly Fleet Cost Performance: <?= $report_year ?></h5>
    <div style="height:280px"><canvas id="chart-cost-trends"></canvas></div>
  </div>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
