<?php
/** Cost Overview only. Read-only financial sources, integer-cent amounts. */
function overview_test_rows(): array {
 $rows=json_decode(file_get_contents(__DIR__.'/../database/preview/cost-overview.json'),true,512,JSON_THROW_ON_ERROR);
 foreach($rows as &$r){$r['status']=$r['status']??'Completed';$r['other']=$r['other']??null;$r['funding_status']=$r['funding']===null?'Unfunded':'Funding Confirmed';$r['expense_complete']=$r['cost_recorded'];$r['distance']=$r['distance']??null;}unset($r);return $rows;
}
function overview_live_rows(PDO $pdo,?string $from=null,?string $to=null): array {
 $sql="SELECT t.id,COALESCE(t.actual_arrival,t.scheduled_departure)::date date,t.status,t.origin,t.destination,
 v.plate_number||' '||v.brand||' '||v.model vehicle,f.cost fuel,NULL::numeric maintenance,t.toll_fee toll,NULL::numeric other,
 (f.cost IS NOT NULL OR t.toll_fee IS NOT NULL) cost_recorded,
 (t.status='Completed' AND f.cost IS NOT NULL AND t.toll_fee IS NOT NULL AND NOT EXISTS(SELECT 1 FROM maintenance_orders mo WHERE mo.source_trip_id=t.id AND mo.status='Completed') AND NOT EXISTS(SELECT 1 FROM fuel_transactions pending WHERE pending.trip_id=t.id AND pending.vehicle_id=t.vehicle_id AND pending.verification_status NOT IN ('Verified','Rejected') AND pending.transaction_date<=CURRENT_TIMESTAMP)) expense_complete,
 e.estimated_total_cost estimate,b.approved_amount funding,b.status funding_status,
 CASE WHEN t.final_odometer>NULLIF(to_jsonb(t)->>'departure_odometer','')::numeric THEN t.final_odometer-NULLIF(to_jsonb(t)->>'departure_odometer','')::numeric END distance
 FROM trips t JOIN vehicles v ON v.id=t.vehicle_id
 LEFT JOIN LATERAL(SELECT SUM(total_cost) cost FROM fuel_transactions WHERE trip_id=t.id AND vehicle_id=t.vehicle_id AND verification_status='Verified' AND transaction_date<=CURRENT_TIMESTAMP) f ON true
 LEFT JOIN LATERAL(SELECT approved_amount,estimate_id,status FROM trip_funding_requests WHERE trip_id=t.id AND status='Funding Confirmed' AND (approved_at IS NULL OR approved_at<=CURRENT_TIMESTAMP) ORDER BY id DESC LIMIT 1) b ON true
 LEFT JOIN LATERAL(SELECT estimated_total_cost FROM trip_cost_estimates WHERE trip_id=t.id AND (id=b.estimate_id OR b.estimate_id IS NULL) ORDER BY id DESC LIMIT 1) e ON true
 WHERE (to_jsonb(v)->>'service_category'='Cargo' OR COALESCE((to_jsonb(v)->>'payload_capacity_kg')::numeric,0)>0)
 AND t.status NOT IN ('Cancelled','Canceled') AND (t.status<>'Completed' OR t.actual_arrival IS NOT NULL)
 AND COALESCE(t.actual_arrival,t.scheduled_departure)<=CURRENT_TIMESTAMP
 AND COALESCE(t.actual_arrival,t.scheduled_departure)::date>=?::date AND COALESCE(t.actual_arrival,t.scheduled_departure)::date<=?::date";
 $stmt=$pdo->prepare($sql);$stmt->execute([$from??'2000-01-01',$to??date('Y-m-d')]);$rows=$stmt->fetchAll();
 foreach($rows as &$r){foreach(['fuel','maintenance','toll','other','estimate','funding'] as $key)$r[$key]=$r[$key]===null?null:(int)round((float)$r[$key]*100);foreach(['cost_recorded','expense_complete'] as $key)$r[$key]=in_array($r[$key],[true,1,'1','t'],true);$r['distance']=$r['distance']===null?null:(float)$r['distance'];}unset($r);return $rows;
}
function overview_period(array $rows,string $from,string $to,?string $today=null): array {
 $to=min($to,$today??date('Y-m-d'));
 $blank=['fuel'=>null,'maintenance'=>null,'toll'=>null,'other'=>null,'cost'=>null,'allowance'=>null,'remaining'=>null,'count'=>0,'recorded_count'=>0,'scope_count'=>0,'funded_count'=>0,'comparable_count'=>0,'completed_cost'=>null,'completed_recorded_count'=>0,'comparable_funding'=>0,'comparable_cost'=>0,'distance'=>0,'distance_cost'=>0];
 $result=$blank+['rows'=>[],'months'=>[]];$cursor=new DateTimeImmutable(substr($from,0,7).'-01');
 while($cursor->format('Y-m-d')<=$to){$result['months'][$cursor->format('Y-m')]=$blank+['label'=>$cursor->format('M Y')];$cursor=$cursor->modify('+1 month');}
 $seen=[];foreach($rows as $r){if(isset($seen[$r['id']])||$r['date']<$from||$r['date']>$to||in_array($r['status']??'Completed',['Cancelled','Canceled'],true))continue;$seen[$r['id']]=true;
  $r['status']=$r['status']??'Completed';$r['other']=$r['other']??null;$r['cost']=null;
  if($r['cost_recorded'])foreach(['fuel','maintenance','toll','other'] as $key)if($r[$key]!==null)$r['cost']=($r['cost']??0)+$r[$key];
  $approved=$r['funding']!==null&&($r['funding_status']??'Funding Confirmed')==='Funding Confirmed';if(!$approved)$r['funding']=null;
  $complete=$r['status']==='Completed'&&$r['cost']!==null&&($r['expense_complete']??$r['cost_recorded']);
  $r['expense_complete']=$complete;$r['variance']=$complete&&$r['estimate']!==null?$r['cost']-$r['estimate']:null;
  $r['remaining']=$approved&&$complete?$r['funding']-$r['cost']:null;
  $result['rows'][]=$r;$key=substr($r['date'],0,7);
  foreach([&$result,&$result['months'][$key]] as &$target){$target['scope_count']++;if($r['status']==='Completed')$target['count']++;
   if($r['cost']!==null){$target['recorded_count']++;$target['cost']=($target['cost']??0)+$r['cost'];foreach(['fuel','maintenance','toll','other'] as $category)if($r[$category]!==null)$target[$category]=($target[$category]??0)+$r[$category];if($r['status']==='Completed'){$target['completed_recorded_count']++;$target['completed_cost']=($target['completed_cost']??0)+$r['cost'];}}
   if($approved){$target['funded_count']++;$target['allowance']=($target['allowance']??0)+$r['funding'];}
   if($r['remaining']!==null){$target['comparable_count']++;$target['remaining']=($target['remaining']??0)+$r['remaining'];$target['comparable_funding']+=$r['funding'];$target['comparable_cost']+=$r['cost'];}
   if($complete&&($r['distance']??0)>0){$target['distance']+=$r['distance'];$target['distance_cost']+=$r['cost'];}
  }unset($target);
 }
 foreach($result['months'] as &$month){$month['average']=$month['count']&&$month['completed_cost']!==null?$month['completed_cost']/$month['count']:null;$month['utilization']=$month['comparable_funding']>0?100*$month['comparable_cost']/$month['comparable_funding']:null;}unset($month);
 $result['average']=$result['count']&&$result['completed_cost']!==null?$result['completed_cost']/$result['count']:null;
 $result['utilization']=$result['comparable_funding']>0?100*$result['comparable_cost']/$result['comparable_funding']:null;
 $result['cost_km']=$result['distance']>0?$result['distance_cost']/$result['distance']:null;
 usort($result['rows'],fn($a,$b)=>strcmp($b['date'].$b['id'],$a['date'].$a['id']));return $result;
}
function overview_previous_date(string $date): string {
 $d=new DateTimeImmutable($date);$year=(int)$d->format('Y')-1;$month=(int)$d->format('m');$day=min((int)$d->format('d'),(int)(new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month)))->format('t'));return sprintf('%04d-%02d-%02d',$year,$month,$day);
}
function overview_change($current,$previous): ?float {return $current===null||$previous===null||$previous==0?null:100*($current-$previous)/abs($previous);}
function overview_insights(array $now,array $previous): array {
 $cards=[];
 foreach(['fuel'=>['Fuel Cost Analysis','⛽','Review fuel use per delivery and consolidate compatible cargo routes.','Maintain efficient routing and monitor verified fuel costs per delivery.'], 'maintenance'=>['Maintenance Cost Analysis','⚒','Review verified service invoices and recurring maintenance needs.','Continue preventive maintenance and verify actual service invoices.'], 'toll'=>['Toll Cost Analysis','▰','Compare toll routes against delivery times and recorded fuel savings.','Maintain economical routes while meeting delivery schedules.']] as $key=>[$title,$icon,$higher,$lower]){
  $change=overview_change($now[$key],$previous[$key]);$current=$now[$key];$prior=$previous[$key];$enough=$current!==null&&$prior!==null&&$change!==null;
  $detail=$current===null?'No verified expense records are available.':sprintf('Verified costs: ₱%s. %s',number_format($current/100,2),$prior===null?'No comparable historical expense records.':'Comparison period: ₱'.number_format($prior/100,2).'.');
  $cards[]=['title'=>$title,'icon'=>$icon,'change'=>$change,'bad'=>$change!==null&&$change>=5,'detail'=>$detail,'recommendation'=>!$enough?'Insufficient Historical Data. Record comparable verified expenses before drawing a conclusion.':($change>=5?$higher:$lower)];
 }
 $change=overview_change($now['average'],$previous['average']);
 $detail=$now['average']===null?'No eligible completed-delivery costs are available.':sprintf('Average cost per completed delivery: ₱%s. %d completed deliveries.',number_format($now['average']/100,2),$now['count']);
 if($now['cost_km']!==null)$detail.=' Recorded distance cost: ₱'.number_format($now['cost_km']/100,2).'/km.';
 $cards[]=['title'=>'Overall Transportation Cost Efficiency','icon'=>'▥','change'=>$change,'bad'=>$change!==null&&$change>0,'detail'=>$detail,'recommendation'=>$change===null?'Insufficient Historical Data. Record verified delivery costs and completed delivery counts in both periods.':($change>0?'Review route consolidation, load utilization and verified cost per completed delivery.':'Maintain route consolidation and monitor verified cost per completed delivery.')];return $cards;
}

