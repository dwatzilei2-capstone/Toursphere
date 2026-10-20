<?php
/** Cost Trends only; read-only sources and integer-cent financial calculations. */
function ct_source(?PDO $pdo,bool $preview): array {
    if($preview){
        require_once __DIR__.'/vehicle_costs.php';$base=vc_preview();$rows=$base['deliveries'];
        foreach($rows as &$r){$r['status']='Completed';$r['origin']='Cargo hub';$r['destination']='Delivery depot';}unset($r);
        $extra=json_decode(file_get_contents(__DIR__.'/../database/preview/cost-trends-cases.json'),true,512,JSON_THROW_ON_ERROR);
        return array_merge($rows,$extra);
    }
    if(!$pdo)throw new InvalidArgumentException('Live data requires a database connection.');
    $sql="SELECT t.id,t.vehicle_id,COALESCE(t.actual_arrival,t.scheduled_departure)::date date,t.status,t.origin,t.destination,
      f.fuel,NULL::numeric maintenance,t.toll_fee toll,NULL::numeric other,e.estimated_total_cost estimate,b.approved_amount funding,
      (EXISTS(SELECT 1 FROM maintenance_orders mo WHERE mo.source_trip_id=t.id AND mo.status='Completed') OR
       EXISTS(SELECT 1 FROM fuel_transactions pending WHERE pending.trip_id=t.id AND pending.vehicle_id=t.vehicle_id AND pending.verification_status NOT IN ('Verified','Rejected') AND pending.transaction_date<=CURRENT_TIMESTAMP)) expenses_pending,
      CASE WHEN t.final_odometer>NULLIF(to_jsonb(t)->>'departure_odometer','')::numeric THEN t.final_odometer-NULLIF(to_jsonb(t)->>'departure_odometer','')::numeric END distance
      FROM trips t JOIN vehicles v ON v.id=t.vehicle_id
      LEFT JOIN LATERAL(SELECT SUM(total_cost) fuel FROM fuel_transactions WHERE trip_id=t.id AND vehicle_id=t.vehicle_id AND verification_status='Verified' AND transaction_date<=CURRENT_TIMESTAMP) f ON true
      LEFT JOIN LATERAL(SELECT approved_amount,estimate_id FROM trip_funding_requests WHERE trip_id=t.id AND status='Funding Confirmed' AND (approved_at IS NULL OR approved_at<=CURRENT_TIMESTAMP) ORDER BY id DESC LIMIT 1) b ON true
      LEFT JOIN LATERAL(SELECT estimated_total_cost FROM trip_cost_estimates WHERE trip_id=t.id AND (id=b.estimate_id OR b.estimate_id IS NULL) ORDER BY id DESC LIMIT 1) e ON true
      WHERE (to_jsonb(v)->>'service_category'='Cargo' OR COALESCE((to_jsonb(v)->>'payload_capacity_kg')::numeric,0)>0)
      AND t.status NOT IN ('Cancelled','Canceled') AND COALESCE(t.actual_arrival,t.scheduled_departure)<=CURRENT_TIMESTAMP
      AND (t.status<>'Completed' OR t.actual_arrival IS NOT NULL)";
    $rows=$pdo->query($sql)->fetchAll();foreach($rows as &$r){foreach(['fuel','maintenance','toll','other','estimate','funding'] as $key)$r[$key]=$r[$key]===null?null:(int)round((float)$r[$key]*100);$r['distance']=$r['distance']===null?null:(float)$r['distance'];$r['expenses_pending']=in_array($r['expenses_pending'],[true,1,'1','t'],true);}unset($r);return $rows;
}
function ct_filters(array $input): array {
    require_once __DIR__.'/cost_analysis.php';
    $clean=[];foreach(['from','to','source','comparison','year1','year2','size','page'] as $key)if(isset($input[$key])&&is_scalar($input[$key]))$clean[$key]=(string)$input[$key];
    $range=cost_date_range($clean,date('Y-01-01'),date('Y-m-d'));
    $year=static function($v,$fallback){$n=filter_var($v,FILTER_VALIDATE_INT);return $n!==false&&$n>=2000&&$n<=2100?$n:$fallback;};
    return ['source'=>($clean['source']??'preview')==='live'?'live':'preview','comparison'=>($clean['comparison']??'yearly')==='monthly'?'monthly':'yearly',
      'year1'=>$year($clean['year1']??'',(int)date('Y')-1),'year2'=>$year($clean['year2']??'',(int)date('Y')),
      'from'=>$range['from'],'to'=>$range['to'],'size'=>in_array((int)($clean['size']??10),[10,20,50],true)?(int)($clean['size']??10):10,'page'=>max(1,min(100000,(int)($clean['page']??1)))];
}
function ct_cost(array $r): ?int {$sum=null;foreach(['fuel','maintenance','toll','other'] as $key)if($r[$key]!==null)$sum=($sum??0)+$r[$key];return $sum;}
function ct_complete(array $r): bool {return $r['status']==='Completed'&&$r['fuel']!==null&&$r['toll']!==null&&empty($r['expenses_pending']);}
function ct_rows(array $rows,array $filter,?string $today=null): array {
    $today??=date('Y-m-d');$out=[];
    foreach($rows as $r){if($r['date']>$today||in_array($r['status'],['Cancelled','Canceled'],true)||isset($out[$r['id']]))continue;
        $match=$filter['comparison']==='yearly'?in_array((int)substr($r['date'],0,4),[$filter['year1'],$filter['year2']],true):($r['date']>=$filter['from']&&$r['date']<=$filter['to']);
        if($match)$out[$r['id']]=$r;
    }
    $out=array_values($out);usort($out,fn($a,$b)=>strcmp($b['date'],$a['date'])?:strcmp($b['id'],$a['id']));return $out;
}
function ct_months(array $rows,string $from,string $to,?string $today=null): array {
    $today??=date('Y-m-d');$cursor=new DateTimeImmutable(substr($from,0,7).'-01');$months=[];
    while($cursor->format('Y-m-d')<=$to){$months[$cursor->format('Y-m')]=['label'=>$cursor->format('M Y'),'from'=>max($from,$cursor->format('Y-m-d')),'to'=>min($to,$cursor->format('Y-m-t')),'cost'=>null,'fuel'=>null,'maintenance'=>null,'toll'=>null,'other'=>null,'count'=>0,'eligible_cost'=>0,'eligible_count'=>0,'distance_cost'=>0,'distance'=>0];$cursor=$cursor->modify('+1 month');}
    $seen=[];foreach($rows as $r){if(isset($seen[$r['id']])||$r['status']!=='Completed'||$r['date']<$from||$r['date']>$to||$r['date']>$today)continue;$seen[$r['id']]=true;$key=substr($r['date'],0,7);$m=&$months[$key];$m['count']++;
        foreach(['fuel','maintenance','toll','other'] as $category)if($r[$category]!==null)$m[$category]=($m[$category]??0)+$r[$category];
        $cost=ct_cost($r);if($cost!==null)$m['cost']=($m['cost']??0)+$cost;
        if(ct_complete($r)){$m['eligible_cost']+=$cost;$m['eligible_count']++;if($r['distance']!==null&&$r['distance']>0){$m['distance']+=$r['distance'];$m['distance_cost']+=$cost;}}unset($m);
    }
    foreach($months as &$m){$m['delivery']=$m['eligible_count']?$m['eligible_cost']/$m['eligible_count']/100:null;$m['km']=$m['distance']>0?$m['distance_cost']/$m['distance']/100:null;foreach(['cost','fuel','maintenance','toll','other'] as $key)$m[$key]=$m[$key]===null?null:$m[$key]/100;}unset($m);return array_values($months);
}
function ct_balance(array $r): array {
    $cost=ct_cost($r);$balance=null;
    if(!ct_complete($r))$status='Pending Expense Recording';elseif($r['funding']===null)$status='Comparison Unavailable';
    else{$balance=$r['funding']-$cost;$status=$balance<0?'Over Allowance':($balance<=.05*$r['funding']?'Within Allowance':'Under Allowance');}
    return ['cost'=>$cost,'balance'=>$balance,'status'=>$status];
}
function ct_page(array $rows,int $size,int $page): array {$total=count($rows);$pages=max(1,(int)ceil($total/$size));$page=min(max(1,$page),$pages);return ['rows'=>array_slice($rows,($page-1)*$size,$size),'total'=>$total,'pages'=>$pages,'page'=>$page,'size'=>$size,'start'=>$total?($page-1)*$size+1:0,'end'=>min($page*$size,$total)];}
