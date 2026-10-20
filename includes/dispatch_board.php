<?php
require_once __DIR__.'/trip_funding.php';

/** Cargo board stages reuse existing trip status and explicit completion history. */
function dispatch_board_stage(array $reservation, ?array $trip=null, bool $ready=false): string {
 $status=$reservation['status'];
 if(($reservation['request_kind']??'Passenger')==='Cargo' && $status==='Completed') {
  return $trip && $trip['status']==='Completed' && ($trip['current_step']??'')==='Verified Completed' ? 'Verified Completed' : 'Delivered';
 }
 if(in_array($status,['Assigned','Confirmed','Ready for Dispatch'],true))return $ready?'Ready for Dispatch':'Assignment / Preparation';
 return $status;
}

/** No new completion workflow: verify an already completed/delivered cargo trip. */
function dispatch_board_verify_delivery(PDO $pdo,string $reservationId,string $note,array $actor): void {
 if(!in_array($actor['role_code']??'',['fleet_admin','dispatcher'],true))throw new RuntimeException('Only Admin/Dispatcher may verify delivery.');
 $note=trim($note);if($note===''||mb_strlen($note)>2000)throw new RuntimeException('Enter delivery verification notes (up to 2,000 characters).');
 if(!$pdo->inTransaction())throw new RuntimeException('Delivery verification requires a transaction.');
 $q=$pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');$q->execute([$reservationId]);$r=$q->fetch();
 if(!$r||($r['request_kind']??'')!=='Cargo'||$r['status']!=='Completed')throw new RuntimeException('Only delivered cargo requests can be verified.');
 $q=$pdo->prepare('SELECT * FROM trips WHERE reservation_id=? ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE');$q->execute([$reservationId]);$trip=$q->fetch();
 if(!$trip||$trip['status']!=='Completed'||empty($trip['actual_arrival'])||filter_var($trip['is_archived']??false,FILTER_VALIDATE_BOOLEAN))throw new RuntimeException('A delivered, non-archived trip with an arrival record is required.');
 if($trip['current_step']==='Verified Completed')return;
 $pdo->prepare("UPDATE trips SET current_step='Verified Completed' WHERE id=?")->execute([$trip['id']]);
 $pdo->prepare("INSERT INTO reservation_events(reservation_id,actor_id,status,reason) VALUES (?,?,'Verified Completed',?)")->execute([$reservationId,$actor['id'],$note]);
 $pdo->prepare("INSERT INTO trip_timeline(trip_id,title,event_time,completed,active_step,sort_order) SELECT ?,'Delivery verified completed',?,1,0,COALESCE(MAX(sort_order),0)+1 FROM trip_timeline WHERE trip_id=?")->execute([$trip['id'],date('Y-m-d H:i:s'),$trip['id']]);
}

function dispatch_board_controls(array $r): string {
 if(($r['request_kind']??'Passenger')!=='Cargo'||!has_role(['fleet_admin','dispatcher'])||!can('dispatch.manage'))return '';
 $id=e($r['id']);$stage=$r['board_stage'];
 $hidden='<input type="hidden" name="reservation_id" value="'.$id.'"><input type="hidden" name="source" value="dispatch_board"><input type="hidden" name="csrf" value="'.e($_SESSION['dispatch_board_csrf']).'"><input type="hidden" name="return" value="'.e(BASE_URL.'/modules/vehicle-reservation-dispatch/dispatch-board.php').'">';
 if($stage==='Ready for Dispatch')return '<div class="mt-2" onclick="event.stopPropagation()"><button type="button" class="tc-btn tc-btn-primary tc-btn-sm" onclick="App.openDispatchModal(\''.$id.'\',\'dispatch\')">Dispatch</button></div>';
 if($stage==='Dispatched')return '<form class="mt-2" onclick="event.stopPropagation()" method="post" action="'.BASE_URL.'/actions/trip-status.php">'.$hidden.'<input type="hidden" name="status" value="In Transit"><button class="tc-btn tc-btn-primary tc-btn-sm" type="submit">Start In Transit</button></form>';
 if(in_array($stage,['In Transit','Returning to Depot'],true))return '<details class="mt-2" onclick="event.stopPropagation()"><summary class="small text-primary-custom">Mark Delivered</summary><form class="mt-2" method="post" action="'.BASE_URL.'/actions/trip-status.php">'.$hidden.'<input type="hidden" name="status" value="Completed"><label class="tc-form-label">Final odometer (km)<input class="tc-form-control" type="number" name="final_odometer" min="'.(int)$r['board_odometer'].'" step="1" value="'.(int)$r['board_odometer'].'" required></label><label class="tc-form-label">Vehicle condition<select class="tc-form-select" name="vehicle_condition"><option>Good</option><option>Needs Inspection</option><option>Defect Reported</option></select></label><label class="tc-form-label">Delivery notes<textarea class="tc-form-control" name="completion_notes" rows="2" maxlength="2000" required></textarea></label><button type="submit" class="tc-btn tc-btn-primary tc-btn-sm mt-2">Confirm Delivered</button></form></details>';
 if($stage==='Delivered')return '<details class="mt-2" onclick="event.stopPropagation()"><summary class="small text-primary-custom">Verify Completion</summary><form class="mt-2" method="post" action="'.BASE_URL.'/actions/dispatch-board-verify.php">'.$hidden.'<label class="tc-form-label">Verification notes<textarea class="tc-form-control" name="verification_notes" rows="2" maxlength="2000" required></textarea></label><button type="submit" class="tc-btn tc-btn-primary tc-btn-sm mt-2">Verify Completed</button></form></details>';
 return '';
}
