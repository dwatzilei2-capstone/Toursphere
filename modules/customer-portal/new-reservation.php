<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/customer_reservations.php';
require_login();
if (!has_role('customer')) { http_response_code(403); exit('Forbidden'); }
if (!defined('CUSTOMER_RESERVATION_MODAL')) {
    redirect_to(BASE_URL . '/modules/customer-portal/reservations.php?new=1');
}
$types = customer_vehicle_requirement_options(db());
$_SESSION['customer_reservation_csrf'] ??= bin2hex(random_bytes(32));
?>
<form method="post" action="<?= BASE_URL ?>/actions/reservation.php" id="customer-reservation-form">
<input type="hidden" name="action" value="create">
<input type="hidden" name="csrf" value="<?= e($_SESSION['customer_reservation_csrf']) ?>">
<div class="row g-3">
  <div class="col-md-6"><label class="tc-form-label" for="passengers">Passenger Count *</label><input id="passengers" class="tc-form-control" type="number" min="1" step="1" name="passenger_count" required><div class="invalid-feedback">Enter a positive whole number.</div></div>
  <div class="col-md-6"><label class="tc-form-label" for="required-vehicle-type">Required Vehicle Type *</label><div id="required-vehicle-type" class="tc-form-control bg-light" role="status" aria-live="polite" aria-atomic="true" tabindex="-1">Automatically determined based on passenger count</div><div id="capacity-hint" class="small text-muted-custom mt-1">Automatically selected based on your passenger count.</div></div>
  <div class="col-md-6"><label class="tc-form-label" for="pickup">Pickup Location *</label><input id="pickup" class="tc-form-control" name="origin" maxlength="200" required><div class="invalid-feedback">Enter a pickup location.</div></div>
  <div class="col-md-6"><label class="tc-form-label" for="destination">Destination *</label><input id="destination" class="tc-form-control" name="destination" maxlength="200" required><div class="invalid-feedback">Enter a destination.</div></div>
  <div class="col-md-6"><label class="tc-form-label" for="departure-date">Travel Date *</label><input id="departure-date" class="tc-form-control" type="date" name="departure_date" min="<?= date('Y-m-d') ?>" required><div class="invalid-feedback">Choose a valid travel date.</div></div>
  <div class="col-md-6"><label class="tc-form-label" for="departure-schedule">Available Schedule *</label><select id="departure-schedule" class="tc-form-select" name="departure_schedule_id" required disabled><option value="">Select a travel date and passenger count</option></select><div id="departure-schedule-help" class="small text-muted-custom mt-1" aria-live="polite">Choose your preferred departure schedule.</div></div>
  <div class="col-md-6"><label class="tc-form-label" for="trip-type">Trip Type *</label><select id="trip-type" class="tc-form-select" name="trip_type" required><option value="">Choose trip type</option><?php foreach (['One Way','Round Trip','Day Tour & Transfer','Multi-Day Tour','Educational Heritage Tour','VIP Executive Airport Transfer','School Field Trip'] as $type): ?><option value="<?= e($type) ?>"><?= e($type) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6" id="return-date-wrap" hidden><label class="tc-form-label" for="return-date">Return Date *</label><input id="return-date" class="tc-form-control" type="date" name="return_date"><div class="invalid-feedback">Choose a return date after departure.</div></div>
  <div class="col-md-6" id="return-time-wrap" hidden><label class="tc-form-label" for="return-schedule">Return Schedule *</label><select id="return-schedule" class="tc-form-select" name="return_schedule_id" disabled><option value="">Select a return date and passenger count</option></select><div id="return-schedule-help" class="small text-muted-custom mt-1" aria-live="polite">Choose your preferred return schedule.</div></div>
  <div class="col-md-6"><label class="tc-form-label" for="contact-person">Contact Person *</label><input id="contact-person" class="tc-form-control" name="contact_person" maxlength="120" value="<?= e($current_user['name']) ?>" required></div>
  <div class="col-md-6"><label class="tc-form-label" for="contact-phone">Contact Phone *</label><input id="contact-phone" class="tc-form-control" type="tel" name="contact_phone" maxlength="40" value="<?= e($current_user['phone'] ?? '') ?>" required></div>
  <div class="col-12"><label class="tc-form-label" for="notes">Notes / Special Requests</label><textarea id="notes" class="tc-form-control" name="notes" rows="3" maxlength="2000"></textarea></div>
