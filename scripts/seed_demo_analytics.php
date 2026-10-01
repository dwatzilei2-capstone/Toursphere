<?php
// Run only from the terminal. Creates a labelled, reversible analytics demo batch.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';
require ROOT_PATH . '/includes/cost_analysis.php';
$p = db();
function demo_insert(PDO $p, string $table, array $row): void {
    $columns = array_keys($row);
    $p->prepare('INSERT INTO '.$table.' ('.implode(',', $columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));
}
try {
    $p->beginTransaction();
    if ($p->query("SELECT COUNT(*) FROM reservations WHERE id LIKE 'RES-DEMO-%'")->fetchColumn()) throw new RuntimeException('Demo records already exist; no duplicates were created.');
    $vehicles = $p->query('SELECT * FROM vehicles ORDER BY id LIMIT 6')->fetchAll();
    $drivers = $p->query('SELECT * FROM drivers ORDER BY id LIMIT 6')->fetchAll();
    $customer = $p->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='customer' ORDER BY u.id LIMIT 1")->fetchColumn();
    if (!$vehicles || !$drivers) throw new RuntimeException('Vehicles and drivers are required.');
    $backupDir = ROOT_PATH.'/storage/private/testing-backups';
    if (!is_dir($backupDir)) mkdir($backupDir,0700,true);
    file_put_contents($backupDir.'/.htaccess', "Require all denied\n");
    $baseline = ['vehicles'=>$vehicles,'drivers'=>$drivers,'created_at'=>date(DATE_ATOM)];
    if (file_put_contents($backupDir.'/demo-analytics-baseline-'.date('Ymd-His').'.json',json_encode($baseline,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false) throw new RuntimeException('Backup failed.');
    $dates=[];
    $month=new DateTimeImmutable('2025-10-01');
    for($m=0;$m<12;$m++) { $dates[]=$month->modify('+6 days'); $dates[]=$month->modify('+17 days'); $month=$month->modify('+1 month'); }
    for($day=24;$day<=29;$day++) $dates[]=new DateTimeImmutable('2026-09-'.$day);
    usort($dates,fn($a,$b)=>$a<=>$b);
    $routes=[['Manila','Tagaytay',128],['Quezon City','Baguio',490],['Navotas','Clark',184],['Pasay','Batangas',226],['Makati','Subic',310],['Manila','Antipolo',76]];
    $manifest=['reservations'=>[],'trips'=>[],'fuel_transactions'=>[],'route_history'=>[],'maintenance_orders'=>[]];
    foreach($dates as $i=>$date) {
        $n=$i+1; $suffix=str_pad((string)$n,3,'0',STR_PAD_LEFT);
        $res='RES-DEMO-'.$suffix; $trip='TRP-DEMO-'.$suffix; $fuel='FL-DEMO-'.$suffix; $log='LOG-DEMO-'.$suffix;
        $v=$vehicles[$i%count($vehicles)]; $d=$drivers[$i%count($drivers)];
        [$origin,$destination,$baseDistance]=$routes[$i%count($routes)];
        $distance=round($baseDistance*(0.9+($i%5)*0.06),1);
        $passengers=max(2,min((int)$v['capacity'],(int)round($v['capacity']*(0.45+($i%4)*0.12))));
        $departure=$date->setTime(7+($i%3),0); $actual=$departure->modify('+'.($i%4===0?22:$i%6).' minutes');
        $duration=(int)round($distance/48*60)+($i%4)*12; $arrival=$actual->modify('+'.$duration.' minutes');
        $price=round(54+($i%7)*1.15+((int)$date->format('n')%4)*0.65,2);
        $economy=match($v['type']) {'Tour Bus'=>3.8,'Coaster Bus'=>6.4,'VIP SUV'=>8.5,default=>9.2};
        $liters=round($distance/$economy*(1+($i%3)*0.07),2); $fuelCost=round($liters*$price,2);
        $toll=($i%6===5)?0:180+($i%5)*125;
        $maintenance=$i%5===0?1200+($i%4)*700:0;
        $cost=$fuelCost+$toll+$maintenance;
        $revenue=round($cost*(1.18+($i%6)*0.14)+$passengers*85,2);
        $created=$departure->modify('-3 days')->format('Y-m-d H:i:s');
        demo_insert($p,'reservations',['id'=>$res,'client_name'=>'[DEMO] Sample Booking '.$suffix,'passenger_count'=>$passengers,'required_capacity'=>$passengers,'origin'=>$origin,'destination'=>$destination,'departure_date'=>$date->format('Y-m-d'),'departure_time'=>$departure->format('H:i'),'return_date'=>$arrival->format('Y-m-d'),'return_time'=>$arrival->format('H:i'),'customer_id'=>$customer?:null,'vehicle_requested'=>$v['type'],'assigned_vehicle_id'=>$v['id'],'assigned_driver_id'=>$d['id'],'status'=>'Completed','trip_type'=>'Round Trip','notes'=>'DEMO_ANALYTICS: synthetic sample for testing graphs.','route_distance_km'=>$distance,'fare_per_person'=>round($revenue/$passengers,2),'total_booking_fare'=>$revenue,'fare_status'=>'Confirmed','fare_calculated_at'=>$created,'created_at'=>$created]);
        demo_insert($p,'trips',['id'=>$trip,'reservation_id'=>$res,'origin'=>$origin,'destination'=>$destination,'vehicle_id'=>$v['id'],'driver_id'=>$d['id'],'passengers'=>$passengers,'scheduled_departure'=>$departure->format('Y-m-d H:i:s'),'actual_departure'=>$actual->format('Y-m-d H:i:s'),'estimated_arrival'=>$departure->modify('+'.$duration.' minutes')->format('Y-m-d H:i:s'),'actual_arrival'=>$arrival->format('Y-m-d H:i:s'),'distance_km'=>$distance,'status'=>'Completed','progress_pct'=>100,'current_step'=>'Completed & Returned','navigation_active'=>0,'fuel_estimate'=>round($distance/$economy,1).' L','toll_fee'=>$toll,'total_cost'=>$cost,'vehicle_condition'=>'Good','completion_notes'=>'DEMO_ANALYTICS: synthetic completed trip.','created_at'=>$created]);
        demo_insert($p,'fuel_transactions',['id'=>$fuel,'vehicle_id'=>$v['id'],'driver_id'=>$d['id'],'trip_id'=>$trip,'transaction_date'=>$arrival->format('Y-m-d H:i:s'),'fuel_type'=>'Diesel','liters'=>$liters,'price_per_liter'=>$price,'total_cost'=>$fuelCost,'odometer'=>10000+(int)round($distance)*$n,'station'=>'[DEMO] Sample Fuel Station '.(($i%3)+1),'receipt_no'=>'DEMO-RECEIPT-'.$suffix,'efficiency'=>round($distance/$liters,1).' km/L','created_at'=>$arrival->format('Y-m-d H:i:s')]);
        demo_insert($p,'route_history',['log_id'=>$log,'route_title'=>'[DEMO] '.$origin.' to '.$destination,'vehicle'=>trim($v['brand'].' '.$v['model']),'vehicle_id'=>$v['id'],'generated_date'=>$departure->modify('-30 minutes')->format('Y-m-d H:i:s'),'selected_mode'=>['Balanced (ROUTETHINK)','Fastest Express Corridor','Eco-Optimized Fuel Efficient'][$i%3],'model_version'=>'demo-synthetic','predicted_duration_mins'=>max(1,$duration-($i%4)*8),'actual_duration_mins'=>$duration,'predicted_fuel_liters'=>round($distance/$economy,2),'actual_fuel_liters'=>$liters,'actual_fuel_verified'=>0,'variance_pct'=>round(($i%4)*8/max(1,$duration)*100,2),'reservation_id'=>$res,'trip_id'=>$trip,'origin'=>$origin,'destination'=>$destination,'waypoints_json'=>'[]','route_data_json'=>json_encode(['demo'=>true,'distanceKm'=>$distance,'revenue'=>$revenue]),'navigation_key'=>hash('sha256','DEMO_ANALYTICS:'.$trip)]);
        $p->prepare('UPDATE trips SET route_history_id=? WHERE id=?')->execute([$log,$trip]);
        foreach(['Reservation Confirmed','Dispatched','In Transit','Arrived','Returning to Depot','Trip Completed'] as $step=>$title) demo_insert($p,'trip_timeline',['trip_id'=>$trip,'title'=>$title,'event_time'=>($step<2?$departure:$arrival)->format('Y-m-d H:i'),'completed'=>1,'active_step'=>0,'sort_order'=>$step+1]);
        demo_insert($p,'reservation_events',['reservation_id'=>$res,'status'=>'Completed','reason'=>'DEMO_ANALYTICS','created_at'=>$arrival->format('Y-m-d H:i:s')]);
        if($customer) demo_insert($p,'driver_ratings',['trip_id'=>$trip,'reservation_id'=>$res,'customer_id'=>$customer,'driver_id'=>$d['id'],'stars'=>3+($i%3),'feedback'=>'[DEMO] Synthetic sample rating.','created_at'=>$arrival->format('Y-m-d H:i:s')]);
        if($maintenance) { $wo='WO-DEMO-'.$suffix; demo_insert($p,'maintenance_orders',['id'=>$wo,'vehicle_id'=>$v['id'],'service_type'=>'[DEMO] Routine service','priority'=>'Low','scheduled_date'=>$arrival->format('Y-m-d'),'status'=>'Completed','estimated_cost'=>$maintenance,'notes'=>'DEMO_ANALYTICS: synthetic maintenance expense.','source_trip_id'=>$trip,'created_at'=>$arrival->format('Y-m-d H:i:s')]);$manifest['maintenance_orders'][]=$wo; }
        $p->prepare('UPDATE vehicles SET total_trips=total_trips+1,total_km=total_km+? WHERE id=?')->execute([(int)round($distance),$v['id']]);
        $p->prepare('UPDATE drivers SET trip_count=trip_count+1,completed_trips=completed_trips+1 WHERE id=?')->execute([$d['id']]);
        foreach(['reservations'=>$res,'trips'=>$trip,'fuel_transactions'=>$fuel,'route_history'=>$log] as $key=>$id) $manifest[$key][]=$id;
    }
    // Demo history should not flood real inboxes with historical notifications.
    $p->exec("DELETE FROM notifications WHERE body LIKE '%DEMO-%' OR target LIKE '%DEMO-%'");
    $summary=cost_summary($p,'2025-10-01','2026-10-01');
    $series=cost_monthly_series($p,'2025-10-01','2026-10-01');
    if(count($series)!==12 || $summary['trip_count']!==30 || abs(array_sum(array_column($series,'revenue'))-$summary['revenue'])>.01 || abs(array_sum(array_column($series,'cost'))-$summary['cost'])>.01) throw new RuntimeException('Analytics verification failed.');
    $manifest['summary']=$summary;$manifest['monthly_series']=$series;
    if(file_put_contents($backupDir.'/demo-analytics-manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false) throw new RuntimeException('Manifest could not be saved.');
    $p->commit();
    echo json_encode(['created'=>array_map('count',array_intersect_key($manifest,array_flip(['reservations','trips','fuel_transactions','route_history','maintenance_orders']))),'summary'=>$summary,'months'=>count($series)],JSON_PRETTY_PRINT);
} catch(Throwable $e) { if($p->inTransaction())$p->rollBack();fwrite(STDERR,'No demo data committed: '.$e->getMessage().PHP_EOL);exit(1); }
