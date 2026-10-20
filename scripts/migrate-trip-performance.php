<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/includes/bootstrap.php';
if(db()->query("SELECT to_regclass('public.trip_performance_evaluations')")->fetchColumn()){echo "Trip performance migration already applied.\n";exit;}
$dir=ROOT_PATH.'/storage/private/trip-performance-migration-'.date('Ymd-His');
if(!mkdir($dir,0700,true))throw new RuntimeException('Cannot create backup directory.');
$env=getenv();$env['PGPASSWORD']=DB_PASS;$env['PGSSLMODE']=DB_SSLMODE;
function performance_backup_command(array $args,array $env): string {
 $proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
 if(!is_resource($proc))throw new RuntimeException('Cannot run backup utility.');
 fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 if(proc_close($proc)!==0)throw new RuntimeException('Backup utility failed: '.$error);return $out;
}
performance_backup_command(['C:/Program Files/PostgreSQL/18/bin/pg_dump.exe','--host='.DB_HOST,'--port='.DB_PORT,'--username='.DB_USER,'--dbname='.DB_NAME,'--format=custom','--file='.$dir.'/before.dump'],$env);
$list=performance_backup_command(['C:/Program Files/PostgreSQL/18/bin/pg_restore.exe','--list',$dir.'/before.dump'],$env);
if(strpos($list,'TABLE public trips')===false||!filesize($dir.'/before.dump'))throw new RuntimeException('Backup verification failed.');
db()->exec(file_get_contents(ROOT_PATH.'/database/migrations/2026_10_12_trip_performance.sql'));
echo 'Migration applied. Verified backup: '.$dir."/before.dump\n";
