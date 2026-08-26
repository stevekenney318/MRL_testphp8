<?php
declare(strict_types=1);

/**
 * MRL TESTPHP8 — LP → RP DATABASE VERIFICATION
 * VERSION: v001
 * LAST MODIFIED: 8/23/2026 4:37:00 pm
 *
 * TESTPHP8 ONLY • READ ONLY • NO DB WRITES • NO FILE WRITES
 */

date_default_timezone_set('America/New_York');

$VERSION = 'v001';
$TEAM = 'Be Like Biff';
$YEAR = '2026';
$SEGMENT = 'S1';

function dv_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function dv_test_host(): bool {
    return strtolower((string)($_SERVER['HTTP_HOST'] ?? '')) === 'testphp8.manliusracingleague.com';
}
function dv_query_all(PDO $dbo, string $sql, array $params = []): array {
    $stmt = $dbo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}
function dv_find_type(array $rows, string $type): ?array {
    foreach ($rows as $row) {
        if (strtoupper(trim((string)($row['pick_type'] ?? ''))) === $type) return $row;
    }
    return null;
}
function dv_check(array &$checks, string $label, bool $ok, string $detail): void {
    $checks[] = ['label'=>$label,'ok'=>$ok,'detail'=>$detail];
}
function dv_render_rows(array $rows): void {
    if (empty($rows)) {
        echo '<tr><td colspan="15" class="bad">No rows found.</td></tr>';
        return;
    }
    foreach ($rows as $r) {
        echo '<tr>';
        foreach (['pickID','userID','teamName','raceYear','segment','pick_type','effective_race','driverA','driverB','driverC','driverD','supersedes_pickID','entryDate','submission_id','formID'] as $k) {
            echo '<td>' . dv_h($r[$k] ?? '') . '</td>';
        }
        echo '</tr>';
    }
}

$errors = [];
$checks = [];

