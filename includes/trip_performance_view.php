<?php
// Included only by the existing Trip Performance page.
require_once __DIR__.'/trip_performance_presentation.php';
$isDriver=has_role('driver');$isDispatcher=has_role('dispatcher');$isReviewer=performance_reviewer($current_user);
$tripList=$pdo->prepare("SELECT t.*,d.name AS driver_name,r.cargo_type,r.status AS reservation_status,e.status AS evaluation_status,EXISTS(SELECT 1 FROM reservation_events ev WHERE ev.reservation_id=r.id AND ev.status='Verified Completed') AS delivery_verified FROM trips t JOIN reservations r ON r.id=t.reservation_id LEFT JOIN drivers d ON d.id=t.driver_id LEFT JOIN trip_performance_evaluations e ON e.trip_id=t.id WHERE r.request_kind='Cargo'".($isDriver?' AND d.user_id=?':'')." ORDER BY COALESCE(t.actual_arrival,t.scheduled_departure,t.created_at) DESC,t.id");
$tripList->execute($isDriver?[$current_user['id']]:[]);$cargoTrips=$tripList->fetchAll();
$selectedId=trim((string)($_GET['trip']??''));$selectedTrip=null;$performanceError=null;
if($selectedId!==''){try{$selectedTrip=performance_trip($pdo,$selectedId,$current_user);}catch(RuntimeException $e){$performanceError=$e->getMessage();}}
$configWeights=performance_config($pdo);
function performance_display_date($value): string {
 if(!$value)return 'Not recorded';
 try{return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(company_timezone()))->format('M j, Y · g:i A');}catch(Exception $e){return (string)$value;}
}
function performance_form_start(string $operation,string $tripId): void { ?>
 <form method="post" action="<?= BASE_URL ?>/actions/trip-performance.php">
 <input type="hidden" name="csrf" value="<?= e($_SESSION['trip_performance_csrf']) ?>">
 <input type="hidden" name="trip_id" value="<?= e($tripId) ?>">
 <input type="hidden" name="operation" value="<?= e($operation) ?>">
<?php }
?>
<div class="tc-card p-3 mb-4">
 <h4 class="fw-bold fs-6">Trip Performance Evaluation Matrix</h4>
 <p class="text-muted-custom small mb-2">Current evaluation criteria and weights. Open a trip record to view or evaluate its evidence; historical evaluations retain their saved weights.</p>
 <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Criterion</th><th>Weight</th></tr></thead><tbody>
 <?php foreach(PERFORMANCE_CRITERIA as $key=>$label): ?><tr><td><?= e($label) ?></td><td><?= (int)$configWeights[$key] ?>%</td></tr><?php endforeach; ?>
 </tbody></table></div>
