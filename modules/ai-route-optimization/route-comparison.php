<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('ai.view');
if (($current_user['role_code'] ?? '') === 'driver') {
    redirect_to(BASE_URL . '/modules/ai-route-optimization/ai-route-planner.php');
}

$pdo = db();

$presets = $pdo->query('SELECT * FROM route_presets ORDER BY id')->fetchAll();
$selected_preset = $_GET['preset'] ?? ($presets[0]['id'] ?? 'ROUTE-PRESET-1');

$alternatives = [];
$stmt = $pdo->prepare(
    'SELECT a.* FROM route_alternatives a WHERE a.preset_id = ? ORDER BY a.id'
);
$stmt->execute([$selected_preset]);
$alternatives = $stmt->fetchAll();

 
$order = ['balanced', 'fastest', 'fuelEfficient', 'shortest'];
usort($alternatives, function ($a, $b) use ($order) {
    return (array_search($a['mode_key'], $order) ?: 99) <=> (array_search($b['mode_key'], $order) ?: 99);
});

$mode_labels = [
    'balanced'      => 'Balanced (Recommended)',
    'fastest'       => 'Fastest Express',
    'fuelEfficient' => 'Eco / Fuel Efficient',
    'shortest'      => 'Shortest Distance',
];
$mode_icons = [
    'balanced'      => 'bi-stars',
    'fastest'       => 'bi-lightning-charge',
    'fuelEfficient' => 'bi-fuel-pump',
    'shortest'      => 'bi-signpost-2',
];
$mode_colors = [
    'balanced'      => 'text-primary-custom',
    'fastest'       => 'text-warning',
    'fuelEfficient' => 'text-success',
    'shortest'      => 'text-secondary',
];

$active_page = 'route-comparison';
$page_title  = 'AI Route Comparison Matrix';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">AI Route Comparison Matrix</h1>
    <p class="text-muted-custom mb-0">Evaluate trade-offs across Distance, Duration, Fuel Burn, Toll Fees, and Road Stress.</p>
  </div>
  <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php">← Back to Map</a>
</div>

<div class="tc-card p-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="fw-bold mb-0 fs-6">Optimization Modes</h4>
    <form method="get" class="d-flex align-items-center gap-2">
      <label class="text-muted-custom small mb-0">Preset:</label>
      <select class="tc-form-select" name="preset" onchange="this.form.submit()" style="max-width: 380px;">
        <?php foreach ($presets as $p): ?>
          <option value="<?= e($p['id']) ?>" <?= $p['id'] === $selected_preset ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <div class="tc-table-container border-0">
    <table class="tc-table">
      <thead>
        <tr>
          <th>Optimization Mode</th>
          <th>Distance</th>
          <th>Travel Time</th>
          <th>Fuel Burn</th>
          <th>Toll Estimate</th>
          <th>Total Cost</th>
          <th>AI Score</th>
          <th class="text-end">Select</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($alternatives)): ?>
          <tr><td colspan="8" class="text-center text-muted-custom py-4">No route alternatives configured for this preset.</td></tr>
        <?php else: foreach ($alternatives as $i => $a): $recommended = $a['mode_key'] === 'balanced'; ?>
          <tr class="<?= $recommended ? 'table-primary' : '' ?>">
            <td>
              <strong class="<?= $recommended ? 'text-primary-custom' : '' ?>">
                <i class="bi <?= $mode_icons[$a['mode_key']] ?? 'bi-signpost-2' ?> <?= $mode_colors[$a['mode_key']] ?? '' ?> me-1"></i>
                <?= $recommended ? '<i class="bi bi-stars me-1"></i>' : '' ?><?= e($mode_labels[$a['mode_key']] ?? $a['title']) ?>
              </strong>
            </td>
            <td><?= e($a['distance']) ?></td>
            <td><?= e($a['duration']) ?></td>
            <td><?= e($a['fuel_estimate']) ?></td>
            <td><?= e($a['toll_estimate']) ?></td>
            <td><strong><?= e($a['total_trip_cost']) ?></strong></td>
            <td><span class="badge <?= str_starts_with($a['route_score'], '9') ? 'bg-success' : (str_starts_with($a['route_score'], '8') ? 'bg-secondary' : 'bg-warning text-dark') ?>"><?= e($a['route_score']) ?></span></td>
            <td class="text-end">
              <a class="tc-btn <?= $recommended ? 'tc-btn-primary' : 'tc-btn-light' ?> tc-btn-sm"
                 href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php?preset=<?= e($selected_preset) ?>&mode=<?= e($a['mode_key']) ?>">
                <?= $recommended ? 'Active' : 'Apply' ?>
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
