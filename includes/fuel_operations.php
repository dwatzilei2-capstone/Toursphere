<?php
require_once __DIR__.'/trip_funding.php';
require_once __DIR__.'/fuel_receipts.php';
function fuel_ops_role(array $actor,array $roles):void {
 if(!in_array($actor['role_code']??'', $roles,true))throw new DomainException('This fuel action is not permitted for your role.');
}
function fuel_ops_note($value,string $label):string {
 $text=trim((string)$value);if($text===''||mb_strlen($text)>2000)throw new DomainException($label.' is required (up to 2,000 characters).');return $text;
}
function fuel_ops_number($value,string $label,float $max=999999999):float {
 if(!is_scalar($value)||!is_numeric($value)||!is_finite((float)$value)||(float)$value<=0||(float)$value>$max)throw new DomainException('Enter a valid positive '.$label.'.');return (float)$value;
}
function fuel_ops_lock(PDO $pdo,string $tripId):void {
 if(!$pdo->inTransaction())throw new LogicException('Fuel operations require a transaction.');
 $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute(['fuel-budget:'.$tripId]);
}
function fuel_ops_audit(PDO $pdo,string $action,string $id,array $actor,$old,$new):void {
 $pdo->prepare("INSERT INTO audit_logs(action,user_id,entity_type,entity_id,details,actor_name,actor_role,old_values,new_values) VALUES (?,?,'fuel_operations',?,?,?,?,?::jsonb,?::jsonb)")->execute([$action,$actor['id'],$id,json_encode(['module'=>'Fuel Management']),$actor['name']??null,$actor['role_code'],json_encode($old),json_encode($new)]);
}
function fuel_ops_trip(PDO $pdo,string $id,array $actor):array {
 $trip=funding_trip($pdo,$id);
 if(!$trip||($trip['request_kind']??'')!=='Cargo'||!$trip['vehicle_id']||!$trip['driver_id'])throw new DomainException('Select an assigned cargo trip.');
 if(($actor['role_code']??'')==='driver'){
  $q=$pdo->prepare('SELECT user_id FROM drivers WHERE id=?');$q->execute([$trip['driver_id']]);if((int)$q->fetchColumn()!==(int)$actor['id'])throw new DomainException('This trip does not belong to your driver account.');
 }
 return $trip;
}
function fuel_ops_budget(PDO $pdo,array $trip):array {
 $q=$pdo->prepare("SELECT f.*,e.inputs FROM trip_funding_requests f JOIN trip_cost_estimates e ON e.id=f.estimate_id WHERE f.trip_id=? AND f.approval_status='APPROVED' AND f.approved_at IS NOT NULL AND f.status IN ('Funding Confirmed','Superseded') ORDER BY f.id DESC LIMIT 1");$q->execute([$trip['id']]);$f=$q->fetch();
 $pretrip=in_array($trip['status'],['Scheduled','Assigned','Confirmed'],true);
 $approved=$f&&$f['vehicle_id']===$trip['vehicle_id']&&$f['driver_id']===$trip['driver_id']&&(!$pretrip||funding_matches($f,$trip))?(float)$f['approved_fuel_amount']:0.0;
 $q=$pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM fuel_transactions WHERE trip_id=? AND verification_status='Verified'");$q->execute([$trip['id']]);$spent=(float)$q->fetchColumn();
 $q=$pdo->prepare("SELECT COALESCE(SUM(maximum_amount),0) FROM fuel_authorizations WHERE trip_id=? AND status IN ('Authorized','Submitted')");$q->execute([$trip['id']]);$reserved=(float)$q->fetchColumn();
 $q=$pdo->prepare("SELECT requested_fuel_amount FROM trip_funding_requests WHERE trip_id=? AND status='Pending Finance Approval' ORDER BY id DESC LIMIT 1");$q->execute([$trip['id']]);$pending=$q->fetchColumn();
 $usable=$pending!==false?min($approved,(float)$pending):$approved;
 return ['funding'=>$approved>0?$f:null,'approved'=>$approved,'verified'=>$spent,'reserved'=>$reserved,'remaining'=>max(0,$approved-$spent),'available'=>max(0,$usable-$spent-$reserved)];
}
function fuel_ops_monitoring(PDO $pdo,?int $driverUser=null):array {
 $q=$pdo->prepare("SELECT f.id,f.trip_id,f.price_per_liter,f.system_default_price,f.odometer,f.vehicle_id FROM fuel_transactions f JOIN drivers d ON d.id=f.driver_id WHERE f.verification_status='Verified' AND (?::integer IS NULL OR d.user_id=?::integer) ORDER BY f.transaction_date DESC LIMIT 200");$q->execute([$driverUser,$driverUser]);$findings=[];
 foreach($q->fetchAll() as $row)if((float)$row['system_default_price']>0&&(float)$row['price_per_liter']>1.2*(float)$row['system_default_price'])$findings[]=$row['id'].' unit price exceeds its saved reference by more than 20%. Compare the station receipt and reference date; this is a review flag, not proof of inefficiency.';
 $q=$pdo->prepare("SELECT MIN(f.station) station,MIN(f.receipt_no) receipt_no,COUNT(*) copies FROM fuel_transactions f LEFT JOIN drivers d ON d.id=f.driver_id WHERE f.receipt_no IS NOT NULL AND f.receipt_no<>'' AND f.verification_status<>'Rejected' AND (?::integer IS NULL OR d.user_id=?::integer) GROUP BY lower(f.station),lower(f.receipt_no) HAVING COUNT(*)>1 LIMIT 20");$q->execute([$driverUser,$driverUser]);
 foreach($q->fetchAll() as $row)$findings[]='Repeated receipt '.$row['receipt_no'].' at '.$row['station'].': '.$row['copies'].' records. Review historical entries; none are automatically removed.';
 $q=$pdo->prepare("WITH readings AS(SELECT f.id,f.odometer,LAG(f.odometer) OVER(PARTITION BY f.vehicle_id ORDER BY f.transaction_date,f.id) previous FROM fuel_transactions f LEFT JOIN drivers d ON d.id=f.driver_id WHERE f.odometer IS NOT NULL AND f.verification_status<>'Rejected' AND (?::integer IS NULL OR d.user_id=?::integer)) SELECT id,odometer,previous FROM readings WHERE odometer<previous LIMIT 20");$q->execute([$driverUser,$driverUser]);foreach($q->fetchAll() as $row)$findings[]=$row['id'].' odometer '.$row['odometer'].' km is below the preceding recorded reading '.$row['previous'].' km. Check dates and receipt readings.';
 $q=$pdo->prepare("SELECT DISTINCT ON (rh.actual_fuel_transaction_id) rh.actual_fuel_liters,rh.pre_trip_features_json FROM route_history rh JOIN fuel_transactions f ON f.id=rh.actual_fuel_transaction_id JOIN drivers d ON d.id=f.driver_id WHERE rh.actual_fuel_verified=1 AND rh.actual_fuel_liters>0 AND f.verification_status='Verified' AND (?::integer IS NULL OR d.user_id=?::integer) ORDER BY rh.actual_fuel_transaction_id,rh.id DESC");$q->execute([$driverUser,$driverUser]);$distance=0.0;$consumed=0.0;
 foreach($q->fetchAll() as $row){$measurement=json_decode($row['pre_trip_features_json']??'{}',true)['fuel_measurement']??[];if(filter_var($measurement['distance_verified']??false,FILTER_VALIDATE_BOOLEAN)&&is_numeric($measurement['distance_km']??null)&&(float)$measurement['distance_km']>0){$distance+=(float)$measurement['distance_km'];$consumed+=(float)$row['actual_fuel_liters'];}}
 return ['findings'=>$findings,'efficiency'=>$consumed>0?$distance/$consumed:null,'distance'=>$distance,'consumed'=>$consumed];
}
function fuel_ops_authorize(PDO $pdo,string $tripId,array $input,array $actor):int {
 fuel_ops_role($actor,['dispatcher']);fuel_ops_lock($pdo,$tripId);$trip=fuel_ops_trip($pdo,$tripId,$actor);
 if(!in_array($trip['status'],['Assigned','Confirmed','Scheduled','Dispatched','In Transit','Returning to Depot'],true)||$trip['vehicle_id']!==$trip['assigned_vehicle_id']||$trip['driver_id']!==$trip['assigned_driver_id'])throw new DomainException('Trip assignment is not currently available for refueling.');
 $assessment=fuel_ops_note($input['assessment']??'','Assessment evidence / reasoning');$instructions=fuel_ops_note($input['instructions']??'','Instructions');
 $status=(string)($input['assessment_result']??'Authorized');if(!in_array($status,['Authorized','Not Required','Assessment Needed'],true))throw new DomainException('Invalid assessment result.');
 $q=$pdo->prepare("SELECT id FROM fuel_authorizations WHERE trip_id=? AND status IN ('Authorized','Submitted')");$q->execute([$tripId]);if($q->fetchColumn())throw new DomainException('An open refueling authorization already exists.');
 $liters=null;$amount=null;$funding=null;
 if($status==='Authorized'){$liters=round(fuel_ops_number($input['estimated_liters']??'','estimated liters',100000),3);$amount=round(fuel_ops_number($input['maximum_amount']??'','authorized amount'),2);$budget=fuel_ops_budget($pdo,$trip);if(!$budget['funding']||$amount>$budget['available']+0.001)throw new DomainException('Insufficient approved fuel allocation. Adjust the existing Trip Funding budget first.');$funding=$budget['funding']['id'];}
 $q=$pdo->prepare('INSERT INTO fuel_authorizations(trip_id,vehicle_id,driver_id,funding_request_id,estimated_liters,maximum_amount,assessment,instructions,status,authorized_by) VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id');$q->execute([$tripId,$trip['vehicle_id'],$trip['driver_id'],$funding,$liters,$amount,$assessment,$instructions,$status,$actor['id']]);$id=(int)$q->fetchColumn();
 fuel_ops_audit($pdo,'Fuel Assessment Recorded',(string)$id,$actor,null,['trip_id'=>$tripId,'status'=>$status,'assessment'=>$assessment,'instructions'=>$instructions,'maximum_amount'=>$amount,'estimated_liters'=>$liters]);return $id;
}
function fuel_ops_submission(PDO $pdo,int $authorizationId,array $input,array $actor,?array $upload):string {
 fuel_ops_role($actor,['driver','dispatcher','fleet_admin']);
 $q=$pdo->prepare('SELECT * FROM fuel_authorizations WHERE id=?');$q->execute([$authorizationId]);$auth=$q->fetch();if(!$auth)throw new DomainException('Authorization not found.');
 fuel_ops_lock($pdo,$auth['trip_id']);$q=$pdo->prepare('SELECT * FROM fuel_authorizations WHERE id=? FOR UPDATE');$q->execute([$authorizationId]);$auth=$q->fetch();$trip=fuel_ops_trip($pdo,$auth['trip_id'],$actor);
 if(!in_array($auth['status'],['Authorized','Submitted'],true)||$auth['vehicle_id']!==$trip['vehicle_id']||$auth['driver_id']!==$trip['driver_id']||in_array($trip['status'],['Cancelled','Incomplete'],true))throw new DomainException('Authorization is closed or no longer matches this trip.');
 $q=$pdo->prepare('SELECT * FROM fuel_transactions WHERE authorization_id=? FOR UPDATE');$q->execute([$authorizationId]);$old=$q->fetch();
 if($old&&$old['verification_status']!=='Returned')throw new DomainException('This authorization already has a submission.');
 if($old&&(int)($input['revision']??0)!==(int)$old['revision'])throw new DomainException('Submission changed. Refresh before correcting.');
 $liters=round(fuel_ops_number($input['liters']??'','liters',100000),3);$price=round(fuel_ops_number($input['price_per_liter']??'','unit price',100000),4);$total=round($liters*$price,2);
 $entered=round(fuel_ops_number($input['total_cost']??'','receipt total'),2);if(abs($entered-$total)>0.01)throw new DomainException('Receipt total must equal liters × unit price.');
 if($total>(float)$auth['maximum_amount']+0.001)throw new DomainException('Receipt exceeds authorized amount. Dispatcher must adjust funding and authorization before submission.');
 $odo=filter_var($input['odometer']??null,FILTER_VALIDATE_INT);if($odo===false||$odo<0)throw new DomainException('Enter a valid odometer reading.');
 $q=$pdo->prepare('SELECT fuel_type,odometer FROM vehicles WHERE id=?');$q->execute([$trip['vehicle_id']]);$vehicle=$q->fetch();
 if($trip['status']!=='Completed'&&$odo<(int)$vehicle['odometer'])throw new DomainException('Odometer cannot be below the vehicle current reading for an open trip.');
 if(strcasecmp(trim((string)($input['fuel_type']??'')),(string)$vehicle['fuel_type'])!==0)throw new DomainException('Fuel type must match the assigned vehicle.');
 $station=fuel_ops_note($input['station']??'','Fuel station');if(mb_strlen($station)>200)throw new DomainException('Fuel station must be at most 200 characters.');
 $receipt=trim((string)($input['receipt_no']??''));if($receipt===''||mb_strlen($receipt)>40)throw new DomainException('Enter a receipt number (up to 40 characters).');
 $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($input['transaction_date']??''),new DateTimeZone(company_timezone()));
 if(!$date||$date->format('Y-m-d\TH:i')!==$input['transaction_date']||$date>new DateTimeImmutable('+5 minutes',new DateTimeZone(company_timezone())))throw new DomainException('Enter a valid refueling date/time, not in the future.');
 if($date<(new DateTimeImmutable(substr($auth['created_at'],0,19),new DateTimeZone(company_timezone())))->modify('-1 minute'))throw new DomainException('Refueling must occur after authorization.');
 $q=$pdo->prepare("SELECT id FROM fuel_transactions WHERE id<>? AND verification_status<>'Rejected' AND lower(station)=lower(?) AND lower(receipt_no)=lower(?)");$q->execute([$old['id']??'',$station,$receipt]);if($q->fetchColumn())throw new DomainException('This station receipt is already recorded.');
 $q=$pdo->prepare("SELECT transaction_date,odometer FROM fuel_transactions WHERE vehicle_id=? AND id<>? AND verification_status='Verified' AND odometer IS NOT NULL");$q->execute([$trip['vehicle_id'],$old['id']??'']);foreach($q->fetchAll() as $row){if(($row['transaction_date']<=$date->format('Y-m-d H:i:s')&&(int)$row['odometer']>$odo)||($row['transaction_date']>$date->format('Y-m-d H:i:s')&&(int)$row['odometer']<$odo))throw new DomainException('Odometer conflicts with a verified refueling record.');}
 if(!$upload&&!($old&&fuel_receipt_path($old['receipt_filename'])))throw new DomainException('A fuel receipt photo or PDF is required.');
 $file=$upload??['filename'=>$old['receipt_filename'],'name'=>$old['receipt_original_name'],'mime'=>$old['receipt_mime'],'size'=>$old['receipt_size'],'sha256'=>$old['receipt_sha256']];
 if(!fuel_receipt_path($file['filename'])||!hash_equals($file['sha256'],hash_file('sha256',fuel_receipt_path($file['filename']))))throw new DomainException('Receipt file is missing or altered.');
 $id=$old['id']??next_sequential_id($pdo,'fuel_transactions','id','FL-2026-',4);
 $values=[$trip['vehicle_id'],$trip['driver_id'],$trip['id'],$date->format('Y-m-d H:i:s'),$vehicle['fuel_type'],$liters,$price,$total,$odo,$station,$receipt,$file['filename'],$file['name'],$file['mime'],$file['size'],$file['sha256'],$actor['id'],$authorizationId,$auth['funding_request_id']];
 if($old){$q=$pdo->prepare("UPDATE fuel_transactions SET vehicle_id=?,driver_id=?,trip_id=?,transaction_date=?,fuel_type=?,liters=?,price_per_liter=?,total_cost=?,odometer=?,station=?,receipt_no=?,receipt_filename=?,receipt_original_name=?,receipt_mime=?,receipt_size=?,receipt_sha256=?,submitted_by=?,authorization_id=?,funding_request_id=?,verification_status='Submitted',confirmed_by=NULL,confirmed_at=NULL,review_reason=NULL,revision=revision+1 WHERE id=?");$q->execute([...$values,$id]);}
 else{$q=$pdo->prepare("INSERT INTO fuel_transactions(vehicle_id,driver_id,trip_id,transaction_date,fuel_type,liters,price_per_liter,total_cost,odometer,station,receipt_no,receipt_filename,receipt_original_name,receipt_mime,receipt_size,receipt_sha256,submitted_by,authorization_id,funding_request_id,id,verification_status,efficiency) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Submitted',NULL)");$q->execute([...$values,$id]);}
 $reference=fuel_current_price($pdo,$vehicle['fuel_type']);
 $pdo->prepare('UPDATE fuel_transactions SET system_default_price=?,system_fuel_price_id=? WHERE id=?')->execute([$reference['price_per_liter']??null,$reference['id']??null,$id]);
 $pdo->prepare("UPDATE fuel_authorizations SET status='Submitted' WHERE id=?")->execute([$authorizationId]);
 fuel_ops_audit($pdo,$old?'Fuel Submission Corrected':'Fuel Receipt Submitted',$id,$actor,$old,['liters'=>$liters,'price'=>$price,'total'=>$total,'receipt_sha256'=>$file['sha256'],'authorization_id'=>$authorizationId]);return $id;
}
function fuel_ops_review(PDO $pdo,string $id,string $decision,string $reason,int $revision,array $actor):void {
 $dispatcher=$actor['role_code']==='dispatcher';fuel_ops_role($actor,$decision==='confirm'?['dispatcher']:($decision==='return'&&$dispatcher?['dispatcher']:['fleet_admin']));
 $q=$pdo->prepare('SELECT trip_id FROM fuel_transactions WHERE id=?');$q->execute([$id]);$tripId=$q->fetchColumn();if(!$tripId)throw new DomainException('Trip-linked transaction not found.');fuel_ops_lock($pdo,$tripId);
 $q=$pdo->prepare('SELECT * FROM fuel_transactions WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch();if($revision!==(int)$old['revision'])throw new DomainException('Transaction changed. Refresh before reviewing.');
 $expected=$dispatcher?'Submitted':'Pending Verification';if($old['verification_status']!==$expected)throw new DomainException('Transaction is not awaiting your review.');
 $status=match($decision){'confirm'=>'Pending Verification','verify'=>'Verified','return'=>'Returned','reject'=>'Rejected',default=>throw new DomainException('Invalid review action.')};
 if(in_array($decision,['return','reject'],true))$reason=fuel_ops_note($reason,'Correction / rejection reason');
 if(in_array($decision,['confirm','verify'],true)){
  $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute(['fuel-odometer:'.$old['vehicle_id']]);
  $path=fuel_receipt_path($old['receipt_filename']);if(!$path||!$old['receipt_sha256']||!hash_equals($old['receipt_sha256'],hash_file('sha256',$path)))throw new DomainException('Receipt missing or altered.');
  $q=$pdo->prepare('SELECT fuel_type FROM vehicles WHERE id=?');$q->execute([$old['vehicle_id']]);if(strcasecmp($q->fetchColumn(),$old['fuel_type'])!==0||(float)$old['liters']<=0||(float)$old['price_per_liter']<=0||abs(round((float)$old['liters']*(float)$old['price_per_liter'],2)-(float)$old['total_cost'])>0.01||$old['odometer']===null)throw new DomainException('Fuel details are inconsistent. Return for correction.');
  $q=$pdo->prepare("SELECT id FROM fuel_transactions WHERE vehicle_id=? AND id<>? AND verification_status='Verified' AND ((transaction_date<=? AND odometer>?) OR (transaction_date>? AND odometer<?)) LIMIT 1");$q->execute([$old['vehicle_id'],$id,$old['transaction_date'],$old['odometer'],$old['transaction_date'],$old['odometer']]);if($q->fetchColumn())throw new DomainException('Odometer conflicts with a verified record. Return for correction.');
  $q=$pdo->prepare('SELECT * FROM fuel_authorizations WHERE id=? FOR UPDATE');$q->execute([$old['authorization_id']]);$auth=$q->fetch();if(!$auth||$auth['status']!=='Submitted'||$auth['trip_id']!==$tripId||$auth['vehicle_id']!==$old['vehicle_id']||$auth['driver_id']!==$old['driver_id']||(float)$old['total_cost']>(float)$auth['maximum_amount'])throw new DomainException('Authorization is invalid.');
  $trip=fuel_ops_trip($pdo,$tripId,$actor);$budget=fuel_ops_budget($pdo,$trip);
  if(!$budget['funding']||$budget['verified']+$budget['reserved']>$budget['approved']+0.001)throw new DomainException('Fuel allocation no longer covers this authorization.');
 }
 $pdo->prepare("UPDATE fuel_transactions SET verification_status=?,review_reason=?,revision=revision+1,confirmed_by=CASE WHEN ?='confirm' THEN ? ELSE confirmed_by END,confirmed_at=CASE WHEN ?='confirm' THEN clock_timestamp() ELSE confirmed_at END,verified_by=CASE WHEN ?='verify' THEN ? ELSE verified_by END,verified_at=CASE WHEN ?='verify' THEN clock_timestamp() ELSE verified_at END WHERE id=?")->execute([$status,$reason,$decision,$actor['id'],$decision,$decision,$actor['id'],$decision,$id]);
 if(in_array($decision,['verify','reject'],true))$pdo->prepare('UPDATE fuel_authorizations SET status=? WHERE id=?')->execute([$decision==='verify'?'Fulfilled':'Cancelled',$old['authorization_id']]);
 if($decision==='verify')$pdo->prepare('UPDATE vehicles SET odometer=GREATEST(odometer,?) WHERE id=?')->execute([$old['odometer'],$old['vehicle_id']]);
 fuel_ops_audit($pdo,'Fuel '.$status,$id,$actor,$old,['status'=>$status,'reason'=>$reason,'reviewer'=>$actor['id']]);
}
function fuel_ops_cancel(PDO $pdo,int $id,string $reason,array $actor):void {
 fuel_ops_role($actor,['dispatcher']);$reason=fuel_ops_note($reason,'Cancellation reason');$q=$pdo->prepare('SELECT trip_id FROM fuel_authorizations WHERE id=?');$q->execute([$id]);$trip=$q->fetchColumn();if(!$trip)throw new DomainException('Authorization not found.');fuel_ops_lock($pdo,$trip);
 $q=$pdo->prepare("UPDATE fuel_authorizations SET status='Cancelled' WHERE id=? AND status='Authorized' RETURNING *");$q->execute([$id]);$row=$q->fetch();if(!$row)throw new DomainException('Only unused authorizations may be cancelled.');fuel_ops_audit($pdo,'Fuel Authorization Cancelled',(string)$id,$actor,null,['record'=>$row,'reason'=>$reason]);
}
function fuel_ops_amend(PDO $pdo,int $id,array $input,array $actor):void {
 fuel_ops_role($actor,['dispatcher']);$reason=fuel_ops_note($input['reason']??'','Adjustment reason');
 $q=$pdo->prepare('SELECT trip_id FROM fuel_authorizations WHERE id=?');$q->execute([$id]);$tripId=$q->fetchColumn();if(!$tripId)throw new DomainException('Authorization not found.');fuel_ops_lock($pdo,$tripId);
 $q=$pdo->prepare('SELECT a.*,f.verification_status FROM fuel_authorizations a LEFT JOIN fuel_transactions f ON f.authorization_id=a.id WHERE a.id=? FOR UPDATE OF a');$q->execute([$id]);$old=$q->fetch();
 if($old['status']!=='Authorized'&&!($old['status']==='Submitted'&&$old['verification_status']==='Returned'))throw new DomainException('Only an unused or returned authorization may be adjusted.');
 $trip=fuel_ops_trip($pdo,$tripId,$actor);$budget=fuel_ops_budget($pdo,$trip);
 $maximum=round(fuel_ops_number($input['maximum_amount']??'','maximum amount'),2);$liters=round(fuel_ops_number($input['estimated_liters']??'','estimated liters',100000),3);
 if(!$budget['funding']||$maximum>$budget['available']+(float)$old['maximum_amount']+0.001)throw new DomainException('Request additional allocation through Trip Funding first.');
 $pdo->prepare('UPDATE fuel_authorizations SET maximum_amount=?,estimated_liters=?,funding_request_id=? WHERE id=?')->execute([$maximum,$liters,$budget['funding']['id'],$id]);
 fuel_ops_audit($pdo,'Fuel Authorization Adjusted',(string)$id,$actor,$old,['maximum_amount'=>$maximum,'estimated_liters'=>$liters,'reason'=>$reason]);
}
function fuel_ops_shortage(PDO $pdo,string $tripId,array $input,array $actor):void {
 fuel_ops_role($actor,['driver']);fuel_ops_lock($pdo,$tripId);$trip=fuel_ops_trip($pdo,$tripId,$actor);if(!in_array($trip['status'],['Dispatched','In Transit','Returning to Depot'],true))throw new DomainException('Shortage reports are for dispatched or ongoing trips.');
 $note=fuel_ops_note($input['description']??'','Shortage report');$q=$pdo->prepare('INSERT INTO fuel_shortage_reports(trip_id,driver_id,reported_by,description) VALUES (?,?,?,?) RETURNING id');$q->execute([$tripId,$trip['driver_id'],$actor['id'],$note]);fuel_ops_audit($pdo,'Fuel Shortage Reported',(string)$q->fetchColumn(),$actor,null,['trip_id'=>$tripId,'description'=>$note]);
}
