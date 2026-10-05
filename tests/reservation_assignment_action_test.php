<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!isset($argv[1])) {
    foreach(['inherit','pair','reassign','silent','mismatch','incompatible','capacity','documents','pending','dispatch','rollback','second_booking'] as $case) {
        $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case);
        exec($command,$output,$code);
        if($code!==0)throw new RuntimeException($case.' failed: '.implode("\n",$output));
        $output=[];
    }
    echo "12 full assignment action integration cases passed using temporary tables.\n";exit;
}
require_once dirname(__DIR__).'/includes/bootstrap.php';
$pdo=db();$case=$argv[1];
foreach(['vehicles','drivers','reservations','trips','maintenance_orders','vehicle_documents','audit_logs','system_settings','reservation_events'] as $table) $pdo->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES)");
$pdo->exec("CREATE TEMP SEQUENCE assignment_test_settings START 1000000; ALTER TABLE system_settings ALTER COLUMN id SET DEFAULT nextval('assignment_test_settings'); CREATE TEMP SEQUENCE assignment_test_audit; ALTER TABLE audit_logs ALTER COLUMN id SET DEFAULT nextval('assignment_test_audit'); INSERT INTO system_settings SELECT * FROM public.system_settings");
$pdo->exec("INSERT INTO drivers(id,name,status,license_class,license_expiration) VALUES('D1','Driver One','Active','Class 3','2099-12-31'),('D2','Driver Two','Active','Class 2','2099-12-31')");
$pdo->exec("INSERT INTO vehicles(id,plate_number,type,brand,model,year,capacity,assigned_driver_id) VALUES('V14','TEST14','Van','Test','Best Fit',2026,14,'D1'),('VNONE','TESTNONE','Van','Test','No driver',2026,14,NULL)");
$doc=$pdo->prepare("INSERT INTO vehicle_documents(id,vehicle_id,document_type,extracted_data,extraction_status,stored_filename,original_filename,mime_type,file_size) VALUES(md5(random()::text),?,?,?,'extracted',md5(random()::text),'test.pdf','application/pdf',1)");
foreach(['V14','VNONE'] as $v) foreach(['registration','insurance','ltfrb_permit'] as $type) $doc->execute([$v,$type,json_encode(['vehicle_match_status'=>'MATCHED','document_type_status'=>'MATCHED','expiration_date'=>'2099-12-31'])]);
$pdo->exec("INSERT INTO reservations(id,client_name,passenger_count,origin,destination,departure_date,departure_time,vehicle_requested,required_capacity,status) VALUES('RTEST','Test',13,'A','B','2030-01-10','07:00','Van',14,'Approved')");
$current_user=['id'=>1,'role_code'=>'fleet_admin'];$current_permissions=['dispatch.manage'];
$_SESSION=['assignment_csrf'=>'test-csrf'];$_SERVER['REQUEST_METHOD']='POST';
$_POST=['csrf'=>'test-csrf','reservation_id'=>'RTEST','vehicle_id'=>'V14','driver_id'=>'D1','dispatch_action'=>'assign','departure'=>'2030-01-10T08:00','notes'=>'Test notes','return'=>'/fleet/test'];
$success=in_array($case,['inherit','pair','reassign','dispatch'],true);
if(in_array($case,['pair','reassign','silent','rollback'],true)) $_POST['vehicle_id']='VNONE';
if($case==='pair')$_POST['driver_id']='D2';
if(in_array($case,['reassign','rollback'],true))$_POST['reassignment_from']='V14';
if($case==='mismatch')$_POST['driver_id']='D2';
if($case==='incompatible')$pdo->exec("UPDATE drivers SET license_class='Class 1' WHERE id='D1'");
if($case==='capacity')$pdo->exec("UPDATE vehicles SET capacity=12 WHERE id='V14'");
if($case==='documents')$pdo->exec("DELETE FROM vehicle_documents WHERE vehicle_id='V14' AND document_type='insurance'");
if($case==='pending')$pdo->exec("UPDATE reservations SET status='Pending' WHERE id='RTEST'");
if($case==='rollback')$pdo->exec("CREATE FUNCTION pg_temp.reject_test_save() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Intentional failure after pairing''; END'; CREATE TRIGGER assignment_fail BEFORE UPDATE ON reservations FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_test_save()");
if($case==='second_booking')$pdo->exec("INSERT INTO trips(id,reservation_id,origin,destination,vehicle_id,driver_id,scheduled_departure,status) VALUES('TOTHER','ROTHER','A','B','V14','D1','2030-01-10 08:00','Assigned')");
if($case==='dispatch'){
 $_POST['dispatch_action']='dispatch';$pdo->exec("UPDATE reservations SET assigned_vehicle_id='V14',assigned_driver_id='D1',status='Assigned' WHERE id='RTEST'; INSERT INTO trips(id,reservation_id,origin,destination,vehicle_id,driver_id,scheduled_departure,status) VALUES('TTEST','RTEST','A','B','V14','D1','2030-01-10 08:00','Assigned')");
}
register_shutdown_function(function()use($pdo,$case,$success){
 try {
  $r=$pdo->query("SELECT * FROM reservations WHERE id='RTEST'")->fetch();
  if($success){
   if($r['assigned_vehicle_id']!==$_POST['vehicle_id'] || $r['assigned_driver_id']!==$_POST['driver_id'])throw new RuntimeException('Saved assignment mismatch: '.($GLOBALS['assignment_test_error'] ?? 'no captured error'));
   $v=$pdo->query("SELECT assigned_driver_id FROM vehicles WHERE id='".$_POST['vehicle_id']."'")->fetchColumn();
   if($v!==$r['assigned_driver_id'])throw new RuntimeException('Designated pairing mismatch');
   $t=$pdo->query("SELECT * FROM trips WHERE reservation_id='RTEST'")->fetch();
   if(!$t || $t['vehicle_id']!==$r['assigned_vehicle_id'] || $t['driver_id']!==$r['assigned_driver_id'])throw new RuntimeException('Trip pairing mismatch');
   if($t['status']!==($case==='dispatch'?'Dispatched':'Assigned'))throw new RuntimeException('Assignment dispatched prematurely');
   if(($t['fuel_allowance'] ?? $t['driver_allowance'] ?? null)!==null)throw new RuntimeException('Fake fuel allowance generated');
   if($r['departure_time']!=='08:00')throw new RuntimeException('Departure not persisted');
   if((int)$pdo->query('SELECT count(*) FROM audit_logs')->fetchColumn()!==1)throw new RuntimeException('Audit missing or duplicated');
   if($case==='reassign' && $pdo->query("SELECT assigned_driver_id FROM vehicles WHERE id='V14'")->fetchColumn())throw new RuntimeException('Old designation retained');
  } else {
   if($r['assigned_vehicle_id'])throw new RuntimeException('Invalid assignment saved');
   if($pdo->query("SELECT assigned_driver_id FROM vehicles WHERE id='V14'")->fetchColumn()!=='D1')throw new RuntimeException('Old pairing lost after failed save');
   if($pdo->query("SELECT assigned_driver_id FROM vehicles WHERE id='VNONE'")->fetchColumn())throw new RuntimeException('Partial new pairing retained');
   if((int)$pdo->query('SELECT count(*) FROM audit_logs')->fetchColumn()!==0)throw new RuntimeException('Failed save generated audit');
  }
 }catch(Throwable $e){fwrite(STDERR,$case.': '.$e->getMessage()."\n");exit(1);}
});
// Execute the action unchanged except for recording its handled exception for diagnostics.
$action=file_get_contents(ROOT_PATH.'/actions/dispatch.php');
$action=str_replace('} catch (Exception $ex) {', '} catch (Exception $ex) { $GLOBALS["assignment_test_error"] = $ex->getMessage();', $action);
eval(substr($action,5));
