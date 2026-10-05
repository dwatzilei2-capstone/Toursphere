<?php
require_once ROOT_PATH.'/includes/archive.php';
if (!archive_can_trips() && !archive_can_retire()) return;
$_SESSION['archive_csrf'] ??= bin2hex(random_bytes(32));
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/archive.css">
<div class="modal fade archive-modal" id="archive-action-modal" tabindex="-1" aria-labelledby="archive-action-title" aria-describedby="archive-action-copy">
 <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
  <form id="archive-action-form" action="<?= BASE_URL ?>/actions/archive.php" method="post">
   <div class="modal-header"><h2 class="modal-title fs-6 fw-bold" id="archive-action-title">Archive Trip?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
   <div class="modal-body">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['archive_csrf']) ?>"><input type="hidden" name="action"><input type="hidden" name="id"><input type="hidden" name="confirmed" value="1">
    <p id="archive-action-copy"></p>
    <div id="archive-retirement-fields" hidden>
     <div class="bg-light border rounded p-3 mb-3"><strong>Recommended Reason</strong><div id="archive-recommendation"></div><strong class="d-block mt-2">Basis</strong><div class="small" id="archive-basis"></div></div>
     <label class="tc-form-label" for="archive-reason">Retirement Reason <span aria-hidden="true">*</span></label>
     <select class="tc-form-control mb-3" id="archive-reason" name="reason"><option value="">Select a reason</option><?php foreach(archive_reasons() as $reason): ?><option><?= e($reason) ?></option><?php endforeach; ?></select>
     <div id="archive-other" hidden><label class="tc-form-label" for="archive-explanation">Other explanation *</label><textarea class="tc-form-control mb-3" id="archive-explanation" name="explanation" maxlength="1000"></textarea></div>
     <label class="tc-form-label" for="archive-notes">Additional Notes</label><textarea class="tc-form-control" id="archive-notes" name="notes" maxlength="4000" rows="3"></textarea>
    </div>
    <p id="archive-action-error" class="text-danger small mt-3 mb-0" role="alert"></p>
   </div>
   <div class="modal-footer"><button type="button" class="tc-btn tc-btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="tc-btn tc-btn-secondary" id="archive-back" hidden>Back</button><button type="submit" class="tc-btn tc-btn-primary" id="archive-confirm">Confirm Archive</button></div>
  </form>
 </div></div>
</div>
<?php $page_scripts=($page_scripts??'').'<script src="'.BASE_URL.'/js/archive.js?v='.(int)filemtime(ROOT_PATH.'/js/archive.js').'"></script>'; ?>
