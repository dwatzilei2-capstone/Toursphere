<?php
/** Verified modern LTO qualifications; legacy restriction strings never grant DL codes. */
require_once __DIR__.'/vehicle_document.php';
/** Display-only development marker. Never grants driving privileges or DL codes. */
function driver_license_dev_display(array $driver): bool {
 $environment=strtolower(trim((string)($_ENV['APP_ENV'] ?? (getenv('APP_ENV') ?: 'production'))));
 return in_array($environment,['development','testing'],true)
     && filter_var($driver['license_development_verified'] ?? false,FILTER_VALIDATE_BOOLEAN);
}
/** Simulated eligibility requires an explicit database flag and a non-production runtime. */
function driver_license_development_eligible(array $driver, ?string $date=null): bool {
 return driver_license_dev_display($driver)
     && filter_var($driver['license_development_assignment_enabled'] ?? false,FILTER_VALIDATE_BOOLEAN)
     && !empty($driver['license_development_verified_at'])
     && (string)($driver['license_expiration'] ?? '') >= max(date('Y-m-d'),$date ?? date('Y-m-d'));
}
function driver_dl_codes($raw): array {
 if(is_string($raw)){$decoded=json_decode($raw,true);$raw=is_array($decoded)?$decoded:preg_split('/[\s,;\/]+/',strtoupper(trim($raw)));}
 if(!is_array($raw))throw new InvalidArgumentException('Select the DL codes actually printed on the license.');
 $allowed=['A','A1','B','B1','B2','BE','C','CE','D'];$out=[];
 foreach($raw as $code){if(!is_string($code)||!in_array(strtoupper(trim($code)),$allowed,true))throw new InvalidArgumentException('Unsupported LTO DL code.');$out[]=strtoupper(trim($code));}
 return array_values(array_unique($out));
}
function driver_license_active(array $d, ?string $date=null): bool {
 $date??=date('Y-m-d');$expiry=(string)($d['license_expiration']??'');
 $validDate=DateTimeImmutable::createFromFormat('!Y-m-d',$expiry);if(!$validDate||$validDate->format('Y-m-d')!==$expiry)return false;
 try{$codes=driver_dl_codes($d['authorized_dl_codes']??[]);}catch(InvalidArgumentException $e){return false;}
 return ($d['license_type']??'')==='Professional' && ($d['license_verification_status']??'')==='Verified'
 && !empty($d['license_verified_by']) && !empty($d['license_verified_at']) && !empty($d['license_document_filename'])
 && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$expiry) && $expiry >= max(date('Y-m-d'),$date) && in_array('C',$codes,true);
}
function vehicle_required_dl_code(array $v): string {
 if(filter_var($v['requires_trailer_license']??false,FILTER_VALIDATE_BOOLEAN)||preg_match('/\b(trailer|tractor|articulated)\b/i',($v['type']??'').' '.($v['model']??'')))return 'CE';
 if(($v['service_category']??'')==='Cargo'){
  if(!empty($v['gross_vehicle_weight_kg']))return (float)$v['gross_vehicle_weight_kg']>3500?'C':'B2';
  return preg_match('/\b(truck|wing|refrigerated)\b/i',($v['type']??'').' '.($v['model']??''))?'C':'B2';
 }
 return preg_match('/bus|coach/i',$v['type']??'')?'D':((int)($v['capacity']??0)>8?'B1':'B');
}
function driver_license_eligibility(array $v,array $d,?string $date=null): string {
 if(driver_license_development_eligible($d,$date))return '';
 if(!driver_license_active($d,$date))return 'A valid, manually verified Professional license with DL Code C is required.';
 $required=vehicle_required_dl_code($v);$codes=driver_dl_codes($d['authorized_dl_codes']);
 return in_array($required,$codes,true)?'':'Verified DL Code '.$required.' is required for this vehicle'.($required==='CE'?' (truck-trailer combination).':'.');
}
function driver_license_extract(string $path,string $mime): array {
 $text=read_vehicle_document_text($path,$mime)??'';$data=['authorized_dl_codes'=>[],'supported'=>trim($text)!==''];
 if(vehicle_document_is_test_fixture($text))$data['test_fixture']=true;
 if(preg_match('/\b(?:DL\s*CODES?|DRIVER[\x{2019}\x27]?S?\s*LICENSE\s*CODES?)\s*[:\-]?\s*([^\r\n]+)/iu',$text,$m)){
  preg_match_all('/(?<![A-Z0-9])(?:A1|B1|B2|BE|CE|A|B|C|D)(?![A-Z0-9])/i',$m[1],$codes);$data['authorized_dl_codes']=driver_dl_codes(array_map('strtoupper',$codes[0]));
 }
 if(preg_match('/\bNON[ -]?PROFESSIONAL\b/i',$text))$data['license_type']='Non-Professional';
 elseif(preg_match('/\bPROFESSIONAL\b/i',$text))$data['license_type']='Professional';
 if(preg_match('/\bLICENSE\s*(?:NO\.?|NUMBER)\s*[:\-]?\s*([A-Z0-9-]+)/i',$text,$m))$data['license_no']=strtoupper($m[1]);
 if($expiry=vehicle_document_labeled_date($text,['EXPIRATION DATE','EXPIRY DATE']))$data['license_expiration']=$expiry;
 return $data;
}
