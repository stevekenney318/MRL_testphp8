<?php
/*
MRL WP IMAGES DATA
VERSION: v004
LAST MODIFIED: 7/23/2026 6:12:06 am

CHANGELOG
v004 (7/23/2026 6:12:06 am)
- NEW: Winning-driver capture endpoint for the compiled local helper.
- NEW: Driver-card status check used by mrl_wp_images.html polling.
- CHANGE: Driver cards are uploaded directly as final JPEG files.
- PHP 7.3 compatible.
*/
declare(strict_types=1);
date_default_timezone_set('America/New_York');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function out(array $p,int $s=200):void{http_response_code($s);header('Content-Type: application/json; charset=utf-8');echo json_encode($p,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);exit;}
function safe_name(string $n):string{$n=preg_replace('/[^A-Za-z0-9]+/','_',trim($n));return trim((string)$n,'_')?:'Driver';}
$action=(string)($_GET['action']??$_POST['action']??'');
$year=(int)($_GET['year']??$_POST['year']??date('Y'));
$driver=trim((string)($_GET['driver']??$_POST['driver']??''));

if($action==='card_status'){
  if($driver===''||$year<2000||$year>2100)out(['ok'=>false,'error'=>'Invalid driver or year.'],400);
  $file=safe_name($driver).'_'.$year.'.jpg';
  $rel=$year.'/images/drivers/'.$file;
  out(['ok'=>true,'exists'=>is_file(__DIR__.DIRECTORY_SEPARATOR.$rel),'filename'=>$file,'relative_path'=>$rel,'url'=>'/race_results/'.$rel]);
}

if($action==='upload_driver_card'){
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}
  if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'POST required.'],405);
  $in=json_decode((string)file_get_contents('php://input'),true);
  if(!is_array($in))out(['ok'=>false,'error'=>'Invalid JSON.'],400);
  $year=(int)($in['year']??0);$driver=trim((string)($in['driver']??''));$image=(string)($in['image']??'');
  if($year<2000||$year>2100||$driver===''||!preg_match('#^data:image/jpeg;base64,#',$image))out(['ok'=>false,'error'=>'Invalid upload.'],400);
  $raw=base64_decode(substr($image,strpos($image,',')+1),true);
  if($raw===false||strlen($raw)<1000)out(['ok'=>false,'error'=>'JPEG decode failed.'],400);
  $dir=__DIR__.DIRECTORY_SEPARATOR.$year.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'drivers';
  if(!is_dir($dir)&&!@mkdir($dir,0775,true))out(['ok'=>false,'error'=>'Could not create driver folder.'],500);
  $file=safe_name($driver).'_'.$year.'.jpg';$path=$dir.DIRECTORY_SEPARATOR.$file;
  if(is_file($path))@copy($path,$path.'.bak_'.date('Ymd_His'));
  if(@file_put_contents($path,$raw)===false)out(['ok'=>false,'error'=>'Could not save JPEG.'],500);
  $rel=$year.'/images/drivers/'.$file;
  out(['ok'=>true,'filename'=>$file,'relative_path'=>$rel,'url'=>'/race_results/'.$rel,'bytes'=>strlen($raw)]);
}

/* Reuse v003 race discovery by including the preserved prior endpoint when present. */
$legacy=__DIR__.DIRECTORY_SEPARATOR.'mrl_wp_images_data_v003_legacy.php';
if(is_file($legacy)){require $legacy;exit;}
out(['ok'=>false,'error'=>'Race-data legacy endpoint is missing. Run the v004 installer, which preserves v003 automatically.'],500);
