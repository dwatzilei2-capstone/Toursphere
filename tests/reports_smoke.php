<?php
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/includes/bootstrap.php';
require ROOT_PATH.'/includes/reports.php';
require ROOT_PATH.'/includes/report_exports.php';
if(!is_dir(ROOT_PATH.'/tmp/reports-validation')) mkdir(ROOT_PATH.'/tmp/reports-validation',0750,true);
$current_user=db()->query("SELECT u.*,r.code role_code,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='fleet_admin' AND u.status='Active' LIMIT 1")->fetch();
foreach(array_keys(reports_catalog()) as $type) {
 $m=reports_get($type,['period'=>'custom','from'=>'2026-09-01','to'=>'2026-09-30'],true);
 echo $type.' '.json_encode(['metrics'=>$m['metrics'],'counts'=>array_column($m['sections'],'count')],JSON_UNESCAPED_UNICODE).PHP_EOL;
 file_put_contents(ROOT_PATH.'/tmp/reports-validation/'.$type.'.json',json_encode($m,JSON_UNESCAPED_UNICODE));
 foreach(['xlsx','pdf','csv'] as $format) ('reports_export_'.$format)($m,ROOT_PATH.'/tmp/reports-validation/'.$type.'.'.$format);
}
