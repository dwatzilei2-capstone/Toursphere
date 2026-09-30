<?php
require dirname(__DIR__).'/includes/bootstrap.php';
$p=db(); $p->exec(file_get_contents(ROOT_PATH.'/database/migrations/2026_09_30_notifications.sql'));
function check($condition,$message) { if (!$condition) throw new RuntimeException($message); echo "PASS $message\n"; }
$p->beginTransaction();
try {
 $customer=$p->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='customer' AND u.status='Active' LIMIT 1")->fetchColumn();
 $driver=$p->query("SELECT d.id,d.user_id FROM drivers d JOIN users u ON u.id=d.user_id WHERE u.status='Active' LIMIT 1")->fetch();
 check($customer && $driver,'Active customer and linked driver exist');
 $before=(int)$p->query('SELECT COALESCE(MAX(id),0) FROM notifications')->fetchColumn();
 $id='NOTIF-'.bin2hex(random_bytes(4));
 $p->prepare("INSERT INTO reservations(id,client_name,passenger_count,origin,destination,departure_date,status,customer_id) VALUES (?,'Notification verification',1,'Origin','Destination',CURRENT_DATE,'Pending Approval',?)")->execute([$id,$customer]);
 $q=$p->prepare("SELECT count(*) FROM notifications n JOIN users u ON u.id=n.user_id JOIN roles r ON r.id=u.role_id WHERE n.id>? AND r.code IN ('fleet_admin','dispatcher')"); $q->execute([$before]); check($q->fetchColumn()>0,'Customer reservation reaches operations');
 $p->prepare("UPDATE reservations SET status='Approved' WHERE id=?")->execute([$id]);
 $q=$p->prepare('SELECT count(*) FROM notifications WHERE id>? AND user_id=?'); $q->execute([$before,$customer]); check($q->fetchColumn()==2,'Customer receives creation and approval');
 $tid='NT-'.bin2hex(random_bytes(4));
 $p->prepare("INSERT INTO trips(id,reservation_id,origin,destination,status,driver_id) VALUES (?,?,'Origin','Destination','Dispatched',?)")->execute([$tid,$id,$driver['id']]);
 $q->execute([$before,$driver['user_id']]); check($q->fetchColumn()==1,'Direct dispatch notifies assigned driver exactly once');
 $max=(int)$p->query('SELECT MAX(id) FROM notifications')->fetchColumn();
 $p->prepare('UPDATE trips SET progress_pct=1 WHERE id=?')->execute([$tid]); check((int)$p->query('SELECT MAX(id) FROM notifications')->fetchColumn()==$max,'Telemetry creates no notification spam');
 $p->prepare("UPDATE trips SET status='Completed' WHERE id=?")->execute([$tid]); $q->execute([$before,$driver['user_id']]); check($q->fetchColumn()==2,'Trip completion notifies driver');
 $stmt=$p->prepare('SELECT id FROM notifications WHERE id>? AND user_id=? LIMIT 1'); $stmt->execute([$before,$customer]); $nid=$stmt->fetchColumn();
 $p->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([$nid,$driver['user_id']]);
 $stmt=$p->prepare('SELECT is_read FROM notifications WHERE id=?'); $stmt->execute([$nid]); check($stmt->fetchColumn()==0,'Other users cannot mark customer notifications read');
 $vehicle=$p->query('SELECT id FROM vehicles LIMIT 1')->fetchColumn();
 check((bool)$vehicle,'Vehicle exists for operational event verification');
 $wo='NW-'.bin2hex(random_bytes(4));
 $p->prepare("INSERT INTO maintenance_orders(id,vehicle_id,service_type,priority,scheduled_date,status) VALUES (?,?,'Verification','Critical',CURRENT_DATE,'Scheduled')")->execute([$wo,$vehicle]);
 $q=$p->prepare('SELECT count(*) FROM notifications WHERE id>? AND target=?'); $q->execute([$before,'maintenance:'.$wo]); check($q->fetchColumn()>0,'Maintenance creation reaches operations');
 $p->prepare("UPDATE maintenance_orders SET status='Completed' WHERE id=?")->execute([$wo]); $q->execute([$before,'maintenance:'.$wo]); check($q->fetchColumn()>1,'Maintenance completion reaches operations');
 $fuel='NF-'.bin2hex(random_bytes(4));
 $p->prepare('INSERT INTO fuel_transactions(id,vehicle_id,driver_id,transaction_date,liters,price_per_liter,total_cost) VALUES (?,?,?,NOW(),1,1,1)')->execute([$fuel,$vehicle,$driver['id']]);
 $q->execute([$before,'fuel-transactions:'.$fuel]); check($q->fetchColumn()>0,'Fuel recording reaches operations and driver');
 $p->prepare('INSERT INTO driver_ratings(trip_id,reservation_id,customer_id,driver_id,stars) VALUES (?,?,?,?,5)')->execute([$tid,$id,$customer,$driver['id']]);
 $q=$p->prepare("SELECT count(*) FROM notifications WHERE id>? AND user_id=? AND title='Driver Rating Received'"); $q->execute([$before,$driver['user_id']]); check($q->fetchColumn()==1,'Customer rating reaches rated driver');
 check($p->query('SELECT COUNT(*) FROM notifications WHERE user_id IS NULL')->fetchColumn()==0,'No shared broadcast inbox records');
 $p->rollBack(); $stmt=$p->prepare('SELECT count(*) FROM notifications WHERE id>?'); $stmt->execute([$before]); check($stmt->fetchColumn()==0,'Rolled back events leave no notifications');
} catch(Throwable $e) { if($p->inTransaction())$p->rollBack(); throw $e; }
