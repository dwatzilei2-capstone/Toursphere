<?php
ini_set('display_errors','0');
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/reports.php';
reports_require();
$type=(string)($_GET['report']??'');
if($type!=='') reports_require($type);
$path=null;
try {
    $format=(string)($_GET['format']??'csv');
    if(!in_array($format,['xlsx','pdf','csv'],true)) throw new DomainException('Choose Excel, PDF or CSV.');
    $m=isset($_GET['snapshot'])?reports_read((string)$_GET['snapshot']):reports_get($type,$_GET);
    if($type!=='' && $m['type']!==$type) throw new DomainException('Report selection does not match this preview.');
    require_once ROOT_PATH.'/includes/report_exports.php';
    $path=reports_storage().'/'.$m['token'].'/'.bin2hex(random_bytes(12)).'.'.$format;
    ('reports_export_'.$format)($m,$path);
    reports_log($m,'Report exported',$format);
    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();
    $mime=['xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','pdf'=>'application/pdf','csv'=>'text/csv; charset=UTF-8'][$format];
    header('Content-Type: '.$mime); header('Content-Disposition: attachment; filename="'.reports_filename($m,$format).'"');
    header('Content-Length: '.filesize($path)); header('Cache-Control: private, no-store'); header('X-Report-Snapshot: '.$m['token']);
    readfile($path);
} catch(DomainException $e) { http_response_code(422); header('Content-Type: text/plain; charset=UTF-8'); echo $e->getMessage(); }
catch(Throwable $e) { error_log('Reports export: '.$e); http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); echo 'Unable to generate the report. Please try again.'; }
finally { if($path && is_file($path)) unlink($path); }
