<?php
require_once dirname(__DIR__,2).'/includes/bootstrap.php'; require_once ROOT_PATH.'/includes/cost_analysis.php'; require_login(); require_permission('costs.view');
$pdo=db(); $range=cost_date_range($_GET,date('Y-01-01'),date('Y-m-d')); $summary=cost_summary($pdo,$range['start'],$range['end']); $previous=cost_summary($pdo,$range['previous_start'],$range['previous_end']); $breakdown=cost_breakdown($pdo,$range['start'],$range['end']); $series=cost_monthly_series($pdo,$range['start'],$range['end']);
$q=$pdo->prepare("WITH f AS(SELECT trip_id,SUM(total_cost) n FROM fuel_transactions WHERE trip_id IS NOT NULL GROUP BY trip_id),m AS(SELECT source_trip_id trip_id,SUM(COALESCE(estimated_cost,0)) n FROM maintenance_orders WHERE status='Completed' AND source_trip_id IS NOT NULL GROUP BY source_trip_id) SELECT t.id,t.actual_arrival,t.origin,t.destination,v.plate_number,TRIM(v.brand||' '||v.model) vehicle,COALESCE(f.n,0) fuel,COALESCE(m.n,0)+COALESCE(t.toll_fee,0) other,COALESCE(f.n,0)+COALESCE(m.n,0)+COALESCE(t.toll_fee,0) cost,COALESCE(r.total_booking_fare,0) revenue FROM trips t LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN f ON f.trip_id=t.id LEFT JOIN m ON m.trip_id=t.id LEFT JOIN reservations r ON r.id=t.reservation_id AND r.status='Completed' AND r.fare_status='Confirmed' WHERE t.status='Completed' AND t.actual_arrival>=?::date AND t.actual_arrival<?::date ORDER BY t.actual_arrival DESC LIMIT 10");$q->execute([$range['start'],$range['end']]);$recent=$q->fetchAll();
$cards=[['Total Transportation Cost',$summary['cost'],cost_comparison($summary['cost'],$previous['cost']),'Recorded operating expenses'],['Total Revenue',$summary['revenue'],cost_comparison($summary['revenue'],$previous['revenue']),'Confirmed fares on completed trips'],['Trip Margin',$summary['margin'],cost_comparison($summary['margin'],$previous['margin']),'Revenue less recorded cost'],['Average Cost per Trip',$summary['average'],$previous['trip_count']?cost_comparison($summary['average'],$previous['average']):null,$summary['trip_count'].' completed trip(s)']];
$breakdownColors=['Fuel'=>'#2F80ED','Maintenance'=>'#F2994A','Toll'=>'#27AE60'];
$breakdownChart=['labels'=>array_column($series,'label'),'datasets'=>[]];
foreach(['Fuel'=>'fuel','Maintenance'=>'maintenance','Toll'=>'toll'] as $category=>$key) {
    $values=array_map('floatval',array_column($series,$key));
    if(array_sum($values)>0) $breakdownChart['datasets'][]=['label'=>$category,'data'=>$values,'backgroundColor'=>$breakdownColors[$category],'borderRadius'=>4,'maxBarThickness'=>36,'barPercentage'=>0.7,'categoryPercentage'=>0.8];
}
$rc=['labels'=>array_column($series,'label'),'datasets'=>[['label'=>'Revenue','data'=>array_map('floatval',array_column($series,'revenue')),'borderColor'=>'#27AE60','backgroundColor'=>'rgba(39,174,96,.1)'],['label'=>'Transportation cost','data'=>array_map('floatval',array_column($series,'cost')),'borderColor'=>'#2F80ED','backgroundColor'=>'rgba(47,128,237,.1)']]];
$active_page='cost-overview';$body_class=trim(($body_class??'').' cost-analysis-module cost-overview-page');$page_title='Transport Cost Analysis & Optimization';$include_chart=true;require ROOT_PATH.'/includes/header.php';?>
<div class="d-flex flex-wrap justify-content-between align-items-end mb-4 gap-3 cost-page-heading"><div><h1 class="mb-1">Transport Cost Analysis & Optimization</h1><p class="text-muted-custom mb-0">Recorded transportation costs and recognized revenue.</p></div><form method="get" class="d-flex flex-wrap align-items-end gap-2 cost-filter-form"><div><label class="tc-form-label small">From date</label><input class="tc-form-control" type="date" name="from" value="<?=e($range['from'])?>" required></div><div><label class="tc-form-label small">To date</label><input class="tc-form-control" type="date" name="to" value="<?=e($range['to'])?>" required></div><button class="tc-btn tc-btn-primary">Apply</button></form></div>
<div class="row g-3 mb-4"><?php foreach($cards as [$title,$amount,$change,$note]):?><div class="col-12 col-sm-6 col-xl-3"><div class="tc-card p-3 h-100"><div class="small text-muted-custom text-uppercase fw-semibold"><?=e($title)?></div><div class="fs-4 fw-bold mt-2"><?=money($amount)?></div><div class="small text-muted-custom mt-2"><?=e(cost_comparison_label($change))?></div><div class="small text-muted-custom mt-1"><?=e($note)?></div></div></div><?php endforeach;?></div>
<div class="row g-3 mb-4"><div class="col-lg-5"><div class="tc-card p-3 h-100 cost-breakdown-panel">
  <h2 class="fs-6 fw-bold mb-1">Cost Breakdown</h2>
  <p class="small text-muted-custom mb-3">Monthly recorded expenses by category</p>
  <?php if($breakdown): ?>
  <div class="cost-breakdown-plot"><canvas id="breakdown" role="img" aria-label="Recorded transportation costs by category"></canvas></div>
  <div class="cost-breakdown-legend">
    <?php foreach($breakdown as $i => $r): $share = $summary['cost'] > 0 ? 100 * (float)$r['amount'] / $summary['cost'] : 0; ?>
    <div class="cost-breakdown-row">
      <span class="cost-breakdown-category"><i style="background:<?=e($breakdownColors[$r['category']] ?? '#64748B')?>"></i><?=e($r['category'])?></span>
      <span class="cost-breakdown-amount"><strong><?=money($r['amount'])?></strong><small><?=number_format($share,1)?>%</small></span>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="d-flex justify-content-between border-top pt-3 mt-2"><strong>Total</strong><strong><?=money($summary['cost'])?></strong></div>
  <?php else: ?><div class="text-center text-muted-custom py-5">No transportation cost records for this period.</div><?php endif; ?>
