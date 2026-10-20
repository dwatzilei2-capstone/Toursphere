<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/includes/bootstrap.php';require ROOT_PATH.'/includes/trip_performance.php';
$checks=0;function readiness_check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$none=performance_fuel_readiness(['distance_km'=>7.3],[]);
readiness_check(!$none['available']&&str_contains($none['message'],'no verified fuel record'),'No consumption support detected without inventing zero liters');
readiness_check(!performance_fuel_readiness(['distance_km'=>7.3],[['kind'=>'Supporting Document']])['available'],'Uploaded document alone is not a fuel record');
readiness_check(!performance_fuel_readiness(['distance_km'=>null],[['kind'=>'Fuel']])['available'],'Missing distance cannot enable fuel scoring');
readiness_check(performance_fuel_readiness(['distance_km'=>7.3],[['kind'=>'Fuel']])['available'],'Existing verified fuel source and distance permit manual assessment');
readiness_check(str_contains(performance_fuel_readiness(['distance_km'=>7.3],[['kind'=>'Fuel']])['message'],'purchase alone is insufficient'),'Fuel purchases are not automatically actual consumption');
echo "$checks fuel readiness checks passed; no database records modified.\n";
