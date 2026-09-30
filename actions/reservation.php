<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once ROOT_PATH . '/includes/customer_reservations.php';
require_once ROOT_PATH . '/includes/schedules.php';
require_login();
if (!has_role('customer')) {
    http_response_code(403);
    exit('Only customers can create reservations.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/dashboard.php');
}
$return = BASE_URL . '/modules/customer-portal/reservations.php?new=1';

try {
    $pdo = db();

    if (($_POST['action'] ?? '') === 'create') {
        if (!is_string($_POST['csrf'] ?? null) || !isset($_SESSION['customer_reservation_csrf']) || !hash_equals($_SESSION['customer_reservation_csrf'], $_POST['csrf'])) {
            throw new RuntimeException('Your reservation session expired. Refresh the page and try again.');
        }
        $client_name = $current_user['name'];
        $origin      = trim($_POST['origin'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $departure_date = trim($_POST['departure_date'] ?? '');
        $passengerRaw = (string)($_POST['passenger_count'] ?? '');
        $passengers = filter_var($passengerRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $departureScheduleId = filter_var($_POST['departure_schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $tripType = trim($_POST['trip_type'] ?? '');
        $returnDate = trim($_POST['return_date'] ?? '');
        $returnScheduleId = filter_var($_POST['return_schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $supportedTypes = ['One Way', 'Round Trip', 'Day Tour & Transfer', 'Multi-Day Tour', 'Educational Heritage Tour', 'VIP Executive Airport Transfer', 'School Field Trip'];
        if (!$passengers || $passengers > 1000) throw new RuntimeException('Passenger Count: enter a positive whole number.');
        if ($origin === '') throw new RuntimeException('Pickup Location: enter a location.');
        if ($destination === '') throw new RuntimeException('Destination: enter a location.');
        if (!in_array($tripType, $supportedTypes, true)) throw new RuntimeException('Trip Type: choose a supported option.');
        if (!$departureScheduleId) throw new RuntimeException('Departure Schedule: choose an available schedule.');
        $roundTrip = in_array($tripType, ['Round Trip', 'Multi-Day Tour'], true);
        if ($roundTrip && (!$returnScheduleId || $returnDate === '')) throw new RuntimeException('Return Schedule: choose a return date and schedule.');
        if (!$roundTrip) { $returnDate = ''; $returnScheduleId = null; }
        if (trim($_POST['contact_person'] ?? '') === '') throw new RuntimeException('Contact Person: enter a name.');
        if (trim($_POST['contact_phone'] ?? '') === '') throw new RuntimeException('Contact Phone: enter a phone number.');
        if ($client_name === '' || $origin === '' || $destination === '' || $departure_date === '' || $passengers <= 0) {
            redirect_with_toast($return, 'Please fill in the required reservation fields.', 'danger');
        }

        // The browser estimate is display-only. Re-resolve the road route and
        // recalculate from the current database configuration at submission.
        $fare = customer_calculate_fare($origin, $destination, (int)$passengers);

        $pdo->beginTransaction();
        $departureInstance = schedule_lock_or_create_instance($pdo, (int)$departureScheduleId, $departure_date);
        $departureDemand = schedule_assert_capacity($pdo, $departureInstance, (int)$passengers);
        $departureTime = substr((string)$departureInstance['departure_time_snapshot'], 0, 5);
        $departureStamp = new DateTimeImmutable($departure_date . ' ' . $departureTime, new DateTimeZone(company_timezone()));
        $returnInstance = null; $returnTime = '';
        if ($roundTrip) {
            $returnInstance = schedule_lock_or_create_instance($pdo, (int)$returnScheduleId, $returnDate);
            $returnDemand = schedule_assert_capacity($pdo, $returnInstance, (int)$passengers);
            $returnTime = substr((string)$returnInstance['departure_time_snapshot'], 0, 5);
            $returnStamp = new DateTimeImmutable($returnDate . ' ' . $returnTime, new DateTimeZone(company_timezone()));
            if ($returnStamp <= $departureStamp) throw new RuntimeException('Return Date and Schedule must be after the departure schedule.');
        } else $returnDemand = 0;
        $requiredVehicle = customer_required_vehicle_type($pdo, max($departureDemand, $returnDemand));
        if (!$requiredVehicle) throw new RuntimeException('The total scheduled passenger demand exceeds available transportation capacity.');
        $vehicleType = $requiredVehicle['type'];
        $rid = next_sequential_id($pdo, 'reservations', 'id', 'RES-' . date('Y') . '-');
        $stmt = $pdo->prepare(
            "INSERT INTO reservations (id, client_name, contact_person, contact_phone, passenger_count,
                                       origin, destination, departure_date, departure_time, return_date,
                                       vehicle_requested, status, trip_type, notes, customer_id, return_time,
                                       base_fare_used, rate_per_km_used, route_distance_km, distance_charge,
                                       fare_per_person, total_booking_fare, fare_status, fare_calculated_at, estimated_cost,
                                       required_vehicle_type_id, required_capacity, departure_schedule_instance_id,
                                       return_schedule_instance_id, departure_schedule_name, return_schedule_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Approval', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Estimated', CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $rid,
            $client_name,
            trim($_POST['contact_person'] ?? ''),
            trim($_POST['contact_phone'] ?? ''),
            $passengers,
            $origin,
            $destination,
            $departure_date,
            $departureTime,
            $returnDate !== '' ? $returnDate : null,
            $vehicleType,
            $tripType,
            trim($_POST['notes'] ?? ''),
            $current_user['id'],
            $returnTime !== '' ? $returnTime : null,
            $fare['base_fare'],
            $fare['rate_per_km'],
            $fare['route_distance_km'],
            $fare['distance_charge'],
            $fare['fare_per_person'],
            $fare['total_booking_fare'],
            $fare['total_booking_fare'],
            $requiredVehicle['vehicle_type_id'],
            $requiredVehicle['capacity'],
            $departureInstance['id'],
            $returnInstance['id'] ?? null,
            $departureInstance['schedule_name_snapshot'],
            $returnInstance['schedule_name_snapshot'] ?? null,
        ]);
        $pdo->prepare("INSERT INTO reservation_events (reservation_id, actor_id, status) VALUES (?, ?, 'Pending Approval')")->execute([$rid, $current_user['id']]);
        schedule_refresh_required_vehicle($pdo, (int)$departureInstance['id']);
        if ($returnInstance && (int)$returnInstance['id'] !== (int)$departureInstance['id']) schedule_refresh_required_vehicle($pdo, (int)$returnInstance['id']);
        $pdo->commit();
        redirect_with_toast(BASE_URL . '/modules/customer-portal/reservations.php', 'Reservation ' . $rid . ' submitted for approval.', 'success');
    }

    redirect_with_toast($return, 'Unknown reservation action.', 'danger');
} catch (Throwable $ex) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    redirect_with_toast($return, 'Could not save the reservation: ' . $ex->getMessage(), 'danger');
}
