<?php
require_once ROOT_PATH.'/includes/trip_funding.php';
if(!has_role('driver') || empty($driverFundingTripId))return;
$driverFundingTrip=funding_trip($pdo,$driverFundingTripId);
$ownership=$pdo->prepare('SELECT 1 FROM drivers WHERE id=? AND user_id=?');$ownership->execute([$driverFundingTrip['driver_id']??null,current_user()['id']]);
if(!$ownership->fetchColumn() || in_array($driverFundingTrip['status'],['Cancelled','Completed'],true))return;
$safeFunding=funding_request($pdo,$driverFundingTripId);
$safeStatus=$safeFunding['status']??'Not Requested';if($safeFunding && in_array($driverFundingTrip['status'],['Assigned','Confirmed','Scheduled'],true) && !funding_matches($safeFunding,$driverFundingTrip))$safeStatus='Not Confirmed';
?>
<div class="small text-muted-custom mt-2"><strong>Trip Funding</strong><br>Method: <?= e($safeFunding['method_name']??'Not Requested') ?><br>Status: <?= e($safeStatus) ?></div>
