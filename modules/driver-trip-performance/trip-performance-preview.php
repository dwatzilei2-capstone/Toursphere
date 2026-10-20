<?php
// Read-only visual preview. Every seeded record lives in connection-local temporary tables.
require dirname(__DIR__,2).'/includes/bootstrap.php';
require_login();require_once ROOT_PATH.'/includes/trip_performance.php';
if(!in_array($current_user['role_code'],['fleet_admin','fleet_manager','dispatcher'],true)){http_response_code(403);exit('Staff preview only.');}
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit('Visual preview only. No changes can be saved.');}
$pdo=db();
$previewBefore=[];foreach(['trips','reservations','drivers','trip_performance_evaluations','trip_performance_evidence'] as $table)$previewBefore[$table]=(int)$pdo->query('SELECT count(*) FROM public.'.$table)->fetchColumn();
$tables=$pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $table)$pdo->exec('CREATE TEMP TABLE "'.$table.'" (LIKE public."'.$table.'" INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES INCLUDING IDENTITY)');
foreach($pdo->query("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='public' AND column_default LIKE 'nextval(%'")->fetchAll() as $serial){
 $sequence='preview_'.$serial['table_name'].'_'.$serial['column_name'];$pdo->exec('CREATE TEMP SEQUENCE "'.$sequence.'" START 1000000');
 $pdo->exec('ALTER TABLE "'.$serial['table_name'].'" ALTER COLUMN "'.$serial['column_name'].'" SET DEFAULT nextval(\'pg_temp."'.$sequence.'"\')');
}
$pdo->exec('INSERT INTO system_settings SELECT * FROM public.system_settings');
for($i=1;$i<=6;$i++){
 $driver='DEMO-D'.$i;$vehicle='DEMO-V'.$i;
 $pdo->prepare("INSERT INTO drivers(id,name,license_no,status,department) VALUES (?,?,?,'Active','Sample Cargo Unit')")->execute([$driver,'Sample Driver '.str_pad((string)$i,2,'0',STR_PAD_LEFT),'DEMO-LICENSE-'.$i]);
 $pdo->prepare("INSERT INTO vehicles(id,plate_number,type,brand,model,year,capacity,status,assigned_driver_id) VALUES (?,?,'Light Truck','Sample','Cargo Truck',2026,0,'Available',?)")->execute([$vehicle,'DEMO-'.(100+$i),$driver]);
}
for($i=1;$i<=40;$i++){
 $trip='DEMO-TRP-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);$res='DEMO-RES-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);$driver='DEMO-D'.(($i-1)%6+1);$vehicle='DEMO-V'.(($i-1)%6+1);
 $day=(new DateTimeImmutable('2026-08-01'))->modify('+'.($i-1).' days')->format('Y-m-d');
 $tripStatus=$i<=34?'Completed':($i<=36?'In Transit':($i<=38?'Assigned':'Cancelled'));
 $resStatus=match($tripStatus){'In Transit'=>'Dispatched','Assigned'=>'Confirmed',default=>$tripStatus};
 $pdo->prepare("INSERT INTO reservations(id,client_name,contact_person,contact_phone,passenger_count,origin,destination,departure_date,departure_time,return_date,return_time,status,request_kind,cargo_type,cargo_weight_kg,cargo_volume_m3,cargo_quantity,assigned_driver_id,assigned_vehicle_id,notes,customer_id,request_purpose) VALUES (?,?,?,?,0,?,?,?,'08:00',?,'12:00',?,'Cargo',?,?,?, ?,?,?,'Isolated visual preview only',?,'Cargo Transport')")->execute([$res,'Sample Warehouse '.(($i-1)%5+1),'Sample Receiver','09000000000','Sample Pickup '.$i,'Sample Delivery '.$i,$day,$day,$resStatus,['Office supplies','Packaged food','Appliance cartons','Spare parts'][$i%4],100+$i*25,1+($i%6),5+$i,$driver,$vehicle,$current_user['id']]);
 $arrival=$day.($i%4===0?' 12:30:00':' 11:45:00');
 $step=$tripStatus==='Completed'?'Verified Completed':$tripStatus;
 $pdo->prepare("INSERT INTO trips(id,reservation_id,driver_id,vehicle_id,origin,destination,status,current_step,scheduled_departure,actual_departure,actual_arrival,distance_km,completion_notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$trip,$res,$driver,$vehicle,'Sample Pickup '.$i,'Sample Delivery '.$i,$tripStatus,$step,$day.' 08:00:00',$tripStatus==='Assigned'?null:$day.' 08:05:00',$tripStatus==='Completed'?$arrival:null,12+$i*2,'Isolated visual preview; no actual delivery']);
 if($tripStatus!=='Completed')continue;
 $pdo->prepare("INSERT INTO reservation_events(reservation_id,actor_id,status,reason) VALUES (?,?,'Verified Completed','Sample verification for isolated preview')")->execute([$res,$current_user['id']]);
 $scores=[];$total=0;
 foreach(PERFORMANCE_CRITERIA as $key=>$label){$score=60+(($i*7+strlen($key)*3)%37);$scores[$key]=['score'=>$score,'notes'=>'Sample assessment for visual preview only.','evidence_ids'=>[]];$total+=$score*PERFORMANCE_DEFAULT_WEIGHTS[$key]/100;}
 $scores['on_time']['delivery_outcome']=$i%4===0?'late':'on_time';
 $evalStatus=$i<=28?'Finalized':'Submitted';
 $pdo->prepare("INSERT INTO trip_performance_evaluations(trip_id,driver_id,status,weights,scores,timing_snapshot,overall_score,submitted_by,submitted_at,finalized_by,finalized_at,delay_cause,delay_notes) VALUES (?,?,?,?::jsonb,?::jsonb,?::jsonb,?,?,?,?,?,?,?)")->execute([$trip,$driver,$evalStatus,json_encode(PERFORMANCE_DEFAULT_WEIGHTS),json_encode($scores),json_encode(['actual_arrival'=>$arrival,'scheduled_arrival'=>$day.' 12:00:00']),round($total,2),$current_user['id'],$day.' 14:00:00',$evalStatus==='Finalized'?$current_user['id']:null,$evalStatus==='Finalized'?$day.' 16:00:00':null,$i%4===0?'Driver':'None',$i%4===0?'Sample delay explanation':'']);
 $pdo->prepare("INSERT INTO trip_performance_evidence(trip_id,kind,criteria,description,added_by,verified_by,verified_at,verification_notes) VALUES (?,'Supporting Document',?::jsonb,'Sample combined delivery evidence (visual preview only)',?,?,CURRENT_TIMESTAMP,'Sample verification')")->execute([$trip,json_encode(array_keys(PERFORMANCE_CRITERIA)),$current_user['id'],$current_user['id']]);
}
ob_start();require ROOT_PATH.'/modules/driver-trip-performance/trip-performance.php';$previewHtml=ob_get_clean();
foreach($previewBefore as $table=>$count)if((int)$pdo->query('SELECT count(*) FROM public.'.$table)->fetchColumn()!==$count){http_response_code(500);exit('Operational data changed during preview generation.');}
$banner='<div class="alert alert-info mb-3" role="status"><strong>ISOLATED VISUAL PREVIEW</strong> · 40 sample trips · 28 finalized evaluations · 6 sample drivers. Nothing here is a real trip. Forms cannot save. <a href="'.BASE_URL.'/modules/driver-trip-performance/trip-performance.php">Back to real Trip Performance</a></div>';
$previewHtml=str_replace('<div class="driver-trip-page-shell">','<div class="driver-trip-page-shell">'.$banner,$previewHtml);
// Redirect every form away from operational handlers. The preview rejects all POSTs.
$previewHtml=preg_replace('/action="[^"]*\/actions\/[^" ]+"/','action="'.BASE_URL.'/modules/driver-trip-performance/trip-performance-preview.php"',$previewHtml);
$previewHtml=preg_replace('/href="[^"]*\/actions\/trip-performance-evidence.php[^" ]*"/','href="#"',$previewHtml);
$previewHtml=preg_replace('#<script[^>]+src="[^"]*/js/notifications\.js[^"]*"[^>]*></script>#','',$previewHtml);
$previewHtml=str_replace('</body>','<script>document.querySelectorAll("form").forEach(form=>{form.addEventListener("submit",event=>{event.preventDefault();alert("Visual preview only. Nothing has been saved.");},true);});</script></body>',$previewHtml);
header('Cache-Control: no-store');echo $previewHtml;
