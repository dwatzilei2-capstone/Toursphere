<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/customer_reservations.php';
require_login();
if (!has_role('customer')) { http_response_code(403); exit('Forbidden'); }
$pdo=db();
$stmt=$pdo->prepare("SELECT r.*, t.id AS trip_id, t.status AS trip_status, t.navigation_active, t.actual_departure, t.actual_arrival, t.progress_pct, d.name AS driver_name, v.type AS assigned_type, dr.stars, dr.feedback
 FROM reservations r
 LEFT JOIN LATERAL (SELECT * FROM trips WHERE reservation_id=r.id ORDER BY created_at DESC LIMIT 1) t ON TRUE
 LEFT JOIN drivers d ON d.id=t.driver_id LEFT JOIN vehicles v ON v.id=t.vehicle_id
 LEFT JOIN driver_ratings dr ON dr.trip_id=t.id
 WHERE r.customer_id=? ORDER BY r.created_at DESC");
$stmt->execute([$current_user['id']]); $reservations=$stmt->fetchAll();
$selectedId=trim($_GET['id'] ?? ''); $selected=null; $events=[];
foreach($reservations as $reservation) if($reservation['id']===$selectedId) $selected=$reservation;
if($selected){$eventStmt=$pdo->prepare('SELECT e.*, u.name AS actor_name FROM reservation_events e LEFT JOIN users u ON u.id=e.actor_id WHERE e.reservation_id=? ORDER BY e.created_at,e.id');$eventStmt->execute([$selectedId]);$events=$eventStmt->fetchAll();}
$active_page='customer-reservations';$page_title='My Reservations';require ROOT_PATH . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"><div><h1 class="mb-1">My Reservations</h1><p class="text-muted-custom mb-0">Follow approval, dispatch and trip progress in one place.</p></div><button type="button" class="tc-btn tc-btn-primary" onclick="openCustomerReservation()"><i class="bi bi-plus-lg me-1"></i>New Reservation</button></div>
<?php if($selected): ?>
<a class="small d-inline-block mb-3" href="<?= BASE_URL ?>/modules/customer-portal/reservations.php">← All reservations</a>
<div class="tc-card p-4 mb-3"><div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h2 class="h5 mb-1"><?= e($selected['origin']) ?> → <?= e($selected['destination']) ?></h2><div class="small text-muted-custom"><?= e($selected['id']) ?> · <?= e($selected['trip_type']) ?></div></div><span class="status-badge <?= status_badge_class($selected['status']) ?>"><?= e($selected['status']) ?></span></div>
<div class="row g-3 small"><div class="col-sm-6 col-lg-3"><div class="text-muted-custom">Departure Schedule</div><strong><?= e($selected['departure_date']) ?> <?= e($selected['departure_time']) ?><?= $selected['departure_schedule_name'] ? ' · ' . e($selected['departure_schedule_name']) : '' ?></strong></div><div class="col-sm-6 col-lg-3"><div class="text-muted-custom">Return Schedule</div><strong><?= e($selected['return_date'] ?: '—') ?> <?= e($selected['return_time'] ?: '') ?><?= $selected['return_schedule_name'] ? ' · ' . e($selected['return_schedule_name']) : '' ?></strong></div><div class="col-sm-6 col-lg-3"><div class="text-muted-custom">Passengers / Required Vehicle Type</div><strong><?= (int)$selected['passenger_count'] ?> · <?= e($selected['vehicle_requested']) ?><?= $selected['required_capacity'] !== null ? ' · ' . (int)$selected['required_capacity'] . ' pax capacity' : '' ?></strong></div><div class="col-sm-6 col-lg-3"><div class="text-muted-custom">Driver / Trip</div><strong><?= e($selected['driver_name'] ?: 'Awaiting assignment') ?><?= $selected['trip_id'] ? ' · ' . e($selected['trip_id']) : '' ?></strong></div><div class="col-sm-6"><div class="text-muted-custom">Contact</div><?= e($selected['contact_person']) ?> · <?= e($selected['contact_phone']) ?></div><div class="col-sm-6"><div class="text-muted-custom">Special requests</div><?= e($selected['notes'] ?: 'None') ?></div></div>
<?php if($selected['total_booking_fare'] !== null): ?><div class="fare-estimate mt-4"><div class="d-flex justify-content-between align-items-center mb-3"><h3 class="h6 mb-0">Fare Details</h3><span class="status-badge <?= $selected['fare_status']==='Confirmed'?'status-available':'status-scheduled' ?>"><?= e(($selected['fare_status'] ?: 'Estimated') . ' Fare') ?></span></div><div class="fare-lines"><span>Route Distance</span><strong><?= number_format((float)$selected['route_distance_km'],2) ?> km</strong><span>Base Fare Used <small>/ passenger</small></span><strong><?= money($selected['base_fare_used']) ?></strong><span>Rate Per Kilometer</span><strong><?= money($selected['rate_per_km_used']) ?> / km</strong><span>Distance Charge</span><strong><?= money($selected['distance_charge']) ?></strong><span>Fare Per Person</span><strong><?= money($selected['fare_per_person']) ?></strong><span>Passengers</span><strong><?= (int)$selected['passenger_count'] ?></strong></div><div class="fare-total"><span>Total Booking Fare</span><strong><?= money($selected['total_booking_fare']) ?></strong></div></div><?php endif; ?>
<?php if($selected['rejection_reason']): ?><div class="alert alert-danger mt-3 mb-0">Rejection reason: <?= e($selected['rejection_reason']) ?></div><?php endif; ?>
<?php if($selected['cancellation_reason']): ?><div class="alert alert-secondary mt-3 mb-0">Cancellation reason: <?= e($selected['cancellation_reason']) ?></div><?php endif; ?>
<?php if($selected['trip_id']): ?><div class="small mt-3">Trip status: <strong><?= e($selected['trip_status']) ?></strong> · Progress: <?= (int)$selected['progress_pct'] ?>% · <a href="<?= BASE_URL ?>/trip-details.php?id=<?= urlencode($selected['trip_id']) ?>">Trip details</a></div><?php endif; ?>
</div>
<?php $cancelBlocked=customer_cancellation_blocked($selected); ?>
<?php if(!in_array($selected['status'],['Cancelled','Completed'],true) && $selected['trip_status']!=='Completed'): ?>
<div class="tc-card p-4 mb-3"><h3 class="h6">Cancel reservation</h3><?php if($cancelBlocked): ?><p class="text-muted-custom mb-0">Cancellation is unavailable because navigation has started or the driver has departed.</p><?php else: ?><form method="post" action="<?= BASE_URL ?>/actions/customer-reservation.php" class="d-flex flex-wrap gap-2"><input type="hidden" name="action" value="cancel"><input type="hidden" name="reservation_id" value="<?= e($selected['id']) ?>"><input class="tc-form-control flex-grow-1" style="min-width:240px" name="reason" maxlength="120" placeholder="Reason for cancellation" required><button class="tc-btn tc-btn-secondary" type="submit" onclick="return confirm('Cancel this reservation?')">Cancel Reservation</button></form><?php endif; ?></div>
<?php endif; ?>
<?php if($selected['trip_status']==='Completed'): ?><div class="tc-card p-4 mb-3"><h3 class="h6">Rate your driver</h3><?php if($selected['stars']): ?><p class="mb-0">Your rating: <?= (int)$selected['stars'] ?>/5 stars<?= $selected['feedback'] ? ' · ' . e($selected['feedback']) : '' ?></p><?php else: ?><form method="post" action="<?= BASE_URL ?>/actions/customer-reservation.php"><input type="hidden" name="action" value="rate"><input type="hidden" name="reservation_id" value="<?= e($selected['id']) ?>"><label class="tc-form-label" for="stars">Stars *</label><select class="tc-form-select mb-2" id="stars" name="stars" style="max-width:180px" required><option value="">Choose rating</option><?php for($i=1;$i<=5;$i++): ?><option value="<?= $i ?>"><?= $i ?> star<?= $i===1?'':'s' ?></option><?php endfor; ?></select><label class="tc-form-label" for="feedback">Feedback (optional)</label><textarea class="tc-form-control mb-2" id="feedback" name="feedback" maxlength="2000" rows="2"></textarea><button class="tc-btn tc-btn-primary" type="submit">Submit Rating</button></form><?php endif; ?></div><?php endif; ?>
<div class="tc-card p-4"><h3 class="h6">Reservation history</h3><?php if(!$events): ?><p class="small text-muted-custom mb-0">No events recorded yet.</p><?php endif; ?><?php foreach($events as $event): ?><div class="border-top py-2 small"><strong><?= e($event['status']) ?></strong> · <?= e($event['created_at']) ?><?php if($event['reason']): ?><div><?= e($event['reason']) ?></div><?php endif; ?></div><?php endforeach; ?></div>
<?php else: ?>
<?php foreach(['Current Reservations'=>array_filter($reservations,fn($r)=>!in_array($r['status'],['Completed','Cancelled','Rejected'],true)),'Trip History'=>array_filter($reservations,fn($r)=>in_array($r['status'],['Completed','Cancelled','Rejected'],true))] as $section=>$items): ?>
<?php if($items): ?><h2 class="h5 mt-4 mb-3"><?= e($section) ?></h2><div class="row g-3"><?php foreach($items as $r): ?><div class="col-md-6 col-xl-4"><a class="text-decoration-none text-dark" href="<?= BASE_URL ?>/modules/customer-portal/reservations.php?id=<?= urlencode($r['id']) ?>"><div class="tc-card p-3 h-100"><div class="d-flex justify-content-between gap-2 mb-2"><strong><?= e($r['destination']) ?></strong><span class="status-badge <?= status_badge_class($r['status']) ?>"><?= e($r['status']) ?></span></div><div class="small text-muted-custom"><?= e($r['origin']) ?> → <?= e($r['destination']) ?></div><div class="small mt-2"><?= e($r['departure_date']) ?> · <?= e($r['departure_time']) ?> · <?= (int)$r['passenger_count'] ?> passengers</div><div class="small text-primary mt-3">View details →</div></div></a></div><?php endforeach; ?></div><?php endif; ?>
<?php endforeach; ?>
<?php if(!$reservations): ?><div class="tc-card p-5 text-center"><h2 class="h5">No reservations yet</h2><p class="text-muted-custom">Start by entering your trip details and passenger count.</p><button type="button" class="tc-btn tc-btn-primary" onclick="openCustomerReservation()">New Reservation</button></div><?php endif; ?>
<?php endif; ?>
<div id="modal-customer-reservation" class="tc-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="customer-reservation-title">
  <div class="tc-modal tc-modal-lg">
    <div class="tc-card-header d-flex justify-content-between align-items-center"><div><h2 id="customer-reservation-title" class="h5 mb-1">New Reservation</h2><div class="small text-muted-custom">Enter the passenger count and TourSphere will determine the required vehicle type.</div></div><button type="button" class="btn-close" aria-label="Close" onclick="App.closeModal('modal-customer-reservation')"></button></div>
    <div class="tc-card-body overflow-auto">
      <?php define('CUSTOMER_RESERVATION_MODAL', true); require __DIR__ . '/new-reservation.php'; ?>
    </div>
  </div>
</div>
<script>
async function openCustomerReservation() {
  App.openModal('modal-customer-reservation');
  try {
    const response = await fetch('<?= BASE_URL ?>/actions/customer-profile-prefill.php', { cache: 'no-store', credentials: 'same-origin' });
    if (!response.ok) return;
    const profile = await response.json();
    if (!profile.ok) return;
    document.getElementById('contact-person').value = profile.name;
    document.getElementById('contact-phone').value = profile.phone || '';
  } catch (_) {
    // The form keeps its server-rendered values if the refresh cannot complete.
  }
}
</script>
<?php if (($_GET['new'] ?? '') === '1'): ?><script>
document.addEventListener('DOMContentLoaded', () => {
  openCustomerReservation();
  const url = new URL(window.location.href);
  url.searchParams.delete('new');
  window.history.replaceState(null, '', url.pathname + url.search + url.hash);
});
</script><?php endif; ?>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
