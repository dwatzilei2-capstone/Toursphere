<?php
/** One authorized, immutable dataset is shared by preview and every export format. */
require_once __DIR__.'/cost_analysis.php';
require_once __DIR__.'/vehicle_compliance.php';

function reports_catalog(): array
{
    return [
        'fleet'=>['title'=>'Fleet Operational Summary','icon'=>'truck','description'=>'Current fleet and compliance snapshot, with utilization and maintenance for the selected period.'],
        'reservations'=>['title'=>'Reservation & Dispatch Report','icon'=>'calendar2-check','description'=>'Bookings, passenger demand, assignments, dispatch status and review history.'],
        'trips'=>['title'=>'Trip Operations Report','icon'=>'signpost-split','description'=>'Trip assignments, routes, departure and arrival records, including archived trips.'],
        'fuel'=>['title'=>'Fuel & Cost Accounting','icon'=>'fuel-pump','description'=>'Recorded fuel purchases, TCAO expenses, vehicle costs and confirmed booking revenue.'],
        'drivers'=>['title'=>'Driver Performance Report','icon'=>'person-badge','description'=>'Recorded trip outcomes, punctuality and actual customer ratings.'],
        'route'=>['title'=>'AI Route Optimization Report','icon'=>'cpu','description'=>'Saved route plans, selected alternatives and recorded prediction versus actual results.'],
    ];
}
function reports_allowed(): array
{
    if (!can('reports.view')) return [];
    if (has_role(['fleet_admin','fleet_manager'])) return reports_catalog();
    if (has_role('dispatcher') && can('dispatch.view')) return array_intersect_key(reports_catalog(),array_flip(['reservations','trips']));
    return [];
}
function reports_require(?string $type=null): void
{
    require_login();
    if (!reports_allowed() || ($type!==null && !isset(reports_allowed()[$type]))) {
        http_response_code(403); exit('You do not have permission to access this report.');
    }
}
function reports_periods(): array
{
    return ['today'=>'Today','week'=>'This Week','month'=>'This Month','previous_month'=>'Previous Month','quarter'=>'This Quarter','year'=>'This Year','custom'=>'Custom Date Range'];
}
function reports_period(array $input, ?DateTimeImmutable $today=null): array
{
    $today ??= new DateTimeImmutable('today',new DateTimeZone(company_timezone()));
    $period=(string)($input['period']??'month');
    if (!isset(reports_periods()[$period])) throw new DomainException('Select a valid reporting period.');
    $start=$today; $finish=$today;
    switch($period) {
        case 'week': $start=$today->modify('monday this week'); $finish=$start->modify('+6 days'); break;
        case 'month': $start=$today->modify('first day of this month'); $finish=$today->modify('last day of this month'); break;
        case 'previous_month': $start=$today->modify('first day of previous month'); $finish=$start->modify('last day of this month'); break;
        case 'quarter': $month=1+3*(int)floor(((int)$today->format('n')-1)/3); $start=$today->setDate((int)$today->format('Y'),$month,1); $finish=$start->modify('+3 months -1 day'); break;
        case 'year': $start=$today->setDate((int)$today->format('Y'),1,1); $finish=$start->modify('+1 year -1 day'); break;
        case 'custom':
            foreach(['from','to'] as $key) {
                $value=(string)($input[$key]??''); $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$today->getTimezone());
                if (!$d || $d->format('Y-m-d')!==$value) throw new DomainException('Enter valid From and To dates.');
                if($key==='from') $start=$d; else $finish=$d;
            }
    }
    if($start>$finish || $start->format('Y')<'2000' || $finish->format('Y')>'2100' || $start->diff($finish)->days>3660)
        throw new DomainException('Choose a date range of no more than ten years, with To on or after From.');
    return ['period'=>$period,'from'=>$start->format('Y-m-d'),'to'=>$finish->format('Y-m-d'),
        'start'=>$start->format('Y-m-d'),'end'=>$finish->modify('+1 day')->format('Y-m-d'),
        'label'=>$start->format('F j, Y').' – '.$finish->format('F j, Y')];
}
function reports_column(string $label,string $type='text',int $width=22): array { return ['label'=>$label,'type'=>$type,'width'=>$width]; }
function reports_trip_date(): string
{
    return "CASE WHEN t.status='Cancelled' THEN COALESCE(r.cancelled_at,t.scheduled_departure,t.created_at)
        ELSE COALESCE(t.actual_arrival,t.actual_departure,t.scheduled_departure,t.created_at) END";
}
function reports_trip_cte(): string
{
    return 'cohort AS (SELECT t.* FROM trips t LEFT JOIN reservations r ON r.id=t.reservation_id WHERE '.reports_trip_date().'>=?::date AND '.reports_trip_date().'<?::date)';
}
function reports_storage(): string
{
    $dir=ROOT_PATH.'/storage/private/reports';
    if(!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new RuntimeException('Report storage unavailable.');
    return $dir;
}
function reports_log(array $m,string $action,?string $format=null): void
{
    try {
        db()->prepare('INSERT INTO audit_logs(action,user_id,entity_type,entity_id,details) VALUES (?,?,?,?,?)')->execute([
            $action,current_user()['id'],'report',$m['type'],json_encode(['report'=>$m['title'],'from'=>$m['range']['from'],'to'=>$m['range']['to'],
                'snapshot'=>$m['token'],'generated_at'=>$m['generated_at'],'format'=>$format,'actor_name'=>current_user()['name'],'actor_role'=>current_user()['role_code']],JSON_THROW_ON_ERROR)]);
    } catch(Throwable $e) { error_log('Reports activity logging: '.$e); }
}
function reports_read(string $token): array
{
    if(!preg_match('/^[a-f0-9]{40}$/D',$token)) throw new DomainException('This report is unavailable. Generate it again.');
    $file=reports_storage().'/'.$token.'/manifest.json';
    if(!is_file($file)) throw new DomainException('This report is unavailable. Generate it again.');
    $m=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    reports_require($m['type']);
    if((int)$m['owner']!==(int)current_user()['id'] || $m['role_code']!==current_user()['role_code']) {
        http_response_code(403); exit('You do not have permission to access this report.');
    }
    if($m['expires']<time()) throw new DomainException('This report has expired. Generate it again.');
    return $m;
}
function reports_cleanup(): void
{
    // Only directories matching our exact token format are eligible for expiry cleanup.
    foreach(glob(reports_storage().'/*/manifest.json')?:[] as $file) {
        $dir=dirname($file);
        if(!preg_match('/^[a-f0-9]{40}$/D',basename($dir)) || filemtime($file)>time()-1800) continue;
        foreach(glob($dir.'/*')?:[] as $entry) if(is_file($entry) && in_array(pathinfo($entry,PATHINFO_EXTENSION),['json','jsonl'],true)) unlink($entry);
        @rmdir($dir);
    }
}
function reports_get(string $type,array $input,bool $refresh=false): array
{
    reports_require($type); $range=reports_period($input);
    $cacheKey=hash('sha256',$type.'|'.$range['period'].'|'.$range['from'].'|'.$range['to'].'|'.current_user()['id'].'|'.current_user()['role_code']);
    $cached=$_SESSION['report_snapshots'][$cacheKey]??null;
    if(!$refresh && $cached) {
        try { return reports_read($cached); } catch(DomainException $e) { unset($_SESSION['report_snapshots'][$cacheKey]); }
    }
    require_once __DIR__.'/report_queries.php';
    reports_cleanup();
    $token=bin2hex(random_bytes(20)); $dir=reports_storage().'/'.$token;
    if(!mkdir($dir,0750)) throw new RuntimeException('Report snapshot unavailable.');
    $pdo=db(); $pdo->beginTransaction(); $file=null;
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $definition=reports_definition($pdo,$type,$range);
        $u=current_user();
        $m=['token'=>$token,'type'=>$type,'title'=>reports_catalog()[$type]['title'],'company'=>company_name(),
            'range'=>$range,'owner'=>(int)$u['id'],'role_code'=>$u['role_code'],'generated_by'=>$u['name'],
            'generated_role'=>$u['role_name'],'generated_at'=>date('Y-m-d H:i:s'),'timezone'=>company_timezone(),
            'expires'=>time()+1800,'metrics'=>$definition['metrics'],'notes'=>$definition['notes'],'sections'=>[]];
        foreach($definition['sections'] as $key=>$section) {
            $q=$pdo->prepare('DECLARE report_cursor NO SCROLL CURSOR FOR '.$section['sql']); $q->execute($section['params']);
            $file=fopen($dir.'/'.$key.'.jsonl','xb'); if(!$file) throw new RuntimeException('Unable to create report dataset.');
            $count=0; $offsets=[];
            do {
                $batch=$pdo->query('FETCH FORWARD 500 FROM report_cursor')->fetchAll();
                foreach($batch as $row) {
                    if(isset($section['transform'])) $row=$section['transform']($row);
                    foreach($section['columns'] as $field=>$column) {
                        $value=$row[$field]??null;
                        if($value!==null && in_array($column['type'],['integer','decimal','money','percent'],true)) {
                            if(!is_numeric($value)) throw new UnexpectedValueException('Invalid numeric report value.');
                            $row[$field]=$column['type']==='integer'?(int)$value:(float)$value;
                        }
                    }
                    $row=array_intersect_key($row,$section['columns']);
                    if($count%20===0) $offsets[]=ftell($file);
                    $line=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
                    if(fwrite($file,$line)!==strlen($line)) throw new RuntimeException('Unable to save report dataset.');
                    $count++;
                }
            } while($batch);
            fclose($file); $file=null; $pdo->exec('CLOSE report_cursor');
            $m['sections'][$key]=['title'=>$section['title'],'columns'=>$section['columns'],'count'=>$count,'offsets'=>$offsets,'totals'=>$section['totals']??[],
                'pdf_groups'=>$section['pdf_groups']??[array_keys($section['columns'])]];
        }
        $pdo->commit();
        if(file_put_contents($dir.'/manifest.json',json_encode($m,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false) throw new RuntimeException('Unable to save report manifest.');
        $_SESSION['report_snapshots'][$cacheKey]=$token;
        return $m;
    } catch(Throwable $e) {
        if(is_resource($file)) fclose($file);
        if($pdo->inTransaction()) $pdo->rollBack();
        foreach(glob($dir.'/*')?:[] as $entry) if(is_file($entry)) unlink($entry);
        @rmdir($dir); throw $e;
    }
}
function reports_rows(array $m,string $section,?int $page=null): Generator
{
    if(!isset($m['sections'][$section])) throw new DomainException('Invalid report section.');
    $s=$m['sections'][$section]; $f=fopen(reports_storage().'/'.$m['token'].'/'.$section.'.jsonl','rb');
    if(!$f) throw new RuntimeException('Report dataset unavailable.');
    try {
        $limit=null;
        if($page!==null) { $page=max(1,$page); if(!isset($s['offsets'][$page-1])) return; fseek($f,$s['offsets'][$page-1]); $limit=20; }
        $i=0;
        while(($line=fgets($f))!==false && ($limit===null || $i<$limit)) { $i++; yield json_decode($line,true,512,JSON_THROW_ON_ERROR); }
    } finally { fclose($f); }
}
function reports_value($value,string $type): string
{
    if($value===null || $value==='') return '—';
    if($type==='money') return '₱'.number_format((float)$value,2);
    if($type==='percent') return number_format((float)$value*100,1).'%';
    if($type==='integer') return number_format((int)$value);
    if($type==='decimal') return number_format((float)$value,2);
    if($type==='date' || $type==='datetime') return (new DateTimeImmutable((string)$value))->format($type==='date'?'M j, Y':'M j, Y · g:i A');
    return (string)$value;
}
function reports_filename(array $m,string $format): string
{
    $names=['fleet'=>'Fleet_Operational_Summary','reservations'=>'Reservation_Dispatch','trips'=>'Trip_Operations','fuel'=>'Fuel_Cost','drivers'=>'Driver_Performance','route'=>'AI_Route_Optimization'];
    $r=$m['range']; $suffix=$r['from'].'_to_'.$r['to'];
    if($r['period']!=='custom' && substr($r['from'],8)==='01' && date('Y-m-t',strtotime($r['from']))===$r['to']) $suffix=substr($r['from'],0,7);
    return 'TourSphere_'.$names[$m['type']].'_'.$suffix.'.'.$format;
}
