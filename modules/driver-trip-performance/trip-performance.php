<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('drivers.view');

$pdo = db();

 
 
$perf_stmt = $pdo->prepare(
    "SELECT d.name,
            COUNT(t.id) FILTER (WHERE t.status = 'Completed') AS completed_trips,
            COALESCE(
              100.0 * COUNT(t.id) FILTER (
                WHERE t.status = 'Completed'
                  AND t.actual_departure IS NOT NULL AND t.scheduled_departure IS NOT NULL
                  AND t.actual_departure <= t.scheduled_departure + INTERVAL '15 minutes'
              ) / NULLIF(COUNT(t.id) FILTER (
                WHERE t.status = 'Completed'
                  AND t.actual_departure IS NOT NULL AND t.scheduled_departure IS NOT NULL
              ), 0), 0
            ) AS on_time_rate
       FROM drivers d
       LEFT JOIN trips t ON t.driver_id = d.id
      WHERE d.status <> :inactive_status
      GROUP BY d.id, d.name
      HAVING COUNT(t.id) FILTER (WHERE t.status = 'Completed') > 0
      ORDER BY d.id"
);
$perf_stmt->execute(['inactive_status' => 'Inactive']);
$perf_rows = $perf_stmt->fetchAll();
$perf_labels = array_map(fn($d) => driver_short_name($d['name']), $perf_rows);
$perf_data   = array_map(fn($d) => (float)$d['on_time_rate'], $perf_rows);

 
$total_completed = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE status = 'Completed'")->fetchColumn();
$timingKpis = $pdo->query(
    "SELECT
       COUNT(*) FILTER (WHERE actual_departure IS NOT NULL AND scheduled_departure IS NOT NULL) measurable,
       COUNT(*) FILTER (
         WHERE actual_departure IS NOT NULL AND scheduled_departure IS NOT NULL
           AND actual_departure <= scheduled_departure + INTERVAL '15 minutes'
       ) on_time,
       COALESCE(AVG(EXTRACT(EPOCH FROM (actual_departure - scheduled_departure)) / 60.0)
         FILTER (WHERE actual_departure IS NOT NULL AND scheduled_departure IS NOT NULL), 0) avg_variance
     FROM trips WHERE status = 'Completed'"
)->fetch();
$measurable_trips = (int)($timingKpis['measurable'] ?? 0);
$avg_ontime = $measurable_trips > 0 ? ((int)$timingKpis['on_time'] / $measurable_trips) * 100 : 0;
$avg_departure_variance = (float)($timingKpis['avg_variance'] ?? 0);
$reservationKpis = $pdo->query(
    "SELECT COUNT(*) total, COUNT(*) FILTER (WHERE status = 'Cancelled') cancelled FROM reservations"
)->fetch();
$total_reservations = (int)($reservationKpis['total'] ?? 0);
$cancel_rate = $total_reservations > 0 ? ((int)$reservationKpis['cancelled'] / $total_reservations) * 100 : 0;

$tripTrendRows = $pdo->query(
    "SELECT id,
            ROUND((EXTRACT(EPOCH FROM (actual_departure - scheduled_departure)) / 60.0)::numeric, 1) departure_variance
       FROM trips
      WHERE status = 'Completed' AND actual_departure IS NOT NULL AND scheduled_departure IS NOT NULL
      ORDER BY COALESCE(actual_arrival, actual_departure), id"
)->fetchAll();
$tripTrendLabels = array_column($tripTrendRows, 'id');
$tripTrendData = array_map('floatval', array_column($tripTrendRows, 'departure_variance'));

$chart_data = [
    'chart-driver-on-time' => [
        'type'   => 'bar',
        'label'  => 'On-Time Score %',
        'labels' => $perf_labels,
        'data'   => $perf_data,
        'color'  => '#27AE60',
        'min'    => 0,
        'max'    => 100,
        'legend' => false,
    ],
    'chart-trip-punctuality' => [
        'type'   => 'line',
        'label'  => 'Departure Variance (minutes)',
        'labels' => $tripTrendLabels,
        'data'   => $tripTrendData,
        'color'  => '#2F80ED',
        'fill'   => true,
        'legend' => true,
    ],
];

$active_page = 'trip-performance';
$page_title  = 'Trip Performance & Punctuality Analytics';
$include_chart = true;
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Trip Performance & Punctuality Analytics</h1>
    <p class="text-muted-custom mb-0">Historical completion analysis, route delay factors, and driver punctuality scoring.</p>
  </div>
  <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/driver-trip-performance/driver-performance.php">← Driver Directory</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="tc-card p-3">
      <h4 class="fw-bold mb-3 fs-6">Driver On-Time Rating Benchmark (%)</h4>
      <div style="height: 240px;">
        <canvas id="chart-driver-on-time"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="tc-card p-3">
      <h4 class="fw-bold mb-3 fs-6">Key Performance Indicators</h4>
      <div class="p-2 border rounded mb-2 d-flex justify-content-between">
        <span class="text-muted-custom">Total Fleet Completed Trips:</span>
        <span class="fw-bold text-success"><?= number_format($total_completed) ?> Trips</span>
      </div>
      <div class="p-2 border rounded mb-2 d-flex justify-content-between">
        <span class="text-muted-custom">Fleet On-Time Departure Rate:</span>
        <span class="fw-bold text-primary"><?= number_format($avg_ontime, 1) ?>%</span>
      </div>
      <div class="p-2 border rounded mb-2 d-flex justify-content-between">
        <span class="text-muted-custom">Reservation Cancellation Rate:</span>
        <span class="fw-bold text-muted"><?= number_format($cancel_rate, 1) ?>%</span>
      </div>
      <div class="p-2 border rounded d-flex justify-content-between">
        <span class="text-muted-custom">Average Departure Variance:</span>
        <span class="fw-bold <?= $avg_departure_variance <= 15 ? 'text-success' : 'text-warning' ?>"><?= $avg_departure_variance > 0 ? '+' : '' ?><?= number_format($avg_departure_variance, 1) ?> min</span>
      </div>
    </div>
  </div>
</div>

<div class="tc-card p-3 mb-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="fw-bold mb-0 fs-6">Completed Trip Departure Variance</h4>
    <span class="badge bg-success-subtle text-success border">On-time benchmark: 15 minutes or less</span>
  </div>
  <?php if ($tripTrendLabels): ?>
    <div style="height: 260px;">
      <canvas id="chart-trip-punctuality"></canvas>
    </div>
  <?php else: ?>
    <div class="text-center text-muted-custom py-5">Complete a trip with scheduled and actual departure timestamps to populate this graph.</div>
  <?php endif; ?>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
