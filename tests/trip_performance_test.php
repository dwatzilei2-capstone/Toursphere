<?php
if(PHP_SAPI!=='cli')exit;
$mode=$argv[1]??'';$argv[1]='stages';ob_start();require __DIR__.'/logistics_requests_test.php';ob_end_clean();
require_once ROOT_PATH.'/includes/trip_performance.php';
$checks=0;
function perf_check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function perf_tx(callable $fn){global $pdo;$pdo->beginTransaction();try{$out=$fn();$pdo->commit();return $out;}catch(Throwable $e){$pdo->rollBack();throw $e;}}
function perf_reject(callable $fn,string $label){try{perf_tx($fn);}catch(RuntimeException $e){perf_check(true,$label);return;}throw new RuntimeException($label);}
// Exercise the same POST handler used by manual forms, with isolated tables.
function perf_action(array $post,array $actor,callable $verify): never {
 global $current_user,$current_permissions;
 $current_user=$actor;$current_permissions=[];
 $_SESSION['last_valid_activity']=time();$_SESSION['trip_performance_csrf']='isolated-performance-action';
 $_SERVER['REQUEST_METHOD']='POST';$_POST=$post+['trip_id'=>'T-PERF','csrf'=>'isolated-performance-action'];
 register_shutdown_function($verify);
 require ROOT_PATH.'/actions/trip-performance.php';exit;
}
$dispatcher=['id'=>1,'name'=>'Dispatcher Test','role_code'=>'dispatcher'];$manager=['id'=>1,'name'=>'Manager Test','role_code'=>'fleet_manager'];$admin=['id'=>1,'name'=>'Admin Test','role_code'=>'fleet_admin'];$driver=['id'=>1,'name'=>'Driver Test','role_code'=>'driver'];
$pdo->exec("UPDATE drivers SET user_id=1 WHERE id='D-CARGO'");
$day=$input['departure_date'];$pdo->prepare("UPDATE reservations SET status='Completed',return_date=?,return_time='18:00',assigned_vehicle_id='V-CARGO',assigned_driver_id='D-CARGO' WHERE id=?")->execute([$day,$id]);
$pdo->prepare("INSERT INTO trips(id,reservation_id,driver_id,vehicle_id,origin,destination,status,scheduled_departure,actual_arrival,distance_km,current_step) VALUES ('T-PERF',?,'D-CARGO','V-CARGO','Manila Warehouse','Batangas Depot','Completed',?,?,100,'Verified Completed')")->execute([$id,$day.' 09:00',$day.' 20:00']);
$pdo->prepare("INSERT INTO trip_timeline(trip_id,title,event_time,completed,sort_order) VALUES ('T-PERF','Arrived',?,1,1)")->execute([$day.' 18:00']);
$pdo->prepare("INSERT INTO reservation_events(reservation_id,actor_id,status,reason) VALUES (?,1,'Verified Completed','Receipt reviewed')")->execute([$id]);
$pdo->exec("INSERT INTO fuel_transactions(id,vehicle_id,driver_id,trip_id,transaction_date,fuel_type,liters,price_per_liter,total_cost) VALUES ('F-PERF','V-CARGO','D-CARGO','T-PERF',CURRENT_TIMESTAMP,'Diesel',10,60,600)");
$pdo->exec("UPDATE fuel_transactions SET verification_status='Verified',confirmed_by=1,confirmed_at=CURRENT_TIMESTAMP,verified_by=1,verified_at=CURRENT_TIMESTAMP,receipt_filename='isolated-fixture.png' WHERE id='F-PERF'");
$trip=performance_trip($pdo,'T-PERF',$dispatcher);
perf_check(performance_timing($trip)['variance_minutes']===0.0,'Use retained delivery arrival rather than overwritten completion timestamp');
perf_check(count(performance_sources($pdo,$trip))===3,'Reuse arrival, verified delivery and fuel sources');
perf_reject(fn()=>performance_trip($pdo,'T-PERF',['id'=>999999,'role_code'=>'driver']),'Driver cannot read another driver evaluation');
perf_reject(fn()=>performance_trip($pdo,'T-PERF',['id'=>1,'role_code'=>'staff']),'Unrelated role denied');
perf_reject(fn()=>performance_weights(['on_time'=>25]),'Missing weights rejected');
perf_reject(fn()=>performance_weights(array_replace(PERFORMANCE_DEFAULT_WEIGHTS,['on_time'=>26])),'Weights must total 100');
perf_check(performance_weights(PERFORMANCE_DEFAULT_WEIGHTS)===PERFORMANCE_DEFAULT_WEIGHTS,'Configured weights accepted');
$arrival=perf_tx(fn()=>performance_add_evidence($pdo,'T-PERF',['source'=>'arrival','description'=>'Actual arrival checked against timeline'],$dispatcher));
perf_reject(fn()=>performance_add_evidence($pdo,'T-PERF',['source'=>'arrival','description'=>'Duplicate'],$dispatcher),'Duplicate source version rejected');
perf_reject(fn()=>performance_verify_evidence($pdo,'T-PERF',$arrival,'Reviewed',$manager),'Manager cannot replace Dispatcher evidence verification');
$scoreInput=['operation'=>'submit','scores'=>['on_time'=>['score'=>100,'evidence_ids'=>[$arrival],'notes'=>'Delivered at promised time']],'delay_cause'=>'None'];
perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$scoreInput,$dispatcher),'Unverified evidence cannot support score');
if($mode==='action-verify')perf_action(['operation'=>'verify_evidence','evidence_id'=>$arrival,'verification_notes'=>'Isolated Dispatcher manual-form verification'],$dispatcher,function()use($pdo,$arrival){
 $q=$pdo->prepare('SELECT verified_at,verification_notes FROM trip_performance_evidence WHERE id=?');$q->execute([$arrival]);$row=$q->fetch();
 perf_check($row['verified_at']!==null&&$row['verification_notes']==='Isolated Dispatcher manual-form verification','Dispatcher verification POST persists notes');
 echo "Dispatcher Verify Evidence form handler passed using temporary tables.\n";
});
perf_tx(fn()=>performance_verify_evidence($pdo,'T-PERF',$arrival,'Delivery timestamp confirmed',$dispatcher));
perf_reject(fn()=>performance_verify_evidence($pdo,'T-PERF',$arrival,'Again',$dispatcher),'Verified evidence immutable');
perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$scoreInput,$manager),'Only Dispatcher submits initial evaluation');
$evalId=perf_tx(fn()=>performance_evaluate($pdo,'T-PERF',$scoreInput,$dispatcher));
$getEval=fn()=>$pdo->query("SELECT * FROM trip_performance_evaluations WHERE trip_id='T-PERF'")->fetch();$eval=$getEval();
perf_check($eval['status']==='Submitted'&&$eval['overall_score']===null,'Incomplete evaluation stays Not Evaluated overall');
perf_check(json_decode($eval['scores'],true)['safety']['score']===null,'No evidence does not imply perfect safety');
$scoreInput['operation']='finalize';$scoreInput['revision']=$eval['revision'];$scoreInput['change_reason']='Reviewed';
perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$scoreInput,$manager),'Cannot finalize incomplete evaluation');
$deliverySource=array_keys(array_filter(performance_sources($pdo,$trip),fn($s)=>$s['kind']==='Delivery Verification'))[0];
$delivery=perf_tx(fn()=>performance_add_evidence($pdo,'T-PERF',['source'=>$deliverySource,'description'=>'Dispatch delivery verification record'],$dispatcher));
$fuel=perf_tx(fn()=>performance_add_evidence($pdo,'T-PERF',['source'=>'fuel:F-PERF','description'=>'Actual trip-linked fuel record'],$dispatcher));
foreach([$delivery,$fuel] as $eid)perf_tx(fn()=>performance_verify_evidence($pdo,'T-PERF',$eid,'Original record reviewed',$dispatcher));
$dir=ROOT_PATH.'/storage/private/trip-evidence';if(!is_dir($dir))mkdir($dir,0700,true);$testFiles=[];
register_shutdown_function(function()use(&$testFiles){foreach($testFiles as $path)if(is_file($path))unlink($path);});
function perf_document(array $criteria,string $kind,array $actor): int {global $pdo,$dir,$testFiles;$filename=bin2hex(random_bytes(24)).'.pdf';$path=$dir.'/'.$filename;$testFiles[]=$path;file_put_contents($path,"%PDF-1.4\nTemporary isolated test evidence. Not genuine.\n%%EOF");$file=['filename'=>$filename,'name'=>'isolated-test.pdf','mime'=>'application/pdf','size'=>filesize($path),'sha256'=>hash_file('sha256',$path)];return perf_tx(fn()=>performance_add_evidence($pdo,'T-PERF',['source'=>'upload','kind'=>$kind,'criteria'=>$criteria,'includes_pod'=>$kind==='Supporting Document'&&in_array('completion',$criteria,true),'description'=>'Temporary test document reviewed only in isolated tests'],$actor,$file));}
$support=perf_document(array_keys(PERFORMANCE_CRITERIA),'Supporting Document',$driver);$pod=$support;
perf_tx(fn()=>performance_verify_evidence($pdo,'T-PERF',$support,'Combined packet including signed receipt and supporting records reviewed',$dispatcher));
$full=['operation'=>'adjust','revision'=>$eval['revision'],'delay_cause'=>'None','change_reason'=>'Reviewed all evidence','scores'=>[]];
foreach(PERFORMANCE_CRITERIA as $key=>$label)$full['scores'][$key]=['score'=>80,'evidence_ids'=>match($key){'on_time'=>[$arrival],'completion'=>[$delivery,$pod],'fuel'=>[$fuel,$support],'documentation'=>[$pod],default=>[$support]},'notes'=>$label.' justified by verified evidence'];
if(in_array($mode,['action-submit','action-finalize'],true)){
 $manual=$full;$manual['operation']='submit';
 if($mode==='action-submit')perf_action($manual,$dispatcher,function()use($getEval){$saved=$getEval();
  perf_check($saved['status']==='Submitted'&&(float)$saved['overall_score']===80.0,'Dispatcher complete score POST saves 80');
  foreach(json_decode($saved['scores'],true) as $row)perf_check($row['notes']!==''&&count($row['evidence_ids'])>0,'Manual evidence and explanation persisted');
  echo "Dispatcher scoring form handler saved 80/100 with six evidence links and explanations using temporary tables.\n";
 });
 perf_tx(fn()=>performance_evaluate($pdo,'T-PERF',$manual,$dispatcher));$manual['revision']=$getEval()['revision'];$manual['operation']='finalize';
 $manual['change_reason']='Isolated Admin review of Dispatcher scores and verified evidence';
 perf_action($manual,$admin,function()use($pdo,$getEval){$saved=$getEval();
  perf_check($saved['status']==='Finalized'&&(float)$saved['overall_score']===80.0,'Admin approval POST finalizes 80');
  perf_check((int)$pdo->query("SELECT count(*) FROM audit_logs WHERE entity_type='trip_performance' AND action='Evaluation Finalized'")->fetchColumn()===1,'Exactly one final approval audited');
  echo "Admin Approve & Finalize form handler finalized 80/100 with one audited approval using temporary tables.\n";
 });
}
perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',array_replace($full,['revision'=>999]),$admin),'Stale edits rejected');
$bad=$full;$bad['scores']['completion']['evidence_ids']=[$delivery];perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$bad,$admin),'Completion requires POD');
$bad=$full;$bad['scores']['fuel']['evidence_ids']=[$fuel];perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$bad,$admin),'Fuel purchases alone cannot establish efficiency');
$bad=$full;$bad['scores']['safety']['evidence_ids']=[$delivery];perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$bad,$admin),'Evidence must support the correct criterion');
$bad=$full;$bad['scores']['safety']['score']=101;perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$bad,$admin),'Out-of-range score rejected');
perf_tx(fn()=>performance_evaluate($pdo,'T-PERF',$full,$admin));$eval=$getEval();
perf_check((float)$eval['overall_score']===80.0,'Weighted score calculated correctly');
$full['revision']=$eval['revision'];$full['operation']='finalize';
perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$full,$dispatcher),'Dispatcher cannot finalize');
perf_tx(fn()=>performance_dispute($pdo,'T-PERF',['operation'=>'dispute','reason'=>'Please review evidence','evidence_ids'=>[$support]],$driver));
perf_reject(fn()=>performance_dispute($pdo,'T-PERF',['operation'=>'dispute','reason'=>'Duplicate'],$driver),'Duplicate open dispute rejected');
perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$full,$manager),'Open dispute blocks finalization');
$disputeId=$pdo->query("SELECT id FROM trip_performance_disputes WHERE status='Open'")->fetchColumn();
perf_tx(fn()=>performance_dispute($pdo,'T-PERF',['operation'=>'resolve','dispute_id'=>$disputeId,'decision'=>'Upheld','resolution'=>'Evidence supports current assessment'],$admin));
perf_tx(fn()=>performance_evaluate($pdo,'T-PERF',$full,$manager));$eval=$getEval();
perf_check($eval['status']==='Finalized'&&(int)$eval['finalized_by']===1,'Manager single approval finalizes evaluation');
perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$full,$admin),'Second final approval rejected');
perf_check((int)$pdo->query('SELECT count(*) FROM trip_performance_evaluations')->fetchColumn()===1,'One evaluation per trip');
perf_tx(fn()=>performance_dispute($pdo,'T-PERF',['operation'=>'dispute','reason'=>'New supporting evidence'],$driver));$disputeId=$pdo->query("SELECT id FROM trip_performance_disputes WHERE status='Open'")->fetchColumn();
perf_tx(fn()=>performance_dispute($pdo,'T-PERF',['operation'=>'resolve','dispute_id'=>$disputeId,'decision'=>'Returned for Review','resolution'=>'Review new information'],$manager));$eval=$getEval();
perf_check($eval['status']==='Submitted'&&$eval['finalized_by']===null,'Dispute reopens same evaluation with audit history');
$full['revision']=$eval['revision'];perf_tx(fn()=>performance_evaluate($pdo,'T-PERF',$full,$admin));
perf_check($getEval()['status']==='Finalized','Admin has equal authority to finalize');
perf_check((int)$pdo->query("SELECT count(*) FROM audit_logs WHERE action='Evaluation Finalized' AND entity_type='trip_performance'")->fetchColumn()===2,'Original and reconsidered final approvals retained in audit');
// Reopen for timing/evidence change and delay attribution checks.
perf_tx(fn()=>performance_dispute($pdo,'T-PERF',['operation'=>'dispute','reason'=>'Timing review'],$driver));$disputeId=$pdo->query("SELECT id FROM trip_performance_disputes WHERE status='Open'")->fetchColumn();
perf_tx(fn()=>performance_dispute($pdo,'T-PERF',['operation'=>'resolve','dispute_id'=>$disputeId,'decision'=>'Returned for Review','resolution'=>'Check delay cause'],$admin));
$pdo->prepare("UPDATE reservations SET return_time='17:00' WHERE id=?")->execute([$id]);$eval=$getEval();$full['revision']=$eval['revision'];
perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$full,$manager),'Changed arrival schedule invalidates verified snapshot');
$arrival=perf_tx(fn()=>performance_add_evidence($pdo,'T-PERF',['source'=>'arrival','description'=>'Updated schedule compared with actual arrival'],$dispatcher));perf_tx(fn()=>performance_verify_evidence($pdo,'T-PERF',$arrival,'New timing checked',$dispatcher));
$full['scores']['on_time']['evidence_ids']=[$arrival,$support];$full['delay_cause']='Unknown';
perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$full,$manager),'Unknown responsibility cannot penalize driver');
$full['delay_cause']='External';$full['delay_notes']='Receiver warehouse was unavailable';
perf_reject(fn()=>performance_evaluate($pdo,'T-PERF',$full,$manager),'External delay cannot reduce driver punctuality score');
$full['scores']['on_time']['score']=100;perf_tx(fn()=>performance_evaluate($pdo,'T-PERF',$full,$manager));
perf_check((float)$getEval()['overall_score']===85.0,'External delay handled with evidence and no punctuality penalty');
// Driver page contains only own cargo data, with legacy fleet charts withheld.
$renderActor=match($mode){'render-manager'=>$manager,'render-admin'=>$admin,'render-dispatcher'=>$dispatcher,default=>$driver};
if(in_array($mode,['render-manager','render-admin','render-dispatcher'],true))$pdo->exec("UPDATE trip_performance_evaluations SET status='Submitted',finalized_by=NULL,finalized_at=NULL WHERE trip_id='T-PERF'");
$current_user=$renderActor+['role_name'=>'Test Role','emp_id'=>'TEST','avatar'=>null];$current_permissions=[];$_SESSION['last_valid_activity']=time();$_GET=['trip'=>'T-PERF'];
ob_start();require ROOT_PATH.'/modules/driver-trip-performance/trip-performance.php';$html=ob_get_clean();
foreach(['Trip Performance Evaluation Matrix','Verified Evidence','Evaluation Audit Trail'] as $label)perf_check(strpos($html,$label)!==false,'Role page shows '.$label);
if($renderActor['role_code']==='driver'){
 perf_check(strpos($html,'Submit Dispute')!==false,'Driver can dispute');
 perf_check(strpos($html,'Total Fleet Completed Trips:')===false,'Driver does not see fleet analytics');
 perf_check(strpos($html,'Approve &amp; Finalize')===false,'Driver has no approval control');
 perf_check(strpos($html,'name="operation" value="verify_evidence"')===false,'Driver cannot verify evidence');
}elseif(in_array($mode,['render-manager','render-admin'],true)){
 perf_check(strpos($html,'Approve &amp; Finalize')!==false&&strpos($html,'Save Review')!==false,'Manager sees equal review and finalization controls');
 perf_check(strpos($html,'Submit Initial Evaluation')===false,'Manager cannot replace initial Dispatcher submission');
}else{
 perf_check(strpos($html,'Submit Initial Evaluation')!==false,'Dispatcher sees initial submission control');
 perf_check(strpos($html,'Approve &amp; Finalize')===false,'Dispatcher cannot finalize');
}
if($renderActor['role_code']!=='driver'){
 $dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);
 foreach(PERFORMANCE_CRITERIA as $key=>$label){
  perf_check($xp->query('//input[@name="scores['.$key.'][score]"]')->length===1,'Manual score input: '.$key);
  perf_check($xp->query('//input[@name="scores['.$key.'][evidence_ids][]"]')->length>0,'Verified criterion-specific checkboxes: '.$key);
  perf_check($xp->query('//textarea[@name="scores['.$key.'][notes]"]')->length===1,'Manual explanation input: '.$key);
 }
}
if($mode==='csrf'){
 $_SERVER['REQUEST_METHOD']='POST';$_POST=['csrf'=>'invalid','operation'=>'weights','weights'=>PERFORMANCE_DEFAULT_WEIGHTS];$current_user=$admin;
 $before=$pdo->query('SELECT count(*) FROM audit_logs')->fetchColumn();register_shutdown_function(function()use($pdo,$before){if($pdo->query('SELECT count(*) FROM audit_logs')->fetchColumn()!==$before)exit(1);echo "CSRF rejects mutations.\n";});require ROOT_PATH.'/actions/trip-performance.php';
}
echo "$checks evidence, role, scoring, approval, dispute and render checks passed; only temporary tables used.\n";
