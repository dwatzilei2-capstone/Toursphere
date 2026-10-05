<?php
ini_set('display_errors','0');
require_once __DIR__.'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/reports.php';
require_once ROOT_PATH.'/includes/reports-ui.php';
reports_require();
$active_page='reports'; $page_title='Report Preview'; $error=null; $m=null;
try {
    if(isset($_GET['snapshot'])) $m=reports_read((string)$_GET['snapshot']);
    else {
        $type=(string)($_GET['report']??''); reports_require($type);
        $m=reports_get($type,$_GET,isset($_GET['refresh']));
        reports_log($m,'Report preview generated');
        header('Location: '.BASE_URL.'/report-preview.php?'.http_build_query(['snapshot'=>$m['token']])); exit;
    }
    $section=(string)($_GET['section']??array_key_first($m['sections']));
    if(!isset($m['sections'][$section])) throw new DomainException('Select a valid report section.');
    $s=$m['sections'][$section]; $pages=max(1,(int)ceil($s['count']/20)); $page=min($pages,max(1,(int)($_GET['page']??1)));
    $rows=iterator_to_array(reports_rows($m,$section,$page));
} catch(DomainException $e) { $error=$e->getMessage(); http_response_code(422); }
catch(Throwable $e) { error_log('Reports preview: '.$e); $error='Unable to generate the report. Please try again.'; http_response_code(500); }
header('Cache-Control: private, no-store');
reports_ui_assets(); require ROOT_PATH.'/includes/header.php';
?>
<div class="report-page">
<a class="report-back" href="<?= BASE_URL ?>/reports.php<?= $m?'?'.e(http_build_query(array_intersect_key($m['range'],array_flip(['period','from','to'])))):'' ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Reports</a>
<?php if($error): ?><div class="tc-card p-4 report-empty" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><h1>Report unavailable</h1><p><?= e($error) ?></p><a class="tc-btn tc-btn-primary" href="<?= BASE_URL ?>/reports.php">Generate a report</a></div>
<?php else: ?>
<section class="tc-card report-document">
 <div class="report-heading"><div><span class="report-brand"><?= e($m['company']) ?></span><p class="text-muted-custom small">Fleet &amp; Transportation Management</p><h1><?= e($m['title']) ?></h1><p class="report-range"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= e($m['range']['label']) ?></p></div>
 <div class="report-actions"><?php reports_export_menu(['report'=>$m['type'],'snapshot'=>$m['token']]); ?><a class="tc-btn tc-btn-secondary report-generate" href="<?= e(BASE_URL.'/report-preview.php?'.http_build_query(['report'=>$m['type'],'period'=>$m['range']['period'],'from'=>$m['range']['from'],'to'=>$m['range']['to'],'refresh'=>1])) ?>"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh</a></div></div>
 <p class="report-metadata">Generated <?= e(reports_value($m['generated_at'],'datetime').' · '.$m['timezone']) ?><br>By <?= e($m['generated_by'].' · '.$m['generated_role']) ?></p>
 <h2>Operational Summary</h2><div class="report-metrics"><?php foreach($m['metrics'] as $metric): ?><div class="report-metric"><span><?= e($metric['label']) ?></span><strong><?= e(reports_value($metric['value'],$metric['type'])) ?></strong></div><?php endforeach; ?></div>
 <details class="report-notes"><summary>Reporting notes &amp; definitions</summary><?php foreach($m['notes'] as $note): ?><p><?= e($note) ?></p><?php endforeach; ?><p>This snapshot is available for 30 minutes. Refresh to include subsequent changes.</p></details>
</section>
<section class="tc-card report-records">
 <nav class="report-tabs" aria-label="Report sections"><?php foreach($m['sections'] as $key=>$sec): ?><a <?= $key===$section?'aria-current="page"':'' ?> href="<?= e('?'.http_build_query(['snapshot'=>$m['token'],'section'=>$key])) ?>"><?= e($sec['title']) ?> <span><?= number_format($sec['count']) ?></span></a><?php endforeach; ?></nav>
 <div class="report-detail-heading"><h2><?= e($s['title']) ?></h2><span class="text-muted-custom small"><?= number_format($s['count']) ?> records</span></div>
 <?php if(!$rows): ?><div class="report-empty"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><p>No report data is available for the selected period.</p></div>
 <?php else: ?>
 <div class="report-table-wrap" tabindex="0" aria-label="Detailed report records; scroll horizontally for all columns"><table class="report-table"><thead><tr><?php foreach($s['columns'] as $col): ?><th scope="col"><?= e($col['label']) ?></th><?php endforeach; ?></tr></thead><tbody>
 <?php foreach($rows as $row): ?><tr><?php foreach($s['columns'] as $field=>$col): ?><td class="<?= $col['type']==='id'?'report-id':(in_array($col['type'],['integer','decimal','money','percent'],true)?'report-number':'') ?>"><?= e(reports_value($row[$field]??null,$col['type'])) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
 <div class="report-mobile-records"><?php foreach($rows as $row): ?><details class="report-mobile-record"><summary><strong class="report-id"><?= e(reports_value($row[array_key_first($s['columns'])]??null,'id')) ?></strong><span><?= e((string)($row['status']??$row['driver']??$row['plate']??$row['category']??'')) ?></span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary><dl><?php foreach($s['columns'] as $field=>$col): ?><div><dt><?= e($col['label']) ?></dt><dd class="<?= $col['type']==='id'?'report-id':'' ?>"><?= e(reports_value($row[$field]??null,$col['type'])) ?></dd></div><?php endforeach; ?></dl></details><?php endforeach; ?></div>
 <?php endif; ?>
 <?php if($s['totals']): ?><div class="report-totals"><strong>Grand Total · complete dataset</strong><?php foreach($s['totals'] as $field=>$value): ?><span><?= e($s['columns'][$field]['label']) ?> <b><?= e(reports_value($value,$s['columns'][$field]['type'])) ?></b></span><?php endforeach; ?></div><?php endif; ?>
 <?php if($s['count']): ?><nav class="report-pagination" aria-label="Record pages"><span>Page <?= $page ?> of <?= $pages ?> · <?= min($s['count'],($page-1)*20+1) ?>–<?= min($s['count'],$page*20) ?> of <?= number_format($s['count']) ?></span><div><?php if($page>1): ?><a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= e('?'.http_build_query(['snapshot'=>$m['token'],'section'=>$section,'page'=>$page-1])) ?>">Previous</a><?php endif; ?><?php if($page<$pages): ?><a class="tc-btn tc-btn-secondary tc-btn-sm" href="<?= e('?'.http_build_query(['snapshot'=>$m['token'],'section'=>$section,'page'=>$page+1])) ?>">Next</a><?php endif; ?></div></nav><?php endif; ?>
</section>
<?php endif; ?>
<div id="report-status" class="report-status" role="status" aria-live="polite" hidden></div>
</div>
<?php require ROOT_PATH.'/includes/footer.php'; ?>
