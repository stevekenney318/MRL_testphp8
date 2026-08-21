<?php
declare(strict_types=1);

/**
 * admin_rd_simulation.php
 *
 * VERSION: v005
 * LAST MODIFIED: 8/21/2026 12:01:00 am
 *
 * PURPOSE:
 * TESTPHP8-only RD diagnostic/simulation harness.
 *
 * This page:
 * - Reads the same saved snapshot choice currently used by race_results_monitor
 *   (lexicographically latest snapshot_*.html in each completed race folder).
 * - Copies parsed point data into isolated _rd_simulation fixtures.
 * - Re-parses those fixtures with rrs_load_snapshot_driver_points().
 * - Allows ONE driver to be overridden across selected completed races.
 * - Runs the shared RD v005 eligibility engine.
 * - ALSO shows underlying trailing-pair eligibility when the real season-used
 *   guard reports RD_ALREADY_USED. This diagnostic display does not weaken or
 *   bypass the real RD protection.
 *
 * CHANGELOG:
 * v005 (8/21/2026 12:01:00 am)
 * - NEW: "Tom test" guard refuses simulation when one or more race NET values
 *   are entered but no driver is selected.
 * - NEW: Clear error message: "Select a driver for the simulated race values."
 * - PRESERVE: Race-centric per-race NET inputs, fixture audit, normalized driver
 *   matching, underlying-eligibility diagnostics, and all read-only safeguards.
 *
 * v004 (8/20/2026 11:27:00 pm)
 * - CHANGE: Simulator input is now race-centric: each completed race has its
 *   own optional NET value field; blank means use saved snapshot value.
 * - FIX: Override matching now normalizes/case-folds driver names instead of
 *   requiring an exact array-key match.
 * - NEW: If a selected driver is absent from a completed source snapshot, an
 *   isolated synthetic fixture row can be added for simulation only.
 * - NEW: Selected-driver audit shows saved NET, simulated NET, row match, and
 *   override state for every completed race.
 * - REMOVE: Shared NET field + race override checkboxes.
 *
 * v003 (8/20/2026 11:05:00 pm)
 * - FIX: Simulation fixture header now uses TD cells so the shared snapshot
 *   parser's ".//tr[td]" pass can see the header row and begin parsing data.
 * - PRESERVE: Same fixture columns/data, same real parser, no DB or snapshot writes.
 *
 * v002 (8/20/2026 10:50:00 pm)
 * - NEW: Underlying Eligibility is calculated/displayed even when current DB
 *   history correctly reports RD_ALREADY_USED.
 * - NEW: Real Guard and Underlying Eligibility are shown separately.
 * - PRESERVE: No DB writes, no normal snapshot changes, no RD submission.
 *
 * v001 (8/20/2026 10:27:00 pm)
 * - Initial TestPHP8 RD simulation harness.
 *
 * It NEVER:
 * - changes normal race snapshots;
 * - changes user_picks or user_picks_history;
 * - changes standings;
 * - submits an RD;
 * - writes to the DB.
 *
 * PHP: 7.3 compatible.
 */

session_start();
date_default_timezone_set('America/New_York');

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if ($host !== 'testphp8.manliusracingleague.com') {
    http_response_code(403);
    exit('REFUSED: RD Simulation is TESTPHP8-only.');
}

require_once dirname(__DIR__) . '/class.user.php';
$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('../login.php');
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/config_mrl.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';
require_once __DIR__ . '/race_results_rd_helper.php';

$uid = (int)($_SESSION['userSession'] ?? 0);
if (!function_exists('isAdmin') || !isAdmin($uid)) {
    http_response_code(403);
    exit('REFUSED: Admin access required.');
}

function rds_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rds_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function rds_point_races(array $yearIndex, string $yearFolder): array
{
    $out = [];
    $races = isset($yearIndex['races']) && is_array($yearIndex['races'])
        ? $yearIndex['races']
        : [];

    foreach ($races as $raceId => $row) {
        if (!is_array($row) || (string)($row['kind'] ?? '') !== 'R') {
            continue;
        }

        $number = (int)($row['number'] ?? 0);
        $folder = trim((string)($row['folder'] ?? ''));

        if ($number <= 0 || $folder === '') {
            continue;
        }

        $out[$number] = [
            'number' => $number,
            'raceCode' => 'R' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'folder' => $folder,
            'raceFolder' => $yearFolder . '/' . $folder,
            'raceName' => (string)($row['race_name'] ?? ''),
            'raceId' => (string)$raceId,
        ];
    }

    ksort($out, SORT_NUMERIC);
    return $out;
}

