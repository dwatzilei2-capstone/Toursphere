<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('ai.view');

$pdo = db();
if (($current_user['role_code'] ?? '') === 'driver') {
    $historyStmt = $pdo->prepare(
        'SELECT rh.* FROM route_history rh
          JOIN trips t ON t.id = rh.trip_id
          JOIN drivers d ON d.id = t.driver_id
         WHERE d.user_id = ? ORDER BY rh.generated_date DESC'
    );
    $historyStmt->execute([$current_user['id']]);
    $history = $historyStmt->fetchAll();
} else {
    $history = $pdo->query('SELECT * FROM route_history ORDER BY generated_date DESC')->fetchAll();
}

$active_page = 'route-history';
$page_title  = 'AI Route Generation History & Logs';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">AI Route Generation History & Logs</h1>
    <p class="text-muted-custom mb-0">Audit logs of all previously generated route polylines and real-world variance accuracy.</p>
  </div>
  <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php">← Back to Map</a>
</div>

<div class="tc-card">
  <div class="tc-table-container border-0">
    <table class="tc-table align-middle">
      <thead>
        <tr>
          <th>Log ID</th>
          <th>Route Title</th>
          <th>Vehicle</th>
          <th>Generated Date</th>
          <th>Optimization Mode</th>
          <th>Model Version</th>
          <th>Est. Duration</th>
          <th>Est. Fuel</th>
          <th>Route Score</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($history)): ?>
          <tr><td colspan="9" class="text-center text-muted-custom py-4">No route generation logs recorded yet.</td></tr>
        <?php else: foreach ($history as $h): ?>
          <tr>
            <td><strong><?= e($h['log_id']) ?></strong></td>
            <td><?= e($h['route_title']) ?></td>
            <td><?= e($h['vehicle'] ?? '—') ?></td>
            <td><?= e(date('Y-m-d H:i', strtotime($h['generated_date']))) ?></td>
            <td><span class="badge bg-primary"><?= e($h['selected_mode']) ?></span></td>
            <td><span class="badge bg-light text-dark border"><?= e($h['model_version'] ?? 'v0-kinematic') ?></span></td>
            <td><?= !empty($h['predicted_duration_mins']) ? e($h['predicted_duration_mins']) . ' min' : '—' ?></td>
            <td><?= !empty($h['predicted_fuel_liters']) ? e($h['predicted_fuel_liters']) . ' L' : '—' ?></td>
            <td><span class="badge bg-success-subtle text-success border"><?= e($h['variance_accuracy'] ?? 'Optimal') ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
