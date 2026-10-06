<?php
// Data loader for the existing planner; never generates or changes a route.
function trip_route_snapshot(PDO $pdo, ?array $trip): ?array {
    if (empty($trip['trip_id'])) return null;
    require_once ROOT_PATH . '/includes/route_planner_state.php';
    $state = route_planner_load($pdo, 'trip:'.$trip['trip_id'].':outbound');
    if (!empty($state['state_data']['applied'])) return $state['state_data']['applied'];
    if (empty($trip['route_history_id'])) return null;
    $q=$pdo->prepare('SELECT route_data_json FROM route_history WHERE log_id=? AND trip_id=?');
    $q->execute([$trip['route_history_id'],$trip['trip_id']]);
    $payload=json_decode((string)($q->fetchColumn() ?: ''),true);
    if (!is_array($payload) || ($payload['phase'] ?? 'outbound') !== 'outbound') return null;
    $index=$payload['selectedCandidate']['index'] ?? null;
    if (!is_int($index) || !isset($payload['directions']['routes'][$index])) return null;
    return ['mode'=>$payload['mode'] ?? '', 'data'=>$payload['route'] ?? [],
        'directions'=>$payload['directions'],'evaluation'=>['selectedIndex'=>$index],
        'sampleSelectedRoute'=>!empty($payload['sampleSelectedRoute'])];
}
