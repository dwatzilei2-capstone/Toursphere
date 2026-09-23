<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (!has_role('driver')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Profile photo upload is currently available only to driver accounts.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST method required.']);
    exit;
}

$upload = $_FILES['avatar'] ?? null;
if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please select a valid profile image.']);
    exit;
}

if (($upload['size'] ?? 0) < 1 || $upload['size'] > 5 * 1024 * 1024) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Profile image must be 5 MB or smaller.']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($upload['tmp_name']);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
if (!isset($allowed[$mime])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Only JPG, PNG, and WebP images are allowed.']);
    exit;
}

try {
    $uploadDir = ROOT_PATH . '/uploads/avatars';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create the profile image directory.');
    }

    $filename = 'driver-' . (int)$current_user['id'] . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $destination = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
        throw new RuntimeException('Unable to save the uploaded profile image.');
    }

    $avatarPath = BASE_URL . '/uploads/avatars/' . $filename;
    $stmt = db()->prepare('UPDATE users SET avatar = ? WHERE id = ?');
    $stmt->execute([$avatarPath, $current_user['id']]);

    echo json_encode([
        'ok' => true,
        'avatar' => $avatarPath,
        'message' => 'Your profile photo has been updated.',
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to update the profile photo.']);
}