</div></div><div class="col-lg-7"><div class="tc-card cost-revenue-card h-100">
  <div class="cost-revenue-heading">
    <div><h2>Revenue vs Transportation Cost</h2><p>Monthly performance &middot; <?=e(date('M j, Y',strtotime($range['from'])))?> &ndash; <?=e(date('M j, Y',strtotime($range['to'])))?></p></div>
    <span class="cost-chart-period">Monthly</span>
  </div>
  <div class="cost-chart-totals">
    <div><span class="cost-chart-label"><i class="cost-chart-dot revenue"></i>Revenue</span><strong><?=money($summary['revenue'])?></strong></div>
    <div><span class="cost-chart-label"><i class="cost-chart-dot expense"></i>Transportation cost</span><strong><?=money($summary['cost'])?></strong></div>
  </div>
  <?php if(count($series)===1):?><p class="cost-chart-note">Select more than one month to compare the trend.</p><?php endif;?>
  <div class="cost-revenue-plot"><canvas id="revenue-cost" role="img" aria-label="Monthly revenue and transportation costs for the selected period"></canvas></div>
  <div class="cost-chart-footnote">Revenue: confirmed fares from completed trips. Costs: recorded operating expenses.</div>
</div></div></div>
<div class="tc-card"><div class="tc-card-header"><h2 class="fs-6 fw-bold mb-0">Recent Trip Costs</h2></div><div class="tc-table-container border-0"><table class="tc-table"><thead><tr><th>Date</th><th>Trip</th><th>Route</th><th>Vehicle</th><th>Fuel</th><th>Other recorded cost</th><th>Total</th><th>Revenue</th><th>Margin</th></tr></thead><tbody><?php if(!$recent):?><tr><td colspan="9" class="text-center text-muted-custom py-4">No completed trips available for this period.</td></tr><?php else:foreach($recent as $r):$margin=(float)$r['revenue']-(float)$r['cost'];?><tr><td><?=e(date('M j, Y',strtotime($r['actual_arrival'])))?></td><td><a href="<?=BASE_URL?>/trip-details.php?id=<?=urlencode($r['id'])?>"><?=e($r['id'])?></a></td><td><?=e($r['origin'].' → '.$r['destination'])?></td><td><?=e(trim($r['plate_number'].' '.$r['vehicle']))?></td><td><?=money($r['fuel'])?></td><td><?=money($r['other'])?></td><td class="fw-semibold"><?=money($r['cost'])?></td><td><?=money($r['revenue'])?></td><td class="<?=$margin<0?'text-danger':'text-success'?>"><?=money($margin)?></td></tr><?php endforeach;endif;?></tbody></table></div></div>
<?php
$page_scripts='<script>window.TC_REVENUE_COST_DATA='.json_encode($rc,JSON_UNESCAPED_UNICODE).';window.TC_COST_BREAKDOWN_DATA='.json_encode($breakdownChart,JSON_UNESCAPED_UNICODE).';</script><script src="'.BASE_URL.'/js/cost-breakdown.js?v='.(int)filemtime(ROOT_PATH.'/js/cost-breakdown.js').'"></script><script src="'.BASE_URL.'/js/cost-analysis.js?v='.(int)filemtime(ROOT_PATH.'/js/cost-analysis.js').'"></script>';
require ROOT_PATH.'/includes/footer.php';
?>