/** Single-year selections retain prior-year comparison. Different selected years
 * compare only the endpoint years; intervening years never enter totals. */
function overview_date_plan(string $from,string $to,?string $today=null): array {
    $today??=date('Y-m-d');$effectiveTo=min($to,$today);
    $single=substr($from,0,4)===substr($to,0,4);
    if(!$single) {
        $firstYear=substr($from,0,4);$lastYear=substr($to,0,4);
        $currentFrom=$lastYear.'-01-01';$previousTo=min($firstYear.'-12-31',$today);
        $buckets=[];
        for($month=1;$month<=12;$month++) {
            $current=new DateTimeImmutable(sprintf('%s-%02d-01',$lastYear,$month));
            $previous=new DateTimeImmutable(sprintf('%s-%02d-01',$firstYear,$month));
            $buckets[]=['label'=>$current->format('M'),'from'=>$current->format('Y-m-d'),
              'to'=>min($current->format('Y-m-t'),$effectiveTo),
              'previous_from'=>max($previous->format('Y-m-d'),$from),
              'previous_to'=>min($previous->format('Y-m-t'),$previousTo)];
        }
        return ['from'=>$currentFrom,'to'=>$effectiveTo,'previous_from'=>$from,'previous_to'=>$previousTo,
          'granularity'=>'Monthly','single'=>false,'buckets'=>$buckets,'cutoff'=>$today];
    }
    $monthCount=((int)substr($to,0,4)-(int)substr($from,0,4))*12+(int)substr($to,5,2)-(int)substr($from,5,2)+1;
    $granularity=$single?'Monthly':($monthCount<=36?'Quarterly':'Yearly');
    $end=new DateTimeImmutable(max($from,$effectiveTo));$start=new DateTimeImmutable($from);
    $days=(int)$start->diff($end)->days+1;
    $previousFrom=$single?overview_previous_date($from):$start->modify('-'.$days.' days')->format('Y-m-d');
    $previousTo=$single?overview_previous_date(max($from,$effectiveTo)):$start->modify('-1 day')->format('Y-m-d');
    $buckets=[];$cursor=$start;
    while($cursor->format('Y-m-d')<=$effectiveTo) {
        if($granularity==='Monthly'){$next=$cursor->modify('first day of next month');$label=$cursor->format('M Y');}
        elseif($granularity==='Quarterly'){$q=(int)floor(((int)$cursor->format('n')-1)/3)+1;$next=(new DateTimeImmutable($cursor->format('Y').'-'.sprintf('%02d',($q-1)*3+1).'-01'))->modify('+3 months');$label='Q'.$q.' '.$cursor->format('Y');}
        else{$next=new DateTimeImmutable(((int)$cursor->format('Y')+1).'-01-01');$label=$cursor->format('Y');}
        $bucketTo=min($next->modify('-1 day')->format('Y-m-d'),$effectiveTo);
        $bucketFrom=$cursor->format('Y-m-d');
        $buckets[]=['label'=>$label,'from'=>$bucketFrom,'to'=>$bucketTo,
          'previous_from'=>$single?overview_previous_date($bucketFrom):$cursor->modify('-'.$days.' days')->format('Y-m-d'),
          'previous_to'=>$single?overview_previous_date($bucketTo):(new DateTimeImmutable($bucketTo))->modify('-'.$days.' days')->format('Y-m-d')];
        $cursor=$next;
    }
    return ['from'=>$from,'to'=>$effectiveTo,'previous_from'=>$previousFrom,'previous_to'=>$previousTo,'granularity'=>$granularity,'single'=>$single,'buckets'=>$buckets,'cutoff'=>$today];
}
function overview_bucket_series(array $period,array $buckets,bool $previous=false): array {
    $series=[];
    foreach($buckets as $bucket) {
        $from=$bucket[$previous?'previous_from':'from'];$to=$bucket[$previous?'previous_to':'to'];
        $part=overview_period($period['rows'],$from,$to);
        $series[]=['label'=>$bucket['label'],'dates'=>$from>$to?'No eligible dates (outside selected dates or in the future)':$from.' – '.$to,
          'cost'=>$part['cost']===null?null:$part['cost']/100,'allowance'=>$part['allowance']===null?null:$part['allowance']/100,
          'average'=>$part['average']===null?null:$part['average']/100,'utilization'=>$part['utilization'],'comparable_count'=>$part['comparable_count'],
          'remaining'=>$part['remaining']===null?null:$part['remaining']/100,'count'=>$part['count'],
          'fuel'=>$part['fuel']===null?null:$part['fuel']/100,'maintenance'=>$part['maintenance']===null?null:$part['maintenance']/100,
          'toll'=>$part['toll']===null?null:$part['toll']/100,'other'=>$part['other']===null?null:$part['other']/100];
    }
    return $series;
}
