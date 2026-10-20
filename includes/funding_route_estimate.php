<?php
/** Cache a funding planning distance without generating, selecting or applying a Driver route. */
function funding_prepare_automatic_distance(PDO $pdo,array $trip,?callable $provider=null): array {
 if(!funding_can_manage() || $pdo->inTransaction() || (float)($trip['distance_km']??0)>0 || (float)($trip['route_distance_km']??0)>0
    || !in_array($trip['status'],['Assigned','Confirmed','Scheduled','Dispatched'],true) || (int)($trip['navigation_active']??0)!==0)return $trip;
 $request=funding_request($pdo,$trip['id']);
 if($request && in_array($request['status'],['Pending Finance Approval','Funding Confirmed'],true))return $trip;
 $origin=trim($trip['origin']);$destination=trim($trip['destination']);if($origin===''||$destination==='')return $trip;
 $stops=array_values(array_filter(array_map('trim',preg_split('/[;\n]+/',(string)($trip['waypoints']??''))?:[])));
 if(count($stops)>15)return $trip;
 if($provider===null){require_once __DIR__.'/customer_reservations.php';$provider='customer_route_distance_km';}
 try{
  $points=[$origin,...$stops,$destination];$distance=0.0;
  for($i=1;$i<count($points);$i++){
   $segment=$provider($points[$i-1],$points[$i]);
   if(!is_numeric($segment)||!is_finite((float)$segment)||(float)$segment<=0)throw new DomainException('Invalid driving distance.');
   $distance+=(float)$segment;
  }
  if($distance>100000)throw new DomainException('Invalid driving distance.');
  // Provider requests finish before locks are acquired.
  $pdo->beginTransaction();
  $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute(['fuel-budget:'.$trip['id']]);
  $pdo->prepare('SELECT id FROM reservations WHERE id=? FOR UPDATE')->execute([$trip['reservation_id']]);
  $pdo->prepare('SELECT id FROM trips WHERE id=? FOR UPDATE')->execute([$trip['id']]);
  $fresh=funding_trip($pdo,$trip['id']);$request=funding_request($pdo,$trip['id']);
  if(!$fresh || $fresh['origin']!==$trip['origin'] || $fresh['destination']!==$trip['destination'] || ($fresh['waypoints']??'')!==($trip['waypoints']??'')
     || $fresh['vehicle_id']!==$trip['vehicle_id'] || $fresh['driver_id']!==$trip['driver_id']
     || !in_array($fresh['status'],['Assigned','Confirmed','Scheduled','Dispatched'],true) || (int)$fresh['navigation_active']!==0
     || (float)$fresh['distance_km']>0 || (float)$fresh['route_distance_km']>0
     || ($request && in_array($request['status'],['Pending Finance Approval','Funding Confirmed'],true))){$pdo->commit();return $fresh?:$trip;}
  $distance=round($distance,2);
  // Reservation planning distance retains two decimals; trips.distance_km stores only one.
  $pdo->prepare('UPDATE reservations SET route_distance_km=? WHERE id=?')->execute([$distance,$trip['reservation_id']]);
  $pdo->prepare('INSERT INTO audit_logs(action,user_id,entity_type,entity_id,details) VALUES(?,?,?,?,?)')->execute([
   'TRIP_DISTANCE_ESTIMATED',current_user()['id'],'trip',$trip['id'],json_encode(['source'=>'Automatic Google driving-distance estimate','distance_km'=>$distance,'origin'=>$origin,'destination'=>$destination,'waypoints'=>$stops,'driver_route_applied'=>false])]);
  $pdo->commit();return funding_trip($pdo,$trip['id']);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();return $trip;}
}
