<?php
define('TOURSPHERE_JSON_REQUEST',true);
require_once __DIR__.'/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function dashboard_reply(array $data,int $status=200): void {
    http_response_code($status);echo json_encode($data,JSON_THROW_ON_ERROR);exit;
}
if(!is_logged_in())dashboard_reply(['error'=>'Please sign in again.'],401);
if(!can('dispatch.view')||has_role(['customer','driver']))dashboard_reply(['error'=>'Schedule access denied.'],403);
try {
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals($_SESSION['dashboard_operations_csrf']??'',(string)($_POST['csrf']??''))||empty($_SESSION['dashboard_operations_csrf']))dashboard_reply(['error'=>'Refresh the dashboard and try again.'],403);
        if(!in_array($_POST['visible']??'', ['0','1'],true))dashboard_reply(['error'=>'Invalid preference.'],422);
        save_fleet_setting('user.'.$current_user['id'].'.dashboard_calendar',$_POST['visible']);
        dashboard_reply(['saved'=>true]);
    }
    if($_SERVER['REQUEST_METHOD']!=='GET')dashboard_reply(['error'=>'Method not allowed.'],405);
    require_once ROOT_PATH.'/includes/dashboard-operations.php';
    $sample=($_GET['source']??'')==='sample';
    if($sample)require_once ROOT_PATH.'/includes/dashboard-operations-samples.php';
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',(string)($_GET['date']??''));
    if(!$date||$date->format('Y-m-d')!==($_GET['date']??'')||(int)$date->format('Y')<1900||(int)$date->format('Y')>2100)dashboard_reply(['error'=>'Choose a valid date between 1900 and 2100.'],422);
    $view=$_GET['view']??'month';
    if(!in_array($view,['day','week','month','year'],true))dashboard_reply(['error'=>'Invalid view.'],422);
    if(($_GET['kind']??'')==='calendar'){
        if($view==='year')dashboard_reply(['error'=>'Invalid calendar view.'],422);
        $start=$view==='month'?$date->modify('first day of this month'):$date;
        if($view!=='day')$start=$start->modify('-'.$start->format('w').' days');
        if($view==='month'){
            $end=$date->modify('first day of next month');
            if($end->format('w')!=='0')$end=$end->modify('+'.(7-(int)$end->format('w')).' days');
        }else $end=$start->modify($view==='week'?'+7 days':'+1 day');
        dashboard_reply($sample?dashboard_sample_calendar($start->format('Y-m-d'),$end->format('Y-m-d')):dashboard_calendar(db(),$start->format('Y-m-d'),$end->format('Y-m-d')));
    }
    if(($_GET['kind']??'')!=='trend'||$view==='week')dashboard_reply(['error'=>'Invalid request.'],422);
    dashboard_reply($sample?dashboard_sample_trend($date,$view):dashboard_trend(db(),$date,$view));
} catch(Throwable $e){
    error_log('Dashboard operations: '.$e->getMessage());
    dashboard_reply(['error'=>'Unable to load operations data. Please retry.'],500);
}
