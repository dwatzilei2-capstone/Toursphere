<?php
 
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/driver_fuel_context.php';
require_once ROOT_PATH . '/includes/fuel_operations.php';
require_login();
require_permission('fuel.view');

$pdo = db();

require_once ROOT_PATH.'/includes/fuel-unified-data.php';
require_once ROOT_PATH.'/includes/fuel-presentation.php';
$fuelFilters=[];$fuelFilterErrors=[];
foreach(['q','vehicle','status','from','to','sort'] as $key)$fuelFilters[$key]=is_string($_GET[$key]??null)?trim($_GET[$key]):'';
$fuelFilters['q']=mb_substr($fuelFilters['q'],0,120);
foreach(['from','to'] as $key)if($fuelFilters[$key]!==''){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$fuelFilters[$key]);if(!$date||$date->format('Y-m-d')!==$fuelFilters[$key]){$fuelFilterErrors[]='Use a valid refueling date.';$fuelFilters[$key]='';}}
if($fuelFilters['from']&&$fuelFilters['to']&&$fuelFilters['from']>$fuelFilters['to']){$fuelFilterErrors[]='End date must be on or after the start date.';$fuelFilters['from']=$fuelFilters['to']='';}
$fuelUnifiedData=fuel_unified_data($pdo,current_user(),(int)($_GET['page']??1),$fuelFilters);
$fuelQuery=array_filter($fuelFilters,fn($value,$key)=>$value!==''&&($key!=='sort'||$value!=='newest'),ARRAY_FILTER_USE_BOTH);
$fuelAdvancedCount=(int)($fuelFilters['from']!=='')+(int)($fuelFilters['to']!=='')+(int)($fuelFilters['sort']==='oldest');
$fuelSelected=null;
foreach($fuelUnifiedData['rows'] as $fuelRow)if((isset($_GET['fuel_trip'])&&$fuelRow['trip']&&$fuelRow['trip']['id']===(string)$_GET['fuel_trip'])||(isset($_GET['fuel_record'])&&$fuelRow['record_id']===(string)$_GET['fuel_record'])){$fuelSelected=$fuelRow;break;}

$active_page = 'fuel-transactions';
$body_class = trim(($body_class ?? '') . ' fuel-management-module fuel-transactions-page');
$page_title  = 'Fuel Transactions Log';
require ROOT_PATH . '/includes/header.php';
?>

<div class="fuel-management-page-shell">
<div class="d-flex justify-content-between align-items-center mb-4 fuel-page-heading">
  <div>
    <h1 class="mb-1">Fuel Transactions Log</h1>
    <p class="text-muted-custom mb-0">Fuel allocations, authorizations and verified refueling expenses by trip.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= BASE_URL ?>/modules/fuel-management/fuel-overview.php">← Fuel Overview</a>
    <?php if (has_role(['driver','dispatcher','fleet_admin'])): ?>
      <button class="tc-btn tc-btn-primary tc-btn-sm" onclick="App.openModal('modal-log-fuel')"><i class="bi bi-plus-lg"></i> Submit Fuel Receipt</button>
    <?php endif; ?>
  </div>
</div>
</div>

