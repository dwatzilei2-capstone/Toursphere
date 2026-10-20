<?php
require_once __DIR__ . '/trip_performance.php';

/** One row per reservation, matching Trip Schedule's latest linked trip. */
function dashboard_operations_source(): string {
    return "FROM reservations r
        LEFT JOIN LATERAL (SELECT * FROM trips WHERE reservation_id=r.id ORDER BY created_at DESC,id DESC LIMIT 1) t ON TRUE
        LEFT JOIN vehicles v ON v.id=COALESCE(t.vehicle_id,r.assigned_vehicle_id)
        LEFT JOIN drivers d ON d.id=COALESCE(t.driver_id,r.assigned_driver_id)";
}

function dashboard_calendar(PDO $pdo, string $start, string $end): array {
    $q = $pdo->prepare("SELECT r.id AS reservation_id,r.external_request_id,r.request_kind,r.cargo_type,
        r.origin,r.destination,COALESCE(t.scheduled_departure,r.departure_date+COALESCE(NULLIF(r.departure_time,'')::time,'00:00'::time)) AS departure,
        r.return_date,r.return_time,COALESCE(t.status,r.status) AS status,t.id AS trip_id,v.plate_number,d.name AS driver_name
        ".dashboard_operations_source()."
        WHERE COALESCE(t.scheduled_departure,r.departure_date+COALESCE(NULLIF(r.departure_time,'')::time,'00:00'::time))>=?::timestamp
          AND COALESCE(t.scheduled_departure,r.departure_date+COALESCE(NULLIF(r.departure_time,'')::time,'00:00'::time))<?::timestamp
          AND ((r.request_kind='Cargo' AND r.status IN ('Approved','Assigned','Confirmed','Ready for Dispatch','Dispatched','In Transit','Returning to Depot','Completed'))
            OR (r.request_kind<>'Cargo' AND r.status NOT IN ('Completed','Cancelled')))
        ORDER BY departure,r.id LIMIT 2001");
    $q->execute([$start,$end]);
    $rows=$q->fetchAll();
    $truncated=count($rows)>2000;
    return ['events'=>array_slice($rows,0,2000),'truncated'=>$truncated];
}

/** Uses the established verification and punctuality rules; never uses predicted arrival. */
function dashboard_delivery_on_time(array $row): ?bool {
    if (!performance_delivery_verified($row)) return null;
    $assessment=(json_decode($row['finalized_scores']??'{}',true)?:[])['on_time']??[];
    $outcome=isset($assessment['score'])?($assessment['delivery_outcome']??null):null;
    if (in_array($outcome,['on_time','late'],true)) return $outcome==='on_time';
    $variance=performance_timing($row)['variance_minutes'];
    return $variance===null ? null : $variance<=0;
}

function dashboard_trend(PDO $pdo, DateTimeImmutable $date, string $period): array {
    $start=$period==='year'?$date->modify('first day of January this year'):($period==='month'?$date->modify('first day of this month'):$date);
    $end=$start->modify($period==='year'?'+1 year':($period==='month'?'+1 month':'+1 day'));
    $format=$period==='year'?'Y-m':($period==='day'?'Y-m-d H':'Y-m-d');
    $step=$period==='year'?'+1 month':($period==='day'?'+1 hour':'+1 day');
    $buckets=[];
    for($cursor=$start;$cursor<$end;$cursor=$cursor->modify($step)){
        $buckets[$cursor->format($format)]=['label'=>$cursor->format($period==='year'?'M':($period==='day'?'H:00':'M j')),'completed'=>0,'on_time'=>0,'scheduled'=>0,'cancelled'=>0];
    }
    $q=$pdo->prepare("SELECT t.*,r.status AS reservation_status,r.return_date,r.return_time,r.departure_date,r.departure_time,
        EXISTS(SELECT 1 FROM reservation_events ev WHERE ev.reservation_id=r.id AND ev.status='Verified Completed') AS delivery_verified,
        a.id AS arrival_id,a.event_time AS arrival_time,e.scores AS finalized_scores,c.cancelled_at
        ".dashboard_operations_source()."
        LEFT JOIN trip_performance_evaluations e ON e.trip_id=t.id AND e.status='Finalized'
        LEFT JOIN LATERAL (SELECT id,event_time FROM trip_timeline WHERE trip_id=t.id AND title='Arrived' ORDER BY sort_order,id LIMIT 1) a ON TRUE
        LEFT JOIN LATERAL (SELECT MAX(created_at) AS cancelled_at FROM reservation_events WHERE reservation_id=r.id AND status='Cancelled') c ON TRUE
        WHERE r.request_kind='Cargo'
        AND (COALESCE(r.cargo_type,'')||' '||COALESCE(r.notes,'')||' '||COALESCE(t.completion_notes,'')) !~* 'DEVELOPMENT (TEST|SIMULATION)'
        AND ((r.departure_date>=?::date AND r.departure_date<?::date)
          OR (t.actual_arrival>=?::timestamp AND t.actual_arrival<?::timestamp)
          OR (c.cancelled_at>=?::timestamp AND c.cancelled_at<?::timestamp))");
    $q->execute([$start->format('Y-m-d'),$end->format('Y-m-d'),$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]);
    $unknown=0;
    $increment=static function($stamp,$metric) use (&$buckets,$format) {
        if(!$stamp)return;
        $key=(new DateTimeImmutable($stamp))->format($format);
        if(isset($buckets[$key]))$buckets[$key][$metric]++;
    };
    foreach($q as $row){
        if(in_array($row['reservation_status'],['Approved','Assigned','Confirmed','Ready for Dispatch','Dispatched','In Transit','Returning to Depot','Completed'],true))
            $increment($row['departure_date'].' '.$row['departure_time'],'scheduled');
        if($row['reservation_status']==='Cancelled')$increment($row['cancelled_at'],'cancelled');
        if(performance_delivery_verified($row)){
            $increment($row['actual_arrival'],'completed');
            $row['arrival_event']=$row['arrival_id']?['id'=>$row['arrival_id'],'event_time'=>$row['arrival_time']]:null;
            $onTime=dashboard_delivery_on_time($row);
            if($onTime===true)$increment($row['actual_arrival'],'on_time');
            elseif($onTime===null && isset($buckets[(new DateTimeImmutable($row['actual_arrival']))->format($format)]))$unknown++;
        }
    }
    return ['buckets'=>array_values($buckets),'unknown'=>$unknown,'start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d')];
}
