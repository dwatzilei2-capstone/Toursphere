<?php
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once ROOT_PATH.'/includes/archive.php';
archive_require_access();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD']==='GET' && ($_GET['action']??'')==='retirement-preview') {
        if(!archive_can_retire()) { http_response_code(403); echo json_encode(['error'=>'Only Admin may retire a vehicle.']); exit; }
        $id=trim((string)($_GET['id']??''));
        $q=db()->prepare('SELECT id,plate_number,status FROM vehicles WHERE id=? AND NOT is_archived'); $q->execute([$id]); $v=$q->fetch();
        if(!$v) throw new DomainException('Vehicle not found or already retired.');
        echo json_encode(['vehicle'=>$v,'recommendation'=>archive_recommendation(db(),$id)]); exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); header('Allow: GET, POST'); echo json_encode(['error'=>'Unsupported request.']); exit; }
    if(!is_string($_POST['csrf']??null) || empty($_SESSION['archive_csrf']) || !hash_equals($_SESSION['archive_csrf'],$_POST['csrf'])) {
        http_response_code(403); echo json_encode(['error'=>'Your form session expired. Refresh the page and try again.']); exit;
    }
    $action=(string)($_POST['action']??'');
    if(($action==='trip' && !archive_can_trips()) || ($action==='retire' && !archive_can_retire())) {
        http_response_code(403); echo json_encode(['error'=>'You do not have permission to perform this action.']); exit;
    }
    if(($_POST['confirmed']??'')!=='1') throw new DomainException('Confirm the decision before continuing.');
    $id=trim((string)($_POST['id']??''));
    if($action==='trip') archive_trip(db(),$id);
    elseif($action==='retire') archive_retire_vehicle(db(),$id,trim((string)($_POST['reason']??'')),trim((string)($_POST['explanation']??'')),trim((string)($_POST['notes']??'')));
    else throw new DomainException('Unknown Archive action.');
    $message=$action==='trip'
        ? 'Trip '.$id.' was archived successfully. Its historical records remain available in Archive.'
        : 'Vehicle '.$id.' was retired successfully. Its historical records remain available in Archive.';
    // Preserve confirmation across the client's reload, then display it only once.
    $_SESSION['archive_success']=$message;
    echo json_encode(['ok'=>true,'message'=>$message]);
} catch(DomainException $e) { http_response_code(422); echo json_encode(['error'=>$e->getMessage()]); }
catch(Throwable $e) { error_log('Archive action: '.$e->getMessage()); http_response_code(500); echo json_encode(['error'=>'Unable to save this Archive decision. Please try again.']); }
