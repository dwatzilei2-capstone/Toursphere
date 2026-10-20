<?php
if(PHP_SAPI!=='cli')exit;
ob_start();require __DIR__.'/trip_performance_test.php';ob_end_clean();$checks=0;
$singleRes=logistics_create_mock_request($pdo,$input,$actor);
$pdo->prepare("UPDATE reservations SET status='Completed' WHERE id=?")->execute([$singleRes]);
$pdo->prepare("INSERT INTO trips(id,reservation_id,driver_id,vehicle_id,origin,destination,status,current_step,actual_arrival) VALUES ('T-ONEFILE',?,'D-CARGO','V-CARGO','Origin','Destination','Completed','Verified Completed',CURRENT_TIMESTAMP)")->execute([$singleRes]);
$pdo->prepare("INSERT INTO reservation_events(reservation_id,actor_id,status,reason) VALUES (?,1,'Verified Completed','Isolated packet test')")->execute([$singleRes]);
function single_packet(array $who): int {
 global $pdo,$dir,$testFiles;
 $filename=bin2hex(random_bytes(24)).'.pdf';$path=$dir.'/'.$filename;$testFiles[]=$path;file_put_contents($path,"%PDF-1.4\nSynthetic combined delivery packet\n%%EOF");
 $file=['filename'=>$filename,'name'=>'combined-test.pdf','mime'=>'application/pdf','size'=>filesize($path),'sha256'=>hash_file('sha256',$path)];
 return perf_tx(fn()=>performance_add_evidence($pdo,'T-ONEFILE',['source'=>'upload','kind'=>'Supporting Document','criteria'=>array_keys(PERFORMANCE_CRITERIA),'includes_pod'=>1,'description'=>'Synthetic packet with POD and warehouse assessment'],$who,$file));
}
$first=single_packet($driver);
perf_check(count(performance_evidence($pdo,'T-ONEFILE'))===1,'First upload accepted');
perf_reject(fn()=>single_packet($dispatcher),'Second uploaded file rejected across users');
perf_reject(fn()=>performance_remove_upload($pdo,'T-ONEFILE',$first,$manager),'Unrelated removal role rejected');
perf_reject(fn()=>performance_remove_upload($pdo,'T-PERF',$first,$dispatcher),'Cross-trip removal rejected');
perf_tx(fn()=>performance_remove_upload($pdo,'T-ONEFILE',$first,$driver));
perf_check(performance_evidence($pdo,'T-ONEFILE')===[],'Own unverified file can be removed from active evidence');
$q=$pdo->prepare('SELECT snapshot,stored_filename FROM trip_performance_evidence WHERE id=?');$q->execute([$first]);$archived=$q->fetch();
perf_check(!empty(json_decode($archived['snapshot'],true)['removed_at'])&&performance_file_path($archived['stored_filename'])!==null,'Original retained privately with removal history');
perf_reject(fn()=>performance_verify_evidence($pdo,'T-ONEFILE',$first,'Review',$dispatcher),'Removed upload cannot be verified');
$replacement=single_packet($dispatcher);
perf_check(!performance_evidence($pdo,'T-ONEFILE')[0]['verified_at'],'Replacement requires fresh verification');
perf_tx(fn()=>performance_verify_evidence($pdo,'T-ONEFILE',$replacement,'Combined test document reviewed',$dispatcher));
perf_reject(fn()=>performance_remove_upload($pdo,'T-ONEFILE',$replacement,$driver),'Driver cannot remove a verified Dispatcher document');
$trip=performance_trip($pdo,'T-ONEFILE',$dispatcher);$deliveryKey=array_key_first(array_filter(performance_sources($pdo,$trip),fn($s)=>$s['kind']==='Delivery Verification'));
$linked=perf_tx(fn()=>performance_add_evidence($pdo,'T-ONEFILE',['source'=>$deliveryKey,'description'=>'System completion verification'],$dispatcher));
perf_tx(fn()=>performance_verify_evidence($pdo,'T-ONEFILE',$linked,'Reviewed system completion',$dispatcher));
$evaluationInput=['operation'=>'submit','scores'=>['completion'=>['score'=>80,'notes'=>'Combined packet includes reviewed signed receipt','evidence_ids'=>[$replacement,$linked]]]];
perf_tx(fn()=>performance_evaluate($pdo,'T-ONEFILE',$evaluationInput,$dispatcher));
perf_check((int)$pdo->query("SELECT count(*) FROM trip_performance_evaluations WHERE trip_id='T-ONEFILE'")->fetchColumn()===1,'One verified packet plus system records supports completion evaluation');
perf_reject(fn()=>performance_remove_upload($pdo,'T-ONEFILE',$replacement,$dispatcher),'File linked in saved evaluation cannot be removed silently');
perf_reject(fn()=>performance_remove_upload($pdo,'T-ONEFILE',$linked,$dispatcher),'System records are not removable uploads');
echo "$checks single-upload, removal, replacement and combined-packet checks passed using temporary tables and synthetic files.\n";
