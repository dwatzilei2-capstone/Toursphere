<?php
 
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();

$trip_id = $_GET['id'] ?? 'TRP-8801';
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
    <div class="tc-card p-3 mb-3">
      <h4 class="fw-bold mb-3 fs-6"><i class="bi bi-info-circle me-2 text-primary-custom"></i>Trip Summary & Allocation</h4>
      <div class="row g-2 small">
        <div class="col-6"><span class="text-muted-custom">Vehicle:</span> <strong><?= e($trip['vehicle_brand'] ?? '') ?> <?= e($trip['vehicle_model'] ?? '') ?> (<?= e($trip['plate_number'] ?? '—') ?>)</strong></div>
        <div class="col-6"><span class="text-muted-custom">Assigned Driver:</span> <strong><?= e($trip['driver_name'] ?? '—') ?></strong></div>
        <div class="col-6"><span class="text-muted-custom">Departure Time:</span> <strong><?= e(date('h:i A (M d, Y)', strtotime($trip['scheduled_departure']))) ?></strong></div>
        <div class="col-6"><span class="text-muted-custom">Distance:</span> <strong><?= number_format((float)$trip['distance_km'], 1) ?> km</strong></div>
        <div class="col-6"><span class="text-muted-custom">Estimated Fuel:</span> <strong><?= e($trip['fuel_estimate'] ?? '—') ?></strong></div>
        <div class="col-6"><span class="text-muted-custom">Total Trip Cost:</span> <strong class="text-primary-custom"><?= money($trip['total_cost']) ?></strong></div>
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
