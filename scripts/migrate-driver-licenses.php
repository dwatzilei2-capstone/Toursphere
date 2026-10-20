<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/includes/bootstrap.php';
if(db()->query("SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='drivers' AND column_name='authorized_dl_codes'")->fetchColumn()){echo "Driver-license migration already applied.\n";exit;}
$dir=ROOT_PATH.'/storage/private/driver-license-migration-'.date('Ymd-His');mkdir($dir,0700,true);
$env=getenv();$env['PGPASSWORD']=DB_PASS;$env['PGSSLMODE']=DB_SSLMODE;
$args=['C:/Program Files/PostgreSQL/18/bin/pg_dump.exe','--host='.DB_HOST,'--port='.DB_PORT,'--username='.DB_USER,'--dbname='.DB_NAME,'--format=custom','--file='.$dir.'/before.dump'];
$proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);fclose($pipes[0]);stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($err);
db()->exec(file_get_contents(ROOT_PATH.'/database/migrations/2026_10_12_driver_license_qualifications.sql'));echo 'Migration applied; backup: '.$dir."\n";
