<?php

function schedule_days_labels(): array
{
    return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
}

function schedule_active_reservation_statuses(): array
{
    // Pending reservations reserve capacity so concurrent customers cannot take
    // the same remaining seats. Rejected/cancelled reservations do not count.
    return ['Pending Approval', 'Approved', 'Pending', 'Assigned', 'Confirmed', 'Dispatched', 'In Transit'];
}

function schedule_operational_capacity(PDO $pdo): int
{
    require_once ROOT_PATH . '/includes/customer_reservations.php';
    $capacity = 0;
    foreach (customer_vehicle_requirement_options($pdo) as $option) {
        $capacity = max($capacity, (int)$option['capacity']);
    }
    return $capacity;
}

function schedule_operational_capacity_at(PDO $pdo, string $date, string $time): int
{
    require_once ROOT_PATH . '/includes/vehicle_compliance.php';
    $start = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone(company_timezone()));
    $end = $start->modify('+4 hours');
    $buffer = max(1, min(72, (int)fleet_setting('dispatch.buffer_hours', '4')));
    $stmt = $pdo->prepare(
        "SELECT v.id,v.capacity FROM vehicles v
          WHERE v.capacity>0 AND v.status NOT IN ('Maintenance','On Trip','Retired','Inactive')
            AND NOT EXISTS (SELECT 1 FROM maintenance_orders m WHERE m.vehicle_id=v.id AND m.status='In Repair')
            AND NOT EXISTS (
              SELECT 1 FROM reservations r WHERE r.assigned_vehicle_id=v.id
                AND r.status IN ('Assigned','Confirmed','Dispatched','In Transit')
                AND r.departure_date::timestamp + COALESCE(NULLIF(r.departure_time,''),'00:00')::time < ?::timestamp + (? || ' hours')::interval
                AND COALESCE(r.return_date::timestamp + COALESCE(NULLIF(r.return_time,''),'23:59')::time,
                    r.departure_date::timestamp + COALESCE(NULLIF(r.departure_time,''),'00:00')::time + interval '4 hours') > ?::timestamp - (? || ' hours')::interval
            )
            AND NOT EXISTS (
              SELECT 1 FROM trips t WHERE t.vehicle_id=v.id
                AND t.status IN ('Scheduled','Assigned','Dispatched','In Transit','Returning to Depot')
                AND t.scheduled_departure BETWEEN (?::timestamp - (? || ' hours')::interval) AND (?::timestamp + (? || ' hours')::interval)
            )
          ORDER BY v.capacity DESC"
    );
    $stamp = $start->format('Y-m-d H:i:s');
    $stmt->execute([$end->format('Y-m-d H:i:s'),$buffer,$stamp,$buffer,$stamp,$buffer,$stamp,$buffer]);
    foreach ($stmt->fetchAll() as $vehicle) {
        if (vehicle_operational_compliance($pdo, (string)$vehicle['id'])['operational']) return (int)$vehicle['capacity'];
    }
    return 0;
}

function schedule_reserved_demand(PDO $pdo, int $instanceId, ?string $excludeReservationId = null): int
{
    $statuses = schedule_active_reservation_statuses();
    $marks = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "SELECT COALESCE(SUM(passenger_count), 0) FROM reservations
             WHERE (departure_schedule_instance_id = ? OR return_schedule_instance_id = ?)
               AND status IN ($marks)";
    $params = [$instanceId, $instanceId, ...$statuses];
    if ($excludeReservationId !== null) { $sql .= ' AND id <> ?'; $params[] = $excludeReservationId; }
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function schedule_template_valid_for_date(array $schedule, string $date): bool
{
    $day = (int)(new DateTimeImmutable($date))->format('N');
    $rawDays = $schedule['operating_days'] ?? [];
    if (is_string($rawDays)) $rawDays = $rawDays === '{}' ? [] : explode(',', trim($rawDays, '{}'));
    $days = array_map('intval', $rawDays);
    return ($schedule['status'] ?? '') === 'Active' && in_array($day, $days, true);
}

function schedule_templates_for_date(PDO $pdo, string $date, int $passengers): array
{
    require_once ROOT_PATH . '/includes/customer_reservations.php';
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(company_timezone()));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || $parsed->format('Y-m-d') !== $date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) return [];
    $weekday = (int)$parsed->format('N');
    $stmt = $pdo->prepare(
        "SELECT s.id, s.name, to_char(s.departure_time, 'HH24:MI') AS departure_time,
                ARRAY_AGG(d.iso_weekday ORDER BY d.iso_weekday) AS operating_days
           FROM operating_schedules s
           JOIN operating_schedule_days d ON d.schedule_id=s.id
          WHERE s.status='Active' AND EXISTS (
                SELECT 1 FROM operating_schedule_days valid_day
                 WHERE valid_day.schedule_id=s.id AND valid_day.iso_weekday=?
          )
          GROUP BY s.id ORDER BY s.departure_time, s.name"
    );
    $stmt->execute([$weekday]);
    $now = new DateTimeImmutable('now', new DateTimeZone(company_timezone()));
    $result = [];
    foreach ($stmt->fetchAll() as $schedule) {
        $instanceStmt = $pdo->prepare("SELECT id,capacity,status,schedule_name_snapshot,to_char(departure_time_snapshot,'HH24:MI') AS departure_time_snapshot FROM scheduled_departures WHERE schedule_id=? AND service_date=?");
        $instanceStmt->execute([$schedule['id'], $date]); $instance = $instanceStmt->fetch();
        $offeredTime = $instance ? (string)$instance['departure_time_snapshot'] : (string)$schedule['departure_time'];
        $offeredName = $instance ? (string)$instance['schedule_name_snapshot'] : (string)$schedule['name'];
        $departure = new DateTimeImmutable($date . ' ' . $offeredTime, new DateTimeZone(company_timezone()));
        if ($departure <= $now || !reservation_notice_valid($date, $offeredTime)) continue;
        $availableCapacity = schedule_operational_capacity_at($pdo, $date, $offeredTime);
        $capacity = $instance ? min((int)$instance['capacity'], $availableCapacity) : $availableCapacity;
        $demand = $instance ? schedule_reserved_demand($pdo, (int)$instance['id']) : 0;
        $remaining = max(0, $capacity - $demand);
        $selectable = (!$instance || $instance['status'] === 'Open') && $passengers > 0 && $passengers <= $remaining;
        $projectedRequirement = $selectable ? customer_required_vehicle_type($pdo, $demand + $passengers) : null;
        $result[] = [
            'id' => (int)$schedule['id'], 'name' => $offeredName,
            'time' => $offeredTime, 'display_time' => $departure->format('g:i A'),
            'availability' => $remaining === 0 ? 'Full' : ($remaining <= max(5, (int)ceil($capacity * .2)) ? 'Limited Availability' : 'Available'),
            'selectable' => $selectable,
            'required_type' => $projectedRequirement['type'] ?? null,
            'required_capacity' => $projectedRequirement['capacity'] ?? null,
        ];
    }
    return $result;
}

