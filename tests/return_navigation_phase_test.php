<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/includes/route_planner_state.php';
$cases = [
 ['outbound', ['phase'=>'outbound'], 1, true],
 ['return', ['phase'=>'outbound'], 1, false],
 ['return', ['phase'=>'return'], 1, true],
 ['return', ['phase'=>'return'], 0, false],
 ['return', [], 1, false],
 ['outbound', [], 1, true],
 ['return', null, 1, false],
];
foreach ($cases as [$phase, $route, $active, $expected]) {
 if (route_planner_navigation_matches_phase(['navigation_active'=>$active], $route, $phase) !== $expected) {
  throw new RuntimeException('Incorrect navigation lock for '.$phase);
 }
}
echo "7 navigation phase checks passed.\n";