</div>
<section class="fare-estimate mt-4" id="fare-estimate" aria-live="polite">
  <div class="d-flex justify-content-between gap-3 align-items-start mb-3"><div><h3 class="h6 mb-1">Fare Estimate</h3><p class="small text-muted-custom mb-0">Calculated from the verified driving route and current company pricing.</p></div><span class="status-badge status-scheduled">Estimated Fare</span></div>
  <div id="fare-estimate-message" class="small text-muted-custom">Enter pickup, destination, and passenger count to calculate the fare.</div>
  <div id="fare-estimate-details" hidden>
    <div class="fare-lines">
      <span>Base Fare <small>/ passenger</small></span><strong id="fare-base"></strong>
      <span>Route Distance</span><strong id="fare-distance"></strong>
      <span>Distance Rate <small>/ km</small></span><strong id="fare-rate"></strong>
      <span>Distance Charge</span><strong id="fare-distance-charge"></strong>
      <span>Fare Per Person</span><strong id="fare-person"></strong>
      <span>Number of Passengers</span><strong id="fare-passengers"></strong>
    </div>
    <div class="fare-total"><span>Estimated Booking Total</span><strong id="fare-total"></strong></div>
  </div>
</section>
<section class="reservation-review mt-3" id="reservation-review" hidden>
  <h3 class="h6 mb-3">Review Reservation</h3>
  <div class="row g-3 small"><div class="col-sm-6"><span>Origin</span><strong id="review-origin"></strong></div><div class="col-sm-6"><span>Destination</span><strong id="review-destination"></strong></div><div class="col-sm-6"><span>Travel Schedule</span><strong id="review-schedule"></strong></div><div class="col-sm-6"><span>Passengers</span><strong id="review-passengers"></strong></div></div>
