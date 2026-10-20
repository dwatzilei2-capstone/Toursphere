<?php
if(PHP_SAPI!=='cli')exit;
$mode=$argv[1]??'stages';
// Reuse existing isolated request/capacity/approval fixtures; never write operational data.
$argv[1]='stages';ob_start();require __DIR__.'/logistics_requests_test.php';ob_end_clean();
require_once ROOT_PATH.'/includes/dispatch_board.php';
$r=$pdo->query('SELECT * FROM reservations ORDER BY id LIMIT 1')->fetch();
$n=0;function board_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$cargo=['request_kind'=>'Cargo','status'=>'Assigned'];
board_check(dispatch_board_stage($cargo,null,false)==='Assignment / Preparation','Assignment alone is not ready');
board_check(dispatch_board_stage($cargo,null,true)==='Ready for Dispatch','Ready requires current readiness checks');
foreach(['Dispatched','In Transit','Cancelled','Rejected'] as $stage)board_check(dispatch_board_stage(array_replace($cargo,['status'=>$stage]))===$stage,'Preserve '.$stage);
board_check(dispatch_board_stage(array_replace($cargo,['status'=>'Completed']),['status'=>'Completed','current_step'=>'Completed & Returned'])==='Delivered','Completion alone is delivery, never automatic verification');
board_check(dispatch_board_stage(array_replace($cargo,['status'=>'Completed']),['status'=>'Completed','current_step'=>'Verified Completed'])==='Verified Completed','Explicit verification advances stage');
board_check(dispatch_board_stage(['request_kind'=>'Passenger','status'=>'Completed'])==='Completed','Legacy trip behavior preserved');
$pdo->exec("UPDATE vehicles SET status='Assigned',assigned_driver_id='D-CARGO',payload_capacity_kg=5000,odometer=1000 WHERE id='V-CARGO'");
$pdo->prepare("UPDATE reservations SET status=?,assigned_vehicle_id='V-CARGO',assigned_driver_id='D-CARGO' WHERE id=?")->execute([$mode==='deliver'?'In Transit':($mode==='invalid-transit'?'Approved':'Dispatched'),$id]);
$tripStatus=$mode==='deliver'?'In Transit':($mode==='invalid-transit'?'Assigned':'Dispatched');
$pdo->prepare("INSERT INTO trips(id,reservation_id,origin,destination,vehicle_id,driver_id,scheduled_departure,status,passengers,actual_departure) VALUES ('T-BOARD',?,'Manila Warehouse','Batangas Depot','V-CARGO','D-CARGO',?,?,0,?)")->execute([$id,$input['departure_date'].' 09:00',$tripStatus,$mode==='deliver'?date('Y-m-d H:i:s'):null]);
if(in_array($mode,['transit','invalid-transit','deliver'],true)){
 $current_user=['id'=>1,'role_code'=>'fleet_admin'];$current_permissions=['dispatch.manage'];$_SESSION['dispatch_board_csrf']='board-test';$_SERVER['REQUEST_METHOD']='POST';
 $_POST=['source'=>'dispatch_board','csrf'=>'board-test','reservation_id'=>$id,'status'=>$mode==='deliver'?'Completed':'In Transit','final_odometer'=>'1100','vehicle_condition'=>'Good','completion_notes'=>'Cargo received at delivery destination.','return'=>'/fleet/modules/vehicle-reservation-dispatch/dispatch-board.php'];
 register_shutdown_function(function()use($pdo,$id,$mode){$q=$pdo->prepare('SELECT status FROM reservations WHERE id=?');$q->execute([$id]);$reservationStatus=$q->fetchColumn();$trip=$pdo->query("SELECT * FROM trips WHERE id='T-BOARD'")->fetch();
  $expected=$mode==='deliver'?'Completed':($mode==='invalid-transit'?'Assigned':'In Transit');
  if($trip['status']!==$expected||($mode==='invalid-transit'&&$reservationStatus!=='Approved')){fwrite(STDERR,'Board transition failed: '.($GLOBALS['board_error']??'Unknown')."\n");exit(1);}
  if($mode==='transit'&&empty($trip['actual_departure'])){fwrite(STDERR,"Departure not recorded\n");exit(1);}
  if($mode==='deliver'&&(empty($trip['actual_arrival'])||$trip['current_step']==='Verified Completed')){fwrite(STDERR,"Delivery prematurely verified\n");exit(1);}
  echo "Board $mode action passed using temporary tables.\n";
 });
 $action=file_get_contents(ROOT_PATH.'/actions/trip-status.php');$action=str_replace('} catch (Exception $ex) {','} catch (Exception $ex) { $GLOBALS["board_error"]=$ex->getMessage();',$action);eval(substr($action,5));
}
// Verification refuses shortcuts and unauthorized reviewers, then records exactly one event.
$pdo->beginTransaction();try{dispatch_board_verify_delivery($pdo,$id,'Received',['id'=>1,'role_code'=>'dispatcher']);throw new LogicException('Undelivered trip verified');}catch(RuntimeException $e){$pdo->rollBack();board_check(true,'Undelivered trip rejected');}
$pdo->prepare("UPDATE reservations SET status='Completed' WHERE id=?")->execute([$id]);$pdo->exec("UPDATE trips SET status='Completed',actual_arrival=CURRENT_TIMESTAMP WHERE id='T-BOARD'");
$pdo->beginTransaction();try{dispatch_board_verify_delivery($pdo,$id,'Received',['id'=>1,'role_code'=>'customer']);throw new LogicException('Requester verified delivery');}catch(RuntimeException $e){$pdo->rollBack();board_check(true,'Requester cannot verify');}
$pdo->beginTransaction();dispatch_board_verify_delivery($pdo,$id,'Receipt and delivery reviewed',['id'=>1,'role_code'=>'dispatcher']);dispatch_board_verify_delivery($pdo,$id,'Repeated submit',['id'=>1,'role_code'=>'dispatcher']);$pdo->commit();
board_check($pdo->query("SELECT current_step FROM trips WHERE id='T-BOARD'")->fetchColumn()==='Verified Completed','Explicit verified completion saved');
board_check((int)$pdo->query("SELECT count(*) FROM reservation_events WHERE status='Verified Completed'")->fetchColumn()===1,'Repeat verification is idempotent');
board_check((int)$pdo->query("SELECT count(*) FROM trip_timeline WHERE title='Delivery verified completed'")->fetchColumn()===1,'Verification retained in trip history');
$current_user=['id'=>1,'name'=>'Board Test Admin','role_code'=>'fleet_admin','role_name'=>'Fleet Administrator','emp_id'=>'TEST','avatar'=>null];$current_permissions=['dispatch.view','dispatch.manage'];$_SESSION['last_valid_activity']=time();
$pdo->prepare("UPDATE reservations SET status='Dispatched' WHERE id=?")->execute([$id]);$pdo->exec("UPDATE trips SET status='Dispatched',current_step='Dispatched' WHERE id='T-BOARD'");
ob_start();require ROOT_PATH.'/modules/vehicle-reservation-dispatch/dispatch-board.php';$html=ob_get_clean();
foreach(['board-two-lines','board-one-line','kg · ','m³','Start In Transit','kanban-ready','kanban-delivered'] as $text)board_check(strpos($html,$text)!==false,'Board renders '.$text);
board_check(board_number('100.000')==='100' && board_number('1.000')==='1' && board_number('0.125')==='0.125','Compact numbers preserve meaningful precision');
board_check(board_summary('DEVELOPMENT TEST - packaged goods')==='packaged goods','Test prefix hidden only in summary');
board_check(board_is_test(['notes'=>'DEVELOPMENT TEST ONLY - authorized test']),'Test badge recognizes notes');
board_check(board_schedule(null)==='Schedule unavailable','Missing schedule never displays current time');
$display=array_replace($r,['id'=>'RES-TEST','client_name'=>'Requester','request_kind'=>'Cargo','cargo_type'=>'DEVELOPMENT TEST - packaged goods','cargo_quantity'=>5,'cargo_weight_kg'=>'100.000','cargo_volume_m3'=>'1.000','origin'=>'DEVELOPMENT TEST - Manila, full pickup address','destination'=>'Makati, full destination address','special_handling'=>'PRIVATE HANDLING INSTRUCTIONS','assigned_vehicle_id'=>'V-CARGO','plate_number'=>'DEV-1','driver_name'=>'Driver','status'=>'Dispatched','board_stage'=>'Dispatched']);
$card=kanban_card($display);
board_check(str_contains($card,'>Test</span>') && str_contains($card,'100 kg · 1 m³'),'Compact test card and combined measurements');
board_check(!str_contains($card,'DEVELOPMENT TEST') && !str_contains($card,'PRIVATE HANDLING') && !str_contains($card,'full pickup address'),'Full text excluded from card only');
board_check(str_contains($card,'App.openDispatchModal') && str_contains($card,'event.stopPropagation()'),'Details click and isolated action preserved');
echo "$n board stage, presentation and verification checks passed; only temporary tables used.\n";
