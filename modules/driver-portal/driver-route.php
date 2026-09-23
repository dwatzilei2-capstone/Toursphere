<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('driver.portal');

$pdo = db();

$driver = $pdo->prepare('SELECT * FROM drivers WHERE user_id = ?');
$driver->execute([$current_user['id']]);
$driver = $driver->fetch();

$nextTrip = null;
if ($driver) {
    $tripsStmt = $pdo->prepare(
        "SELECT r.*, t.id AS trip_id, t.route_history_id
           FROM reservations r JOIN trips t ON t.reservation_id = r.id
          WHERE r.assigned_driver_id = ? AND r.status <> 'Completed'
          ORDER BY t.scheduled_departure NULLS LAST, r.departure_date, r.departure_time LIMIT 1"
    );
    $tripsStmt->execute([$driver['id']]);
    $nextTrip = $tripsStmt->fetch() ?: null;
}

 
$preset = null;
$alternatives = [];
$balanced = null;
$savedRoute = null;
if ($nextTrip && !empty($nextTrip['route_history_id'])) {
    $savedStmt = $pdo->prepare('SELECT * FROM route_history WHERE log_id = ? AND trip_id = ? LIMIT 1');
    $savedStmt->execute([$nextTrip['route_history_id'], $nextTrip['trip_id']]);
    $savedRoute = $savedStmt->fetch() ?: null;
    if ($savedRoute) {
        $routePayload = json_decode($savedRoute['route_data_json'] ?? '', true) ?: [];
        $routeSummary = $routePayload['route'] ?? [];
        $balanced = [
            'distance' => $routeSummary['distance'] ?? '—',
            'duration' => $routeSummary['duration'] ?? (($savedRoute['predicted_duration_mins'] ?? null) ? $savedRoute['predicted_duration_mins'] . ' min' : '—'),
            'fuel_estimate' => $routeSummary['fuelEstimate'] ?? (($savedRoute['predicted_fuel_liters'] ?? null) ? $savedRoute['predicted_fuel_liters'] . ' L' : '—'),
        ];
        $candidateStmt = $pdo->prepare('SELECT * FROM ai_route_candidates_log WHERE log_id = ? ORDER BY candidate_index');
        $candidateStmt->execute([$savedRoute['log_id']]);
        foreach ($candidateStmt->fetchAll() as $candidate) {
            $alternatives[] = [
                'mode_key' => $candidate['is_selected'] ? 'selected' : 'candidate',
                'title' => $candidate['summary_title'],
                'distance' => number_format((float)$candidate['distance_km'], 1) . ' km',
                'duration' => (int)$candidate['duration_mins'] . ' min',
                'fuel_estimate' => number_format((float)$candidate['estimated_fuel_l'], 1) . ' L',
                'route_score' => (int)$candidate['composite_score'] . ' / 100',
            ];
        }
    }
}
if (!$savedRoute && $nextTrip && $nextTrip['destination']) {
    $destPart = explode(',', $nextTrip['destination'])[0];
    $presetStmt = $pdo->prepare('SELECT * FROM route_presets WHERE LOWER(destination) LIKE ? LIMIT 1');
    $presetStmt->execute(['%' . strtolower(trim($destPart)) . '%']);
    $preset = $presetStmt->fetch() ?: null;

    if ($preset) {
        $altStmt = $pdo->prepare('SELECT * FROM route_alternatives WHERE preset_id = ? ORDER BY id');
        $altStmt->execute([$preset['id']]);
        $alternatives = $altStmt->fetchAll();

        $balStmt = $pdo->prepare("SELECT * FROM route_alternatives WHERE preset_id = ? AND mode_key = 'balanced'");
        $balStmt->execute([$preset['id']]);
        $balanced = $balStmt->fetch() ?: null;
    }
}

$active_page = 'driver-route';
$page_title  = 'Route Info';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Route Info</h1>
    <p class="text-muted-custom mb-0">Read-only route guidance for your next assigned trip.</p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($nextTrip): ?>
      <a class="tc-btn tc-btn-primary tc-btn-sm" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php?trip_id=<?= urlencode($nextTrip['trip_id']) ?>">
        <i class="bi bi-compass"></i> Open Navigation
      </a>
    <?php endif; ?>
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/driver-portal/driver-dashboard.php">Back to Dashboard</a>
  </div>
