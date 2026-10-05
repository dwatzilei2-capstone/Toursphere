<?php
/** Read-only presentation helpers. No audit writes belong here. */
function audit_ui_label(string $value): string {
    $value = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $value);
    return ucwords(strtolower(str_replace(['_', '-'], ' ', $value)));
}
function audit_ui_action(string $value): string {
    $key = strtoupper(str_replace(' ', '_', $value));
    $key = preg_replace('/^(VEHICLES|TRIPS|RESERVATIONS|DRIVERS)_/', match (strtok($key, '_')) {'VEHICLES'=>'VEHICLE_', 'TRIPS'=>'TRIP_', 'RESERVATIONS'=>'RESERVATION_', 'DRIVERS'=>'DRIVER_', default=>''}, $key);
    $labels = ['LOGIN_SUCCEEDED'=>'Login Successful','LOGIN_FAILED'=>'Login Failed','LOGIN_2FA_FAILED'=>'Two-Factor Verification Failed','LOGOUT'=>'Logged Out','ACCOUNT_CREDENTIAL_CHANGED'=>'Account Credentials Changed'];
    if (isset($labels[$key])) return $labels[$key];
    $key = preg_replace('/_INSERT$/', '_CREATED', $key);
    $key = preg_replace('/_UPDATE$/', '_UPDATED', $key);
    $key = preg_replace('/_DELETE$/', '_DELETED', $key);
    return audit_ui_label($key);
}
function audit_ui_category(string $value): array {
    $key = strtoupper($value);
    if ($key==='LOGIN_SUCCEEDED') return ['Login / Security','success'];
    if (str_contains($key,'PREVIEW_GENERATED')) return ['Report Generated','primary'];
    foreach (['FAILED'=>['Security Warning','danger'],'REJECT'=>['Rejected','danger'],'CANCEL'=>['Cancelled','muted'],'RESTOR'=>['Restored','success'],'ARCHIV'=>['Archived','muted'],'RETIR'=>['Archived','muted'],'APPROV'=>['Approved','success'],'COMPLET'=>['Completed','success'],'EXPORT'=>['Exported','success'],'DISPATCH'=>['Dispatched','primary'],'ASSIGN'=>['Assigned','warning'],'CREAT'=>['Created','success'],'INSERT'=>['Created','success'],'UPDATE'=>['Updated','warning'],'CHANG'=>['Updated','warning'],'START'=>['Started','warning'],'LOGIN'=>['Login / Security','primary'],'LOGOUT'=>['Login / Security','primary'],'CREDENTIAL'=>['Login / Security','warning'],'DELETE'=>['Deleted','danger']] as $needle=>$category) {
        if (str_contains($key,$needle)) return $category;
    }
    return ['System Activity','primary'];
}
function audit_ui_role(?string $role): string {
    return match (strtolower(trim($role ?? ''))) {
        'fleet_admin','fleet administrator','admin','administrator'=>'Admin',
        'customer','user','user/customer'=>'User', 'dispatcher'=>'Dispatcher', 'driver'=>'Driver',
        ''=>'Unknown', default=>audit_ui_label($role)
    };
}
function audit_ui_module(?string $module): string {
    return match (strtolower($module ?? '')) {
        'vehicle','vehicles','vehicle_documents','vehicle_photos'=>'Fleet & Vehicles',
        'reservation','reservations','reservation_events'=>'Reservations', 'trip','trips'=>'Trips',
        'driver','drivers','driver_ratings'=>'Driver Monitoring', 'security','users'=>'Login / Security',
        'report','reports'=>'Reports', 'maintenance_orders'=>'Maintenance', 'fuel_transactions'=>'Fuel Management',
        'vehicle_cost_ledger'=>'Cost Analysis', 'roles','role_permissions'=>'Access Management',
        'system_settings'=>'System Settings', 'operating_schedules','operating_schedule_days','scheduled_departures'=>'Schedules',
        ''=>'System', default=>audit_ui_label($module)
    };
}
function audit_ui_json($value): array {
    if (is_array($value)) return $value;
    $decoded=json_decode((string)$value,true);
    return is_array($decoded) ? $decoded : [];
}
function audit_ui_field(string $key): string {
    return ['ip'=>'IP Address','verification'=>'Verification Method','assigned_driver_id'=>'Assigned Driver','driver_id'=>'Driver','assigned_vehicle_id'=>'Assigned Vehicle','vehicle_id'=>'Vehicle','is_archived'=>'Archived','navigation_active'=>'Navigation Active','actor_role'=>'Account Role','from'=>'Start Date','to'=>'End Date'][$key] ?? audit_ui_label($key);
}
function audit_ui_value(string $key, $value, array $lookups=[]): string {
    if ($value===null || $value==='') return 'None';
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if ($value==='two_factor') return 'Two-Factor Authentication';
    if (str_ends_with($key,'driver_id') && isset($lookups['drivers'][(string)$value])) return $lookups['drivers'][(string)$value];
    if (str_ends_with($key,'vehicle_id') && isset($lookups['vehicles'][(string)$value])) return $lookups['vehicles'][(string)$value];
    if (in_array($key,['actor_id','reviewed_by','cancelled_by','user_id','customer_id'],true) && isset($lookups['users'][(string)$value])) return $lookups['users'][(string)$value];
    if ($key==='actor_role') return audit_ui_role((string)$value);
    if (is_array($value)) return implode('; ',array_map(fn($k,$v)=>audit_ui_field((string)$k).': '.audit_ui_value((string)$k,$v,$lookups),array_keys($value),array_values($value)));
    return (string)$value;
}
function audit_ui_time(string $value): string {
    try { return (new DateTimeImmutable($value))->format('M j, Y · g:i A'); } catch (Exception $e) { return $value; }
}
function audit_ui_present(array $row,array $lookups=[]): array {
    $details=audit_ui_json($row['details']);
    $name=$row['actor_name'] ?: ($details['actor_name'] ?? ($row['current_name'] ?: ($row['user_id'] ? 'Account #'.$row['user_id'] : 'System / Unauthenticated')));
    $role=audit_ui_role($row['actor_role'] ?: ($details['actor_role'] ?? ($row['current_role'] ?? null)));
    $old=audit_ui_json($row['old_values']); $new=audit_ui_json($row['new_values']);
    $changes=[];
    if ($old && $new) foreach (array_unique(array_merge(array_keys($old),array_keys($new))) as $key) {
        if (($old[$key]??null)!==($new[$key]??null)) $changes[]=[audit_ui_field($key),audit_ui_value($key,$old[$key]??null,$lookups),audit_ui_value($key,$new[$key]??null,$lookups)];
    }
    $information=[]; $security=[];
    foreach ($details as $key=>$value) {
        if (in_array($key,['actor_name','actor_role','module','operation','snapshot'],true) || $value===null) continue;
        $pair=[audit_ui_field($key),audit_ui_value($key,$value,$lookups)];
        if (in_array($key,['ip','verification','user_agent'],true)) $security[]=$pair; else $information[]=$pair;
    }
    $recordInfo=[];
    if (!$old || !$new) foreach (($new ?: $old) as $key=>$value) {
        if ($value!==null && $value!=='') $recordInfo[]=[audit_ui_field($key),audit_ui_value($key,$value,$lookups)];
    }
    $action=audit_ui_action($row['action']);
    $category=audit_ui_category($row['action']);
    if (str_ends_with($row['action'],'_STATUS_CHANGED') && isset($new['status'])) {
        $event=match ($new['status']) {
            'Approved'=>'Approved', 'Rejected'=>'Rejected', 'Dispatched'=>'Dispatched',
            'Completed'=>'Completed', 'Cancelled'=>'Cancelled', 'In Transit'=>'Started',
            'Retired'=>'Retired', default=>null
        };
        if ($event) {
            $action=audit_ui_action(preg_replace('/_STATUS_CHANGED$/','_'.$event,$row['action']));
            $category=audit_ui_category($event);
        }
    }
    $verb=match ($category[0]) {
        'Created'=>'created', 'Updated'=>'updated', 'Approved'=>'approved', 'Rejected'=>'rejected',
        'Assigned'=>'assigned', 'Dispatched'=>'dispatched', 'Completed'=>'completed', 'Archived'=>'archived',
        'Restored'=>'restored', 'Exported'=>'exported', 'Cancelled'=>'cancelled', 'Started'=>'started',
        default=>null
    };
    $recordType=match ($row['entity_type']) {'vehicle','vehicles'=>'vehicle','trip','trips'=>'trip','reservation','reservations'=>'reservation','report'=>'report',default=>strtolower(audit_ui_module($row['entity_type']))};
    $description=$name.($verb ? ' '.$verb.' '.$recordType.($row['entity_id'] ? ' '.$row['entity_id'] : '').'.' : ' performed '.strtolower($action).'.');
    if (!$details && trim((string)$row['details'])!=='') $description=$row['details'];
    return ['id'=>$row['id'],'name'=>$name,'email'=>$row['actor_email'] ?: ($row['current_email'] ?? ''),'role'=>$role,
        'roleFull'=>$row['actor_role'] ?: ($details['actor_role'] ?? ($row['current_role'] ?? 'Unknown')),
        'roleCurrent'=>!$row['actor_role'] && empty($details['actor_role']) && !empty($row['current_role']),
        'action'=>$action,'category'=>$category,'module'=>audit_ui_module($row['entity_type']),
        'record'=>$row['entity_type']==='report' ? audit_ui_label($row['entity_id'] ?? '') : ($row['entity_id'] ?: '—'),'time'=>audit_ui_time($row['created_at']),'timestamp'=>$row['created_at'],
        'changes'=>$changes,'information'=>$information,'security'=>$security,'recordInfo'=>$recordInfo,'description'=>$description,
        'raw'=>['action'=>$row['action'],'details'=>$details ?: $row['details'],'old_values'=>$old ?: null,'new_values'=>$new ?: null]];
}
