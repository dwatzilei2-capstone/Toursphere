<?php
/** Cargo requests share existing reservation ownership, review, trip and funding relationships. */
function logistics_request_validate(array $input): array {
 $out=[];
 foreach(['cargo_type'=>120,'origin'=>200,'destination'=>200,'contact_person'=>120,'contact_phone'=>40] as $key=>$max){
  $value=trim((string)($input[$key]??''));if($value===''||mb_strlen($value)>$max)throw new RuntimeException('Enter a valid '.str_replace('_',' ',$key).' (up to '.$max.' characters).');$out[$key]=$value;
 }
 if(strcasecmp($out['origin'],$out['destination'])===0)throw new RuntimeException('Pickup and delivery locations must be different.');
 foreach(['cargo_weight_kg','cargo_volume_m3'] as $key){$value=trim((string)($input[$key]??''));if(!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D',$value)||(float)$value<=0)throw new RuntimeException('Enter a positive '.str_replace('_',' ',$key).' with up to three decimal places.');$out[$key]=$value;}
 $quantity=filter_var($input['cargo_quantity']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);if(!$quantity)throw new RuntimeException('Quantity must be a positive whole number.');$out['cargo_quantity']=$quantity;
 $out['request_purpose']=(string)($input['request_purpose']??'');if(!in_array($out['request_purpose'],['Cargo Transport','Vehicle Request'],true))throw new RuntimeException('Choose a request type.');
 $out['departure_date']=(string)($input['departure_date']??'');$out['departure_time']=(string)($input['departure_time']??'');
 $stamp=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$out['departure_date'].' '.$out['departure_time'],new DateTimeZone(company_timezone()));
 if(!$stamp||$stamp->format('Y-m-d H:i')!==$out['departure_date'].' '.$out['departure_time']||$stamp<=new DateTimeImmutable('now',new DateTimeZone(company_timezone())))throw new RuntimeException('Choose a valid future pickup date and time.');
 foreach(['special_handling','notes'] as $key){$out[$key]=trim((string)($input[$key]??''));if(mb_strlen($out[$key])>2000)throw new RuntimeException('Instructions must be no longer than 2,000 characters.');}
 return $out;
}

/** Used for both the chooser and final server-side assignment validation. */
function logistics_vehicle_issue(array $request,array $vehicle): string {
 if(($request['request_kind']??'Passenger')!=='Cargo')return '';
 if(($vehicle['service_category']??'')!=='Cargo')return 'A cargo vehicle is required.';
 if((float)($vehicle['payload_capacity_kg']??0)<(float)$request['cargo_weight_kg'])return 'Insufficient payload capacity for '. $request['cargo_weight_kg'].' kg.';
 if((float)($vehicle['cargo_volume_m3']??0)<(float)$request['cargo_volume_m3'])return 'Insufficient or unrecorded cargo volume for '.$request['cargo_volume_m3'].' m³.';
 return '';
}

/** Local mock only: source and owner come from trusted server context, never posted values. */
function logistics_create_mock_request(PDO $pdo,array $input,array $actor): string {
 if(($actor['role_code']??'')!=='customer')throw new RuntimeException('Only Logistics 1 Requesters may submit requests.');
 $data=logistics_request_validate($input);
 $pdo->exec("SELECT pg_advisory_xact_lock(hashtext('logistics-reservation-id'))");
 $id=next_sequential_id($pdo,'reservations','id','RES-'.date('Y').'-');
 $pdo->prepare("INSERT INTO reservations(id,client_name,customer_id,contact_person,contact_phone,passenger_count,origin,destination,departure_date,departure_time,status,trip_type,request_kind,request_purpose,cargo_type,cargo_weight_kg,cargo_volume_m3,cargo_quantity,special_handling,notes,source_system,integration_mode) VALUES (?,?,?,?,?,0,?,?,?,?,'Pending Approval','Cargo Delivery','Cargo',?,?,?,?,?,?,?,'Logistics 1','Mock')")->execute([$id,$actor['name'],$actor['id'],$data['contact_person'],$data['contact_phone'],$data['origin'],$data['destination'],$data['departure_date'],$data['departure_time'],$data['request_purpose'],$data['cargo_type'],$data['cargo_weight_kg'],$data['cargo_volume_m3'],$data['cargo_quantity'],$data['special_handling'],$data['notes']]);
 $pdo->prepare("INSERT INTO reservation_events(reservation_id,actor_id,status,reason) VALUES (?,?,'Pending Approval','Logistics 1 mock request submitted locally in TourSphere; no live integration.')")->execute([$id,$actor['id']]);
 return $id;
}

function logistics_review_request(PDO $pdo,string $id,string $decision,string $reason,array $actor): string {
 if(!in_array($actor['role_code']??'',['fleet_admin','dispatcher'],true))throw new RuntimeException('Only Admin/Dispatcher may review requests.');
 if(!in_array($decision,['approve','reject'],true))throw new RuntimeException('Choose approve or reject.');
 if($decision==='reject'&&trim($reason)==='')throw new RuntimeException('A rejection reason is required.');
 $q=$pdo->prepare('SELECT * FROM reservations WHERE id=? FOR UPDATE');$q->execute([$id]);$r=$q->fetch();
 if(!$r||$r['status']!=='Pending Approval'||!$r['customer_id'])throw new RuntimeException('This request is no longer pending approval.');
 $status=$decision==='approve'?'Approved':'Rejected';
 $pdo->prepare("UPDATE reservations SET status=?,rejection_reason=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP,fare_status=CASE WHEN ?='approve' AND total_booking_fare IS NOT NULL THEN 'Confirmed' ELSE fare_status END WHERE id=?")->execute([$status,$decision==='reject'?mb_substr(trim($reason),0,2000):null,$actor['id'],$decision,$id]);
 $pdo->prepare('INSERT INTO reservation_events(reservation_id,actor_id,status,reason) VALUES (?,?,?,?)')->execute([$id,$actor['id'],$status,$decision==='reject'?mb_substr(trim($reason),0,2000):null]);
 if($r['departure_schedule_instance_id'])schedule_refresh_required_vehicle($pdo,(int)$r['departure_schedule_instance_id']);
 if($r['return_schedule_instance_id']&&$r['return_schedule_instance_id']!==$r['departure_schedule_instance_id'])schedule_refresh_required_vehicle($pdo,(int)$r['return_schedule_instance_id']);
 return $status;
}
