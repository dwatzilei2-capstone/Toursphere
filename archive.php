<?php
require_once __DIR__.'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/archive.php';
archive_require_access();
$categories=archive_categories();
$category=(string)($_GET['category']??array_key_first($categories));
archive_require_access($category);
$vehicles=$category==='vehicles'; $error=null;
$result=['rows'=>[],'total'=>0,'page'=>1,'pages'=>1]; $options=[];
$filterFields=$vehicles ? ['reason'=>'Retirement Reason','type'=>'Vehicle Type','brand'=>'Brand','model'=>'Model']
    : ['driver'=>'Driver','vehicle'=>'Vehicle','origin'=>'Origin / Terminal','destination'=>'Destination / Route'];
try {
    $result=archive_records(db(),$category,$_GET);
    foreach($filterFields as $key=>$label) {
        if($vehicles) {
            $field=['reason'=>'retirement_reason','type'=>'type','brand'=>'brand','model'=>'model'][$key];
            $q=db()->query("SELECT DISTINCT $field AS value,$field AS label FROM vehicles WHERE is_archived AND status='Retired' AND $field IS NOT NULL ORDER BY label");
        } else {
            $field=['driver'=>'a.driver_id','vehicle'=>'a.vehicle_id','origin'=>'a.origin','destination'=>'a.destination'][$key];
            $display=['driver'=>'d.name','vehicle'=>"a.vehicle_id || ' · ' || v.plate_number",'origin'=>'a.origin','destination'=>'a.destination'][$key];
            $q=db()->prepare("SELECT DISTINCT $field AS value,$display AS label FROM trips a LEFT JOIN drivers d ON d.id=a.driver_id LEFT JOIN vehicles v ON v.id=a.vehicle_id WHERE a.is_archived AND a.status=? AND $field IS NOT NULL ORDER BY label");
            $q->execute([ucfirst($category)]);
        }
        $options[$key]=$q->fetchAll();
    }
} catch(DomainException $e) { $error=$e->getMessage(); }
catch(Throwable $e) { error_log('Archive list: '.$e->getMessage()); $error='Archive records are temporarily unavailable. Please try again.'; }
$sorts=$vehicles ? ['recent'=>'Recently Retired','oldest'=>'Oldest Retirement'] : ['recent'=>'Newest Archived','oldest'=>'Oldest Archived','trip_newest'=>'Newest Trip','trip_oldest'=>'Oldest Trip'];
$criteria=array_intersect_key($_GET,array_flip(array_merge(['q','sort','from','to','archived_from','archived_to'],array_keys($filterFields))));
$hasFilters=(bool)array_filter(array_diff_key($criteria,['sort'=>true]),fn($v)=>$v!=='');
function archive_page_url(int $page): string { global $criteria,$category; return BASE_URL.'/archive.php?'.http_build_query($criteria+['category'=>$category,'page'=>$page]); }
$active_page='archive'; $page_title='Archive Management';
require ROOT_PATH.'/includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/archive.css">
<div class="archive-shell">
 <div class="mb-4"><h1 class="mb-1">Archive Management</h1><p class="text-muted-custom mb-0">Historical records, preserved for operational reference. Trips are archived manually.</p></div>
 <nav class="archive-tabs" aria-label="Archive categories"><?php foreach($categories as $key=>$label): ?><a class="tc-btn tc-btn-secondary tc-btn-sm" href="?category=<?= e($key) ?>" <?= $category===$key?'aria-current="page"':'' ?>><?= e($label) ?></a><?php endforeach; ?></nav>
 <div class="tc-card">
  <form method="get" id="archive-search-form">
   <input type="hidden" name="category" value="<?= e($category) ?>">
   <div class="archive-toolbar">
    <div class="archive-search"><label class="visually-hidden" for="archive-search">Search archived records</label><input type="search" class="tc-form-control" name="q" id="archive-search" placeholder="Search archived records…" value="<?= e((string)($_GET['q']??'')) ?>" maxlength="200"><button class="tc-btn tc-btn-primary tc-btn-sm" type="submit">Search</button></div>
    <button class="tc-btn tc-btn-secondary tc-btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#archive-filter-modal"><i class="bi bi-funnel" aria-hidden="true"></i> Filter<?= $hasFilters?' •':'' ?></button>
    <div><label class="visually-hidden" for="archive-sort">Sort records</label><select class="tc-form-control" id="archive-sort" name="sort"><?php foreach($sorts as $key=>$label): ?><option value="<?= e($key) ?>" <?= ($_GET['sort']??'recent')===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
   </div>
   <div class="modal fade archive-modal" id="archive-filter-modal" tabindex="-1" aria-labelledby="archive-filter-title">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
     <div class="modal-header"><h2 class="modal-title fs-6 fw-bold" id="archive-filter-title">Filter <?= e($categories[$category]) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
     <div class="modal-body"><div class="archive-filter-grid">
      <?php foreach(['from'=>$vehicles?'Retirement From':'Trip Date From','to'=>$vehicles?'Retirement To':'Trip Date To','archived_from'=>'Archived From','archived_to'=>'Archived To'] as $key=>$label): ?>
       <div><label class="tc-form-label" for="filter-<?= e($key) ?>"><?= e($label) ?></label><input class="tc-form-control" id="filter-<?= e($key) ?>" type="date" name="<?= e($key) ?>" value="<?= e((string)($_GET[$key]??'')) ?>"></div>
      <?php endforeach; ?>
      <?php foreach($filterFields as $key=>$label): ?><div><label class="tc-form-label" for="filter-<?= e($key) ?>"><?= e($label) ?></label><select class="tc-form-control" id="filter-<?= e($key) ?>" name="<?= e($key) ?>"><option value="">All</option><?php foreach($options[$key]??[] as $option): ?><option value="<?= e($option['value']) ?>" <?= ($_GET[$key]??'')===$option['value']?'selected':'' ?>><?= e($option['label']??$option['value']) ?></option><?php endforeach; ?></select></div><?php endforeach; ?>
     </div></div>
     <div class="modal-footer"><a href="?category=<?= e($category) ?>" class="tc-btn tc-btn-secondary">Reset</a><button class="tc-btn tc-btn-primary" type="submit">Apply Filters</button></div>
    </div></div>
   </div>
  </form>
  <div id="archive-loading" class="archive-loading" role="status"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading archive records…</div>
  <?php if($hasFilters): ?><div class="px-3 pt-3 small text-muted-custom">Search or filters applied. <a href="?category=<?= e($category) ?>">Clear Filters</a></div><?php endif; ?>
  <?php if($error): ?><div class="p-4" role="alert"><?= e($error) ?> <a href="?category=<?= e($category) ?>">Reset</a></div><?php else: ?>
  <table class="tc-table archive-table"><thead><tr><th><?= $vehicles?'Vehicle':'Trip' ?></th><th><?= $vehicles?'Make & Type':'Driver / Vehicle' ?></th><th><?= $vehicles?'Retirement Reason':'Route' ?></th><th class="archive-secondary"><?= $vehicles?'Retired On':'Trip Date' ?></th><th class="archive-secondary">Archived On</th><th class="text-end">Actions</th></tr></thead><tbody>
   <?php if(!$result['rows']): ?><tr><td colspan="6" class="text-center text-muted-custom py-5"><i class="bi bi-archive d-block fs-3 mb-2" aria-hidden="true"></i><?= $hasFilters?'No records match your search or selected filters.':'No archived records available.' ?><?php if($hasFilters): ?><a class="d-block mt-2" href="?category=<?= e($category) ?>">Clear Filters</a><?php endif; ?></td></tr>
   <?php else: foreach($result['rows'] as $r): ?>
    <tr><td><strong class="text-primary-custom"><?= e($r['id']) ?></strong><div class="small mt-1"><?= e($vehicles?$r['plate_number']:($r['reservation_id']??'')) ?></div><span class="status-badge <?= status_badge_class($r['status']) ?> mt-1"><?= e($r['status']) ?></span></td>
     <td data-label="<?= $vehicles?'Vehicle':'Assignment' ?>"><?php if($vehicles): ?><?= e($r['brand'].' '.$r['model']) ?><div class="small text-muted-custom"><?= e($r['type']) ?></div><?php else: ?><?= e($r['driver_name']??'Unassigned') ?><div class="small text-muted-custom"><?= e(($r['vehicle_id']??'Unassigned').' · '.($r['plate_number']??'—')) ?></div><?php endif; ?></td>
     <td data-label="<?= $vehicles?'Reason':'Route' ?>"><?= e($vehicles?$r['retirement_reason']:$r['origin'].' → '.$r['destination']) ?></td>
     <td class="archive-secondary"><?= e(archive_datetime($vehicles?$r['retired_at']:$r['scheduled_departure'])) ?></td><td class="archive-secondary"><?= e(archive_datetime($r['archived_at'])) ?></td>
     <td class="text-end archive-actions"><a class="tc-btn tc-btn-light tc-btn-sm" href="<?= BASE_URL ?>/archive-details.php?category=<?= e($category) ?>&amp;id=<?= urlencode($r['id']) ?>">View Details<span class="visually-hidden"> for <?= e($r['id']) ?></span></a></td>
    </tr>
   <?php endforeach; endif; ?>
  </tbody></table>
  <nav class="archive-pagination" aria-label="Archive pagination"><span><?= number_format($result['total']) ?> records · Page <?= $result['page'] ?> of <?= $result['pages'] ?></span><div class="d-flex gap-2"><?php if($result['page']>1): ?><a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= e(archive_page_url($result['page']-1)) ?>">Previous</a><?php endif; ?><?php if($result['page']<$result['pages']): ?><a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= e(archive_page_url($result['page']+1)) ?>">Next</a><?php endif; ?></div></nav>
  <?php endif; ?>
 </div>
</div>
<?php $page_scripts='<script>document.getElementById("archive-search-form").addEventListener("submit",function(){document.getElementById("archive-loading").classList.add("is-loading");this.setAttribute("aria-busy","true");});document.getElementById("archive-sort").addEventListener("change",function(){document.getElementById("archive-search-form").requestSubmit();});</script>'; require ROOT_PATH.'/includes/footer.php'; ?>