</div>

<div class="tc-card p-3 mb-4">
  <div class="alert alert-light border mb-3 small">
    <i class="bi bi-info-circle me-2 text-primary-custom"></i>
    <strong id="driver-route-trip-ref">
      <?= $nextTrip ? 'Route guidance for ' . e($nextTrip['id']) . ' — ' . e($nextTrip['destination']) : 'Route guidance' ?>
    </strong>
    — follow the dispatch-approved itinerary. Route adjustments are managed by your dispatcher.
  </div>
  <div class="row g-3" id="driver-route-summary">
    <?php if ($balanced): ?>
      <div class="col-md-3"><div class="p-2 border rounded bg-light text-center"><div class="text-muted-custom small">Approved Mode</div><div class="fw-bold mt-1"><i class="bi bi-stars text-primary me-1"></i><?= e($savedRoute['selected_mode'] ?? 'Balanced') ?></div></div></div>
      <div class="col-md-3"><div class="p-2 border rounded bg-light text-center"><div class="text-muted-custom small">Distance</div><div class="fw-bold mt-1"><?= e($balanced['distance']) ?></div></div></div>
      <div class="col-md-3"><div class="p-2 border rounded bg-light text-center"><div class="text-muted-custom small">Travel Time</div><div class="fw-bold mt-1 text-primary"><?= e($balanced['duration']) ?></div></div></div>
      <div class="col-md-3"><div class="p-2 border rounded bg-light text-center"><div class="text-muted-custom small">Est. Fuel Burn</div><div class="fw-bold mt-1 text-success"><?= e(explode(' (', $balanced['fuel_estimate'])[0]) ?></div></div></div>
    <?php else: ?>
      <div class="col-12"><div class="p-3 bg-light rounded small text-muted-custom text-center">Route summary will be provided by dispatch for your next assigned trip.</div></div>
    <?php endif; ?>
  </div>
</div>

<div class="tc-card">
  <div class="tc-card-header">
    <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-diagram-3 me-2 text-primary-custom"></i>Route Alternatives</h4>
  </div>
  <div class="tc-table-container border-0">
    <table class="tc-table">
      <thead>
        <tr>
          <th>Route Mode</th>
          <th>Distance</th>
          <th>Travel Time</th>
          <th>Fuel Burn</th>
          <th>Recommendation</th>
        </tr>
      </thead>
      <tbody id="driver-route-alternatives">
        <?php if (empty($alternatives)): ?>
          <tr><td colspan="5" class="text-center text-muted-custom py-3">Route alternatives are generated by the AI Route Planner once your trip is scheduled.</td></tr>
        <?php else:
          $mode_labels = ['balanced' => 'Balanced (AI Recommended)', 'fastest' => 'Fastest Express', 'fuelEfficient' => 'Eco / Fuel Efficient', 'shortest' => 'Shortest Distance'];
          $mode_icons = ['balanced' => 'bi-stars text-primary', 'fastest' => 'bi-lightning-charge text-warning', 'fuelEfficient' => 'bi-fuel-pump text-success', 'shortest' => 'bi-signpost-2 text-secondary'];
          foreach ($alternatives as $a):
        ?>
          <tr>
            <td><strong><i class="bi <?= $mode_icons[$a['mode_key']] ?? 'bi-signpost-2 text-secondary' ?> me-1"></i><?= e($mode_labels[$a['mode_key']] ?? $a['title']) ?></strong></td>
            <td><?= e($a['distance']) ?></td>
            <td><?= e($a['duration']) ?></td>
            <td><?= e(explode(' (', $a['fuel_estimate'])[0]) ?></td>
            <td>
              <span class="badge <?= str_starts_with($a['route_score'], '9') ? 'bg-success' : (str_starts_with($a['route_score'], '8') ? 'bg-secondary' : 'bg-warning text-dark') ?> border"><?= e($a['route_score']) ?></span>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>


