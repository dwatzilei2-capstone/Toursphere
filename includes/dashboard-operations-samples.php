<?php
/** Explicitly synthetic preview records. Never inserted into operational tables. */
function dashboard_sample_deliveries(): array {
    $month=new DateTimeImmutable('first day of this month');
    $routes=[
        ['Manila Warehouse','Makati Distribution Center','Packaged groceries'],
        ['Quezon City Depot','Pasig Warehouse','Office supplies'],
        ['Pasay Cargo Hub','Taguig Distribution Center','Consumer goods'],
        ['Caloocan Depot','Marikina Warehouse','Household supplies'],
        ['Parañaque Warehouse','Las Piñas Distribution Center','Retail cartons'],
    ];
    $records=[];
    for($i=1;$i<=20;$i++){
        [$origin,$destination,$cargo]=$routes[($i-1)%count($routes)];
        $pickup=$month->modify('+'.($i-1).' days')->setTime(8+($i%3),0);
        $due=$pickup->modify('+3 hours');
        $status=$i<=12?'Completed':($i<=15?'Cancelled':'Assigned');
        $arrival=$status==='Completed'?$due->modify($i%4===0?'+35 minutes':'-15 minutes'):null;
        $records[]=[
            'reservation_id'=>sprintf('SAMPLE-RES-%03d',$i),'trip_id'=>sprintf('SAMPLE-TRP-%03d',$i),
            'external_request_id'=>sprintf('SAMPLE-CARGO-%03d',$i),'request_kind'=>'Cargo','cargo_type'=>$cargo.' (sample)',
            'origin'=>$origin,'destination'=>$destination,'departure'=>$pickup->format('Y-m-d H:i:s'),
            'return_date'=>$due->format('Y-m-d'),'return_time'=>$due->format('H:i:s'),
            'status'=>$status,'plate_number'=>sprintf('SAMPLE-VAN-%02d',1+(($i-1)%4)),
            'driver_name'=>'Sample Driver '.(1+(($i-1)%4)),
            'sample_arrival'=>$arrival?->format('Y-m-d H:i:s'),
            'sample_cancelled_at'=>$status==='Cancelled'?$pickup->modify('-1 hour')->format('Y-m-d H:i:s'):null,
        ];
    }
    return $records;
}

function dashboard_sample_calendar(string $start,string $end): array {
    return ['events'=>array_values(array_filter(dashboard_sample_deliveries(),static fn($row)=>$row['departure']>=$start&&$row['departure']<$end)),'truncated'=>false,'sample'=>true];
}

/** Dense fictional analytics fixtures with quiet days, peaks and hourly variation. */
function dashboard_sample_trend_records(DateTimeImmutable $start,DateTimeImmutable $end): array {
    $records=[];
    for($day=$start;$day<$end;$day=$day->modify('+1 day')){
        $n=(int)$day->format('z');
        $wave=(sin($n*.46)+sin($n*.19+1)+2)/4;
        $completed=5+(int)round(38*$wave);
        $assigned=2+($n%8);
        $cancelled=1+(int)round(7*(sin($n*.7)+1)/2);
        for($i=0;$i<$completed+$assigned+$cancelled;$i++){
            $status=$i<$completed?'Completed':($i<$completed+$assigned?'Assigned':'Cancelled');
            $pickup=$day->setTime([6,8,9,10,10,10,11,12,13,13,14,15,16,17][($i+$n)%14],($i%4)*15);
            $due=$pickup->modify('+2 hours');
            $onTime=($i%10)<(6+$n%3);
            $records[]=[
                'departure'=>$pickup->format('Y-m-d H:i:s'),'status'=>$status,
                'return_date'=>$due->format('Y-m-d'),'return_time'=>$due->format('H:i:s'),
                'sample_arrival'=>$status==='Completed'?$due->modify($onTime?'-15 minutes':'+30 minutes')->format('Y-m-d H:i:s'):null,
                'sample_cancelled_at'=>$status==='Cancelled'?$pickup->format('Y-m-d H:i:s'):null,
            ];
        }
    }
    return $records;
}

function dashboard_sample_trend(DateTimeImmutable $date,string $period): array {
    $start=$period==='year'?$date->modify('first day of January this year'):($period==='month'?$date->modify('first day of this month'):$date);
    $end=$start->modify($period==='year'?'+1 year':($period==='month'?'+1 month':'+1 day'));
    $format=$period==='year'?'Y-m':($period==='day'?'Y-m-d H':'Y-m-d');
    $step=$period==='year'?'+1 month':($period==='month'?'+1 day':'+1 hour');
    $buckets=[];
    for($day=$start;$day<$end;$day=$day->modify($step))
        $buckets[$day->format($format)]=['label'=>$day->format($period==='year'?'M':($period==='day'?'H:00':'M j')),'completed'=>0,'on_time'=>0,'scheduled'=>0,'cancelled'=>0];
    $increment=static function(?string $stamp,string $metric)use(&$buckets,$format):void{
        if(!$stamp)return;
        $key=(new DateTimeImmutable($stamp))->format($format);
        if(isset($buckets[$key]))$buckets[$key][$metric]++;
    };
    $records=dashboard_sample_trend_records($start,$end);
    foreach($records as $row){
        if($row['status']!=='Cancelled')$increment($row['departure'],'scheduled');
        $increment($row['sample_cancelled_at'],'cancelled');
        if($row['sample_arrival']){
            $increment($row['sample_arrival'],'completed');
            if($row['sample_arrival']<=$row['return_date'].' '.$row['return_time'])$increment($row['sample_arrival'],'on_time');
        }
    }
    return ['buckets'=>array_values($buckets),'unknown'=>0,'start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'sample'=>true,'sample_count'=>count($records)];
}
