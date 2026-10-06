<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
db()->exec(file_get_contents(ROOT_PATH.'/database/migrations/2026_10_06_route_planner_states.sql'));echo "Route Planner persistence migration applied.\n";
