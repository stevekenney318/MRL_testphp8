<?php
declare(strict_types=1);
/**
 * mrl_user_year_audit_installer.php
 * VERSION: v001
 * GENERATED: 8/21/2026 9:21:00 pm America/New_York
 * TESTPHP8 ONLY. Adds one read-only admin diagnostic. No DB changes.
 */
date_default_timezone_set('America/New_York');
const HOST_REQ='testphp8.manliusracingleague.com';
$host=strtolower((string)($_SERVER['HTTP_HOST']??''));
$root=rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/\\');
$target=$root.'/admin_user_year_audit.php';
$backupDir=$root.'/mrl_user_year_audit_backup_20260821_092100pm';
$checks=[];$errors=[];$post=[];$installed=false;
function i_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function i_ck(&$a,$n,$ok,$d=''){$a[]=['n'=>$n,'ok'=>$ok,'d'=>$d];}
function i_write($p,$c):bool{$t=$p.'.tmp_'.uniqid();if(@file_put_contents($t,$c,LOCK_EX)===false)return false;if(!@rename($t,$p)){@unlink($t);return false;}return true;}
$tool=<<<'TOOL'
<?php
declare(strict_types=1);
/**
 * admin_user_year_audit.php
 * VERSION: v001
 * LAST MODIFIED: 8/21/2026 9:21:00 pm
 * TESTPHP8-only, admin-only, READ ONLY.
 *
 * PURPOSE:
 * Compare the years table (which controls team.php Previous Years Picks)
 * against user_teams, user_picks, and user_picks_history for any selected user.
 * PHP 7.3 compatible.
 */
session_start();
date_default_timezone_set('America/New_York');

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if ($host !== 'testphp8.manliusracingleague.com') {
    http_response_code(403);
    exit('REFUSED: TESTPHP8 only.');
}

require_once __DIR__ . '/class.user.php';
$user_home = new USER();
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_mrl.php';

$sessionUid = (int)($_SESSION['userSession'] ?? 0);
if (!function_exists('isAdmin') || !isAdmin($sessionUid)) {
    http_response_code(403);
    exit('REFUSED: Admin access required.');
}

function uya_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function uya_table_exists(PDO $dbo, string $table): bool {
    $stmt = $dbo->prepare('SHOW TABLES LIKE :t');
    $stmt->execute([':t' => $table]);
    return $stmt->fetchColumn() !== false;
}
function uya_pick_detail(PDO $dbo, string $table, int $uid): array {
    if (!uya_table_exists($dbo, $table)) return [];
    $sql = "SELECT raceYear, segment,
                   COALESCE(NULLIF(pick_type,''),'(blank)') AS pick_type,
                   COUNT(*) AS cnt
            FROM `$table`
            WHERE userID = :uid AND raceYear > 0
            GROUP BY raceYear, segment, COALESCE(NULLIF(pick_type,''),'(blank)')
            ORDER BY raceYear DESC, segment ASC, pick_type ASC";
    $stmt = $dbo->prepare($sql);
    $stmt->execute([':uid' => $uid]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $y = (int)($r['raceYear'] ?? 0);
        if ($y <= 0) continue;
        if (!isset($out[$y])) $out[$y] = [];
        $out[$y][] = [
            'segment' => (string)($r['segment'] ?? ''),
            'type' => (string)($r['pick_type'] ?? ''),
            'count' => (int)($r['cnt'] ?? 0),
        ];
    }
    return $out;
}
function uya_detail_text(array $rows): string {
    if (empty($rows)) return '—';
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = (($r['segment'] ?? '') !== '' ? $r['segment'] : '(no segment)')
            . ' / ' . (($r['type'] ?? '') !== '' ? $r['type'] : '(blank)')
            . ' ×' . (int)($r['count'] ?? 0);
    }
    return implode(' | ', $parts);
}

$currentRaceYear = isset($raceYear) ? (int)$raceYear : (int)date('Y');

$usersStmt = $dbo->query("SELECT userID, userName FROM users ORDER BY userName ASC, userID ASC");
$users = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$selectedUid = isset($_GET['userID']) ? (int)$_GET['userID'] : $sessionUid;
$valid = [];
foreach ($users as $u) $valid[(int)$u['userID']] = true;
if (!isset($valid[$selectedUid])) $selectedUid = $sessionUid;

