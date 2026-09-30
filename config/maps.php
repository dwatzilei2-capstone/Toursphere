<?php
 
























 
$localConfig = __DIR__ . '/maps.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

 
if (!defined('GOOGLE_MAPS_API_KEY')) {
    $envKey = getenv('GOOGLE_MAPS_API_KEY') ?: ($_ENV['GOOGLE_MAPS_API_KEY'] ?? ($_SERVER['GOOGLE_MAPS_API_KEY'] ?? ''));
    define('GOOGLE_MAPS_API_KEY', !empty($envKey) ? $envKey : 'YOUR_GOOGLE_MAPS_API_KEY');
}

// Keep server-side web-service credentials separate from the browser key.
// In production, restrict this key to the web server's public IP and only the
// routing API used by the customer fare calculator.
if (!defined('GOOGLE_MAPS_SERVER_API_KEY')) {
    $envServerKey = getenv('GOOGLE_MAPS_SERVER_API_KEY') ?: ($_ENV['GOOGLE_MAPS_SERVER_API_KEY'] ?? ($_SERVER['GOOGLE_MAPS_SERVER_API_KEY'] ?? ''));
    define('GOOGLE_MAPS_SERVER_API_KEY', !empty($envServerKey) ? $envServerKey : '');
}

 
if (!defined('AI_API_KEY')) {
    $envAiKey = getenv('AI_API_KEY') ?: ($_ENV['AI_API_KEY'] ?? ($_SERVER['AI_API_KEY'] ?? ''));
    define('AI_API_KEY', !empty($envAiKey) ? $envAiKey : 'YOUR_SERVER_SIDE_AI_KEY');
}
