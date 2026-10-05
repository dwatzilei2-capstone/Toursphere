<?php
 
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once ROOT_PATH . '/includes/trip_funding.php';

$pdo = db();

$trip_id = $_GET['id'] ?? 'TRP-8801';
if (has_role('driver')) {
    $owner=$pdo->prepare('SELECT 1 FROM trips t JOIN drivers d ON d.id=t.driver_id WHERE t.id=? AND d.user_id=?');
    $owner->execute([$trip_id,$current_user['id']]);
    if(!$owner->fetchColumn()){http_response_code(404);exit('Trip not found.');}
}
if (has_role('customer')) {
    $owner = $pdo->prepare('SELECT 1 FROM trips t JOIN reservations r ON r.id=t.reservation_id WHERE t.id=? AND r.customer_id=?');
    $owner->execute([$trip_id, $current_user['id']]);
    if (!$owner->fetchColumn()) { http_response_code(404); exit('Trip not found.'); }
}
$stmt = $pdo->prepare(
    "SELECT t.*, v.plate_number, v.model AS vehicle_model, v.brand AS vehicle_brand, v.type AS vehicle_type,
            d.name AS driver_name, r.client_name
       FROM trips t
       LEFT JOIN vehicles v ON v.id = t.vehicle_id
       LEFT JOIN drivers d  ON d.id = t.driver_id
       LEFT JOIN reservations r ON r.id = t.reservation_id
      WHERE t.id = ?"
);
$stmt->execute([$trip_id]);
$trip = $stmt->fetch();

$timeline = [];
if ($trip) {
    $tlStmt = $pdo->prepare('SELECT * FROM trip_timeline WHERE trip_id = ? ORDER BY sort_order');
    $tlStmt->execute([$trip['id']]);
    $timeline = $tlStmt->fetchAll();
}

$active_page = 'dashboard';
$include_role_portal_polish = true;
$page_title  = 'Trip Details';
require ROOT_PATH . '/includes/header.php';
?>

<?php if (!$trip): ?>
  <div class="tc-card p-4 text-center text-muted-custom">
    <i class="bi bi-search fs-1 d-block mb-2 text-primary-custom"></i>
    Trip <strong><?= e($trip_id) ?></strong> was not found.
  </div>
<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Trip Details: <?= e($trip['id']) ?></h1>
    <p class="text-muted-custom mb-0"><?= e($trip['origin']) ?> → <?= e($trip['destination']) ?> (<?= (int)$trip['passengers'] ?> Passengers)</p>
  </div>
  <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/dashboard.php">← Back to Dashboard</a>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <?php if (funding_can_view()): ?>
    <section class="tc-card funding-inline p-3 mb-3"><h2 class="fs-6 fw-bold">Trip Funding</h2><p class="small text-muted-custom">Review the saved estimate, requested funding and Finance status before dispatch.</p><button type="button" class="tc-btn tc-btn-primary tc-btn-sm" onclick="TripFunding.open(null, '<?= e($trip['id']) ?>')">View Trip Funding</button></section>
    <?php elseif (has_role('driver')): $safeFunding=funding_request($pdo,$trip['id']); ?>
    <section class="tc-card p-3 mb-3"><h2 class="fs-6 fw-bold">Trip Funding</h2><div class="small">Funding Method: <?= e($safeFunding['method_name'] ?? 'Not Requested') ?><br>Funding Status: <?= e($safeFunding['status'] ?? 'Not Requested') ?></div></section>
    <?php endif; ?>
    <div class="tc-card p-3 mb-3">
      <h4 class="fw-bold mb-3 fs-6"><i class="bi bi-info-circle me-2 text-primary-custom"></i>Trip Summary & Allocation</h4>
      <div class="row g-2 small">
        <div class="col-6"><span class="text-muted-custom">Vehicle:</span> <strong><?= e($trip['vehicle_brand'] ?? '') ?> <?= e($trip['vehicle_model'] ?? '') ?> (<?= e($trip['plate_number'] ?? '—') ?>)</strong></div>
        <div class="col-6"><span class="text-muted-custom">Assigned Driver:</span> <strong><?= e($trip['driver_name'] ?? '—') ?></strong></div>
        <div class="col-6"><span class="text-muted-custom">Departure Time:</span> <strong><?= e(date('h:i A (M d, Y)', strtotime($trip['scheduled_departure']))) ?></strong></div>
        <div class="col-6"><span class="text-muted-custom">Distance:</span> <strong><?= number_format((float)$trip['distance_km'], 1) ?> km</strong></div>
        <div class="col-6"><span class="text-muted-custom">Estimated Fuel:</span> <strong><?= e($trip['fuel_estimate'] ?? '—') ?></strong></div>
        <div class="col-6"><span class="text-muted-custom">Actual Recorded Trip Cost:</span> <strong class="text-primary-custom"><?= funding_money(funding_actual_cost($pdo,$trip['id'])) ?></strong></div>
      </div>
    </div>

    <div class="tc-card p-3">
      <h4 class="fw-bold mb-3 fs-6"><i class="bi bi-clock-history me-2 text-primary-custom"></i>Trip Progress Timeline</h4>
      <div class="trip-timeline">
        <?php foreach ($timeline as $step): ?>
          <div class="timeline-step <?= $step['completed'] ? 'completed' : '' ?> <?= $step['active_step'] ? 'active' : '' ?>">
            <div class="timeline-node"><?= $step['completed'] ? '<i class="bi bi-check"></i>' : ($step['active_step'] ? '<i class="bi bi-truck"></i>' : '') ?></div>
            <div class="fw-bold small <?= $step['active_step'] ? 'text-primary-custom' : ($step['completed'] ? '' : 'text-muted') ?>"><?= e($step['title']) ?></div>
            <div class="text-muted-custom" style="font-size: 11px;"><?= e($step['event_time']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="tc-card p-3 h-100">
      <h4 class="fw-bold mb-2 fs-6"><i class="bi bi-pin-map me-2 text-primary-custom"></i>Live Route Alignment</h4>
      <p class="text-muted-custom small mb-3">AI-Optimized highway corridor via <?= e($trip['waypoints'] ?? 'approved corridors') ?>.</p>
      <div class="p-3 bg-light rounded text-center my-4">
        <i class="bi bi-map fs-1 text-primary-custom mb-2 d-block"></i>
        <div class="fw-semibold">Interactive Route Polyline Active</div>
        <div class="text-muted-custom small"><?= (int)$trip['progress_pct'] ?>% of journey completed without traffic incident</div>
      </div>
      <a class="tc-btn tc-btn-primary w-100 tc-btn-sm" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php?trip_id=<?= urlencode($trip['id']) ?>">
        Open in AI Route Optimizer Workspace
      </a>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
