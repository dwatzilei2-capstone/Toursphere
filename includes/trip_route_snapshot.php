<?php
// Data loader for the existing planner; never generates or changes a route.
function trip_route_snapshot(PDO $pdo, ?array $trip): ?array {
    if (empty($trip['trip_id'])) return null;
    require_once ROOT_PATH . '/includes/route_planner_state.php';
    $state = route_planner_load($pdo, 'trip:'.$trip['trip_id'].':outbound');
    if (!empty($state['state_data']['applied'])) return $state['state_data']['applied'];
    // The trip pointer moves to the return leg. Historical viewing must still
    // find the outbound log even if the planner state is no longer present.
    $q=$pdo->prepare('SELECT route_data_json FROM route_history WHERE trip_id=? ORDER BY generated_date DESC, log_id DESC');
    $q->execute([$trip['trip_id']]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $json) {
    $payload=json_decode((string)$json,true);
    if (!is_array($payload) || ($payload['phase'] ?? 'outbound') !== 'outbound') continue;
    $index=$payload['selectedCandidate']['index'] ?? null;
    if (!is_int($index) || !isset($payload['directions']['routes'][$index])) continue;
    return ['mode'=>$payload['mode'] ?? '', 'data'=>$payload['route'] ?? [],
        'directions'=>$payload['directions'],'evaluation'=>['selectedIndex'=>$index],
        'sampleSelectedRoute'=>!empty($payload['sampleSelectedRoute'])];
    }
    return null;
}
