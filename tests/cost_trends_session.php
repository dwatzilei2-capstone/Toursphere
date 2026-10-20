<?php
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../includes/bootstrap.php';
$file=ROOT_PATH.'/tmp/cost-trends-session.json';
if(in_array('--counts',$argv,true)){$counts=[];foreach(['vehicles','trips','reservations','fuel_transactions','maintenance_orders','trip_cost_estimates','trip_funding_requests'] as $table)$counts[$table]=(int)db()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();echo json_encode($counts);exit;}
if(in_array('--clean',$argv,true)){if(is_file($file)){session_id(json_decode(file_get_contents($file),true)['fleet_admin']);session_start();$_SESSION=[];session_destroy();unlink($file);}exit;}
$id=db()->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='fleet_admin' AND u.status='Active' LIMIT 1")->fetchColumn();if(!$id)throw new RuntimeException('No existing admin account');
$sid=bin2hex(random_bytes(24));session_id($sid);if(!session_start())throw new RuntimeException('Session unavailable');$_SESSION=['user_id'=>(int)$id,'last_valid_activity'=>time()];session_write_close();file_put_contents($file,json_encode(['fleet_admin'=>$sid]));echo "Temporary Cost Trends validation session created.\n";
