<?php

function validate_dispatch_assignment(array $reservation, string $action, string $vehicleId, string $driverId): void
{
    if (!in_array($reservation['status'], ['Assigned', 'Confirmed'], true)) return;
    if ($action !== 'dispatch') return;
    if (empty($reservation['assigned_vehicle_id']) || empty($reservation['assigned_driver_id'])
        || $vehicleId !== $reservation['assigned_vehicle_id'] || $driverId !== $reservation['assigned_driver_id']) {
        throw new RuntimeException('Dispatch must use the saved assignment: vehicle '
            . ($reservation['assigned_vehicle_id'] ?: 'not assigned') . ' and driver '
            . ($reservation['assigned_driver_id'] ?: 'not assigned') . '. Refresh the page to load the current assignment. No changes were saved.');
    }
}
