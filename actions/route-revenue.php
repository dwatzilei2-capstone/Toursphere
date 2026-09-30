<?php

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('ai.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function route_revenue_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$isDriver = ($current_user['role_code'] ?? '') === 'driver';
$isAdmin = has_role('fleet_admin');
if (!$isDriver && !$isAdmin) route_revenue_response(403, ['ok' => false, 'error' => 'Revenue is not available for this account.']);

$pdo = db();
$eligibleStatuses = "'Approved','Assigned','Confirmed','Dispatched','In Transit','Returning to Depot','Completed'";

if ($isDriver) {
    $tripId = trim((string)($_GET['trip_id'] ?? ''));
    if ($tripId === '') route_revenue_response(400, ['ok' => false, 'error' => 'An assigned trip is required.']);

    $tripStmt = $pdo->prepare(
        "SELECT t.id, t.reservation_id, t.origin, t.destination, t.vehicle_id, t.driver_id,
                anchor.departure_schedule_instance_id,
                COALESCE(t.scheduled_departure::date, anchor.departure_date) AS trip_date
           FROM drivers d
           JOIN trips t ON t.driver_id=d.id
           JOIN reservations anchor ON anchor.id=t.reservation_id
          WHERE d.user_id=? AND t.id=?
            AND t.status IN ('Scheduled','Assigned','Dispatched','In Transit','Returning to Depot')
            AND t.vehicle_id IS NOT NULL"
    );
    $tripStmt->execute([$current_user['id'], $tripId]);
    $trip = $tripStmt->fetch();
    if (!$trip) route_revenue_response(404, ['ok' => false, 'error' => 'Assigned trip not found.']);

    $revenueStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(r.total_booking_fare),0) AS revenue,
                COALESCE(SUM(r.passenger_count),0) AS passengers,
                COUNT(*) AS reservation_count
           FROM reservations r
          WHERE r.status IN ($eligibleStatuses)
            AND r.fare_status='Confirmed'
            AND r.total_booking_fare IS NOT NULL
            AND (
              r.id=? OR (
                ?::bigint IS NOT NULL
                AND r.departure_schedule_instance_id=?::bigint
                AND r.assigned_vehicle_id=?
                AND r.assigned_driver_id=?
                AND r.departure_date=?::date
                AND LOWER(BTRIM(r.origin))=LOWER(BTRIM(?))
                AND LOWER(BTRIM(r.destination))=LOWER(BTRIM(?))
              )
            )"
    );
    $revenueStmt->execute([
        $trip['reservation_id'],
        $trip['departure_schedule_instance_id'], $trip['departure_schedule_instance_id'],
        $trip['vehicle_id'], $trip['driver_id'], $trip['trip_date'],
        $trip['origin'], $trip['destination'],
    ]);
    $summary = $revenueStmt->fetch();
    $reservationCount = (int)$summary['reservation_count'];
    route_revenue_response(200, [
        'ok' => true,
        'hasRevenue' => $reservationCount > 0,
        'revenue' => (float)$summary['revenue'],
        'passengers' => (int)$summary['passengers'],
        'reservationCount' => $reservationCount,
        'tripCount' => $reservationCount > 0 ? 1 : 0,
        'tripId' => $trip['id'],
        'origin' => $trip['origin'],
        'destination' => $trip['destination'],
        'date' => $trip['trip_date'],
    ]);
}

$origin = trim((string)($_GET['origin'] ?? ''));
$destination = trim((string)($_GET['destination'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$dateErrors = DateTimeImmutable::getLastErrors();
if ($origin === '' || $destination === '' || strlen($origin) > 200 || strlen($destination) > 200
    || !$parsedDate || $parsedDate->format('Y-m-d') !== $date
    || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))) {
    route_revenue_response(422, ['ok' => false, 'error' => 'Select a valid route and date.']);
}

$revenueStmt = $pdo->prepare(
    "WITH scoped_trips AS (
       SELECT t.id, t.reservation_id, t.vehicle_id, t.driver_id,
              anchor.departure_schedule_instance_id,
              COALESCE(t.scheduled_departure::date, anchor.departure_date) AS trip_date,
              t.origin, t.destination
         FROM trips t
         JOIN reservations anchor ON anchor.id=t.reservation_id
        WHERE t.status IN ('Scheduled','Assigned','Dispatched','In Transit','Returning to Depot','Completed')
          AND t.vehicle_id IS NOT NULL AND t.driver_id IS NOT NULL
          AND COALESCE(t.scheduled_departure::date, anchor.departure_date)=?::date
          AND LOWER(BTRIM(t.origin))=LOWER(BTRIM(?))
          AND LOWER(BTRIM(t.destination))=LOWER(BTRIM(?))
          AND anchor.status IN ($eligibleStatuses)
          AND anchor.fare_status='Confirmed'
          AND anchor.total_booking_fare IS NOT NULL
     ), eligible_pairs AS (
       SELECT DISTINCT st.id AS trip_id, r.id AS reservation_id,
              r.passenger_count, r.total_booking_fare
         FROM scoped_trips st
         JOIN reservations r ON (
              r.id=st.reservation_id OR (
                st.departure_schedule_instance_id IS NOT NULL
                AND r.departure_schedule_instance_id=st.departure_schedule_instance_id
                AND r.assigned_vehicle_id=st.vehicle_id
                AND r.assigned_driver_id=st.driver_id
                AND r.departure_date=st.trip_date
                AND LOWER(BTRIM(r.origin))=LOWER(BTRIM(st.origin))
                AND LOWER(BTRIM(r.destination))=LOWER(BTRIM(st.destination))
              )
         )
        WHERE r.status IN ($eligibleStatuses)
          AND r.fare_status='Confirmed'
          AND r.total_booking_fare IS NOT NULL
     ), unique_reservations AS (
       SELECT DISTINCT reservation_id, passenger_count, total_booking_fare
         FROM eligible_pairs
     )
     SELECT COALESCE((SELECT SUM(total_booking_fare) FROM unique_reservations),0) AS revenue,
            COALESCE((SELECT SUM(passenger_count) FROM unique_reservations),0) AS passengers,
            (SELECT COUNT(*) FROM unique_reservations) AS reservation_count,
            (SELECT COUNT(DISTINCT trip_id) FROM eligible_pairs) AS trip_count"
);
$revenueStmt->execute([$date, $origin, $destination]);
$summary = $revenueStmt->fetch();
$reservationCount = (int)$summary['reservation_count'];
route_revenue_response(200, [
    'ok' => true,
    'hasRevenue' => $reservationCount > 0,
    'revenue' => (float)$summary['revenue'],
    'passengers' => (int)$summary['passengers'],
    'reservationCount' => $reservationCount,
    'tripCount' => (int)$summary['trip_count'],
    'origin' => $origin,
    'destination' => $destination,
    'date' => $date,
]);