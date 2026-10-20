<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/includes/bootstrap.php';
require ROOT_PATH.'/includes/trip_performance.php';
require ROOT_PATH.'/includes/trip_performance_presentation.php';
$stored=['status'=>'Submitted','overall_score'=>80,'source_id'=>'INTERNAL-ID','source_table'=>'internal_table','sha256'=>'INTERNAL-HASH','snapshot'=>['secret'=>'TECHNICAL'],'scores'=>json_encode(['on_time'=>['score'=>80,'notes'=>'<script>unsafe</script>','delivery_outcome'=>'on_time']]),'weights'=>json_encode(['on_time'=>25])];
$facts=performance_display_facts(json_encode($stored));
if(($facts['Status']??null)!=='Submitted'||($facts['On-Time Delivery']??null)!=='80 / 100'||($facts['Warehouse assessment']??null)!=='On time')throw new RuntimeException('Stored evaluation facts missing.');
ob_start();performance_render_facts($facts);$output=ob_get_clean();
foreach(['INTERNAL-ID','internal_table','INTERNAL-HASH','TECHNICAL','<script>','source_id','sha256'] as $hidden)if(str_contains($output,$hidden))throw new RuntimeException('Technical or unsafe audit content exposed.');
if(!str_contains($output,'&lt;script&gt;'))throw new RuntimeException('Stored explanation must remain readable and escaped.');
if(performance_display_facts(null)!==[])throw new RuntimeException('Missing facts must not be invented.');
echo "11 audit presentation checks passed; stored facts preserved, technical payloads omitted, text escaped.\n";
