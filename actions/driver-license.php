<?php
require dirname(__DIR__).'/includes/bootstrap.php';
require ROOT_PATH.'/includes/driver_license.php';
require_login();require_permission('drivers.manage');
$analyze=($_GET['action']??'')==='analyze';$return=BASE_URL.'/modules/driver-trip-performance/driver-performance.php';$stored=null;
try {
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new DomainException('POST required.');
 if(!is_string($_POST['csrf_token']??null)||!hash_equals($_SESSION['driver_license_csrf']??'',$_POST['csrf_token']))throw new DomainException('Form session expired. Reload the driver page.');
 $upload=$_FILES['license_document']??[];$file=validate_vehicle_document_upload($upload);
 $extracted=driver_license_extract($upload['tmp_name'],$file['mime']);
 if($analyze){header('Content-Type: application/json');echo json_encode(['ok'=>true,'data'=>$extracted,'message'=>'Extracted suggestions only. Review the original license, including the reverse side, before verifying.']);exit;}
 if(!empty($extracted['test_fixture']))throw new DomainException('A synthetic or TEST ONLY license cannot verify a driver qualification. Upload the actual license.');
 $name=trim((string)($_POST['name']??''));$no=strtoupper(trim((string)($_POST['license_no']??'')));$expiry=(string)($_POST['license_expiration']??'');$type=(string)($_POST['license_type']??'');$codes=driver_dl_codes($_POST['authorized_dl_codes']??[]);$notes=trim((string)($_POST['verification_notes']??''));
 $date=DateTimeImmutable::createFromFormat('!Y-m-d',$expiry);
 if($name===''||strlen($name)>120||$no===''||strlen($no)>40)throw new DomainException('Enter a valid driver name and license number.');
 if(!$date||$date->format('Y-m-d')!==$expiry||$expiry<date('Y-m-d'))throw new DomainException('A valid, unexpired license is required.');
 if($type!=='Professional'||!in_array('C',$codes,true))throw new DomainException('Active logistics drivers require a Professional license with DL Code C. Do not select a code that is absent from the license.');
 if(($_POST['manual_verified']??'')!=='1'||strlen($notes)<10||strlen($notes)>2000)throw new DomainException('Confirm your review of the original license and record verification notes (10-2000 characters).');
 foreach(['license_no'=>$no,'license_type'=>$type,'license_expiration'=>$expiry] as $k=>$value)if(!empty($extracted[$k])&&$extracted[$k]!==$value)throw new DomainException('The entered '.$k.' conflicts with the uploaded license. Upload a clearer copy for manual review.');
 if(!empty($extracted['authorized_dl_codes'])&&array_diff($codes,$extracted['authorized_dl_codes']))throw new DomainException('The selected DL codes conflict with the extracted license. Upload a clear image of both sides for manual review if extraction is incomplete.');
 $id=trim((string)($_POST['driver_id']??''));if($id==='')throw new DomainException('Select an existing driver profile to review. Driver creation is not supported.');$p=db();$p->beginTransaction();$p->exec("SELECT pg_advisory_xact_lock(hashtext('driver-license-registration'))");
 if($id!==''){$q=$p->prepare('SELECT id FROM drivers WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetchColumn())throw new DomainException('Driver not found.');$q=$p->prepare("SELECT 1 FROM trips WHERE driver_id=? AND status IN ('Dispatched','In Transit','Returning to Depot')");$q->execute([$id]);if($q->fetchColumn())throw new DomainException('Complete the active trip before replacing license credentials.');}
 $q=$p->prepare('SELECT id FROM drivers WHERE upper(license_no)=? AND id<>?');$q->execute([$no,$id]);if($q->fetchColumn())throw new DomainException('This license number belongs to another driver profile.');
 $dir=ROOT_PATH.'/storage/private/driver-licenses';if(!is_dir($dir)&&!mkdir($dir,0750,true))throw new RuntimeException('Secure license storage unavailable.');
 $filename=bin2hex(random_bytes(24)).'.'.$file['extension'];$stored=$dir.'/'.$filename;if(!move_uploaded_file($upload['tmp_name'],$stored))throw new RuntimeException('License upload could not be stored.');
 $params=[$name,$no,$expiry,$type,json_encode($codes),$filename,$file['mime'],json_encode($extracted),$notes,$current_user['id']];
 $q=$p->prepare("UPDATE drivers SET name=?,license_no=?,license_expiration=?,license_type=?,authorized_dl_codes=?::jsonb,license_document_filename=?,license_document_mime=?,license_extracted_data=?::jsonb,license_verification_notes=?,license_verified_by=?,license_verified_at=CURRENT_TIMESTAMP,license_verification_status='Verified',license_class=?,status='Active' WHERE id=?");
 $params[]='Professional (DL '.implode(', ',$codes).')';$params[]=$id;$q->execute($params);
 $q=$p->prepare('SELECT * FROM drivers WHERE id=?');$q->execute([$id]);$saved=$q->fetch();
 $q=$p->prepare("SELECT t.*,v.* FROM trips t JOIN vehicles v ON v.id=t.vehicle_id WHERE t.driver_id=? AND t.status IN ('Scheduled','Assigned','Confirmed','Dispatched','In Transit','Returning to Depot')");$q->execute([$id]);
 foreach($q->fetchAll() as $v)if(driver_license_eligibility($v,$saved,substr($v['scheduled_departure']??date('Y-m-d'),0,10))!=='')throw new DomainException('Reassign or cancel open trips requiring qualifications absent from this license before saving.');
 $q=$p->prepare('SELECT * FROM vehicles WHERE assigned_driver_id=? FOR UPDATE');$q->execute([$id]);
 foreach($q->fetchAll() as $v)if(driver_license_eligibility($v,$saved)!=='')$p->prepare("UPDATE vehicles SET assigned_driver_id=NULL,status=CASE WHEN status='Assigned' THEN 'Available' ELSE status END WHERE id=?")->execute([$v['id']]);
 $p->prepare("UPDATE drivers SET status=CASE WHEN EXISTS(SELECT 1 FROM vehicles WHERE assigned_driver_id=?) THEN 'Assigned' ELSE 'Active' END WHERE id=?")->execute([$id,$id]);

 $p->prepare("INSERT INTO audit_logs(action,user_id,entity_type,entity_id,details) VALUES ('DRIVER_LICENSE_MANUALLY_VERIFIED',?,'driver',?,?)")->execute([$current_user['id'],$id,json_encode(['authorized_dl_codes'=>$codes,'source'=>'Original document manually reviewed','expiry'=>$expiry])]);
 $p->commit();redirect_with_toast($return,'Driver license verified and saved.','success');
}catch(Throwable $e){if(isset($p)&&$p->inTransaction())$p->rollBack();if($stored&&is_file($stored))unlink($stored);if($analyze){http_response_code(422);header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);exit;}redirect_with_toast($return,$e->getMessage(),'danger');}
