<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=db();
foreach(['route_history','trips','reservations','route_planner_states'] as $table){
    $pdo->exec('CREATE TEMP TABLE '.$table.' (LIKE public.'.$table.' INCLUDING DEFAULTS INCLUDING INDEXES)');
}
$pdo->exec("INSERT INTO route_history SELECT * FROM public.route_history WHERE log_id LIKE 'LOG-DEMO-%'");
$pdo->exec("INSERT INTO trips SELECT * FROM public.trips WHERE id LIKE 'TRP-DEMO-%'");
$pdo->exec("INSERT INTO reservations SELECT * FROM public.reservations WHERE id LIKE 'RES-DEMO-%'");
$pdo->exec("UPDATE route_history SET route_data_json=json_build_object('demo',true,'distanceKm',1,'revenue',0)::text");
foreach(['route_history','trips','reservations','route_planner_states'] as $table)$pdo->exec('ANALYZE '.$table);
$sql=file_get_contents(ROOT_PATH.'/database/imports/toursphere-demo-selected-routes.sql');
$pdo->exec($sql);
echo "First import passed.\n";
$check=$pdo->query("SELECT count(*) FROM route_history WHERE route_data_json::jsonb->>'geometrySource'='OSRM / OpenStreetMap'")->fetchColumn();
if((int)$check!==30)throw new RuntimeException('Expected 30 imported road geometries.');
$pdo->exec($sql); // Safe to import again.
echo "Repeat import passed.\n";
$pdo->exec("UPDATE trips SET origin='Different terminal' WHERE id='TRP-DEMO-001'");
try{$pdo->exec($sql);throw new RuntimeException('Import accepted a mismatched trip.');}
catch(PDOException $e){if($pdo->inTransaction())$pdo->rollBack();if(!str_contains($e->getMessage(),'expected 30 matching'))throw $e;}
$pdo->exec("UPDATE trips SET origin=(SELECT origin FROM route_history WHERE trip_id=trips.id) WHERE id='TRP-DEMO-001'");
$pdo->exec("INSERT INTO route_planner_states(scope_key,trip_id,user_id,phase,lifecycle,state_data)
 VALUES('trip:TRP-DEMO-001:outbound','TRP-DEMO-001',1,'outbound','APPLIED','{\"applied\":{\"routeId\":\"real-driver-route\"}}'::jsonb)");
try{$pdo->exec($sql);throw new RuntimeException('Import replaced a Driver-applied route.');}
catch(PDOException $e){if($pdo->inTransaction())$pdo->rollBack();if(!str_contains($e->getMessage(),'Driver-applied'))throw $e;}
echo "Demo import, repeat import, terminal mismatch and Driver-route protection passed using temporary tables. No operational data changed.\n";
