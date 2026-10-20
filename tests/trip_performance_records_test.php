<?php
if(PHP_SAPI!=='cli')exit;
$mode=$argv[1]??'dispatcher';$argv[1]='stages';ob_start();require __DIR__.'/logistics_requests_test.php';ob_end_clean();
require_once ROOT_PATH.'/includes/trip_performance.php';
$checks=0;function records_check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$pdo->exec("UPDATE drivers SET user_id=1 WHERE id='D-CARGO'");
$roles=['dispatcher','fleet_manager','fleet_admin','driver'];
$candidate=['status'=>'Completed','reservation_status'=>'Completed','current_step'=>'Verified Completed','delivery_verified'=>true,'driver_id'=>'D-CARGO','vehicle_id'=>'V-CARGO','actual_arrival'=>date('Y-m-d H:i:s'),'evaluation_status'=>null];
foreach($roles as $role){$actorForRole=['id'=>1,'role_code'=>$role];records_check((performance_record_action($candidate,$actorForRole)['label']==='Evaluate')===($role==='dispatcher'),'Only Dispatcher evaluates initially: '.$role);
 records_check(performance_record_action(array_replace($candidate,['evaluation_status'=>'Finalized']),$actorForRole)['label']==='View Evaluation','Finalized action: '.$role);
 records_check(performance_record_action(array_replace($candidate,['evaluation_status'=>'Submitted']),$actorForRole)['label']===match($role){'fleet_manager','fleet_admin'=>'Review Evaluation','dispatcher'=>'Edit Evaluation',default=>'View Evaluation'},'Submitted action: '.$role);
}
foreach(['status'=>'In Transit','reservation_status'=>'Dispatched','current_step'=>'Completed & Returned','delivery_verified'=>false,'driver_id'=>null,'vehicle_id'=>null,'actual_arrival'=>null] as $key=>$value)records_check(performance_record_action(array_replace($candidate,[$key=>$value]),['id'=>1,'role_code'=>'dispatcher'])['label']!=='Evaluate','Incomplete eligibility blocks Evaluate: '.$key);
foreach(['unverified','eligible','submitted','finalized'] as $stage){
 $reservation=logistics_create_mock_request($pdo,$input,$actor);
 $pdo->prepare("UPDATE reservations SET status='Completed',assigned_vehicle_id='V-CARGO',assigned_driver_id='D-CARGO' WHERE id=?")->execute([$reservation]);
 $tripId='T-'.strtoupper($stage);$pdo->prepare("INSERT INTO trips(id,reservation_id,driver_id,vehicle_id,origin,destination,status,current_step,actual_arrival) VALUES (?,?,'D-CARGO','V-CARGO','Manila','Batangas','Completed',?,CURRENT_TIMESTAMP)")->execute([$tripId,$reservation,$stage==='unverified'?'Completed & Returned':'Verified Completed']);
 if($stage!=='unverified')$pdo->prepare("INSERT INTO reservation_events(reservation_id,actor_id,status,reason) VALUES (?,1,'Verified Completed','Temporary test evidence')")->execute([$reservation]);
 if(in_array($stage,['submitted','finalized'],true))$pdo->prepare("INSERT INTO trip_performance_evaluations(trip_id,driver_id,weights,status,submitted_by,submitted_at,finalized_by,finalized_at,overall_score) VALUES (?,'D-CARGO',?::jsonb,?,1,CURRENT_TIMESTAMP,?,CASE WHEN ?='Finalized' THEN CURRENT_TIMESTAMP ELSE NULL END,?)")->execute([$tripId,json_encode(PERFORMANCE_DEFAULT_WEIGHTS),ucfirst($stage),$stage==='finalized'?1:null,ucfirst($stage),$stage==='finalized'?80:null]);
}
$otherReservation=logistics_create_mock_request($pdo,$input,$actor);$pdo->prepare("INSERT INTO trips(id,reservation_id,origin,destination,status) VALUES ('T-OTHER',?,'Manila','Batangas','Scheduled')")->execute([$otherReservation]);
$pdo->beginTransaction();try{performance_evaluate($pdo,'T-UNVERIFIED',['operation'=>'submit'],['id'=>1,'role_code'=>'dispatcher']);throw new LogicException('Unverified completed trip evaluated');}catch(RuntimeException $e){$pdo->rollBack();records_check(true,'Backend blocks unverified completed evaluation');}
$isModal=str_starts_with($mode,'modal-');$role=match(str_replace('modal-','',$mode)){'manager'=>'fleet_manager','admin'=>'fleet_admin','driver'=>'driver',default=>'dispatcher'};
$current_user=['id'=>1,'name'=>'Records Test','role_code'=>$role,'role_name'=>'Test Role','emp_id'=>'TEST','avatar'=>null];$current_permissions=[];$_SESSION['last_valid_activity']=time();$_SERVER['REQUEST_METHOD']='GET';
$_GET=$isModal?['trip'=>$role==='dispatcher'?'T-ELIGIBLE':($role==='driver'?'T-FINALIZED':'T-SUBMITTED')]:[];
ob_start();require ROOT_PATH.'/modules/driver-trip-performance/trip-performance.php';$html=ob_get_clean();
records_check(strpos($html,'Trip Performance Records')!==false,'Existing records table rendered');
records_check(strpos($html,'Select a cargo trip')===false&&strpos($html,'<select class="form-select" name="trip"')===false,'No trip selector');
records_check(strpos($html,'Configure Evaluation Weights')===false&&strpos($html,'name="weights[')===false,'Weights remain only in Settings');
records_check((strpos($html,'id="modal-trip-performance"')!==false)===$isModal,'Modal exists only for a selected contextual action');
$dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
$table=$xpath->query('//table[thead/tr/th[text()="Trip ID"]]')->item(0);records_check($table!==null,'Records table has required headers');
$tableHtml=$dom->saveHTML($table);
records_check((strpos($tableHtml,'>Evaluate</a>')!==false)===($role==='dispatcher'),'Role-specific Evaluate in records');
records_check((strpos($tableHtml,'T-OTHER')!==false)===($role!=='driver'),'Driver list excludes other drivers trips');
if($isModal){
 records_check(strpos($html,'aria-modal="true"')!==false,'Uses accessible existing modal component');
 records_check((strpos($html,'Submit Initial Evaluation')!==false)===($role==='dispatcher'),'Initial evaluation form restricted');
 records_check((strpos($html,'Approve &amp; Finalize')!==false)===in_array($role,['fleet_manager','fleet_admin'],true),'Review approval form restricted');
 records_check((strpos($html,'Submit Dispute')!==false)===($role==='driver'),'Driver dispute retained');
}
echo "$checks trip records and modal checks passed for $mode using temporary tables.\n";
