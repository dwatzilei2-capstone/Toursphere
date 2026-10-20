<?php
require_once __DIR__.'/../includes/vehicle_costs.php';
function check_vc($ok,$message){if(!$ok)throw new RuntimeException($message);}
$data=vc_preview();$rows=$data['deliveries'];check_vc(count($rows)===90,'90 isolated records');
check_vc(count(array_unique(array_column($rows,'id')))===90,'Unique delivery IDs');
$ids=array_column($data['vehicles'],'id');
foreach($rows as $r){check_vc($r['test_data']===true&&str_starts_with($r['id'],'TEST-VC-'),'Preview labels');check_vc(in_array($r['vehicle_id'],$ids,true),'Vehicle relationships');$vehicle=$data['vehicles'][array_search($r['vehicle_id'],$ids,true)];check_vc($r['date']>=$vehicle['acquired'],'Not before acquisition');}
foreach($ids as $id){
 $filtered=array_values(array_filter($rows,fn($r)=>$r['vehicle_id']===$id&&$r['date']>='2026-09-01'&&$r['date']<='2026-09-30'));
 $t=vc_period($rows,$id,'2026-09-01','2026-09-30');check_vc($t['count']===count($filtered),'Counts');
 if($filtered){$cost=array_sum(array_map(fn($r)=>$r['fuel']+$r['maintenance']+$r['toll']+$r['other'],$filtered));$revenue=array_sum(array_column($filtered,'revenue'));check_vc($t['cost']===$cost,'Independent costs');check_vc($t['profit']===$revenue-$cost,'Profit');check_vc(abs($t['margin']-100*($revenue-$cost)/$revenue)<.0001,'Margin');check_vc($t['average']===$cost/count($filtered),'Cost per delivery');if($t['funded_count']===$t['count'])check_vc($t['remaining']===$t['funding']-$cost,'Funding balance');
 $duplicated=array_merge($rows,[$filtered[0]]);check_vc(vc_period($duplicated,$id,'2026-09-01','2026-09-30')['cost']===$cost,'Duplicate-safe');}
 $months=vc_series($rows,$id,'2025-01-01','2026-09-30');$years=vc_series($rows,$id,'2025-01-01','2026-09-30','yearly');check_vc(array_sum(array_column($months,'count'))===array_sum(array_column($years,'count')),'Monthly/yearly reconcile');
 check_vc(vc_period($rows,$id,'2027-01-01','2027-12-31')['count']===0,'Future empty');
}
$a=vc_period($rows,$ids[0],'2026-09-01','2026-09-30');$b=vc_period($rows,$ids[0],'2026-08-01','2026-08-31');check_vc(vc_status($a['cost_km'],$b['cost_km'])==='Improving','Improving sample');
$a=vc_period($rows,$ids[1],'2026-09-01','2026-09-30');$b=vc_period($rows,$ids[1],'2026-08-01','2026-08-31');check_vc(vc_status($a['cost_km'],$b['cost_km'])==='Needs Attention','Worsening sample');
$a=vc_period($rows,$ids[3],'2026-09-01','2026-09-30');check_vc($a['distance']===null&&$a['km_l']===null,'No invented distance/consumption');
$missing=$rows[0];$missing['consumption']=null;check_vc(vc_period([$missing],$missing['vehicle_id'],$missing['date'],$missing['date'])['km_l']===null,'Purchased fuel is not actual consumption');
$missing['funding']=null;check_vc(vc_period([$missing],$missing['vehicle_id'],$missing['date'],$missing['date'])['remaining']===null,'Unfunded not zero budget');
$original=vc_period($rows,$ids[0],'2026-09-01','2026-09-30');foreach($rows as &$r)$r['funding']=999999999;unset($r);check_vc(vc_period($rows,$ids[0],'2026-09-01','2026-09-30')['cost']===$original['cost'],'Allowance never actual cost');
check_vc(vc_change(10,0)===null,'No false percentage');
$years=vc_series($data['deliveries'],$ids[0],'2025-01-01','2026-09-30','yearly');check_vc(!vc_comparable($years[1],$years[0],'yearly'),'Partial year not compared with full year');
echo "Cost by Vehicle financial and isolation checks passed.\n";