function schedule_lock_or_create_instance(PDO $pdo, int $scheduleId, string $date): array
{
    $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(?))")->execute(['schedule:' . $scheduleId . ':' . $date]);
    $stmt = $pdo->prepare(
        "SELECT s.id, s.name, s.status, to_char(s.departure_time, 'HH24:MI') AS departure_time,
                ARRAY_AGG(d.iso_weekday ORDER BY d.iso_weekday) AS operating_days
           FROM operating_schedules s JOIN operating_schedule_days d ON d.schedule_id=s.id
          WHERE s.id=? GROUP BY s.id"
    );
    $stmt->execute([$scheduleId]); $schedule = $stmt->fetch();
    if (!$schedule || !schedule_template_valid_for_date($schedule, $date)) throw new RuntimeException('The selected schedule is not active on this travel date.');
    $instanceStmt = $pdo->prepare('SELECT * FROM scheduled_departures WHERE schedule_id=? AND service_date=? FOR UPDATE');
    $instanceStmt->execute([$scheduleId, $date]); $instance = $instanceStmt->fetch();
    if (!$instance) {
        $capacity = schedule_operational_capacity_at($pdo, $date, (string)$schedule['departure_time']);
        if ($capacity < 1) throw new RuntimeException('No operational transportation capacity is available.');
        $create = $pdo->prepare("INSERT INTO scheduled_departures(schedule_id,service_date,schedule_name_snapshot,departure_time_snapshot,capacity) VALUES (?,?,?,?,?) RETURNING *");
        $create->execute([$scheduleId, $date, $schedule['name'], $schedule['departure_time'], $capacity]);
        $instance = $create->fetch();
    }
    $offeredTime = substr((string)$instance['departure_time_snapshot'], 0, 5);
    if (!reservation_notice_valid($date, $offeredTime)) throw new RuntimeException('The selected departure schedule has passed or does not meet the minimum booking notice.');
    if ($instance['status'] !== 'Open') throw new RuntimeException('The selected departure schedule is no longer open.');
    $currentlyAvailableCapacity = schedule_operational_capacity_at($pdo, $date, $offeredTime);
    $instance['capacity'] = min((int)$instance['capacity'], $currentlyAvailableCapacity);
    if ((int)$instance['capacity'] < 1) throw new RuntimeException('No operational vehicle is available for the selected schedule.');
    return $instance;
}

function schedule_assert_capacity(PDO $pdo, array $instance, int $passengers): int
{
    $demandAfter = schedule_reserved_demand($pdo, (int)$instance['id']) + $passengers;
    if ($passengers < 1 || $demandAfter > (int)$instance['capacity']) throw new RuntimeException('The selected schedule does not have enough remaining capacity. Please choose another schedule.');
    return $demandAfter;
}

function schedule_refresh_required_vehicle(PDO $pdo, int $instanceId): void
{
    require_once ROOT_PATH . '/includes/customer_reservations.php';
    $statuses = schedule_active_reservation_statuses();
    $marks = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare("SELECT id,departure_schedule_instance_id,return_schedule_instance_id FROM reservations WHERE (departure_schedule_instance_id=? OR return_schedule_instance_id=?) AND status IN ($marks) FOR UPDATE");
    $stmt->execute([$instanceId, $instanceId, ...$statuses]);
    $update = $pdo->prepare('UPDATE reservations SET vehicle_requested=?,required_vehicle_type_id=?,required_capacity=? WHERE id=?');
    foreach ($stmt->fetchAll() as $reservation) {
        $demand = schedule_reserved_demand($pdo, (int)$reservation['departure_schedule_instance_id']);
        if ($reservation['return_schedule_instance_id']) $demand = max($demand, schedule_reserved_demand($pdo, (int)$reservation['return_schedule_instance_id']));
        $required = customer_required_vehicle_type($pdo, $demand);
        if (!$required) throw new RuntimeException('The scheduled passenger demand exceeds the operational fleet capacity.');
        $update->execute([$required['type'], $required['vehicle_type_id'], $required['capacity'], $reservation['id']]);
    }
}
