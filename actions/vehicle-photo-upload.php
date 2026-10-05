<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/vehicle_photo.php';
require_login();
require_permission('vehicles.manage');
$directory = BASE_URL . '/modules/fleet-vehicle-management/vehicle-directory.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect_to($directory); }
$id = is_string($_POST['vehicle_id'] ?? null) ? $_POST['vehicle_id'] : '';
$return = $directory . '?vehicle=' . rawurlencode($id);
$stored = null;
try {
    if (empty($_SESSION['vehicle_csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['vehicle_csrf_token'], $_POST['csrf_token'])) throw new RuntimeException('Your photo form expired. Reopen Vehicle Details and try again.');
    $upload = $_FILES['vehicle_photo'] ?? [];
    $validated = vehicle_photo_validate_upload($upload);
    $pdo = db();
    $pdo->beginTransaction();
    // Serializes concurrent replacements and retirement/deletion against the same vehicle.
    $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id=? AND NOT is_archived AND status<>'Retired' FOR UPDATE");
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) throw new InvalidArgumentException('This vehicle is no longer available for photo updates.');
    $old = vehicle_photo_records($pdo, [$id])[$id]['actual_filename'] ?? null;
    $stored = vehicle_photo_store_upload($upload, $validated);
    vehicle_photo_save_actual($pdo, $id, $stored, (string)$current_user['id']);
    $pdo->commit();
    $stored = null;
    $oldPath = vehicle_photo_actual_path($old);
    if ($oldPath) @unlink($oldPath);
    redirect_with_toast($return, 'Vehicle Photo updated throughout TourSphere.', 'success');
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($stored && is_file($stored['path'])) @unlink($stored['path']);
    error_log('Vehicle photo update: ' . $error->getMessage());
    $message = $error instanceof InvalidArgumentException || $error instanceof RuntimeException && !$error instanceof PDOException ? $error->getMessage() : 'Vehicle Photo could not be saved. Try again.';
    redirect_with_toast($return, $message, 'danger');
}
