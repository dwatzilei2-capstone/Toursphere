<?php
require dirname(__DIR__).'/includes/vehicle_efficiency.php';
$checks=0;
foreach(['Tour Bus'=>3.8,'Coaster Bus'=>6.5,'Executive Van'=>9.5,'VIP SUV'=>10.5,'Minibus'=>6.0] as $type=>$expected){
    $stored=vehicle_efficiency_default($type);
    if(strlen($stored)>20 || vehicle_efficiency_value($stored)!==$expected || !str_contains($stored,'(est.)')) throw new RuntimeException('Invalid planning estimate for '.$type);
    $checks++;
}
foreach(['—','0 km/L','unknown','6 km/L manufacturer claim',null] as $invalid){
    if(vehicle_efficiency_value($invalid)!==null) throw new RuntimeException('Invalid efficiency accepted');
    $checks++;
}
if(vehicle_efficiency_value('9.5 km/L')!==9.5) throw new RuntimeException('Existing vehicle value changed');
try{vehicle_efficiency_default('Unknown');throw new RuntimeException('Unsupported type accepted');}catch(InvalidArgumentException $e){$checks++;}
echo ($checks+1)." vehicle efficiency cases passed.\n";
