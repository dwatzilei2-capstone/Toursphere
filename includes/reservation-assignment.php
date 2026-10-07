<?php
require_once __DIR__ . '/vehicle_compliance.php';
require_once __DIR__ . '/driver_vehicle_assignment.php';
require_once __DIR__ . '/schedules.php';
require_once __DIR__ . '/vehicle_photo.php';

function assignment_driver_class_matches(array $vehicle, array $driver): bool
{
    $type = strtolower((string)$vehicle['type']);
    $class = ((int)$vehicle['capacity'] > 30 || str_contains($type, 'bus') || str_contains($type, 'coach')) ? 3 : 2;
    preg_match_all('/(?:class|restriction)\s*(\d+(?:\s*[,\/&]\s*\d+)*)/i', (string)($driver['license_class'] ?? ''), $groups);
    $codes = [];
    foreach ($groups[1] as $group) {
        preg_match_all('/\d+/', $group, $numbers);
        $codes = array_merge($codes, array_map('intval', $numbers[0]));
    }
    return in_array(3, $codes, true) || ($class === 2 && in_array(2, $codes, true));
}

function assignment_resource_conflict(PDO $pdo, array $r, string $column, string $id, string $departure, bool $reassignment = false): bool
{
    if (!in_array($column, ['driver_id','vehicle_id'], true)) throw new InvalidArgumentException('Invalid resource.');
    $buffer = max(1, min(72, (int)fleet_setting('dispatch.buffer_hours', '4')));
    $start = new DateTimeImmutable($departure);
    $end = !empty($r['return_date']) ? new DateTimeImmutable($r['return_date'].' '.($r['return_time'] ?: '23:59')) : $start->modify('+4 hours');
    if ($end <= $start) $end = $start->modify('+4 hours');
    $stmt = $pdo->prepare("SELECT 1 FROM trips t LEFT JOIN reservations sr ON sr.id=t.reservation_id
        WHERE t.$column=? AND t.reservation_id IS DISTINCT FROM ?
        AND (?::bigint IS NULL OR sr.departure_schedule_instance_id IS DISTINCT FROM ?::bigint)
        AND t.status IN ('Scheduled','Assigned','Confirmed','Dispatched','In Transit','Returning to Depot')
        AND (t.status IN ('Dispatched','In Transit','Returning to Depot') OR ?::boolean OR
            t.scheduled_departure BETWEEN ?::timestamp - (? || ' hours')::interval AND ?::timestamp + (? || ' hours')::interval) LIMIT 1");
    $instance = $r['departure_schedule_instance_id'] ?? null;
    $stmt->execute([$id,$r['id'],$instance,$instance,$reassignment ? 'true' : 'false',$departure,$buffer,$departure,$buffer]);
    if ($stmt->fetchColumn()) return true;
    $stmt = $pdo->prepare("SELECT 1 FROM reservations other WHERE other.assigned_$column=? AND other.id<>?
        AND (?::bigint IS NULL OR other.departure_schedule_instance_id IS DISTINCT FROM ?::bigint)
        AND other.status IN ('Assigned','Confirmed','Dispatched','In Transit')
        AND (other.status IN ('Dispatched','In Transit') OR ?::boolean OR (
          other.departure_date::timestamp + COALESCE(NULLIF(other.departure_time,''),'00:00')::time < ?::timestamp + (? || ' hours')::interval
          AND COALESCE(other.return_date::timestamp + COALESCE(NULLIF(other.return_time,''),'23:59')::time,
            other.departure_date::timestamp + COALESCE(NULLIF(other.departure_time,''),'00:00')::time + interval '4 hours') > ?::timestamp - (? || ' hours')::interval)) LIMIT 1");
    $stmt->execute([$id,$r['id'],$instance,$instance,$reassignment ? 'true' : 'false',$end->format('Y-m-d H:i:s'),$buffer,$departure,$buffer]);
    return (bool)$stmt->fetchColumn();
}

function assignment_options(PDO $pdo, array $r, string $departure): array
{
    $vehicles = $pdo->query('SELECT * FROM vehicles ORDER BY id')->fetchAll();
    $drivers = $pdo->query('SELECT * FROM drivers ORDER BY name')->fetchAll();
    $photos = vehicle_photo_records($pdo, array_column($vehicles, 'id'));
    $minimum = max((int)$r['passenger_count'], (int)($r['required_capacity'] ?? 0));
    if (!empty($r['departure_schedule_instance_id'])) $minimum = max($minimum, schedule_reserved_demand($pdo, (int)$r['departure_schedule_instance_id']));
    $result = [];
    $conflicts = [];
    $conflict = function(string $column, string $id, bool $reassignment = false) use ($pdo,$r,$departure,&$conflicts): bool {
        $key = $column.':'.$id.':'.(int)$reassignment;
        return $conflicts[$key] ??= assignment_resource_conflict($pdo,$r,$column,$id,$departure,$reassignment);
    };
    foreach ($vehicles as $v) {
        if (filter_var($v['is_archived'] ?? false, FILTER_VALIDATE_BOOLEAN)) continue;
        $reasons = [];
        if (!in_array($v['status'], ['Available','Assigned'], true)) $reasons[] = 'Vehicle '.$v['status'];
        if ((int)$v['capacity'] < $minimum) $reasons[] = 'Not enough seats: '.$v['capacity'].' available; '.$minimum.' required';
        if (!empty($r['vehicle_requested']) && $v['type'] !== $r['vehicle_requested']) $reasons[] = 'Booking requires '.$r['vehicle_requested'].'; this vehicle is '.$v['type'];
        $maintenance = $pdo->prepare("SELECT 1 FROM maintenance_orders WHERE vehicle_id=? AND status IN ('Scheduled','In Repair') LIMIT 1");
        $maintenance->execute([$v['id']]);
        if ($maintenance->fetchColumn()) $reasons[] = 'Under maintenance';
        $compliance = vehicle_operational_compliance($pdo, $v['id']);
        if ($compliance['operational']) $compliance = vehicle_operational_compliance($pdo, $v['id'], substr($departure,0,10));
        if (!$compliance['operational']) $reasons[] = $compliance['reason'] ?: 'Required documents incomplete';
        if ($conflict('vehicle_id',$v['id'])) $reasons[] = 'Existing trip or schedule conflict';
        $eligible = []; $unavailableDrivers = []; $designated = null;
        foreach ($drivers as $d) {
            $current = array_values(array_filter($vehicles, fn($other) => $other['assigned_driver_id'] === $d['id']));
            $reassign = count($current) > 0 && $current[0]['id'] !== $v['id'];
            $issue = !in_array($d['status'], ['Active','Assigned'], true) ? 'Driver '.$d['status'] : '';
            if (!$issue && !assignment_driver_class_matches($v,$d)) $issue = 'Not available for this vehicle';
            if (!$issue && !empty($d['license_expiration']) && $d['license_expiration'] < substr($departure,0,10)) $issue = 'License expires on '.$d['license_expiration'].'; departure is '.substr($departure,0,10);
            if (!$issue && $conflict('driver_id',$d['id'],$reassign)) $issue = 'Driver has an active trip or another assignment overlapping this departure and dispatch buffer';
            if (!$issue && $reassign) foreach ($current as $previous) {
                if ($conflict('vehicle_id',$previous['id'],true)) $issue = 'Current vehicle has an active assignment';
            }
            if (count($current)>1) $issue = 'Driver has duplicate designated vehicles; correct Fleet assignment first';
            $entry = ['id'=>$d['id'],'name'=>$d['name'],'reason'=>$issue,'reassignment'=>$reassign,'current_vehicles'=>array_map(fn($other)=>['id'=>$other['id'],'plate'=>$other['plate_number']],$current)];
            if ($v['assigned_driver_id'] === $d['id']) $designated = $entry;
            if (!$issue) $eligible[] = $entry;
            else $unavailableDrivers[] = $entry;
        }
        usort($eligible,fn($a,$b)=>($a['reassignment']<=>$b['reassignment']) ?: strcmp($a['name'],$b['name']));
        if ($v['assigned_driver_id'] && !$designated) $reasons[] = 'Designated driver missing';
        if ($designated && $designated['reason']) $reasons[] = 'Assigned '.$designated['reason'];
        if (!$v['assigned_driver_id'] && !$eligible) $reasons[] = 'No compatible available driver';
        $priority = $designated ? 1 : ((!empty($eligible) && !$eligible[0]['reassignment']) ? 2 : 3);
        $result[] = ['photo'=>vehicle_photo_present($v['id'], $photos[$v['id']] ?? []),'id'=>$v['id'],'name'=>trim($v['brand'].' '.$v['model']),'plate'=>$v['plate_number'],'type'=>$v['type'],'capacity'=>(int)$v['capacity'],'driver'=>$designated,'drivers'=>$designated ? [] : $eligible,'unavailable_drivers'=>$unavailableDrivers,'eligible'=>!$reasons,'reasons'=>array_values(array_unique($reasons)),'priority'=>$priority,
            'status'=>$reasons ? 'Unavailable' : ($designated ? 'Available' : ($priority===2 ? 'Driver Required' : 'Reassignment Required'))];
    }
    usort($result,fn($a,$b)=>($b['eligible']<=>$a['eligible']) ?: ($a['priority']<=>$b['priority']) ?: ($a['capacity']<=>$b['capacity']) ?: strcmp($a['id'],$b['id']));
    return ['vehicles'=>$result,'recommended_id'=>($result[0]['eligible'] ?? false) ? $result[0]['id'] : null,'minimum_capacity'=>$minimum];
}

/** Caller holds the shared designation lock and vehicle/driver row locks. */
function assignment_confirm_pairing(PDO $pdo, array $vehicle, string $driverId, array $choice, string $action, string $confirmation): ?array
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Assignment requires a transaction.');
    if (!$choice['eligible']) throw new RuntimeException('Vehicle-driver combination is unavailable.');
    $pairingChange = null;
    if ($vehicle['assigned_driver_id']) {
        if ($vehicle['assigned_driver_id'] !== $driverId) throw new RuntimeException('Driver must match the selected vehicle designated driver. Refresh the assignment.');
    } else {
        if ($action === 'dispatch') throw new RuntimeException('The saved designated pairing is missing. Review and confirm assignment before dispatch.');
        $candidate = null;
        foreach ($choice['drivers'] as $eligible) if ($eligible['id'] === $driverId) $candidate = $eligible;
        if (!$candidate) throw new RuntimeException('Driver is no longer eligible for this vehicle.');
        if ($candidate['reassignment'] && $confirmation !== implode(',',array_column($candidate['current_vehicles'],'id'))) {
            throw new RuntimeException('Explicit confirmation of the current driver reassignment is required. Refresh and review the assignment.');
        }
        $pairingChange = ['previous'=>$candidate['current_vehicles'],'vehicle_id'=>$vehicle['id'],'driver_id'=>$driverId];
        $pdo->prepare('UPDATE vehicles SET assigned_driver_id=NULL WHERE assigned_driver_id=? AND id<>?')->execute([$driverId,$vehicle['id']]);
        foreach ($candidate['current_vehicles'] as $previous) refresh_vehicle_operational_status($pdo,$previous['id']);
        $pdo->prepare('UPDATE vehicles SET assigned_driver_id=? WHERE id=?')->execute([$driverId,$vehicle['id']]);
    }
    return $pairingChange;
}
