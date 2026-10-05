<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/vehicle_photo.php';
$statePath=ROOT_PATH.'/tmp/vehicle-photo-http-fixture.json';
$mode=$argv[1] ?? '';
if ($mode==='create') {
    if (is_file($statePath)) throw new RuntimeException('Clean the previous photo test fixture first.');
    $v=db()->query('SELECT * FROM vehicles WHERE NOT is_archived ORDER BY id LIMIT 1')->fetch();
    $id='PHOTOTEST-'.bin2hex(random_bytes(4));
    $state=['id'=>$id,'name'=>trim($v['brand'].' '.$v['model']),'initial'=>['type'=>$v['type'],'capacity'=>(int)$v['capacity'],'status'=>'Available']];
    file_put_contents($statePath,json_encode($state));
    db()->prepare('INSERT INTO vehicles(id,plate_number,type,brand,model,year,capacity,status) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$id,$id,$v['type'],$v['brand'],$v['model'],$v['year'],$v['capacity'],'Available']);
    $v['id']=$id;
    vehicle_photo_seed_sample(db(),$v,json_decode(file_get_contents(ROOT_PATH.'/assets/images/vehicle-samples/manifest.json'),true));
    echo json_encode($state); exit;
}
$state=json_decode(file_get_contents($statePath),true,512,JSON_THROW_ON_ERROR);
$id=$state['id'];
if (!preg_match('/^PHOTOTEST-[a-f0-9]{8}$/D',$id)) throw new RuntimeException('Invalid fixture identity');
if ($mode==='inspect') {
    $stmt=db()->prepare('SELECT id,type,capacity,status,assigned_driver_id FROM vehicles WHERE id=?'); $stmt->execute([$id]);
    echo json_encode(['vehicle'=>$stmt->fetch(),'photo'=>vehicle_photo_records(db(),[$id])[$id] ?? null]); exit;
}
if ($mode==='clean') {
    $record=vehicle_photo_records(db(),[$id])[$id] ?? [];
    $path=vehicle_photo_actual_path($record['actual_filename'] ?? null);
    db()->beginTransaction();
    db()->prepare("DELETE FROM audit_logs WHERE entity_type='vehicle' AND entity_id=?")->execute([$id]);
    db()->prepare('DELETE FROM vehicle_photos WHERE vehicle_id=?')->execute([$id]);
    db()->prepare('DELETE FROM vehicles WHERE id=?')->execute([$id]);
    db()->commit();
    if ($path) unlink($path);
    unlink($statePath);
    echo "Temporary photo fixture and uploaded test images removed.\n"; exit;
}
throw new RuntimeException('Unknown fixture operation');