function rds_monitor_snapshot_choice(string $raceFolder): string
{
    if (!is_dir($raceFolder)) {
        return '';
    }

    // Deliberately mirrors race_results_monitor.php:
    // glob snapshot_*.html, sort, choose the last file.
    $files = glob(rtrim($raceFolder, '/\\') . '/snapshot_*.html');

    if (!is_array($files) || empty($files)) {
        return '';
    }

    sort($files, SORT_STRING);
    return (string)end($files);
}

function rds_build_source_points(array $races, int $startRace, int $throughRace): array
{
    $out = [];

    foreach ($races as $rn => $race) {
        $rn = (int)$rn;

        if ($rn < $startRace || $rn > $throughRace) {
            continue;
        }

        $snapshot = rds_monitor_snapshot_choice((string)$race['raceFolder']);
        if ($snapshot === '') {
            continue;
        }

        $drivers = rrs_load_snapshot_driver_points($snapshot);
        if (!is_array($drivers) || empty($drivers)) {
            continue;
        }

        $out[$rn] = [
            'snapshot' => $snapshot,
            'drivers' => $drivers,
        ];
    }

    ksort($out, SORT_NUMERIC);
    return $out;
}

function rds_fixture_html(int $raceNumber, array $driverRows): string
{
    $rows = '';
    $pos = 1;

    foreach ($driverRows as $driverName => $data) {
        if (!is_array($data)) {
            continue;
        }

        $pts = (int)($data['pts'] ?? 0);
        $bonus = (int)($data['bonus'] ?? 0);
        $penalty = (int)($data['penalty'] ?? 0);

        $rows .= '<tr>'
            . '<td>' . $pos . '</td>'
            . '<td>' . rds_h($driverName) . '</td>'
            . '<td>0</td><td>0</td><td>0</td><td>0</td>'
            . '<td>' . $pts . '</td>'
            . '<td>' . $bonus . '</td>'
            . '<td>' . $penalty . '</td>'
            . '</tr>' . "\n";

        $pos++;
    }

    return '<!doctype html><html><head><meta charset="utf-8">'
        . '<title>RD Simulation R' . str_pad((string)$raceNumber, 2, '0', STR_PAD_LEFT) . '</title>'
        . '</head><body><table>'
        . '<tr><td>POS</td><td>DRIVER</td><td>START</td><td>LAPS</td><td>STATUS</td><td>LEAD</td><td>PTS</td><td>BONUS</td><td>PENALTY</td></tr>'
        . $rows
        . '</table></body></html>';
}

