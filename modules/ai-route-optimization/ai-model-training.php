<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/routethink_engine.php';
require_login();
if (!has_role('fleet_admin')) redirect_with_toast(BASE_URL . '/' . home_path(), 'Administrators only.', 'warning');
if (!defined('TOURSPHERE_EMBED_AI_MODEL_TRAINING') || !TOURSPHERE_EMBED_AI_MODEL_TRAINING) redirect_to(BASE_URL . '/settings.php?tab=ai-model-training');
$_SESSION['learning_csrf'] ??= bin2hex(random_bytes(32));
$learningError = false;
try { ai_learning_sync(db()); $stats = ai_learning_stats(db()); }
catch (Throwable $e) { $stats = []; $learningError = true; }
$models = db()->query('SELECT model_id, version, target_variable, status, training_samples FROM ai_model_registry ORDER BY id DESC LIMIT 20')->fetchAll();
?>
<div class="settings-section-title"><h2>AI Model Training</h2><p>Continuously learning from validated completed trips. No minimum sample requirement.</p></div>
<div class="ai-training-toolbar">
<span class="badge bg-success">Continuous baseline calibration</span>
<a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php">Route Planner Map</a>
<a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/ai-route-optimization/route-history.php">Route History</a>
</div>
<?php if ($learningError): ?><div class="alert alert-warning">Learning is temporarily unavailable. Historical records are preserved and baseline route planning remains available.</div><?php endif; ?>
<div class="row g-3 mb-4">
<?php foreach (['duration_mins' => 'Travel Time Learning', 'fuel_liters' => 'Fuel Consumption Learning'] as $target => $label):
$rows = $stats[$target] ?? []; $count = array_sum(array_column($rows, 'samples'));
$lastUpdate = $rows ? max(array_column($rows, 'updated_at')) : 'Not yet available'; ?>
<div class="col-md-6"><div class="tc-card p-3 h-100"><h4 class="fs-6"><?= e($label) ?></h4>
<div class="fw-bold fs-4"><?= number_format($count) ?> <span class="fs-6 fw-normal">valid learned outcomes</span></div>
<p class="small text-muted-custom"><?= $count === 0 ? 'Baseline active — waiting for the first eligible trip.' : ($count < 30 ? 'Learning active — early history, baseline-heavy estimates.' : 'Learning active — growing historical coverage.') ?></p>
<p class="small text-muted-custom mb-0">Last update: <?= e($lastUpdate) ?></p></div></div>
<?php endforeach; ?>
</div>
<div class="tc-card p-3 mb-4"><h4 class="fs-6">Continuous Learning</h4>
<p class="small text-muted-custom">Completed trip → validate actual outcomes → preserve history → incrementally update statistics → apply learned estimates to future route recommendations.</p>
<p class="small text-muted-custom">Only completed, linked trips with valid vehicle/route features and actual departure/arrival are eligible. Fuel requires verified trip consumption, not ordinary refills. Invalid outcomes are skipped and repeated checks never double-count the same trip.</p>
<p class="small text-muted-custom">Historical influence increases within comparable vehicle-weight and traffic contexts: samples ÷ (samples + 30). Thirty samples is not a training lock or proof of prediction accuracy. Route distance remains a pre-trip feature; a final odometer alone does not establish actual trip distance.</p>
<button id="sync-learning" class="tc-btn tc-btn-primary" type="button">Check for new completed-trip data</button><p id="learning-feedback" class="small mt-2 mb-0" role="status"></p></div>
<div class="tc-card mb-4"><div class="tc-card-header"><h4 class="fs-6 mb-0">Learning Statistics by Context</h4></div>
<div class="table-responsive"><table class="table mb-0"><thead><tr><th>Target</th><th>Vehicle / Traffic Context</th><th>Outcomes</th><th>Historical Influence</th><th>Observed / Baseline Ratio</th></tr></thead><tbody>
<?php foreach ($stats as $target => $rows): foreach ($rows as $row): ?>
<tr><td><?= e($target) ?></td><td><?= e($row['context_key']) ?></td><td><?= (int)$row['samples'] ?></td><td><?= round(100 * $row['samples'] / ($row['samples'] + 30), 1) ?>%</td><td><?= number_format($row['ratio_sum'] / $row['samples'], 3) ?></td></tr>
<?php endforeach; endforeach; ?>
<?php if (!$stats): ?><tr><td colspan="5" class="text-muted-custom">No validated outcomes learned yet. The first eligible completed trip starts learning.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="tc-card"><div class="tc-card-header"><h4 class="fs-6 mb-0">Preserved Legacy Model Registry</h4></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Model</th><th>Version</th><th>Target</th><th>Samples</th><th>Status</th></tr></thead><tbody>
<?php foreach ($models as $model): ?><tr><td><?= e($model['model_id']) ?></td><td><?= e($model['version']) ?></td><td><?= e($model['target_variable']) ?></td><td><?= (int)$model['training_samples'] ?></td><td><?= e($model['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$models): ?><tr><td colspan="5" class="text-muted-custom">No legacy models. Continuous learning does not require manual deployment.</td></tr><?php endif; ?>
</tbody></table></div></div>
<script>
document.getElementById('sync-learning').addEventListener('click', async function () {
const feedback = document.getElementById('learning-feedback');
this.disabled = true; feedback.textContent = 'Checking validated trip outcomes…';
const body = new FormData(); body.append('action', 'train'); body.append('learning_csrf', <?= json_encode($_SESSION['learning_csrf']) ?>);
try {
const response = await fetch('<?= BASE_URL ?>/actions/ai_train.php', {method: 'POST', body});
const data = await response.json(); feedback.textContent = data.message || data.error;
if (data.ok) setTimeout(() => location.reload(), 1200);
} catch (error) { feedback.textContent = 'Update unavailable. Historical records are preserved.'; }
finally { this.disabled = false; }
});
</script>
