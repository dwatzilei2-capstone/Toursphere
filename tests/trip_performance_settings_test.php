<?php
if(PHP_SAPI!=='cli')exit;
$mode=$argv[1]??'manager';$argv[1]='stages';ob_start();require __DIR__.'/logistics_requests_test.php';ob_end_clean();
require_once ROOT_PATH.'/includes/trip_performance.php';
$checks=0;
function weight_check($value,string $label){global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
function weight_save(array $weights,string $role):void{global $pdo;$pdo->beginTransaction();try{performance_save_config($pdo,$weights,['id'=>1,'name'=>'Weight Test','role_code'=>$role]);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}}
function weight_reject(array $weights,string $role,string $label):void{try{weight_save($weights,$role);}catch(RuntimeException $e){weight_check(true,$label);return;}throw new RuntimeException($label);}
$original=performance_config($pdo);$new=array_replace($original,['on_time'=>30,'completion'=>20]);
$pdo->prepare("INSERT INTO trips(id,reservation_id,origin,destination,status) VALUES ('T-WEIGHTS',?,'Manila','Batangas','Completed')")->execute([$id]);
$pdo->prepare("INSERT INTO trip_performance_evaluations(trip_id,weights) VALUES ('T-WEIGHTS',?::jsonb)")->execute([json_encode($original)]);
foreach(['dispatcher','driver','staff','customer'] as $role)weight_reject($new,$role,'Cannot configure: '.$role);
foreach([array_replace($new,['on_time'=>31]),array_replace($new,['on_time'=>29]),array_replace($new,['on_time'=>'30.5']),array_replace($new,['safety'=>-1]),$new+['extra'=>0],array_diff_key($new,['conduct'=>true])] as $bad)weight_reject($bad,'fleet_admin','Invalid six-criterion weights rejected');
weight_check(performance_config($pdo)===$original,'Invalid saves leave PostgreSQL settings unchanged');
weight_save($new,'fleet_manager');weight_check(performance_config($pdo)===$new,'Manager saves exact weights');
$adminWeights=array_replace($new,['on_time'=>35,'completion'=>15]);weight_save($adminWeights,'fleet_admin');weight_check(performance_config($pdo)===$adminWeights,'Admin saves exact weights');
weight_check(performance_weights(json_decode($pdo->query("SELECT weights FROM trip_performance_evaluations WHERE trip_id='T-WEIGHTS'")->fetchColumn(),true))===$original,'Historical evaluation snapshot unchanged');
weight_check((int)$pdo->query("SELECT count(*) FROM audit_logs WHERE action='Weights Updated'")->fetchColumn()===2,'Successful changes audited');
$role=match($mode){'admin','post-admin'=>'fleet_admin','dispatcher','post-dispatcher'=>'dispatcher','driver','post-driver'=>'driver',default=>'fleet_manager'};
$current_user=['id'=>1,'name'=>'Settings Test','role_code'=>$role,'role_name'=>'Test Role','emp_id'=>'TEST','avatar'=>null];$current_permissions=['settings.manage'];$_SESSION['last_valid_activity']=time();$_SESSION['settings_csrf']='settings-test';$_SESSION['trip_performance_csrf']='trip-test';$_GET=['tab'=>'trip-performance-evaluation'];$_SERVER['REQUEST_METHOD']='GET';
$requestWeights=array_replace($adminWeights,['on_time'=>40,'completion'=>10]);
if(str_starts_with($mode,'post-')||in_array($mode,['invalid-total','invalid-csrf','old-route'],true)){
 $_SERVER['REQUEST_METHOD']='POST';$_POST=['csrf'=>$mode==='invalid-csrf'?'invalid':'settings-test','section'=>'trip-performance-evaluation','weights'=>$mode==='invalid-total'?array_replace($requestWeights,['safety'=>19]):$requestWeights];
 $expectSuccess=in_array($mode,['post-admin','post-manager'],true);$auditBefore=(int)$pdo->query("SELECT count(*) FROM audit_logs WHERE action='Weights Updated'")->fetchColumn();
 register_shutdown_function(function()use($pdo,$expectSuccess,$requestWeights,$adminWeights,$original,$mode,$auditBefore){
  weight_check(performance_config($pdo)===($expectSuccess?$requestWeights:$adminWeights),'Settings handler persistence and permissions: '.$mode);
  weight_check((int)$pdo->query("SELECT count(*) FROM audit_logs WHERE action='Weights Updated'")->fetchColumn()===$auditBefore+($expectSuccess?1:0),'Handler audit is atomic');
  weight_check(performance_weights(json_decode($pdo->query("SELECT weights FROM trip_performance_evaluations WHERE trip_id='T-WEIGHTS'")->fetchColumn(),true))===$original,'Handler preserves historical snapshots');
  echo "Settings action $mode passed using temporary tables.\n";
 });
 if($mode==='old-route'){$_POST=['csrf'=>'trip-test','operation'=>'weights','weights'=>$requestWeights];require ROOT_PATH.'/actions/trip-performance.php';}
 ob_start();require ROOT_PATH.'/settings.php';ob_end_clean();
}else{
 ob_start();require ROOT_PATH.'/settings.php';$html=ob_get_clean();
 $allowed=in_array($role,['fleet_admin','fleet_manager'],true);
 weight_check((strpos($html,'data-performance-weights')!==false)===$allowed,'Settings editor role visibility: '.$role);
 weight_check((strpos($html,'?tab=trip-performance-evaluation')!==false)===$allowed,'Settings section role visibility: '.$role);
 if($allowed)foreach(PERFORMANCE_CRITERIA as $key=>$label)weight_check(strpos($html,'name="weights['.$key.']"')!==false,'Settings includes '.$key);
 $view=file_get_contents(ROOT_PATH.'/includes/trip_performance_view.php');weight_check(strpos($view,'Configure Evaluation Weights')===false&&strpos($view,'name="weights[')===false,'No duplicate weights configuration on Trip Performance');
 weight_check(strpos($view,'Edit evidence / explanation')!==false,'Evaluation uses compact expandable controls');
 echo "$checks settings checks passed for $role using temporary tables.\n";
}