<?php require ROOT_PATH.'/includes/fuel-operations-ui.php'; ?>
<section class="tc-card fuel-records-panel" aria-labelledby="fuel-records-title">
<h2 class="visually-hidden" id="fuel-records-title">Trip Fuel Records</h2>
<form method="get" class="fuel-filterbar" role="search" aria-label="Filter fuel records">
<label class="fuel-filter-search"><span class="visually-hidden">Search fuel records</span><i class="bi bi-search" aria-hidden="true"></i><input class="tc-form-control" name="q" value="<?= e($fuelFilters['q']) ?>" maxlength="120" placeholder="Search logs, trips, vehicles, drivers…"></label>
<label><span class="visually-hidden">Status</span><select class="tc-form-select" name="status"><option value="">All statuses</option><?php foreach(['authorization'=>['Authorized','Not Required','Assessment Needed','Submitted','Fulfilled','Cancelled'],'transaction'=>['Legacy Unverified','Submitted','Pending Verification','Verified','Returned','Rejected']] as $kind=>$statuses): ?><optgroup label="<?= $kind==='authorization'?'Authorization':'Transaction verification' ?>"><?php foreach($statuses as $status):$value=$kind.':'.$status; ?><option value="<?= e($value) ?>" <?= $fuelFilters['status']===$value?'selected':'' ?>><?= e($status) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
<label><span class="visually-hidden">Vehicle</span><select class="tc-form-select" name="vehicle"><option value="">All vehicles</option><?php foreach($fuelUnifiedData['vehicles'] as $v): ?><option value="<?= e($v['id']) ?>" <?= $fuelFilters['vehicle']===$v['id']?'selected':'' ?>><?= e($v['plate_number']??$v['id']) ?></option><?php endforeach; ?></select></label>
<details class="fuel-advanced-filters" <?= $fuelAdvancedCount||$fuelFilterErrors?'open':'' ?>><summary><i class="bi bi-sliders" aria-hidden="true"></i> Filters<?php if($fuelAdvancedCount): ?><span class="fuel-filter-count" aria-label="<?= $fuelAdvancedCount ?> advanced filters active"><?= $fuelAdvancedCount ?></span><?php endif; ?></summary><div class="fuel-advanced-fields">
<label class="fuel-date-filter"><span>From</span><input class="tc-form-control" type="date" name="from" aria-label="Refueling start date" value="<?= e($fuelFilters['from']) ?>"></label>
<label class="fuel-date-filter"><span>To</span><input class="tc-form-control" type="date" name="to" aria-label="Refueling end date" value="<?= e($fuelFilters['to']) ?>"></label>
<label><span class="visually-hidden">Sort order</span><select class="tc-form-select" name="sort"><option value="newest">Newest trips</option><option value="oldest" <?= $fuelFilters['sort']==='oldest'?'selected':'' ?>>Oldest trips</option></select></label>
<button class="tc-btn tc-btn-primary tc-btn-sm">Apply Filters</button></div></details>
<?php if($fuelQuery): ?><a class="fuel-clear-filters" href="?">Clear Filters</a><?php endif; ?>
<noscript><button class="tc-btn tc-btn-primary tc-btn-sm">Search / Filter</button></noscript>
</form>
<?php foreach($fuelFilterErrors as $error): ?><p class="fuel-filter-error" role="alert"><?= e($error) ?></p><?php endforeach; ?>
<div class="fuel-table-scroll" tabindex="0" role="region" aria-label="Fuel records table"><table class="tc-table fuel-unified-table"><colgroup><col class="fuel-col-log"><col class="fuel-col-vehicle"><col class="fuel-col-allocation"><col class="fuel-col-date"><col class="fuel-col-quantity"><col class="fuel-col-price"><col class="fuel-col-status"><col class="fuel-col-actions"></colgroup><thead><tr><th scope="col">Log / Trip</th><th scope="col">Vehicle / Driver</th><th scope="col">Fuel Allocation</th><th scope="col">Refueling Date</th><th scope="col">Fuel Type / Quantity</th><th scope="col">Price / Total</th><th scope="col">Status</th><th scope="col" class="text-end">Actions</th></tr></thead><tbody>
<?php foreach($fuelUnifiedData['rows'] as $row): $trip=$row['trip'];$first=$row['transactions'][0]??null;$latest=$row['authorizations'][0]??null;$open=null;foreach($row['authorizations'] as $a)if($trip&&($a['vehicle_id']??null)===($trip['vehicle_id']??null)&&($a['driver_id']??null)===($trip['driver_id']??null)&&($a['status']==='Authorized'||$a['verification_status']==='Returned')){$open=$a;break;}
 $count=count($row['transactions']);$multiple=$count>1;$url='?'.http_build_query($fuelQuery+['page'=>$fuelUnifiedData['page']]+($trip?['fuel_trip'=>$trip['id']]:['fuel_record'=>$row['record_id']]));
 $reviewRecord=null;foreach($row['transactions'] as $candidate)if(($fuelAdmin&&$candidate['verification_status']==='Pending Verification')||($fuelDispatcher&&$candidate['verification_status']==='Submitted')){$reviewRecord=$candidate;break;}
 if($reviewRecord)$url.='&fuel_review='.rawurlencode($reviewRecord['id']);
 $vehicle=trim(($trip['brand']??'').' '.($trip['model']??''));$driverName=$trip['driver_name']??$first['driver_name']??'—';
