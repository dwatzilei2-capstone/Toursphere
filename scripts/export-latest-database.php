<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
$dir=ROOT_PATH.'/storage/private/latest-database-'.date('Ymd-His');
if(!mkdir($dir,0700,true))throw new RuntimeException('Cannot create export directory');
$env=getenv();$env['PGPASSWORD']=DB_PASS;$env['PGSSLMODE']=DB_SSLMODE;
$args=['C:/Program Files/PostgreSQL/18/bin/pg_dump.exe','--host='.DB_HOST,'--port='.DB_PORT,'--username='.DB_USER,'--dbname='.DB_NAME,'--schema=public','--no-owner','--no-privileges','--inserts','--rows-per-insert=1','--file='.$dir.'/latest.sql'];
$proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
if(!is_resource($proc))throw new RuntimeException('Cannot start export');
fclose($pipes[0]);stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
if(proc_close($proc)!==0)throw new RuntimeException('Export failed: '.$error);
$tables=db()->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
$counts=[];foreach($tables as $table)$counts[$table]=(int)db()->query('SELECT COUNT(*) FROM public."'.str_replace('"','""',$table).'"')->fetchColumn();
file_put_contents($dir.'/source-counts.json',json_encode($counts,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo $dir.PHP_EOL;
