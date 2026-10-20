<?php
require_once dirname(__DIR__,2).'/includes/bootstrap.php';
require_login();
if(!has_role('customer')){http_response_code(403);exit('Forbidden');}
if(!defined('CUSTOMER_RESERVATION_MODAL'))redirect_to(BASE_URL.'/modules/customer-portal/reservations.php?new=1');
$_SESSION['customer_reservation_csrf']??=bin2hex(random_bytes(32));
?>
<form method="post" action="<?= BASE_URL ?>/actions/reservation.php" id="customer-reservation-form">
<input type="hidden" name="action" value="create">
<input type="hidden" name="csrf" value="<?= e($_SESSION['customer_reservation_csrf']) ?>">
<div class="row g-3">
 <div class="col-md-6"><label class="tc-form-label" for="request-purpose">Request Type *</label><select class="tc-form-select" id="request-purpose" name="request_purpose" required><option value="Cargo Transport">Cargo Transport</option><option value="Vehicle Request">Vehicle Request</option></select></div>
 <div class="col-md-6"><label class="tc-form-label" for="cargo-type">Cargo Type *</label><input class="tc-form-control" id="cargo-type" name="cargo_type" maxlength="120" placeholder="e.g. Packaged goods, chilled food" required></div>
 <div class="col-md-4"><label class="tc-form-label" for="cargo-weight">Total Cargo Weight (kg) *</label><input class="tc-form-control" id="cargo-weight" name="cargo_weight_kg" type="number" min="0.001" max="999999999.999" step="0.001" required></div>
 <div class="col-md-4"><label class="tc-form-label" for="cargo-volume">Total Cargo Volume (m&sup3;) *</label><input class="tc-form-control" id="cargo-volume" name="cargo_volume_m3" type="number" min="0.001" max="999999999.999" step="0.001" required></div>
 <div class="col-md-4"><label class="tc-form-label" for="cargo-quantity">Quantity (packages / units) *</label><input class="tc-form-control" id="cargo-quantity" name="cargo_quantity" type="number" min="1" max="2147483647" step="1" required></div>
 <div class="col-md-6"><label class="tc-form-label" for="pickup">Pickup Location *</label><input id="pickup" class="tc-form-control" name="origin" maxlength="200" required></div>
 <div class="col-md-6"><label class="tc-form-label" for="destination">Delivery Destination *</label><input id="destination" class="tc-form-control" name="destination" maxlength="200" required></div>
 <div class="col-md-6"><label class="tc-form-label" for="departure-date">Requested Pickup Date *</label><input id="departure-date" class="tc-form-control" type="date" name="departure_date" min="<?= date('Y-m-d') ?>" required></div>
 <div class="col-md-6"><label class="tc-form-label" for="departure-time">Requested Pickup Time *</label><input id="departure-time" class="tc-form-control" type="time" name="departure_time" required></div>
 <div class="col-md-6"><label class="tc-form-label" for="contact-person">Contact Person *</label><input id="contact-person" class="tc-form-control" name="contact_person" maxlength="120" value="<?= e($current_user['name']) ?>" required></div>
 <div class="col-md-6"><label class="tc-form-label" for="contact-phone">Contact Phone *</label><input id="contact-phone" class="tc-form-control" type="tel" name="contact_phone" maxlength="40" value="<?= e($current_user['phone']??'') ?>" required></div>
 <div class="col-12"><label class="tc-form-label" for="special-handling">Special Handling Requirements</label><textarea id="special-handling" class="tc-form-control" name="special_handling" maxlength="2000" rows="2" placeholder="Temperature control, fragile goods, loading equipment, or other instructions"></textarea></div>
 <div class="col-12"><label class="tc-form-label" for="notes">Additional Notes</label><textarea id="notes" class="tc-form-control" name="notes" maxlength="2000" rows="2"></textarea></div>
</div>
<p class="small text-muted-custom mt-3">Admin/Dispatcher reviews your request and selects the vehicle and driver. This temporary Logistics 1 request form runs within TourSphere; no live Logistics 1 connection is active.</p>
<div class="d-flex justify-content-end mt-4"><button class="tc-btn tc-btn-primary" id="reservation-submit" type="submit">Submit Request</button></div>
</form>
