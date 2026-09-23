<?php
 

function db_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

define('DB_HOST', db_env('DB_HOST', '127.0.0.1'));
define('DB_PORT', db_env('DB_PORT', '5432'));
define('DB_NAME', db_env('DB_NAME', 'fleetcore'));
define('DB_USER', db_env('DB_USER', 'postgres'));
define('DB_PASS', db_env('DB_PASS', ''));
define('DB_SSLMODE', db_env('DB_SSLMODE', 'prefer'));

if (!defined('BASE_URL')) {
    $__script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (preg_match('#(.*)/(modules/[^/]+|actions)$#', $__script_dir, $__m)) {
        $__base_dir = $__m[1];
    } else {
        $__base_dir = $__script_dir;
    }
    define('BASE_URL', rtrim($__base_dir, '/') ?: '');
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

 
$pdo = null;

function db(): PDO
{
    global $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', DB_HOST, DB_PORT, DB_NAME, DB_SSLMODE);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET TIME ZONE 'Asia/Manila'");
    } catch (PDOException $e) {
        http_response_code(500);
        $message = PHP_SAPI === 'cli' && db_env('APP_DEBUG', 'false') === 'true'
            ? ': ' . htmlspecialchars($e->getMessage())
            : '. The service is temporarily unavailable. Please try again later.';
        die('Database connection failed' . $message);
    }
    return $pdo;
}