if (!dv_test_host()) {
    $errors[] = 'REFUSED: TESTPHP8 host only.';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

if (!isset($dbo) || !($dbo instanceof PDO)) {
    $errors[] = 'PDO database connection $dbo is unavailable.';
}

$currentRows = [];
$historyRows = [];
$yearCurrentRdRows = [];
$yearHistoryRdRows = [];

if (empty($errors)) {
    $params = [':teamName'=>$TEAM, ':raceYear'=>$YEAR, ':segment'=>$SEGMENT];

    $currentRows = dv_query_all($dbo, "
        SELECT pickID,userID,teamName,raceYear,segment,
               driverA,driverB,driverC,driverD,
               entryDate,submission_id,formID,pick_type,
               effective_race,supersedes_pickID
        FROM user_picks
        WHERE teamName=:teamName AND raceYear=:raceYear AND segment=:segment
        ORDER BY pickID ASC
    ", $params);

    $historyRows = dv_query_all($dbo, "
        SELECT pickID,userID,teamName,raceYear,segment,
               driverA,driverB,driverC,driverD,
               entryDate,submission_id,formID,pick_type,
               effective_race,supersedes_pickID
        FROM user_picks_history
        WHERE teamName=:teamName AND raceYear=:raceYear AND segment=:segment
        ORDER BY pickID ASC
    ", $params);

    $yr = [':teamName'=>$TEAM, ':raceYear'=>$YEAR];

    $yearCurrentRdRows = dv_query_all($dbo, "
        SELECT pickID,userID,teamName,raceYear,segment,
               driverA,driverB,driverC,driverD,
               entryDate,submission_id,formID,pick_type,
               effective_race,supersedes_pickID
        FROM user_picks
        WHERE teamName=:teamName AND raceYear=:raceYear AND pick_type='RD'
        ORDER BY pickID ASC
    ", $yr);

    $yearHistoryRdRows = dv_query_all($dbo, "
        SELECT pickID,userID,teamName,raceYear,segment,
               driverA,driverB,driverC,driverD,
               entryDate,submission_id,formID,pick_type,
               effective_race,supersedes_pickID
        FROM user_picks_history
        WHERE teamName=:teamName AND raceYear=:raceYear AND pick_type='RD'
        ORDER BY pickID ASC
    ", $yr);
}

$lpRow = dv_find_type($currentRows, 'LP');
$rdRow = dv_find_type($currentRows, 'RD');

$lpRows = array_values(array_filter($currentRows, function ($r) {
    return strtoupper(trim((string)($r['pick_type'] ?? ''))) === 'LP';
}));
$rdRows = array_values(array_filter($currentRows, function ($r) {
    return strtoupper(trim((string)($r['pick_type'] ?? ''))) === 'RD';
}));
$s1RdHistory = array_values(array_filter($historyRows, function ($r) {
    return strtoupper(trim((string)($r['pick_type'] ?? ''))) === 'RD';
}));

dv_check($checks, 'Exactly one current LP row', count($lpRows) === 1,
    is_array($lpRow) ? 'pickID=' . (string)$lpRow['pickID'] : 'not found');
dv_check($checks, 'Exactly one current RD row', count($rdRows) === 1,
    is_array($rdRow) ? 'pickID=' . (string)$rdRow['pickID'] : 'not found');

if (is_array($lpRow)) {
    dv_check($checks, 'LP effective race = R06', (int)$lpRow['effective_race'] === 6,
        'stored=' . (string)$lpRow['effective_race']);
    dv_check($checks, 'LP Group A = Chase Elliott', trim((string)$lpRow['driverA']) === 'Chase Elliott', (string)$lpRow['driverA']);
    dv_check($checks, 'LP Group B = AJ Allmendinger', trim((string)$lpRow['driverB']) === 'AJ Allmendinger', (string)$lpRow['driverB']);
    dv_check($checks, 'LP Group C = Ryan Blaney', trim((string)$lpRow['driverC']) === 'Ryan Blaney', (string)$lpRow['driverC']);
    dv_check($checks, 'LP Group D = Brad Keselowski', trim((string)$lpRow['driverD']) === 'Brad Keselowski', (string)$lpRow['driverD']);
}

if (is_array($rdRow)) {
    dv_check($checks, 'RD effective race = R08', (int)$rdRow['effective_race'] === 8,
        'stored=' . (string)$rdRow['effective_race']);
    dv_check($checks, 'RD Group A unchanged', trim((string)$rdRow['driverA']) === 'Chase Elliott', (string)$rdRow['driverA']);
    dv_check($checks, 'RD Group B = Ricky Stenhouse Jr.', trim((string)$rdRow['driverB']) === 'Ricky Stenhouse Jr.', (string)$rdRow['driverB']);
    dv_check($checks, 'RD Group C unchanged', trim((string)$rdRow['driverC']) === 'Ryan Blaney', (string)$rdRow['driverC']);
    dv_check($checks, 'RD Group D unchanged', trim((string)$rdRow['driverD']) === 'Brad Keselowski', (string)$rdRow['driverD']);
}

if (is_array($lpRow) && is_array($rdRow)) {
    dv_check($checks, 'RD supersedes_pickID points to LP pickID',
        (int)$rdRow['supersedes_pickID'] === (int)$lpRow['pickID'],
        'LP pickID=' . (string)$lpRow['pickID'] . ' • RD supersedes=' . (string)$rdRow['supersedes_pickID']);
    dv_check($checks, 'Only Group B changed LP → RD',
        trim((string)$lpRow['driverA']) === trim((string)$rdRow['driverA'])
        && trim((string)$lpRow['driverB']) !== trim((string)$rdRow['driverB'])
        && trim((string)$lpRow['driverC']) === trim((string)$rdRow['driverC'])
        && trim((string)$lpRow['driverD']) === trim((string)$rdRow['driverD']),
        (string)$lpRow['driverB'] . ' → ' . (string)$rdRow['driverB']);
}

dv_check($checks, 'At least one S1 RD history row exists', count($s1RdHistory) >= 1,
    'count=' . count($s1RdHistory));

if (is_array($rdRow) && !empty($s1RdHistory) && is_array($lpRow)) {
    $latest = $s1RdHistory[count($s1RdHistory)-1];
    dv_check($checks, 'Latest RD history lineup matches current RD row',
        trim((string)$latest['driverA']) === trim((string)$rdRow['driverA'])
        && trim((string)$latest['driverB']) === trim((string)$rdRow['driverB'])
        && trim((string)$latest['driverC']) === trim((string)$rdRow['driverC'])
        && trim((string)$latest['driverD']) === trim((string)$rdRow['driverD']),
        'history pickID=' . (string)$latest['pickID']);
    dv_check($checks, 'Latest RD history supersedes LP source',
        (int)$latest['supersedes_pickID'] === (int)$lpRow['pickID'],
        'history supersedes=' . (string)$latest['supersedes_pickID']);
}

dv_check($checks, 'Exactly one current RD row for Be Like Biff in 2026',
    count($yearCurrentRdRows) === 1, 'count=' . count($yearCurrentRdRows));
dv_check($checks, 'At least one RD history row for Be Like Biff in 2026',
    count($yearHistoryRdRows) >= 1, 'count=' . count($yearHistoryRdRows));

$allPass = empty($errors);
foreach ($checks as $c) {
    if (!$c['ok']) {
        $allPass = false;
        break;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL LP → RP Database Verification <?=$VERSION?></title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1600px;margin:0 auto;padding:14px}
.banner{background:#24351d;border:1px solid #547c3d;border-radius:10px;padding:12px 14px}
.banner h1{margin:0;color:#dfffcf;font-size:22px}
.sub{font-size:12px;color:#bdd4ae;margin-top:4px}
.card{background:#1b1b1b;border:1px solid #414141;border-radius:9px;padding:12px 14px;margin-top:11px;overflow:auto}
h2{margin:0 0 8px;color:#d5efc9;font-size:18px}
table{width:100%;border-collapse:collapse;min-width:1050px}
th,td{padding:6px 7px;border-bottom:1px solid #333;text-align:left;vertical-align:top}
th{background:#272727}
.ok{color:#69ef98;font-weight:700}
.bad{color:#ff7777;font-weight:700}
.summary-pass{background:#143725;border:1px solid #2f6c48;border-radius:8px;padding:12px 14px;margin-top:11px;font-size:18px;font-weight:bold;color:#69ef98}
.summary-fail{background:#461919;border:1px solid #9b4646;border-radius:8px;padding:12px 14px;margin-top:11px;font-size:18px;font-weight:bold;color:#ff7777}
.note{font-size:12px;color:#bbb}
code{color:#f2d996}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
<h1>MRL LP → RP DATABASE VERIFICATION v001</h1>
<div class="sub">TESTPHP8 ONLY • READ ONLY • Be Like Biff • 2026 S1 • LP R06 → Replacement Pick R08</div>
</div>

<?php foreach ($errors as $e): ?>
<div class="summary-fail"><?=dv_h($e)?></div>
<?php endforeach; ?>

<?php if (empty($errors)): ?>
<div class="<?=$allPass ? 'summary-pass' : 'summary-fail'?>">
<?=$allPass
    ? 'PASS — LP → RP database relationships match the expected test result.'
    : 'REVIEW REQUIRED — one or more database checks did not match the expected test result.'?>
</div>
<?php endif; ?>

<div class="card">
<h2>Verification Checks</h2>
<table style="min-width:800px">
<tr><th style="width:52%">Check</th><th style="width:9%">Result</th><th>Stored / Observed</th></tr>
<?php foreach ($checks as $c): ?>
<tr>
<td><?=dv_h($c['label'])?></td>
<td class="<?=$c['ok'] ? 'ok' : 'bad'?>"><?=$c['ok'] ? 'PASS' : 'FAIL'?></td>
<td><?=dv_h($c['detail'])?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>Current user_picks — Be Like Biff / 2026 / S1</h2>
<table>
<tr>
<th>pickID</th><th>userID</th><th>team</th><th>year</th><th>segment</th><th>type</th><th>effective</th>
<th>A</th><th>B</th><th>C</th><th>D</th><th>supersedes</th><th>entryDate</th><th>submission_id</th><th>formID</th>
</tr>
<?php dv_render_rows($currentRows); ?>
</table>
</div>

<div class="card">
<h2>user_picks_history — Be Like Biff / 2026 / S1</h2>
<table>
<tr>
<th>pickID</th><th>userID</th><th>team</th><th>year</th><th>segment</th><th>type</th><th>effective</th>
<th>A</th><th>B</th><th>C</th><th>D</th><th>supersedes</th><th>entryDate</th><th>submission_id</th><th>formID</th>
</tr>
<?php dv_render_rows($historyRows); ?>
</table>
</div>

<div class="card">
<h2>2026 Replacement Pick Usage — One-Per-Year Review</h2>
<table style="min-width:900px">
<tr><th>Location</th><th>Count</th><th>Meaning</th></tr>
<tr><td>Current user_picks RD rows</td><td><?=dv_h((string)count($yearCurrentRdRows))?></td><td>Expected: exactly 1 current Replacement Pick row.</td></tr>
<tr><td>user_picks_history RD rows</td><td><?=dv_h((string)count($yearHistoryRdRows))?></td><td>Expected: at least 1 audit row; more are valid if the same RP was edited before deadline.</td></tr>
</table>
</div>

<div class="card note">
READ ONLY. SELECT queries only. No database changes, no file changes, no fixture changes, no scheduler changes.
</div>

</div>
</body>
</html>
