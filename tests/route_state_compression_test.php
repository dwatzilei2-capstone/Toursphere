<?php
require dirname(__DIR__).'/includes/route_planner_state.php';
$json=json_encode(['directions'=>['path'=>array_fill(0,20000,['lat'=>14.6,'lng'=>121.1])]]);
$compressed=base64_encode(gzencode($json));
if(route_planner_decode_state($compressed,'gzip-base64')!==$json)throw new RuntimeException('Compressed state did not round-trip.');
if(route_planner_decode_state($json)!==$json)throw new RuntimeException('Legacy state changed.');
foreach([['invalid','gzip-base64'],[base64_encode(gzencode(str_repeat('x',3000001))),'gzip-base64'],['{}','unknown']] as [$payload,$encoding]){
 try{route_planner_decode_state($payload,$encoding);throw new RuntimeException('Invalid state accepted.');}catch(DomainException $e){}
}
echo 'Compression checks passed; '.strlen($json).' bytes reduced to '.strlen($compressed)." bytes.\n";
