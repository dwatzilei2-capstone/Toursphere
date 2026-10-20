<?php
if(PHP_SAPI!=='cli')exit;
$requestedFuelPageRole=$argv[1]??'dispatcher';$overview=($argv[2]??'')==='overview';$fuelPageModal=($argv[2]??'')==='modal';
ob_start();require __DIR__.'/fuel_operations_test.php';ob_end_clean();
$role=$requestedFuelPageRole;
$sampleAuthorization=ops_tx(fn()=>fuel_ops_authorize($pdo,'T-OPS',array_replace($assess,['maximum_amount'=>100,'estimated_liters'=>1]),$dispatcher));
$sampleName=bin2hex(random_bytes(24)).'.png';$samplePath=ROOT_PATH.'/storage/private/fuel-receipts/'.$sampleName;$files[]=$samplePath;file_put_contents($samplePath,file_get_contents($path)."page-fixture");
$sampleFile=['filename'=>$sampleName,'name'=>'isolated-page.png','mime'=>'image/png','size'=>filesize($samplePath),'sha256'=>hash_file('sha256',$samplePath)];
$sampleInput=array_replace($submission,['liters'=>1,'total_cost'=>60,'odometer'=>120,'receipt_no'=>'PAGE-TEST']);
$sampleTransaction=ops_tx(fn()=>fuel_ops_submission($pdo,$sampleAuthorization,$sampleInput,$driver,$sampleFile));
if($role!=='dispatcher')ops_tx(fn()=>fuel_ops_review($pdo,$sampleTransaction,'confirm','Page fixture',1,$dispatcher));
$current_user=['id'=>1,'name'=>'Fuel Page Test','role_code'=>$role,'role_name'=>'Test Role','emp_id'=>'TEST','avatar'=>null];$current_permissions=['fuel.view'];if(in_array($role,['dispatcher','fleet_admin','fleet_manager'],true))$current_permissions[]='dispatch.view';if(in_array($role,['dispatcher','fleet_admin'],true))$current_permissions[]='dispatch.manage';
$_SESSION['last_valid_activity']=time();$_SERVER['REQUEST_METHOD']='GET';$_GET=$fuelPageModal?['fuel_trip'=>'T-OPS','fuel_review'=>$sampleTransaction]:[];
ob_start();require ROOT_PATH.'/modules/fuel-management/'.($overview?'fuel-overview.php':'fuel-transactions.php');$html=ob_get_clean();
ops_check((str_contains($html,'id="modal-fuel-assessment"'))===(!$overview&&$role==='dispatcher'),'Assessment controls remain in unified Transactions Log');
ops_check((str_contains($html,'id="fuel-submission-form"'))===(!$overview&&in_array($role,['driver','dispatcher','fleet_admin'],true)),'Submission controls remain in Transactions Log for operational roles');
ops_check(!str_contains($html,'verified_trip_consumption')&&!str_contains($html,'current_fuel'),'No tank tracking or purchased-equals-consumed checkbox');
if(!$overview){
 ops_check(str_contains($html,'Trip Fuel Records'),'Unified table is rendered');
 ops_check(str_contains($html,'fuel-advanced-filters')&&str_contains($html,'Apply Filters'),'Date and sort controls stay in expandable Filters');
 foreach(['Verified'=>'success','Approved'=>'success','Fulfilled'=>'success','Funding Confirmed'=>'success','Legacy Unverified'=>'warning','Pending Verification'=>'warning','Returned'=>'warning','Rejected'=>'danger','Failed'=>'danger','Cancelled'=>'neutral','Not assessed'=>'neutral','Not allocated'=>'neutral','Authorized'=>'info'] as $status=>$tone)ops_check(str_contains(fuel_badge($status),'fuel-status--'.$tone),$status.' uses its semantic status color');
 ops_check(str_contains(fuel_badge('Verified · 2'),'fuel-status--success'),'Grouped counts preserve semantic color');

 ops_check(!str_contains($html,'Fuel Allocations &amp; Authorizations'),'No second allocations table');
 ops_check(!str_contains($html,'<summary>History</summary>'),'No redundant history action');
 ops_check(substr_count($html,'<table')===1,'One unified table; details use expandable refills instead of nested tables');
 ops_check(str_contains($html,'data-fuel-review')===($fuelPageModal&&in_array($role,['dispatcher','fleet_admin'],true)),'Review actions remain on exact records in the modal');
 if(!$fuelPageModal&&in_array($role,['dispatcher','fleet_admin'],true))ops_check(str_contains($html,'fuel_review='.rawurlencode($sampleTransaction)),'Existing Review entry points directly to the pending transaction');
 if($fuelPageModal&&!in_array($role,['dispatcher','fleet_admin'],true))ops_check(!str_contains($html,'fuel-review-workspace'),'Forged review parameter cannot expose unauthorized review controls');

 if($fuelPageModal&&in_array($role,['dispatcher','fleet_admin'],true)){
  ops_check(str_contains($html,'Review Fuel Transaction')&&str_contains($html,'fuel-review-workspace'),'Review entry opens complete two-column workspace immediately');
  ops_check(str_contains($html,'data-review-receipt-id="'.$sampleTransaction.'"'),'Receipt preview belongs to the exact reviewed transaction');
  ops_check(!str_contains($html,'data-fuel-review-toggle'),'Workspace requires no additional review disclosure click');

  ops_check(str_contains($html,'type="radio" name="decision"')&&!preg_match('/<select[^>]*name="decision"/',$html),'Review decisions use exclusive radio buttons, not a dropdown');
  ops_check(str_contains($html,'data-fuel-review-submit hidden disabled')&&str_contains($html,'data-fuel-review-cancel'),'Review initially hides confirmation action and retains Cancel');
  ops_check(str_contains($html,'value="verify"')===($role==='fleet_admin'),'Only Admin can select Verify');
  ops_check(str_contains($html,'value="reject"')===($role==='fleet_admin'),'Only Admin can select Reject');
  ops_check(str_contains($html,'value="confirm"')===($role==='dispatcher'),'Dispatcher retains confirmation instead of final verification');
  ops_check(!preg_match('/<input[^>]*name="decision"[^>]*checked/',$html),'No review decision is preselected');
  ops_check(str_contains($html,'class="fuel-review-followup" hidden'),'Notes and confirmation message start hidden');
 }

 $group=array_values(array_filter($fuelUnifiedData['rows'],fn($item)=>($item['trip']['id']??'')==='T-OPS'))[0];
 ops_check(count($group['transactions'])===2&&count(array_unique(array_column($group['transactions'],'id')))===2,'Multiple refuels remain distinct within one trip');
 ops_check($group['actual']===480.0,'Verified cost is counted once and pending expense excluded');
 ops_check(count($group['statuses'])===2,'Mixed verification statuses are retained');
 ops_check(fuel_unified_data($pdo,$current_user,1,['q'=>'T-OPS'])['total']===1,'Search matches trip before pagination');
 ops_check(fuel_unified_data($pdo,$current_user,1,['q'=>'unmatched-record'])['total']===0,'Search provides an empty state without fabricated rows');
 ops_check(fuel_unified_data($pdo,$current_user,1,['status'=>'transaction:Verified'])['total']===1,'Verification filter matches actual transaction');
 ops_check(fuel_unified_data($pdo,$current_user,1,['status'=>'transaction:Rejected'])['total']===0,'Verification filter excludes other statuses');
 ops_check(fuel_unified_data($pdo,$current_user,1,['vehicle'=>'V-CARGO'])['total']===1,'Vehicle filter matches assigned vehicle');
 ops_check(fuel_unified_data($pdo,$current_user,1,['from'=>date('Y-m-d',strtotime('+1 day'))])['total']===0,'Date filter excludes older refills');
 ops_check(fuel_unified_data($pdo,['id'=>999999,'role_code'=>'driver'],1,['status'=>'transaction:Verified'])['total']===0,'Filters preserve Driver isolation');
 ops_check(fuel_number(0)==='0'&&fuel_number(8.5)==='8.5','Quantities preserve zero and omit trailing decimals');
 ops_check(fuel_unit_price(60)==='₱60.00'&&fuel_unit_price(60.1234)==='₱60.1234','Unit prices retain stored precision with readable currency formatting');

 ops_check(count(fuel_unified_data($pdo,['id'=>999999,'role_code'=>'driver'])['rows'])===0,'Other Driver cannot access grouped trip records');
 $pdo->exec("INSERT INTO fuel_transactions(id,vehicle_id,driver_id,transaction_date,fuel_type,liters,price_per_liter,total_cost) VALUES ('ORPHAN-FUEL','V-CARGO','D-CARGO',CURRENT_TIMESTAMP,'Diesel',2,60,120)");
 $withOrphan=fuel_unified_data($pdo,$current_user);$orphans=array_values(array_filter($withOrphan['rows'],fn($item)=>$item['record_id']==='ORPHAN-FUEL'));
 ops_check(count($orphans)===1&&count($orphans[0]['transactions'])===1&&$orphans[0]['transactions'][0]['id']==='ORPHAN-FUEL','Unlinked historical transactions remain individually accessible');
 ops_check($orphans[0]['actual']===0.0,'Unverified historical expense is not counted as verified actual cost');
 $pdo->exec("INSERT INTO fuel_transactions(id,vehicle_id,driver_id,transaction_date,fuel_type,liters,price_per_liter,total_cost) SELECT 'PAGE-'||i,'V-CARGO','D-CARGO',CURRENT_TIMESTAMP,'Diesel',2,60,120 FROM generate_series(1,31) i");
 $firstPage=fuel_unified_data($pdo,$current_user,1);$secondPage=fuel_unified_data($pdo,$current_user,2);
 ops_check($firstPage['pages']===2&&count($firstPage['rows'])===30&&count($secondPage['rows'])===3,'Pagination preserves all grouped and standalone records');
 ops_check(fuel_unified_data($pdo,$current_user,1,['q'=>'PAGE-31'])['total']===1,'Search applies before pagination');
 ops_check(fuel_unified_data($pdo,$current_user,999,['q'=>'PAGE-31'])['page']===1,'Filtered pagination clamps safely');

}else {
 ops_check(str_contains($html,'Measured consumption efficiency:')&&str_contains($html,'Not available'),'Existing monitoring preserved');
 ops_check(!str_contains($html,'Fuel Allocations &amp; Authorizations')&&!str_contains($html,'Refueling Authorizations'),'No allocation table moved to Overview');
 ops_check(str_contains($html,'chart-fuel-monthly')&&str_contains($html,'Trip Fuel: Estimated vs Actual'),'Original charts and comparison retained');
}

echo "Fuel page role checks passed for $role ".($overview?'overview':'transactions')." using temporary tables.\n";
