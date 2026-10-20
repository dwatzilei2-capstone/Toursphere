<?php
// Calculation-only reference from user ZIP; never loads the old application.
// Source entry SHA-256: 47ce26870aeeb4f245162a8976d1b12b675cfa10851de305785d891f0baccb82
function funding_estimate_legacy_reference(PDO $pdo,array $t): array {
 $issues=[]; $distance=(float)($t['distance_km']>0?$t['distance_km']:$t['route_distance_km']);
 $efficiency=vehicle_efficiency_value((string)$t['avg_fuel_km']) ?? 0;
 $price=fuel_current_price($pdo,(string)$t['fuel_type']);
 if(!$t['vehicle_id'] || !$t['driver_id'])$issues[]='Assign a vehicle and driver first.';
 if($distance<=0)$issues[]='Route distance unavailable. Prepare the route in the existing AI Route Planner.';
 if($efficiency<=0)$issues[]='Vehicle fuel efficiency data unavailable.';
 if(!$price)$issues[]='Current '.($t['fuel_type'] ?: 'vehicle fuel').' price unavailable. Admin must configure it in Settings → Fuel Prices.';
 $liters=$distance>0 && $efficiency>0 ? $distance/$efficiency : null;
 $fuel=$liters!==null && $price ? round($liters*(float)$price['price_per_liter'],2) : null;
 return ['issues'=>$issues,'distance_km'=>$distance,'distance_source'=>$t['distance_km']>0?'Saved trip route':'Saved reservation route','expected_km_per_liter'=>$efficiency,
 'fuel_type'=>$t['fuel_type'],'price'=>$price,'reference_price'=>$price['price_per_liter']??null,'estimated_liters'=>$liters,'estimated_fuel_cost'=>$fuel,'estimated_total_cost'=>$fuel,
 'toll'=>'Not Included in Estimate','parking'=>'Not Included in Estimate'];
}
