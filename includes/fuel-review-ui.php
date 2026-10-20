<?php
$reviewKey='fuel-review-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$f['id']);$isDispatcher=has_role('dispatcher');
if(empty($fuelReviewWorkspaceMode)){
 $reviewLink='?'.http_build_query(($fuelQuery??[])+['page'=>$fuelUnifiedData['page'],'fuel_trip'=>$f['trip_id'],'fuel_review'=>$f['id']]); ?>
<a class="fuel-review-trigger" href="<?= e($reviewLink) ?>" aria-haspopup="dialog"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Review Transaction</a>
<?php return; } ?>
<div class="fuel-review" data-fuel-review-panel>
<section id="<?= e($reviewKey) ?>" class="fuel-review-content" aria-labelledby="<?= e($reviewKey) ?>-title">
<header class="fuel-review-heading"><div><h4 id="<?= e($reviewKey) ?>-title">Review Decision</h4><p>Choose an action based on the transaction details and receipt.</p></div><?= fuel_badge($f['verification_status']) ?></header>
<form method="post" action="<?= BASE_URL ?>/actions/fuel.php" data-fuel-review>
<input type="hidden" name="action" value="review"><input type="hidden" name="csrf" value="<?= e($_SESSION['fuel_csrf']) ?>"><input type="hidden" name="transaction_id" value="<?= e($f['id']) ?>"><input type="hidden" name="revision" value="<?= (int)$f['revision'] ?>">
<div class="fuel-review-controls"><fieldset class="fuel-review-decisions"><legend>Review decision</legend>
<?php $choices=$isDispatcher?['confirm'=>['Confirm','success','check-circle'],'return'=>['Return for Correction','warning','arrow-counterclockwise']]:['verify'=>['Verify','success','check-circle'],'return'=>['Return for Correction','warning','arrow-counterclockwise'],'reject'=>['Reject','danger','x-circle']];foreach($choices as $value=>[$label,$tone,$icon]): ?>
<label class="fuel-review-choice fuel-review-choice--<?= e($tone) ?>"><input type="radio" name="decision" value="<?= e($value) ?>" required><span><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i><?= e($label) ?></span></label>
<?php endforeach; ?></fieldset>
<p class="fuel-review-initial" role="status" data-fuel-review-initial><i class="bi bi-info-circle" aria-hidden="true"></i> Select a decision to continue.</p>
<div class="fuel-review-followup" hidden>
<div class="fuel-review-confirmation" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true" data-fuel-decision-icon></i><div><strong data-fuel-decision-title></strong><p data-fuel-decision-message></p></div></div>
<div class="fuel-review-notes"><label for="<?= e($reviewKey) ?>-reason"><span data-fuel-notes-title>Verification Notes</span> <span data-fuel-reason-label>(optional)</span></label><textarea id="<?= e($reviewKey) ?>-reason" name="reason" rows="3" maxlength="2000" class="tc-form-control" placeholder="Add verification notes if needed…" aria-describedby="<?= e($reviewKey) ?>-error" disabled></textarea><p id="<?= e($reviewKey) ?>-error" class="fuel-review-error" role="alert" hidden></p></div></div></div>
<footer class="fuel-review-actions"><span class="fuel-review-progress" role="status" aria-live="polite"></span><button type="button" class="tc-btn tc-btn-secondary tc-btn-sm" data-fuel-review-cancel>Cancel</button><button type="submit" class="tc-btn tc-btn-primary tc-btn-sm" data-fuel-review-submit hidden disabled><i class="bi bi-check-lg" aria-hidden="true" data-fuel-submit-icon></i> <span data-fuel-submit-label>Confirm Verification</span></button></footer>
</form></section></div>
