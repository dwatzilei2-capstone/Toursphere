<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/vehicle_photo.php';
$checks=0;
function photo_check(bool $value,string $label): void { global $checks; if (!$value) throw new RuntimeException($label); $checks++; }
function photo_reject(callable $fn,string $label): void { try { $fn(); } catch (InvalidArgumentException $e) { photo_check(true,$label); return; } photo_check(false,$label); }
$catalog=json_decode(file_get_contents(ROOT_PATH.'/assets/images/vehicle-samples/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$rows=db()->query('SELECT * FROM vehicles ORDER BY id')->fetchAll();
$records=vehicle_photo_records(db(),array_column($rows,'id'));
foreach ($rows as $vehicle) {
    $record=$records[$vehicle['id']] ?? [];
    photo_check(!empty($record['sample_filename']),'Sample association: '.$vehicle['id']);
    photo_check($record['sample_filename']===vehicle_photo_sample_key($vehicle).'.png','Structured model match: '.$vehicle['id']);
    $path=vehicle_photo_sample_path($record['sample_filename']);
    photo_check($path!==null,'Sample file exists: '.$vehicle['id']);
    photo_check(vehicle_photo_validate_file($path,'sample.png')['mime']==='image/png','Valid sample image: '.$vehicle['id']);
    photo_check(vehicle_photo_present($vehicle['id'],$record)['vehicleId']===$vehicle['id'],'Vehicle identity retained');
}
photo_check(vehicle_photo_validate_upload([],true)===null,'Optional photo omission supported');
foreach ([UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE,UPLOAD_ERR_PARTIAL,UPLOAD_ERR_NO_FILE,UPLOAD_ERR_NO_TMP_DIR,UPLOAD_ERR_CANT_WRITE,UPLOAD_ERR_EXTENSION] as $error) photo_reject(fn()=>vehicle_photo_validate_upload(['error'=>$error]),'Upload error rejected');
$sample=vehicle_photo_sample_path($catalog[0]['filename']);
photo_reject(fn()=>vehicle_photo_validate_upload(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$sample,'name'=>'sample.png']),'Non-uploaded local file rejected');
photo_reject(fn()=>vehicle_photo_validate_file($sample,'sample.php'),'Executable extension rejected');
photo_reject(fn()=>vehicle_photo_validate_file($sample,'sample.jpg'),'Content/extension mismatch rejected');
$temp=ROOT_PATH.'/tmp/vehicle-photo-test-'.bin2hex(random_bytes(6));
$actualName=bin2hex(random_bytes(24)).'.png';
if (!is_dir(vehicle_photo_storage())) mkdir(vehicle_photo_storage(),0750,true);
$actualPath=vehicle_photo_storage().'/'.$actualName;
try {
    file_put_contents($temp,'<?php echo "not an image";');
    photo_reject(fn()=>vehicle_photo_validate_file($temp,'fake.png'),'Fake image rejected');
    file_put_contents($temp,str_repeat('x',5*1024*1024+1));
    photo_reject(fn()=>vehicle_photo_validate_file($temp,'large.png'),'Oversize image rejected');
    $record=['sample_filename'=>$catalog[0]['filename'],'actual_filename'=>$actualName];
    copy($sample,$actualPath);
    photo_check(vehicle_photo_present('TEST',$record)['source']==='actual','Actual takes priority');
    photo_check(vehicle_photo_present('TEST',$record)['sampleSrc']!==null,'Sample retained behind actual');
    unlink($actualPath);
    photo_check(vehicle_photo_present('TEST',$record)['source']==='sample','Missing actual falls back to sample');
    $record['sample_filename']=str_repeat('f',20).'.png';
    photo_check(vehicle_photo_present('TEST',$record)['source']==='placeholder','Missing sample falls back to placeholder');
    photo_check(vehicle_photo_actual_path('../vehicle-documents/private.png')===null,'Actual traversal rejected');
    photo_check(vehicle_photo_sample_path('../vehicle-placeholder.svg')===null,'Sample traversal rejected');
    $pdo=db();
    $pdo->exec('CREATE TEMP TABLE vehicle_photos (LIKE public.vehicle_photos INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES)');
    $pdo->exec('CREATE TEMP TABLE audit_logs (LIKE public.audit_logs INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');
    $pdo->exec('CREATE TEMP SEQUENCE photo_test_audit; ALTER TABLE audit_logs ALTER COLUMN id SET DEFAULT nextval(\'photo_test_audit\')');
    $v=$rows[0]; $v['id']='PHOTO-TEST';
    photo_check(vehicle_photo_seed_sample($pdo,$v,$catalog),'Sample seeding from metadata');
    $file=vehicle_photo_validate_file($sample,'sample.png')+['filename'=>$actualName];
    vehicle_photo_save_actual($pdo,$v['id'],$file,'1');
    $saved=vehicle_photo_records($pdo,[$v['id']])[$v['id']];
    photo_check($saved['actual_filename']===$actualName && $saved['sample_filename']===$catalog[0]['filename'],'Actual replacement preserves sample');
    vehicle_photo_seed_sample($pdo,$v,$catalog);
    photo_check(vehicle_photo_records($pdo,[$v['id']])[$v['id']]['actual_filename']===$actualName,'Reseeding preserves uploaded actual');
    $file['filename']=bin2hex(random_bytes(24)).'.png';
    vehicle_photo_save_actual($pdo,$v['id'],$file,'1');
    photo_check((int)$pdo->query('SELECT COUNT(*) FROM vehicle_photos')->fetchColumn()===1,'One primary record per vehicle after replacement');
    photo_check((int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()===2,'Actual updates audited');
} finally {
    if (is_file($temp)) unlink($temp);
    if (is_file($actualPath)) unlink($actualPath);
}
echo "$checks vehicle photo security, priority, association, and storage checks passed.\n";
