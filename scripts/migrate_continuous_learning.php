<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/routethink_engine.php';
db()->exec(file_get_contents(dirname(__DIR__) . '/database/migrations/2026_10_01_continuous_learning.sql'));
echo 'New learned outcomes: ', ai_learning_sync(db()), PHP_EOL;
