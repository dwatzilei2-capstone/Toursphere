<?php
/** Present the existing assignment checks; this never assigns resources. */
function cargo_reservation_availability(PDO $pdo,array $reservation,?array $options=null): array {
 $options??=assignment_options($pdo,$reservation,$reservation['departure_date'].' '.$reservation['departure_time']);
 $availableVehicles=[];$availableDrivers=[];$vehicleBlockers=[];$matched=0;
 foreach($options['vehicles'] as $vehicle){
  if(in_array('A cargo vehicle is required.',$vehicle['reasons'],true))continue;
  $label=$vehicle['plate'].' · '.$vehicle['type'].' · '.$vehicle['cargoWeightKg'].' kg payload · '.$vehicle['cargoVolumeM3'].' m³';
  if($vehicle['cargoWeightKg']>=(float)$reservation['cargo_weight_kg'] && $vehicle['cargoVolumeM3']>=(float)$reservation['cargo_volume_m3'])$matched++;
  if($vehicle['eligible'])$availableVehicles[]=$label;
  foreach($vehicle['reasons'] as $reason)$vehicleBlockers[$reason][$vehicle['id']]=$label;
 }
 // Driver availability is independent of the designated vehicle pairing.
 // Use existing license/schedule rules, without allocating or reassigning anyone.
 $q=$pdo->query("SELECT * FROM vehicles WHERE service_category='Cargo' AND NOT COALESCE(is_archived,false) ORDER BY id");
 $cargoVehicles=$q->fetchAll();$driverBlockers=[];
 $departure=$reservation['departure_date'].' '.$reservation['departure_time'];
 foreach($pdo->query('SELECT * FROM drivers ORDER BY name')->fetchAll() as $driver){
  $reason=!in_array($driver['status'],['Active','Assigned'],true)?'Driver '.$driver['status']:'';
  if(!$reason && assignment_resource_conflict($pdo,$reservation,'driver_id',$driver['id'],$departure))$reason='Driver has an active trip or another assignment overlapping this departure and dispatch buffer';
  if(!$reason){
   $licenseIssues=[];$compatible=false;
   foreach($cargoVehicles as $vehicle){
    $issue=driver_license_eligibility($vehicle,$driver,$reservation['departure_date']);
    if($issue===''){$compatible=true;break;}
    $licenseIssues[$issue]=true;
   }
   if(!$compatible)$reason=$licenseIssues?implode(' ',array_keys($licenseIssues)):'No registered cargo vehicle type is available to check license compatibility';
  }
  if($reason)$driverBlockers[$reason][$driver['id']]=$driver['name'];
  else $availableDrivers[$driver['id']]=$driver['name'];
 }
 $format=static function(array $groups):array{
  $result=[];foreach($groups as $reason=>$items)$result[]=['reason'=>$reason,'count'=>count($items),'details'=>array_values($items)];return $result;
 };
 return ['applicable'=>true,'cargo'=>true,'buffer_hours'=>max(1,min(72,(int)fleet_setting('dispatch.buffer_hours','4'))),
  'compatible_vehicle_count'=>count($availableVehicles),'available'=>count($availableVehicles)>0 && count($availableDrivers)>0,
  'vehicles'=>['matched_count'=>$matched,'available_count'=>count($availableVehicles),'available_items'=>$availableVehicles,'blockers'=>$format($vehicleBlockers)],
  'drivers'=>['available_count'=>count($availableDrivers),'available_items'=>array_values($availableDrivers),'blockers'=>$format($driverBlockers)]];
}
