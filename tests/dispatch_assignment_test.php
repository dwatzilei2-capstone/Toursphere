<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/dispatch-assignment.php';
$count = 0;
foreach (['Assigned','Confirmed'] as $status) {
    $r = ['status'=>$status,'assigned_vehicle_id'=>'VEH-002','assigned_driver_id'=>'DRV-101'];
    foreach ([['dispatch','VEH-002','DRV-101',true],['dispatch','VEH-001','DRV-101',false],['dispatch','VEH-002','DRV-102',false],['assign','VEH-002','DRV-101',true],['assign','VEH-001','DRV-102',true]] as [$action,$vehicle,$driver,$expected]) {
        $allowed = true;
        try { validate_dispatch_assignment($r,$action,$vehicle,$driver); }
        catch (RuntimeException $e) { $allowed = false; }
        if ($allowed !== $expected) throw new RuntimeException('Assignment lock regression.');
        $count++;
    }
}
validate_dispatch_assignment(['status'=>'Approved'],'assign','VEH-002','DRV-101');
echo (++$count), " dispatch-assignment checks passed; no database records changed.\n";
