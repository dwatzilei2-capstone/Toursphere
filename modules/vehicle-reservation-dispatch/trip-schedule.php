<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('dispatch.view');
require_once ROOT_PATH.'/includes/trip_schedule.php';
$_SESSION['trip_schedule_csrf'] ??= bin2hex(random_bytes(32));

$pdo = db();

 
 
$requested_week = trim($_GET['week'] ?? '');
if ($requested_week !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested_week)) {
    $requested_week = '';
}
$anchor_date = $requested_week;
if ($anchor_date === '') {
    $anchor_date = (string)($pdo->query(
        "SELECT COALESCE(MIN(departure_date) FILTER (WHERE departure_date >= CURRENT_DATE),
                         MAX(departure_date), CURRENT_DATE)
           FROM reservations"
    )->fetchColumn() ?: date('Y-m-d'));
}
$anchor = new DateTimeImmutable($anchor_date);
$week_start = $anchor->modify('monday this week');
$week_end = $week_start->modify('+6 days');
$columns = [];
for ($day = $week_start; $day <= $week_end; $day = $day->modify('+1 day')) {
    $columns[$day->format('Y-m-d')] = $day->format('D, M j');
}

$reservationStmt = $pdo->prepare(
    "SELECT r.*, v.plate_number, v.brand, v.type AS vehicle_type, v.capacity, d.name AS driver_name,
            t.id AS linked_trip_id,t.status AS linked_trip_status,t.current_step AS linked_trip_step
       FROM reservations r
       LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
       LEFT JOIN drivers d  ON d.id = r.assigned_driver_id
       LEFT JOIN LATERAL (SELECT id,status,current_step FROM trips WHERE reservation_id=r.id ORDER BY created_at DESC,id DESC LIMIT 1) t ON TRUE
      WHERE r.departure_date BETWEEN ? AND ?
        AND ((r.request_kind='Cargo' AND r.status IN ('Approved','Assigned','Confirmed','Ready for Dispatch','Dispatched','In Transit','Returning to Depot','Completed'))
          OR (r.request_kind<>'Cargo' AND r.status NOT IN ('Completed','Cancelled')))
      ORDER BY r.departure_date, r.departure_time"
);
$reservationStmt->execute([$week_start->format('Y-m-d'), $week_end->format('Y-m-d')]);
$reservations = $reservationStmt->fetchAll();

 
$grid = [];
$slots = [];
foreach ($reservations as $r) {
    $slot = date('h:i A', strtotime($r['departure_time']));
    $grid[$r['departure_date']][$slot][] = $r;
    $slots[$slot] = strtotime($r['departure_time']);
}
asort($slots);
$slots = array_keys($slots);

// Presentation helpers only; stored schedule and cargo values are unchanged.
function schedule_measurement($value): string {
    return rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.') ?: '0';
}
function schedule_display_time($date, $time): string {
    if (!$date || !$time) return 'Not set';
    try { return (new DateTimeImmutable($date.' '.$time))->format('M j · g:i A'); }
    catch (Exception $e) { return 'Not set'; }
}
$active_page = 'trip-schedule';
$body_class = trim(($body_class ?? '') . ' reservation-dispatch-module trip-schedule-page');
$page_title  = 'Trip Schedule & Dispatch Calendar';
require ROOT_PATH . '/includes/header.php';
?>

<div class="reservation-dispatch-page-shell">
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Trip Schedule & Dispatch Calendar</h1>
    <p class="text-muted-custom mb-0">Cargo pickup and estimated delivery schedules with assigned vehicles and drivers.</p>
  </div>
  <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/vehicle-reservation-dispatch/reservations.php">← Reservations</a>
</div>

