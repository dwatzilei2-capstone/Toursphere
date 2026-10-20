<?php
// Retired passenger-income metric. RouteThink costs come from route evaluation.
if (!defined('TOURSPHERE_JSON_REQUEST')) define('TOURSPHERE_JSON_REQUEST', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('ai.view');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode(['ok'=>false,'error'=>'Passenger revenue is no longer a RouteThink metric. Generate a route for supported transportation cost estimates.']);
