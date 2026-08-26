<?php
/**
 * MRL TESTPHP8 — LP → RP SUBMISSION DIAGNOSTIC
 * VERSION: v001
 * LAST MODIFIED: 8/23/2026 12:35:00 pm
 *
 * PURPOSE
 * -------
 * Read-only diagnostic for the LP → RP HTTP 500 discovered after the public
 * RP form successfully appeared for Be Like Biff / S1 / AJ Allmendinger.
 *
 * This page checks the current database and app-file state WITHOUT WRITING:
 * - current S1 LP row
 * - any S1 RD row created by the failed submit
 * - RD history row count/details
 * - exact pending RP fixture
 * - current app versions
 * - whether submit-team-picks.php contains the expected LP/RD helper functions
 * - whether the submit code can resolve a SEG/ADJ base row versus an LP row
 *
 * SAFETY
 * ------
 * - TESTPHP8 ONLY.
 * - READ ONLY.
 * - No database writes.
 * - No file writes.
 * - No schedule changes.
 * - No POST to submit-team-picks.php.
 *
 * Keep the scheduler OFF and leave the current LP→RP harness/bridge installed
 * while using this diagnostic.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$root = __DIR__;

function dg_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function dg_test_host(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' && strpos($host, 'testphp8.manliusracingleague.com') !== false;
}

function dg_read(string $path): string
{
    $s = @file_get_contents($path);
    return $s === false ? '' : $s;
}

function dg_query_all(PDO $dbo, string $sql, array $params = []): array
{
    $stmt = $dbo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function dg_query_one(PDO $dbo, string $sql, array $params = []): ?array
{
    $rows = dg_query_all($dbo, $sql, $params);
    return !empty($rows) && is_array($rows[0]) ? $rows[0] : null;
}

function dg_find_r07(string $root): array
{
    $dirs = glob($root . '/race_results/2026/R07_*', GLOB_ONLYDIR);
    if (!is_array($dirs) || count($dirs) !== 1) {
        return ['', '', '', count((array)$dirs)];
    }

    $dir = $dirs[0];
    return [
        $dir,
        $dir . '/_rd_pending_Be_Like_Biff.json',
        $dir . '/_rd_pending_Be_Like_Biff.lp_rp_edge_marker_20260823_114100am.json',
        1,
    ];
}

$errors = [];
$checks = [];

if (!dg_test_host()) {
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

$teamSrc = dg_read($teamPath);
$submitSrc = dg_read($submitPath);
$wrapperSrc = dg_read($wrapperPath);
$helperSrc = dg_read($helperPath);

list($raceDir, $fixturePath, $markerPath, $r07Count) = dg_find_r07($root);

$lpRows = [];
$rdRows = [];
$rdHistory = [];
$segAdjRows = [];
$teamRows = [];

if (empty($errors)) {
    $params = [
        ':raceYear' => '2026',
        ':segment' => 'S1',
        ':teamName' => 'Be Like Biff',
    ];

    $teamRows = dg_query_all($dbo, "
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

    $lpRows = array_values(array_filter($teamRows, function ($r) {
        return strtoupper((string)($r['pick_type'] ?? '')) === 'LP';
    }));

    $rdRows = array_values(array_filter($teamRows, function ($r) {
        return strtoupper((string)($r['pick_type'] ?? '')) === 'RD';
    }));

    $segAdjRows = array_values(array_filter($teamRows, function ($r) {
        $t = strtoupper((string)($r['pick_type'] ?? ''));
        return $t === 'SEG' || $t === 'ADJ';
    }));

    $rdHistory = dg_query_all($dbo, "
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

$pendingPayload = null;
if ($fixturePath !== '' && is_file($fixturePath)) {
    $raw = @file_get_contents($fixturePath);
    if ($raw !== false) {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) {
            $pendingPayload = $tmp;
        }
    }
}

$submitFeatureChecks = [
    'mrl_get_segment_base_pick_id' =>
        strpos($submitSrc, 'function mrl_get_segment_base_pick_id') !== false,
    'mrl_get_existing_pick_id_by_type' =>
        strpos($submitSrc, 'function mrl_get_existing_pick_id_by_type') !== false,
    'mrl_get_existing_non_rd_pick_meta' =>
        strpos($submitSrc, 'function mrl_get_existing_non_rd_pick_meta') !== false,
    'mrl_rd_reject' =>
        strpos($submitSrc, 'mrl_rd_reject') !== false,
    'RD override branch' =>
        strpos($submitSrc, "if (\$pickTypeOverride === 'RD')") !== false,
    'SEG/ADJ base lookup' =>
        strpos($submitSrc, "pick_type IN ('SEG', 'ADJ')") !== false,
    'LP awareness somewhere in file' =>
        strpos($submitSrc, "pick_type = 'LP'") !== false
        || strpos($submitSrc, "pick_type <> 'RD'") !== false,
];

$hasLp = count($lpRows) > 0;
$hasRd = count($rdRows) > 0;
$hasRdHistory = count($rdHistory) > 0;
$hasSegAdj = count($segAdjRows) > 0;

$lp = $hasLp ? $lpRows[count($lpRows)-1] : null;
$rd = $hasRd ? $rdRows[count($rdRows)-1] : null;

$checks[] = ['TESTPHP8 host', dg_test_host(), (string)($_SERVER['HTTP_HOST'] ?? '')];
$checks[] = ['team.php current test version', strpos($teamSrc, 'VERSION: v026') !== false, 'expected temporary bridge state v026'];
$checks[] = ['submit-team-picks.php version', strpos($submitSrc, 'VERSION: v007') !== false, 'expected v007'];
$checks[] = ['team_replacement_driver.php version', strpos($wrapperSrc, 'VERSION: v010') !== false, 'expected v010'];
$checks[] = ['race_results_rd_helper.php version', strpos($helperSrc, 'VERSION: v005') !== false, 'expected v005'];
$checks[] = ['Exactly one R07 race folder', $r07Count === 1, $raceDir !== '' ? basename($raceDir) : 'found ' . $r07Count];
$checks[] = ['Pending LP→RP fixture JSON exists', is_array($pendingPayload), $fixturePath !== '' ? basename($fixturePath) : ''];
$checks[] = ['LP→RP fixture marker exists', $markerPath !== '' && is_file($markerPath), $markerPath !== '' ? basename($markerPath) : ''];
$checks[] = ['Current S1 LP row exists', $hasLp, $hasLp ? 'count=' . count($lpRows) : 'none'];
$checks[] = ['Current S1 SEG/ADJ row exists', $hasSegAdj, $hasSegAdj ? 'count=' . count($segAdjRows) : 'none'];
$checks[] = ['Current S1 RD row exists', $hasRd, $hasRd ? 'count=' . count($rdRows) : 'none'];
$checks[] = ['S1 RD history rows exist', $hasRdHistory, 'count=' . count($rdHistory)];

$likelyFailure = '';

if ($hasLp && !$hasSegAdj) {
    $likelyFailure =
        'The current S1 lineup is LP-only. submit-team-picks.php still contains a SEG/ADJ-only '
        . 'base-pick lookup for RD supersedes/validation, which is the most likely LP→RP failure point.';
} elseif ($hasRd || $hasRdHistory) {
    $likelyFailure =
        'The failed HTTP 500 may have occurred after at least part of the RD write path ran. '
        . 'Do not resubmit until the exact rows below are reviewed.';
} else {
    $likelyFailure =
        'No obvious partial RD write is present. The failure likely occurred before RD persistence.';
}

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
.wrap{max-width:1280px;margin:0 auto;padding:14px}
.header{background:#18301f;border:1px solid #4f8b60;border-radius:10px;padding:12px 14px}
h1{margin:0;color:#6dff9f;font-size:23px}
.sub{margin-top:3px;color:#ddd}
.card{background:#1b1b1b;border:1px solid #444;border-radius:9px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#ffc44d;font-size:19px}
table{width:100%;border-collapse:collapse}
th,td{padding:7px 8px;border-bottom:1px solid #343434;text-align:left;vertical-align:top}
th{background:#242424}
.ok{color:#65ef98;font-weight:bold}
.bad{color:#ff7878;font-weight:bold}
.warn{color:#ffd269;font-weight:bold}
.info{color:#b9dfff}
.err{background:#461919;border:1px solid #9b4646;border-radius:8px;padding:9px 11px;margin-top:9px}
.callout{background:#4a2b00;border:2px solid #dc9425;padding:10px 12px;font-weight:bold;margin:9px 0}
code{color:#f2dc9c}
.small{font-size:13px;color:#bbb}
</style>
</head>
<body>
<div class="wrap">

<div class="header">
<h1>MRL LP → RP SUBMISSION DIAGNOSTIC v001</h1>
<div class="sub">TESTPHP8 ONLY • READ ONLY • inspect the HTTP 500 state before any fix or resubmit</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="err"><?=dg_h($e)?></div>
<?php endforeach; ?>

<div class="card">
<h2>Current State Checks</h2>
<table>
<?php foreach ($checks as $c): ?>
<tr>
<td style="width:50%"><?=dg_h($c[0])?></td>
<td style="width:10%" class="<?=$c[1]?'ok':'bad'?>"><?=$c[1]?'PASS':'NO'?></td>
<td><?=dg_h($c[2])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>Likely Failure Direction</h2>
<div class="callout"><?=dg_h($likelyFailure)?></div>
</div>

<div class="card">
<h2>Current user_picks — Be Like Biff / 2026 / S1</h2>
<table>
<tr>
<th>pickID</th><th>type</th><th>effective</th><th>A</th><th>B</th><th>C</th><th>D</th>
<th>supersedes</th><th>entryDate</th><th>submission_id</th>
</tr>
<?php if (empty($teamRows)): ?>
<tr><td colspan="10" class="bad">No rows found.</td></tr>
<?php else: ?>
<?php foreach ($teamRows as $r): ?>
<tr>
<td><?=dg_h($r['pickID'] ?? '')?></td>
<td><?=dg_h($r['pick_type'] ?? '')?></td>
<td><?=dg_h($r['effective_race'] ?? '')?></td>
<td><?=dg_h($r['driverA'] ?? '')?></td>
<td><?=dg_h($r['driverB'] ?? '')?></td>
<td><?=dg_h($r['driverC'] ?? '')?></td>
<td><?=dg_h($r['driverD'] ?? '')?></td>
<td><?=dg_h($r['supersedes_pickID'] ?? '')?></td>
<td><?=dg_h($r['entryDate'] ?? '')?></td>
<td><?=dg_h($r['submission_id'] ?? '')?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</table>
</div>

<div class="card">
<h2>RD History — Be Like Biff / 2026 / S1</h2>
<table>
<tr>
<th>pickID</th><th>effective</th><th>A</th><th>B</th><th>C</th><th>D</th>
<th>supersedes</th><th>entryDate</th><th>submission_id</th>
</tr>
<?php if (empty($rdHistory)): ?>
<tr><td colspan="9" class="info">No RD history rows found.</td></tr>
<?php else: ?>
<?php foreach ($rdHistory as $r): ?>
<tr>
<td><?=dg_h($r['pickID'] ?? '')?></td>
<td><?=dg_h($r['effective_race'] ?? '')?></td>
<td><?=dg_h($r['driverA'] ?? '')?></td>
<td><?=dg_h($r['driverB'] ?? '')?></td>
<td><?=dg_h($r['driverC'] ?? '')?></td>
<td><?=dg_h($r['driverD'] ?? '')?></td>
<td><?=dg_h($r['supersedes_pickID'] ?? '')?></td>
<td><?=dg_h($r['entryDate'] ?? '')?></td>
<td><?=dg_h($r['submission_id'] ?? '')?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</table>
</div>

<div class="card">
<h2>Pending LP→RP Fixture</h2>
<table>
<tr><td>Fixture ID</td><td><?=dg_h(is_array($pendingPayload) ? ($pendingPayload['fixture_id'] ?? '') : '')?></td></tr>
<tr><td>Status</td><td><?=dg_h(is_array($pendingPayload) ? ($pendingPayload['status'] ?? '') : '')?></td></tr>
<tr><td>Qualifier</td><td><?=dg_h(
    is_array($pendingPayload)
        ? (($pendingPayload['qualifiers'][0]['slot'] ?? '') . ' — ' . ($pendingPayload['qualifiers'][0]['driver'] ?? ''))
        : ''
)?></td></tr>
<tr><td>Effective race</td><td><?=dg_h(is_array($pendingPayload) ? ($pendingPayload['effective_race'] ?? '') : '')?></td></tr>
<tr><td>Source pick type</td><td><?=dg_h(is_array($pendingPayload) ? ($pendingPayload['source_pick_type'] ?? '') : '')?></td></tr>
<tr><td>Source pick ID</td><td><?=dg_h(is_array($pendingPayload) ? ($pendingPayload['source_pick_id'] ?? '') : '')?></td></tr>
</table>
</div>

<div class="card">
<h2>submit-team-picks.php Feature Scan</h2>
<table>
<?php foreach ($submitFeatureChecks as $name => $ok): ?>
<tr>
<td><?=dg_h($name)?></td>
<td class="<?=$ok?'ok':'bad'?>"><?=$ok?'FOUND':'NOT FOUND'?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card small">
<strong>READ ONLY:</strong> this page does not write to the database, files, schedule, or submit endpoint.<br>
Keep scheduler OFF. Do not resubmit the RP yet. Send me this diagnostic page/result and we will patch only the exact missing LP→RP submit logic.
</div>

</div>
</body>
</html>