?>
<tr><td data-label="Log / Trip"><strong class="fuel-cell-title fuel-ellipsis" title="<?= e($multiple?$trip['id']:($first['id']??$trip['id']??$row['record_id'])) ?>"><?= e($multiple?$trip['id']:($first['id']??$trip['id']??$row['record_id'])) ?></strong><span class="fuel-cell-sub fuel-ellipsis"><?= e($multiple?$count.' refueling records':($trip['id']??'Depot / historical')) ?></span></td>
<td data-label="Vehicle / Driver"><strong class="fuel-cell-title fuel-ellipsis" title="<?= e($vehicle) ?>"><?= e($trip['plate_number']??$first['plate_number']??'—') ?><?= $vehicle?' · '.e($vehicle):'' ?></strong><span class="fuel-cell-sub fuel-ellipsis" title="<?= e($driverName) ?>"><?= e($driverName) ?></span></td>
<td data-label="Fuel Allocation"><strong class="fuel-cell-title"><?= $row['budget']&&$row['budget']['funding']?money($row['budget']['approved']):'—' ?></strong><span class="fuel-cell-sub"><?= fuel_badge($row['budget']['funding']['status']??'Not allocated') ?></span></td>
<td data-label="Refueling Date"><?php if($first): ?><span class="fuel-cell-title"><?= e(date('M j, Y',strtotime($first['transaction_date']))) ?></span><span class="fuel-cell-sub"><?= e(date('g:i A',strtotime($first['transaction_date']))) ?><?= $multiple?' · Latest refill':'' ?></span><?php else: ?>—<?php endif; ?></td>
<td data-label="Fuel Type / Quantity"><span class="fuel-cell-title"><?= e($first['fuel_type']??'—') ?></span><span class="fuel-cell-sub"><?= $first?fuel_number($first['liters']).' L purchased'.($multiple?' · Latest':''):'—' ?></span></td>
<td data-label="Price / Total"><span class="fuel-cell-title"><?= $first?fuel_unit_price($first['price_per_liter']).'/L':'—' ?></span><span class="fuel-cell-sub fuel-cost"><?= $first?money($first['total_cost']).($multiple?' latest':''):'—' ?></span></td>
<td data-label="Status"><div class="fuel-status-line"><span title="Refueling authorization">Auth</span><?= fuel_badge($latest['status']??'Not assessed') ?></div><div class="fuel-status-line"><span title="Transaction verification">Txn</span><?php if(!$row['statuses']): ?><?= fuel_badge('No refueling') ?><?php else: ?><span class="fuel-status-list"><?php foreach($row['statuses'] as $status=>$n): ?><?= fuel_badge($status.($multiple?' · '.$n:'')) ?><?php endforeach; ?></span><?php endif; ?></div></td>
<td data-label="Actions"><div class="fuel-row-actions"><a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= e($url) ?>" aria-haspopup="dialog" aria-label="Fuel details <?= e($trip['id']??$row['record_id']) ?>"><?= (($fuelDispatcher&&isset($row['statuses']['Submitted']))||($fuelAdmin&&isset($row['statuses']['Pending Verification'])))?'Review':'Details' ?></a>
<?php if($trip&&($trip['request_kind']??'')==='Cargo'&&$fuelDispatcher&&!in_array($trip['status'],['Completed','Cancelled','Incomplete'],true)&&!$open): ?><button class="tc-btn tc-btn-primary tc-btn-sm" type="button" data-fuel-assess-trip="<?= e($trip['id']) ?>">Assess / Authorize</button><?php endif; ?>
<?php if($open&&$trip&&!in_array($trip['status'],['Cancelled','Incomplete'],true)&&($fuelDriver||$fuelDispatcher||$fuelAdmin)): ?><button class="tc-btn tc-btn-secondary tc-btn-sm" type="button" data-fuel-submit-authorization="<?= (int)$open['id'] ?>"><?= $open['verification_status']==='Returned'?'Correct Receipt':'Submit Receipt' ?></button><?php endif; ?></div></td></tr>
<?php endforeach;if(!$fuelUnifiedData['rows']): ?><tr class="fuel-empty"><td colspan="8"><i class="bi bi-fuel-pump" aria-hidden="true"></i><strong><?= $fuelQuery?'No matching fuel records':'No fuel records yet' ?></strong><span><?= $fuelQuery?'Adjust or clear the filters to see other records.':'Cargo trips and recorded refills will appear here.' ?></span></td></tr><?php endif; ?></tbody></table></div>
<footer class="fuel-table-footer"><span><?= $fuelUnifiedData['total']?'Showing '.(($fuelUnifiedData['page']-1)*30+1).'–'.min($fuelUnifiedData['page']*30,$fuelUnifiedData['total']).' of '.$fuelUnifiedData['total']:'0' ?> records · Verified expenses only in cost analysis</span><nav aria-label="Fuel record pages"><?php if($fuelUnifiedData['page']>1): ?><a class="tc-btn tc-btn-light tc-btn-sm" href="?<?= e(http_build_query($fuelQuery+['page'=>$fuelUnifiedData['page']-1])) ?>">Previous</a><?php endif; ?><span>Page <?= $fuelUnifiedData['page'] ?> / <?= $fuelUnifiedData['pages'] ?></span><?php if($fuelUnifiedData['page']<$fuelUnifiedData['pages']): ?><a class="tc-btn tc-btn-light tc-btn-sm" href="?<?= e(http_build_query($fuelQuery+['page'=>$fuelUnifiedData['page']+1])) ?>">Next</a><?php endif; ?></nav></footer></section>
<?php if($fuelSelected)require ROOT_PATH.'/includes/fuel-unified-details.php'; ?>
<script src="<?= BASE_URL ?>/js/fuel-management.js?v=<?= (int)filemtime(ROOT_PATH . '/js/fuel-management.js') ?>"></script>
<?php require ROOT_PATH . '/includes/fuel-receipt-ui.php';require ROOT_PATH.'/includes/trip-funding-ui.php'; require ROOT_PATH . '/includes/footer.php'; ?>
