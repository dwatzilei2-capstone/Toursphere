<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/vehicle_photo.php';
$checks=0;
function photo_check(bool $value,string $label): void { global $checks; if (!$value) throw new RuntimeException($label); $checks++; }
function photo_reject(callable $fn,string $label): void { try { $fn(); } catch (InvalidArgumentException $e) { photo_check(true,$label); return; } photo_check(false,$label); }
$rows=db()->query('SELECT * FROM vehicles ORDER BY id')->fetchAll();
$records=vehicle_photo_records(db(),array_column($rows,'id'));
foreach ($rows as $vehicle) {
    $record=$records[$vehicle['id']] ?? [];
    $photo=vehicle_photo_present($vehicle['id'],$record);
    photo_check($photo['vehicleId']===$vehicle['id'],'Vehicle identity retained: '.$vehicle['id']);
    photo_check(in_array($photo['source'],['actual','placeholder'],true),'Photo source is uploaded image or placeholder: '.$vehicle['id']);
    photo_check($photo['src']!=='','Photo URL resolves: '.$vehicle['id']);
    photo_check(!array_key_exists('sampleSrc',$photo),'Built-in passenger illustrations retired: '.$vehicle['id']);
    photo_check($photo['source']===$photo['hasActual']?'actual':'placeholder','Stored upload decides the source: '.$vehicle['id']);
}
photo_check(vehicle_photo_validate_upload([],true)===null,'Optional photo omission supported');
foreach ([UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE,UPLOAD_ERR_PARTIAL,UPLOAD_ERR_NO_FILE,UPLOAD_ERR_NO_TMP_DIR,UPLOAD_ERR_CANT_WRITE,UPLOAD_ERR_EXTENSION] as $error) photo_reject(fn()=>vehicle_photo_validate_upload(['error'=>$error]),'Upload error rejected');
$temp=ROOT_PATH.'/tmp/vehicle-photo-test-'.bin2hex(random_bytes(6)).'.png';
$actualName=bin2hex(random_bytes(24)).'.png';
if (!is_dir(vehicle_photo_storage())) mkdir(vehicle_photo_storage(),0750,true);
$actualPath=vehicle_photo_storage().'/'.$actualName;
try {
    // 1x1 raster recorded as the valid-image reference for content checks.
    file_put_contents($temp,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='));
    photo_check(vehicle_photo_validate_file($temp,'upload.png')['mime']==='image/png','Valid raster accepted');
    photo_reject(fn()=>vehicle_photo_validate_upload(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$temp,'name'=>'upload.png']),'Non-uploaded local file rejected');
    photo_reject(fn()=>vehicle_photo_validate_file($temp,'upload.php'),'Executable extension rejected');
    photo_reject(fn()=>vehicle_photo_validate_file($temp,'upload.jpg'),'Content/extension mismatch rejected');
    $fake=ROOT_PATH.'/tmp/vehicle-photo-fake-'.bin2hex(random_bytes(6)).'.png';
    file_put_contents($fake,'<?php echo "not an image";');
    photo_reject(fn()=>vehicle_photo_validate_file($fake,'fake.png'),'Fake image rejected');
    unlink($fake);
    $large=ROOT_PATH.'/tmp/vehicle-photo-large-'.bin2hex(random_bytes(6)).'.png';
    file_put_contents($large,str_repeat('x',5*1024*1024+1));
    photo_reject(fn()=>vehicle_photo_validate_file($large,'large.png'),'Oversize image rejected');
    unlink($large);

    photo_check(vehicle_photo_present('TEST',[])['src']===vehicle_photo_placeholder(),'Placeholder used when no image is uploaded');
    photo_check(vehicle_photo_present('TEST',[])['source']==='placeholder','Placeholder source labelled cleanly');
    copy($temp,$actualPath);
    photo_check(vehicle_photo_present('TEST',['actual_filename'=>$actualName])['source']==='actual','Uploaded image is preferred');
    unlink($actualPath);
    photo_check(vehicle_photo_present('TEST',['actual_filename'=>$actualName])['source']==='placeholder','Missing upload falls back to the placeholder');
    photo_check(vehicle_photo_actual_path('../vehicle-documents/private.png')===null,'Actual traversal rejected');
    photo_check(vehicle_photo_actual_path('sample.png')===null,'Unstructured filename rejected');

    $pdo=db();
    $pdo->exec('CREATE TEMP TABLE vehicle_photos (LIKE public.vehicle_photos INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES)');
    $pdo->exec('CREATE TEMP TABLE audit_logs (LIKE public.audit_logs INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');
    $pdo->exec('CREATE TEMP SEQUENCE photo_test_audit; ALTER TABLE audit_logs ALTER COLUMN id SET DEFAULT nextval(\'photo_test_audit\')');
    $v=$rows[0]; $v['id']='PHOTO-TEST';
    copy($temp,$actualPath);
    $file=vehicle_photo_validate_file($temp,'upload.png')+['filename'=>$actualName];
    vehicle_photo_save_actual($pdo,$v['id'],$file,'1');
    $saved=vehicle_photo_records($pdo,[$v['id']])[$v['id']];
    photo_check($saved['actual_filename']===$actualName,'Uploaded filename stored');
    photo_check(vehicle_photo_present($v['id'],$saved)['source']==='actual','Stored upload resolves as actual');
    $file['filename']=bin2hex(random_bytes(24)).'.png';
    vehicle_photo_save_actual($pdo,$v['id'],$file,'1');
    photo_check((int)$pdo->query('SELECT COUNT(*) FROM vehicle_photos')->fetchColumn()===1,'One primary record per vehicle after replacement');
    photo_check((int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()===2,'Actual updates audited');
} finally {
    if (is_file($temp)) unlink($temp);
    if (is_file($actualPath)) unlink($actualPath);
}
echo "$checks vehicle photo security, placeholder, and storage checks passed.\n";
