<?php

/** Keep production diagnostics in server logs, never in HTTP responses. */
function toursphere_configure_runtime_errors(string $environment, string $sapi = PHP_SAPI): void
{
    if ($sapi === 'cli') return;
    if (!in_array(strtolower(trim($environment)), ['development', 'testing'], true)) {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
    }
}
