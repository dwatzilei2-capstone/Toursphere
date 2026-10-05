<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/runtime-errors.php';
$original = ['display_errors'=>ini_get('display_errors'), 'display_startup_errors'=>ini_get('display_startup_errors'), 'log_errors'=>ini_get('log_errors')];
$level = error_reporting();
try {
    foreach (['production', '', 'unknown'] as $mode) {
        ini_set('display_errors','1');
        toursphere_configure_runtime_errors($mode, 'fpm-fcgi');
        if (ini_get('display_errors') !== '0' || ini_get('log_errors') !== '1' || error_reporting() !== E_ALL) throw new RuntimeException('Unsafe production error handling.');
    }
    foreach (['development','testing'] as $mode) {
        ini_set('display_errors','1');
        toursphere_configure_runtime_errors($mode, 'apache2handler');
        if (ini_get('display_errors') !== '1') throw new RuntimeException('Local diagnostics changed.');
    }
    ini_set('display_errors','1');
    toursphere_configure_runtime_errors('production','cli');
    if (ini_get('display_errors') !== '1') throw new RuntimeException('CLI diagnostics changed.');
    echo "PASS: 6 runtime error configuration checks.\n";
} finally {
    foreach ($original as $key=>$value) ini_set($key,$value);
    error_reporting($level);
}
