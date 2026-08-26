<?php
/**
 * MRL TESTPHP8 team.php RP Marker Diagnostic
 * VERSION: v001
 * LAST MODIFIED: 8/25/2026 6:05:26 pm
 *
 * READ-ONLY. TESTPHP8 only. PHP 7.3 compatible.
 * Purpose: explain why the migration-readiness behavior marker
 * "race_results_rd_helper.php" was not found in team.php v034.
 */

date_default_timezone_set('America/New_York');

$expectedHost='testphp8.manliusracingleague.com';
$host=isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
$root=isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'],'/') : '';
$team=$root.'/team.php';
$helper=$root.'/race_results/race_results_rd_helper.php';

$errors=array();
$matches=array();
$version='';
$hash='';

function h($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

if($host!==$expectedHost){ $errors[]='REFUSED: TESTPHP8-only. Current host: '.$host; }
if($root==='' || !is_dir($root)){ $errors[]='Document root unavailable.'; }
if(empty($errors) && !is_file($team)){ $errors[]='team.php not found.'; }

if(empty($errors)){
    $data=@file_get_contents($team);
    if($data===false){
        $errors[]='Could not read team.php.';
    }else{
        if(preg_match('/VERSION:\s*(v[0-9]+)/i',$data,$m)===1){ $version=$m[1]; }
        $hash=@hash_file('sha256',$team);

        $needles=array(
            'race_results_rd_helper.php',
            'rd_helper',
            'replacement',
            'Replacement',
            'pick_type',
            "'RD'",
            '"RD"',
            'mrl_rd',
            'rd_status',
            'team_replacement_driver.php'
        );

        $lines=preg_split('/\R/',$data);
        foreach($lines as $i=>$line){
            foreach($needles as $needle){
                if(strpos($line,$needle)!==false){
                    $matches[]=array(
                        'line'=>$i+1,
                        'needle'=>$needle,
                        'text'=>$line
                    );
                    break;
                }
            }
        }
    }
}

$helperExists=is_file($helper);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MRL TESTPHP8 team.php RP Marker Diagnostic v001</title>
<style>
body{margin:0;background:#111;color:#eee;font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:1500px;margin:16px auto;padding:0 14px}
.banner{border:1px solid #396d36;background:#173717;border-radius:12px;padding:14px 18px}
.banner h1{margin:0 0 4px;color:#e9ffd9;font-size:28px}
.card{margin-top:14px;border:1px solid #444;background:#1b1b1b;border-radius:11px;padding:14px 18px}
.bad{margin-top:12px;border:1px solid #ad3e3e;background:#511818;border-radius:8px;padding:11px 14px;color:#ffb1b1}
.pass{color:#78ef9c;font-weight:bold}
.info{color:#8bc6ff;font-weight:bold}
.path{font-family:Consolas,monospace;color:#cddcff}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:7px 9px;border-bottom:1px solid #353535;text-align:left;vertical-align:top}
th{background:#242424}
.code{font-family:Consolas,monospace;white-space:pre-wrap;word-break:break-word;color:#ddd}
</style>
</head>
<body>
<div class="wrap">
<div class="banner">
<h1>MRL TESTPHP8 team.php RP Marker Diagnostic v001</h1>
<div>READ-ONLY • explains the single readiness-marker failure</div>
</div>

<?php foreach($errors as $e): ?>
<div class="bad"><?=h($e)?></div>
<?php endforeach; ?>

<?php if(empty($errors)): ?>
<div class="card">
<h2>File status</h2>
<table>
<tr><td style="width:260px">team.php version</td><td class="<?=$version==='v034'?'pass':'info'?>"><?=h($version)?></td></tr>
<tr><td>team.php SHA-256</td><td class="path"><?=h($hash)?></td></tr>
<tr><td>RD helper file exists</td><td class="<?=$helperExists?'pass':'bad'?>"><?=$helperExists?'YES':'NO'?></td></tr>
<tr><td>Literal <span class="path">race_results_rd_helper.php</span> in team.php</td><td class="info"><?=strpos(@file_get_contents($team),'race_results_rd_helper.php')!==false?'YES':'NO'?></td></tr>
</table>
</div>

<div class="card">
<h2>RP/RD-related lines found in team.php</h2>
<?php if(count($matches)===0): ?>
<div class="bad">No related lines found using the diagnostic search terms.</div>
<?php else: ?>
<table>
<thead><tr><th style="width:80px">Line</th><th style="width:180px">Matched</th><th>Source line</th></tr></thead>
<tbody>
<?php foreach($matches as $r): ?>
<tr>
<td><?=h($r['line'])?></td>
<td class="path"><?=h($r['needle'])?></td>
<td class="code"><?=h($r['text'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<div class="card">
<strong>Interpretation:</strong> if team.php is still v034 and the RP/RD-related lines are present,
the migration-readiness failure was a too-specific literal marker check, not evidence that the RP integration is broken.
</div>
<?php endif; ?>

</div>
</body>
</html>
