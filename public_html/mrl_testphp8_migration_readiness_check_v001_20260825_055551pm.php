<?php
/**
 * MRL TESTPHP8 Migration-Oriented Final Readiness Check
 * VERSION: v001
 * LAST MODIFIED: 8/25/2026 5:55:51 pm
 *
 * READ-ONLY. TESTPHP8 only. PHP 7.3 compatible.
 * Scheduler state is informational only and is NOT a migration-readiness gate.
 */

date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
$testRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
$liveRoot = dirname($testRoot);

$errors = array();
$checks = array();
$fileRows = array();
$markerRows = array();
$artifactRows = array();

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function add_check(&$checks, $group, $label, $ok, $detail, $gate) {
    $checks[] = array(
        'group'=>$group, 'label'=>$label, 'ok'=>(bool)$ok,
        'detail'=>$detail, 'gate'=>(bool)$gate
    );
}
function read_version($path) {
    if (!is_file($path) || !is_readable($path)) return '';
    $data = @file_get_contents($path, false, null, 0, 20000);
    if ($data === false) return '';
    if (preg_match('/VERSION:\s*(v[0-9]+)/i', $data, $m) === 1) return $m[1];
    if (preg_match('/\bv([0-9]{3})\b/', $data, $m) === 1) return 'v'.$m[1];
    return '';
}
function has_all($path, $needles) {
    if (!is_file($path) || !is_readable($path)) return false;
    $data = @file_get_contents($path);
    if ($data === false) return false;
    foreach ($needles as $n) {
        if (strpos($data, $n) === false) return false;
    }
    return true;
}
function find_artifacts($root) {
    $found = array();
    if (!is_dir($root) || !is_readable($root)) return $found;

    $patterns = array(
        '/^mrl_lp_rp_/i',
        '/^mrl_rd_be_like_biff_fixture_manager_/i',
        '/^mrl_rp_deadline_test_switch_/i',
        '/^mrl_rp_late_submission_server_gate_test_/i',
        '/^_rd_pending_.*\.json$/i',
        '/lp_rp_edge_marker/i',
        '/^admin_rd_simulation\.php$/i',
        '/^_rd_simulation$/i'
    );

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $info) {
            $path = $info->getPathname();
            if (strpos($path, DIRECTORY_SEPARATOR.'_safe_to_delete_') !== false) continue;
            $name = $info->getFilename();
            foreach ($patterns as $p) {
                if (preg_match($p, $name) === 1) {
                    $found[] = ltrim(substr($path, strlen($root)), '/\\');
                    break;
                }
            }
        }
    } catch (Exception $e) {
        $found[] = '[SCAN ERROR] '.$e->getMessage();
    }
    return $found;
}

if ($host !== $expectedHost) {
    $errors[] = 'REFUSED: TESTPHP8-only. Current host: '.$host;
}
if ($testRoot === '' || !is_dir($testRoot)) {
    $errors[] = 'TESTPHP8 document root unavailable.';
}

$protected = array(
    'team.php'=>'v034',
    'submit-team-picks.php'=>'v011',
    'team_replacement_driver.php'=>'v010',
    'race_results/race_results_rd_helper.php'=>'v005',
    'race_results/race_results_monitor.php'=>'v139',
    'team_chart.php'=>'v018',
    'submitted_teams_count.php'=>'v001',
    'race_results/race_schedule_helper.php'=>'v003',
    'admin_setup.php'=>'v004',
    'pick_window_helper.php'=>'v003',
    'config_mrl.php'=>'v003',
    'form-team-picks.php'=>'v007'
);

