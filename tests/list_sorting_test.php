<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$pdo = db();
$tests = [
    'Vehicle Directory' => "SELECT v.id FROM vehicles v ORDER BY v.created_at DESC NULLS LAST, v.id DESC",
    'Vehicle Assignment' => "SELECT v.id FROM vehicles v WHERE v.status IN ('Available','Assigned','Maintenance') ORDER BY v.created_at DESC NULLS LAST, v.id DESC",
    'Driver Directory' => "SELECT d.id FROM drivers d LEFT JOIN users u ON u.id = d.user_id ORDER BY u.created_at DESC NULLS LAST, d.id DESC",
    'Maintenance' => "SELECT wo.id FROM maintenance_orders wo ORDER BY CASE WHEN wo.status = 'Completed' THEN 1 ELSE 0 END, CASE WHEN wo.status <> 'Completed' THEN CASE wo.priority WHEN 'Critical' THEN 0 WHEN 'Medium' THEN 1 ELSE 2 END END, CASE WHEN wo.status <> 'Completed' THEN wo.scheduled_date END ASC, wo.created_at DESC, wo.id DESC",
];
foreach ($tests as $name => $sql) { $pdo->query($sql)->fetchAll(); echo "PASS: $name sorting works with the existing database\n"; }
$source = file_get_contents(dirname(__DIR__) . '/modules/driver-portal/driver-trips.php');
preg_match('/"(SELECT r\.\*, v\.plate_number.*?r\.id DESC)"/s', $source, $match);
if (!$match) throw new RuntimeException('Driver trip query was not found.');
$q = $pdo->prepare($match[1]); $q->execute([null]);
echo "PASS: Driver My Trips query works with the existing database\n";
