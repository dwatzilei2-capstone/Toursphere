<?php $fuelMonitor=fuel_ops_monitoring($pdo,has_role('driver')?(int)current_user()['id']:null); ?>
<div class="tc-card p-3 my-3"><h4 class="fs-6 fw-bold">Fuel Monitoring &amp; Optimization</h4>
<div class="small mb-2">Measured consumption efficiency: <strong><?= $fuelMonitor['efficiency']===null?'Not available':number_format($fuelMonitor['efficiency'],2).' km/L' ?></strong><span class="text-muted-custom"> · Requires verified consumption and independently verified distance. Purchased liters are not treated as consumed fuel.</span></div>
<?php if(!$fuelMonitor['findings']): ?><p class="small text-muted-custom mb-0">No supported price or duplicate-receipt flags found in available records. This does not establish overall fuel efficiency.</p><?php else: ?><ul class="small mb-0"><?php foreach($fuelMonitor['findings'] as $finding): ?><li><?= e($finding) ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