<div class="tc-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0"><?= e($week_start->format('M j')) ?>–<?= e($week_end->format('M j, Y')) ?> Fleet Timetable</h4>
    <div class="btn-group" aria-label="Calendar week navigation">
      <a class="btn btn-sm btn-light border" href="?week=<?= e($week_start->modify('-7 days')->format('Y-m-d')) ?>">← Previous</a>
      <a class="btn btn-sm btn-primary" href="?week=<?= e(date('Y-m-d')) ?>">Today</a>
      <a class="btn btn-sm btn-light border" href="?week=<?= e($week_start->modify('+7 days')->format('Y-m-d')) ?>">Next →</a>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-bordered small schedule-timetable">
      <thead class="table-light">
        <tr>
          <th style="width: 120px;">Time Slot</th>
          <?php foreach ($columns as $date => $label): ?>
            <th><?= e($label) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$slots): ?>
          <tr><td colspan="<?= count($columns) + 1 ?>" class="text-center text-muted-custom py-4">No reservations scheduled for this week.</td></tr>
        <?php else: foreach ($slots as $slot): ?>
          <tr>
            <td class="fw-semibold bg-light"><?= e($slot) ?></td>
            <?php foreach ($columns as $date => $label): ?>
              <td class="p-2">
                <?php if (!empty($grid[$date][$slot])): foreach ($grid[$date][$slot] as $r):
                    $short_id = preg_replace('/^RES-2026-/', 'RES-', $r['id']); ?>
                  <div class="bg-primary-subtle p-2 rounded mb-1 schedule-entry">
                    <strong class="d-block text-primary schedule-one-line"><?= e($short_id) ?></strong>
                    <?php if (($r['request_kind']??'Passenger')==='Cargo'):
                      $trip=$r['linked_trip_id']?['status'=>$r['linked_trip_status'],'current_step'=>$r['linked_trip_step']]:null;
                      $ready=false;if($r['linked_trip_id']&&in_array($r['status'],['Assigned','Confirmed','Ready for Dispatch'],true))$ready=funding_readiness($pdo,funding_trip($pdo,$r['linked_trip_id']))['ready'];
                    ?>
                    <div class="schedule-times">
                      <div class="schedule-one-line" title="Scheduled pickup: <?= e(schedule_display_time($r['departure_date'],$r['departure_time'])) ?>"><span class="text-muted-custom">Pickup</span> <strong><?= e(schedule_display_time($r['departure_date'],$r['departure_time'])) ?></strong></div>
                      <div class="schedule-one-line" title="Estimated delivery: <?= e(schedule_display_time($r['return_date'],$r['return_time'])) ?>"><span class="text-muted-custom">Delivery</span> <strong><?= e(schedule_display_time($r['return_date'],$r['return_time'])) ?></strong></div>
                    </div>
                    <div class="schedule-one-line fw-semibold" title="<?= e($r['origin']) ?>"><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= e(explode(',', $r['origin'])[0]) ?></div>
                    <div class="schedule-one-line fw-semibold" title="<?= e($r['destination']) ?>"><i class="bi bi-arrow-right" aria-hidden="true"></i> <?= e(explode(',', $r['destination'])[0]) ?></div>
                    <div class="schedule-resource-row">
                      <span class="schedule-one-line text-muted-custom" title="<?= e($r['plate_number']?:'Vehicle not assigned') ?>"><?= e($r['plate_number']?:'Vehicle not assigned') ?></span>
                      <span class="schedule-one-line text-muted-custom" title="<?= e($r['driver_name']?:'Driver not assigned') ?>"><?= e($r['driver_name']?:'Driver not assigned') ?></span>
                    </div>
                    <div class="schedule-one-line text-primary fw-semibold" title="<?= e(dispatch_board_stage($r,$trip,$ready)) ?>"><?= e(dispatch_board_stage($r,$trip,$ready)) ?></div>
                    <div class="schedule-cargo-summary text-muted-custom">
                      <div class="schedule-cargo-line"><span class="schedule-one-line" title="<?= e($r['cargo_type']) ?>"><?= e($r['cargo_type']) ?></span><span>· <?= (int)$r['cargo_quantity'] ?> units</span></div>
                      <div class="schedule-one-line"><?= schedule_measurement($r['cargo_weight_kg']) ?> kg · <?= schedule_measurement($r['cargo_volume_m3']) ?> m³</div>
                    </div>
                    <?php else: ?>
                    <span class="text-muted-custom schedule-two-lines">
                      <?= ($r['request_kind']??'Passenger')==='Cargo' ? e($r['cargo_weight_kg']).' kg · '.e($r['cargo_volume_m3']).' m³' : ((int)$r['passenger_count'] > 0 ? (int)$r['passenger_count'] . ' pax' : 'Passenger count unavailable') ?>
                      <br>
                      <?php if (!empty($r['assigned_vehicle_id'])): ?>
                        <?= e(trim(($r['brand'] ?? '') . ' ' . ($r['vehicle_type'] ?? '')) ?: $r['assigned_vehicle_id']) ?>
                        <?php if ((int)($r['capacity'] ?? 0) > 0): ?> (Capacity: <?= (int)$r['capacity'] ?>)<?php endif; ?>
                      <?php else: ?>Vehicle not assigned<?php endif; ?>
                      • <?= !empty($r['driver_name']) ? e(driver_short_name($r['driver_name'])) : 'Driver not assigned' ?>
                    </span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; else: ?>
                  <span class="text-muted-custom">-</span>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<script src="<?= BASE_URL ?>/js/reservation-dispatch.js?v=<?= (int)filemtime(ROOT_PATH . '/js/reservation-dispatch.js') ?>"></script>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
