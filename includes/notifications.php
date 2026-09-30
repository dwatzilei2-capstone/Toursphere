<?php
function notification_time(array $n): string {
    $date = new DateTimeImmutable($n['created_at'], new DateTimeZone(company_timezone()));
    return $date->format('M j, Y g:i A');
}
function notification_target_url(?string $target): string {
    global $current_user;
    $parts = explode(':', (string)$target, 2);
    $key = $parts[0]; $id = $parts[1] ?? '';
    $role = $current_user['role_code'] ?? '';
    if ($role === 'customer' && $key === 'trip-details' && $id) { $stmt=db()->prepare('SELECT reservation_id FROM trips WHERE id=?'); $stmt->execute([$id]); $id=(string)$stmt->fetchColumn(); }
    if ($role === 'customer') return BASE_URL.'/modules/customer-portal/reservations.php'.($id ? '?id='.rawurlencode($id) : '');
    if ($role === 'driver' && $key !== 'trip-details') return BASE_URL.'/modules/driver-portal/'.(in_array($key,['vehicles','vehicle-assignment','maintenance'],true) ? 'driver-vehicle.php' : 'driver-trips.php');
    $map = ['settings'=>'/settings.php','dashboard'=>'/dashboard.php','dispatch-board'=>'/modules/vehicle-reservation-dispatch/dispatch-board.php','reservations'=>'/modules/vehicle-reservation-dispatch/reservations.php','trip-schedule'=>'/modules/vehicle-reservation-dispatch/trip-schedule.php','ai-route-planner'=>'/modules/ai-route-optimization/ai-route-planner.php','route-history'=>'/modules/ai-route-optimization/route-history.php','maintenance'=>'/modules/fleet-vehicle-management/maintenance.php','fuel-transactions'=>'/modules/fuel-management/fuel-transactions.php','drivers'=>'/modules/driver-trip-performance/driver-performance.php','vehicles'=>'/modules/fleet-vehicle-management/vehicle-directory.php','vehicle-assignment'=>'/modules/fleet-vehicle-management/vehicle-assignment.php','driver-dashboard'=>'/modules/driver-portal/driver-dashboard.php','driver-trips'=>'/modules/driver-portal/driver-trips.php','driver-route'=>'/modules/driver-portal/driver-route.php','trip-details'=>'/trip-details.php'];
    return BASE_URL.($map[$key] ?? '/notifications.php').($id ? ($key==='trip-details' ? '?id=' : '#record-').rawurlencode($id) : '');
}
