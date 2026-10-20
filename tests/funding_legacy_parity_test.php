<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';require ROOT_PATH.'/includes/trip_funding.php';
require __DIR__.'/fixtures/legacy_funding_estimate.php';
$p=db();
foreach(['fuel_price_history','route_history','fuel_transactions','trip_cost_estimates','trip_funding_requests','fuel_authorizations','audit_logs'] as $table)$p->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING IDENTITY)");
$p->exec("INSERT INTO fuel_price_history(fuel_type,price_per_liter,effective_date,source,updated_by) VALUES('Diesel',95.95,CURRENT_DATE,'Isolated archive comparison',1),('Gasoline',75,CURRENT_DATE,'Isolated archive comparison',1)");
$base=['id'=>'PARITY-TEST','reservation_id'=>'PARITY-REQUEST','vehicle_id'=>'PARITY-VEHICLE','driver_id'=>'PARITY-DRIVER','distance_km'=>null,'route_distance_km'=>8.84,'fuel_type'=>'Diesel','avg_fuel_km'=>'9.0 km/L (est.)','passengers'=>0];
$count=0;
function parity_check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);$count++;}
$fields=['distance_km','expected_km_per_liter','reference_price','estimated_liters','estimated_fuel_cost','estimated_total_cost','toll','parking'];
foreach([8.84,180,0] as $distance)foreach(['9.0 km/L (est.)','8 km/L','—'] as $efficiency)foreach(['Diesel','Gasoline','Missing price'] as $fuel){
 $t=array_replace($base,['route_distance_km'=>$distance,'avg_fuel_km'=>$efficiency,'fuel_type'=>$fuel]);
 $old=funding_estimate_legacy_reference($p,$t);$current=funding_estimate($p,$t);
 foreach($fields as $field)parity_check($old[$field]===$current[$field],"Archive parity: $field / $distance / $efficiency / $fuel");
}
$t=array_replace($base,['distance_km'=>180,'route_distance_km'=>8.84,'avg_fuel_km'=>'8 km/L']);
parity_check(funding_estimate($p,$t)['estimated_liters']===22.5,'Old saved-trip distance precedence retained');
$p->exec("UPDATE fuel_price_history SET price_per_liter=95 WHERE fuel_type='Diesel'");
parity_check(funding_estimate($p,$t)['estimated_total_cost']===2137.5,'Archive example 180 km / 8 km/L * 95 PHP/L matches exactly');
$cargo=funding_estimate($p,array_replace($base,['passengers'=>0]));$passenger=funding_estimate($p,array_replace($base,['passengers'=>40]));
parity_check($cargo['estimated_total_cost']===$passenger['estimated_total_cost'],'Passenger count does not affect funding cost');
parity_check($cargo['estimated_total_cost']===$cargo['estimated_fuel_cost'],'No fuel double-count or invented extra expense');
echo "$count legacy/current funding comparison checks passed using temporary tables. No passenger workflow or operational record was imported.\n";
