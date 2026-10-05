<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/bootstrap.php';require_once ROOT_PATH.'/includes/trip_funding.php';
foreach(db()->query("SELECT id FROM trip_funding_requests WHERE status='Pending Finance Approval' AND approval_due_at<=clock_timestamp()")->fetchAll(PDO::FETCH_COLUMN) as $id)funding_mock_response(db(),(int)$id);
echo "Due mock responses processed.\n";
