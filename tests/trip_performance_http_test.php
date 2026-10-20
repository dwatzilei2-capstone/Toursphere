<?php
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__);$token=bin2hex(random_bytes(24));$env=getenv();$env['TOURSPHERE_PERFORMANCE_TEST_TOKEN']=$token;
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);$address=stream_socket_get_name($socket,false);fclose($socket);$base='http://'.$address;
$log=tempnam(sys_get_temp_dir(),'perf-http-log-');$png=tempnam(sys_get_temp_dir(),'perf-http-png-');$fake=tempnam(sys_get_temp_dir(),'perf-http-fake-');
file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));file_put_contents($fake,'<?php echo "not an image";');
$server=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','upload_tmp_dir='.$root.'/tmp','-S',$address,$root.'/tests/trip_performance_http_fixture.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,$env);if(!is_resource($server))throw new RuntimeException('Cannot start isolated test server.');fclose($pipes[0]);
$checks=0;
function http_check($value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
function performance_http_upload(string $path,string $name,string $role='dispatcher',string $csrf='http-test-token'):array{
 global $base,$token;$c=curl_init($base.'/upload');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['X-Test-Token: '.$token,'X-Test-Role: '.$role],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['operation'=>'add_evidence','csrf'=>$csrf,'trip_id'=>'T-HTTP','source'=>'upload','kind'=>'POD','criteria[0]'=>'completion','description'=>'Isolated HTTP upload test only','evidence_file'=>new CURLFile($path,'image/png',$name)]]);$body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$error=curl_error($c);curl_close($c);$result=json_decode($body,true);if(!$result)throw new RuntimeException('Unexpected HTTP test response '.$status.': '.$error.' '.$body);return $result;
}
try{
 $ready=false;for($i=0;$i<80;$i++){$c=curl_init($base.'/health');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>100,CURLOPT_HTTPHEADER=>['X-Test-Token: '.$token]]);$ok=curl_exec($c);curl_close($c);if($ok==='ok'){$ready=true;break;}usleep(50000);}http_check($ready,'Test server ready');
 $r=performance_http_upload($png,'pod.png');http_check($r['count']===1&&$r['files'][0]['hash_valid'],'Real multipart upload saved with matching hash');http_check($r['files'][0]['mime']==='image/png'&&!$r['files'][0]['verified'],'Upload remains unverified');http_check($r['audit_count']===1&&$r['public_unchanged'],'Upload audited without changing operational records');
 http_check(str_contains($r['location'],'trip=T-HTTP'),'Evidence work stays open on the same trip');
 $r=performance_http_upload($png,'pod.png','driver');http_check($r['count']===1&&!$r['files'][0]['verified'],'Assigned driver can provide unverified POD');
 foreach([[$png,'pod.php','dispatcher','http-test-token'],[$fake,'fake.png','dispatcher','http-test-token'],[$png,'pod.png','fleet_manager','http-test-token'],[$png,'pod.png','dispatcher','invalid'],[$png,'pod.png','staff','http-test-token']] as $args){$r=performance_http_upload(...$args);http_check($r['count']===0&&$r['public_unchanged'],'Unsafe, unauthorized or invalid-CSRF upload rejected');}
 foreach([false,true] as $invalid){
  $post=['operation'=>'submit','trip_id'=>'T-HTTP','csrf'=>'http-test-token'];if($invalid)$post['scores[on_time][score]']=80;
  $c=curl_init($base.'/submit');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['X-Test-Token: '.$token,'X-Test-Role: dispatcher'],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=curl_exec($c);curl_close($c);$result=json_decode($body,true);
  http_check(is_array($result)&&isset($result['location']),'Evaluation response includes redirect');
  parse_str(parse_url($result['location'],PHP_URL_QUERY),$query);
  http_check($invalid?($query['trip']??null)==='T-HTTP'&&$query['type']==='danger':!isset($query['trip'])&&$query['type']==='success',$invalid?'Failed evaluation stays open for correction':'Successful evaluation returns to list with modal closed');
 }
 echo "$checks real HTTP upload, redirect and security checks passed using temporary tables.\n";
}finally{proc_terminate($server);proc_close($server);foreach([$log,$png,$fake] as $path)if(is_file($path))unlink($path);}