</section>
<div class="d-flex justify-content-end mt-4"><button class="tc-btn tc-btn-primary" id="reservation-submit" type="submit" disabled>Submit Reservation</button></div>
</form>
<script>
(() => {
 const form=document.getElementById('customer-reservation-form'), passengers=document.getElementById('passengers'), requiredType=document.getElementById('required-vehicle-type'), trip=document.getElementById('trip-type');
 const vehicleTypes=<?= json_encode($types, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
 const rd=document.getElementById('return-date'), departureSchedule=document.getElementById('departure-schedule'), returnSchedule=document.getElementById('return-schedule');
 const origin=document.getElementById('pickup'), destination=document.getElementById('destination'), submit=document.getElementById('reservation-submit');
 const message=document.getElementById('fare-estimate-message'), details=document.getElementById('fare-estimate-details'), review=document.getElementById('reservation-review');
 let quoteTimer=0, quoteController=null, quotedKey='';
 let scheduleControllers={};
 const amount=(value,symbol)=>symbol+Number(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
 function quoteKey(){return [origin.value.trim(),destination.value.trim(),passengers.value].join('|');}
 async function calculateFare(){
   const count=Number(passengers.value), key=quoteKey(); quotedKey=''; submit.disabled=true; details.hidden=true; review.hidden=true;
   if(!origin.value.trim()||!destination.value.trim()||!Number.isInteger(count)||count<1){message.textContent='Enter pickup, destination, and passenger count to calculate the fare.';return;}
   if(!vehicleTypes.some(item=>Number(item.capacity)>=count)){message.textContent=`No suitable vehicle type is available for ${count} passengers.`;return;}
   message.textContent='Calculating the verified driving route and fare…';
   if(quoteController)quoteController.abort(); quoteController=new AbortController();
   const payload=new FormData(); payload.append('origin',origin.value.trim());payload.append('destination',destination.value.trim());payload.append('passenger_count',String(count));
   try{const response=await fetch('<?= BASE_URL ?>/actions/fare-estimate.php',{method:'POST',body:payload,credentials:'same-origin',signal:quoteController.signal});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Fare could not be calculated.');if(key!==quoteKey())return;const f=data.fare,s=f.currency_symbol;document.getElementById('fare-base').textContent=amount(f.base_fare,s);document.getElementById('fare-distance').textContent=Number(f.route_distance_km).toFixed(2)+' km';document.getElementById('fare-rate').textContent=amount(f.rate_per_km,s);document.getElementById('fare-distance-charge').textContent=amount(f.distance_charge,s);document.getElementById('fare-person').textContent=amount(f.fare_per_person,s);document.getElementById('fare-passengers').textContent=String(f.passenger_count);document.getElementById('fare-total').textContent=amount(f.total_booking_fare,s);document.getElementById('review-origin').textContent=origin.value.trim();document.getElementById('review-destination').textContent=destination.value.trim();document.getElementById('review-schedule').textContent=(document.getElementById('departure-date').value||'Date pending')+' '+(departureSchedule.selectedOptions[0]?.textContent||'Schedule pending');document.getElementById('review-passengers').textContent=String(count);message.textContent='';details.hidden=false;review.hidden=false;quotedKey=key;submit.disabled=!schedulesValid();}catch(error){if(error.name==='AbortError')return;message.textContent=error.message||'Fare calculation failed. Verify the locations or retry.';}
 }
 function scheduleFare(){clearTimeout(quoteTimer);quoteTimer=setTimeout(calculateFare,500);}
 function isRoundTrip(){return ['Round Trip','Multi-Day Tour'].includes(trip.value);}
 function schedulesValid(){return Boolean(departureSchedule.value)&&(!isRoundTrip()||Boolean(returnSchedule.value));}
 async function loadSchedules(kind){const dateInput=kind==='departure'?document.getElementById('departure-date'):rd,select=kind==='departure'?departureSchedule:returnSchedule,help=document.getElementById(kind+'-schedule-help'),count=Number(passengers.value);select.replaceChildren(new Option('Loading available schedules…',''));select.disabled=true;submit.disabled=true;if(!dateInput.value||!Number.isInteger(count)||count<1){select.replaceChildren(new Option('Select a date and passenger count',''));help.textContent='Choose your preferred '+kind+' schedule.';return;}scheduleControllers[kind]?.abort();const controller=new AbortController();scheduleControllers[kind]=controller;try{const response=await fetch(`<?= BASE_URL ?>/actions/schedule-availability.php?date=${encodeURIComponent(dateInput.value)}&passengers=${count}`,{credentials:'same-origin',cache:'no-store',signal:controller.signal});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Schedules could not be loaded.');select.replaceChildren(new Option('Choose an available schedule',''));data.schedules.forEach(item=>{const option=new Option(`${item.display_time} — ${item.name} — ${item.availability}`,String(item.id));option.disabled=!item.selectable;option.dataset.requiredType=item.required_type||'';option.dataset.requiredCapacity=item.required_capacity||'';select.add(option);});select.disabled=!data.schedules.some(item=>item.selectable);help.textContent=data.schedules.length?'Schedules are checked against current passenger demand.':`No available ${kind} schedules for the selected date. Please select another travel date.`;}catch(error){if(error.name==='AbortError')return;select.replaceChildren(new Option('No schedules available',''));help.textContent=error.message||'Schedules could not be loaded.';}}
 function applyScheduleRequirement(){const option=departureSchedule.selectedOptions[0];if(option?.dataset.requiredType){requiredType.textContent=`${option.dataset.requiredType} — Capacity: ${option.dataset.requiredCapacity} passengers`;document.getElementById('capacity-hint').textContent='Based on total passenger demand for this scheduled departure.';}}
 function reloadSchedules(){loadSchedules('departure');if(isRoundTrip())loadSchedules('return');}
 function update(){const count=Number(passengers.value), match=Number.isInteger(count)&&count>0?vehicleTypes.find(item=>Number(item.capacity)>=count):null;requiredType.textContent=match?`${match.type} — Capacity: ${match.capacity} passengers`:(Number.isInteger(count)&&count>0?`No suitable vehicle type is available for ${count} passengers.`:'Automatically determined based on passenger count');requiredType.classList.toggle('text-danger',Number.isInteger(count)&&count>0&&!match);document.getElementById('capacity-hint').textContent=match?'Final requirement also considers total passenger demand for the selected schedule.':(Number.isInteger(count)&&count>0?'Please reduce the passenger count or contact the company for assistance.':'Enter passenger count to determine the required vehicle type.');const needsReturn=isRoundTrip();for(const input of [rd,returnSchedule]){input.required=needsReturn;input.closest('.col-md-6').hidden=!needsReturn;if(!needsReturn){input.value='';input.disabled=input===returnSchedule;}}return match;}
 let scheduleTimer=0;passengers.addEventListener('input',()=>{update();scheduleFare();clearTimeout(scheduleTimer);scheduleTimer=setTimeout(reloadSchedules,350);});origin.addEventListener('input',scheduleFare);destination.addEventListener('input',scheduleFare);document.getElementById('departure-date').addEventListener('change',event=>{rd.min=event.target.value;if(rd.value&&rd.value<event.target.value){rd.value='';returnSchedule.value='';}loadSchedules('departure');});rd.addEventListener('change',()=>loadSchedules('return'));trip.addEventListener('change',()=>{update();if(isRoundTrip())loadSchedules('return');submit.disabled=quotedKey!==quoteKey()||!schedulesValid();});departureSchedule.addEventListener('change',()=>{applyScheduleRequirement();submit.disabled=quotedKey!==quoteKey()||!schedulesValid();});returnSchedule.addEventListener('change',()=>submit.disabled=quotedKey!==quoteKey()||!schedulesValid());
 form.addEventListener('submit',event=>{const match=update();const departureDate=document.getElementById('departure-date').value;rd.setCustomValidity(isRoundTrip()&&rd.value<departureDate?'Return date cannot be before the departure date.':'');for(const field of form.querySelectorAll('input,select,textarea'))field.classList.toggle('is-invalid',!field.checkValidity());if(!match||!schedulesValid()||!form.checkValidity()||quotedKey!==quoteKey()){event.preventDefault();if(!match)requiredType.focus();else if(quotedKey!==quoteKey())calculateFare();else form.reportValidity();}});
 update();
})();
</script>
