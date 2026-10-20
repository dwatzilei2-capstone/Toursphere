<?php
/** Presentation helpers only; do not infer operational or financial values. */
function fuel_number($value,int $precision=3):string { return $value===null||$value===''?'—':(rtrim(rtrim(number_format((float)$value,$precision,'.',','),'0'),'.')?:'0'); }
function fuel_datetime($value):string { return $value?date('M j, Y · g:i A',strtotime($value)):'—'; }
function fuel_unit_price($value):string {
 if($value===null||$value==='')return '—';
 $parts=explode('.',number_format((float)$value,4,'.',','));
 return '₱'.$parts[0].'.'.str_pad(rtrim($parts[1],'0'),2,'0');
}
function fuel_badge(string $status):string {
 $tone=match(explode(' · ',$status)[0]){
  'Verified','Approved','Confirmed','Fulfilled','Completed','Funding Confirmed','Resolved'=>'success',
  'Pending','Submitted','Pending Verification','Pending Finance Approval','Legacy Unverified','Unverified','Assessment Needed','Returned'=>'warning',
  'Rejected','Failed','Critical'=>'danger',
  'Authorized','Dispatched','In Transit','Open'=>'info',
  default=>'neutral'
 };
 return '<span class="fuel-status fuel-status--'.$tone.'">'.e($status).'</span>';
}
function fuel_field(string $label,$value):void { ?><div class="fuel-field"><dt><?= e($label) ?></dt><dd><?= e($value===null||$value===''?'—':$value) ?></dd></div><?php }
