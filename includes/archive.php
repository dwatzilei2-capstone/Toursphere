<?php
/** Archive permissions deliberately combine existing RBAC with fixed operational role limits. */
function archive_can_view(): bool
{
    return has_role(['fleet_admin','fleet_manager','dispatcher','staff']) && can('archive.view')
        && (has_role(['fleet_admin','fleet_manager']) || can('dispatch.view'));
}
function archive_can_trips(): bool
{
    return archive_can_view() && has_role(['fleet_admin','dispatcher']) && can('archive.trips') && can('dispatch.manage');
}
function archive_can_retire(): bool
{
    return archive_can_view() && has_role('fleet_admin') && can('archive.retire');
}
function archive_categories(): array
{
    if (!archive_can_view()) return [];
    $categories = ['completed'=>'Completed Trips','cancelled'=>'Cancelled Trips','incomplete'=>'Incomplete Trips'];
    return has_role(['fleet_admin','fleet_manager']) && can('vehicles.view')
        ? ['vehicles'=>'Retired Vehicles'] + $categories : $categories;
}
function archive_require_access(?string $category = null): void
{
    require_login();
    if (!archive_can_view() || ($category !== null && !isset(archive_categories()[$category]))) {
        http_response_code(403);
        exit('You do not have permission to access these Archive records.');
    }
}
function archive_reasons(): array
{
    return ['Beyond Economical Repair','Severe/Permanent Damage','End of Service Life',
        'Repeated Mechanical Failure','No Longer Fit for Operation','Sold/Disposed','Other'];
}
function archive_datetime(?string $value): string
{
    if (!$value) return '—';
    return (new DateTimeImmutable($value))->format('M j, Y · g:i A');
}
function archive_recommendation(PDO $pdo, string $vehicleId): array
{
    // Routine servicing and estimated costs alone cannot establish economic irreparability.
    $q = $pdo->prepare("SELECT COUNT(*) FROM maintenance_orders WHERE vehicle_id=?
        AND status IN ('In Repair','Completed')
        AND (service_type ~* '(breakdown|mechanical failure|engine failure|transmission failure)'
             OR notes ~* '(breakdown|mechanical failure|engine failure|transmission failure)')");
    $q->execute([$vehicleId]);
    $count = (int)$q->fetchColumn();
    return $count >= 3
        ? ['reason'=>'Repeated Mechanical Failure','basis'=>"{$count} maintenance orders explicitly record breakdown or mechanical failure (in repair or completed). Review the source records before deciding."]
        : ['reason'=>null,'basis'=>'No recommendation available — insufficient vehicle history.'];
}
function archive_log(PDO $pdo, string $action, string $id, array $details): void
{
    $u = current_user();
    $details['actor_name']=$u['name'];
    $details['actor_role']=$u['role_code'];
    $pdo->prepare('INSERT INTO audit_logs(action,user_id,entity_type,entity_id,details) VALUES (?,?,?,?,?)')
        ->execute([$action,$u['id'],$action==='Trip archived'?'trip':'vehicle',$id,json_encode($details,JSON_THROW_ON_ERROR)]);
}
function archive_trip(PDO $pdo, string $id): void
{
    if (!archive_can_trips()) throw new DomainException('You do not have permission to archive trips.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT * FROM trips WHERE id=? FOR UPDATE'); $q->execute([$id]); $t=$q->fetch();
        if (!$t) throw new DomainException('Trip not found.');
        if ($t['is_archived']) throw new DomainException('This trip is already archived.');
        if (!in_array($t['status'],['Completed','Cancelled','Incomplete'],true)) throw new DomainException('Only Completed, Cancelled, or Incomplete trips can be archived.');
        if ($t['navigation_active']) throw new DomainException('Resolve active navigation before archiving this trip.');
        $pdo->prepare('UPDATE trips SET is_archived=TRUE, archived_at=NOW(), archived_by=? WHERE id=?')->execute([current_user()['id'],$id]);
        archive_log($pdo,'Trip archived',$id,['status'=>$t['status'],'reservation_id'=>$t['reservation_id'],'vehicle_id'=>$t['vehicle_id'],'driver_id'=>$t['driver_id']]);
        $pdo->prepare("INSERT INTO trip_timeline(trip_id,title,event_time,completed,active_step,sort_order)
            VALUES (?,'Trip manually archived',TO_CHAR(NOW(),'YYYY-MM-DD HH24:MI'),1,0,
            (SELECT COALESCE(MAX(sort_order),0)+1 FROM trip_timeline WHERE trip_id=?))")->execute([$id,$id]);
        $pdo->commit();
    } catch (Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function archive_retire_vehicle(PDO $pdo, string $id, string $reason, string $explanation, string $notes): void
{
    if (!archive_can_retire()) throw new DomainException('Only Admin may retire a vehicle.');
    if (!in_array($reason,archive_reasons(),true) || ($reason==='Other' && trim($explanation)==='')) throw new DomainException('Select a retirement reason and explain Other when selected.');
    if (strlen($notes)>4000 || strlen($explanation)>1000) throw new DomainException('Retirement notes or explanation are too long.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT * FROM vehicles WHERE id=? FOR UPDATE'); $q->execute([$id]); $v=$q->fetch();
        if (!$v) throw new DomainException('Vehicle not found.');
        if ($v['is_archived'] || $v['status']==='Retired') throw new DomainException('This vehicle is already retired.');
        $q=$pdo->prepare("SELECT 1 FROM trips WHERE vehicle_id=? AND status NOT IN ('Completed','Cancelled','Incomplete')
            UNION ALL SELECT 1 FROM reservations WHERE assigned_vehicle_id=? AND status NOT IN ('Completed','Cancelled','Incomplete','Rejected') LIMIT 1");
        $q->execute([$id,$id]);
        if ($q->fetchColumn() || in_array($v['status'],['On Trip','Dispatched','In Transit','Returning to Depot'],true))
            throw new DomainException('Vehicle Cannot Be Retired. This vehicle is currently assigned to an active trip. Complete, cancel, or properly resolve the current trip before retiring the vehicle.');
        $rec=archive_recommendation($pdo,$id);
        $finalNotes=($reason==='Other' ? 'Other explanation: '.trim($explanation)."\n" : '').trim($notes);
        $pdo->prepare("UPDATE vehicles SET status='Retired',is_archived=TRUE,archived_at=NOW(),archived_by=?,
            retired_at=NOW(),retired_by=?,retirement_reason=?,retirement_notes=?,retirement_recommendation=?,retirement_basis=? WHERE id=?")
            ->execute([current_user()['id'],current_user()['id'],$reason,$finalNotes,$rec['reason'],$rec['basis'],$id]);
        archive_log($pdo,'Vehicle retired',$id,['reason'=>$reason,'notes'=>$finalNotes,'recommendation'=>$rec,'previous_status'=>$v['status']]);
        $pdo->commit();
    } catch (Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** All query criteria are bound; ordering is selected from a fixed allowlist. */
function archive_records(PDO $pdo, string $category, array $input): array
{
    $vehicles=$category==='vehicles';
    $from=$vehicles ? ' FROM vehicles a LEFT JOIN users u ON u.id=a.retired_by'
        : ' FROM trips a LEFT JOIN vehicles v ON v.id=a.vehicle_id LEFT JOIN drivers d ON d.id=a.driver_id LEFT JOIN reservations r ON r.id=a.reservation_id LEFT JOIN users u ON u.id=a.archived_by';
    $where=['a.is_archived=TRUE']; $params=[];
    $where[]=$vehicles ? "a.status='Retired'" : 'a.status=?';
    if(!$vehicles) $params[]=ucfirst($category);
    $search=trim((string)($input['q']??''));
    if($search!=='') {
        $fields=$vehicles ? ['a.id','a.plate_number','a.brand','a.model','a.type'] : ['a.id','a.reservation_id','a.vehicle_id','v.plate_number','d.name','r.client_name','a.origin','a.destination','a.waypoints'];
        $where[]='('.implode(' OR ',array_map(fn($f)=>"$f ILIKE ?",$fields)).')';
        foreach($fields as $f) $params[]='%'.$search.'%';
    }
    $dates=['from'=>[$vehicles?'a.retired_at':'a.scheduled_departure','>='], 'to'=>[$vehicles?'a.retired_at':'a.scheduled_departure','<'], 'archived_from'=>['a.archived_at','>='], 'archived_to'=>['a.archived_at','<']];
    foreach($dates as $key=>[$field,$op]) {
        $value=(string)($input[$key]??'');
        if($value==='') continue;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$date || $date->format('Y-m-d')!==$value) throw new DomainException('Enter a valid date range.');
        $where[]="$field $op ?::date"; $params[]=$op==='<' ? $date->modify('+1 day')->format('Y-m-d') : $value;
    }
    foreach(['from'=>'to','archived_from'=>'archived_to'] as $start=>$end) {
        if(!empty($input[$start]) && !empty($input[$end]) && $input[$start]>$input[$end]) throw new DomainException('The end date must be on or after the start date.');
    }
    $filters=$vehicles ? ['reason'=>'a.retirement_reason','type'=>'a.type','brand'=>'a.brand','model'=>'a.model'] : ['driver'=>'a.driver_id','vehicle'=>'a.vehicle_id','origin'=>'a.origin','destination'=>'a.destination'];
    foreach($filters as $key=>$field) if(($input[$key]??'')!=='') { $where[]="$field=?"; $params[]=(string)$input[$key]; }
    $sql=$from.' WHERE '.implode(' AND ',$where);
    $q=$pdo->prepare('SELECT COUNT(*)'.$sql); $q->execute($params); $total=(int)$q->fetchColumn();
    $pages=max(1,(int)ceil($total/15)); $page=min($pages,max(1,(int)($input['page']??1))); $offset=($page-1)*15;
    $orders=$vehicles ? ['recent'=>'a.retired_at DESC','oldest'=>'a.retired_at ASC']
        : ['recent'=>'a.archived_at DESC','oldest'=>'a.archived_at ASC','trip_newest'=>'a.scheduled_departure DESC NULLS LAST','trip_oldest'=>'a.scheduled_departure ASC NULLS LAST'];
    $order=$orders[$input['sort']??'recent']??$orders['recent'];
    $fields=$vehicles?'a.*,u.name AS actor_name':'a.*,v.plate_number,d.name AS driver_name,r.client_name,u.name AS actor_name';
    $q=$pdo->prepare('SELECT '.$fields.$sql." ORDER BY $order,a.id LIMIT 15 OFFSET $offset"); $q->execute($params);
    return ['rows'=>$q->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>$pages];
}
