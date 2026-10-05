<?php
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/bootstrap.php';
try {
    db()->exec(file_get_contents(ROOT_PATH.'/database/migrations/2026_10_05_archive_management.sql'));
    echo "Archive migration applied. No operational records were archived or retired.\n";
} catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); error_log('Archive migration: '.$e->getMessage()); exit(1); }
