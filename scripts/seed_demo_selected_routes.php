<?php
// Road-following sample geometry for the labelled analytics demo batch only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';
$pdo = db();
// Terminal sample coordinates; intermediate coordinates are never used for routing.
$paths = [
    'Manila|Tagaytay'=>[[14.5995,120.9842],[14.1153,120.9621]],
    'Quezon City|Baguio'=>[[14.676,121.0437],[16.4023,120.596]],
    'Navotas|Clark'=>[[14.6667,120.9417],[15.208,120.541]],
    'Pasay|Batangas'=>[[14.5378,121.0014],[13.7565,121.0583]],
    'Makati|Subic'=>[[14.5547,121.0244],[14.827,120.282]],
    'Manila|Antipolo'=>[[14.5995,120.9842],[14.5869,121.1756]],
];
$roadRoutes = [];
function demo_fetch_road_route(array $start, array $end): array {
    $url='https://routing.openstreetmap.de/routed-car/route/v1/driving/'.$start[1].','.$start[0].';'.$end[1].','.$end[0].'?overview=full&geometries=geojson&steps=true&alternatives=false';
    for ($attempt=0;$attempt<3;$attempt++) {
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>40,CURLOPT_PROXY=>'',CURLOPT_USERAGENT=>'TourSphere demo route repair']);
        $raw=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        $response=json_decode((string)$raw,true);
        if ($http===200 && ($response['code']??'')==='Ok' && count($response['routes'][0]['geometry']['coordinates']??[])>=20) return $response;
    }
    throw new RuntimeException('Road routing failed. No sample data committed.');
}
try {
    $pdo->beginTransaction();
    $rows = $pdo->query("SELECT h.*,t.id AS linked_trip_id FROM route_history h JOIN trips t ON t.route_history_id=h.log_id
        JOIN reservations r ON r.id=t.reservation_id WHERE t.id LIKE 'TRP-DEMO-%' AND r.id LIKE 'RES-DEMO-%'
        AND h.log_id LIKE 'LOG-DEMO-%' AND t.completion_notes LIKE 'DEMO_ANALYTICS:%'
        AND r.notes LIKE 'DEMO_ANALYTICS:%' FOR UPDATE OF h")->fetchAll();
    if (!$rows) throw new RuntimeException('No labelled demo trips found.');
    $backupDir = ROOT_PATH . '/storage/private/testing-backups';
    if (!is_dir($backupDir)) mkdir($backupDir,0700,true);
    file_put_contents($backupDir.'/.htaccess', "Require all denied\n");
    $backup = $backupDir.'/demo-selected-routes-'.date('Ymd-His').'.json';
    if (file_put_contents($backup,json_encode($rows,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false) throw new RuntimeException('Backup failed.');
    $updated = 0;
    foreach ($rows as $row) {
        $payload = json_decode($row['route_data_json'] ?? '',true);
        if (empty($payload['demo'])) throw new RuntimeException('Refusing to alter non-demo history.');
        if (!empty($payload['directions']) && empty($payload['sampleSelectedRoute'])) throw new RuntimeException('Refusing to alter a Driver-selected route.');
        $points = $paths[$row['origin'].'|'.$row['destination']] ?? null;
        if (!$points) throw new RuntimeException('No sample path for this demo terminal pair.');
        $pair=$row['origin'].'|'.$row['destination'];
        if (!isset($roadRoutes[$pair])) {
            $roadRoutes[$pair]=demo_fetch_road_route($points[0],$points[array_key_last($points)]);
            echo 'Retrieved road route: '.$pair.' ('.count($roadRoutes[$pair]['routes'][0]['geometry']['coordinates'])." points)\n";
        }
        $road=$roadRoutes[$pair]['routes'][0];
        $path = array_map(fn($p)=>['lat'=>$p[1],'lng'=>$p[0]],$road['geometry']['coordinates']);
        $distance = round($road['distance']/1000,2);
        $duration = (int)ceil($road['duration']/60);
        $mode = 'fastest';
        $reason = 'DEMO: road-following driving route from OSRM / OpenStreetMap, saved for map testing. Travel time excludes live traffic. This is not a historical Driver GPS track or a live ROUTETHINK recommendation.';
        $oldDistance=(float)($payload['route']['distanceKm']??$payload['distanceKm']??0);
        $oldFuel=(float)($payload['route']['fuelEstimateLiters']??$row['predicted_fuel_liters']);
        $fuel=$oldDistance>0?round($oldFuel/$oldDistance*$distance,2):null;
        $candidate = ['index'=>0,'distanceKm'=>$distance,'durationMins'=>$duration,'fuelEstimateLiters'=>$fuel];
        $leg = ['start_address'=>$row['origin'],'end_address'=>$row['destination'],'start_location'=>$path[0],
            'end_location'=>$path[array_key_last($path)],'distance'=>['value'=>$distance*1000,'text'=>$distance.' km'],
            'duration'=>['value'=>$duration*60,'text'=>$duration.' min']];
        $steps=[];
        foreach ($road['legs'][0]['steps']??[] as $step) {
            $stepPath=array_map(fn($p)=>['lat'=>$p[1],'lng'=>$p[0]],$step['geometry']['coordinates']??[]);
            if ($stepPath) $steps[]=['path'=>$stepPath,'start_location'=>$stepPath[0],'end_location'=>$stepPath[array_key_last($stepPath)],'distance'=>['value'=>$step['distance']],'duration'=>['value'=>$step['duration']]];
        }
        $leg['steps']=$steps;
        $payload = array_replace($payload,['sampleSelectedRoute'=>true,'routeId'=>'demo-road:'.$row['linked_trip_id'],'phase'=>'outbound','mode'=>$mode,
            'geometrySource'=>'OSRM / OpenStreetMap','geometryGeneratedAt'=>date(DATE_ATOM),'distanceKm'=>$distance,
            'route'=>['mode'=>$mode,'distanceKm'=>$distance,'durationMins'=>$duration,'fuelEstimateLiters'=>$candidate['fuelEstimateLiters'],'reason'=>$reason,'modelVersion'=>'demo-synthetic'],
            'selectedCandidate'=>$candidate,'directions'=>['routes'=>[['summary'=>'DEMO saved road route','overview_path'=>$path,'legs'=>[$leg]]]],
            'destinationLocation'=>$leg['end_location']]);
        $pdo->prepare('UPDATE route_history SET route_data_json=?,selected_mode=? WHERE log_id=? AND trip_id=?')->execute([
            json_encode($payload,JSON_THROW_ON_ERROR),'Fastest Express Corridor',$row['log_id'],$row['linked_trip_id']]);
        $updated++;
    }
    $pdo->commit();
    echo "Saved sample selected routes for $updated demo trips. Real trips were not changed.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1);
}
