<?php
/** Read-only grouping for presentation; each refueling record retains its identity. */
function fuel_unified_data(PDO $pdo,array $actor,int $page=1,array $filters=[]):array {
 $driver=($actor['role_code']??'')==='driver';$scope=$driver?' AND d.user_id=?':'';$params=$driver?[$actor['id'],$actor['id'],$actor['id']]:[];
 $tripScope=$driver?' AND (d.user_id=? OR EXISTS(SELECT 1 FROM fuel_transactions own JOIN drivers od ON od.id=own.driver_id WHERE own.trip_id=t.id AND od.user_id=?))':'';
 $keys="SELECT t.id trip_id,NULL::varchar record_id,t.created_at sort_date FROM trips t LEFT JOIN reservations r ON r.id=t.reservation_id LEFT JOIN drivers d ON d.id=t.driver_id WHERE (r.request_kind='Cargo' OR EXISTS(SELECT 1 FROM fuel_transactions f WHERE f.trip_id=t.id))".$tripScope.
 " UNION ALL SELECT NULL::varchar,f.id,f.transaction_date FROM fuel_transactions f LEFT JOIN drivers d ON d.id=f.driver_id WHERE f.trip_id IS NULL".$scope;
 $base="SELECT k.*,COALESCE(t.vehicle_id,f.vehicle_id) vehicle_id FROM ($keys) k LEFT JOIN trips t ON t.id=k.trip_id LEFT JOIN fuel_transactions f ON f.id=k.record_id";
 $q=$pdo->prepare("SELECT DISTINCT v.id,v.plate_number FROM ($base) b JOIN vehicles v ON v.id=b.vehicle_id ORDER BY v.plate_number");$q->execute($params);$vehicles=$q->fetchAll();
 $conditions=[];$extra=[];
 $own=$driver?' AND EXISTS(SELECT 1 FROM drivers od WHERE od.id=x.driver_id AND od.user_id='.(int)$actor['id'].')':'';
 $match="(x.trip_id=b.trip_id OR x.id=b.record_id)".$own;
 if(($filters['q']??'')!==''){
  $conditions[]="(COALESCE(b.trip_id,b.record_id) ILIKE ? OR EXISTS(SELECT 1 FROM vehicles v WHERE v.id=b.vehicle_id AND (v.plate_number ILIKE ? OR (v.brand||' '||v.model) ILIKE ?)) OR EXISTS(SELECT 1 FROM trips t JOIN drivers d ON d.id=t.driver_id WHERE t.id=b.trip_id AND d.name ILIKE ?) OR EXISTS(SELECT 1 FROM fuel_transactions x LEFT JOIN drivers d ON d.id=x.driver_id WHERE $match AND (x.id ILIKE ? OR d.name ILIKE ?)))";
  $term='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$filters['q']).'%';$extra=[...$extra,...array_fill(0,6,$term)];
 }
 if(($filters['vehicle']??'')!==''){$conditions[]='b.vehicle_id=?';$extra[]=$filters['vehicle'];}
 if(($filters['status']??'')!==''){
  [$kind,$status]=array_pad(explode(':',$filters['status'],2),2,'');
  if($kind==='transaction'){$conditions[]="EXISTS(SELECT 1 FROM fuel_transactions x WHERE $match AND x.verification_status=?)";$extra[]=$status;}
  elseif($kind==='authorization'){$conditions[]="EXISTS(SELECT 1 FROM fuel_authorizations x WHERE x.trip_id=b.trip_id$own AND x.status=?)";$extra[]=$status;}
 }
 $dateConditions=[];
 foreach(['from'=>'>=','to'=>'<='] as $key=>$operator)if(($filters[$key]??'')!==''){$dateConditions[]="x.transaction_date::date $operator ?::date";$extra[]=$filters[$key];}
 if($dateConditions)$conditions[]="EXISTS(SELECT 1 FROM fuel_transactions x WHERE $match AND ".implode(' AND ',$dateConditions).')';
 $filtered="SELECT b.* FROM ($base) b".($conditions?' WHERE '.implode(' AND ',$conditions):'');$params=[...$params,...$extra];
 $q=$pdo->prepare("SELECT count(*) FROM ($filtered) k");$q->execute($params);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/30));$page=min($pages,max(1,$page));
 $sort=($filters['sort']??'newest')==='oldest'?'ASC':'DESC';
 $q=$pdo->prepare("SELECT * FROM ($filtered) k ORDER BY sort_date $sort,trip_id,record_id LIMIT 30 OFFSET ".(($page-1)*30));$q->execute($params);$keys=$q->fetchAll();
 $tripIds=array_values(array_filter(array_column($keys,'trip_id')));$recordIds=array_values(array_filter(array_column($keys,'record_id')));$transactions=[];$auths=[];$trips=[];$details=[];
 if($tripIds||$recordIds){
  $conditions=[];$values=[];foreach(['trip_id'=>$tripIds,'id'=>$recordIds] as $column=>$ids)if($ids){$conditions[]='f.'.$column.' IN ('.implode(',',array_fill(0,count($ids),'?')).')';$values=[...$values,...$ids];}
  $q=$pdo->prepare('SELECT f.*,v.plate_number,d.name driver_name,s.name submitter_name,c.name confirmer_name,u.name verifier_name FROM fuel_transactions f LEFT JOIN vehicles v ON v.id=f.vehicle_id LEFT JOIN drivers d ON d.id=f.driver_id LEFT JOIN users s ON s.id=f.submitted_by LEFT JOIN users c ON c.id=f.confirmed_by LEFT JOIN users u ON u.id=f.verified_by WHERE ('.implode(' OR ',$conditions).')'.$scope.' ORDER BY f.transaction_date DESC,f.id');if($driver)$values[]=$actor['id'];$q->execute($values);$transactions=$q->fetchAll();
 }
 if($tripIds){
  $q=$pdo->prepare('SELECT a.*,v.plate_number,v.fuel_type,d.name driver_name,f.id transaction_id,f.verification_status,f.revision,f.liters,f.price_per_liter,f.total_cost,f.odometer,f.station,f.receipt_no,f.transaction_date FROM fuel_authorizations a JOIN vehicles v ON v.id=a.vehicle_id JOIN drivers d ON d.id=a.driver_id LEFT JOIN fuel_transactions f ON f.authorization_id=a.id WHERE a.trip_id IN ('.implode(',',array_fill(0,count($tripIds),'?')).')'.$scope.' ORDER BY a.id DESC');$q->execute($driver?[...$tripIds,$actor['id']]:$tripIds);$auths=$q->fetchAll();
 }
 $rows=[];
 foreach($keys as $key){
  $trip=null;$budget=null;
  if($key['trip_id']){
   $trip=funding_trip($pdo,$key['trip_id']);
   if(!$trip){$q=$pdo->prepare('SELECT t.*,v.plate_number,d.name driver_name,NULL::varchar request_kind FROM trips t LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN drivers d ON d.id=t.driver_id WHERE t.id=?');$q->execute([$key['trip_id']]);$trip=$q->fetch();}
   if($trip){$trips[]=$trip;$details[$trip['id']]=$trip;$budget=fuel_ops_budget($pdo,$trip);}
  }
  $records=array_values(array_filter($transactions,fn($f)=>$key['trip_id']?$f['trip_id']===$key['trip_id']:$f['id']===$key['record_id']));
  $authorizations=array_values(array_filter($auths,fn($a)=>$a['trip_id']===$key['trip_id']));$statuses=[];$actual=0.0;
  foreach($records as $f){$statuses[$f['verification_status']]=($statuses[$f['verification_status']]??0)+1;if($f['verification_status']==='Verified')$actual+=(float)$f['total_cost'];}
  $rows[]=['trip'=>$trip,'record_id'=>$key['record_id'],'budget'=>$budget,'transactions'=>$records,'authorizations'=>$authorizations,'statuses'=>$statuses,'actual'=>$actual];
 }
 return ['rows'=>$rows,'trips'=>$trips,'trip_details'=>$details,'authorizations'=>$auths,'page'=>$page,'pages'=>$pages,'total'=>$total,'vehicles'=>$vehicles];
}
