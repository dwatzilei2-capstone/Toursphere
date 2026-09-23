<?php
 





$include_chart = $include_chart ?? false;
$include_leaflet = $include_leaflet ?? false;
$include_map_route = $include_map_route ?? false;
$chart_data = $chart_data ?? null;
$route_data = $route_data ?? null;
$page_scripts = $page_scripts ?? '';
?>
      </main>
    </div>
  </div>

   
   
   

   
  <div id="modal-account-profile" class="tc-modal-backdrop">
    <div class="tc-modal">
      <div class="tc-card-header">
        <h4 class="mb-0 fw-bold fs-6"><i class="bi bi-person-circle me-2 text-primary-custom"></i>Account Profile</h4>
        <button type="button" class="btn-close" onclick="App.closeModal('modal-account-profile')"></button>
      </div>
      <div class="tc-card-body">
        <div class="d-flex flex-column align-items-center text-center mb-4">
          <img id="account-profile-avatar" data-current-user-avatar src="<?= e($current_user['avatar'] ?? '') ?>" alt="Avatar" class="rounded-circle mb-3" style="width: 84px; height: 84px; object-fit: cover;" onerror="this.src='<?= BASE_URL ?>/assets/images/toursphere-logo.png';">
          <h5 class="fw-bold mb-1"><?= e($current_user['name'] ?? '—') ?></h5>
          <span class="badge bg-primary-subtle text-primary border"><?= e($current_user['role_name'] ?? '—') ?></span>
          <?php if (($current_user['role_code'] ?? '') === 'driver'): ?>
            <input type="file" id="driver-avatar-input" class="d-none" accept="image/jpeg,image/png,image/webp" onchange="App.uploadDriverAvatar(this)">
            <button type="button" id="driver-avatar-button" class="tc-btn tc-btn-secondary tc-btn-sm mt-3" onclick="document.getElementById('driver-avatar-input').click()">
              <i class="bi bi-camera"></i> Change Profile Photo
            </button>
            <div id="driver-avatar-progress-wrap" class="w-100 mt-3 d-none" style="max-width: 260px;">
              <div class="d-flex justify-content-between small mb-1"><span id="driver-avatar-progress-label">Uploading profile photo...</span><span id="driver-avatar-progress-value">0%</span></div>
              <div class="progress" style="height: 5px;"><div id="driver-avatar-progress" class="progress-bar" style="width: 0%;"></div></div>
            </div>
            <div class="text-muted-custom mt-2" style="font-size: 11px;">JPG, PNG or WebP · Maximum 5 MB</div>
          <?php endif; ?>
        </div>
        <div class="small">
          <div class="row mb-2">
            <div class="col-4 text-muted-custom">Employee ID</div>
            <div class="col-8 fw-semibold"><?= e($current_user['emp_id'] ?? '—') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-4 text-muted-custom">Department</div>
            <div class="col-8 fw-semibold"><?= e($current_user['department'] ?? '—') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-4 text-muted-custom">Email</div>
            <div class="col-8 fw-semibold text-break"><?= e($current_user['email'] ?? '—') ?></div>
          </div>
        </div>
      </div>
      <div class="tc-card-footer text-end">
        <div class="text-start small mb-3" style="overflow-wrap: anywhere;">
          <strong><?= e(company_name()) ?></strong>
          <?php foreach (['company.email', 'company.phone', 'company.address'] as $contactKey): ?>
            <?php if (fleet_setting($contactKey) !== ''): ?><div class="text-muted-custom"><?= e(fleet_setting($contactKey)) ?></div><?php endif; ?>
          <?php endforeach; ?>
        </div>
        <button class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-account-profile')">Close</button>
      </div>
    </div>
  </div>

   
  <div id="toast-container" class="position-fixed bottom-0 end-0 p-3" style="z-index: 1100;"></div>

   
  <script src="<?= BASE_URL ?>/js/vendor/bootstrap.bundle.min.js"></script>
  <?php if ($include_chart): ?>
   
  <script src="<?= BASE_URL ?>/js/vendor/chart.umd.min.js"></script>
  <?php endif; ?>
  <?php if ($include_leaflet): ?>
   
  <script src="<?= BASE_URL ?>/js/vendor/leaflet.js"></script>
  <?php endif; ?>

  <?php if (!empty($chart_data)): ?>
  <script>window.TC_CHART_DATA = <?= json_encode($chart_data, JSON_UNESCAPED_UNICODE) ?>;</script>
  <?php endif; ?>
  <?php if (!empty($route_data)): ?>
  <script>window.TC_ROUTE_DATA = <?= json_encode($route_data, JSON_UNESCAPED_UNICODE) ?>;</script>
  <?php endif; ?>
  <script>window.TC_BASE_URL = <?= json_encode(BASE_URL) ?>;</script>

   
  <?php if (!empty($include_map_route) || !empty($include_leaflet)): ?>
  <script src="<?= BASE_URL ?>/js/map-route.js?v=<?= (int)@filemtime(ROOT_PATH . '/js/map-route.js') ?>"></script>
  <?php endif; ?>
  <script src="<?= BASE_URL ?>/js/app.js?v=<?= (int)@filemtime(ROOT_PATH . '/js/app.js') ?>"></script>
  <?php if (!empty($page_scripts)): ?>
  <?= $page_scripts   ?>
  <?php endif; ?>
</body>
</html>
