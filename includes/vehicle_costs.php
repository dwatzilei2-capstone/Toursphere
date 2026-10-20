<?php
/** Read-only Cost by Vehicle calculations. Money is integer Philippine centavos. */
function vc_preview(): array {
    return json_decode(file_get_contents(__DIR__.'/../database/preview/cost-by-vehicle.json'),true,512,JSON_THROW_ON_ERROR);
}
function vc_live(PDO $pdo): array {
    $vehicles=$pdo->query("SELECT v.*,COALESCE(to_jsonb(v)->>'acquisition_date',to_jsonb(v)->>'acquired_date') acquired FROM vehicles v WHERE to_jsonb(v)->>'service_category'='Cargo' OR COALESCE((to_jsonb(v)->>'payload_capacity_kg')::numeric,0)>0 ORDER BY plate_number")->fetchAll();
    // Completed reservation charges are recognized once across all linked deliveries.
    // Estimated maintenance orders and approved funding are never actual expenses.
    $sql="WITH completed AS (SELECT t.*,ROW_NUMBER() OVER(PARTITION BY reservation_id ORDER BY actual_arrival,id) revenue_rank
      FROM trips t WHERE status='Completed' AND actual_arrival<=CURRENT_TIMESTAMP)
      SELECT t.id,t.vehicle_id,t.actual_arrival::date date,
      CASE WHEN r.status='Completed' AND r.fare_status='Confirmed' AND t.revenue_rank=1 THEN COALESCE(r.total_booking_fare,0) ELSE 0 END revenue,
      f.fuel,NULL::numeric maintenance,t.toll_fee toll,NULL::numeric other,
      e.estimated_total_cost estimate,b.approved_amount funding,
      (f.fuel IS NOT NULL AND t.toll_fee IS NOT NULL
       AND NOT EXISTS(SELECT 1 FROM maintenance_orders mo WHERE mo.source_trip_id=t.id AND mo.status='Completed')
       AND NOT EXISTS(SELECT 1 FROM fuel_transactions pending WHERE pending.trip_id=t.id AND pending.vehicle_id=t.vehicle_id AND pending.verification_status NOT IN ('Verified','Rejected') AND pending.transaction_date<=CURRENT_TIMESTAMP)) expense_complete,
      CASE WHEN t.final_odometer>=t.departure_odometer AND t.final_odometer>0 THEN t.final_odometer-t.departure_odometer END distance,
      CASE WHEN rh.actual_fuel_verified=1 AND rh.actual_fuel_liters>0 AND EXISTS(SELECT 1 FROM fuel_transactions vf WHERE vf.id=rh.actual_fuel_transaction_id AND vf.trip_id=t.id AND vf.vehicle_id=t.vehicle_id AND vf.verification_status='Verified' AND vf.transaction_date<=CURRENT_TIMESTAMP) THEN rh.actual_fuel_liters END consumption
      FROM completed t LEFT JOIN reservations r ON r.id=t.reservation_id
      LEFT JOIN LATERAL(SELECT SUM(total_cost) fuel FROM fuel_transactions WHERE trip_id=t.id AND vehicle_id=t.vehicle_id AND verification_status='Verified' AND transaction_date<=CURRENT_TIMESTAMP) f ON true
      LEFT JOIN LATERAL(SELECT approved_amount,estimate_id FROM trip_funding_requests WHERE trip_id=t.id AND status='Funding Confirmed' AND (approved_at IS NULL OR approved_at<=CURRENT_TIMESTAMP) ORDER BY id DESC LIMIT 1) b ON true
      LEFT JOIN LATERAL(SELECT estimated_total_cost FROM trip_cost_estimates WHERE trip_id=t.id AND (id=b.estimate_id OR b.estimate_id IS NULL) ORDER BY id DESC LIMIT 1) e ON true
      LEFT JOIN route_history rh ON rh.log_id=t.route_history_id";
    // Some deployments have no departure odometer column; use JSON for optional evidence.
    $sql=str_replace('t.departure_odometer',"NULLIF(to_jsonb(t)->>'departure_odometer','')::numeric",$sql);
    $rows=$pdo->query($sql)->fetchAll();
    foreach($rows as &$r){$r['expense_complete']=in_array($r['expense_complete'],[true,1,'1','t'],true);foreach(['revenue','fuel','maintenance','toll','other','estimate','funding'] as $key)$r[$key]=$r[$key]===null?null:(int)round((float)$r[$key]*100);foreach(['distance','consumption'] as $key)$r[$key]=$r[$key]===null?null:(float)$r[$key];}unset($r);
    return ['vehicles'=>$vehicles,'deliveries'=>$rows];
}
function vc_period(array $rows,string $vehicle,string $from,string $to,?string $today=null): array {
    $to=min($to,$today??date('Y-m-d'));
    $out=['count'=>0,'revenue'=>0,'fuel'=>null,'maintenance'=>null,'toll'=>null,'other'=>null,'cost'=>null,'estimate'=>null,'funding'=>null,'distance'=>null,'consumption'=>null,'funded_cost'=>null,'funded_count'=>0,'unfunded_count'=>0,'fuel_distance'=>0,'consumption_distance'=>0];
    $out+=['allowance'=>null,'allowance_balance'=>null,'utilization'=>null,'comparable_allowance'=>0,'comparable_expenses'=>0,'comparable_count'=>0,'eligible_cost_km'=>null];$eligibleDistance=0;$eligibleDistanceCost=0;
    $distanceCount=0;$consumptionCount=0;$fuelDistanceCount=0;$seen=[];$fundedActualCount=0;$estimateCount=0;
    foreach($rows as $r){
        if($r['vehicle_id']!==$vehicle||$r['date']<$from||$r['date']>$to||isset($seen[$r['id']]))continue;
        $seen[$r['id']]=true;$out['count']++;$out['revenue']+=$r['revenue'];$actual=null;
        foreach(['fuel','maintenance','toll','other'] as $key)if($r[$key]!==null){$out[$key]=($out[$key]??0)+$r[$key];$actual=($actual??0)+$r[$key];}
        $complete=$actual!==null&&($r['status']??'Completed')==='Completed'&&($r['expense_complete']??($r['fuel']!==null&&$r['toll']!==null));
        $approved=$r['funding']!==null&&($r['funding_status']??'Funding Confirmed')==='Funding Confirmed';
        if($approved)$out['allowance']=($out['allowance']??0)+$r['funding'];
        if($approved&&$complete){$out['comparable_count']++;$out['comparable_allowance']+=$r['funding'];$out['comparable_expenses']+=$actual;}
        if($complete&&$r['distance']!==null&&$r['distance']>0){$eligibleDistance+=$r['distance'];$eligibleDistanceCost+=$actual;}
        if($actual!==null)$out['cost']=($out['cost']??0)+$actual;
        if($r['estimate']!==null){$estimateCount++;$out['estimate']=($out['estimate']??0)+$r['estimate'];}
        if($r['funding']!==null){$out['funded_count']++;$out['funding']=($out['funding']??0)+$r['funding'];if($actual!==null){$fundedActualCount++;$out['funded_cost']=($out['funded_cost']??0)+$actual;}}else{$out['unfunded_count']++;}
        if($r['distance']!==null&&$r['distance']>0){$distanceCount++;$out['distance']=($out['distance']??0)+$r['distance'];if($r['fuel']!==null){$fuelDistanceCount++;$out['fuel_distance']+=$r['distance'];}
            if($r['consumption']!==null&&$r['consumption']>0){$consumptionCount++;$out['consumption']=($out['consumption']??0)+$r['consumption'];$out['consumption_distance']+=$r['distance'];}}
    }
    // Do not divide partial distance/consumption evidence into full-period expense totals.
    if($distanceCount!==$out['count'])$out['distance']=null;
    $out['cost_km']=$out['distance']>0&&$out['cost']!==null?$out['cost']/$out['distance']:null;
    $out['fuel_km']=$out['distance']>0&&$out['fuel']!==null&&$fuelDistanceCount===$out['count']?$out['fuel']/$out['distance']:null;
    $out['km_l']=$consumptionCount===$out['count']&&$out['consumption']>0?$out['consumption_distance']/$out['consumption']:null;
    $out['profit']=$out['count']&&$out['cost']!==null?$out['revenue']-$out['cost']:null;
    $out['margin']=$out['revenue']&&$out['profit']!==null?100*$out['profit']/$out['revenue']:null;
    $out['average']=$out['count']&&$out['cost']!==null?$out['cost']/$out['count']:null;
    $out['toll_other_average']=$out['count']&&($out['toll']!==null||$out['other']!==null)?(($out['toll']??0)+($out['other']??0))/$out['count']:null;
    $out['remaining']=$out['count']&&$out['funded_count']===$out['count']&&$fundedActualCount===$out['count']?$out['funding']-$out['cost']:null;
    $out['allowance_balance']=$out['comparable_count']?$out['comparable_allowance']-$out['comparable_expenses']:null;
    $out['utilization']=$out['comparable_count']&&$out['comparable_allowance']>0?100*$out['comparable_expenses']/$out['comparable_allowance']:null;
    $out['eligible_cost_km']=$eligibleDistance>0?$eligibleDistanceCost/$eligibleDistance:null;
    $out['estimate_complete']=$estimateCount===$out['count'];
    return $out;
}
function vc_change($now,$previous): ?float {return $now===null||$previous===null||$previous==0?null:100*($now-$previous)/abs($previous);}
function vc_status($now,$previous,bool $higherBetter=false): string {
    $change=vc_change($now,$previous);if($change===null)return 'Insufficient Data';
    if(abs($change)<5)return 'Stable';return ($higherBetter?$change>0:$change<0)?'Improving':'Needs Attention';
}
function vc_comparable(?array $current,?array $previous,string $mode): bool {
    if(!$current||!$previous||!$current['count']||!$previous['count'])return false;
    if($mode==='yearly')return substr($current['from'],5)===substr($previous['from'],5)&&substr($current['to'],5)===substr($previous['to'],5);
    $full=static fn($p)=>substr($p['from'],8)==='01'&&$p['to']===(new DateTimeImmutable($p['to']))->format('Y-m-t');
    return ($full($current)&&$full($previous))||(substr($current['from'],8)===substr($previous['from'],8)&&substr($current['to'],8)===substr($previous['to'],8));
}
function vc_series(array $rows,string $id,string $from,string $to,string $mode='monthly'): array {
    $cursor=new DateTimeImmutable($mode==='yearly'?substr($from,0,4).'-01-01':substr($from,0,7).'-01');$series=[];
    while($cursor->format('Y-m-d')<=$to){$end=$mode==='yearly'?$cursor->format('Y').'-12-31':$cursor->format('Y-m-t');$start=max($from,$cursor->format('Y-m-d'));$end=min($end,$to);$value=vc_period($rows,$id,$start,$end);$value['label']=$cursor->format($mode==='yearly'?'Y':'M Y');$value['from']=$start;$value['to']=$end;$series[]=$value;$cursor=$cursor->modify($mode==='yearly'?'+1 year':'+1 month');}
    return $series;
}
