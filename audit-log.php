<?php
require_once __DIR__ . '/includes/bootstrap.php';
audit_require_access();
header('Cache-Control: no-store');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','HEAD'],true)) {
    http_response_code(405); header('Allow: GET, HEAD'); exit('Method not allowed');
}
require_once ROOT_PATH . '/includes/audit-ui.php';
$page_title='Audit Log'; $active_page='audit-log';
$filters=[];
foreach (['q','role','action','module','from','to'] as $key) $filters[$key]=mb_substr(trim(is_string($_GET[$key]??null)?$_GET[$key]:''),0,200);
$from=' FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN roles r ON r.id=u.role_id';
$roleSource="COALESCE(NULLIF(a.actor_role,''),r.name,'')";
$roleSql="CASE lower($roleSource) WHEN 'fleet_admin' THEN 'Admin' WHEN 'fleet administrator' THEN 'Admin' WHEN 'administrator' THEN 'Admin' WHEN 'admin' THEN 'Admin' WHEN 'customer' THEN 'User' WHEN 'user' THEN 'User' WHEN 'dispatcher' THEN 'Dispatcher' WHEN 'driver' THEN 'Driver' WHEN '' THEN 'Unknown' ELSE $roleSource END";
$roles=db()->query('SELECT DISTINCT '.$roleSql.' AS label'.$from.' ORDER BY label')->fetchAll(PDO::FETCH_COLUMN);
$actions=db()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$moduleTypes=db()->query('SELECT DISTINCT entity_type FROM audit_logs')->fetchAll(PDO::FETCH_COLUMN);
$modules=[]; foreach ($moduleTypes as $type) $modules[audit_ui_module($type)][]=$type; ksort($modules);
$clauses=[]; $params=[]; $filterError='';
if ($filters['q']!=='') {
    $clauses[]="concat_ws(' ',a.actor_name,a.actor_email,$roleSql,a.action,a.entity_type,a.entity_id,a.details,a.old_values::text,a.new_values::text,u.name,u.email) ILIKE ?";
    $params[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$filters['q']).'%';
}
if ($filters['role']!=='') { $clauses[]=$roleSql.'=?'; $params[]=$filters['role']; }
if ($filters['action']!=='') { $clauses[]='a.action=?'; $params[]=$filters['action']; }
if ($filters['module']!=='') {
    $types=$modules[$filters['module']] ?? [];
    if (!$types) $clauses[]='false';
    else { $parts=[]; foreach ($types as $type) { if ($type===null) $parts[]='a.entity_type IS NULL'; else { $parts[]='a.entity_type=?'; $params[]=$type; } } $clauses[]='('.implode(' OR ',$parts).')'; }
}
foreach (['from','to'] as $key) if ($filters[$key]!=='') {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$filters[$key]);
    if (!$date || $date->format('Y-m-d')!==$filters[$key]) { $filterError='Enter valid dates for the date range.'; $clauses[]='false'; continue; }
    $clauses[]=$key==='from' ? 'a.created_at>=?::date' : "a.created_at<(?::date + INTERVAL '1 day')"; $params[]=$filters[$key];
}
if ($filters['from'] && $filters['to'] && $filters['from']>$filters['to']) { $filterError='The end date must be on or after the start date.'; $clauses[]='false'; }
$where=$clauses ? ' WHERE '.implode(' AND ',$clauses) : '';
$count=db()->prepare('SELECT COUNT(*)'.$from.$where); $count->execute($params); $total=(int)$count->fetchColumn();
$perPage=20; $pages=max(1,(int)ceil($total/$perPage)); $page=min($pages,max(1,(int)($_GET['page']??1)));
$stmt=db()->prepare('SELECT a.*,u.name AS current_name,u.email AS current_email,r.name AS current_role'.$from.$where.' ORDER BY a.created_at DESC,a.id DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));
$stmt->execute($params); $rows=$stmt->fetchAll();
// Resolve only the related accounts/resources actually mentioned by these displayed events.
$ids=['drivers'=>[],'vehicles'=>[],'users'=>[]];
$collect=function(array $values) use (&$ids,&$collect) {
    foreach ($values as $key=>$value) {
        if (is_array($value)) { $collect($value); continue; }
        if (!is_scalar($value) || $value==='') continue;
        $type=str_ends_with((string)$key,'driver_id')?'drivers':(str_ends_with((string)$key,'vehicle_id')?'vehicles':(in_array($key,['actor_id','reviewed_by','cancelled_by','user_id','customer_id'],true)?'users':null));
        if ($type) $ids[$type][(string)$value]=(string)$value;
    }
};
foreach ($rows as $row) foreach (['details','old_values','new_values'] as $field) $collect(audit_ui_json($row[$field]));
$lookups=[];
foreach ($ids as $table=>$values) {
    if (!$values) continue;
    if ($table==='users') $values=array_filter($values,fn($value)=>ctype_digit($value));
    if (!$values) continue;
    $label=$table==='vehicles'?"id || ' · ' || plate_number":'name';
    $q=db()->prepare('SELECT id,'.$label.' AS label FROM '.$table.' WHERE id IN ('.implode(',',array_fill(0,count($values),'?')).')'); $q->execute(array_values($values));
    foreach ($q->fetchAll() as $related) $lookups[$table][(string)$related['id']]=$related['label'];
}
$activities=array_map(fn($row)=>audit_ui_present($row,$lookups),$rows);
$pageUrl=fn($number)=>'?'.http_build_query(array_filter($filters,fn($value)=>$value!=='')+['page'=>$number]);
$page_scripts='<script src="'.BASE_URL.'/js/audit-log.js?v='.filemtime(ROOT_PATH.'/js/audit-log.js').'"></script>';
require ROOT_PATH . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/audit-log.css?v=<?= filemtime(ROOT_PATH.'/css/audit-log.css') ?>">
<section class="audit-shell" aria-labelledby="audit-heading">
 <div class="audit-heading"><div><h1 id="audit-heading">Audit Log</h1><p>System activity and account history. Records are read-only.</p></div><span class="audit-readonly"><i class="bi bi-shield-check" aria-hidden="true"></i> Admin access</span></div>
 <form method="get" class="audit-filters" aria-label="Filter audit activities">
  <div class="audit-search"><label for="audit-search">Search</label><div class="audit-search-input"><i class="bi bi-search" aria-hidden="true"></i><input id="audit-search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search activities…" maxlength="200"></div></div>
  <?php foreach (['role'=>['Role',$roles],'action'=>['Action',$actions],'module'=>['Module',array_keys($modules)]] as $key=>[$label,$options]): ?>
   <div><label for="audit-<?= $key ?>"><?= e($label) ?></label><select id="audit-<?= $key ?>" name="<?= $key ?>"><option value="">All <?= $label==='Role'?'Roles':($label==='Action'?'Actions':'Modules') ?></option><?php foreach ($options as $option): ?><option value="<?= e($option) ?>" <?= $filters[$key]===$option?'selected':'' ?>><?= e($key==='action'?audit_ui_action($option):$option) ?></option><?php endforeach; ?></select></div>
  <?php endforeach; ?>
  <fieldset class="audit-date-range"><legend>Date Range</legend><div><input type="date" aria-label="Start date" name="from" value="<?= e($filters['from']) ?>"><span aria-hidden="true">–</span><input type="date" aria-label="End date" name="to" value="<?= e($filters['to']) ?>"></div></fieldset>
  <div class="audit-filter-actions"><button class="tc-btn tc-btn-primary" type="submit">Apply</button><a class="tc-btn tc-btn-secondary" href="<?= BASE_URL ?>/audit-log.php">Reset</a></div>
 </form>
 <?php if ($filterError): ?><p class="audit-filter-error" role="alert"><?= e($filterError) ?></p><?php endif; ?>
 <div class="audit-card">
  <div class="audit-table-scroll" tabindex="0" role="region" aria-label="Audit activities, scroll horizontally to see all columns">
   <table class="audit-table"><thead><tr><th scope="col">Date &amp; Time <i class="bi bi-arrow-down" title="Newest first"></i></th><th scope="col">User</th><th scope="col">Role</th><th scope="col">Action</th><th scope="col">Module / Record</th><th scope="col">Details</th></tr></thead>
    <tbody><?php foreach ($activities as $index=>$activity): ?>
     <tr><td><div class="audit-date"><i class="bi bi-clock" aria-hidden="true"></i><time datetime="<?= e((new DateTimeImmutable($activity['timestamp']))->format(DateTimeInterface::ATOM)) ?>"><?= e($activity['time']) ?></time></div></td>
      <td><div class="audit-user"><span class="audit-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($activity['name'],0,1))) ?></span><div class="audit-user-text"><span title="<?= e($activity['name']) ?>"><?= e($activity['name']) ?></span><small title="<?= e($activity['email']) ?>"><?= e($activity['email']) ?></small></div></div></td>
      <td><span class="audit-badge audit-<?= $activity['role']==='Unknown'?'muted':($activity['role']==='Driver'?'success':'primary') ?>" title="<?= $activity['roleCurrent']?'Current account role; historical role was not stored.':e($activity['roleFull']) ?>"><?= e($activity['role']) ?></span></td>
      <td><span class="audit-action-label" title="<?= e($activity['action']) ?>"><?= e($activity['action']) ?></span><span class="audit-badge audit-<?= e($activity['category'][1]) ?>"><?= e($activity['category'][0]) ?></span></td>
      <td><span class="audit-module"><?= e($activity['module']) ?></span><small class="audit-record" title="<?= e($activity['record']) ?>"><?= e($activity['record']) ?></small></td>
      <td><button class="audit-details-button" type="button" data-bs-toggle="modal" data-bs-target="#audit-details-modal" data-audit-index="<?= $index ?>" aria-label="View details for <?= e($activity['action'].' by '.$activity['name']) ?>"><i class="bi bi-eye" aria-hidden="true"></i> View Details</button></td>
     </tr>
    <?php endforeach; ?><?php if (!$activities): ?><tr><td colspan="6"><div class="audit-empty"><i class="bi bi-search" aria-hidden="true"></i><strong>No activities found</strong><p>Try a different search or reset your filters.</p></div></td></tr><?php endif; ?></tbody>
   </table>
  </div>
  <div class="audit-pagination"><span>Showing <?= $total ? ($page-1)*$perPage+1 : 0 ?>–<?= min($page*$perPage,$total) ?> of <?= number_format($total) ?> records</span><nav aria-label="Audit log pages">
   <?php if ($page>1): ?><a href="<?= e($pageUrl($page-1)) ?>" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
   <?php $pageNumbers=array_unique(array_merge([1],range(max(1,$page-2),min($pages,$page+2)),[$pages])); $last=0; foreach ($pageNumbers as $number): if ($last && $number>$last+1): ?><span class="audit-page-gap">…</span><?php endif; ?><a href="<?= e($pageUrl($number)) ?>" <?= $number===$page?'aria-current="page"':'' ?> aria-label="Page <?= $number ?>"><?= $number ?></a><?php $last=$number; endforeach; ?>
   <?php if ($page<$pages): ?><a href="<?= e($pageUrl($page+1)) ?>" aria-label="Next page"><i class="bi bi-chevron-right"></i></a><?php endif; ?>
  </nav></div>
 </div>
 <p class="audit-footnote"><i class="bi bi-lock" aria-hidden="true"></i> Audit records are preserved automatically and cannot be edited or deleted.</p>
</section>
<div class="modal fade audit-modal" id="audit-details-modal" tabindex="-1" aria-labelledby="audit-modal-title">
 <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title" id="audit-modal-title">Audit Activity Details</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body" id="audit-modal-body"></div>
  <div class="modal-footer"><span><i class="bi bi-lock" aria-hidden="true"></i> Read-only system record</span><button type="button" class="tc-btn tc-btn-secondary" data-bs-dismiss="modal">Close</button></div>
 </div></div>
</div>
<script type="application/json" id="audit-activities"><?= json_encode($activities,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
