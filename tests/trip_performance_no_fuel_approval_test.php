<?php
if(PHP_SAPI!=='cli')exit;
ob_start();require __DIR__.'/trip_performance_single_upload_test.php';ob_end_clean();$checks=0;
$trip=performance_trip($pdo,'T-ONEFILE',$dispatcher);
perf_check(performance_fuel_excludable($pdo,$trip),'No fuel sources permits exclusion');
perf_check(!performance_fuel_excludable($pdo,performance_trip($pdo,'T-PERF',$dispatcher)),'Existing fuel purchases prevent automatic exclusion');
$packetScores=[];
foreach(PERFORMANCE_CRITERIA as $key=>$label){
 if($key==='fuel')continue;
 $packetScores[$key]=['score'=>80,'evidence_ids'=>$key==='completion'?[$replacement,$linked]:[$replacement],'notes'=>'Verified combined packet supports '.$label];
 if($key==='on_time')$packetScores[$key]['delivery_outcome']='on_time';
}
$result=performance_scores($pdo,$trip,$packetScores,PERFORMANCE_DEFAULT_WEIGHTS,'Unknown');
perf_check($result['overall']===80.0,'Remaining 85 percent normalized to 100 without penalty or fabricated fuel grade');
perf_check($result['scores']['fuel']['score']===null&&$result['scores']['fuel']['status']==='excluded','Fuel remains explicitly unscored');
perf_check($result['timing']['scoring_basis']['applicable_weight']===85,'Calculation basis retained for audit');
$q=$pdo->query("SELECT revision FROM trip_performance_evaluations WHERE trip_id='T-ONEFILE'");
$submit=['operation'=>'submit','revision'=>$q->fetchColumn(),'scores'=>$packetScores,'delay_cause'=>'Unknown'];
perf_tx(fn()=>performance_evaluate($pdo,'T-ONEFILE',$submit,$dispatcher));
$eval=$pdo->query("SELECT * FROM trip_performance_evaluations WHERE trip_id='T-ONEFILE'")->fetch();
$approve=$submit;$approve['operation']='finalize';$approve['revision']=$eval['revision'];$approve['change_reason']='Reviewed five applicable criteria; no fuel consumption records exist.';
$missing=$approve;$missing['scores']['safety']['score']='';
perf_reject(fn()=>performance_evaluate($pdo,'T-ONEFILE',$missing,$admin),'Other missing weighted criteria still block final approval');
perf_tx(fn()=>performance_evaluate($pdo,'T-ONEFILE',$approve,$admin));
$final=$pdo->query("SELECT * FROM trip_performance_evaluations WHERE trip_id='T-ONEFILE'")->fetch();
perf_check($final['status']==='Finalized'&&(float)$final['overall_score']===80.0,'Admin can finalize documented evaluation with fuel excluded');
perf_check(json_decode($final['weights'],true)==PERFORMANCE_DEFAULT_WEIGHTS,'Original configured weights preserved');
$audit=$pdo->query("SELECT new_values FROM audit_logs WHERE action='Evaluation Finalized' ORDER BY id DESC LIMIT 1")->fetchColumn();
perf_check(str_contains($audit,'scoring_basis')&&str_contains($audit,'excluded'),'Final approval audit includes exclusion and calculation basis');
$pdo->prepare("INSERT INTO fuel_transactions(id,trip_id,vehicle_id,driver_id,transaction_date,fuel_type,liters,price_per_liter,total_cost) VALUES ('F-PENDING','T-ONEFILE','V-CARGO','D-CARGO',CURRENT_TIMESTAMP,'Diesel',10,60,600)")->execute();
perf_check(!performance_fuel_excludable($pdo,$trip),'Unverified fuel purchase cannot be bypassed as missing consumption');
$pending=performance_scores($pdo,$trip,$packetScores,PERFORMANCE_DEFAULT_WEIGHTS,'Unknown');
perf_check($pending['overall']===null&&!isset($pending['scores']['fuel']['status']),'When fuel records exist, normal fuel evidence requirements remain');
echo "$checks no-fuel approval checks passed using temporary tables; no operational evaluations finalized.\n";
