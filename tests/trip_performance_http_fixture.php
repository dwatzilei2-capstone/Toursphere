<?php
// Only the explicitly started, loopback-only test server may execute this fixture.
$token=getenv('TOURSPHERE_PERFORMANCE_TEST_TOKEN');
if(PHP_SAPI!=='cli-server'||!$token||!hash_equals($token,(string)($_SERVER['HTTP_X_TEST_TOKEN']??''))){http_response_code(404);exit;}
if($_SERVER['REQUEST_URI']==='/health'){echo 'ok';exit;}
$argv[1]='stages';ob_start();$fixture=file_get_contents(__DIR__.'/logistics_requests_test.php');$fixture=str_replace("if(PHP_SAPI!=='cli')exit;",'',$fixture);eval(substr($fixture,5));ob_end_clean();
require_once ROOT_PATH.'/includes/trip_performance.php';
$pdo->exec("UPDATE drivers SET user_id=1 WHERE id='D-CARGO'");
$pdo->prepare("INSERT INTO trips(id,reservation_id,driver_id,vehicle_id,origin,destination,status,scheduled_departure) VALUES ('T-HTTP',?,'D-CARGO','V-CARGO','Manila','Batangas','Completed',CURRENT_TIMESTAMP)")->execute([$id]);
$role=(string)($_SERVER['HTTP_X_TEST_ROLE']??'dispatcher');$current_user=['id'=>1,'name'=>'HTTP Test','role_code'=>$role];$current_permissions=[];$_SESSION['trip_performance_csrf']='http-test-token';
if($_SERVER['REQUEST_URI']==='/submit'){
 $pdo->exec("UPDATE trips SET current_step='Verified Completed',actual_arrival=CURRENT_TIMESTAMP WHERE id='T-HTTP'");
 $pdo->prepare("UPDATE reservations SET status='Completed' WHERE id=?")->execute([$id]);
 $pdo->prepare("INSERT INTO reservation_events(reservation_id,actor_id,status,reason) VALUES (?,1,'Verified Completed','Isolated redirect fixture')")->execute([$id]);
}
$before=(int)$pdo->query('SELECT count(*) FROM public.trip_performance_evidence')->fetchColumn();
register_shutdown_function(function()use($pdo,$before){
 $rows=$pdo->query('SELECT * FROM trip_performance_evidence')->fetchAll();$files=[];
 foreach($rows as $row){$path=performance_file_path($row['stored_filename']);$files[]=['mime'=>$row['mime_type'],'size'=>$row['file_size'],'verified'=>$row['verified_at']!==null,'hash_valid'=>$path&&hash_equals($row['sha256'],hash_file('sha256',$path))];if($path)unlink($path);}
 $location=null;foreach(headers_list() as $header)if(stripos($header,'Location:')===0)$location=trim(substr($header,9));
 echo json_encode(['location'=>$location,'count'=>count($rows),'files'=>$files,'public_unchanged'=>$before===(int)$pdo->query('SELECT count(*) FROM public.trip_performance_evidence')->fetchColumn(),'audit_count'=>(int)$pdo->query("SELECT count(*) FROM audit_logs WHERE entity_type='trip_performance'")->fetchColumn()]);
});
require ROOT_PATH.'/actions/trip-performance.php';
