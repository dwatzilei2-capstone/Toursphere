<?php
function route_planner_decode_state(string $payload,string $encoding=''): string {
 if(strlen($payload)>4000000)throw new DomainException('Route state exceeds the permitted size.');
 if($encoding==='gzip-base64'){
  $bytes=base64_decode($payload,true);
  if($bytes===false)throw new DomainException('Invalid compressed route state.');
  $decoded=@gzdecode($bytes,3000001);
  if($decoded===false)throw new DomainException('Invalid or oversized compressed route state.');
  $payload=$decoded;
 }elseif($encoding!=='')throw new DomainException('Unsupported route state encoding.');
 if(strlen($payload)>3000000)throw new DomainException('Route state exceeds the permitted size.');
 return $payload;
}
function route_planner_scope(PDO $pdo,string $tripId,string $phase): array {
 if(!can('ai.view'))throw new DomainException('Route Planner access denied.');
 if($tripId!=='' && !has_role('driver') && !can('dispatch.view'))throw new DomainException('Trip route access denied.');
 if(!in_array($phase,['outbound','return'],true))throw new DomainException('Invalid route phase.');
 $trip=null;
 if($tripId!==''){
  $q=$pdo->prepare('SELECT t.*,d.user_id driver_user_id FROM trips t LEFT JOIN drivers d ON d.id=t.driver_id WHERE t.id=?');$q->execute([$tripId]);$trip=$q->fetch();
  if(!$trip || (has_role('driver') && (string)$trip['driver_user_id']!==(string)current_user()['id']))throw new DomainException('Trip not found or not assigned to you.');
 }elseif(has_role('driver'))throw new DomainException('An assigned trip is required.');
 return ['key'=>$trip?'trip:'.$tripId.':'.$phase:'account:'.current_user()['id'].':'.$phase,'trip'=>$trip,'phase'=>$phase];
}
function route_planner_load(PDO $pdo,string $key): ?array {
 $q=$pdo->prepare('SELECT * FROM route_planner_states WHERE scope_key=?');$q->execute([$key]);$row=$q->fetch();
 if(!$row)return null;$row['state_data']=json_decode($row['state_data'],true);return $row;
}
function route_planner_validate(array $state): void {
 $modes=['balanced','fastest','fuelEfficient','shortest'];
 if(!isset($state['inputs'],$state['directions']['routes'],$state['selected']) || !is_array($state['directions']['routes']) || !$state['directions']['routes'])throw new DomainException('Complete generated route geometry is required.');
 foreach(['origin','destination','vehicleId'] as $field){if(!is_string($state['inputs'][$field]??null)||trim($state['inputs'][$field])==='')throw new DomainException('Complete route inputs are required.');}
 if(!is_array($state['inputs']['waypoints']??null))throw new DomainException('Invalid route stops.');
 foreach(['selected','applied'] as $key){
  $route=$state[$key]??null;if(!$route){if($key==='applied')continue;throw new DomainException('Select a generated route.');}
  if(!in_array($route['mode']??'',$modes,true)||!is_string($route['routeId']??null)||$route['routeId']===''||strlen($route['routeId'])>150 || ($route['data']['routeId']??'')!==$route['routeId'] || ($route['data']['mode']??'')!==$route['mode'])throw new DomainException('Invalid selected route identity.');
  $index=$route['evaluation']['selectedIndex']??null;$directions=$route['directions']??$state['directions'];
  if(!is_int($index)||!isset($directions['routes'][$index]['legs'])||!is_array($directions['routes'][$index]['legs']))throw new DomainException('Selected route geometry does not match its candidate.');
  $meters=0;foreach($directions['routes'][$index]['legs'] as $leg){$meters+=(float)($leg['distance']['value']??0);}
  $distance=(float)($route['data']['distanceKm']??0);
  if($meters<=0||!is_finite($distance)||abs($meters/1000-$distance)>0.05)throw new DomainException('Route distance does not match its own geometry.');
  $geometry=$directions['routes'][$index];
  if(empty($geometry['overview_path']) && empty($geometry['overview_polyline']))throw new DomainException('The route path is missing. Generate a complete route.');
  foreach($geometry['legs'] as $leg){
   foreach(['start_location','end_location'] as $field){$point=$leg[$field]??[];if(!is_numeric($point['lat']??null)||!is_numeric($point['lng']??null)||abs((float)$point['lat'])>90||abs((float)$point['lng'])>180)throw new DomainException('The route has invalid navigation coordinates.');}
  }
  $candidate=$route['evaluation']['selectedCandidate']??[];
  foreach(['durationMins','fuelEstimateLiters'] as $field){
   $value=$route['data'][$field]??null;$expected=$candidate[$field]??null;
   if($value===null && $expected===null)continue;
   if(!is_numeric($value)||!is_numeric($expected)||!is_finite((float)$value)||(float)$value<0||abs((float)$value-(float)$expected)>0.0001)throw new DomainException('Route estimates do not match the selected candidate.');
  }
  if(($route['evaluation']['mode']??$route['mode'])!==$route['mode'] || (int)($route['evaluation']['selectedCandidate']['index']??-1)!==$index)throw new DomainException('Route metrics belong to another mode or candidate.');
 }
}
