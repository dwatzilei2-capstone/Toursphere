<?php
 


require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/routethink_engine.php';
require_login();
if (!has_role('fleet_admin')) {
    redirect_with_toast(BASE_URL . '/' . home_path(), 'AI Model Training is available to administrators only.', 'warning');
}

$isEmbeddedInSettings = defined('TOURSPHERE_EMBED_AI_MODEL_TRAINING') && TOURSPHERE_EMBED_AI_MODEL_TRAINING === true;
if (!$isEmbeddedInSettings) {
    redirect_to(BASE_URL . '/settings.php?tab=ai-model-training');
}

$pdo = db();

 
$models = $pdo->query('SELECT * FROM ai_model_registry ORDER BY id DESC')->fetchAll();

$completedTripsCount = (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE status = 'Completed'")->fetchColumn();
$fuelLogsCount = (int)$pdo->query("SELECT COUNT(*) FROM fuel_transactions")->fetchColumn();
$routeLogsCount = (int)$pdo->query("SELECT COUNT(*) FROM route_history")->fetchColumn();
$fuelSamples = (int)$pdo->query("SELECT COUNT(*) FROM route_history WHERE pre_trip_features_json IS NOT NULL AND pre_trip_features_json <> '' AND pre_trip_features_json <> 'null' AND actual_fuel_liters > 0 AND actual_fuel_verified = 1")->fetchColumn();
$durationSamples = (int)$pdo->query("SELECT COUNT(*) FROM route_history WHERE pre_trip_features_json IS NOT NULL AND pre_trip_features_json <> '' AND pre_trip_features_json <> 'null' AND actual_duration_mins > 0")->fetchColumn();

$deployedFuel = $pdo->query("SELECT * FROM ai_model_registry WHERE target_variable = 'fuel_liters' AND status = 'Deployed' ORDER BY deployed_at DESC LIMIT 1")->fetch() ?: null;
$deployedDuration = $pdo->query("SELECT * FROM ai_model_registry WHERE target_variable = 'duration_mins' AND status = 'Deployed' ORDER BY deployed_at DESC LIMIT 1")->fetch() ?: null;

 
$usableSamples = max($fuelSamples, $durationSamples);
$lifecycleState = 'ColdStart';
if ($usableSamples >= 100) $lifecycleState = 'Validated';
elseif ($usableSamples >= 30) $lifecycleState = 'Experimental';

?>

<div class="settings-section-title">
  <h2>AI Model Training</h2>
  <p>Train, validate, deploy, and review RouteThink prediction models.</p>
</div>

<div class="ai-training-toolbar">
  <div class="d-flex flex-wrap align-items-center gap-2">
    <span class="badge bg-primary-subtle text-primary border">Module 6 ML Pipeline</span>
    <span class="badge bg-dark text-white"><i class="bi bi-cpu me-1"></i>v1.4 Engine</span>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/ai-route-optimization/ai-route-planner.php"><i class="bi bi-map"></i> Route Planner Map</a>
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/ai-route-optimization/route-history.php"><i class="bi bi-clock-history"></i> Route History</a>
  </div>
</div>

 
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="tc-card p-3 h-100">
      <div class="text-muted-custom small text-uppercase fw-semibold">Model Lifecycle State</div>
      <div class="d-flex align-items-center gap-2 mt-2">
        <span class="badge <?= $lifecycleState === 'Validated' ? 'bg-success' : ($lifecycleState === 'Experimental' ? 'bg-primary' : 'bg-warning text-dark') ?> fs-6 py-2 px-3">
          <i class="bi <?= $lifecycleState === 'Validated' ? 'bi-check-circle-fill' : 'bi-gear-wide-connected' ?> me-1"></i>
          <?= e($lifecycleState) ?>
        </span>
      </div>
      <div class="text-muted-custom small mt-2" style="font-size: 11px;">
        <?= $lifecycleState === 'Validated' ? 'Model verified on validation split (R² ≥ 0.70).' : ($lifecycleState === 'Experimental' ? 'Experimental training is available with cross-validation.' : (30 - $usableSamples) . ' more paired ground-truth sample(s) required for training.') ?>
      </div>
    </div>
  </div>

  <div class="col-md-3">
    <div class="tc-card p-3 h-100">
      <div class="text-muted-custom small text-uppercase fw-semibold">Operational Dataset Size</div>
      <div class="fw-bold fs-4 text-dark mt-1"><?= number_format($usableSamples) ?> <span class="fs-6 text-muted-custom fw-normal">paired samples</span></div>
      <div class="text-muted-custom small mt-1" style="font-size: 11px;">
        <i class="bi bi-database me-1 text-primary"></i><?= $fuelSamples ?> fuel target(s) &bull; <?= $durationSamples ?> duration target(s)
      </div>
    </div>
  </div>

  <div class="col-md-3">
    <div class="tc-card p-3 h-100">
      <div class="text-muted-custom small text-uppercase fw-semibold">Deployed Fuel Model</div>
      <?php if ($deployedFuel): ?>
        <div class="fw-bold fs-5 text-success mt-1"><?= e($deployedFuel['version']) ?></div>
        <div class="text-muted-custom small" style="font-size: 11px;">
          R² = <?= e($deployedFuel['r2_score']) ?> &bull; MAE = <?= e($deployedFuel['mae']) ?> L
        </div>
      <?php else: ?>
        <div class="fw-bold fs-6 text-primary mt-1">v0-kinematic (Physics)</div>
        <div class="text-muted-custom small" style="font-size: 11px;">Calibrated Kinematic Baseline Active</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-md-3">
    <div class="tc-card p-3 h-100">
      <div class="text-muted-custom small text-uppercase fw-semibold">Validation Method</div>
      <div class="fw-bold fs-6 text-dark mt-1"><i class="bi bi-diagram-2 me-1 text-primary"></i>5-Fold Cross Validation</div>
      <div class="text-muted-custom small mt-1" style="font-size: 11px;">
        L2 Ridge Regularization (α = 1.0)
      </div>
    </div>
  </div>
</div>

 
<div class="row g-4 mb-4">
   
  <div class="col-lg-6">
    <div class="tc-card h-100 d-flex flex-column justify-content-between">
      <div>
        <div class="tc-card-header d-flex justify-content-between align-items-center">
          <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-play-circle me-2 text-primary-custom"></i>Supervised Model Training Pipeline</h4>
          <span class="badge bg-light text-dark border" style="font-size: 10px;">Scikit / NumPy Backend</span>
        </div>
        <div class="p-3">
          <p class="text-muted-custom small mb-3">
            Initiate training on historical completed trip telemetry and fuel transactions. The pipeline extracts pre-trip features, performs Z-score scaling, and evaluates generalization via K-Fold Cross Validation.
          </p>

          <div class="mb-3">
            <label class="tc-form-label">Target Prediction Objective</label>
            <select class="tc-form-select" id="train-target-select">
              <option value="actual_fuel_liters" selected>Fuel Consumption (actual_fuel_liters) — Supervised Ridge Regressor</option>
              <option value="actual_duration_mins">Transit Duration (actual_duration_mins) — Congestion-Adjusted Model</option>
            </select>
          </div>

          <div class="mb-3 p-3 border rounded bg-light">
            <div class="small fw-semibold text-dark mb-1"><i class="bi bi-shield-check text-success me-1"></i>Data Leakage Prevention Protocol</div>
            <div class="text-muted-custom" style="font-size: 11px; line-height: 1.4;">
              Pre-trip features (distance, baseline speed, vehicle curb weight, passenger load ratio) are strictly isolated from post-trip ground truth labels (actual fuel burned and transit duration).
            </div>
          </div>

          <div id="training-console" class="p-3 border rounded bg-dark text-light font-monospace small mb-2 d-none" style="font-size: 11px; max-height: 160px; overflow-y: auto;">
             
          </div>
        </div>
      </div>

      <div class="p-3 border-top bg-light">
        <button id="btn-trigger-train" class="tc-btn tc-btn-primary w-100 py-2 fw-semibold" onclick="triggerModelTraining()" <?= $usableSamples < 30 ? 'disabled' : '' ?>>
          <i class="bi bi-cpu me-1"></i> <?= $usableSamples < 30 ? 'Need 30 Paired Samples to Train' : 'Train RouteThink Model' ?>
        </button>
      </div>
    </div>
  </div>

   
  <div class="col-lg-6">
    <div class="tc-card h-100">
      <div class="tc-card-header d-flex justify-content-between align-items-center">
        <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-list-check me-2 text-primary-custom"></i>Pre-Trip Feature Store & Model Weights</h4>
        <span class="badge bg-success-subtle text-success" style="font-size: 10px;">8 Features</span>
      </div>
      <div class="p-3">
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle mb-0" style="font-size: 12px;">
            <thead class="table-light">
              <tr>
                <th>Feature Name</th>
                <th>Type</th>
                <th>Source</th>
                <th>Description</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><code>distance_km</code></td>
                <td><span class="badge bg-light text-dark border">Float</span></td>
                <td>Google Maps API</td>
                <td>Total road network distance (km)</td>
              </tr>
              <tr>
                <td><code>base_duration_mins</code></td>
                <td><span class="badge bg-light text-dark border">Float</span></td>
                <td>Google Maps API</td>
                <td>Free-flow travel time without traffic</td>
              </tr>
              <tr>
                <td><code>traffic_delay_ratio</code></td>
                <td><span class="badge bg-light text-dark border">Float</span></td>
                <td>Live Traffic</td>
                <td>T_traffic / T_base delay coefficient</td>
              </tr>
              <tr>
                <td><code>waypoint_count</code></td>
                <td><span class="badge bg-light text-dark border">Integer</span></td>
                <td>Dispatch Request</td>
                <td>Number of stopovers and waypoints</td>
              </tr>
              <tr>
                <td><code>baseline_km_per_liter</code></td>
                <td><span class="badge bg-light text-dark border">Float</span></td>
                <td>Vehicle Catalog</td>
                <td>Manufacturer baseline fuel economy</td>
              </tr>
              <tr>
                <td><code>vehicle_weight_class</code></td>
                <td><span class="badge bg-light text-dark border">Categorical</span></td>
                <td>Vehicle Catalog</td>
                <td>Weight tier (Bus=3, Coaster=2, Van=1)</td>
              </tr>
              <tr>
                <td><code>passenger_load_ratio</code></td>
                <td><span class="badge bg-light text-dark border">Float</span></td>
                <td>Reservation Data</td>
                <td>Passenger count / vehicle capacity</td>
              </tr>
              <tr>
                <td><code>highway_ratio</code></td>
                <td><span class="badge bg-light text-dark border">Float</span></td>
                <td>Route Polyline</td>
                <td>Fraction of route on expressways</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

 
<div class="tc-card">
  <div class="tc-card-header d-flex justify-content-between align-items-center">
    <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-journal-code me-2 text-primary-custom"></i>AI Model Registry & Version History</h4>
    <span class="badge bg-light text-dark border" style="font-size: 10px;"><?= count($models) ?> Registered Version(s)</span>
  </div>
  <div class="tc-table-container border-0">
    <table class="tc-table align-middle">
      <thead>
        <tr>
          <th>Model ID</th>
          <th>Version</th>
          <th>Algorithm</th>
          <th>Target</th>
          <th>Samples</th>
          <th>Val. MAE</th>
          <th>Val. RMSE</th>
          <th>Val. R² Score</th>
          <th>Status</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody id="model-registry-tbody">
        <?php if (empty($models)): ?>
          <tr>
            <td colspan="10" class="text-center text-muted-custom py-4">
              <i class="bi bi-info-circle me-1"></i> No trained models recorded yet. Click <strong>Train RouteThink Model</strong> above to fit the initial model.
            </td>
          </tr>
        <?php else: foreach ($models as $m): ?>
          <tr class="<?= $m['status'] === 'Deployed' ? 'table-primary' : '' ?>">
            <td><strong><?= e($m['model_id']) ?></strong></td>
            <td><span class="badge bg-light text-dark border"><?= e($m['version']) ?></span></td>
            <td><?= e($m['algorithm']) ?></td>
            <td><span class="badge bg-secondary-subtle text-dark"><?= e($m['target_variable']) ?></span></td>
            <td><?= number_format($m['training_samples']) ?></td>
            <td><?= number_format((float)$m['mae'], 3) ?> <?= $m['target_variable'] === 'fuel_liters' ? 'L' : 'min' ?></td>
            <td><?= number_format((float)$m['rmse'], 3) ?></td>
            <td>
              <strong class="<?= (float)$m['r2_score'] >= 0.70 ? 'text-success' : 'text-primary' ?>">
                <?= number_format((float)$m['r2_score'], 4) ?>
              </strong>
            </td>
            <td>
              <span class="badge <?= $m['status'] === 'Deployed' ? 'bg-success' : ($m['status'] === 'Validated' ? 'bg-primary' : 'bg-warning text-dark') ?>">
                <?= e($m['status']) ?>
              </span>
            </td>
            <td class="text-end">
              <?php if ($m['status'] === 'Validated'): ?>
                <button type="button" class="btn btn-outline-primary btn-sm py-1 px-2" style="font-size: 11px;" onclick="deployModel('<?= e($m['model_id']) ?>')">
                  <i class="bi bi-cloud-arrow-up me-1"></i> Deploy
                </button>
              <?php elseif ($m['status'] === 'Deployed'): ?>
                <span class="badge bg-success-subtle text-success border"><i class="bi bi-check2 me-1"></i>Active</span>
              <?php else: ?>
                <span class="text-muted-custom small">Not deployable</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function triggerModelTraining() {
  const target = document.getElementById('train-target-select').value;
  const btn = document.getElementById('btn-trigger-train');
  const consoleEl = document.getElementById('training-console');

  btn.disabled = true;
  btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Training & Cross-Validating...`;
  consoleEl.classList.remove('d-none');
  consoleEl.innerHTML = `<div>[INFO] Ingesting historical fleet trip dataset...</div><div>[INFO] Performing Z-score feature standardization...</div><div>[INFO] Running 5-Fold Cross Validation with Ridge L2 Regularizer...</div>`;

  const form = new FormData();
  form.append('action', 'train');
  form.append('target', target);

  fetch('<?= BASE_URL ?>/actions/ai_train.php', { method: 'POST', body: form })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = `<i class="bi bi-cpu me-1"></i> Train RouteThink Model`;
      if (data.ok) {
        consoleEl.innerHTML += `<div class="text-success">[SUCCESS] Model ${data.model_id} (${data.version}) fitted successfully!</div>`;
        consoleEl.innerHTML += `<div>[METRICS] Validation MAE: ${data.mae} | RMSE: ${data.rmse} | R² Score: ${data.r2_score}</div>`;
        if (window.showAppToast) {
          window.showAppToast("Model Training Complete", data.message, "success");
        }
        setTimeout(() => location.reload(), 1200);
      } else {
        consoleEl.innerHTML += `<div class="text-danger">[ERROR] ${data.error}</div>`;
        if (window.showAppToast) {
          window.showAppToast("Training Failed", data.error || "Unable to train model.", "danger");
        }
      }
    })
    .catch(err => {
      btn.disabled = false;
      btn.innerHTML = `<i class="bi bi-cpu me-1"></i> Train RouteThink Model`;
      consoleEl.innerHTML += `<div class="text-danger">[ERROR] Training request failed: ${err.message}</div>`;
    });
}

function deployModel(modelId) {
  if (!confirm(`Deploy model ${modelId} as the active production prediction model for RouteThink?`)) return;

  const form = new FormData();
  form.append('action', 'deploy');
  form.append('model_id', modelId);

  fetch('<?= BASE_URL ?>/actions/ai_train.php', { method: 'POST', body: form })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        if (window.showAppToast) {
          window.showAppToast("Model Deployed", data.message, "success");
        }
        setTimeout(() => location.reload(), 1000);
      } else {
        if (window.showAppToast) {
          window.showAppToast("Deploy Failed", data.error, "danger");
        }
      }
    });
}
</script>