if (empty($errors)) {
    $problems = 0;
    foreach ($protected as $rel=>$expected) {
        $full = $testRoot.'/'.$rel;
        $actual = read_version($full);
        $ok = is_file($full) && is_readable($full) && $actual === $expected;
        if (!$ok) $problems++;
        $fileRows[] = array(
            'path'=>$rel,
            'expected'=>$expected,
            'actual'=>$actual,
            'ok'=>$ok,
            'hash'=>(is_file($full) && is_readable($full)) ? @hash_file('sha256', $full) : ''
        );
    }
    add_check(
        $checks, 'Permanent baseline', 'Protected permanent versions',
        $problems === 0,
        $problems === 0 ? 'All 12 permanent phase files are present, readable, and at expected versions.' : $problems.' file/version problem(s) found.',
        true
    );

    $behaviorTests = array(
        array('team.php RP integration', 'team.php', array('race_results_rd_helper.php')),
        array('submit RP handling', 'submit-team-picks.php', array('pick_type', 'RD')),
        array('team_chart privacy gate', 'team_chart.php', array('race_schedule_helper.php', 'startRace')),
        array('submitted teams link target', 'submitted_teams_count.php', array('submitted_teams.php')),
        array('pick-window helper', 'pick_window_helper.php', array('segment'))
    );

    $markerProblems = 0;
    foreach ($behaviorTests as $t) {
        $ok = has_all($testRoot.'/'.$t[1], $t[2]);
        if (!$ok) $markerProblems++;
        $markerRows[] = array(
            'label'=>$t[0], 'path'=>$t[1],
            'markers'=>implode(' + ', $t[2]), 'ok'=>$ok
        );
    }
    add_check(
        $checks, 'Behavior markers', 'Finalized feature markers',
        $markerProblems === 0,
        $markerProblems === 0 ? 'All selected LP/RP, privacy, and pick-window markers are present.' : $markerProblems.' behavior marker check(s) need review.',
        true
    );

    $deps = array(
        'race_results/_race_results_schedule.json',
        'race_results/race_schedule_helper.php',
        'race_results/race_results_rd_helper.php',
        'race_results/race_results_monitor.php',
        'race_results/README_INSTALL_AND_UPDATE.md',
        'submitted_teams.php',
        'team_chart.php',
        'submit-team-picks.php'
    );
    $missing = array();
    foreach ($deps as $rel) {
        if (!file_exists($testRoot.'/'.$rel)) $missing[] = $rel;
    }
    add_check(
        $checks, 'Dependencies', 'Critical migration dependencies',
        count($missing) === 0,
        count($missing) === 0 ? 'All selected migration dependencies are present.' : 'Missing: '.implode(', ', $missing),
        true
    );

    $artifactRows = find_artifacts($testRoot);
    $artifactOk = count($artifactRows) === 0;
    if (count($artifactRows) === 1 && strpos($artifactRows[0], '[SCAN ERROR]') === 0) $artifactOk = false;
    add_check(
        $checks, 'Test debris', 'Active LP/RP fixture/simulation artifacts',
        $artifactOk,
        $artifactOk ? 'No active LP/RP fixture, marker, pending, or simulation artifacts found outside quarantine.' : count($artifactRows).' artifact/scan item(s) found outside quarantine.',
        true
    );

    $quarantines = glob($testRoot.'/_safe_to_delete_*');
    if ($quarantines === false) $quarantines = array();
    add_check(
        $checks, 'Cleanup state', 'Quarantine directories',
        true,
        count($quarantines).' quarantine director'.(count($quarantines) === 1 ? 'y' : 'ies').' present; ignored as active application content.',
        false
    );

    $liveReadable = is_dir($liveRoot) && is_readable($liveRoot);
    $testRR = is_dir($testRoot.'/race_results') && is_readable($testRoot.'/race_results');
    $liveRR = is_dir($liveRoot.'/race_results') && is_readable($liveRoot.'/race_results');

    add_check(
        $checks, 'Migration path', 'LIVE root readable from TESTPHP8',
        $liveReadable,
        $liveReadable ? 'LIVE root readable: '.$liveRoot : 'LIVE root is not readable from this script.',
        true
    );
    add_check(
        $checks, 'Migration path', 'TESTPHP8 race_results readable',
        $testRR,
        $testRR ? 'TESTPHP8 race_results tree is readable.' : 'TESTPHP8 race_results tree is not readable.',
        true
    );
    add_check(
        $checks, 'Migration path', 'LIVE race_results readable',
        $liveRR,
        $liveRR ? 'LIVE race_results tree is readable.' : 'LIVE race_results tree is not readable.',
        true
    );

    add_check(
        $checks, 'Scheduler', 'TESTPHP8 scheduler state',
        true,
        'Informational only. Scheduler state is NOT a migration-readiness gate and does not need to be re-enabled before migration.',
        false
    );
}