</div>
<div class="tc-card p-3 mb-4">
 <h4 class="fw-bold fs-6">Trip Performance Records</h4>
 <p class="text-muted-custom small">Review cargo delivery evidence, evaluations and approvals.</p>
 <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Trip ID</th><th>Driver</th><th>Delivery Status</th><th>Evaluation Status</th><th>Actions</th></tr></thead><tbody>
 <?php if(!$cargoTrips): ?><tr><td colspan="5" class="text-center text-muted-custom py-4">No cargo trip records available. Evaluations become available after delivery is verified completed.</td></tr><?php endif; ?>
 <?php $eligibleCount=0;foreach($cargoTrips as $record):
  if(performance_delivery_verified($record)&&!$record['evaluation_status'])$eligibleCount++;
  $action=performance_record_action($record,$current_user);
  $deliveryStatus=$record['status']==='Completed'?($record['current_step']==='Verified Completed'?'Verified Completed':'Delivered'):$record['status'];
 ?>
 <tr><td class="fw-semibold text-nowrap"><?= e($record['id']) ?></td><td><?= e($record['driver_name']?:'Unassigned') ?></td><td><?= e($deliveryStatus) ?></td><td><?= e($record['evaluation_status']?:'Not Evaluated') ?></td><td><a class="tc-btn tc-btn-secondary tc-btn-sm text-nowrap" href="?trip=<?= rawurlencode($record['id']) ?>" aria-haspopup="dialog"><?= e($action['label']) ?></a></td></tr>
 <?php endforeach; ?></tbody></table></div>
 <?php if($cargoTrips&&!$eligibleCount): ?><p class="small text-muted-custom mt-2 mb-0">No verified completed cargo trips are awaiting an initial evaluation.</p><?php endif; ?>
 <?php if($performanceError): ?><p class="text-danger"><?= e($performanceError) ?></p><?php endif; ?>
 </div>
 <?php if($selectedTrip):
  $q=$pdo->prepare('SELECT * FROM trip_performance_evaluations WHERE trip_id=?');$q->execute([$selectedId]);$evaluation=$q->fetch();
  $weights=$evaluation?json_decode($evaluation['weights'],true):$configWeights;$scores=$evaluation?json_decode($evaluation['scores'],true):[];
  $timing=performance_timing($selectedTrip);$evidence=performance_evidence($pdo,$selectedId);$sources=performance_sources($pdo,$selectedTrip);
  $uploadedEvidence=array_values(array_filter($evidence,fn($item)=>!empty($item['stored_filename'])));
  $fuelReadiness=performance_fuel_readiness($selectedTrip,$sources);
  $fuelExcludable=performance_fuel_excludable($pdo,$selectedTrip);
  $editable=performance_delivery_verified($selectedTrip)&&(!$evaluation||$evaluation['status']!=='Finalized')&&($isDispatcher||($isReviewer&&$evaluation&&$evaluation['status']==='Submitted'));
 ?>
 <div id="modal-trip-performance" class="tc-modal-backdrop show" role="dialog" aria-modal="true" aria-labelledby="trip-performance-modal-title" data-trip-id="<?= e($selectedId) ?>" data-evaluation-actor="<?= e($current_user['id'].':'.$current_user['role_code']) ?>">
 <div class="tc-modal tc-modal-xl">
 <div class="tc-card-header"><h4 class="mb-0 fw-bold fs-6" id="trip-performance-modal-title"><?= e($selectedId) ?> · <?= $editable?($isReviewer?'Review Evaluation':'Trip Evaluation'):($evaluation?'View Evaluation':'Trip Evidence') ?></h4><button type="button" class="btn-close" data-performance-close aria-label="Close evaluation"></button></div>
 <div class="tc-card-body performance-modal-body">
 <?php if(preg_match('/DEVELOPMENT TEST|DEVELOPMENT SIMULATION/i',$selectedTrip['cargo_type'].' '.($selectedTrip['completion_notes']??''))): ?><div class="small text-muted-custom mb-2"><span class="badge bg-secondary-subtle text-secondary me-1">Test</span> Simulated evaluation · not an actual driver assessment.</div><?php endif; ?>
 <div class="border rounded p-3 mb-3 performance-trip-summary">
  <div class="d-flex flex-wrap justify-content-between gap-2"><strong><?= e($selectedId.' · '.$selectedTrip['cargo_type'].' · '.$selectedTrip['cargo_weight_kg'].' kg') ?></strong><span class="badge bg-primary-subtle text-primary"><?= e($evaluation['status']??'Not Evaluated') ?></span></div>
  <p class="small mt-2 mb-1">Driver: <?= e($selectedTrip['driver_name']?:'Unassigned') ?> · Vehicle: <?= e($selectedTrip['vehicle_id']?:'Unassigned') ?> · Trip status: <?= e($selectedTrip['status']) ?></p>
  <div class="row g-2 small mt-1">
   <div class="col-md-4"><strong>Scheduled Delivery:</strong> <?= e($timing['scheduled_arrival']?:'Not recorded') ?></div>
   <div class="col-md-4"><strong>Actual Arrival:</strong> <?= e($timing['actual_arrival']?:'Not recorded') ?></div>
   <div class="col-md-4"><strong>Delivery Variance:</strong> <?= $timing['variance_minutes']===null?'Not Evaluated':e(($timing['variance_minutes']>0?'+':'').$timing['variance_minutes'].' min') ?></div>
   <div class="performance-overall"><strong>Overall Score:</strong> <?= !$evaluation||$evaluation['overall_score']===null?'Not Evaluated':e($evaluation['overall_score'].' / 100') ?> <?= $evaluation&&$evaluation['status']!=='Finalized'?'(provisional)':'' ?></div>
  </div>
  <details class="small text-muted-custom mt-2 mb-2"><summary>Timing and GPS information</summary><div class="mt-1">Arrival source: <?= e($timing['actual_source']) ?>. Confirm that this represents delivery arrival. Route estimates are predictions.</div><div>Observed GPS coordinates are not retained by the current tracker. Planned destination coordinates do not establish arrival.</div></details>
  <?php if($evaluation): ?><details class="small mt-2"><summary>Saved Timing and Approval</summary><?php performance_render_facts(performance_display_facts($evaluation['timing_snapshot'])); ?>Submitted: <?= e($evaluation['submitted_at']??'Not submitted') ?> · Finalized: <?= e($evaluation['finalized_at']??'Not finalized') ?></details><?php endif; ?>
 </div>
 <section class="performance-section performance-evidence-section"><h5 class="performance-section-title"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> Trip Evidence</h5>
 <p class="small text-muted-custom">For On-Time Delivery, link and verify a receiving warehouse document confirming whether delivery arrived on time. Driver evidence may support the warehouse record. Uploading a file does not verify it; use Verify Evidence after review. A separate delivery deadline is not required for a documented assessment.</p>
 <details class="small text-muted-custom mb-2"><summary>Evidence guidelines</summary><p class="mb-1">Use verified records relevant to each criterion. Fuel purchases alone do not establish consumption; safety and conduct need supporting records. POD does not establish GPS location.</p></details>
 <div class="table-responsive mb-3"><table class="table table-sm align-middle performance-evidence-table"><thead><tr><th>Evidence</th><th>Supporting Record</th><th>Verification</th></tr></thead><tbody>
 <?php if(!$evidence): ?><tr><td colspan="3" class="text-muted-custom">No evidence linked. Not Evaluated.</td></tr><?php endif; ?>
 <?php foreach($evidence as $item): ?><tr>
 <td>#<?= (int)$item['id'] ?> <?= e($item['kind']) ?><br><small><?= e(implode(', ',array_map(fn($key)=>PERFORMANCE_CRITERIA[$key]??$key,json_decode($item['criteria'],true)))) ?></small></td>
 <td>
 <?php $testEvidence=(bool)preg_match('/DEVELOPMENT SIMULATION|DEVELOPMENT TEST/i',$item['description']); ?>
 <div class="performance-evidence-summary"><?= e($testEvidence?'Simulated '.strtolower($item['kind']).' record':$item['description']) ?></div>
 <?php if($item['stored_filename']): ?><a class="small performance-evidence-summary" href="<?= BASE_URL ?>/actions/trip-performance-evidence.php?id=<?= (int)$item['id'] ?>"><?= e($item['original_filename']) ?></a><?php endif; ?>
 <?php if($item['stored_filename']&&($isDispatcher||has_role('fleet_admin')||($isDriver&&(int)$item['added_by']===(int)$current_user['id']&&!$item['verified_at']))&&(!$evaluation||$evaluation['status']!=='Finalized')): performance_form_start('remove_upload',$selectedId); ?><input type="hidden" name="evidence_id" value="<?= (int)$item['id'] ?>"><button type="submit" class="tc-btn tc-btn-secondary tc-btn-sm mt-1">Remove File</button></form><?php endif; ?>
 <details class="small mt-1"><summary>Record notes</summary><div class="mt-1"><?= e($item['description']) ?></div><?php if(!$item['stored_filename']): ?><div class="text-muted-custom"><?= e($item['source_table'].' #'.$item['source_id']) ?></div><?php performance_render_facts(performance_display_facts($item['snapshot'])); ?><?php endif; ?></details></td>
 <td><?php if($item['verified_at']): ?><span class="text-success small fw-semibold">Verified</span><div class="small"><?= e($item['verifier_name']) ?></div><div class="small text-muted-custom"><?= e(performance_display_date($item['verified_at'])) ?></div>
 <details class="small mt-1"><summary>Verification notes</summary><div class="mt-1"><?= e($item['verification_notes']) ?></div></details>
 <?php elseif($isDispatcher): performance_form_start('verify_evidence',$selectedId); ?><input type="hidden" name="evidence_id" value="<?= (int)$item['id'] ?>"><label class="small">Verification notes<input class="form-control form-control-sm" name="verification_notes" required maxlength="2000"></label><button class="tc-btn tc-btn-secondary tc-btn-sm mt-1">Verify Evidence</button></form>
 <?php else: ?><span class="text-muted-custom">Awaiting Dispatcher verification</span><?php endif; ?></td>
 </tr><?php endforeach; ?></tbody></table></div>
 <?php if($isDispatcher||$isDriver): ?>
 <details class="mb-3"><summary class="text-primary">Add Trip Evidence</summary>
 <?php if($isDispatcher&&$sources): performance_form_start('add_evidence',$selectedId); ?>
 <div class="row g-2 mt-1"><label class="col-md-6">Existing Record<select class="form-select form-select-sm" name="source" required><?php foreach($sources as $key=>$source): ?><option value="<?= e($key) ?>"><?= e($source['kind'].' · '.$source['source_table'].' #'.$source['source_id']) ?></option><?php endforeach; ?></select></label><label class="col-md-6">Evidence Description<input class="form-control form-control-sm" name="description" required maxlength="2000"></label></div><button class="tc-btn tc-btn-secondary tc-btn-sm mt-2">Link Record</button></form>
 <?php endif; ?>
 <?php if(!$uploadedEvidence): ?><p class="small text-muted-custom">One uploaded file per trip. Combine the warehouse confirmation, delivery receipt and driver evidence into one PDF if needed (maximum 5 MB).</p><form method="post" enctype="multipart/form-data" action="<?= BASE_URL ?>/actions/trip-performance.php" class="border-top mt-3 pt-3">
 <input type="hidden" name="csrf" value="<?= e($_SESSION['trip_performance_csrf']) ?>"><input type="hidden" name="trip_id" value="<?= e($selectedId) ?>"><input type="hidden" name="operation" value="add_evidence"><input type="hidden" name="source" value="upload">
 <div class="row g-2"><label class="col-md-4">Document Type<select class="form-select form-select-sm" name="kind"><option>Supporting Document</option><option>POD</option></select></label><label class="col-md-4">Document (PDF, JPG, PNG; 5 MB)<input type="file" accept=".pdf,.jpg,.jpeg,.png" name="evidence_file" class="form-control form-control-sm" required></label><label class="col-md-4">What does this document establish?<input class="form-control form-control-sm" name="description" maxlength="2000" required></label></div>
 <label class="small d-block mt-2"><input type="checkbox" name="includes_pod" value="1"> This Supporting Document also contains the POD / signed delivery receipt. Dispatcher must review this before verification.</label><fieldset class="mt-2"><legend class="fs-6">Supported Criteria</legend><div class="d-flex flex-wrap gap-3"><?php foreach(PERFORMANCE_CRITERIA as $key=>$label): ?><label class="small"><input type="checkbox" name="criteria[]" value="<?= e($key) ?>"> <?= e($label) ?></label><?php endforeach; ?></div><p class="text-muted-custom small">POD supports Delivery Completion and Documentation. For one combined file covering other criteria, choose Supporting Document, select its supported criteria, and mark whether it includes the POD.</p></fieldset><button class="tc-btn tc-btn-primary tc-btn-sm">Upload Evidence</button></form><?php else: ?><p class="small text-muted-custom">A file is already uploaded. Use Remove File on the existing upload before adding a replacement. Linking system records does not upload another file.</p><?php endif; ?>
 </details><?php endif; ?>
 </section><section class="performance-section performance-matrix-section"><h5 class="performance-section-title"><i class="bi bi-bar-chart-fill" aria-hidden="true"></i> Trip Performance Evaluation Matrix</h5>
 <?php if($fuelExcludable&&($editable||($scores['fuel']['status']??'')==='excluded')): ?><p class="small alert alert-info">Fuel Efficiency (<?= (int)$weights['fuel'] ?>%) is excluded because no fuel consumption record exists. The remaining <?= (int)(array_sum($weights)-$weights['fuel']) ?>% is normalized to a score out of 100. No fuel score is invented.</p><?php endif; ?>
 <?php if($editable): ?><div class="performance-evaluation-guide small mb-3"><strong>How to evaluate</strong><ol class="mb-1"><li>Add or link the relevant records in Trip Evidence, then use Verify Evidence after reviewing them.</li><li>Score only criteria supported by verified evidence. Select the evidence below and explain the score.</li><li>Leave other scores blank (Not Evaluated). You can submit an initial evaluation with incomplete criteria; final approval requires all weighted criteria.</li></ol><button type="button" class="tc-btn tc-btn-secondary tc-btn-sm" data-evidence-jump>Add / Review Evidence</button></div><?php endif; ?>
 <?php if($editable): ?><form id="performance-evaluation-form" data-delivery-variance="<?= e($timing['variance_minutes']??'') ?>" data-distance-km="<?= e($selectedTrip['distance_km']??'') ?>" method="post" action="<?= BASE_URL ?>/actions/trip-performance.php"><input type="hidden" name="csrf" value="<?= e($_SESSION['trip_performance_csrf']) ?>"><input type="hidden" name="trip_id" value="<?= e($selectedId) ?>"><input type="hidden" name="revision" value="<?= (int)($evaluation['revision']??0) ?>"><?php endif; ?>
 <div class="table-responsive performance-matrix"><table class="table table-sm align-middle"><thead><tr><th>Criterion</th><th>Weight</th><th>Score / 100</th><th>Verified Evidence &amp; Explanation</th></tr></thead><tbody>
 <?php foreach(PERFORMANCE_CRITERIA as $key=>$label): $row=$scores[$key]??[];$fuelBlocked=$key==='fuel'&&!$fuelReadiness['available']&&!isset($row['score']); ?><tr data-criterion="<?= e($key) ?>" data-criterion-label="<?= e($label) ?>" data-weight="<?= (int)$weights[$key] ?>" data-excluded-criterion="<?= $key==='fuel'&&($editable?$fuelExcludable:($row['status']??'')==='excluded')?'true':'false' ?>">
 <td class="text-nowrap"><?= e($label) ?>
 <?php if($editable): ?><div class="small text-muted-custom mt-1"><?php $guides=['on_time'=>'Warehouse assessment + verified supporting document, or verified recorded delivery timing.','completion'=>'Select both Delivery Verification and POD (delivery receipt).','safety'=>'Select verified safety or inspection evidence.','fuel'=>'Actual consumption evidence + recorded distance. Fuel purchases alone are insufficient.','documentation'=>'Select verified documentation records.','conduct'=>'Select verified conduct or requester feedback records.'];echo e($guides[$key]); ?></div><?php endif; ?>
 <?php if($key==='on_time'): ?>
 <?php if($editable): ?><label class="d-block small mt-1">Receiving warehouse assessment<select class="form-select form-select-sm" name="scores[on_time][delivery_outcome]"><?php foreach([''=>'Choose assessment / recorded arrival','on_time'=>'On time','late'=>'Late'] as $value=>$text): ?><option value="<?= e($value) ?>" <?= ($row['delivery_outcome']??'')===$value?'selected':'' ?>><?= e($text) ?></option><?php endforeach; ?></select></label>
 <?php elseif(!empty($row['delivery_outcome'])): ?><div class="small text-muted-custom"><?= $row['delivery_outcome']==='on_time'?'Warehouse confirms on time':'Warehouse confirms late' ?></div><?php endif; ?>
 <?php endif; ?></td><td><?= $weights[$key] ?>%</td>
 <td><?php if($editable): ?><input class="form-control form-control-sm" style="width:110px" aria-label="<?= e($label) ?> score" type="number" min="0" max="100" step="0.01" placeholder="<?= $fuelBlocked&&($fuelReadiness['reason']??'')==='missing_fuel_record'?'No fuel record':'Not Evaluated' ?>" name="scores[<?= $key ?>][score]" value="<?= e($row['score']??'') ?>" <?= $fuelBlocked?'readonly data-records-unavailable="true" aria-describedby="fuel-record-readiness"':'' ?>><?php else: ?><?= isset($row['score'])?e($row['score']):(($row['status']??'')==='excluded'?'Excluded — no fuel record':'Not Evaluated') ?><?php endif; ?>
 </td>
 <td style="min-width:200px"> <?php if($key==='fuel'&&!$fuelReadiness['available']): ?><div id="fuel-record-readiness" class="small text-muted-custom mt-2" role="status"><?= e($fuelReadiness['message']) ?> <?php if($editable): ?><?= $fuelExcludable?'Excluded from the final score; this criterion will not block approval.':'Existing fuel records must be reviewed before approval.' ?><?php endif; ?></div><?php endif; ?><details <?= $editable?'open':'' ?>><summary class="small text-primary"><?= count($row['evidence_ids']??[]) ?> linked · <?= $editable?'Edit evidence / explanation':'View evidence / explanation' ?></summary><div class="mt-1"><?php if($editable): $hasEvidence=false;foreach($evidence as $item): if(!$item['verified_at']||!in_array($key,json_decode($item['criteria'],true),true))continue;$hasEvidence=true; ?><label class="d-block small"><input type="checkbox" name="scores[<?= $key ?>][evidence_ids][]" value="<?= (int)$item['id'] ?>" data-evidence-kind="<?= e($item['kind']) ?>" data-source-table="<?= e($item['source_table']??'') ?>" data-includes-pod="<?= !empty(json_decode($item['snapshot'],true)['includes_pod'])?'1':'0' ?>" <?= in_array((int)$item['id'],$row['evidence_ids']??[],true)?'checked':'' ?>> #<?= (int)$item['id'] ?> <?= e($item['kind']) ?></label><?php endforeach;if(!$hasEvidence): ?><span class="text-muted-custom">No verified evidence for this criterion. Add or link the relevant record in Trip Evidence, then verify it before scoring.</span><?php endif;else: ?><?= e(implode(', ',array_map(fn($id)=>'#'.$id,$row['evidence_ids']??[]))) ?><?php endif; ?></div>
 <div class="mt-1"><?php if($editable): ?><label class="d-block small">Explanation<textarea class="form-control form-control-sm" name="scores[<?= $key ?>][notes]" maxlength="2000" rows="1"><?= e($row['notes']??'') ?></textarea></label><?php else: ?><?= e($row['notes']??'') ?><?php endif; ?></div></details></td>
 </tr><?php endforeach; ?></tbody></table></div>
 <div class="row g-2 mt-1 performance-delay"><div class="col-md-4"><label class="d-block">Delay Responsibility</label><?php if($editable): ?><select class="form-select form-select-sm" name="delay_cause"><?php foreach(['Unknown','External','Driver','None'] as $cause): ?><option <?= ($evaluation['delay_cause']??'Unknown')===$cause?'selected':'' ?>><?= $cause ?></option><?php endforeach; ?></select><?php else: ?><?= e($evaluation['delay_cause']??'Unknown') ?><?php endif; ?></div>
 <div class="col-md-8"><label class="d-block">Delay Explanation</label><?php if($editable): ?><textarea class="form-control form-control-sm" name="delay_notes" maxlength="2000" rows="2"><?= e($evaluation['delay_notes']??'') ?></textarea><?php else: ?><?= e($evaluation['delay_notes']??'Not recorded') ?><?php endif; ?></div></div>
 <p class="text-muted-custom small mt-2">A late timestamp does not establish driver fault. Unknown delay responsibility stays Not Evaluated; external delays require evidence and cannot reduce the driver's punctuality score. Overall score = sum of each score × weight / 100. All applicable weighted criteria require evidence. Only Fuel Efficiency may be excluded when there are no recorded fuel consumption sources; remaining weights are normalized to 100 and the exclusion is saved in the audit trail.</p>
 <?php if($editable): if($isReviewer): ?><label class="d-block mb-2">Review / Adjustment Reason<textarea class="form-control form-control-sm" name="change_reason" required maxlength="2000"></textarea></label><div class="performance-submit-actions"><button form="performance-evaluation-form" class="tc-btn tc-btn-secondary tc-btn-sm" name="operation" value="adjust">Save Review</button> <button form="performance-evaluation-form" class="tc-btn tc-btn-primary tc-btn-sm" name="operation" value="finalize">Approve &amp; Finalize</button></div><?php else: ?><div class="performance-submit-actions"><button form="performance-evaluation-form" class="tc-btn tc-btn-primary tc-btn-sm" name="operation" value="submit">Submit Initial Evaluation</button></div><?php endif; ?></form><?php endif; ?>
 </section><?php if($evaluation):
 $q=$pdo->prepare('SELECT p.*,u.name AS driver_account FROM trip_performance_disputes p JOIN users u ON u.id=p.driver_user_id WHERE evaluation_id=? ORDER BY id DESC');$q->execute([$evaluation['id']]);$disputes=$q->fetchAll();$openDispute=false;
 ?>
 <h5 class="fs-6 fw-bold mt-4">Driver Disputes</h5>
 <?php foreach($disputes as $dispute):$openDispute=$openDispute||$dispute['status']==='Open'; ?>
 <div class="border rounded p-2 mb-2 small"><strong><?= e($dispute['status'].' · '.$dispute['created_at']) ?></strong><div><?= e($dispute['reason']) ?></div><div>Evidence: <?= e(implode(', ',array_map(fn($id)=>'#'.$id,json_decode($dispute['evidence_ids'],true)))) ?></div>
 <?php if($dispute['status']==='Open'&&$isReviewer): performance_form_start('resolve',$selectedId); ?><input type="hidden" name="dispute_id" value="<?= (int)$dispute['id'] ?>"><label class="d-block">Decision<select class="form-select form-select-sm" name="decision"><option>Upheld</option><option>Returned for Review</option></select></label><p class="text-muted-custom small">Upheld keeps the evaluation. Returned for Review reopens the same evaluation and retains its previous final approval in the audit trail.</p><label class="d-block">Resolution<textarea class="form-control form-control-sm" name="resolution" maxlength="2000" required></textarea></label><button class="tc-btn tc-btn-secondary tc-btn-sm mt-1">Resolve Dispute</button></form><?php elseif($dispute['resolution']): ?><div><?= e($dispute['resolution'].' · '.$dispute['resolved_at']) ?></div><?php endif; ?></div>
 <?php endforeach; ?>
 <?php if(!$disputes): ?><p class="text-muted-custom small">No disputes submitted.</p><?php endif; ?>
 <?php if($isDriver&&!$openDispute): performance_form_start('dispute',$selectedId); ?><label class="d-block">Dispute Reason<textarea class="form-control" name="reason" required maxlength="2000"></textarea></label><fieldset class="mt-2"><legend class="fs-6">Supporting Evidence (optional)</legend><?php foreach($evidence as $item): ?><label class="d-block small"><input type="checkbox" name="evidence_ids[]" value="<?= (int)$item['id'] ?>"> #<?= (int)$item['id'] ?> <?= e($item['description']) ?></label><?php endforeach; ?></fieldset><button class="tc-btn tc-btn-secondary tc-btn-sm mt-2">Submit Dispute</button></form><?php endif; ?>
 <?php endif; ?>
 <details class="mt-3 performance-section performance-audit"><summary class="text-primary">Evaluation Audit Trail</summary>
 <?php $q=$pdo->prepare("SELECT action,actor_name,actor_role,created_at,old_values,new_values FROM audit_logs WHERE entity_type='trip_performance' AND (new_values->>'trip_id'=? OR (entity_id=? AND action IN ('Evaluation Submitted','Evaluation Adjusted','Evaluation Finalized','Evaluation Disputed','Dispute Resolved'))) ORDER BY id DESC");$q->execute([$selectedId,(string)($evaluation['id']??0)]);$history=$q->fetchAll(); ?>
 <?php if(!$history): ?><p class="small text-muted-custom">No evaluation changes recorded.</p><?php endif; ?>
 <?php foreach($history as $event): ?><details class="small border-bottom p-2"><summary><?= e($event['action'].' · '.$event['actor_name'].' · '.$event['actor_role'].' · '.$event['created_at']) ?></summary><div class="performance-audit-change"><h6>Previous record</h6><?php performance_render_facts(performance_display_facts($event['old_values'])); ?><h6>Updated record</h6><?php performance_render_facts(performance_display_facts($event['new_values'])); ?></div></details><?php endforeach; ?>
 </details>
 </div><div class="tc-card-footer text-end"><button class="tc-btn tc-btn-secondary" type="button" data-performance-close>Close</button></div>
 </div></div>
 <?php endif; ?>
