<?php
require_once __DIR__.'/includes/bootstrap.php'; require_once ROOT_PATH.'/includes/archive.php';
$category=(string)($_GET['category']??''); archive_require_access($category);
$vehicles=$category==='vehicles'; $id=(string)($_GET['id']??''); $record=null; $events=[]; $timeline=[]; $fuel=[]; $maintenance=[];
try {
    if($vehicles) $q=db()->prepare("SELECT a.*,u.name AS retired_by_name FROM vehicles a LEFT JOIN users u ON u.id=a.retired_by WHERE a.id=? AND a.is_archived AND a.status='Retired'");
    else $q=db()->prepare('SELECT a.*,v.plate_number,v.brand,v.model,d.name AS driver_name,r.client_name,r.cancellation_reason,r.cancellation_notes,r.cancelled_at,c.name AS cancelled_by_name,u.name AS archived_by_name FROM trips a LEFT JOIN vehicles v ON v.id=a.vehicle_id LEFT JOIN drivers d ON d.id=a.driver_id LEFT JOIN reservations r ON r.id=a.reservation_id LEFT JOIN users c ON c.id=r.cancelled_by LEFT JOIN users u ON u.id=a.archived_by WHERE a.id=? AND a.is_archived AND a.status=?');
    $q->execute($vehicles?[$id]:[$id,ucfirst($category)]); $record=$q->fetch();
    if(!$record) { http_response_code(404); }
    else {
         $q=db()->prepare('SELECT l.*,u.name AS user_name FROM audit_logs l LEFT JOIN users u ON u.id=l.user_id WHERE l.entity_type=? AND l.entity_id=? ORDER BY l.created_at DESC,l.id DESC LIMIT 50'); $q->execute([$vehicles?'vehicle':'trip',$id]); $events=$q->fetchAll();
        if(!$vehicles) {
            $q=db()->prepare('SELECT * FROM trip_timeline WHERE trip_id=? ORDER BY sort_order LIMIT 100'); $q->execute([$id]); $timeline=$q->fetchAll();
            $q=db()->prepare('SELECT id,transaction_date,liters,total_cost,station FROM fuel_transactions WHERE trip_id=? ORDER BY transaction_date DESC LIMIT 50'); $q->execute([$id]); $fuel=$q->fetchAll();
        } else {
            $q=db()->prepare('SELECT id,service_type,status,scheduled_date,estimated_cost,notes FROM maintenance_orders WHERE vehicle_id=? ORDER BY scheduled_date DESC LIMIT 50'); $q->execute([$id]); $maintenance=$q->fetchAll();
        }
    }
} catch(Throwable $e) { error_log('Archive details: '.$e->getMessage()); http_response_code(500); $record=null; }
$active_page='archive'; $page_title='Archive Record Details'; require ROOT_PATH.'/includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/archive.css">
<div class="archive-shell">
 <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center mb-4"><div><h1 class="mb-1">Archive Record Details</h1><p class="text-muted-custom mb-0"><?= e($id) ?> · Historical information</p></div><a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/archive.php?category=<?= e($category) ?>">Back to Archive</a></div>
 <?php if(!$record): ?><div class="tc-card p-4">This Archive record is unavailable.</div><?php else:
 $fields=$vehicles ? ['id'=>'Vehicle ID','plate_number'=>'Plate Number','brand'=>'Brand','model'=>'Model','type'=>'Vehicle Type','status'=>'Status','retirement_reason'=>'Retirement Reason','retirement_recommendation'=>'Recommended Reason at Retirement','retirement_basis'=>'Recommendation Basis','retirement_notes'=>'Retirement Notes','retired_at'=>'Retired On','retired_by_name'=>'Retired By','archived_at'=>'Archived On']
 : ['id'=>'Trip ID','status'=>'Final Status','reservation_id'=>'Reservation Reference','client_name'=>'Customer / Reservation','driver_name'=>'Driver','driver_id'=>'Driver ID','vehicle_id'=>'Vehicle ID','plate_number'=>'Plate Number','origin'=>'Origin / Terminal A','destination'=>'Destination / Terminal B','waypoints'=>'Route Waypoints','passengers'=>'Passenger Count','scheduled_departure'=>'Scheduled Departure','actual_departure'=>'Actual Departure','estimated_arrival'=>'Estimated Arrival','actual_arrival'=>'Actual Arrival','distance_km'=>'Distance (km)','fuel_estimate'=>'Fuel Estimate','toll_fee'=>'Toll Fee','driver_allowance'=>'Driver Allowance','total_cost'=>'Trip Cost','route_history_id'=>'Route History Reference','completion_notes'=>'Completion / Interruption Notes','cancellation_reason'=>'Cancellation Reason','cancellation_notes'=>'Cancellation Notes','cancelled_at'=>'Cancelled On','cancelled_by_name'=>'Cancelled By','archived_at'=>'Archived On','archived_by_name'=>'Archived By'];
 ?>
 <div class="tc-card p-3 mb-3"><dl class="archive-detail-grid mb-0"><?php foreach($fields as $key=>$label): $value=$record[$key]??null; if($value!==null && $value!=='') { if(in_array($key,['retired_at','archived_at','cancelled_at','scheduled_departure','actual_departure','estimated_arrival','actual_arrival'],true)) $value=archive_datetime($value); elseif(in_array($key,['toll_fee','driver_allowance','total_cost'],true)) $value=money($value); } ?><div><dt><?= e($label) ?></dt><dd class="mb-0"><?= e($value===null||$value===''?'—':(string)$value) ?></dd></div><?php endforeach; ?></dl></div>
 <?php if($maintenance): ?><div class="tc-card p-3 mb-3"><h2 class="fs-6 fw-bold">Vehicle Maintenance History</h2><p class="small text-muted-custom">Most recent 50 records. All source records remain preserved.</p><?php foreach($maintenance as $m): ?><div class="border-top py-3"><strong><?= e($m['id'].' · '.$m['service_type']) ?></strong><div class="small text-muted-custom"><?= e($m['scheduled_date'].' · '.$m['status']) ?> · Estimated cost <?= money($m['estimated_cost']) ?></div><p class="small mb-0"><?= e($m['notes']??'') ?></p></div><?php endforeach; ?></div><?php endif; ?>
 <?php if(!$vehicles): ?><div class="tc-card p-3 mb-3"><h2 class="fs-6 fw-bold">Fuel Records</h2><p class="small text-muted-custom">Most recent 50 linked fuel records. All records remain in Fuel Management.</p><?php if(!$fuel): ?><p class="small mb-0">No linked fuel records.</p><?php else: foreach($fuel as $f): ?><div class="border-top py-2"><strong><?= e($f['id']) ?></strong><div class="small"><?= e($f['transaction_date']) ?> · <?= e($f['liters']) ?> L · <?= money($f['total_cost']) ?> · <?= e($f['station']??'') ?></div></div><?php endforeach; endif; ?></div>
 <div class="tc-card p-3 mb-3"><h2 class="fs-6 fw-bold">Trip Timeline</h2><?php if(!$timeline): ?><p class="small mb-0">No timeline records.</p><?php else: foreach($timeline as $t): ?><div class="border-top py-2"><strong class="small"><?= e($t['title']) ?></strong><div class="small text-muted-custom"><?= e($t['event_time']??'') ?></div></div><?php endforeach; endif; ?></div><?php endif; ?>
 <div class="tc-card p-3"><h2 class="fs-6 fw-bold">Archive History</h2><?php if(!$events): ?><p class="small mb-0">No Archive activity recorded.</p><?php else: foreach($events as $event): $eventDetails=json_decode((string)$event['details'],true)?:[]; ?><div class="border-top py-3"><strong><?= e($event['action']) ?></strong><div class="small text-muted-custom"><?= e(($eventDetails['actor_name']??$event['user_name']??'Former user').' · '.($eventDetails['actor_role']??'').' · '.$event['created_at']) ?></div></div><?php endforeach; endif; ?></div>
 <?php endif; ?>
</div>
<?php require ROOT_PATH.'/includes/footer.php'; ?>
