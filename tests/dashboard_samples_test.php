<?php
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../includes/bootstrap.php';
require_once ROOT_PATH.'/includes/dashboard-operations-samples.php';
$records=dashboard_sample_deliveries();
if(count($records)!==20||count(array_unique(array_column($records,'reservation_id')))!==20)throw new RuntimeException('Expected 20 unique sample deliveries');
$month=dashboard_sample_trend(new DateTimeImmutable('today'),'month');
foreach(['day','month','year'] as $period){
    $data=dashboard_sample_trend(new DateTimeImmutable('today'),$period);
    foreach(['completed','on_time','scheduled','cancelled'] as $metric){
        if(count(array_unique(array_column($data['buckets'],$metric)))<($period==='day'?2:3))throw new RuntimeException('Insufficient variation: '.$period.' '.$metric);
    }
    foreach($data['buckets'] as $bucket)if($bucket['on_time']>$bucket['completed'])throw new RuntimeException('Invalid on-time subset');
}
if($month['sample_count']<500)throw new RuntimeException('Expected dense monthly data');
$values=array_column($month['buckets'],'completed');
if(max($values)-min($values)<20)throw new RuntimeException('Expected visible peaks and dips');
$first=new DateTimeImmutable('first day of this month');
if(count(dashboard_sample_calendar($first->format('Y-m-d'),$first->modify('+1 month')->format('Y-m-d'))['events'])!==20)throw new RuntimeException('Calendar count incorrect');
echo "PASS: 20 calendar examples preserved; dense Day/Month/Year trends vary; peaks exceed troughs by at least 20; on-time remains a completed subset. Monthly examples: ".$month['sample_count']."\n";