$selectedName = '';
foreach ($users as $u) {
    if ((int)$u['userID'] === $selectedUid) {
        $selectedName = (string)($u['userName'] ?? '');
        break;
    }
}

$yearsMap = [];
$stmt = $dbo->query("SELECT year FROM years WHERE year > 0 ORDER BY year DESC");
if ($stmt) {
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $y) {
        $y = (int)$y;
        if ($y > 0) $yearsMap[$y] = true;
    }
}

$teamMap = [];
$stmt = $dbo->prepare("SELECT raceYear, teamName FROM user_teams WHERE userID=:uid AND raceYear>0 ORDER BY raceYear DESC");
$stmt->execute([':uid'=>$selectedUid]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $y=(int)($r['raceYear']??0);
    if ($y<=0) continue;
    if (!isset($teamMap[$y])) $teamMap[$y]=[];
    $teamMap[$y][]=(string)($r['teamName']??'');
}

$pickDetail = uya_pick_detail($dbo, 'user_picks', $selectedUid);
$histDetail = uya_pick_detail($dbo, 'user_picks_history', $selectedUid);

$pickCounts=[]; foreach($pickDetail as $y=>$rows){$n=0;foreach($rows as $r)$n+=(int)$r['count'];$pickCounts[(int)$y]=$n;}
$histCounts=[]; foreach($histDetail as $y=>$rows){$n=0;foreach($rows as $r)$n+=(int)$r['count'];$histCounts[(int)$y]=$n;}

$all=[];
foreach(array_keys($yearsMap) as $y)$all[(int)$y]=true;
foreach(array_keys($teamMap) as $y)$all[(int)$y]=true;
foreach(array_keys($pickCounts) as $y)$all[(int)$y]=true;
foreach(array_keys($histCounts) as $y)$all[(int)$y]=true;
$allYears=array_keys($all); rsort($allYears,SORT_NUMERIC);

