<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=db();
if($pdo->query("SELECT to_regclass('public.fuel_authorizations')")->fetchColumn()){echo "Fuel operations migration already applied.\n";exit;}
$dir=ROOT_PATH.'/storage/private/fuel-operations-backup-'.date('Ymd-His');
if(!mkdir($dir,0700,true))throw new RuntimeException('Cannot create backup directory.');
$env=getenv();$env['PGPASSWORD']=DB_PASS;$env['PGSSLMODE']=DB_SSLMODE;
function fuel_migration_command(array $args,array $env):string {
 $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);if(!is_resource($p))throw new RuntimeException('Cannot start backup utility.');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($err);return $out;
}
fuel_migration_command(['C:/Program Files/PostgreSQL/18/bin/pg_dump.exe','--host='.DB_HOST,'--port='.DB_PORT,'--username='.DB_USER,'--dbname='.DB_NAME,'--format=custom','--file='.$dir.'/before.dump'],$env);
$list=fuel_migration_command(['C:/Program Files/PostgreSQL/18/bin/pg_restore.exe','--list',$dir.'/before.dump'],$env);
if(!filesize($dir.'/before.dump')||!str_contains($list,'TABLE public fuel_transactions'))throw new RuntimeException('Backup verification failed.');
try{$pdo->exec(file_get_contents(ROOT_PATH.'/database/migrations/2026_10_13_fuel_operations.sql'));}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
echo "Fuel operations migration applied. Verified backup: $dir/before.dump\n";
