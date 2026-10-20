<?php
if(PHP_SAPI!=='cli')exit;
if(($argv[1]??'')==='worker'){
 require dirname(__DIR__).'/includes/bootstrap.php';require ROOT_PATH.'/includes/trip_performance.php';
 $schema=$argv[2]??'';if(!preg_match('/^perf_test_[a-f0-9]{16}$/D',$schema))throw new RuntimeException('Invalid test schema.');
 $pdo=db();$pdo->exec('SET search_path TO "'.$schema.'",public');
 $q=$pdo->query("SELECT * FROM trip_performance_evaluations WHERE trip_id='T-PERF'");$evaluation=$q->fetch();
 $input=['operation'=>'finalize','revision'=>$evaluation['revision'],'scores'=>json_decode($evaluation['scores'],true),'delay_cause'=>$evaluation['delay_cause'],'delay_notes'=>$evaluation['delay_notes'],'change_reason'=>'Concurrent approval test'];
 $pdo->beginTransaction();
 try{performance_evaluate($pdo,'T-PERF',$input,['id'=>1,'name'=>'Concurrent test reviewer','role_code'=>$argv[3]]);$pdo->commit();echo 'FINALIZED';}
 catch(RuntimeException $e){$pdo->rollBack();if(strpos($e->getMessage(),'already final')===false)throw $e;echo 'REJECTED_DUPLICATE';}exit;
}
// Reuse completed, evidence-backed fixtures; every operational table is temporary.
$argv[1]='';ob_start();require __DIR__.'/trip_performance_test.php';ob_end_clean();
$schema='perf_test_'.bin2hex(random_bytes(8));$tables=['users','drivers','reservations','trips','trip_timeline','reservation_events','fuel_transactions','route_history','driver_ratings','system_settings','audit_logs','trip_performance_evaluations','trip_performance_evidence','trip_performance_disputes'];$workers=[];
try{
 $pdo->exec('CREATE SCHEMA "'.$schema.'"');
 foreach($tables as $table){
  $pdo->exec('CREATE TABLE "'.$schema.'"."'.$table.'" (LIKE public."'.$table.'" INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES INCLUDING IDENTITY)');
  $q=$pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=? AND column_default LIKE 'nextval(%'");$q->execute([$table]);
  foreach($q->fetchAll(PDO::FETCH_COLUMN) as $column){$seq=$table.'_'.$column.'_isolated';$pdo->exec('CREATE SEQUENCE "'.$schema.'"."'.$seq.'" START 2000000');$pdo->exec('ALTER TABLE "'.$schema.'"."'.$table.'" ALTER COLUMN "'.$column.'" SET DEFAULT nextval(\'"'.$schema.'"."'.$seq.'"\')');}
  $pdo->exec('INSERT INTO "'.$schema.'"."'.$table.'" SELECT * FROM pg_temp."'.$table.'"');
 }
 $pdo->exec('SET search_path TO "'.$schema.'",public');
 $pdo->exec('UPDATE "'.$schema.'".trip_performance_evaluations SET status=\'Submitted\',finalized_by=NULL,finalized_at=NULL WHERE trip_id=\'T-PERF\'');
 $before=(int)$pdo->query('SELECT count(*) FROM "'.$schema.'".audit_logs WHERE action=\'Evaluation Finalized\'')->fetchColumn();
 $pdo->beginTransaction();$pdo->query('SELECT id FROM "'.$schema.'".trips WHERE id=\'T-PERF\' FOR UPDATE');
 foreach(['fleet_manager','fleet_admin'] as $role){$process=proc_open([PHP_BINARY,__FILE__,'worker',$schema,$role],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));if(!is_resource($process))throw new RuntimeException('Cannot start test worker.');fclose($pipes[0]);$workers[]=[$process,$pipes];}
 // Both sessions queue behind the same trip lock before either may finalize.
 usleep(500000);$pdo->commit();$outcomes=[];
 foreach($workers as [$process,$pipes]){$outcomes[]=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($error);}$workers=[];
 sort($outcomes);if($outcomes!==['FINALIZED','REJECTED_DUPLICATE'])throw new RuntimeException('Unexpected concurrent outcomes: '.json_encode($outcomes));
 if((int)$pdo->query('SELECT count(*) FROM "'.$schema.'".audit_logs WHERE action=\'Evaluation Finalized\'')->fetchColumn()!==$before+1)throw new RuntimeException('Duplicate final audit event.');
 echo "Concurrent Manager/Admin approval passed: one finalization, one duplicate rejected.\n";
}finally{
 if($pdo->inTransaction())$pdo->rollBack();foreach($workers as [$process,$pipes]){proc_terminate($process);foreach([1,2] as $i)if(is_resource($pipes[$i]))fclose($pipes[$i]);proc_close($process);}
 $pdo->exec('SET search_path TO public');
 // The checked random test schema owns all copied tables and sequences; no public dependencies.
 if(preg_match('/^perf_test_[a-f0-9]{16}$/D',$schema))$pdo->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
}