$missing=[];
$loopYears=[];
foreach($allYears as $y){
    $inYears=isset($yearsMap[$y]);
    $p=(int)($pickCounts[$y]??0);
    if($inYears && $y<$currentRaceYear)$loopYears[]=$y;
    if($p>0 && !$inYears)$missing[]=$y;
}
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL User Year Audit</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#111;color:#eee;font:14px Arial,sans-serif}
.wrap{max-width:1550px;margin:auto;padding:15px}.banner{background:#3d3212;border:1px solid #9b7200;border-radius:11px;padding:12px 15px}
.banner h1{margin:0;color:#fff0c8;font-size:22px}.sub{font-size:12px;color:#dac884;margin-top:4px}
.card{background:#1c1c1c;border:1px solid #444;border-radius:9px;padding:12px 14px;margin-top:11px}
select{background:#0e0e0e;color:#eee;border:1px solid #555;border-radius:5px;padding:7px;min-width:360px}
button{background:#2b669f;color:#fff;border:0;border-radius:6px;padding:8px 12px;font-weight:700}
table{width:100%;border-collapse:collapse;margin-top:9px}th,td{border:1px solid #3a3a3a;padding:6px 7px;text-align:left;vertical-align:top}
th{background:#262626}.good{color:#67e89b;font-weight:700}.bad{color:#ff7777;font-weight:700}.warn{color:#f0bd62;font-weight:700}
.small{font-size:12px;color:#aaa}code{color:#f0d98c}
</style></head><body><div class="wrap">
<div class="banner"><h1>TESTPHP8 / MRL USER YEAR AUDIT</h1><div class="sub">Read-only • admin only • all years at once • no DB changes</div></div>

<div class="card"><form method="get">
<select name="userID">
<?php foreach($users as $u): $id=(int)$u['userID']; ?>
<option value="<?=$id?>" <?=$id===$selectedUid?'selected':''?>><?=uya_h($u['userName']??'')?> [<?=$id?>]</option>
<?php endforeach; ?>
</select>
<button type="submit">Audit Selected User</button>
</form></div>

<div class="card">
<strong><?=uya_h($selectedName)?></strong> — current MRL year: <strong><?=$currentRaceYear?></strong>
<div class="small" style="margin-top:7px">Current team.php discovers previous years only from <code>years</code>, not from user_picks.</div>
<?php if($missing): ?>
<div class="bad" style="margin-top:8px">PICKS EXIST BUT YEAR IS MISSING FROM years: <?=uya_h(implode(', ',$missing))?></div>
<?php else: ?>
<div class="good" style="margin-top:8px">No selected-user pick years are missing from years.</div>
<?php endif; ?>
<div style="margin-top:7px">team.php would currently loop: <strong><?=uya_h($loopYears?implode(', ',$loopYears):'none')?></strong></div>
</div>

<div class="card"><strong>All-year source comparison</strong>
<table><thead><tr>
<th>Year</th><th>In years?</th><th>team.php loops?</th><th>user_teams</th><th>user_picks</th><th>Pick detail</th><th>History</th><th>History detail</th><th>Diagnosis</th>
</tr></thead><tbody>
<?php foreach($allYears as $y):
$in=isset($yearsMap[$y]); $loops=$in&&$y<$currentRaceYear; $pc=(int)($pickCounts[$y]??0); $hc=(int)($histCounts[$y]??0);
$teams=array_values(array_unique(array_filter($teamMap[$y]??[],function($v){return trim((string)$v)!=='';})));
$diag='No current display source'; $cls='';
if($pc>0&&!$in){$diag='PICKS EXIST / YEAR NOT IN years — team.php cannot reach it';$cls='bad';}
elseif($loops&&$pc>0){$diag='Expected to display';$cls='good';}
elseif($loops&&$pc===0){$diag='team.php loops it, but selected user has no current picks';$cls='warn';}
elseif($y>=$currentRaceYear){$diag='Not a previous year';}
elseif(!$in&&$pc===0&&$hc>0){$diag='History exists only; no current pick rows';$cls='warn';}
?>
<tr>
<td><strong><?=$y?></strong></td>
<td class="<?=$in?'good':'bad'?>"><?=$in?'YES':'NO'?></td>
<td class="<?=$loops?'good':''?>"><?=$loops?'YES':'NO'?></td>
<td><?=uya_h($teams?implode(' | ',$teams):'—')?></td>
<td><?=$pc?></td>
<td class="small"><?=uya_h(uya_detail_text($pickDetail[$y]??[]))?></td>
<td><?=$hc?></td>
<td class="small"><?=uya_h(uya_detail_text($histDetail[$y]??[]))?></td>
<td class="<?=$cls?>"><?=uya_h($diag)?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

<div class="card"><strong>Global years table:</strong> <?=uya_h($yearsMap?implode(', ',array_keys($yearsMap)):'none')?>
<div class="small" style="margin-top:7px">Diagnostic only. No repair is performed here.</div></div>
</div></body></html>

TOOL;

i_ck($checks,'Host is TESTPHP8',$host===HOST_REQ,$host);
i_ck($checks,'Document root available',$root!==''&&is_dir($root),$root);
i_ck($checks,'PHP 7.3 compatible target',PHP_VERSION_ID>=70300,PHP_VERSION);
if($host!==HOST_REQ)$errors[]='REFUSED: TestPHP8-only installer.';
if($root===''||!is_dir($root))$errors[]='Document root unavailable.';
if(PHP_VERSION_ID<70300)$errors[]='PHP 7.3 or newer required.';

$current=is_file($target)?(string)@file_get_contents($target):'';
$safe=$current===''||strpos($current,'VERSION: v001')!==false;
i_ck($checks,'Diagnostic destination safe',$safe,$current===''?'new file':'VERSION: v001 already present');
if(!$safe)$errors[]='REFUSED: unexpected existing admin_user_year_audit.php.';

$pkg=[
['Tool package is v001',strpos($tool,'VERSION: v001')!==false],
['TESTPHP8 guard packaged',strpos($tool,'testphp8.manliusracingleague.com')!==false],
['Admin guard packaged',strpos($tool,'Admin access required')!==false],
['years comparison packaged',strpos($tool,'FROM years')!==false],
['user_teams comparison packaged',strpos($tool,'FROM user_teams')!==false],
['user_picks comparison packaged',strpos($tool,"'user_picks'")!==false],
['history comparison packaged',strpos($tool,"'user_picks_history'")!==false],
];
foreach($pkg as $p){i_ck($checks,$p[0],$p[1],$p[1]?'PASS':'missing');if(!$p[1])$errors[]='Package failure: '.$p[0];}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['install'])&&empty($errors)){
    if(strpos($current,'VERSION: v001')!==false){$installed=true;}
    else{
        if(!is_dir($backupDir)&&!@mkdir($backupDir,0775,true)&&!is_dir($backupDir))$errors[]='Could not create backup folder.';
        if(empty($errors)&&!i_write($target,$tool))$errors[]='Could not install diagnostic.';
        if(empty($errors)){
            $after=(string)@file_get_contents($target);
            $post=[
                ['admin_user_year_audit.php v001 installed',strpos($after,'VERSION: v001')!==false],
                ['User selector installed',strpos($after,'Audit Selected User')!==false],
                ['All-year comparison installed',strpos($after,'All-year source comparison')!==false],
                ['No DB repair SQL added',strpos($after,'INSERT INTO')===false&&strpos($after,'DELETE FROM')===false],
            ];
            foreach($post as $p)if(!$p[1])$errors[]='Postflight failed: '.$p[0];
            if(empty($errors))$installed=true; else @unlink($target);
        }
    }
}
?><!doctype html><html><head><meta charset="utf-8"><title>MRL User Year Audit Installer</title>
<style>:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#111;color:#eee;font:14px Arial}.wrap{max-width:1200px;margin:auto;padding:15px}.banner{background:#3d3212;border:1px solid #9b7200;border-radius:11px;padding:12px 15px}.banner h1{margin:0;color:#fff0c8;font-size:22px}.sub{font-size:12px;color:#dac884;margin-top:4px}.card{background:#1d1d1d;border:1px solid #444;border-radius:9px;padding:12px 14px;margin-top:11px}h2{margin:0 0 8px;color:#ffd08a}table{width:100%;border-collapse:collapse}td{padding:6px;border-bottom:1px solid #333}.ok{color:#5cf09a;font-weight:700}.bad{color:#ff7777;font-weight:700}code{color:#f0d98c}button{background:#443419;color:#ffd08a;border:1px solid #b48636;border-radius:7px;padding:9px 14px;font-weight:700}.success{background:#143b2b}</style></head><body><div class="wrap">
<div class="banner"><h1>MRL User Year Audit Installer v001</h1><div class="sub">TESTPHP8 ONLY • generated 8/21/2026 9:21:00 pm • DB changes: NONE</div></div>
<div class="card"><h2>What I Found</h2>team.php's Previous Years Picks section is driven by the <code>years</code> table, then includes the prior-year chart once per returned year. It does not discover years directly from user_picks.</div>
<div class="card"><h2>Preflight</h2><table><?php foreach($checks as $c): ?><tr><td style="width:43%"><?=i_h($c['n'])?></td><td class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td><td><?=i_h($c['d'])?></td></tr><?php endforeach; ?></table></div>
<?php if($errors): ?><div class="card"><h2 class="bad">STOPPED SAFELY</h2><?php foreach($errors as $e):?><div class="bad">• <?=i_h($e)?></div><?php endforeach;?></div>
<?php elseif(!$installed): ?><div class="card"><h2>Ready to Install</h2><p>Adds only <code>admin_user_year_audit.php</code>. No DB or existing-file changes.</p><form method="post"><button name="install" value="1">INSTALL USER YEAR AUDIT</button></form></div><?php endif; ?>
<?php if($installed): ?><div class="card success"><h2 class="ok">INSTALL COMPLETE</h2><table><?php foreach($post as $p):?><tr><td><?=i_h($p[0])?></td><td class="<?=$p[1]?'ok':'bad'?>"><?=$p[1]?'PASS':'FAIL'?></td></tr><?php endforeach;?></table><p><a href="/admin_user_year_audit.php">Open User Year Audit</a></p></div><?php endif;?>
</div></body></html>
