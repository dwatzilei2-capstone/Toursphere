<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// The existing funding suite creates connection-local tables and verified fixture resources.
require __DIR__.'/trip_funding_test.php';
$p=$pdo;
$p->exec('CREATE TEMP TABLE route_planner_states (LIKE public.route_planner_states INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING IDENTITY)');
$p->exec("UPDATE reservations SET status='Assigned',route_distance_km=NULL WHERE id='FUND-R'; UPDATE trips SET status='Assigned',distance_km=NULL,navigation_active=0,waypoints='Stop A;Stop B' WHERE id='FUND-T'; UPDATE vehicles SET avg_fuel_km='9.0 km/L (est.)' WHERE id='FUND-V'");
$count=0;
function automatic_check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);$count++;echo "PASS: $label\n";}
$t=funding_trip($p,'FUND-T');
$failed=funding_prepare_automatic_distance($p,$t,fn()=>throw new RuntimeException('Provider unavailable'));
automatic_check((float)$failed['distance_km']===0.0 && (float)funding_trip($p,'FUND-T')['distance_km']===0.0,'Provider failure never fabricates/saves a distance');
$calls=[];$t=funding_prepare_automatic_distance($p,$t,function($a,$b)use(&$calls){$calls[]=[$a,$b];return 12.5;});
automatic_check($calls===[['Origin','Stop A'],['Stop A','Stop B'],['Stop B','Destination']] && (float)$t['route_distance_km']===37.5,'Automatic estimate includes each intermediate stop');
automatic_check((int)$p->query('SELECT count(*) FROM route_planner_states')->fetchColumn()===0,'Calculation works without a generated or applied Driver route');
$e=funding_estimate($p,$t);
automatic_check(!$e['issues'] && $e['expected_km_per_liter']===9.0 && str_contains($e['efficiency_source'],'not measured'),'Existing configured efficiency is labelled as a planning estimate');
automatic_check($e['estimated_total_cost']===round(37.5/9*100,2) && $e['estimated_total_cost']===$e['estimated_fuel_cost'],'Distance/efficiency/current price calculation counts fuel once');
$called=false;funding_prepare_automatic_distance($p,$t,function()use(&$called){$called=true;return 999;});automatic_check(!$called,'Reopening funding reuses saved planning distance');
$p->exec("UPDATE reservations SET route_distance_km=NULL WHERE id='FUND-R'; UPDATE trips SET distance_km=NULL,waypoints='' WHERE id='FUND-T'");
$current_user['role_code']='fleet_manager';$called=false;funding_prepare_automatic_distance($p,funding_trip($p,'FUND-T'),function()use(&$called){$called=true;return 999;});automatic_check(!$called,'Monitoring role cannot write planning distance');$current_user['role_code']='fleet_admin';
$race=funding_prepare_automatic_distance($p,funding_trip($p,'FUND-T'),function()use($p){$p->exec("UPDATE trips SET destination='Changed delivery' WHERE id='FUND-T'");return 10;});
automatic_check((float)$race['distance_km']===0.0 && $race['destination']==='Changed delivery','Concurrent destination change rejects stale provider result');
$p->exec("UPDATE trips SET destination='Destination',navigation_active=1 WHERE id='FUND-T'");
$called=false;funding_prepare_automatic_distance($p,funding_trip($p,'FUND-T'),function()use(&$called){$called=true;return 10;});automatic_check(!$called,'Active navigation is not recalculated');
$p->exec("UPDATE trips SET navigation_active=0 WHERE id='FUND-T'; UPDATE trip_funding_requests SET status='Funding Confirmed' WHERE trip_id='FUND-T'");
$snapshot=funding_request($p,'FUND-T');$called=false;funding_prepare_automatic_distance($p,funding_trip($p,'FUND-T'),function()use(&$called){$called=true;return 10;});
automatic_check(!$called && funding_request($p,'FUND-T')==$snapshot,'Approved funding snapshot remains unchanged');
$p->exec("UPDATE trip_funding_requests SET status='Superseded' WHERE trip_id='FUND-T'");
$p->prepare("INSERT INTO route_planner_states(scope_key,trip_id,user_id,phase,lifecycle,revision,state_data) VALUES('trip:FUND-T:outbound','FUND-T',1,'outbound','APPLIED',1,?::jsonb)")->execute([json_encode(['applied'=>['routeId'=>'existing-locked-route','geometry'=>[['lat'=>14.6,'lng'=>121.0]]]])]);
$before=$p->query('SELECT state_data FROM route_planner_states')->fetchColumn();
$t=funding_prepare_automatic_distance($p,funding_trip($p,'FUND-T'),fn()=>37.5);
automatic_check($p->query('SELECT state_data FROM route_planner_states')->fetchColumn()===$before,'Existing applied geometry/lock are untouched');
$p->exec('DELETE FROM route_planner_states');
$f=funding_create($p,'FUND-T','company_card','500');
automatic_check($f['status']==='Pending Finance Approval' && (int)$p->query('SELECT count(*) FROM route_planner_states')->fetchColumn()===0,'Normal funding request works without Apply Route and still awaits approval');
automatic_check((float)$f['requested_fuel_amount']===(float)$f['estimated_fuel_cost'],'Fuel allocation defaults to calculated fuel cost when no separate input is submitted');
// A separate newly assigned future trip has no special trip ID handling.
$p->exec("INSERT INTO reservations(id,client_name,passenger_count,origin,destination,departure_date,departure_time,status,assigned_vehicle_id,assigned_driver_id) VALUES('FUTURE-R','Isolated future trip',0,'Future pickup','Future delivery','2035-01-10','09:00','Assigned','FUND-V','FUND-D'); INSERT INTO trips(id,reservation_id,origin,destination,vehicle_id,driver_id,status,scheduled_departure,passengers) VALUES('FUTURE-T','FUTURE-R','Future pickup','Future delivery','FUND-V','FUND-D','Assigned','2035-01-10 09:00',0)");
$future=funding_prepare_automatic_distance($p,funding_trip($p,'FUTURE-T'),fn()=>23.45);$e=funding_estimate($p,$future);
automatic_check((float)$future['route_distance_km']===23.45 && $e['estimated_total_cost']===round(23.45/9*100,2),'A separate future trip automatically calculates its own distance/cost');
echo "$count automatic funding checks passed; all data is connection-local.\n";