$gateFailures = 0;
foreach ($checks as $c) {
    if ($c['gate'] && !$c['ok']) $gateFailures++;
}
$ready = empty($errors) && $gateFailures === 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MRL TESTPHP8 Migration Readiness Check v001</title>
<style>
body{margin:0;background:#111;color:#eee;font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:1550px;margin:16px auto;padding:0 14px}
.banner{border:1px solid #396d36;background:#173717;border-radius:12px;padding:14px 18px}
.banner h1{margin:0 0 4px;color:#e9ffd9;font-size:28px}
.sub{color:#d9efc9}
.card{margin-top:14px;border:1px solid #444;background:#1b1b1b;border-radius:11px;padding:14px 18px}
.notice{margin-top:12px;border:1px solid #2f6590;background:#102c46;border-radius:9px;padding:11px 14px;color:#b9ddff}
.bad{margin-top:12px;border:1px solid #ad3e3e;background:#511818;border-radius:8px;padding:11px 14px;color:#ffb1b1}
h2{margin:2px 0 12px;font-size:24px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:7px 9px;border-bottom:1px solid #353535;text-align:left;vertical-align:top}
th{background:#242424}
.pass{color:#78ef9c;font-weight:bold}
.fail{color:#ff9292;font-weight:bold}
.info{color:#8bc6ff;font-weight:bold}
.path{font-family:Consolas,monospace;color:#cddcff;word-break:break-all}
.hash{font-family:Consolas,monospace;font-size:12px;color:#bbb;word-break:break-all}
.ready{font-size:27px;color:#78ef9c;font-weight:bold}
.notready{font-size:27px;color:#ff9292;font-weight:bold}
.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL TESTPHP8 Migration-Oriented Final Readiness Check v001</h1>
<div class="sub">READ-ONLY • migration focused • scheduler is informational only</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="bad"><?=h($e)?></div>
<?php endforeach; ?>

<div class="notice">
Final TESTPHP8 readiness gate before the direct filesystem comparison with LIVE.
This script changes nothing.
</div>

<div class="card">
<h2>Migration readiness</h2>
<?php if ($ready): ?>
<div class="ready">PASS — TESTPHP8 is ready for TESTPHP8 ↔ LIVE filesystem comparison.</div>
<?php else: ?>
<div class="notready">REVIEW NEEDED — <?=$gateFailures?> migration-readiness gate(s) failed.</div>
<?php endif; ?>
</div>

<div class="card">
<h2>Readiness checks</h2>
<table>
<thead>
<tr>
<th style="width:180px">Group</th>
<th style="width:310px">Check</th>
<th style="width:100px">Result</th>
<th style="width:90px">Gate?</th>
<th>Detail</th>
</tr>
</thead>
<tbody>
<?php foreach ($checks as $c): ?>
<tr>
<td><?=h($c['group'])?></td>
<td><?=h($c['label'])?></td>
<td class="<?=$c['ok'] ? ($c['gate'] ? 'pass' : 'info') : 'fail'?>"><?=$c['ok'] ? ($c['gate'] ? 'PASS' : 'INFO') : 'FAIL'?></td>
<td><?=$c['gate'] ? 'YES' : 'NO'?></td>
<td><?=h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="card">
<h2>Permanent phase files</h2>
<table>
<thead>
<tr>
<th>Path</th><th style="width:100px">Expected</th><th style="width:100px">Found</th><th style="width:90px">Result</th><th>SHA-256</th>
</tr>
</thead>
<tbody>
<?php foreach ($fileRows as $r): ?>
<tr>
<td class="path"><?=h($r['path'])?></td>
<td><?=h($r['expected'])?></td>
<td><?=h($r['actual'])?></td>
<td class="<?=$r['ok'] ? 'pass' : 'fail'?>"><?=$r['ok'] ? 'PASS' : 'FAIL'?></td>
<td class="hash"><?=h($r['hash'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="card">
<h2>Finalized behavior markers</h2>
<table>
<thead>
<tr><th>Behavior</th><th>File</th><th style="width:90px">Result</th><th>Marker(s)</th></tr>
</thead>
<tbody>
<?php foreach ($markerRows as $r): ?>
<tr>
<td><?=h($r['label'])?></td>
<td class="path"><?=h($r['path'])?></td>
<td class="<?=$r['ok'] ? 'pass' : 'fail'?>"><?=$r['ok'] ? 'PASS' : 'FAIL'?></td>
<td class="path"><?=h($r['markers'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="card">
<h2>Active test-artifact discovery</h2>
<?php if (count($artifactRows) === 0): ?>
<div class="pass">PASS — none found outside quarantine.</div>
<?php else: ?>
<table>
<?php foreach ($artifactRows as $rel): ?>
<tr><td class="path"><?=h($rel)?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
</div>

<div class="card small">
<strong>Next step after PASS:</strong>
a separate read-only direct filesystem comparison of
<span class="path"><?=$testRoot?>/race_results</span>
against
<span class="path"><?=$liveRoot?>/race_results</span>,
followed by MIGRATE / KEEP LIVE / OBSOLETE / TEST-ONLY classification.
</div>

</div>
</body>
</html>
