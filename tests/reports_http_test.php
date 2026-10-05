<?php
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/includes/bootstrap.php';
$file=ROOT_PATH.'/tmp/reports-test-sessions.json'; $sessions=[]; $passed=0;
function reports_http_check(bool $ok,string $message): void { global $passed; if(!$ok) throw new RuntimeException($message); $passed++; }
function reports_http_request(string $id,string $path): array {
 $c=curl_init('http://localhost/fleet/'.$path); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIE=>session_name().'='.$id,CURLOPT_TIMEOUT=>60,CURLOPT_HEADER=>true]);
 $data=curl_exec($c); if($data===false) throw new RuntimeException(curl_error($c)); $code=curl_getinfo($c,CURLINFO_RESPONSE_CODE); $size=curl_getinfo($c,CURLINFO_HEADER_SIZE); curl_close($c); return [$code,substr($data,$size),substr($data,0,$size)];
}
if(in_array('--clean',$argv,true)) { foreach(json_decode(@file_get_contents($file)?:'[]',true) as $id) { session_id($id); session_start(); $_SESSION=[]; session_destroy(); } if(is_file($file)) unlink($file); echo "Report test sessions removed.\n"; exit; }
try {
 foreach(['fleet_admin','fleet_manager','dispatcher','driver','customer'] as $role) {
  $q=db()->prepare("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code=? AND u.status='Active' LIMIT 1"); $q->execute([$role]); $uid=$q->fetchColumn();
  $id=bin2hex(random_bytes(24)); session_id($id); session_start(); $_SESSION=['user_id'=>(int)$uid,'last_valid_activity'=>time()]; session_write_close(); $sessions[$role]=$id;
  [$status,$body]=reports_http_request($id,'reports.php?period=previous_month'); $allowed=in_array($role,['fleet_admin','fleet_manager','dispatcher'],true);
  reports_http_check($status===($allowed?200:403),'Reports page '.$role);
  if($allowed) { reports_http_check(substr_count($body,'class="tc-card report-card"')===($role==='dispatcher'?2:6),'Only permitted cards '.$role); reports_http_check(!str_contains($body,'August 2026') && str_contains($body,'September 1, 2026'),'Dynamic period'); }
  foreach(['fleet','reservations','trips','fuel','drivers','route'] as $type) {
   $can=$allowed && ($role!=='dispatcher' || in_array($type,['reservations','trips'],true));
   [$status,$body,$headers]=reports_http_request($id,'report-preview.php?report='.$type.'&period=previous_month');
   reports_http_check($status===($can?302:403),'Preview authorization '.$role.' '.$type);
   if($can) {
    preg_match('/snapshot=([a-f0-9]{40})/',$headers,$match); reports_http_check(!empty($match[1]),'Preview snapshot'); $token=$match[1];
    [$status,$body]=reports_http_request($id,'report-preview.php?snapshot='.$token); reports_http_check($status===200 && str_contains($body,'Operational Summary') && !str_contains($body,'Warning:'),'Preview data renders');
    if($role==='fleet_admin' && $type==='fuel') $adminToken=$token;
   }
   foreach(['csv','xlsx','pdf'] as $format) {
    [$status,$body,$headers]=reports_http_request($id,'actions/export.php?report='.$type.'&period=previous_month&format='.$format);
    reports_http_check($status===($can?200:403),'Export authorization '.$role.' '.$type.' '.$format);
    if($can) {
     reports_http_check(str_contains($headers,'TourSphere_') && str_contains($headers,'2026-09.'.$format),'Filename');
     reports_http_check($format==='xlsx'?str_starts_with($body,'PK'):($format==='pdf'?str_starts_with($body,'%PDF'):str_starts_with($body,"\xEF\xBB\xBF")),'Real format');
     reports_http_check(str_contains($headers,'X-Report-Snapshot: '.$token),'Export reuses preview dataset');
    }
   }
  }
 }
 foreach(['fleet_manager','dispatcher','driver','customer'] as $role) { [$status]=reports_http_request($sessions[$role],'actions/export.php?snapshot='.$adminToken.'&format=csv'); reports_http_check($status===403,'Another user snapshot denied '.$role); }
 foreach(['report-preview.php?snapshot=../../.env','actions/export.php?report=trips&period=custom&from=2026-02-30&to=2026-03-01','actions/export.php?report=trips&format=html'] as $path) { [$status,$body]=reports_http_request($sessions['fleet_admin'],$path); reports_http_check($status===422 && !str_contains($body,'SQLSTATE') && !str_contains($body,'C:\\'),'Invalid request sanitized'); }
 [$status]=reports_http_request($sessions['fleet_admin'],'storage/private/reports/'.$adminToken.'/manifest.json'); reports_http_check($status===403,'Snapshot storage not public');
 [$status]=reports_http_request($sessions['fleet_admin'],'lib/reporting/SimpleXLSXGen.php'); reports_http_check($status===403,'Bundled libraries not public');
 file_put_contents($file,json_encode($sessions));
} finally { if(!in_array('--browser',$argv,true)) foreach($sessions as $id) { session_id($id); session_start(); $_SESSION=[]; session_destroy(); } }
echo "$passed HTTP authorization, genuine downloads, snapshot consistency and private storage checks passed.\n";
