<?php
require_once ROOT_PATH.'/includes/trip_funding.php';
$_SESSION['fuel_csrf']??=bin2hex(random_bytes(32));
$fuelDefaults=[];
foreach($pdo->query('SELECT id,fuel_type FROM vehicles')->fetchAll() as $v){$p=fuel_current_price($pdo,(string)$v['fuel_type']);$fuelDefaults[$v['id']]=['fuel_type'=>$v['fuel_type'],'price'=>$p['price_per_liter']??null,'price_id'=>$p['id']??null];}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/trip-funding.css?v=<?= filemtime(ROOT_PATH.'/css/trip-funding.css') ?>">
<script>window.TC_FUEL_DEFAULTS=<?= json_encode($fuelDefaults,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;window.TC_FUEL_CONTEXT=<?= json_encode(['vehicle_id'=>$driver_fuel_trip['vehicle_id']??null,'trip_id'=>$driver_fuel_trip['trip_id']??null,'csrf'=>$_SESSION['fuel_csrf']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
<?php $page_scripts=($page_scripts??'').'<script src="'.BASE_URL.'/js/fuel-log.js?v='.filemtime(ROOT_PATH.'/js/fuel-log.js').'"></script>'; ?>
