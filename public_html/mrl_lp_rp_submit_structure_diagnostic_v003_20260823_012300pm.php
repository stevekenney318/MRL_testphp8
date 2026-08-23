<?php
/**
 * MRL TESTPHP8 — LP → RP SUBMIT STRUCTURE DIAGNOSTIC
 * VERSION: v003
 * LAST MODIFIED: 8/23/2026 1:23:00 pm
 *
 * PURPOSE
 * -------
 * Read-only structural diagnostic for the current submit-team-picks.php after
 * the failed LP → RP submissions.
 *
 * This page does NOT run submit-team-picks.php.  It only reads the file as
 * text and reports WHERE important variables/blocks occur, so we can detect
 * ordering mistakes such as a deadline check running before its variables are
 * initialized.
 *
 * It also rechecks that no RD row/history was written.
 *
 * SAFETY
 * ------
 * TESTPHP8 ONLY. READ ONLY.
 * No DB writes. No file writes. No POST. No schedule changes.
 *
 * Keep the scheduler OFF.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/New_York');

$VERSION = 'v003';
$root = __DIR__;

function sd_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function sd_test_host(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' && strpos($host, 'testphp8.manliusracingleague.com') !== false;
}

function sd_read(string $path): string {
    $s = @file_get_contents($path);
    return $s === false ? '' : $s;
}

function sd_lines(string $src): array {
    $lines = preg_split('/\R/', $src);
    return is_array($lines) ? $lines : [];
}

function sd_find_all(array $lines, string $needle): array {
    $hits = [];
    foreach ($lines as $i => $line) {
        if (strpos($line, $needle) !== false) {
            $hits[] = $i + 1;
        }
    }
    return $hits;
}

function sd_find_regex(array $lines, string $pattern): array {
    $hits = [];
    foreach ($lines as $i => $line) {
        if (@preg_match($pattern, $line)) {
            if (preg_match($pattern, $line)) {
                $hits[] = $i + 1;
            }
        }
    }
    return $hits;
}

function sd_first(array $hits): int {
    return empty($hits) ? 0 : (int)$hits[0];
}

function sd_window(array $lines, int $center, int $before = 12, int $after = 22): string {
    if ($center <= 0 || empty($lines)) return '';

    $start = max(1, $center - $before);
    $end = min(count($lines), $center + $after);
    $out = [];

    for ($n = $start; $n <= $end; $n++) {
        $out[] = str_pad((string)$n, 5, ' ', STR_PAD_LEFT) . ' | ' . $lines[$n - 1];
    }

    return implode("\n", $out);
}

function sd_query_count(PDO $dbo, string $table, string $type): int {
    $sql = "
        SELECT COUNT(*)
        FROM {$table}
        WHERE raceYear = '2026'
          AND segment = 'S1'
          AND teamName = 'Be Like Biff'
          AND pick_type = :pick_type
    ";
    $stmt = $dbo->prepare($sql);
    $stmt->execute([':pick_type' => $type]);
    return (int)$stmt->fetchColumn();
}

$errors = [];

if (!sd_test_host()) {
    $errors[] = 'REFUSED: TESTPHP8 only.';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

if (!isset($dbo) || !($dbo instanceof PDO)) {
    $errors[] = 'PDO database connection $dbo is unavailable.';
}

$submitPath = $root . '/submit-team-picks.php';
$teamPath = $root . '/team.php';
$wrapperPath = $root . '/team_replacement_driver.php';
$helperPath = $root . '/race_results/race_results_rd_helper.php';

$submitSrc = sd_read($submitPath);
$teamSrc = sd_read($teamPath);
$wrapperSrc = sd_read($wrapperPath);
$helperSrc = sd_read($helperPath);
$lines = sd_lines($submitSrc);

$version = 'unknown';
if (preg_match('/VERSION:\s*(v\d+)/', $submitSrc, $m)) {
    $version = $m[1];
}

$hits = [
    'pickTypeOverride assign' => sd_find_regex($lines, '/\$pickTypeOverride\s*=/'),
    'raceYearStr assign' => sd_find_regex($lines, '/\$raceYearStr\s*=/'),
    'raceYearInt assign' => sd_find_regex($lines, '/\$raceYearInt\s*=/'),
    'effectiveRace assign' => sd_find_regex($lines, '/\$effectiveRace\s*=/'),
    'activeSegment assign' => sd_find_regex($lines, '/\$activeSegment\s*=/'),
    'teamName assign' => sd_find_regex($lines, '/\$teamName\s*=/'),
    'rdSelectedSlotPost assign' => sd_find_regex($lines, '/\$rdSelectedSlotPost\s*=/'),
    'rdSelectedDriverPost assign' => sd_find_regex($lines, '/\$rdSelectedDriverPost\s*=/'),
    'RD branch marker text' => sd_find_all($lines, "pickTypeOverride"),
    'deadline open call' => sd_find_all($lines, '$rdDeadlineOpen = mrl_lp_effective_race_is_open'),
    'base lookup function' => sd_find_all($lines, 'function mrl_get_segment_base_pick_id'),
    'base lookup call' => sd_find_regex($lines, '/mrl_get_segment_base_pick_id\s*\(/'),
    'existing non-RD meta call' => sd_find_regex($lines, '/mrl_get_existing_non_rd_pick_meta\s*\(/'),
    'RD reject calls' => sd_find_regex($lines, '/mrl_rd_reject\s*\(/'),
    'RD INSERT/UPDATE references' => sd_find_regex($lines, '/\b(INSERT|UPDATE)\b.*user_picks/i'),
];

$deadlineLine = sd_first($hits['deadline open call']);
$raceYearIntLine = sd_first($hits['raceYearInt assign']);
$effectiveRaceLine = sd_first($hits['effectiveRace assign']);
$pickTypeLine = sd_first($hits['pickTypeOverride assign']);

$orderProblem = false;
$orderReasons = [];

if ($deadlineLine > 0) {
    if ($raceYearIntLine === 0 || $deadlineLine < $raceYearIntLine) {
        $orderProblem = true;
        $orderReasons[] = 'deadline check appears before $raceYearInt is assigned';
    }
    if ($effectiveRaceLine === 0 || $deadlineLine < $effectiveRaceLine) {
        $orderProblem = true;
        $orderReasons[] = 'deadline check appears before $effectiveRace is assigned';
    }
    if ($pickTypeLine === 0 || $deadlineLine < $pickTypeLine) {
        $orderProblem = true;
        $orderReasons[] = 'deadline check appears before $pickTypeOverride is assigned';
    }
}

$rdCount = 0;
$rdHistoryCount = 0;
$lpCount = 0;

if (empty($errors)) {
    $rdCount = sd_query_count($dbo, 'user_picks', 'RD');
    $rdHistoryCount = sd_query_count($dbo, 'user_picks_history', 'RD');
    $lpCount = sd_query_count($dbo, 'user_picks', 'LP');
}

$interestingCenters = [];
foreach ([
    $pickTypeLine,
    $raceYearIntLine,
    $effectiveRaceLine,
    $deadlineLine,
    sd_first($hits['base lookup call']),
    sd_first($hits['existing non-RD meta call']),
] as $c) {
    if ($c > 0 && !in_array($c, $interestingCenters, true)) {
        $interestingCenters[] = $c;
    }
}

?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>MRL LP → RP Submit Structure Diagnostic <?=$VERSION?></title>
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
pre{white-space:pre-wrap;word-break:break-word;background:#0b0b0b;border:1px solid #333;padding:10px;max-height:560px;overflow:auto}
code{color:#f2dc9c}.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="header">
<h1>MRL LP → RP SUBMIT STRUCTURE DIAGNOSTIC v003</h1>
<div class="sub">TESTPHP8 ONLY • READ ONLY • variable/block ordering • current submit source structure • no execution of submit handler</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="err"><?=sd_h($e)?></div>
<?php endforeach; ?>

<div class="card">
<h2>Current Test State</h2>
<table>
<tr><td>submit-team-picks.php</td><td><?=sd_h($version)?></td></tr>
<tr><td>team.php bridge</td><td class="<?=strpos($teamSrc,'VERSION: v026')!==false?'ok':'bad'?>"><?=strpos($teamSrc,'VERSION: v026')!==false?'v026':'unexpected'?></td></tr>
<tr><td>wrapper/helper</td><td>v010 / v005</td></tr>
<tr><td>S1 LP rows</td><td><?=sd_h((string)$lpCount)?></td></tr>
<tr><td>S1 RD rows</td><td class="<?=$rdCount===0?'ok':'bad'?>"><?=sd_h((string)$rdCount)?></td></tr>
<tr><td>S1 RD history rows</td><td class="<?=$rdHistoryCount===0?'ok':'bad'?>"><?=sd_h((string)$rdHistoryCount)?></td></tr>
</table>
</div>

<div class="card">
<h2>Ordering Check</h2>
<table>
<tr><td>$pickTypeOverride first assignment</td><td><?=sd_h((string)$pickTypeLine)?></td></tr>
<tr><td>$raceYearInt first assignment</td><td><?=sd_h((string)$raceYearIntLine)?></td></tr>
<tr><td>$effectiveRace first assignment</td><td><?=sd_h((string)$effectiveRaceLine)?></td></tr>
<tr><td>RD deadline-open call</td><td><?=sd_h((string)$deadlineLine)?></td></tr>
</table>

<div class="callout">
<?php if ($orderProblem): ?>
<span class="bad">LIKELY STRUCTURAL BUG FOUND:</span>
<?=sd_h(implode('; ', $orderReasons))?>.
<?php else: ?>
<span class="ok">No obvious variable-order problem detected from these four markers.</span>
<?php endif; ?>
</div>
</div>

<div class="card">
<h2>All Key Marker Locations</h2>
<table>
<tr><th>Marker</th><th>Line(s)</th></tr>
<?php foreach ($hits as $name => $list): ?>
<tr>
<td><?=sd_h($name)?></td>
<td><?=sd_h(empty($list) ? 'NOT FOUND' : implode(', ', $list))?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php foreach ($interestingCenters as $center): ?>
<div class="card">
<h2>Source Context Around Line <?=sd_h((string)$center)?></h2>
<pre><?=sd_h(sd_window($lines, $center, 16, 34))?></pre>
</div>
<?php endforeach; ?>

<div class="card small">
READ ONLY. This page does not execute submit-team-picks.php and does not modify the DB or files.<br>
Keep the scheduler OFF. Save/send this page and we can make the next fix from the actual current source ordering rather than another guess.
</div>

</div>
</body>
</html>
