<?php
require_once __DIR__.'/fuel_receipts.php';
const PERFORMANCE_CRITERIA=['on_time'=>'On-Time Delivery','completion'=>'Delivery Completion','safety'=>'Safety','fuel'=>'Fuel Efficiency','documentation'=>'Documentation','conduct'=>'Driver Conduct'];
const PERFORMANCE_DEFAULT_WEIGHTS=['on_time'=>25,'completion'=>25,'safety'=>20,'fuel'=>15,'documentation'=>10,'conduct'=>5];
function performance_reviewer(array $actor): bool {return in_array($actor['role_code']??'',['fleet_manager','fleet_admin'],true);}
function performance_access(array $actor): bool {return in_array($actor['role_code']??'',['dispatcher','fleet_manager','fleet_admin','driver'],true);}
function performance_delivery_verified(array $trip): bool {
 return $trip['status']==='Completed'&&($trip['reservation_status']??'')==='Completed'&&$trip['current_step']==='Verified Completed'
  &&filter_var($trip['delivery_verified']??false,FILTER_VALIDATE_BOOLEAN)&&!empty($trip['driver_id'])&&!empty($trip['vehicle_id'])&&!empty($trip['actual_arrival']);
}
function performance_record_action(array $trip,array $actor): array {
 if(($trip['evaluation_status']??'')==='Finalized')return ['label'=>'View Evaluation','mode'=>'view'];
 if(($trip['evaluation_status']??'')==='Submitted')return ['label'=>performance_reviewer($actor)?'Review Evaluation':(($actor['role_code']??'')==='dispatcher'?'Edit Evaluation':'View Evaluation'),'mode'=>'review'];
 if(($actor['role_code']??'')==='dispatcher'&&performance_delivery_verified($trip))return ['label'=>'Evaluate','mode'=>'evaluate'];
 return ['label'=>($actor['role_code']??'')==='dispatcher'?'Review Evidence':'View Evidence','mode'=>'evidence'];
}
function performance_weights(array $values): array {
 if(array_diff(array_keys($values),array_keys(PERFORMANCE_CRITERIA)))throw new RuntimeException('Configure only the six evaluation criteria.');
 $weights=[];
 foreach(PERFORMANCE_CRITERIA as $key=>$label){$value=filter_var($values[$key]??null,FILTER_VALIDATE_INT);if($value===false||$value<0||$value>100)throw new RuntimeException('Weights must be whole percentages from 0 to 100.');$weights[$key]=$value;}
 if(array_sum($weights)!==100)throw new RuntimeException('Evaluation weights must total 100%.');return $weights;
}
function performance_config(PDO $pdo): array {
 $q=$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='trip_performance.weights'");
 return performance_weights(json_decode($q->fetchColumn()?:'null',true)?:PERFORMANCE_DEFAULT_WEIGHTS);
}
function performance_save_config(PDO $pdo,array $values,array $actor): void {
 performance_transaction($pdo);
 if(!performance_reviewer($actor))throw new RuntimeException('Only Admin and Manager may configure evaluation weights.');
 $weights=performance_weights($values);
 $pdo->query("SELECT id FROM system_settings WHERE setting_key='trip_performance.weights' FOR UPDATE");
 $old=performance_config($pdo);
 $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key='trip_performance.weights'")->execute([json_encode($weights)]);
 performance_audit($pdo,'Weights Updated','weights',$actor,$old,$weights);
 unset($GLOBALS['fleet_settings_cache']['trip_performance.weights']);
}
function performance_audit(PDO $pdo,string $action,string $id,array $actor,$old,$new): void {
 $pdo->prepare("INSERT INTO audit_logs(action,user_id,entity_type,entity_id,details,actor_name,actor_role,old_values,new_values) VALUES (?,?, 'trip_performance',?,?,?,?,?::jsonb,?::jsonb)")->execute([$action,$actor['id'],$id,json_encode(['module'=>'Trip Performance'],JSON_THROW_ON_ERROR),$actor['name']??null,$actor['role_code'],json_encode($old,JSON_THROW_ON_ERROR),json_encode($new,JSON_THROW_ON_ERROR)]);
}
function performance_trip(PDO $pdo,string $id,array $actor,bool $lock=false): array {
 if(!performance_access($actor))throw new RuntimeException('Trip Performance access denied.');
 $q=$pdo->prepare("SELECT t.*,r.request_kind,r.status AS reservation_status,r.return_date,r.return_time,r.cargo_type,r.cargo_weight_kg,r.departure_date,r.departure_time,d.name AS driver_name,d.user_id AS driver_user_id,EXISTS(SELECT 1 FROM reservation_events ev WHERE ev.reservation_id=r.id AND ev.status='Verified Completed') AS delivery_verified FROM trips t JOIN reservations r ON r.id=t.reservation_id LEFT JOIN drivers d ON d.id=t.driver_id WHERE t.id=?".($lock?' FOR UPDATE OF t':''));$q->execute([$id]);$trip=$q->fetch();
 if(!$trip||$trip['request_kind']!=='Cargo'||($actor['role_code']==='driver'&&(int)$trip['driver_user_id']!==(int)$actor['id']))throw new RuntimeException('Cargo trip not found or access denied.');
 $q=$pdo->prepare("SELECT id,event_time FROM trip_timeline WHERE trip_id=? AND title='Arrived' ORDER BY sort_order,id LIMIT 1");$q->execute([$id]);$arrival=$q->fetch();$trip['arrival_event']=$arrival?:null;return $trip;
}
function performance_timing(array $trip): array {
 // Cargo's shared reservation window is the delivery promise, not a route prediction.
 $scheduled=!empty($trip['return_date'])&&!empty($trip['return_time'])?$trip['return_date'].' '.$trip['return_time']:null;
 $actual=$trip['actual_arrival'];$source='trips.actual_arrival (completion/handover; verify delivery relevance)';
 $event=$trip['arrival_event']??null;
 if($event){$value=$event['event_time'];foreach(['!Y-m-d H:i:s','!Y-m-d H:i'] as $format){$stamp=DateTimeImmutable::createFromFormat($format,$value);if($stamp&&$stamp->format(substr($format,1))===$value){$actual=$stamp->format('Y-m-d H:i:s');$source='trip_timeline.Arrived #'.$event['id'];break;}}}
 return ['scheduled_arrival'=>$scheduled,'scheduled_source'=>$scheduled?'reservations.return_date/return_time':null,'predicted_arrival'=>$trip['estimated_arrival']??null,'actual_arrival'=>$actual,'actual_source'=>$source,'completion_arrival'=>$trip['actual_arrival'],'variance_minutes'=>$scheduled&&$actual?round((strtotime($actual)-strtotime($scheduled))/60,2):null];
}
/** Delivery KPIs use the same arrival selection as evaluation; simulations are excluded. */
function performance_delivery_kpis(PDO $pdo): array {
 $rows=$pdo->query("SELECT t.*,r.return_date,r.return_time,a.id AS arrival_id,a.event_time AS arrival_time,e.scores AS finalized_scores,e.overall_score AS finalized_overall_score,e.finalized_at
 FROM trips t JOIN reservations r ON r.id=t.reservation_id
 LEFT JOIN trip_performance_evaluations e ON e.trip_id=t.id AND e.status='Finalized'
 LEFT JOIN LATERAL (SELECT id,event_time FROM trip_timeline WHERE trip_id=t.id AND title='Arrived' ORDER BY sort_order,id LIMIT 1) a ON true
 WHERE r.request_kind='Cargo' AND t.status='Completed' AND r.status='Completed'
 AND t.current_step='Verified Completed'
 AND EXISTS (SELECT 1 FROM reservation_events ev WHERE ev.reservation_id=r.id AND ev.status='Verified Completed')
 AND (COALESCE(r.cargo_type,'')||' '||COALESCE(r.notes,'')||' '||COALESCE(t.completion_notes,'')) !~* 'DEVELOPMENT (TEST|SIMULATION)'")->fetchAll();
 $measurable=0;$onTime=0;$delay=0.0;$delayMeasurable=0;$byDriver=[];$deliveryVariances=[];$scoreTotal=0.0;$scoreCount=0;$scoreByDriver=[];$overallTrend=[];
 foreach($rows as $trip){
  if(is_numeric($trip['finalized_overall_score'])&&!empty($trip['finalized_at']))$overallTrend[]=['trip_id'=>$trip['id'],'score'=>(float)$trip['finalized_overall_score'],'finalized_at'=>$trip['finalized_at']];
  $trip['arrival_event']=$trip['arrival_id']?['id'=>$trip['arrival_id'],'event_time'=>$trip['arrival_time']]:null;
  $timing=performance_timing($trip);$variance=$timing['variance_minutes'];
  $assessment=(json_decode($trip['finalized_scores']??'{}',true)?:[])['on_time']??[];
  $score=$assessment['score']??null;
  if(is_numeric($score)&&(float)$score>=0&&(float)$score<=100){
   $scoreTotal+=(float)$score;$scoreCount++;
   if(!empty($trip['driver_id'])){
    $id=$trip['driver_id'];$scoreByDriver[$id]??=['count'=>0,'total'=>0.0,'average'=>null];
    $scoreByDriver[$id]['count']++;$scoreByDriver[$id]['total']+=(float)$score;
   }
  }
  $outcome=isset($assessment['score'])?($assessment['delivery_outcome']??null):null;
  if(!in_array($outcome,['on_time','late'],true)&&$variance===null)continue;
  $isOnTime=in_array($outcome,['on_time','late'],true)?$outcome==='on_time':$variance<=0;
  $measurable++;if($isOnTime)$onTime++;
  // A warehouse assessment confirms punctuality, not an invented number of delay minutes.
  if($variance!==null&&!in_array($outcome,['on_time','late'],true)){
   $delay+=max(0,$variance);$delayMeasurable++;
   $deliveryVariances[]=['trip_id'=>$trip['id'],'actual_arrival'=>$timing['actual_arrival'],'variance_minutes'=>$variance];
  }
  if(!empty($trip['driver_id'])){
   $id=$trip['driver_id'];
   $byDriver[$id]??=['measurable'=>0,'on_time'=>0,'late'=>0,'on_time_rate'=>null];
   $byDriver[$id]['measurable']++;
   $byDriver[$id][$isOnTime?'on_time':'late']++;
  }
 }
 foreach($byDriver as &$driver)$driver['on_time_rate']=100*$driver['on_time']/$driver['measurable'];
 unset($driver);
 foreach($scoreByDriver as &$driver)$driver['average']=round($driver['total']/$driver['count'],2);
 unset($driver);
 usort($deliveryVariances,static fn($a,$b)=>strcmp($a['actual_arrival'],$b['actual_arrival'])?:strcmp($a['trip_id'],$b['trip_id']));
 usort($overallTrend,static fn($a,$b)=>(strtotime($a['finalized_at'])<=>strtotime($b['finalized_at']))?:strcmp($a['trip_id'],$b['trip_id']));
 $requests=$pdo->query("SELECT count(*) total,count(*) FILTER (WHERE status='Cancelled') cancelled FROM reservations
 WHERE request_kind='Cargo' AND (COALESCE(cargo_type,'')||' '||COALESCE(notes,'')) !~* 'DEVELOPMENT (TEST|SIMULATION)'")->fetch();
 return ['completed'=>count($rows),'measurable'=>$measurable,'on_time_count'=>$onTime,'on_time_rate'=>$measurable?100*$onTime/$measurable:null,
 'average_delay'=>$delayMeasurable?$delay/$delayMeasurable:null,'cancellation_rate'=>(int)$requests['total']?100*(int)$requests['cancelled']/(int)$requests['total']:null,'by_driver'=>$byDriver,'delivery_variances'=>$deliveryVariances,
 'on_time_score'=>$scoreCount?round($scoreTotal/$scoreCount,2):null,'on_time_score_count'=>$scoreCount,'on_time_score_by_driver'=>$scoreByDriver,'overall_score_trend'=>$overallTrend];
}
function performance_evidence(PDO $pdo,string $tripId): array {
 $q=$pdo->prepare("SELECT e.*,u.name AS verifier_name FROM trip_performance_evidence e LEFT JOIN users u ON u.id=e.verified_by WHERE e.trip_id=? AND e.snapshot->>'removed_at' IS NULL ORDER BY e.id");$q->execute([$tripId]);return $q->fetchAll();
}
/** Read-only candidates: destination coordinates are explicitly planned, not observed GPS. */
function performance_sources(PDO $pdo,array $trip): array {
 $sources=[];$timing=performance_timing($trip);
 if($timing['actual_arrival'])$sources['arrival']=['kind'=>'Arrival','criteria'=>['on_time'],'source_table'=>'trips','source_id'=>$trip['id'],'snapshot'=>$timing];
 $q=$pdo->prepare("SELECT id,actor_id,reason,created_at FROM reservation_events WHERE reservation_id=? AND status='Verified Completed' ORDER BY id");$q->execute([$trip['reservation_id']]);
 foreach($q->fetchAll() as $r)if($trip['status']==='Completed'&&$trip['current_step']==='Verified Completed')$sources['delivery:'.$r['id']]=['kind'=>'Delivery Verification','criteria'=>['completion'],'source_table'=>'reservation_events','source_id'=>(string)$r['id'],'snapshot'=>$r];
 $q=$pdo->prepare('SELECT id,liters,transaction_date,odometer,efficiency,receipt_no FROM fuel_transactions WHERE trip_id=? AND liters>0 AND verification_status=\'Verified\'');$q->execute([$trip['id']]);
 foreach($q->fetchAll() as $r)$sources['fuel:'.$r['id']]=['kind'=>'Fuel','criteria'=>['fuel'],'source_table'=>'fuel_transactions','source_id'=>$r['id'],'snapshot'=>$r+['distance_km'=>$trip['distance_km'],'final_odometer'=>$trip['final_odometer']]];
 $q=$pdo->prepare('SELECT log_id,actual_fuel_liters,actual_fuel_transaction_id FROM route_history WHERE trip_id=? AND actual_fuel_verified=1 AND actual_fuel_liters>0');$q->execute([$trip['id']]);
 foreach($q->fetchAll() as $r)$sources['consumption:'.$r['log_id']]=['kind'=>'Fuel','criteria'=>['fuel'],'source_table'=>'route_history','source_id'=>$r['log_id'],'snapshot'=>$r+['distance_km'=>$trip['distance_km']]];
 $q=$pdo->prepare('SELECT id,stars,feedback,created_at FROM driver_ratings WHERE trip_id=?');$q->execute([$trip['id']]);
 foreach($q->fetchAll() as $r)$sources['conduct:'.$r['id']]=['kind'=>'Conduct','criteria'=>['conduct'],'source_table'=>'driver_ratings','source_id'=>(string)$r['id'],'snapshot'=>$r];
 return $sources;
}
function performance_transaction(PDO $pdo): void {if(!$pdo->inTransaction())throw new RuntimeException('A transaction is required.');}
function performance_fuel_excludable(PDO $pdo,array $trip): bool {
 // Pending/unverified records must be reviewed, not bypassed as an exclusion.
 $q=$pdo->prepare('SELECT (EXISTS(SELECT 1 FROM fuel_transactions WHERE trip_id=? AND liters>0) OR EXISTS(SELECT 1 FROM route_history WHERE trip_id=? AND actual_fuel_liters>0))::int');
 $q->execute([$trip['id'],$trip['id']]);return (int)$q->fetchColumn()===0;
}
/** UI readiness uses the existing scoring prerequisites; missing records do not mean zero consumption. */
function performance_fuel_readiness(array $trip,array $sources): array {
 $hasFuel=false;foreach($sources as $source)if(($source['kind']??'')==='Fuel'){$hasFuel=true;break;}
 if(!$hasFuel)return ['available'=>false,'reason'=>'missing_fuel_record','message'=>'No recorded fuel consumption — no verified fuel record is available for this trip. This does not mean zero fuel was used.'];
 if(!((float)($trip['distance_km']??0)>0))return ['available'=>false,'message'=>'Not Evaluated — recorded trip distance is missing.'];
 return ['available'=>true,'message'=>'Select verified actual consumption evidence. A fuel purchase alone is insufficient.'];
}
function performance_note($value,string $label='Supporting explanation'): string {$text=trim((string)$value);if($text===''||mb_strlen($text)>2000)throw new RuntimeException($label.' is required (up to 2,000 characters).');return $text;}
function performance_add_evidence(PDO $pdo,string $tripId,array $input,array $actor,?array $file=null): int {
 performance_transaction($pdo);$trip=performance_trip($pdo,$tripId,$actor,true);
 if(!in_array($actor['role_code'],['dispatcher','driver'],true))throw new RuntimeException('Dispatcher or assigned driver must provide evidence.');
 $description=performance_note($input['description']??'','Evidence description');$source=(string)($input['source']??'');
 $record=null;
 if($source!=='upload'){$record=performance_sources($pdo,$trip)[$source]??null;if(!$record)throw new RuntimeException('Select an available trip evidence source.');if($actor['role_code']!=='dispatcher')throw new RuntimeException('Only Dispatcher may link system evidence.');}
 else {
  foreach(performance_evidence($pdo,$tripId) as $existing)if($existing['stored_filename'])throw new RuntimeException('Only one uploaded document is allowed per trip. Remove the existing file before uploading its replacement.');
  if(!$file)throw new RuntimeException('Upload a supporting PDF, JPG or PNG.');
  $kind=(string)($input['kind']??'');if(!in_array($kind,['POD','Supporting Document'],true))throw new RuntimeException('Select an evidence type.');
  $criteria=array_values(array_intersect(array_keys(PERFORMANCE_CRITERIA),(array)($input['criteria']??[])));
  if(!$criteria)throw new RuntimeException('Select supported evaluation criteria.');
  if($kind==='POD'&&array_diff($criteria,['completion','documentation']))throw new RuntimeException('POD supports completion and documentation only.');
  $record=['kind'=>$kind,'criteria'=>$criteria,'source_table'=>null,'source_id'=>null,'snapshot'=>['includes_pod'=>$kind==='Supporting Document'&&!empty($input['includes_pod'])]];
 }
 if($record['source_id'])foreach(performance_evidence($pdo,$tripId) as $existing)if($existing['source_table']===$record['source_table']&&$existing['source_id']===$record['source_id']&&json_decode($existing['snapshot'],true)==$record['snapshot'])throw new RuntimeException('This version of the source is already linked.');
 $q=$pdo->prepare('INSERT INTO trip_performance_evidence(trip_id,kind,criteria,source_table,source_id,snapshot,description,stored_filename,original_filename,mime_type,file_size,sha256,added_by) VALUES (?,?,?::jsonb,?,?,?::jsonb,?,?,?,?,?,?,?) RETURNING id');
 $q->execute([$tripId,$record['kind'],json_encode($record['criteria']),$record['source_table'],$record['source_id'],json_encode($record['snapshot']),$description,$file['filename']??null,$file['name']??null,$file['mime']??null,$file['size']??null,$file['sha256']??null,$actor['id']]);$id=(int)$q->fetchColumn();
 performance_audit($pdo,'Evidence Added',(string)$id,$actor,null,['trip_id'=>$tripId,'kind'=>$record['kind'],'criteria'=>$record['criteria'],'source_table'=>$record['source_table'],'source_id'=>$record['source_id'],'snapshot'=>$record['snapshot'],'description'=>$description,'sha256'=>$file['sha256']??null]);return $id;
}
function performance_remove_upload(PDO $pdo,string $tripId,int $evidenceId,array $actor): void {
 performance_transaction($pdo);performance_trip($pdo,$tripId,$actor,true);
 $q=$pdo->prepare('SELECT * FROM trip_performance_evidence WHERE id=? AND trip_id=? FOR UPDATE');$q->execute([$evidenceId,$tripId]);$e=$q->fetch();
 if(!$e||!$e['stored_filename']||isset(json_decode($e['snapshot'],true)['removed_at']))throw new RuntimeException('Uploaded document not found.');
 if(!in_array($actor['role_code']??'',['dispatcher','fleet_admin'],true)&&!(($actor['role_code']??'')==='driver'&&(int)$e['added_by']===(int)$actor['id']&&!$e['verified_at']))throw new RuntimeException('Only Admin, Dispatcher, or the assigned driver removing their own unverified upload may remove this file.');
 $q=$pdo->prepare('SELECT status,scores,id FROM trip_performance_evaluations WHERE trip_id=?');$q->execute([$tripId]);$evaluation=$q->fetch();
 if($evaluation){
  if($evaluation['status']==='Finalized')throw new RuntimeException('Finalized evaluation evidence is preserved. Use the dispute review process.');
  foreach(json_decode($evaluation['scores'],true) as $row)if(in_array($evidenceId,array_map('intval',$row['evidence_ids']??[]),true))throw new RuntimeException('This file is linked to a saved evaluation. Clear its evidence links and scores through evaluation review before removing it.');
 }
 $q=$pdo->prepare('SELECT evidence_ids FROM trip_performance_disputes WHERE evaluation_id IN (SELECT id FROM trip_performance_evaluations WHERE trip_id=?)');$q->execute([$tripId]);foreach($q as $row)if(in_array($evidenceId,array_map('intval',json_decode($row['evidence_ids'],true)),true))throw new RuntimeException('Evidence used in a dispute must be preserved.');
 $snapshot=json_decode($e['snapshot'],true);$snapshot['removed_at']=date('c');$snapshot['removed_by']=$actor['id'];
 $pdo->prepare('UPDATE trip_performance_evidence SET snapshot=?::jsonb WHERE id=?')->execute([json_encode($snapshot),$evidenceId]);
 performance_audit($pdo,'Uploaded Evidence Removed',(string)$evidenceId,$actor,$e,['trip_id'=>$tripId,'description'=>'Uploaded file removed from active evidence; original retained for audit.']);
}
function performance_verify_evidence(PDO $pdo,string $tripId,int $evidenceId,string $notes,array $actor): void {
 performance_transaction($pdo);$trip=performance_trip($pdo,$tripId,$actor,true);
 if($actor['role_code']!=='dispatcher')throw new RuntimeException('Only Dispatcher may verify trip evidence.');$notes=performance_note($notes,'Verification notes');
 $q=$pdo->prepare('SELECT * FROM trip_performance_evidence WHERE id=? AND trip_id=? FOR UPDATE');$q->execute([$evidenceId,$tripId]);$e=$q->fetch();if(!$e||isset(json_decode($e['snapshot'],true)['removed_at']))throw new RuntimeException('Evidence not found.');if($e['verified_at'])throw new RuntimeException('Evidence has already been verified.');
 if($e['source_id']){
  $match=null;foreach(performance_sources($pdo,$trip) as $s)if($s['source_table']===$e['source_table']&&$s['source_id']===$e['source_id'])$match=$s;
 if(!$match)throw new RuntimeException('The source is no longer available.');
  $pdo->prepare('UPDATE trip_performance_evidence SET snapshot=?::jsonb WHERE id=?')->execute([json_encode($match['snapshot']),$evidenceId]);
 }else{ $path=performance_file_path($e['stored_filename']);if(!$path||!hash_equals($e['sha256'],hash_file('sha256',$path)))throw new RuntimeException('Evidence file is missing or has changed.'); }
 $pdo->prepare('UPDATE trip_performance_evidence SET verified_by=?,verified_at=CURRENT_TIMESTAMP,verification_notes=? WHERE id=?')->execute([$actor['id'],$notes,$evidenceId]);
 $q=$pdo->prepare('SELECT * FROM trip_performance_evidence WHERE id=?');$q->execute([$evidenceId]);
 performance_audit($pdo,'Evidence Verified',(string)$evidenceId,$actor,$e,$q->fetch());
}
function performance_file_path(?string $filename): ?string {
 if(!$filename||!preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/D',$filename))return null;
 $path=ROOT_PATH.'/storage/private/trip-evidence/'.$filename;return is_file($path)?$path:null;
}
/** Missing criteria remain unscored. Only fuel with no recorded consumption sources can be excluded. */
function performance_scores(PDO $pdo,array $trip,array $input,array $weights,string $cause): array {
 $evidence=[];foreach(performance_evidence($pdo,$trip['id']) as $e)$evidence[(string)$e['id']]=$e;
 $scores=[];$total=0;$complete=true;$timing=performance_timing($trip);$applicableWeight=array_sum($weights);$excluded=[];
 foreach(PERFORMANCE_CRITERIA as $key=>$label){
  $row=(array)($input[$key]??[]);$raw=trim((string)($row['score']??''));
  if($raw===''){
   $scores[$key]=['score'=>null,'evidence_ids'=>[],'notes'=>trim((string)($row['notes']??''))];
   if($key==='fuel'&&performance_fuel_excludable($pdo,$trip)){
    $scores[$key]['status']='excluded';$scores[$key]['reason']='No recorded fuel consumption';$applicableWeight-=$weights[$key];$excluded[]=$key;
   }elseif($weights[$key]>0)$complete=false;
   continue;
  }
  if(!is_numeric($raw)||(float)$raw<0||(float)$raw>100)throw new RuntimeException($label.' score must be between 0 and 100.');
  $notes=performance_note($row['notes']??'',$label.' explanation');$ids=array_values(array_unique(array_map('intval',(array)($row['evidence_ids']??[]))));
  if(!$ids)throw new RuntimeException($label.' needs verified supporting evidence.');$kinds=[];$consumption=false;
  foreach($ids as $eid){$e=$evidence[(string)$eid]??null;if(!$e||!$e['verified_at']||!in_array($key,json_decode($e['criteria'],true),true))throw new RuntimeException($label.' requires verified evidence belonging to this trip and criterion.');
   if($e['stored_filename']){ $path=performance_file_path($e['stored_filename']);if(!$path||!hash_equals($e['sha256'],hash_file('sha256',$path)))throw new RuntimeException('Supporting evidence is missing or altered.'); }
   if($e['source_id']){ $current=null;foreach(performance_sources($pdo,$trip) as $s)if($s['source_table']===$e['source_table']&&$s['source_id']===$e['source_id'])$current=$s;
    if(!$current||json_decode($e['snapshot'],true)!=$current['snapshot'])throw new RuntimeException('Source evidence changed. Link and verify current evidence before scoring.'); }
   $kinds[]=$e['kind'];if($e['kind']==='Supporting Document'&&!empty(json_decode($e['snapshot'],true)['includes_pod']))$kinds[]='POD';if($e['source_table']==='route_history'&&$e['kind']==='Fuel')$consumption=true;
  }
  if($key==='on_time'){
   $outcome=(string)($row['delivery_outcome']??'');
   if($outcome!==''||$timing['variance_minutes']===null){
    if(!in_array($outcome,['on_time','late'],true))throw new RuntimeException('Select the receiving warehouse assessment: On time or Late, or leave the score Not Evaluated.');
    if(!in_array('Supporting Document',$kinds,true))throw new RuntimeException('On-Time Delivery needs a verified receiving warehouse supporting document confirming the selected assessment.');
    $late=$outcome==='late';
   }else{
    if(!in_array('Arrival',$kinds,true))throw new RuntimeException('Link verified Arrival evidence or use a verified receiving warehouse document and assessment.');
    $late=$timing['variance_minutes']>0;
   }
   if($late&&$cause==='Unknown')throw new RuntimeException('Delay responsibility is unknown. Leave On-Time Delivery Not Evaluated until reviewed.');
   if($late&&$cause==='None')throw new RuntimeException('Explain the cause of the recorded delivery delay.');
   if($late&&$cause==='External'&&(float)$raw<100)throw new RuntimeException('External delays must not reduce the driver punctuality score. Leave it Not Evaluated or justify full credit.');
   if($late&&!in_array('Supporting Document',$kinds,true))throw new RuntimeException('Delay attribution requires verified supporting documentation.');
  }
  if($key==='completion'&&(!in_array('Delivery Verification',$kinds,true)||!in_array('POD',$kinds,true)))throw new RuntimeException('Delivery Completion needs verified delivery records and verified POD.');
  if($key==='fuel'&&(!in_array('Fuel',$kinds,true)||!(float)$trip['distance_km']||(!$consumption&&!in_array('Supporting Document',$kinds,true))))throw new RuntimeException('Fuel Efficiency needs actual consumption evidence and recorded distance. Fuel purchases alone are insufficient.');
  $scores[$key]=['score'=>round((float)$raw,2),'evidence_ids'=>$ids,'notes'=>$notes];$total+=(float)$raw*$weights[$key]/100;
  if($key==='on_time'&&$outcome!=='')$scores[$key]['delivery_outcome']=$outcome;
 }
 if($excluded)$timing['scoring_basis']=['excluded_criteria'=>$excluded,'applicable_weight'=>$applicableWeight,'description'=>'Fuel Efficiency excluded: no recorded fuel consumption. Remaining criteria normalized to 100; original configured weights preserved.'];
 return ['scores'=>$scores,'overall'=>$complete&&$applicableWeight>0?round($total*100/$applicableWeight,2):null,'timing'=>$timing];
}
function performance_evaluate(PDO $pdo,string $tripId,array $input,array $actor): int {
 performance_transaction($pdo);$trip=performance_trip($pdo,$tripId,$actor,true);
 if(!performance_delivery_verified($trip))throw new RuntimeException('Only verified completed cargo deliveries with an assigned driver, vehicle and arrival record may be evaluated.');
 $action=(string)($input['operation']??'');$reviewer=performance_reviewer($actor);
 if(($action==='submit'&&$actor['role_code']!=='dispatcher')||(!in_array($action,['submit','adjust','finalize'],true))||($action!=='submit'&&!$reviewer))throw new RuntimeException('Evaluation action not permitted.');
 $q=$pdo->prepare('SELECT * FROM trip_performance_evaluations WHERE trip_id=? FOR UPDATE');$q->execute([$tripId]);$old=$q->fetch();
 if($old&&$old['status']==='Finalized')throw new RuntimeException('This evaluation is already final. Use the dispute review process.');
 if($action==='finalize'&&$old){$q=$pdo->prepare("SELECT 1 FROM trip_performance_disputes WHERE evaluation_id=? AND status='Open'");$q->execute([$old['id']]);if($q->fetchColumn())throw new RuntimeException('Resolve the open driver dispute before final approval.');}
 if($action!=='submit'&&(!$old||$old['status']!=='Submitted'))throw new RuntimeException('Dispatcher must submit an initial evaluation first.');
 if($old&&(int)($input['revision']??0)!==(int)$old['revision'])throw new RuntimeException('Evaluation changed. Refresh before saving.');
 $weights=$old?json_decode($old['weights'],true):performance_config($pdo);$cause=(string)($input['delay_cause']??'Unknown');if(!in_array($cause,['Unknown','External','Driver','None'],true))throw new RuntimeException('Select a valid delay cause.');
 $delayNotes=trim((string)($input['delay_notes']??''));if(mb_strlen($delayNotes)>2000)throw new RuntimeException('Delay notes are too long.');
 if((performance_timing($trip)['variance_minutes']??0)>0&&$cause!=='Unknown')$delayNotes=performance_note($delayNotes,'Delay explanation');
 $result=performance_scores($pdo,$trip,(array)($input['scores']??[]),$weights,$cause);
 if($action==='finalize'&&$result['overall']===null)throw new RuntimeException('Cannot finalize: evidence or scores are missing. Keep incomplete criteria Not Evaluated.');
 $changeReason=$reviewer?performance_note($input['change_reason']??'','Review / adjustment reason'):null;
 $status=$action==='finalize'?'Finalized':'Submitted';
 if(!$old){$q=$pdo->prepare('INSERT INTO trip_performance_evaluations(trip_id,driver_id,weights) VALUES (?,?,?::jsonb) RETURNING id');$q->execute([$tripId,$trip['driver_id'],json_encode($weights)]);$id=(int)$q->fetchColumn();}else $id=(int)$old['id'];
 $pdo->prepare("UPDATE trip_performance_evaluations SET status=?,scores=?::jsonb,timing_snapshot=?::jsonb,delay_cause=?,delay_notes=?,overall_score=?,submitted_by=COALESCE(submitted_by,?),submitted_at=COALESCE(submitted_at,CURRENT_TIMESTAMP),finalized_by=?,finalized_at=CASE WHEN ?='Finalized' THEN CURRENT_TIMESTAMP ELSE NULL END,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$status,json_encode($result['scores']),json_encode($result['timing']),$cause,$delayNotes,$result['overall'],$actor['id'],$status==='Finalized'?$actor['id']:null,$status,$id]);
 performance_audit($pdo,'Evaluation '.($action==='finalize'?'Finalized':($old?'Adjusted':'Submitted')),(string)$id,$actor,$old?:null,['trip_id'=>$tripId,'status'=>$status,'scores'=>$result['scores'],'weights'=>$weights,'timing'=>$result['timing'],'delay_cause'=>$cause,'delay_notes'=>$delayNotes,'overall_score'=>$result['overall'],'reason'=>$changeReason]);return $id;
}
function performance_dispute(PDO $pdo,string $tripId,array $input,array $actor): void {
 performance_transaction($pdo);$trip=performance_trip($pdo,$tripId,$actor,true);$q=$pdo->prepare('SELECT * FROM trip_performance_evaluations WHERE trip_id=? FOR UPDATE');$q->execute([$tripId]);$eval=$q->fetch();
 if(!$eval||!in_array($eval['status'],['Submitted','Finalized'],true))throw new RuntimeException('No submitted evaluation is available.');
 if(($input['operation']??'')==='dispute'){
  if($actor['role_code']!=='driver'||(int)$trip['driver_user_id']!==(int)$actor['id'])throw new RuntimeException('Only the assigned driver may dispute this evaluation.');
  $reason=performance_note($input['reason']??'','Dispute reason');$ids=array_values(array_unique(array_map('intval',(array)($input['evidence_ids']??[]))));$available=array_column(performance_evidence($pdo,$tripId),'id');if(array_diff($ids,$available))throw new RuntimeException('Dispute evidence must belong to this trip.');
  $q=$pdo->prepare('INSERT INTO trip_performance_disputes(evaluation_id,driver_user_id,reason,evidence_ids) VALUES (?,?,?,?::jsonb) RETURNING id');$q->execute([$eval['id'],$actor['id'],$reason,json_encode($ids)]);$id=$q->fetchColumn();
  performance_audit($pdo,'Evaluation Disputed',(string)$eval['id'],$actor,null,['dispute_id'=>$id,'reason'=>$reason,'evidence_ids'=>$ids]);
 }else{
  if(!performance_reviewer($actor))throw new RuntimeException('Only Manager/Admin may resolve disputes.');$resolution=performance_note($input['resolution']??'','Resolution');$decision=(string)($input['decision']??'');if(!in_array($decision,['Upheld','Returned for Review'],true))throw new RuntimeException('Choose a dispute decision.');
  $q=$pdo->prepare("SELECT * FROM trip_performance_disputes WHERE id=? AND evaluation_id=? AND status='Open' FOR UPDATE");$q->execute([(int)($input['dispute_id']??0),$eval['id']]);$dispute=$q->fetch();if(!$dispute)throw new RuntimeException('Open dispute not found.');
  $pdo->prepare('UPDATE trip_performance_disputes SET status=?,resolution=?,resolved_by=?,resolved_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$decision,$resolution,$actor['id'],$dispute['id']]);
  if($decision==='Returned for Review')$pdo->prepare("UPDATE trip_performance_evaluations SET status='Submitted',finalized_by=NULL,finalized_at=NULL,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$eval['id']]);
  performance_audit($pdo,'Dispute Resolved',(string)$eval['id'],$actor,['evaluation'=>$eval,'dispute'=>$dispute],['decision'=>$decision,'resolution'=>$resolution]);
 }
}
