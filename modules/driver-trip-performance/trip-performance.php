<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_once ROOT_PATH.'/includes/trip_performance.php';
if(!performance_access($current_user)){http_response_code(403);exit('Forbidden');}
$_SESSION['trip_performance_csrf'] ??= bin2hex(random_bytes(32));

$pdo = db();

 
 
if(!has_role('driver')) {
$deliveryKpis=performance_delivery_kpis($pdo);
$perf_labels=[];$perf_data=[];$perf_counts=[];
foreach($pdo->query('SELECT id,name FROM drivers ORDER BY id') as $driver){
    $delivery=$deliveryKpis['on_time_score_by_driver'][$driver['id']]??null;
    if(!$delivery||$delivery['average']===null)continue;
    $perf_labels[]=driver_short_name($driver['name']);
    $perf_data[]=(float)$delivery['average'];
    $perf_counts[]=['count'=>(int)$delivery['count']];
}

$tripTrendRows = $deliveryKpis['overall_score_trend'];
$tripTrendLabels = array_column($tripTrendRows, 'trip_id');
$tripTrendData = array_map('floatval', array_column($tripTrendRows, 'score'));
$tripPointSpacing=max(100,max(array_map('strlen',$tripTrendLabels)?:[0])*8+36);

$chart_data = [
    'chart-driver-on-time' => [
        'type'   => 'bar',
        'label'  => 'Average On-Time Delivery Score /100',
        'labels' => $perf_labels,
        'data'   => $perf_data,
        'evaluation_counts' => $perf_counts,
        'tooltip_value_only' => true,
        'unit' => 'score',
        'color'  => '#27AE60',
        'min'    => 0,
        'max'    => 100,
        'legend' => false,
    ],
    'chart-trip-performance-score' => [
        'type'   => 'line',
        'label'  => 'Overall Performance Score /100',
        'tooltip_label' => 'Overall score',
        'tooltip_value_only' => true,
        'unit' => 'score',
        'min' => 0,
        'max' => 100,
        'labels' => $tripTrendLabels,
        'data'   => $tripTrendData,
        'color'  => '#2F80ED',
        'fill'   => true,
        'legend' => false,
    ],
];

}
$active_page = 'trip-performance';
$body_class = trim(($body_class ?? '') . ' driver-trip-monitoring-module trip-performance-page');
$page_title  = 'Trip Performance & Punctuality Analytics';
$include_chart = !has_role('driver');
require ROOT_PATH . '/includes/header.php';
?>

<div class="driver-trip-page-shell">
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Trip Performance & Punctuality Analytics</h1>
    <p class="text-muted-custom mb-0">Historical completion analysis, route delay factors, and driver punctuality scoring.</p>
  </div>
  <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/<?= has_role('driver')?'modules/driver-portal/driver-dashboard.php':'modules/driver-trip-performance/driver-performance.php' ?>">← <?= has_role('driver')?'Dashboard':'Driver Directory' ?></a>
</div>

