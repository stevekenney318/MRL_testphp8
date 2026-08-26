<?php
/**
 * MRL TESTPHP8 — LP → RP SUBMISSION DIAGNOSTIC v002
 * VERSION: v002
 * LAST MODIFIED: 8/23/2026 12:58:00 pm
 *
 * PURPOSE
 * -------
 * Read-only follow-up after the second LP → RP HTTP 500.
 *
 * This version:
 * - rechecks whether the second failed submission wrote any RD data;
 * - reads the CURRENT submit-team-picks.php test version;
 * - shows the exact RD branch / LP-base lookup source context;
 * - checks common local PHP error_log locations and shows only lines that
 *   mention submit-team-picks.php / Fatal error / Uncaught / TypeError /
 *   PDOException / mysqli / SQLSTATE;
 * - performs no writes of any kind.
 *
 * SAFETY
 * ------
 * TESTPHP8 ONLY. READ ONLY. No DB writes, no file writes, no POST, no schedule
 * changes. Keep the scheduler OFF and leave the current LP→RP harness/bridges
 * installed while using this page.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/New_York');

$VERSION = 'v002';
$root = __DIR__;

function d2_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function d2_test_host(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' && strpos($host, 'testphp8.manliusracingleague.com') !== false;
}

function d2_read(string $path): string {
    $s = @file_get_contents($path);
    return $s === false ? '' : $s;
}

function d2_query_all(PDO $dbo, string $sql, array $params = []): array {
    $stmt = $dbo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function d2_source_window(string $src, string $needle, int $before = 22, int $after = 45): array {
    $lines = preg_split('/\R/', $src);
    if (!is_array($lines)) return ['found' => false, 'text' => ''];

    $hit = -1;
    foreach ($lines as $i => $line) {
        if (strpos($line, $needle) !== false) {
            $hit = $i;
            break;
        }
    }

    if ($hit < 0) return ['found' => false, 'text' => ''];

    $start = max(0, $hit - $before);
    $end = min(count($lines) - 1, $hit + $after);
    $out = [];

    for ($i = $start; $i <= $end; $i++) {
        $out[] = str_pad((string)($i + 1), 5, ' ', STR_PAD_LEFT) . ' | ' . $lines[$i];
    }

    return ['found' => true, 'text' => implode("\n", $out), 'line' => $hit + 1];
}

function d2_error_log_excerpt(string $path): string {
    if (!is_file($path) || !is_readable($path)) return '';

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return '';

    $lines = preg_split('/\R/', $raw);
    if (!is_array($lines)) return '';

    $matches = [];
    $patterns = [
        'submit-team-picks.php',
        'Fatal error',
        'Uncaught',
        'TypeError',
        'PDOException',
        'mysqli',
        'SQLSTATE',
        'ArgumentCountError',
        'ValueError',
        'Error:',
    ];

    foreach ($lines as $line) {
        foreach ($patterns as $p) {
            if (stripos($line, $p) !== false) {
                $matches[] = $line;
                break;
            }
        }
    }

    if (count($matches) > 80) {
        $matches = array_slice($matches, -80);
    }

    return implode("\n", $matches);
}

$errors = [];

if (!d2_test_host()) {
    $errors[] = 'REFUSED: this diagnostic may run only on testphp8.manliusracingleague.com.';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

if (!isset($dbo) || !($dbo instanceof PDO)) {
    $errors[] = 'PDO database connection $dbo is unavailable.';
}

$teamPath = $root . '/team.php';
$submitPath = $root . '/submit-team-picks.php';
$wrapperPath = $root . '/team_replacement_driver.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';

$teamSrc = d2_read($teamPath);
$submitSrc = d2_read($submitPath);
$wrapperSrc = d2_read($wrapperPath);
$helperSrc = d2_read($helperPath);

$params = [
    ':raceYear' => '2026',
    ':segment' => 'S1',
    ':teamName' => 'Be Like Biff',
];

$pickRows = [];
$historyRows = [];

if (empty($errors)) {
    $pickRows = d2_query_all($dbo, "
        SELECT pickID, userID, teamName, raceYear, segment,
               driverA, driverB, driverC, driverD,
               entryDate, submission_id, formID, pick_type,
               effective_race, supersedes_pickID
        FROM user_picks
        WHERE raceYear = :raceYear
          AND segment = :segment
          AND teamName = :teamName
        ORDER BY pickID ASC
    ", $params);

    $historyRows = d2_query_all($dbo, "
        SELECT pickID, userID, teamName, raceYear, segment,
               driverA, driverB, driverC, driverD,
               entryDate, submission_id, formID, pick_type,
               effective_race, supersedes_pickID
        FROM user_picks_history
        WHERE raceYear = :raceYear
          AND segment = :segment
          AND teamName = :teamName
          AND pick_type = 'RD'
        ORDER BY pickID ASC
    ", $params);
}

$rdRows = array_values(array_filter($pickRows, function ($r) {
    return strtoupper((string)($r['pick_type'] ?? '')) === 'RD';
}));

$lpRows = array_values(array_filter($pickRows, function ($r) {
    return strtoupper((string)($r['pick_type'] ?? '')) === 'LP';
}));

$rdBranch = d2_source_window($submitSrc, "\$pickTypeOverride === 'RD'", 28, 120);
$baseLookup = d2_source_window($submitSrc, "pick_type IN ('SEG', 'ADJ', 'LP')", 25, 70);
$deadlineGate = d2_source_window($submitSrc, '$rdDeadlineOpen = mrl_lp_effective_race_is_open', 18, 65);
$supersedes = d2_source_window($submitSrc, 'rd_supersedes_pick_id', 30, 70);

$logCandidates = [
    $root . '/error_log',
    $root . '/php_error.log',
    dirname($root) . '/error_log',
    $_SERVER['DOCUMENT_ROOT'] . '/error_log',
];

$seen = [];
$logResults = [];
foreach ($logCandidates as $p) {
    $real = realpath($p);
    $key = $real !== false ? $real : $p;
    if (isset($seen[$key])) continue;
    $seen[$key] = true;

    $excerpt = d2_error_log_excerpt($p);
    if ($excerpt !== '') {
        $logResults[] = ['path' => $p, 'excerpt' => $excerpt];
    }
}

$partialWrite = count($rdRows) > 0 || count($historyRows) > 0;

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL LP → RP Submission Diagnostic <?=$VERSION?></title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:15px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1400px;margin:0 auto;padding:14px}
.header{background:#18301f;border:1px solid #4f8b60;border-radius:10px;padding:12px 14px}
h1{margin:0;color:#6dff9f;font-size:23px}.sub{color:#ddd}
.card{background:#1b1b1b;border:1px solid #444;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#ffc44d;font-size:19px}
table{width:100%;border-collapse:collapse}
th,td{padding:7px 8px;border-bottom:1px solid #343434;text-align:left;vertical-align:top}
th{background:#242424}
.ok{color:#65ef98;font-weight:bold}.bad{color:#ff7878;font-weight:bold}.warn{color:#ffd269;font-weight:bold}
.err{background:#461919;border:1px solid #9b4646;border-radius:8px;padding:9px 11px;margin-top:9px}
.callout{background:#4a2b00;border:2px solid #dc9425;padding:10px 12px;font-weight:bold;margin:9px 0}
pre{white-space:pre-wrap;word-break:break-word;background:#0d0d0d;border:1px solid #333;padding:10px;max-height:620px;overflow:auto;color:#e8e8e8}
code{color:#f2dc9c}.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="header">
<h1>MRL LP → RP SUBMISSION DIAGNOSTIC v002</h1>
<div class="sub">TESTPHP8 ONLY • READ ONLY • second HTTP 500 • DB state + exact submit-handler source context + local PHP error logs</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="err"><?=d2_h($e)?></div>
<?php endforeach; ?>

<div class="card">
<h2>Immediate Safety Check</h2>
<table>
<tr><td>team.php</td><td class="<?=strpos($teamSrc,'VERSION: v026')!==false?'ok':'bad'?>"><?=strpos($teamSrc,'VERSION: v026')!==false?'v026 PASS':'unexpected'?></td></tr>
<tr><td>submit-team-picks.php</td><td class="<?=strpos($submitSrc,'VERSION: v008')!==false?'ok':'bad'?>"><?=strpos($submitSrc,'VERSION: v008')!==false?'v008 PASS':'unexpected'?></td></tr>
<tr><td>wrapper/helper</td><td class="<?=strpos($wrapperSrc,'VERSION: v010')!==false && strpos($helperSrc,'VERSION: v005')!==false?'ok':'bad'?>">v010 / v005</td></tr>
<tr><td>LP row count</td><td><?=d2_h((string)count($lpRows))?></td></tr>
<tr><td>RD row count after second 500</td><td class="<?=count($rdRows)===0?'ok':'bad'?>"><?=d2_h((string)count($rdRows))?></td></tr>
<tr><td>RD history count after second 500</td><td class="<?=count($historyRows)===0?'ok':'bad'?>"><?=d2_h((string)count($historyRows))?></td></tr>
</table>

<div class="callout">
<?php if ($partialWrite): ?>
PARTIAL WRITE DETECTED. Do not resubmit. Review rows below before any fix.
<?php else: ?>
NO PARTIAL RD WRITE DETECTED. Both user_picks RD and RD history remain empty after the second HTTP 500.
<?php endif; ?>
</div>
</div>

<div class="card">
<h2>Current Be Like Biff / 2026 / S1 user_picks</h2>
<table>
<tr><th>pickID</th><th>type</th><th>effective</th><th>A</th><th>B</th><th>C</th><th>D</th><th>supersedes</th><th>entryDate</th><th>submission_id</th></tr>
<?php foreach ($pickRows as $r): ?>
<tr>
<td><?=d2_h($r['pickID'] ?? '')?></td>
<td><?=d2_h($r['pick_type'] ?? '')?></td>
<td><?=d2_h($r['effective_race'] ?? '')?></td>
<td><?=d2_h($r['driverA'] ?? '')?></td>
<td><?=d2_h($r['driverB'] ?? '')?></td>
<td><?=d2_h($r['driverC'] ?? '')?></td>
<td><?=d2_h($r['driverD'] ?? '')?></td>
<td><?=d2_h($r['supersedes_pickID'] ?? '')?></td>
<td><?=d2_h($r['entryDate'] ?? '')?></td>
<td><?=d2_h($r['submission_id'] ?? '')?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>Local PHP Error Log Matches</h2>
<?php if (empty($logResults)): ?>
<div class="warn">No readable matching local error-log lines were found in the common locations checked.</div>
<?php else: ?>
<?php foreach ($logResults as $log): ?>
<div><code><?=d2_h($log['path'])?></code></div>
<pre><?=d2_h($log['excerpt'])?></pre>
<?php endforeach; ?>
<?php endif; ?>
</div>

<div class="card">
<h2>RD Override Branch Context</h2>
<div class="<?=$rdBranch['found']?'ok':'bad'?>"><?=$rdBranch['found']?'FOUND near line '.d2_h($rdBranch['line']):'NOT FOUND'?></div>
<pre><?=d2_h($rdBranch['text'] ?? '')?></pre>
</div>

<div class="card">
<h2>LP-Capable RD Base Lookup Context</h2>
<div class="<?=$baseLookup['found']?'ok':'bad'?>"><?=$baseLookup['found']?'FOUND near line '.d2_h($baseLookup['line']):'NOT FOUND'?></div>
<pre><?=d2_h($baseLookup['text'] ?? '')?></pre>
</div>

<div class="card">
<h2>Controlled Historical Deadline Gate Context</h2>
<div class="<?=$deadlineGate['found']?'ok':'bad'?>"><?=$deadlineGate['found']?'FOUND near line '.d2_h($deadlineGate['line']):'NOT FOUND'?></div>
<pre><?=d2_h($deadlineGate['text'] ?? '')?></pre>
</div>

<div class="card">
<h2>RD Supersedes POST/Validation Context</h2>
<div class="<?=$supersedes['found']?'ok':'bad'?>"><?=$supersedes['found']?'FOUND near line '.d2_h($supersedes['line']):'NOT FOUND'?></div>
<pre><?=d2_h($supersedes['text'] ?? '')?></pre>
</div>

<div class="card small">
READ ONLY. No writes or submissions were performed. Keep scheduler OFF and do not submit again yet.
Send me this page/file and I can patch the exact failing statement instead of making another assumption.
</div>

</div>
</body>
</html>
