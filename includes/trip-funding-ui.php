<?php
require_once ROOT_PATH.'/includes/trip_funding.php';
if(!funding_can_view())return;
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/trip-funding.css?v=<?= filemtime(ROOT_PATH.'/css/trip-funding.css') ?>">
<div class="modal fade funding-modal" id="trip-funding-modal" tabindex="-1" aria-labelledby="trip-funding-title">
 <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title fs-6 fw-bold" id="trip-funding-title">Trip Funding</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body" id="trip-funding-body" aria-live="polite"></div>
  <div class="modal-footer"><span class="small text-muted-custom">Mock Finance — Test Mode</span><button type="button" class="tc-btn tc-btn-secondary" data-bs-dismiss="modal">Close</button></div>
 </div></div>
</div>
<?php $page_scripts=($page_scripts??'').'<script src="'.BASE_URL.'/js/trip-funding.js?v='.filemtime(ROOT_PATH.'/js/trip-funding.js').'"></script>'; ?>
