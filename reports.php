<?php
 
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('reports.view');

$active_page = 'reports';
$page_title  = 'Fleet Operational Reports Generator';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Fleet Operational Reports Generator</h1>
    <p class="text-muted-custom mb-0">Generate corporate reports for Fleet Utilization, Fuel Consumption, Driver Punctuality, and Cost Accounting.</p>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-6 col-lg-3">
    <div class="tc-card p-3 text-center h-100">
      <i class="bi bi-file-earmark-pdf fs-1 text-danger mb-2 d-block"></i>
      <h5 class="fw-bold mb-1">Monthly Fleet Summary</h5>
      <p class="text-muted-custom small mb-3">Vehicle availability, mileage, and maintenance logs for August 2026.</p>
      <a class="tc-btn tc-btn-secondary tc-btn-sm w-100" href="<?= BASE_URL ?>/actions/export.php?report=fleet"><i class="bi bi-download me-1"></i>Generate CSV</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-3">
    <div class="tc-card p-3 text-center h-100">
      <i class="bi bi-file-earmark-excel fs-1 text-success mb-2 d-block"></i>
      <h5 class="fw-bold mb-1">Fuel & Cost Accounting</h5>
      <p class="text-muted-custom small mb-3">Detailed expense ledger by vehicle, driver allowance, and station receipts.</p>
      <a class="tc-btn tc-btn-secondary tc-btn-sm w-100" href="<?= BASE_URL ?>/actions/export.php?report=fuel"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-3">
    <div class="tc-card p-3 text-center h-100">
      <i class="bi bi-speedometer fs-1 text-primary mb-2 d-block"></i>
      <h5 class="fw-bold mb-1">Driver Performance Scorecard</h5>
      <p class="text-muted-custom small mb-3">Safety scores, completed trips, and HRMS employee rating breakdown.</p>
      <a class="tc-btn tc-btn-secondary tc-btn-sm w-100" href="<?= BASE_URL ?>/actions/export.php?report=drivers"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
  </div>

  <div class="col-md-6 col-lg-3">
    <div class="tc-card p-3 text-center h-100">
      <i class="bi bi-cpu fs-1 text-info mb-2 d-block"></i>
      <h5 class="fw-bold mb-1">AI Route Optimization Audit</h5>
      <p class="text-muted-custom small mb-3">Comparison analysis on route efficiencies and diesel carbon reductions.</p>
      <a class="tc-btn tc-btn-secondary tc-btn-sm w-100" href="<?= BASE_URL ?>/actions/export.php?report=route"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
  </div>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
