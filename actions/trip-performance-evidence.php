<?php
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/trip_performance.php';
require_login();
try{
 $pdo=db();$q=$pdo->prepare('SELECT * FROM trip_performance_evidence WHERE id=?');$q->execute([(int)($_GET['id']??0)]);$e=$q->fetch();if(!$e)throw new RuntimeException('Not found.');
 performance_trip($pdo,$e['trip_id'],$current_user);$path=performance_file_path($e['stored_filename']);if(!$path||!hash_equals($e['sha256'],hash_file('sha256',$path)))throw new RuntimeException('File unavailable.');
 header('Content-Type: '.$e['mime_type']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');
 header('Content-Disposition: attachment; filename="evidence-'.$e['id'].'.'.pathinfo($e['stored_filename'],PATHINFO_EXTENSION).'"');header('Content-Length: '.filesize($path));readfile($path);
}catch(Throwable $e){http_response_code(404);echo 'Evidence unavailable.';}
