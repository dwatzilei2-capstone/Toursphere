<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/includes/bootstrap.php';
if(db()->query("SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='reservations' AND column_name='request_kind'")->fetchColumn()){echo "Logistics migration already applied.\n";exit;}
$dir=ROOT_PATH.'/storage/private/logistics-migration-'.date('Ymd-His');mkdir($dir,0700,true);
$env=getenv();$env['PGPASSWORD']=DB_PASS;$env['PGSSLMODE']=DB_SSLMODE;
$args=['C:/Program Files/PostgreSQL/18/bin/pg_dump.exe','--host='.DB_HOST,'--port='.DB_PORT,'--username='.DB_USER,'--dbname='.DB_NAME,'--format=custom','--file='.$dir.'/before.dump'];
$proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);fclose($pipes[0]);stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($err);
$check=proc_open(['C:/Program Files/PostgreSQL/18/bin/pg_restore.exe','--list',$dir.'/before.dump'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$list=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($check)!==0||strpos($list,'reservations')===false)throw new RuntimeException('Backup verification failed: '.$error);
db()->exec(file_get_contents(ROOT_PATH.'/database/migrations/2026_10_12_logistics_requests.sql'));echo 'Migration applied; verified backup: '.$dir."\n";
