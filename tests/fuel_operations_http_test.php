<?php
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__);$token=bin2hex(random_bytes(24));$env=getenv();$env['TOURSPHERE_FUEL_TEST_TOKEN']=$token;
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);$address=stream_socket_get_name($socket,false);fclose($socket);
$log=tempnam(sys_get_temp_dir(),'fuel-http-');$png=tempnam(sys_get_temp_dir(),'fuel-png-');$fake=tempnam(sys_get_temp_dir(),'fuel-fake-');$big=tempnam(sys_get_temp_dir(),'fuel-big-');$pdf=tempnam(sys_get_temp_dir(),'fuel-pdf-');
file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));file_put_contents($fake,'<?php not an image');
file_put_contents($big,file_get_contents($png).str_repeat('x',6*1024*1024));
require_once $root.'/tests/support/document_pdf.php';file_put_contents($pdf,toursphere_test_document_pdf(['TEST ONLY - FUEL RECEIPT UPLOAD VALIDATION','Not a genuine receipt.']));
$server=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','upload_tmp_dir='.$root.'/tmp','-S',$address,$root.'/tests/fuel_operations_http_fixture.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,$env);fclose($pipes[0]);
function fuel_http(array $fields=[],?string $file=null,string $name='receipt.png',string $role='driver',string $endpoint='submit'):array {
 global $address,$token;$c=curl_init('http://'.$address.'/'.$endpoint);if($file)$fields['receipt']=new CURLFile($file,'image/png',$name);
 curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_HTTPHEADER=>['X-Test-Token: '.$token,'X-Test-Role: '.$role],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25]);$body=curl_exec($c);curl_close($c);$result=json_decode($body,true);if(!$result)throw new RuntimeException('Unexpected HTTP response: '.$body);return $result;
}
$n=0;
try{
 for($i=0;$i<60;$i++){$c=curl_init('http://'.$address.'/health');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['X-Test-Token: '.$token],CURLOPT_CONNECTTIMEOUT_MS=>100]);$ready=curl_exec($c)==='ok';curl_close($c);if($ready)break;usleep(50000);}if(!$ready)throw new RuntimeException('Test server failed to start.');
 $r=fuel_http(['vehicle_id'=>'FORGED','driver_id'=>'FORGED','trip_id'=>'FORGED'],$png);
 if(!$r['saved']||$r['status']!=='Submitted'||!$r['hash_valid']||$r['owner']!=='D-CARGO'||$r['vehicle']!=='V-CARGO'||(float)$r['total']!==480.0||$r['verified_cost']!==null||!$r['public_unchanged'])throw new RuntimeException('Multipart submission/ownership/status failed.');$n++;
 foreach([[[],null,'receipt.png','driver'],[[],$png,'receipt.php','driver'],[[],$fake,'fake.png','driver'],[['csrf'=>'bad'],$png,'receipt.png','driver'],[['fuel_type'=>'Gasoline'],$png,'receipt.png','driver'],[['total_cost'=>481],$png,'receipt.png','driver'],[[],$png,'receipt.png','fleet_manager'],[[],$png,'receipt.png','staff']] as [$fields,$file,$name,$role]){$r=fuel_http($fields,$file,$name,$role);if($r['saved']||!$r['public_unchanged'])throw new RuntimeException('Invalid upload or unauthorized mutation accepted.');$n++;}
 $r=fuel_http([],$big,'large.png');if($r['saved']||!$r['public_unchanged'])throw new RuntimeException('Oversized receipt accepted.');$n++;
 $r=fuel_http([],$pdf,'receipt.pdf');if(!$r['saved']||!$r['hash_valid']||!$r['public_unchanged'])throw new RuntimeException('Valid PDF upload failed.');$n++;
 $r=fuel_http([],null,'','dispatcher','review');if($r['status']!=='Pending Verification'||$r['verified_cost']!==null)throw new RuntimeException('Dispatcher actual confirmation handler failed.');$n++;
 $r=fuel_http([],null,'','fleet_admin','review');if($r['status']!=='Verified'||(float)$r['verified_cost']!==480.0)throw new RuntimeException('Admin actual verification handler failed.');$n++;
 $r=fuel_http(['decision'=>'return','reason'=>''],null,'','fleet_admin','review');if($r['status']!=='Pending Verification')throw new RuntimeException('Admin return without reason accepted.');$n++;
 $r=fuel_http(['decision'=>'return','reason'=>'Correct station'],null,'','fleet_admin','review');if($r['status']!=='Returned'||$r['verified_cost']!==null)throw new RuntimeException('Admin return handler failed.');$n++;
 $r=fuel_http(['decision'=>'reject','reason'=>'Invalid receipt'],null,'','fleet_admin','review');if($r['status']!=='Rejected'||$r['verified_cost']!==null)throw new RuntimeException('Admin rejection handler failed.');$n++;
 $r=fuel_http([],null,'','driver','review');if($r['status']!=='Pending Verification'||$r['verified_cost']!==null)throw new RuntimeException('Driver verified a transaction.');$n++;
 foreach([['fleet_admin','',200],['driver','',200],['driver','&other=1',403],['customer','',403],['fleet_admin','&tamper=1',409],['fleet_admin','&missing=1',404]] as [$role,$extra,$expected]){
  $c=curl_init('http://'.$address.'/receipt?x=1'.$extra);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['X-Test-Token: '.$token,'X-Test-Role: '.$role]]);$body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
  if($status!==$expected||($status===200&&$body!==file_get_contents($png)))throw new RuntimeException('Receipt streaming or integrity/ownership failed: '.$role.' '.$status);$n++;
 }
 echo "$n real HTTP upload, Dispatcher confirmation, Admin verification/return/rejection, ownership, CSRF and verified-cost checks passed. Temporary tables only.\n";
}catch(Throwable $e){fwrite(STDERR,file_get_contents($log));throw $e;}finally{proc_terminate($server);proc_close($server);foreach([$log,$png,$fake,$big,$pdf] as $file)if(is_file($file))unlink($file);}
