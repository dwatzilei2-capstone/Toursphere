<?php
const FUEL_RECEIPT_MAX_BYTES = 5 * 1024 * 1024;
function fuel_receipt_path(?string $filename): ?string {
    if (!$filename || !preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/D', $filename)) return null;
    $path = ROOT_PATH.'/storage/private/fuel-receipts/'.$filename;
    return is_file($path) ? $path : null;
}
function fuel_receipt_upload(?array $file): ?array {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (!is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Receipt upload failed. Choose a JPG, PNG or PDF up to 5 MB.');
    if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Invalid receipt upload.');
    $size = filesize($file['tmp_name']);
    if (!$size || $size > FUEL_RECEIPT_MAX_BYTES) throw new RuntimeException('Receipt must be no larger than 5 MB.');
    $name = basename(str_replace('\\','/', (string)($file['name'] ?? 'receipt')));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','pdf'=>'application/pdf'];
    if (!isset($types[$extension]) || $types[$extension] !== $mime) throw new RuntimeException('Receipt must be a valid JPG, PNG or PDF file.');
    if ($mime !== 'application/pdf') {
        $dimensions = @getimagesize($file['tmp_name']);
        if (!$dimensions || $dimensions[0] > 12000 || $dimensions[1] > 12000 || $dimensions[0]*$dimensions[1] > 40000000) throw new RuntimeException('Receipt image is invalid or too large.');
    } else {
        $handle = fopen($file['tmp_name'],'rb');$signature = fread($handle,5);fclose($handle);
        if ($signature !== '%PDF-') throw new RuntimeException('Invalid PDF receipt.');
    }
    return ['tmp_name'=>$file['tmp_name'],'filename'=>bin2hex(random_bytes(24)).'.'.($mime==='image/jpeg'?'jpg':$extension),'name'=>mb_substr(preg_replace('/[\x00-\x1F\x7F]/u','',$name),0,255),'mime'=>$mime,'size'=>$size];
}
function fuel_receipt_store(array $upload): string {
    $directory = ROOT_PATH.'/storage/private/fuel-receipts';
    if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('Could not save the receipt.');
    $path = $directory.'/'.$upload['filename'];
    if (!move_uploaded_file($upload['tmp_name'],$path)) throw new RuntimeException('Could not save the receipt.');
    return $path;
}
