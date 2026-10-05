<?php

/** Default assignments live in vehicles.assigned_driver_id. Trip assignments live in trips/reservations. */
function driver_has_active_trip(PDO $pdo, string $driverId, ?string $excludeTripId = null): bool
{
    $sql = "SELECT 1 FROM trips WHERE driver_id=? AND status IN ('In Transit','Returning to Depot')";
    $params = [$driverId];
    if ($excludeTripId !== null && $excludeTripId !== '') {
        $sql .= ' AND id<>?';
        $params[] = $excludeTripId;
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

function vehicle_has_active_trip(PDO $pdo, string $vehicleId, ?string $excludeTripId = null): bool
{
    $sql = "SELECT 1 FROM trips WHERE vehicle_id=? AND status IN ('In Transit','Returning to Depot')";
    $params = [$vehicleId];
    if ($excludeTripId !== null && $excludeTripId !== '') {
        $sql .= ' AND id<>?';
        $params[] = $excludeTripId;
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

function refresh_driver_operational_status(PDO $pdo, string $driverId): void
{
    if (driver_has_active_trip($pdo, $driverId)) {
        $status = 'On Trip';
    } else {
        $stmt = $pdo->prepare("SELECT 1 FROM vehicles WHERE assigned_driver_id=? AND status <> 'Retired' LIMIT 1");
        $stmt->execute([$driverId]);
        $status = $stmt->fetchColumn() ? 'Assigned' : 'Active';
    }
    $pdo->prepare('UPDATE drivers SET status=? WHERE id=?')->execute([$status, $driverId]);
}

function refresh_vehicle_operational_status(PDO $pdo, string $vehicleId): void
{
    $lifecycle = $pdo->prepare('SELECT status FROM vehicles WHERE id=?');
    $lifecycle->execute([$vehicleId]);
    if ($lifecycle->fetchColumn() === 'Retired') return;
    $repair = $pdo->prepare("SELECT 1 FROM maintenance_orders WHERE vehicle_id=? AND status IN ('Scheduled','In Repair') LIMIT 1");
    $repair->execute([$vehicleId]);
    if ($repair->fetchColumn()) {
        $pdo->prepare("UPDATE vehicles SET status='Maintenance', location='Maintenance / Inspection' WHERE id=?")->execute([$vehicleId]);
        return;
    }
    if (vehicle_has_active_trip($pdo, $vehicleId)) {
        $pdo->prepare("UPDATE vehicles SET status='On Trip', location='On Trip' WHERE id=?")->execute([$vehicleId]);
        return;
    }
    $scheduled = $pdo->prepare("SELECT origin,destination FROM reservations WHERE assigned_vehicle_id=? AND status IN ('Assigned','Confirmed','Dispatched') ORDER BY departure_date,created_at LIMIT 1");
    $scheduled->execute([$vehicleId]);
    if ($trip = $scheduled->fetch()) {
        $pdo->prepare("UPDATE vehicles SET status='Assigned', location=? WHERE id=?")
            ->execute(['Scheduled: ' . $trip['origin'] . ' → ' . $trip['destination'], $vehicleId]);
        return;
    }
    $default = $pdo->prepare('SELECT assigned_driver_id FROM vehicles WHERE id=?');
    $default->execute([$vehicleId]);
    $status = $default->fetchColumn() ? 'Assigned' : 'Available';
    $pdo->prepare("UPDATE vehicles SET status=?, location='Central Depot' WHERE id=?")->execute([$status, $vehicleId]);
}
