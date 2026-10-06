<div class="modal fade" id="fuel-receipt-modal" tabindex="-1" aria-labelledby="fuel-receipt-title" aria-hidden="true">
 <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
  <div class="modal-header py-3"><h2 class="modal-title fs-6 fw-bold" id="fuel-receipt-title">Fuel Receipt</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close receipt preview"></button></div>
  <div class="modal-body"><div id="fuel-receipt-status" class="small text-muted-custom" role="status"></div><div class="row g-3" id="fuel-receipt-content" hidden><div class="col-md-6" id="fuel-receipt-preview"></div><div class="col-md-6"><h3 class="fs-6 fw-semibold">Transaction Information</h3><dl id="fuel-receipt-info" class="small"></dl><a id="fuel-receipt-download" class="tc-btn tc-btn-primary tc-btn-sm">Download Receipt</a></div></div></div>
 </div></div>
</div>
<script src="<?= BASE_URL ?>/js/fuel-receipt.js?v=<?= (int)filemtime(ROOT_PATH.'/js/fuel-receipt.js') ?>"></script>