<?php require ROOT_PATH.'/includes/trip_performance_view.php'; ?>
<?php if(!has_role('driver')): ?>
<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="tc-card p-3">
      <h4 class="fw-bold mb-3 fs-6">Average On-Time Delivery Score by Driver</h4>
      <p class="small text-muted-custom mb-2"><strong>Uses the numeric On-Time Delivery scores from finalized evaluations.</strong> One score of 80 displays 80/100. This is one criterion, separate from the overall performance score.</p>
      <div style="height: 240px;">
        <?php if($perf_labels): ?><canvas id="chart-driver-on-time"></canvas>
        <?php else: ?><div class="h-100 d-flex flex-column align-items-center justify-content-center text-muted-custom" role="status"><strong>No data</strong><span class="small text-center mt-1">No qualifying finalized On-Time Delivery scores yet.</span></div><?php endif; ?>
      </div>
      <?php if($perf_labels): ?><ul class="list-unstyled small mb-0 mt-3" aria-label="Finalized evaluation counts by driver"><?php foreach($perf_counts as $index=>$counts): ?><li class="d-flex flex-wrap justify-content-between gap-2 border-top py-2"><strong><?= e($perf_labels[$index]) ?></strong><span><?= $counts['count'] ?> finalized evaluation<?= $counts['count']===1?'':'s' ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="tc-card p-3">
      <h4 class="fw-bold mb-3 fs-6">Key Performance Indicators</h4>
      <p class="small text-muted-custom mb-2">Verified cargo deliveries only. The On-Time Delivery score is the average numeric criterion score in qualifying finalized evaluations. Warehouse On time / Late confirmations do not replace the numeric score. Average delay uses recorded timing only; warehouse confirmations do not invent delay minutes. Development simulations are excluded.</p>
      <div class="p-2 border rounded mb-2 d-flex justify-content-between">
        <span class="text-muted-custom">Verified Completed Deliveries:</span>
        <span class="fw-bold text-success"><?= number_format($deliveryKpis['completed']) ?> Deliveries</span>
      </div>
      <div class="p-2 border rounded mb-2 d-flex justify-content-between">
        <span class="text-muted-custom">Average On-Time Delivery Score:</span>
        <div class="text-end"><span class="fw-bold text-primary"><?= $deliveryKpis['on_time_score']===null?'No data':number_format($deliveryKpis['on_time_score'],1).' / 100' ?></span><?php if($deliveryKpis['on_time_score_count']): ?><div class="small text-muted-custom"><?= (int)$deliveryKpis['on_time_score_count'] ?> finalized evaluation<?= $deliveryKpis['on_time_score_count']===1?'':'s' ?></div><?php endif; ?></div>
      </div>
      <div class="p-2 border rounded mb-2 d-flex justify-content-between">
        <span class="text-muted-custom">Request Cancellation Rate:</span>
        <span class="fw-bold text-muted"><?= $deliveryKpis['cancellation_rate']===null?'No data':number_format($deliveryKpis['cancellation_rate'],1).'%' ?></span>
      </div>
      <div class="p-2 border rounded d-flex justify-content-between">
        <span class="text-muted-custom">Average Delivery Delay:</span>
        <span class="fw-bold <?= $deliveryKpis['average_delay']===null?'text-muted':($deliveryKpis['average_delay']>0?'text-warning':'text-success') ?>"><?= $deliveryKpis['average_delay']===null?'No data':number_format($deliveryKpis['average_delay'],1).' min' ?></span>
      </div>
    </div>
  </div>
</div>

<div class="tc-card p-3 mb-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="fw-bold mb-0 fs-6">Overall Performance Score by Trip</h4>
    <span class="badge bg-primary-subtle text-primary border">Finalized evaluations · Score / 100</span>
  </div>
  <?php if ($tripTrendLabels): ?>
    <p class="small text-muted-custom mb-2">Ordered by final approval. Each point is one trip's overall evaluation score.</p>
    <div class="performance-score-chart-scroll" role="region" aria-label="Overall performance scores; scroll horizontally to view more trips" tabindex="0">
      <div style="height:280px;width:100%;min-width:<?= count($tripTrendLabels)*$tripPointSpacing ?>px"><canvas id="chart-trip-performance-score" role="img" aria-label="Overall performance scores by trip"></canvas></div>
    </div>
    <?php if(count($tripTrendLabels)===1): ?><p class="small text-muted-custom mt-2 mb-0">1 finalized trip. More finalized evaluations will extend the line.</p><?php endif; ?>
  <?php else: ?>
    <div class="text-center text-muted-custom py-5" role="status"><strong>No finalized scores yet</strong><div class="small mt-1">Approved overall trip evaluation scores will appear here.</div></div>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>

<script src="<?= BASE_URL ?>/js/driver-trip-monitoring.js?v=<?= (int)filemtime(ROOT_PATH . '/js/driver-trip-monitoring.js') ?>"></script>
<script src="<?= BASE_URL ?>/js/trip-performance.js?v=<?= (int)filemtime(ROOT_PATH . '/js/trip-performance.js') ?>" defer></script>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
