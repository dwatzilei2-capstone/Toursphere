<?php
require_once dirname(__DIR__).'/includes/audit-ui.php';
function ui_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
ui_check(audit_ui_action('LOGIN_SUCCEEDED')==='Login Successful','Login label');
ui_check(audit_ui_action('VEHICLES_UPDATE')==='Vehicle Updated','Trigger label');
ui_check(audit_ui_action('REPORT_PREVIEW_GENERATED')==='Report Preview Generated','Report label');
ui_check(audit_ui_time('2026-10-05 21:24:21.071365')==="Oct 5, 2026 \u{00B7} 9:24 PM",'Date format');
ui_check(audit_ui_role('Fleet Administrator')==='Admin','Admin role');
ui_check(audit_ui_role(null)==='Unknown','Unknown role');
$row=['id'=>1,'user_id'=>3,'actor_name'=>null,'actor_email'=>null,'actor_role'=>null,'current_name'=>'Test account','current_email'=>'test@example.invalid','current_role'=>'Dispatcher','action'=>'VEHICLES_UPDATE','entity_type'=>'vehicles','entity_id'=>'TEST','created_at'=>'2026-10-05 21:24:21','details'=>'{"verification":"two_factor","ip":"::1"}','old_values'=>'{"status":"Available","assigned_driver_id":null}','new_values'=>'{"status":"Maintenance","assigned_driver_id":"D1"}'];
$result=audit_ui_present($row,['drivers'=>['D1'=>'Related driver']]);
ui_check($result['role']==='Dispatcher' && $result['roleCurrent'],'Actual account role fallback');
ui_check($result['changes'][1][2]==='Related driver','Related driver label');
ui_check($result['security'][0][1]==='Two-Factor Authentication','Readable security data');
$row['actor_role']='Driver';ui_check(audit_ui_present($row)['role']==='Driver','Historical role preserved');
$row['actor_role']=null;$row['current_role']=null;ui_check(audit_ui_present($row)['role']==='Unknown','No fabricated role');
echo "PASS: Audit UI labels, role fallback, historical roles, dates, related values and structured security details.\n";
