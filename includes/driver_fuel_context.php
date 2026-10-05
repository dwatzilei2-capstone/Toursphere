<?php

/** Resolve fuel ownership from the signed-in driver, never from submitted driver IDs. */
function driver_fuel_context(PDO $pdo, string $userId): ?array
{
    $stmt = $pdo->prepare("SELECT d.id AS driver_id, d.name AS driver_name,
            t.vehicle_id, t.id AS trip_id, v.plate_number, v.brand, v.model, v.fuel_type
        FROM drivers d
        LEFT JOIN LATERAL (
            SELECT id, vehicle_id FROM trips
            WHERE driver_id=d.id AND vehicle_id IS NOT NULL
              AND status IN ('Dispatched','In Transit','Returning to Depot')
            ORDER BY CASE WHEN status IN ('In Transit','Returning to Depot') THEN 0
                          WHEN status='Dispatched' THEN 1 ELSE 2 END,
                     scheduled_departure ASC NULLS LAST, created_at DESC
            LIMIT 1
        ) t ON TRUE
        LEFT JOIN vehicles v ON v.id=t.vehicle_id
        WHERE d.user_id=?");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}
