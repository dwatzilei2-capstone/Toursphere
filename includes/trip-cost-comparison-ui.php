<?php
require_once ROOT_PATH.'/includes/trip_funding.php';
if(!can('costs.view') || !has_role(['fleet_admin','fleet_manager','dispatcher']))return;
$groupVehicles=($active_page??'')==='cost-by-vehicle';
$comparisonRows=funding_comparison_rows($pdo,$range['start'],$range['end'],null,null,$groupVehicles?0:100);
if($groupVehicles){
 $groups=[];foreach($comparisonRows as $row){$key=$row['vehicle_id'];if(!$key)continue;
  if(!isset($groups[$key]))$groups[$key]=['id'=>$key,'count'=>0,'estimated_total_cost'=>null,'approved_amount'=>null,'actual_recorded_cost'=>null];
  $groups[$key]['count']++;foreach(['estimated_total_cost','approved_amount','actual_recorded_cost'] as $field)if($row[$field]!==null)$groups[$key][$field]=($groups[$key][$field]??0)+(float)$row[$field];
 }$comparisonRows=array_values($groups);
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/trip-funding.css?v=<?= filemtime(ROOT_PATH.'/css/trip-funding.css') ?>">
<section class="tc-card funding-comparison mb-3"><div class="tc-card-header"><div><h2 class="fs-6 fw-bold mb-1"><?= $groupVehicles?'Funding & Recorded Costs by Vehicle':'Estimated vs Funded vs Actual' ?></h2><p class="small text-muted-custom mb-0">Approved funding is a limit, not money spent. Actual costs include only recorded fuel, completed maintenance and recorded tolls. <?= $groupVehicles?'All matching trips in the selected period.':'Up to 100 recent trips in the selected period.' ?></p></div></div><div class="tc-table-container"><table class="tc-table"><thead><tr><th><?= $groupVehicles?'Vehicle':'Trip / Route' ?></th><?php if($groupVehicles): ?><th>Trips</th><?php endif; ?><th>Estimated Cost</th><th>Approved Funding</th><th>Actual Recorded Cost</th><th>Variance vs Estimate</th></tr></thead><tbody>
<?php if(!$comparisonRows): ?><tr><td colspan="<?= $groupVehicles?6:5 ?>" class="text-center text-muted-custom">No trips in the selected period.</td></tr><?php endif; ?>
<?php foreach($comparisonRows as $row):$variance=$row['actual_recorded_cost']!==null && $row['estimated_total_cost']!==null?(float)$row['actual_recorded_cost']-(float)$row['estimated_total_cost']:null; ?><tr><td><?php if($groupVehicles): ?><strong><?= e($row['id']) ?></strong><?php else: ?><a href="<?= BASE_URL ?>/trip-details.php?id=<?= urlencode($row['id']) ?>"><?= e($row['id']) ?></a><div class="small text-muted-custom"><?= e($row['origin'].' → '.$row['destination']) ?></div><?php endif; ?></td><?php if($groupVehicles): ?><td><?= $row['count'] ?></td><?php endif; ?><td><?= funding_money($row['estimated_total_cost'],'Not Available') ?></td><td><?= funding_money($row['approved_amount'],'Not Funded') ?></td><td><?= funding_money($row['actual_recorded_cost']) ?></td><td class="<?= $variance!==null && $variance>0?'text-danger':'text-muted-custom' ?>"><?= $variance===null?'Not Available':money(abs($variance)).($variance>0?' Over Estimate':($variance<0?' Under Estimate':' On Estimate')) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
