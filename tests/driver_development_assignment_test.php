<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/includes/bootstrap.php';
require ROOT_PATH.'/includes/driver_license.php';
function dev_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
$pdo=db();$driver=$pdo->query('SELECT * FROM drivers WHERE license_development_assignment_enabled ORDER BY id LIMIT 1')->fetch();
dev_check((bool)$driver,'A development driver exists');
$env=$_ENV['APP_ENV'];
try {
 $_ENV['APP_ENV']='development';
 dev_check(driver_license_development_eligible($driver),'Development eligibility enabled');
 dev_check(!driver_license_active($driver),'Actual qualification remains unverified');
 dev_check(driver_license_eligibility(['type'=>'Heavy Truck','service_category'=>'Cargo'],$driver)==='','Simulated assignment accepted');
 $_ENV['APP_ENV']='production';
 dev_check(!driver_license_development_eligible($driver),'Production rejects development verification');
 dev_check(driver_license_eligibility(['type'=>'Heavy Truck','service_category'=>'Cargo'],$driver)!=='','Production assignment blocked');
}finally{$_ENV['APP_ENV']=$env;}
$pdo->beginTransaction();try {
 foreach(['development'=>'Assigned','production'=>'Inactive'] as $runtime=>$expected){
  $pdo->prepare("SELECT set_config('toursphere.app_env',?,true)")->execute([$runtime]);
  $q=$pdo->prepare("UPDATE drivers SET status='Assigned' WHERE id=? RETURNING status,authorized_dl_codes");$q->execute([$driver['id']]);$saved=$q->fetch();
  dev_check($saved['status']===$expected,'Database status guard for '.$runtime);
  dev_check($saved['authorized_dl_codes']===$driver['authorized_dl_codes'],'No invented DL codes');
 }
}finally{$pdo->rollBack();}
echo "10 development assignment checks passed; no assignments persisted.\n";
