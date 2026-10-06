<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
$token=bin2hex(random_bytes(24));$tokenFile=ROOT_PATH.'/tmp/fuel-receipt-test-token';file_put_contents($tokenFile,$token);
$sessions=json_decode(file_get_contents(ROOT_PATH.'/tmp/trip-funding-sessions.json'),true);
$media=ROOT_PATH.'/tmp/fuel-receipt-validation';if(!is_dir($media))mkdir($media,0700,true);
$png=$media.'/upload.png';file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
$jpg=$media.'/upload.jpg';file_put_contents($jpg,base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q=='));
$pdf=$media.'/upload.pdf';file_put_contents($pdf,"%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF");
$bad=$media.'/bad.jpg';file_put_contents($bad,'<?php echo "not an image";');
$big=$media.'/big.pdf';file_put_contents($big,'%PDF-1.4'.str_repeat(' ',5*1024*1024));
try {
 foreach([['none',null,null,true,null],['png',$png,'image/png',true,'image/png'],['jpg',$jpg,'image/jpeg',true,'image/jpeg'],['pdf',$pdf,'application/pdf',true,'application/pdf'],['invalid',$bad,'image/jpeg',false,null],['oversize',$big,'application/pdf',false,null],['extension',$png,'image/png',false,null]] as [$label,$path,$mime,$saved,$expectedMime]){
  $c=curl_init('http://localhost/fleet/tests/fuel_receipt_upload_fixture.php?token='.$token);$post=[];
  if($path)$post['receipt']=new CURLFile($path,$mime,$label==='extension'?'upload.php':basename($path));
  curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$sessions['fleet_admin']]);$body=curl_exec($c);curl_close($c);$data=json_decode($body,true);
  if(!$data || $data['saved']!==$saved || $data['mime']!==$expectedMime || ($saved && ((float)$data['total']!==2886.0 || (float)$data['default']!==95.0 || $data['vehicle']!=='LOG-V')) || ($expectedMime && empty($data['file_exists'])))throw new RuntimeException('Receipt case failed: '.$label.' '.$body);
  echo 'PASS: '.$label.PHP_EOL;
 }
 foreach(['driver','customer','fleet_admin'] as $role){$c=curl_init('http://localhost/fleet/actions/fuel-receipt.php?id=FL-DEMO-030');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$sessions[$role]]);curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);if(!in_array($status,[403,404],true))throw new RuntimeException('Missing receipt or unauthorized access not blocked: '.$role);}
 $receiptName=bin2hex(random_bytes(24)).'.png';$receiptPath=ROOT_PATH.'/storage/private/fuel-receipts/'.$receiptName;file_put_contents($receiptPath,file_get_contents($png));file_put_contents(ROOT_PATH.'/tmp/fuel-receipt-read-name',$receiptName);
 try {
  foreach([['fleet_admin','',200],['driver','',200],['driver','&other=1',403],['customer','',403]] as [$role,$extra,$expected]){
   $c=curl_init('http://localhost/fleet/tests/fuel_receipt_read_fixture.php?token='.$token.$extra);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$sessions[$role]]);$body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
   if($status!==$expected || ($status===200 && $body!==file_get_contents($png)))throw new RuntimeException('Receipt streaming/ownership failed '.$role);
  }
  echo "PASS: Actual receipt bytes stream for Admin and owner Driver; another Driver and Customer are denied.\n";
 } finally {unlink($receiptPath);unlink(ROOT_PATH.'/tmp/fuel-receipt-read-name');}
 echo "PASS: Missing/unauthorized receipt endpoints blocked. All upload transactions use temporary tables; test files removed.\n";
}finally{unlink($tokenFile);foreach([$png,$jpg,$pdf,$bad,$big] as $file)if(is_file($file))unlink($file);}