function rds_clear_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        if (is_dir($path)) {
            rds_clear_dir($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

function rds_driver_key(string $name): string
{
    $name = rrs_norm_text($name);
    return strtolower($name);
}

function rds_find_driver_row_key(array $driverRows, string $driverName): string
{
    $wanted = rds_driver_key($driverName);
    if ($wanted === '') {
        return '';
    }

    foreach ($driverRows as $key => $row) {
        if (rds_driver_key((string)$key) === $wanted) {
            return (string)$key;
        }
    }

    return '';
}

function rds_make_fixture_set(
    string $fixtureDir,
    array $sourcePoints,
    string $overrideDriver,
    array $raceNetOverrides
): array {
    if (!is_dir($fixtureDir) && !@mkdir($fixtureDir, 0775, true) && !is_dir($fixtureDir)) {
        throw new RuntimeException('Could not create fixture directory: ' . $fixtureDir);
    }

    rds_clear_dir($fixtureDir);

    $points = [];
    $meta = [];

    foreach ($sourcePoints as $rn => $raceData) {
        $rn = (int)$rn;
        $driverRows = isset($raceData['drivers']) && is_array($raceData['drivers'])
            ? $raceData['drivers']
            : [];

        $overrideApplied = false;
        $sourceMatched = false;
        $syntheticAdded = false;
        $savedNet = null;
        $simulatedNet = null;

        $matchedKey = '';
        if ($overrideDriver !== '') {
            $matchedKey = rds_find_driver_row_key($driverRows, $overrideDriver);

            if ($matchedKey !== '' && isset($driverRows[$matchedKey]) && is_array($driverRows[$matchedKey])) {
                $sourceMatched = true;
                $savedNet = (int)($driverRows[$matchedKey]['net'] ?? 0);
            }
        }

        if ($overrideDriver !== '' && array_key_exists($rn, $raceNetOverrides)) {
            $overrideNet = (int)$raceNetOverrides[$rn];

            if ($matchedKey === '') {
                $matchedKey = $overrideDriver;
                $driverRows[$matchedKey] = [
                    'pts' => $overrideNet,
                    'bonus' => 0,
                    'penalty' => 0,
                    'net' => $overrideNet,
                ];
                $syntheticAdded = true;
            } else {
                $driverRows[$matchedKey]['pts'] = $overrideNet;
                $driverRows[$matchedKey]['bonus'] = 0;
                $driverRows[$matchedKey]['penalty'] = 0;
                $driverRows[$matchedKey]['net'] = $overrideNet;
            }

            $overrideApplied = true;
            $simulatedNet = $overrideNet;
        } elseif ($sourceMatched) {
            $simulatedNet = $savedNet;
        }

        $fixturePath = $fixtureDir
            . '/R'
            . str_pad((string)$rn, 2, '0', STR_PAD_LEFT)
            . '_rd_fixture.html';

        if (@file_put_contents($fixturePath, rds_fixture_html($rn, $driverRows), LOCK_EX) === false) {
            throw new RuntimeException('Could not write fixture: ' . $fixturePath);
        }

        $reparsed = rrs_load_snapshot_driver_points($fixturePath);
        if (!is_array($reparsed) || empty($reparsed)) {
            throw new RuntimeException('Fixture re-parse failed for R' . str_pad((string)$rn, 2, '0', STR_PAD_LEFT) . '.');
        }

        $points[$rn] = [];
        foreach ($reparsed as $driverName => $data) {
            $points[$rn][$driverName] = (int)($data['net'] ?? 0);
        }

        $reparsedMatchedKey = $overrideDriver !== ''
            ? rds_find_driver_row_key($reparsed, $overrideDriver)
            : '';

        if ($reparsedMatchedKey !== '' && isset($reparsed[$reparsedMatchedKey])) {
            $simulatedNet = (int)($reparsed[$reparsedMatchedKey]['net'] ?? 0);
        }

        $meta[$rn] = [
            'source' => (string)($raceData['snapshot'] ?? ''),
            'fixture' => $fixturePath,
            'override_applied' => $overrideApplied,
            'source_matched' => $sourceMatched,
            'synthetic_added' => $syntheticAdded,
            'saved_net' => $savedNet,
            'simulated_net' => $simulatedNet,
        ];
    }

    ksort($points, SORT_NUMERIC);

    return [
        'points' => $points,
        'meta' => $meta,
    ];
}

function rds_status_class(string $status): string
{
    if ($status === 'RD_AVAILABLE' || $status === 'MULTIPLE_RD_AVAILABLE') {
        return 'good';
    }

    if ($status === 'RD_ALREADY_USED') {
        return 'used';
    }

    return 'quiet';
}

$selectedYear = isset($_GET['year']) && preg_match('/^\d{4}$/', (string)$_GET['year'])
    ? (string)$_GET['year']
    : (string)($raceYear ?? date('Y'));

$selectedSegment = isset($_GET['segment']) && preg_match('/^S[1-9]\d*$/', (string)$_GET['segment'])
    ? (string)$_GET['segment']
    : 'S1';

$yearFolder = __DIR__ . '/' . $selectedYear;
$yearIndex = rds_json($yearFolder . '/_year_index.json');
$pointRaces = rds_point_races($yearIndex, $yearFolder);
$bounds = mrl_rd_try_get_segment_bounds($dbo, (int)$selectedYear, $selectedSegment);

$error = '';
$message = '';
$throughRace = 0;
$sourcePoints = [];
$fixtureResult = ['points' => [], 'meta' => []];

$overrideDriver = trim((string)($_POST['override_driver'] ?? ''));

$raceNetOverrides = [];
$postedRaceNet = isset($_POST['race_net']) && is_array($_POST['race_net'])
    ? $_POST['race_net']
    : [];

foreach ($postedRaceNet as $rn => $rawValue) {
    $raceNumber = (int)$rn;
    $value = trim((string)$rawValue);

    if ($raceNumber <= 0 || $value === '') {
        continue;
    }

    if (!preg_match('/^-?\d+$/', $value)) {
        $error = 'Each simulated NET value must be a whole number or left blank.';
        break;
    }

    $raceNetOverrides[$raceNumber] = (int)$value;
}

if ($bounds === null) {
    $error = 'No segment_race_ranges row found for ' . $selectedYear . ' ' . $selectedSegment . '.';
} else {
    $availableRaces = [];

    foreach ($pointRaces as $rn => $race) {
        $rn = (int)$rn;

        if ($rn < (int)$bounds['start'] || $rn > (int)$bounds['end']) {
            continue;
        }

        if (rds_monitor_snapshot_choice((string)$race['raceFolder']) !== '') {
            $availableRaces[] = $rn;
        }
    }

    $requestedThrough = (int)($_GET['through'] ?? $_POST['through_race'] ?? 0);

    if ($requestedThrough > 0 && in_array($requestedThrough, $availableRaces, true)) {
        $throughRace = $requestedThrough;
    } elseif (!empty($availableRaces)) {
        $throughRace = (int)$availableRaces[count($availableRaces) - 1];
    }

    if ($throughRace > 0) {
        $sourcePoints = rds_build_source_points(
            $pointRaces,
            (int)$bounds['start'],
            $throughRace
        );
    }
}

$sourceMap = [];
foreach ($sourcePoints as $rn => $row) {
    $sourceMap[$rn] = [];

    foreach (($row['drivers'] ?? []) as $driverName => $data) {
        $sourceMap[$rn][$driverName] = (int)($data['net'] ?? 0);
    }
}

$driverNames = [];
if (!empty($sourceMap)) {
    foreach (mrl_rd_candidate_team_rows($dbo, $selectedYear, $selectedSegment, $sourceMap) as $row) {
        foreach (['driverA', 'driverB', 'driverC', 'driverD'] as $field) {
            $name = trim((string)($row[$field] ?? ''));
            if ($name !== '') {
                $driverNames[$name] = true;
            }
        }
    }
}

$driverNames = array_keys($driverNames);
natcasesort($driverNames);
$driverNames = array_values($driverNames);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ((string)$_POST['action'] === 'reset') {
            rds_clear_dir(__DIR__ . '/_rd_simulation');
            $message = 'Simulation fixtures cleared. DB and normal snapshots were untouched.';
        }

        if ((string)$_POST['action'] === 'run') {
            if ($bounds === null || $throughRace <= 0 || empty($sourcePoints)) {
                throw new RuntimeException('No completed snapshot state is available for this selection.');
            }

            /*
             * "Tom test" UI safety:
             * Do not silently ignore entered simulation values just because
             * no driver was selected.
             */
            if (!empty($raceNetOverrides) && $overrideDriver === '') {
                throw new RuntimeException('Select a driver for the simulated race values.');
            }

            if ($overrideDriver !== '' && !in_array($overrideDriver, $driverNames, true)) {
                throw new RuntimeException('Override driver is not an active driver for the selected team set.');
            }

            foreach (array_keys($raceNetOverrides) as $rn) {
                if ($rn < (int)$bounds['start'] || $rn > $throughRace) {
                    throw new RuntimeException('A race NET override is outside the loaded completed-race range.');
                }
            }

            $fixtureDir = __DIR__
                . '/_rd_simulation/current/'
                . $selectedYear
                . '/'
                . $selectedSegment;

            $fixtureResult = rds_make_fixture_set(
                $fixtureDir,
                $sourcePoints,
                $overrideDriver,
                $raceNetOverrides
            );

            $message = 'Simulation fixtures generated and re-parsed. No DB writes; normal snapshots untouched.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$evaluationPoints = !empty($fixtureResult['points'])
    ? $fixtureResult['points']
    : $sourceMap;

$results = [];

if (!empty($evaluationPoints)) {
    $candidateRows = mrl_rd_candidate_team_rows(
        $dbo,
        $selectedYear,
        $selectedSegment,
        $evaluationPoints
    );

    foreach ($candidateRows as $row) {
        $teamName = trim((string)($row['teamName'] ?? ''));
        if ($teamName === '') {
            continue;
        }

        $result = mrl_rd_detect_team_segment_eligibility(
            $dbo,
            $selectedYear,
            $selectedSegment,
            $teamName,
            $evaluationPoints
        );

        /*
         * Diagnostic-only underlying eligibility:
         * If the real helper stops at RD_ALREADY_USED, evaluate each active
         * driver against the same trailing completed pair anyway so we can
         * inspect historical eligibility without altering the DB.
         */
        $underlyingQualifiers = [];

        $activeRow = isset($result['base_pick_row']) && is_array($result['base_pick_row'])
            ? $result['base_pick_row']
            : mrl_rd_active_pick_row(
                $dbo,
                $selectedYear,
                $selectedSegment,
                $teamName,
                $evaluationPoints
            );

        if (is_array($activeRow)) {
            foreach (['A', 'B', 'C', 'D'] as $slot) {
                $field = 'driver' . $slot;

                $slotResult = mrl_rd_detect_slot_current_eligibility(
                    $dbo,
                    (int)$selectedYear,
                    $slot,
                    $selectedSegment,
                    (string)($activeRow[$field] ?? ''),
                    $evaluationPoints
                );

                if (!empty($slotResult['qualified'])) {
                    $underlyingQualifiers[] = $slotResult;
                }
            }
        }

        $result['underlying_qualifiers'] = $underlyingQualifiers;
        $result['underlying_qualifier_count'] = count($underlyingQualifiers);

        if (count($underlyingQualifiers) === 1) {
            $result['underlying_status'] = 'RD_AVAILABLE';
        } elseif (count($underlyingQualifiers) > 1) {
            $result['underlying_status'] = 'MULTIPLE_RD_AVAILABLE';
        } else {
            $result['underlying_status'] = 'NO_RD';
        }

        $results[] = $result;
    }
}

$completed = !empty($evaluationPoints) && $bounds !== null
    ? mrl_rd_completed_race_numbers($dbo, (int)$selectedYear, $selectedSegment, $evaluationPoints)
    : [];

$trailing = count($completed) >= 2 ? array_slice($completed, -2) : $completed;

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL RD Simulation</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.35 Arial,Helvetica,sans-serif}
.wrap{max-width:1500px;margin:0 auto;padding:14px}
.banner{background:#3d3212;border:1px solid #9b7200;border-radius:10px;padding:11px 14px;font-size:20px;font-weight:700}
.sub{font-size:12px;color:#dbc783;margin-top:4px}
.card{background:#1b1b1b;border:1px solid #3e3e3e;border-radius:9px;margin-top:12px;padding:12px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:9px}
label{display:block;font-size:12px;color:#bbb;margin-bottom:4px}
select,input{width:100%;background:#0d0d0d;color:#eee;border:1px solid #555;border-radius:5px;padding:7px}
button{background:#2b669f;color:white;border:0;border-radius:6px;padding:8px 12px;font-weight:700;cursor:pointer}
button.secondary{background:#555}
.msg{background:#173b20;border:1px solid #2b7740;padding:9px;margin-top:10px}
.err{background:#4a1717;border:1px solid #a33;padding:9px;margin-top:10px}
table{width:100%;border-collapse:collapse;margin-top:9px}
th,td{border:1px solid #393939;padding:6px 7px;text-align:left;vertical-align:top}
th{background:#262626}
tr:nth-child(even) td{background:#171717}
.good{color:#67e89b;font-weight:700}
.used{color:#e6be74;font-weight:700}
.quiet{color:#aaa}
.small{font-size:12px;color:#aaa}
.races{display:flex;flex-wrap:wrap;gap:7px;margin-top:6px}
.racecheck{display:inline-flex;align-items:center;gap:5px;background:#222;border:1px solid #444;border-radius:5px;padding:5px 7px}
.racecheck input{width:auto}
.nowrap{white-space:nowrap}
</style>
</head>
<body>
<div class="wrap">
    <div class="banner">
        TESTPHP8 / RD SIMULATION
        <div class="sub">Read-only DB diagnostics + isolated snapshot fixtures. Nothing here submits or changes an RD.</div>
    </div>

    <div class="card">
        <form method="get">
            <div class="grid">
                <div>
                    <label>Year</label>
                    <input name="year" value="<?=rds_h($selectedYear)?>">
                </div>
                <div>
                    <label>Segment</label>
                    <select name="segment">
                        <?php foreach (['S1','S2','S3','S4'] as $seg): ?>
                            <option value="<?=rds_h($seg)?>" <?=$selectedSegment===$seg?'selected':''?>><?=rds_h($seg)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Through completed race</label>
                    <select name="through">
                        <?php if ($bounds !== null): ?>
                            <?php foreach ($pointRaces as $rn => $race): ?>
                                <?php
                                $rn = (int)$rn;
                                if ($rn < (int)$bounds['start'] || $rn > (int)$bounds['end']) continue;
                                if (rds_monitor_snapshot_choice((string)$race['raceFolder']) === '') continue;
                                ?>
                                <option value="<?=$rn?>" <?=$throughRace===$rn?'selected':''?>>
                                    R<?=str_pad((string)$rn,2,'0',STR_PAD_LEFT)?> <?=rds_h($race['raceName'])?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div style="align-self:end">
                    <button type="submit">Load Snapshot State</button>
                </div>
            </div>
        </form>

        <div class="small" style="margin-top:8px">
            First target: 2026 / S1 / through R05. With no override, the saved R04/R05 data should show the real Alex Bowman case if the snapshots contain those zeroes.
        </div>
    </div>

    <div class="card">
        <form method="post">
            <input type="hidden" name="through_race" value="<?=$throughRace?>">

            <div class="grid">
                <div>
                    <label>Optional driver override</label>
                    <select name="override_driver">
                        <option value="">No override — use saved snapshot values</option>
                        <?php foreach ($driverNames as $driver): ?>
                            <option value="<?=rds_h($driver)?>" <?=$overrideDriver===$driver?'selected':''?>><?=rds_h($driver)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label style="margin-top:9px">Optional simulated NET by completed race</label>
            <div class="races">
                <?php foreach ($sourcePoints as $rn => $row): $rn=(int)$rn; ?>
                    <div class="racecheck" style="display:block;min-width:105px">
                        <div style="font-weight:700;margin-bottom:4px">
                            R<?=str_pad((string)$rn,2,'0',STR_PAD_LEFT)?>
                        </div>
                        <input
                            name="race_net[<?=$rn?>]"
                            value="<?=isset($raceNetOverrides[$rn])?rds_h((string)$raceNetOverrides[$rn]):''?>"
                            placeholder="saved"
                            style="width:88px"
                        >
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="small" style="margin-top:6px">
                Blank = use saved snapshot NET. Enter a value only for the race(s) you want to simulate.
                If any race has a simulated value, you must select a driver above.
            </div>

            <div style="display:flex;gap:8px;margin-top:10px">
                <button type="submit" name="action" value="run">Generate / Run Simulation</button>
                <button type="submit" name="action" value="reset" class="secondary">Clear Simulation Fixtures</button>
            </div>
        </form>

        <?php if ($message !== ''): ?><div class="msg"><?=rds_h($message)?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="err"><?=rds_h($error)?></div><?php endif; ?>
    </div>

    <div class="card">
        <strong>Evaluation:</strong>
        <span class="small">
            Real Guard honors current DB history. Underlying Eligibility ignores only
            that already-used gate for diagnostics; it never authorizes or submits an RD.
        </span><br>
        <span class="small">
            <?=rds_h($selectedYear)?> <?=rds_h($selectedSegment)?>,
            through <?=$throughRace>0?'R'.str_pad((string)$throughRace,2,'0',STR_PAD_LEFT):'none'?>,
            trailing pair:
            <?=empty($trailing)
                ? 'none'
                : implode(' / ', array_map(function($n){return 'R'.str_pad((string)$n,2,'0',STR_PAD_LEFT);}, $trailing))?>
        </span>

        <table>
            <thead>
            <tr>
                <th>Team</th>
                <th>Active Pick</th>
                <th>A</th><th>B</th><th>C</th><th>D</th>
                <th>Real Guard</th>
                <th>Underlying Eligibility</th>
                <th>Qualifier(s)</th>
                <th>Effective</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($results)): ?>
                <tr><td colspan="10">No evaluable teams.</td></tr>
            <?php else: ?>
                <?php foreach ($results as $res): ?>
                    <?php
                    $base = isset($res['base_pick_row']) && is_array($res['base_pick_row'])
                        ? $res['base_pick_row']
                        : [];
                    $status = (string)($res['status'] ?? '');
                    $underlyingStatus = (string)($res['underlying_status'] ?? 'NO_RD');

                    /*
                     * For diagnostic display, show underlying qualifiers rather
                     * than the season-gated helper list. When RD has not already
                     * been used these should match the normal helper result.
                     */
                    $qualifiers = isset($res['underlying_qualifiers']) && is_array($res['underlying_qualifiers'])
                        ? $res['underlying_qualifiers']
                        : [];
                    $qText = [];
                    $effectiveText = [];

                    foreach ($qualifiers as $q) {
                        $zr = isset($q['zero_races']) && is_array($q['zero_races'])
                            ? $q['zero_races']
                            : [];
                        $zrText = implode('/', array_map(function($n){
                            return 'R' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
                        }, $zr));

                        $qText[] = (string)($q['slot'] ?? '')
                            . ': '
                            . (string)($q['driver'] ?? '')
                            . ($zrText !== '' ? ' [' . $zrText . ']' : '');

                        $er = (int)($q['effective_race'] ?? 0);
                        if ($er > 0) {
                            $effectiveText[] = 'R' . str_pad((string)$er, 2, '0', STR_PAD_LEFT);
                        }
                    }
                    ?>
                    <tr>
                        <td class="nowrap"><?=rds_h($res['teamName'] ?? '')?></td>
                        <td><?=rds_h($res['active_pick_type'] ?? '')?></td>
                        <td><?=rds_h($base['driverA'] ?? '')?></td>
                        <td><?=rds_h($base['driverB'] ?? '')?></td>
                        <td><?=rds_h($base['driverC'] ?? '')?></td>
                        <td><?=rds_h($base['driverD'] ?? '')?></td>
                        <td class="<?=rds_h(rds_status_class($status))?>"><?=rds_h($status)?></td>
                        <td class="<?=rds_h(rds_status_class($underlyingStatus))?>"><?=rds_h($underlyingStatus)?></td>
                        <td><?=rds_h(implode(' | ', $qText))?></td>
                        <td><?=rds_h(implode(' / ', array_unique($effectiveText)))?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($fixtureResult['meta'])): ?>
        <div class="card">
            <strong>Fixture audit</strong>
            <table>
                <thead>
                <tr>
                    <th>Race</th>
                    <th>Source snapshot used</th>
                    <th>Simulation fixture</th>
                    <th>Source Row</th>
                    <th>Saved NET</th>
                    <th>Simulated NET</th>
                    <th>Override</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($fixtureResult['meta'] as $rn => $meta): ?>
                    <tr>
                        <td>R<?=str_pad((string)$rn,2,'0',STR_PAD_LEFT)?></td>
                        <td class="small"><?=rds_h(basename((string)$meta['source']))?></td>
                        <td class="small"><?=rds_h(str_replace(__DIR__ . '/', '', (string)$meta['fixture']))?></td>
                        <td>
                            <?php if (!empty($meta['source_matched'])): ?>
                                MATCHED
                            <?php elseif (!empty($meta['synthetic_added'])): ?>
                                SYNTHETIC
                            <?php else: ?>
                                MISSING
                            <?php endif; ?>
                        </td>
                        <td><?=array_key_exists('saved_net',$meta) && $meta['saved_net'] !== null ? rds_h((string)$meta['saved_net']) : '—'?></td>
                        <td><?=array_key_exists('simulated_net',$meta) && $meta['simulated_net'] !== null ? rds_h((string)$meta['simulated_net']) : '—'?></td>
                        <td><?=!empty($meta['override_applied'])?'YES':'—'?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
