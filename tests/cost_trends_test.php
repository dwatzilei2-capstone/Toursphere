<?php
require_once __DIR__.'/../includes/cost_trends.php';
function ct_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$rows=ct_source(null,true);ct_check(count($rows)===94,'94 isolated source records');
$f=ct_filters(['year1'=>'2025','year2'=>'2026','from'=>'2023-01-01','to'=>'2023-12-31']);$selected=ct_rows($rows,$f,'2026-10-15');ct_check(count($selected)===93,'Cancelled excluded and year selectors control table');
$months=ct_months($selected,'2026-01-01','2026-12-31','2026-10-15');$shared=vc_preview();$expected=0;foreach($shared['deliveries'] as $r)if(str_starts_with($r['date'],'2026'))$expected+=$r['fuel']+$r['maintenance']+$r['toll']+$r['other'];
ct_check(abs(array_sum(array_column($months,'cost'))*100-$expected)<.0001,'Reconcile shared Cost by Vehicle operating costs');
foreach($months as $m){if($m['cost']!==null)ct_check(abs(($m['fuel']??0)+($m['maintenance']??0)+($m['toll']??0)+($m['other']??0)-$m['cost'])<.0001,'Category stacks reconcile');if($m['eligible_count'])ct_check(abs($m['delivery']-$m['eligible_cost']/$m['eligible_count']/100)<.00001,'Completed-delivery efficiency');if($m['distance'])ct_check(abs($m['km']-$m['distance_cost']/$m['distance']/100)<.00001,'Distance-matched cost per km');}
ct_check($months[9]['cost']===null&&$months[11]['cost']===null,'Missing/future months not zero');
ct_check(array_sum(array_column($months,'eligible_count'))===54,'Pending and unrecorded costs excluded from efficiency denominator');
$missing=ct_months($rows,'2023-01-01','2023-12-31','2026-10-15');ct_check(array_filter(array_column($missing,'cost'),fn($v)=>$v!==null)===[],'No fabricated 2023 records');
$same=ct_rows($rows,ct_filters(['year1'=>'2025','year2'=>'2025']),'2026-10-15');ct_check(count($same)===37,'Same-year deduplicated');
$ends=ct_rows($rows,ct_filters(['year1'=>'2023','year2'=>'2026']),'2026-10-15');ct_check(count($ends)===56&&!in_array('2025',array_map(fn($r)=>substr($r['date'],0,4),$ends),true),'Endpoint years only');
$monthly=ct_rows($rows,ct_filters(['comparison'=>'monthly','from'=>'2026-09-01','to'=>'2026-09-30']),'2026-10-15');ct_check(count($monthly)===8,'Inclusive monthly dates');
foreach([10,20,50] as $size){$page=ct_page($selected,$size,2);ct_check(count($page['rows'])===min($size,93-$size),'Actual dataset pagination');ct_check($page['start']===$size+1,'Page boundaries');}
ct_check(ct_page($selected,10,999)['page']===10,'Out-of-range page clamps');
$pending=array_values(array_filter($rows,fn($r)=>$r['id']==='TEST-CT-P02'))[0];ct_check(ct_balance($pending)['balance']===null&&ct_balance($pending)['status']==='Pending Expense Recording','No assumed actual expense');
foreach($shared['deliveries'] as $r){$r['status']='Completed';$b=ct_balance($r);if($r['funding']===null)ct_check($b['balance']===null,'Unfunded comparison unavailable');else ct_check($b['balance']===$r['funding']-ct_cost($r),'Actual allowance balance');}
$duplicate=array_merge($selected,[$selected[0]]);ct_check(count(ct_rows($duplicate,$f,'2026-10-15'))===93,'No double-counted delivery');
ct_check(ct_filters(['year1'=>['bad'],'size'=>'500','comparison'=>'bad'])['size']===10,'Invalid inputs safe');
echo "Cost Trends financial, shared-data reconciliation, date, efficiency, allowance and pagination checks passed.\n";
