<?php
if(PHP_SAPI!=='cli')exit;
$argv[1]='stages';ob_start();require __DIR__.'/logistics_requests_test.php';ob_end_clean();
require_once ROOT_PATH.'/includes/trip_performance.php';
$pdo->exec("UPDATE reservations SET cargo_type='DEVELOPMENT TEST fixture'");
$n=0;
function delivery_check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$empty=performance_delivery_kpis($pdo);
delivery_check($empty['completed']===0&&$empty['on_time_rate']===null&&$empty['average_delay']===null&&$empty['cancellation_rate']===null,'Empty or simulated records are not zero-percent results');
$pdo->exec("INSERT INTO drivers(id,name,license_no,status) VALUES ('D-KPI-LATE','Late delivery fixture','TEST-KPI-LATE','Active')");
foreach(['on-time','late','missing','unverified','cancelled','simulation'] as $case){
 $data=$input;$data['cargo_type']=$case==='simulation'?'DEVELOPMENT TEST simulation':'Packaged goods';
 $res=logistics_create_mock_request($pdo,$data,$actor);
 $pdo->prepare("UPDATE reservations SET status=?,return_date=?,return_time='18:00' WHERE id=?")->execute([$case==='cancelled'?'Cancelled':'Completed',$input['departure_date'],$res]);
 if($case==='cancelled')continue;
 $verified=$case!=='unverified';$trip='KPI-'.$case;
 $pdo->prepare("INSERT INTO trips(id,reservation_id,origin,destination,status,current_step,actual_arrival) VALUES (?,?,'Manila','Batangas','Completed',?,?)")->execute([$trip,$res,$verified?'Verified Completed':'Delivered',$case==='missing'?null:$input['departure_date'].' 20:00']);
 $pdo->prepare("UPDATE trips SET driver_id=?,scheduled_departure=?,actual_departure=? WHERE id=?")->execute([$case==='late'?'D-KPI-LATE':'D-CARGO',$input['departure_date'].' 12:00',$input['departure_date'].' 08:00',$trip]);
 if($verified)$pdo->prepare("INSERT INTO reservation_events(reservation_id,actor_id,status,reason) VALUES (?,1,'Verified Completed','Isolated KPI fixture')")->execute([$res]);
 if(in_array($case,['on-time','late'],true))$pdo->prepare("INSERT INTO trip_timeline(trip_id,title,event_time,completed,sort_order) VALUES (?,'Arrived',?,1,1)")->execute([$trip,$input['departure_date'].($case==='late'?' 18:30:00':' 17:45:00')]);
}
$kpi=performance_delivery_kpis($pdo);
delivery_check($kpi['completed']===3,'Count only verified completed cargo deliveries, excluding simulations');
delivery_check($kpi['measurable']===2,'Missing arrival is not an on-time or late observation');
delivery_check(abs($kpi['on_time_rate']-50.0)<0.001,'Retained arrival takes precedence over later handover time');
delivery_check($kpi['on_time_count']===1&&$kpi['measurable']===2,'Displayed counts explain percentage as one of two deliveries on time');
delivery_check($kpi['average_delay']===15.0,'Early deliveries do not cancel out late delivery delay');
delivery_check(count($kpi['delivery_variances'])===2,'Graph includes only the same two qualifying delivery timing observations');
$variances=array_column($kpi['delivery_variances'],'variance_minutes','trip_id');
delivery_check($variances['KPI-on-time']===-15.0&&$variances['KPI-late']===30.0,'Graph plots delivery arrival variance, independent of early departures');
delivery_check(abs($kpi['cancellation_rate']-20.0)<0.001,'Cargo requests only, simulation excluded from denominator');
delivery_check($kpi['by_driver']['D-CARGO']['measurable']===1&&$kpi['by_driver']['D-CARGO']['on_time_rate']==100,'Driver rate excludes unverified, missing and simulated delivery records');
delivery_check($kpi['by_driver']['D-KPI-LATE']['on_time_rate']==0&&$kpi['by_driver']['D-KPI-LATE']['late']===1,'Starting four hours early cannot mark a late delivery on time');
delivery_check(count($kpi['by_driver'])===2,'Driver observations remain grouped independently');
$pdo->exec("UPDATE trips SET driver_id='D-CARGO' WHERE id='KPI-late'");
$combined=performance_delivery_kpis($pdo);
delivery_check($combined['by_driver']['D-CARGO']['on_time_rate']==50&&$combined['by_driver']['D-CARGO']['late']===1,'Driver card and delivery KPI share the same arrival observations and rate');
$pdo->exec("UPDATE reservations SET return_time=NULL");$missing=performance_delivery_kpis($pdo);
delivery_check($missing['on_time_rate']===null&&$missing['average_delay']===null,'Missing delivery schedule displays no data');
delivery_check($missing['by_driver']===[],'Missing schedules cannot produce driver percentages');
delivery_check($missing['delivery_variances']===[],'Missing delivery timing cannot become departure variance in graph');
echo "$n delivery KPI checks passed using temporary tables only.\n";
