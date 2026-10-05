<?php
function reports_export_menu(array $params): void
{
    ?>
    <div class="dropdown report-export"><button type="button" class="tc-btn tc-btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download" aria-hidden="true"></i> Export</button>
    <ul class="dropdown-menu dropdown-menu-end">
    <?php foreach(['xlsx'=>['file-earmark-excel','Excel (.xlsx)'],'pdf'=>['file-earmark-pdf','PDF (.pdf)'],'csv'=>['filetype-csv','CSV (.csv)']] as $format=>$option): ?>
    <li><a class="dropdown-item report-download" href="<?= e(BASE_URL.'/actions/export.php?'.http_build_query($params+['format'=>$format])) ?>"><i class="bi bi-<?= $option[0] ?> me-2" aria-hidden="true"></i><?= $option[1] ?></a></li>
    <?php endforeach; ?></ul></div>
    <?php
}
function reports_ui_assets(): void
{
    global $page_scripts;
    $page_scripts='<script src="'.BASE_URL.'/js/reports.js?v='.filemtime(ROOT_PATH.'/js/reports.js').'"></script>';
}
