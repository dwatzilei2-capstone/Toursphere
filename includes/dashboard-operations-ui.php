<?php if(can('dispatch.view')&&!has_role(['customer','driver'])):
$_SESSION['dashboard_operations_csrf']??=bin2hex(random_bytes(32));
$calendarVisible=fleet_setting('user.'.$current_user['id'].'.dashboard_calendar','1')!=='0';
$samplePreview=($_GET['sample']??'')==='1';
if($samplePreview)$calendarVisible=true;
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/dashboard-operations.css?v=<?= filemtime(ROOT_PATH.'/css/dashboard-operations.css') ?>">
<div id="dashboard-operations" data-source="<?= $samplePreview?'sample':'actual' ?>" data-endpoint="<?= e(BASE_URL.'/actions/dashboard-operations.php') ?>" data-csrf="<?= e($_SESSION['dashboard_operations_csrf']) ?>" data-today="<?= date('Y-m-d') ?>">
  <div class="do-source">
    <span><?= $samplePreview?'Sample preview · 20 calendar examples plus an expanded fictional trend dataset. Your real records and KPI totals are unchanged.':'Calendar and trend use actual operational records.' ?></span>
    <a href="<?= BASE_URL ?>/dashboard.php<?= $samplePreview?'':'?sample=1' ?>"><?= $samplePreview?'Show actual data':'Preview sample data' ?></a>
  </div>
  <div class="do-visibility"><span id="do-preference-status" role="status"></span><label class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="do-show-calendar" aria-controls="do-calendar" <?= $calendarVisible?'checked':'' ?>> Show Calendar</label></div>
  <section id="do-calendar" class="tc-card do-card" aria-labelledby="do-calendar-title" <?= $calendarVisible?'':'hidden' ?>>
    <div class="do-header">
      <div><h2 id="do-calendar-title"><i class="bi bi-calendar-event"></i> Delivery &amp; Fleet Schedule</h2><p>Scheduled deliveries, assigned vehicles, and trip status.</p></div>
      <div class="do-controls">
        <button type="button" data-calendar-nav="-1" aria-label="Previous calendar period">‹</button><strong id="do-calendar-date"></strong><button type="button" data-calendar-nav="1" aria-label="Next calendar period">›</button>
        <button type="button" id="do-today">Today</button>
        <div class="do-segments" aria-label="Calendar view"><?php foreach(['month','week','day'] as $view): ?><button type="button" data-calendar-view="<?= $view ?>" aria-pressed="<?= $view==='month'?'true':'false' ?>"><?= ucfirst($view) ?></button><?php endforeach; ?></div>
      </div>
    </div>
    <div id="do-calendar-status" class="do-status" role="status"></div>
    <div id="do-calendar-grid"></div>
  </section>
  <section class="tc-card do-card" aria-labelledby="do-trend-title">
    <div class="do-header">
      <div><h2 id="do-trend-title"><i class="bi bi-bar-chart-fill"></i> Delivery Operations Trend</h2><p><?= $samplePreview?'Fictional sample deliveries — not actual performance':'Actual cargo delivery records' ?> · <?= e(company_timezone()) ?></p></div>
      <div class="do-controls">
        <div class="do-segments" aria-label="Analytics period"><?php foreach(['day','month','year'] as $view): ?><button type="button" data-trend-view="<?= $view ?>" aria-pressed="<?= $view==='month'?'true':'false' ?>"><?= ucfirst($view) ?></button><?php endforeach; ?></div>
        <label for="do-trend-date" class="visually-hidden">Analytics date</label><input id="do-trend-date" type="month" value="<?= date('Y-m') ?>" min="1900-01" max="2100-12">
        <button type="button" id="do-trend-retry" aria-label="Reload analytics">↻</button>
      </div>
    </div>
    <div id="do-trend-status" class="do-status" role="status"></div>
    <div class="do-chart"><canvas id="do-trend-chart" role="img" aria-label="Delivery operations trend"></canvas></div>
    <details class="do-data"><summary>View chart data and counting rules</summary>
      <?php if($samplePreview): ?><p>The graph uses an expanded fictional dataset with busy and quiet days to demonstrate peaks and dips across Day, Month and Year views. It is separate from the 20 calendar examples. Scheduled includes non-cancelled pickups; on-time is a subset of completed deliveries. Sample arrival times are illustrative, not verified delivery evidence.</p>
      <?php else: ?><p>Each cargo reservation is counted once per metric using its latest linked trip. Scheduled counts approved, non-cancelled pickups by scheduled date. Completed counts verified deliveries by completion date. On-time is a subset of completed deliveries using the existing finalized warehouse assessment or verified arrival timing against the promised delivery time. Cancelled uses recorded cancellation dates; missing dates are excluded. Development tests and simulations are excluded.</p><?php endif; ?>
      <div class="do-table-wrap"><table><thead><tr><th>Period</th><th>Completed</th><th>On time</th><th>Scheduled</th><th>Cancelled</th></tr></thead><tbody id="do-trend-table"></tbody></table></div>
    </details>
  </section>
  <dialog id="do-event-dialog" aria-labelledby="do-event-title"><div class="do-dialog-header"><h2 id="do-event-title">Schedule details</h2><button type="button" id="do-event-close" aria-label="Close schedule details">×</button></div><p class="text-muted-custom"><?= $samplePreview?'Fictional preview only · No operational record was created.':'Read-only · Manage this record in Reservation & Dispatch.' ?></p><dl id="do-event-details"></dl></dialog>
</div>
<script src="<?= BASE_URL ?>/js/dashboard-operations.js?v=<?= filemtime(ROOT_PATH.'/js/dashboard-operations.js') ?>" defer></script>
<?php endif; ?>
