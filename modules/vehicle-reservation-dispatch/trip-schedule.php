<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();
require_permission('dispatch.view');

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
    "SELECT r.*, v.plate_number, v.brand, v.type AS vehicle_type, v.capacity, d.name AS driver_name
       FROM reservations r
       LEFT JOIN vehicles v ON v.id = r.assigned_vehicle_id
       LEFT JOIN drivers d  ON d.id = r.assigned_driver_id
      WHERE r.departure_date BETWEEN ? AND ?
        AND r.status NOT IN ('Completed', 'Cancelled')
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

$active_page = 'trip-schedule';
$page_title  = 'Trip Schedule & Dispatch Calendar';
require ROOT_PATH . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="mb-1">Trip Schedule & Dispatch Calendar</h1>
    <p class="text-muted-custom mb-0">Consolidated timetable of chartered tour departures and arrival milestones.</p>
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
    <table class="table table-bordered small">
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
                  <div class="bg-primary-subtle p-2 rounded mb-1">
                    <strong class="d-block text-primary"><?= e($short_id) ?>: <?= e(explode(',', $r['destination'])[0]) ?></strong>
                    <span class="text-muted-custom">
                      <?= e($r['brand'] ?? '') ?> <?= e($r['vehicle_type'] ?? '') ?> (<?= (int)$r['capacity'] ?> pax) • <?= e(driver_short_name($r['driver_name'] ?? '')) ?>
                    </span>
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

<?php require ROOT_PATH . '/includes/footer.php'; ?>
