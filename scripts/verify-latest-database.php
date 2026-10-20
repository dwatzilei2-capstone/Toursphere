<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
$dirs=glob(ROOT_PATH.'/storage/private/latest-database-*',GLOB_ONLYDIR);sort($dirs);$dir=end($dirs);
$manifest=json_decode(file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$name='toursphere_restore_check_'.bin2hex(random_bytes(5));
$env=getenv();$env['PGPASSWORD']=DB_PASS;$env['PGSSLMODE']=DB_SSLMODE;
$created=false;
try{
 db()->exec('CREATE DATABASE "'.$name.'"');$created=true;
 foreach($manifest['parts'] as $part){
  $compressed=file_get_contents($dir.'/'.$part['file']);
  if(strlen($compressed)>=1000000||hash('sha256',$compressed)!==$part['sha256'])throw new RuntimeException('Part integrity failure');
  $sql=gzdecode($compressed);if($sql===false)throw new RuntimeException('Invalid gzip');
  $temp=$dir.'/verify-part.sql';file_put_contents($temp,$sql);
  $args=['C:/Program Files/PostgreSQL/18/bin/psql.exe','--host='.DB_HOST,'--port='.DB_PORT,'--username='.DB_USER,'--dbname='.$name,'--no-psqlrc','--set=ON_ERROR_STOP=1','--file='.$temp];
  $proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
  fclose($pipes[0]);stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  if(proc_close($proc)!==0)throw new RuntimeException('Restore failed for '.$part['file'].': '.$err);
  unlink($temp);
 }
 $test=new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',DB_HOST,DB_PORT,$name,DB_SSLMODE),DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $counts=json_decode(file_get_contents($dir.'/source-counts.json'),true,512,JSON_THROW_ON_ERROR);
 foreach($counts as $table=>$expected){$actual=(int)$test->query('SELECT COUNT(*) FROM public."'.str_replace('"','""',$table).'"')->fetchColumn();if($actual!==$expected)throw new RuntimeException('Row count mismatch: '.$table);}
 $manifest['restore_verified']=true;$manifest['verified_tables']=count($counts);$manifest['verified_rows']=array_sum($counts);
 file_put_contents($dir.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 echo json_encode(['restore'=>'PASS','tables'=>count($counts),'rows'=>array_sum($counts),'parts'=>count($manifest['parts'])]).PHP_EOL;
 $test=null;
}finally{
 if($created){db()->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname=? AND pid<>pg_backend_pid()')->execute([$name]);db()->exec('DROP DATABASE "'.$name.'"');}
}
