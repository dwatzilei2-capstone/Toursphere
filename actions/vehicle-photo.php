<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_photo.php';
require_login();
if (!can('vehicles.view') && !can('dispatch.view') && !can('reservations.view')) { http_response_code(403); exit; }
$id = is_string($_GET['vehicle'] ?? null) ? $_GET['vehicle'] : '';
$stmt = db()->prepare('SELECT id FROM vehicles WHERE id=?');
$stmt->execute([$id]);
if (!$stmt->fetchColumn()) { http_response_code(404); exit; }
$record = vehicle_photo_records(db(), [$id])[$id] ?? [];
$path = vehicle_photo_actual_path($record['actual_filename'] ?? null);
$mime = $path ? ($record['actual_mime'] ?? '') : '';
if (!$path || !in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
    $path = vehicle_photo_sample_path($record['sample_filename'] ?? null);
    $mime = $path ? 'image/png' : 'image/svg+xml';
    $path ??= ROOT_PATH . '/assets/images/vehicle-placeholder.svg';
}
session_write_close();
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
// Revalidate on every request: a changed photo replaces a previously cached actual image.
header('Cache-Control: private, no-cache');
$etag = '"' . hash('sha256', basename($path).':'.filemtime($path).':'.filesize($path)) . '"';
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Length: ' . filesize($path));
readfile($path);
