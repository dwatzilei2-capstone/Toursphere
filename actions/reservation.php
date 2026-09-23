<?php
 
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('dispatch.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(BASE_URL . '/dashboard.php');
}
$return = $_POST['return'] ?? (BASE_URL . '/modules/vehicle-reservation-dispatch/reservations.php');

try {
    $pdo = db();

    if (($_POST['action'] ?? '') === 'create') {
        $client_name = trim($_POST['client_name'] ?? '');
        $origin      = trim($_POST['origin'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $departure_date = trim($_POST['departure_date'] ?? '');
        $passengers  = (int)($_POST['passenger_count'] ?? 0);

        if (!reservation_notice_valid($departure_date, trim($_POST['departure_time'] ?? '08:00'))) {
            redirect_with_toast($return, 'This reservation does not meet the minimum booking notice.', 'danger');
        }

        if ($client_name === '' || $origin === '' || $destination === '' || $departure_date === '' || $passengers <= 0) {
            redirect_with_toast($return, 'Please fill in the required reservation fields.', 'danger');
        }

        $rid = next_sequential_id($pdo, 'reservations', 'id', 'RES-2026-');
        $stmt = $pdo->prepare(
            'INSERT INTO reservations (id, client_name, contact_person, contact_phone, passenger_count,
                                       origin, destination, departure_date, departure_time, return_date,
                                       vehicle_requested, status, trip_type, notes, estimated_cost)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Pending\', ?, ?, ?)'
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
            trim($_POST['departure_time'] ?? '08:00 AM'),
            !empty($_POST['return_date']) ? $_POST['return_date'] : null,
            trim($_POST['vehicle_requested'] ?? ''),
            trim($_POST['trip_type'] ?? 'Day Tour & Transfer'),
            trim($_POST['notes'] ?? ''),
            isset($_POST['estimated_cost']) && $_POST['estimated_cost'] !== '' ? (float)$_POST['estimated_cost'] : null,
        ]);

        redirect_with_toast($return, 'Reservation ' . $rid . ' created for ' . $client_name . ' (awaiting dispatch).', 'success');
    }

    redirect_with_toast($return, 'Unknown reservation action.', 'danger');
} catch (Exception $ex) {
    redirect_with_toast($return, 'Could not save the reservation: ' . $ex->getMessage(), 'danger');
}
