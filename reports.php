<?php
ini_set('display_errors','0');
require_once __DIR__.'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/reports.php';
require_once ROOT_PATH.'/includes/reports-ui.php';
reports_require();
$active_page='reports'; $page_title='Fleet Operational Reports'; $error=null;
try { $range=reports_period($_GET); } catch(DomainException $e) { $error=$e->getMessage(); $range=reports_period([]); http_response_code(422); }
$params=array_intersect_key($range,array_flip(['period','from','to']));
reports_ui_assets(); require ROOT_PATH.'/includes/header.php';
?>
<div class="report-page">
<div class="report-intro"><h1>Fleet Operational Reports</h1><p class="text-muted-custom mb-0">Create operational reports from current TourSphere records. Preview the details, then download a formatted document or spreadsheet for your selected period.</p></div>
<section class="tc-card report-period"><h2>Report Period</h2>
<?php if($error): ?><p class="text-danger" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="get" class="report-period-form"><div class="report-period-choice"><label for="report-period">Reporting period</label><select id="report-period" name="period"><?php foreach(reports_periods() as $key=>$label): ?><option value="<?= $key ?>" <?= $range['period']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
<div id="report-custom" class="report-custom" <?= $range['period']!=='custom'?'hidden':'' ?>><div><label for="report-from">From</label><input id="report-from" type="date" name="from" min="2000-01-01" max="2100-12-31" value="<?= e($range['from']) ?>"></div><div><label for="report-to">To</label><input id="report-to" type="date" name="to" min="2000-01-01" max="2100-12-31" value="<?= e($range['to']) ?>"></div></div>
<button class="tc-btn tc-btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Apply Period</button></form>
<p class="report-period-caption"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= e($range['label']) ?> · <?= e(company_timezone()) ?></p></section>
<div class="report-catalog"><?php foreach(reports_allowed() as $key=>$report): ?><article class="tc-card report-card"><div class="report-card-icon"><i class="bi bi-<?= e($report['icon']) ?>" aria-hidden="true"></i></div><h2><?= e($report['title']) ?></h2><p><?= e($report['description']) ?></p><div class="report-actions"><a class="tc-btn tc-btn-primary report-generate" href="<?= e(BASE_URL.'/report-preview.php?'.http_build_query(['report'=>$key]+$params)) ?>"><i class="bi bi-eye" aria-hidden="true"></i> Preview Report</a><?php reports_export_menu(['report'=>$key]+$params); ?></div></article><?php endforeach; ?></div>
<div id="report-status" class="report-status" role="status" aria-live="polite" hidden></div></div>
<?php require ROOT_PATH.'/includes/footer.php'; ?>
