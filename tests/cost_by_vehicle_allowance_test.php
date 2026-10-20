<?php
require __DIR__.'/../includes/vehicle_costs.php';
function allowance_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$fixture=vc_preview();$r=$fixture['deliveries'][0];$r['date']='2026-01-01';$r['funding']=10000;$r['fuel']=9000;$r['maintenance']=0;$r['toll']=2000;$r['other']=0;$r['distance']=100;$r['expense_complete']=true;
$period=fn($rows)=>vc_period($rows,$r['vehicle_id'],'2026-01-01','2026-01-31');
$t=$period([$r]);allowance_check($t['allowance_balance']===-1000&&$t['utilization']==110.0,'Shortfall and over allowance');allowance_check($t['eligible_cost_km']==110,'Verified cost per km');
$b=$r;$b['id'].='-second';$b['funding']=30000;$b['fuel']=1000;$b['toll']=0;
$t=$period([$r,$b,$r]);allowance_check($t['comparable_count']===2&&$t['utilization']==30.0&&$t['allowance_balance']===28000,'Weighted aggregate and deduplication');
$b['funding']=null;$t=$period([$r,$b]);allowance_check($t['utilization']==110.0&&$t['allowance_balance']===-1000,'Unfunded expenses excluded from comparison');
$r['expense_complete']=false;$t=$period([$r]);allowance_check($t['allowance_balance']===null&&$t['utilization']===null&&$t['eligible_cost_km']===null,'Incomplete expenses unavailable');
$r['expense_complete']=true;$r['distance']=null;allowance_check($period([$r])['eligible_cost_km']===null,'Missing distance unavailable');
$r['funding']=null;$t=$period([$r]);allowance_check($t['allowance']===null&&$t['allowance_balance']===null,'No fabricated funding');
$r['funding']=10000;$r['funding_status']='Pending';allowance_check($period([$r])['allowance']===null,'Unapproved funding excluded');
echo "Cost-only allowance and eligibility checks passed.\n";

