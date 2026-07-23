<?php
/*
MRL WP IMAGES DATA
VERSION: v003
LAST MODIFIED: 7/23/2026 2:03:59 am

CHANGELOG
v003 (7/23/2026 2:03:59 am)
- NEW: Server-side Driver Card Library with yearly filenames such as Joey_Logano_2026.jpg.
- NEW: MRL driver discovery from generated _mrl views, with canonical-snapshot fallback.
- NEW: NASCAR profile extraction for portrait, number badge, series/manufacturer logos, and driver facts.
- NEW: Same-origin asset proxy so the browser can render and save driver cards without PowerShell.
- NEW: Save, status, open, download, copy URL, backfill-missing, refresh-all, and refresh-one workflows.
- CHANGE: Removed the custom protocol, PowerShell helper, registry dependency, and on-demand hidden Chrome capture.
- NOTE: NASCAR page data is held only for the request; no temporary driver page is retained.

PHP 7.3 compatible.
*/

declare(strict_types=1);
date_default_timezone_set('America/New_York');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function json_out(array $p, int $s=200): void { http_response_code($s); header('Content-Type: application/json; charset=utf-8'); echo json_encode($p, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES); exit; }
function clean_text(string $s): string { return trim((string)preg_replace('/\s+/', ' ', html_entity_decode($s, ENT_QUOTES|ENT_HTML5, 'UTF-8'))); }
function safe_driver_filename(string $name, int $year): string { $n=preg_replace('/[^A-Za-z0-9]+/','_',trim($name)); $n=trim((string)$n,'_'); return ($n!==''?$n:'Driver').'_'.$year.'.jpg'; }
function race_code(string $n): ?string { return preg_match('/^R(\d{1,2})(?:_|$)/i',$n,$m) ? 'R'.str_pad((string)(int)$m[1],2,'0',STR_PAD_LEFT) : null; }
function canonical_files(string $dir): array { $f=glob($dir.DIRECTORY_SEPARATOR.'snapshot_*.html')?:[]; $o=[]; foreach($f as $x) if(preg_match('/^snapshot_\d{8}_\d{9}\.html$/',basename($x)))$o[]=$x; rsort($o,SORT_STRING); return $o; }
function latest_snapshot(string $dir): ?string { $a=canonical_files($dir); return $a[0]??null; }
function read_meta(string $dir): array { $p=$dir.DIRECTORY_SEPARATOR.'_meta.json'; if(!is_file($p))return[]; $d=json_decode((string)@file_get_contents($p),true); return is_array($d)?$d:[]; }
function race_name(string $folder,array $m,string $code): string { foreach(['short_name','race_short_name','display_name','race_display_name','location','track_short_name'] as $k){ if(!empty($m[$k])) return trim((string)preg_replace('/^R\d+\s*/i','',(string)$m[$k])); } $n=preg_replace('/^R\d+_?/i','',$folder); $n=preg_replace('/_\d{10,}$/','',(string)$n); $n=preg_replace('/^NASCAR_Cup_Series_at_/i','',(string)$n); return trim((string)preg_replace('/\s+/',' ',str_replace('_',' ',(string)$n)))?:$code; }
function table_drivers(string $file): array { $h=@file_get_contents($file); if($h===false)return[]; libxml_use_internal_errors(true); $d=new DOMDocument(); @$d->loadHTML($h); libxml_clear_errors(); $x=new DOMXPath($d); $out=[]; foreach($x->query('//tr')?:[] as $r){ $c=$x->query('./th|./td',$r); if(!$c||$c->length<2)continue; $vals=[]; foreach($c as $cell)$vals[]=clean_text($cell->textContent); $first=$vals[0]??''; if(!preg_match('/^\d+(?:st|nd|rd|th)?$/i',$first))continue; foreach(array_slice($vals,1) as $v){ if($v===''||preg_match('/^\d+$/',$v)||preg_match('/^(driver|car|manufacturer|laps|start|led|pts|bonus|penalty)$/i',$v))continue; $out[$v]=true; break; } } return array_keys($out); }
function latest_mrl_view(string $dir): ?string { $c=array_merge(glob($dir.DIRECTORY_SEPARATOR.'snapshot_*_mrl.html')?:[],glob($dir.DIRECTORY_SEPARATOR.'snapshot_*_mrl_segment.html')?:[]); rsort($c,SORT_STRING); return $c[0]??null; }
function slugify_driver(string $n): string { $n=strtolower($n); $n=str_replace(['&'],['and'],$n); $n=preg_replace('/[^a-z0-9]+/','-',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$n)?:$n); return trim((string)$n,'-'); }
function fetch_url(string $u): string { if(!function_exists('curl_init')) throw new RuntimeException('cURL is not available on this server.'); $c=curl_init($u); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150 Safari/537.36',CURLOPT_ENCODING=>'']); $b=curl_exec($c); $s=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE); $e=curl_error($c); curl_close($c); if($b===false||$s<200||$s>=400) throw new RuntimeException('NASCAR request failed'.($e!==''?': '.$e:' (HTTP '.$s.')')); return (string)$b; }
function abs_url(string $u,string $base): string { if($u==='')return''; if(preg_match('#^https?://#i',$u))return$u; if(strpos($u,'//')===0)return'https:'.$u; $p=parse_url($base); $origin=($p['scheme']??'https').'://'.($p['host']??'www.nascar.com'); if(substr($u,0,1)==='/')return$origin.$u; $path=$p['path']??'/'; $dir=rtrim(str_replace('\\','/',dirname($path)),'/'); return $origin.$dir.'/'.$u; }
function first_img(DOMXPath $x,string $q,string $base): string { $n=$x->query($q); if($n&&$n->length){ $s=$n->item(0)->getAttribute('src'); return abs_url($s,$base); } return''; }

$action=$_GET['action']??'races';
$year=isset($_GET['year'])?(int)$_GET['year']:(int)date('Y');
if($year<2000||$year>2100)json_out(['ok'=>false,'error'=>'Invalid year.'],400);
$yearDir=__DIR__.DIRECTORY_SEPARATOR.$year;

if($action==='asset'){
  $u=(string)($_GET['url']??''); if(!preg_match('#^https://([a-z0-9.-]+\.)?nascar\.com/#i',$u)) { http_response_code(403); exit('Asset not allowed.'); }
  try { $b=fetch_url($u); } catch(Throwable $e){ http_response_code(502); exit($e->getMessage()); }
  $ext=strtolower(pathinfo((string)parse_url($u,PHP_URL_PATH),PATHINFO_EXTENSION)); $types=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','svg'=>'image/svg+xml','webp'=>'image/webp']; header('Content-Type: '.($types[$ext]??'application/octet-stream')); header('Access-Control-Allow-Origin: *'); echo $b; exit;
}

if($action==='save_card'){
  if($_SERVER['REQUEST_METHOD']!=='POST')json_out(['ok'=>false,'error'=>'POST required.'],405);
  $in=json_decode((string)file_get_contents('php://input'),true); if(!is_array($in))json_out(['ok'=>false,'error'=>'Invalid JSON.'],400);
  $driver=trim((string)($in['driver']??'')); $data=(string)($in['image']??''); $yr=(int)($in['year']??$year);
  if($driver===''||!preg_match('#^data:image/jpeg;base64,#',$data))json_out(['ok'=>false,'error'=>'Driver and JPEG data are required.'],400);
  $raw=base64_decode(substr($data,strpos($data,',')+1),true); if($raw===false)json_out(['ok'=>false,'error'=>'JPEG decoding failed.'],400);
  $dir=__DIR__.DIRECTORY_SEPARATOR.$yr.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'drivers'; if(!is_dir($dir)&&!@mkdir($dir,0775,true))json_out(['ok'=>false,'error'=>'Could not create driver-card folder.'],500);
  $fn=safe_driver_filename($driver,$yr); $path=$dir.DIRECTORY_SEPARATOR.$fn; if(@file_put_contents($path,$raw)===false)json_out(['ok'=>false,'error'=>'Could not save driver card.'],500);
  $rel=$yr.'/images/drivers/'.$fn; json_out(['ok'=>true,'filename'=>$fn,'relative_path'=>$rel,'url'=>'/race_results/'.$rel,'bytes'=>strlen($raw)]);
}

if($action==='drivers'){
  if(!is_dir($yearDir))json_out(['ok'=>true,'year'=>$year,'drivers'=>[]]); $set=[];
  foreach(scandir($yearDir)?:[] as $e){ if($e==='.'||$e==='..'||race_code($e)===null)continue; $d=$yearDir.DIRECTORY_SEPARATOR.$e; if(!is_dir($d))continue; $f=latest_mrl_view($d)??latest_snapshot($d); if($f)foreach(table_drivers($f) as $name)$set[$name]=true; }
  $drivers=array_keys($set); natcasesort($drivers); $out=[]; foreach($drivers as $name){ $fn=safe_driver_filename($name,$year); $rel=$year.'/images/drivers/'.$fn; $out[]=['name'=>$name,'slug'=>slugify_driver($name),'filename'=>$fn,'exists'=>is_file(__DIR__.DIRECTORY_SEPARATOR.$rel),'url'=>'/race_results/'.$rel]; }
  json_out(['ok'=>true,'year'=>$year,'count'=>count($out),'drivers'=>array_values($out)]);
}

if($action==='profile'){
  $driver=trim((string)($_GET['driver']??'')); if($driver==='')json_out(['ok'=>false,'error'=>'Driver is required.'],400);
  $overrides=['a j allmendinger'=>'aj-allmendinger','aj allmendinger'=>'aj-allmendinger']; $key=strtolower(preg_replace('/\s+/',' ',$driver)); $slug=$overrides[$key]??slugify_driver($driver); $url='https://www.nascar.com/drivers/'.$slug.'/';
  try { $h=fetch_url($url); libxml_use_internal_errors(true); $d=new DOMDocument(); @$d->loadHTML($h); libxml_clear_errors(); $x=new DOMXPath($d);
    $portrait=first_img($x,"//*[contains(concat(' ',normalize-space(@class),' '),' ndms2023-driver-hero-left-col ')]//img[1]",$url);
    $badge=first_img($x,"//*[contains(concat(' ',normalize-space(@class),' '),' ndms2023-driver-badge ')]//img[1]",$url);
    $series=first_img($x,"//img[contains(concat(' ',normalize-space(@class),' '),' ndms2023-driver-series ')][1]",$url);
    $manu=first_img($x,"//img[contains(concat(' ',normalize-space(@class),' '),' ndms2023-driver-manu ')][1]",$url);
    $h1=$x->query("//*[contains(concat(' ',normalize-space(@class),' '),' ndms2023-driver-name ')]//h1[1]"); $nameText=$h1&&$h1->length?clean_text($h1->item(0)->textContent):$driver;
    $first=$driver; $last=''; if($h1&&$h1->length){ $sp=$x->query('.//span',$h1->item(0)); if($sp&&$sp->length)$first=clean_text($sp->item(0)->textContent); $last=trim(str_ireplace($first,'',$nameText)); }
    if($last===''){ $parts=preg_split('/\s+/',$driver); $first=array_shift($parts)?:$driver; $last=implode(' ',$parts); }
    $facts=[]; foreach($x->query("//*[contains(concat(' ',normalize-space(@class),' '),' ndms2023-driver-hero-right-row-4-col ')]")?:[] as $n){ $hs=$x->query('./h3',$n); $ps=$x->query('./p',$n); if($hs&&$hs->length&&$ps&&$ps->length)$facts[clean_text($hs->item(0)->textContent)]=clean_text($ps->item(0)->textContent); }
    if($portrait===''||$badge==='')throw new RuntimeException('Required driver-card assets were not found on the NASCAR page.');
    $proxy=function($u){ return $u===''?'':'/race_results/mrl_wp_images_data.php?action=asset&url='.rawurlencode($u); };
    json_out(['ok'=>true,'driver'=>$driver,'slug'=>$slug,'source_url'=>$url,'first_name'=>$first,'last_name'=>$last,'portrait'=>$proxy($portrait),'badge'=>$proxy($badge),'series_logo'=>$proxy($series),'manufacturer_logo'=>$proxy($manu),'facts'=>$facts]);
  } catch(Throwable $e){ json_out(['ok'=>false,'driver'=>$driver,'slug'=>$slug,'source_url'=>$url,'error'=>$e->getMessage()],502); }
}

// races
if(!is_dir($yearDir))json_out(['ok'=>true,'year'=>$year,'races'=>[]]); $r=[];
foreach(scandir($yearDir)?:[] as $e){ if($e==='.'||$e==='..')continue; $code=race_code($e); if($code===null)continue; $dir=$yearDir.DIRECTORY_SEPARATOR.$e; if(!is_dir($dir))continue; $snap=latest_snapshot($dir); if(!$snap)continue; $drivers=table_drivers($snap); if(!$drivers)continue; $winner=$drivers[0]; $num=(int)substr($code,1); $m=read_meta($dir); preg_match('/^snapshot_(\d{8}_\d{9})\.html$/',basename($snap),$mm); $r[]=['year'=>$year,'week'=>$num,'race_number'=>$num,'race_code'=>$code,'race_name'=>race_name($e,$m,$code),'winner'=>$winner,'snapshot'=>basename($snap),'release_id'=>($mm[1]??$code).'_'.$code]; }
usort($r,function($a,$b){return $a['race_number']<=>$b['race_number'];}); json_out(['ok'=>true,'year'=>$year,'count'=>count($r),'races'=>$r]);
