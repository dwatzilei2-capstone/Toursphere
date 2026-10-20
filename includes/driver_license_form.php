<?php
if(!can('drivers.manage'))return;
$_SESSION['driver_license_csrf']??=bin2hex(random_bytes(32));
?>
<div id="modal-driver-license" class="tc-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="driver-license-title">
 <div class="tc-modal" style="max-width:720px">
  <div class="tc-card-header"><h4 id="driver-license-title" class="mb-0">Verify Driver License</h4><button type="button" class="btn-close" onclick="App.closeModal('modal-driver-license')" aria-label="Close"></button></div>
  <form id="driver-license-form" class="tc-card-body" method="post" enctype="multipart/form-data" action="<?= BASE_URL ?>/actions/driver-license.php">
   <input type="hidden" name="csrf_token" value="<?= e($_SESSION['driver_license_csrf']) ?>">
   <input type="hidden" name="driver_id" value="">
   <p class="small text-muted-custom">Active logistics drivers need an unexpired Professional license and verified DL Code C. Truck-trailer combinations also require CE. Select only codes printed on the actual license.</p>
   <label class="form-label" for="driver-name">Driver name</label><input id="driver-name" class="tc-form-control mb-3" name="name" required maxlength="120" readonly>
   <label class="form-label" for="driver-license-file">Driver's license (both sides in one PDF or image)</label><input id="driver-license-file" class="tc-form-control mb-2" type="file" name="license_document" required accept=".pdf,.jpg,.jpeg,.png">
   <p class="small text-muted-custom">Maximum 8 MB. Searchable PDFs support extraction. Scans and images require manual verification. Test documents cannot verify qualifications.</p>
   <p id="driver-license-analysis" role="status" class="small"></p><a id="driver-license-preview" target="_blank" rel="noopener" hidden>Review uploaded license</a>
   <div class="row g-3">
    <div class="col-md-6"><label for="driver-license-no" class="form-label">License number</label><input id="driver-license-no" class="tc-form-control" name="license_no" required maxlength="40"></div>
    <div class="col-md-6"><label for="driver-license-expiry" class="form-label">Expiration date</label><input id="driver-license-expiry" class="tc-form-control" type="date" name="license_expiration" min="<?= date('Y-m-d') ?>" required></div>
    <div class="col-12"><label for="driver-license-type" class="form-label">License classification</label><select id="driver-license-type" class="tc-form-select" name="license_type" required><option value="">Select classification</option><option>Professional</option><option>Non-Professional</option></select></div>
    <fieldset class="col-12"><legend class="fs-6">Authorized LTO DL codes</legend><?php foreach(['A','A1','B','B1','B2','BE','C','CE','D'] as $code): ?><label class="me-3"><input type="checkbox" name="authorized_dl_codes[]" value="<?= $code ?>"> <?= $code ?></label><?php endforeach; ?></fieldset>
    <div class="col-12"><label class="form-label" for="driver-license-notes">Verification notes</label><textarea id="driver-license-notes" class="tc-form-control" name="verification_notes" required minlength="10" maxlength="2000" placeholder="Record which original license details and DL codes you checked."></textarea></div>
    <div class="col-12"><label><input type="checkbox" name="manual_verified" value="1" required> I reviewed the original license, both sides, and confirm its holder, Professional classification, expiry and selected DL codes.</label></div>
   </div>
   <button id="driver-license-save" class="tc-btn tc-btn-primary mt-3" type="submit">Verify and Save License</button>
  </form>
 </div>
</div>
<script src="<?= BASE_URL ?>/js/driver-license.js?v=<?= filemtime(ROOT_PATH.'/js/driver-license.js') ?>"></script>
