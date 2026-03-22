<?php
declare(strict_types=1);

// No-cache for testing / rapid iteration
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

/**
 * race_results_single_test.php
 *
 * VERSION: v029
 * LAST MODIFIED: 3/13/2026 4:17:49 PM
 *
 * CHANGELOG:
 * v029 (3/13/2026)
 *   - FIX: Weekly winners now correctly handle ties for 1st place.
 *   - Weekly winner names are joined with " / " when multiple teams share top points.
 *   - Validation updated so tied winners pass correctly.
 *
 * v028 (2026-03-13)
 *   - Updated wording:
 *       - "Drivers Loaded For Selected Race" -> "Drivers read from snapshot"
 *       - PASS: "No unexpected zero scores detected."
 *       - WARN: "Unexpected zero scores detected: X"
 *   - No functional logic changes.
 *
 * v027 (2026-03-13)
 *   - Added validation summary panel with PASS / WARN / FAIL checks.
 *   - Validation currently checks:
 *       - selected race exists
 *       - snapshot found
 *       - teams loaded
 *       - weekly rows generated
 *       - weekly totals equal A+B+C+D
 *       - weekly winner matches highest total
 *       - weekly standings are sorted correctly
 *       - duplicate teams
 *       - missing drivers scored as zero
 *   - Keeps newest year first and newest race first in dropdowns.
 *   - Keeps cumulative calculations based on selected race number, not dropdown position.
 *   - Keeps simplified race-name prefix stripping for older naming variants.
 *
 * PHP: 7.3 compatible.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once __DIR__ . '/race_results_team_helper.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';
require_once __DIR__ . '/race_results_engine.php';

function rrsg_h($val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

function rrsg_driver_net(array $driverPoints, string $driverName): int
{
    if ($driverName === '') {
        return 0;
    }

    if (!isset($driverPoints[$driverName]) || !is_array($driverPoints[$driverName])) {
        return 0;
    }

    return (int)($driverPoints[$driverName]['net'] ?? 0);
}

function rrsg_sort_weekly_rows(array &$rows): void
{
    usort($rows, function ($a, $b) {
        $aTotal = (int)($a['weeklyTotal'] ?? 0);
        $bTotal = (int)($b['weeklyTotal'] ?? 0);

        if ($aTotal !== $bTotal) {
            return ($bTotal <=> $aTotal);
        }

        return strcasecmp((string)($a['teamName'] ?? ''), (string)($b['teamName'] ?? ''));
    });
}

function rrsg_sort_total_rows(array $totals): array
{
    $rows = [];

    foreach ($totals as $teamName => $total) {
        $rows[] = [
            'teamName' => (string)$teamName,
            'total' => (int)$total,
        ];
    }

    usort($rows, function ($a, $b) {
        $aTotal = (int)$a['total'];
        $bTotal = (int)$b['total'];

        if ($aTotal !== $bTotal) {
            return ($bTotal <=> $aTotal);
        }

        return strcasecmp((string)$a['teamName'], (string)$b['teamName']);
    });

    return $rows;
}

function rrsg_find_snapshot_file(string $raceFolder): string
{
    if (!is_dir($raceFolder)) {
        return '';
    }

    $files = glob($raceFolder . '/snapshot_*.html');
    if (!is_array($files) || empty($files)) {
        return '';
    }

    sort($files, SORT_STRING);
    return (string)end($files);
}

function rrsg_build_weekly_rows(array $teamRows, array $driverPoints): array
{
    $weeklyRows = [];

    foreach ($teamRows as $team) {
        $driverA = (string)($team['driverA'] ?? '');
        $driverB = (string)($team['driverB'] ?? '');
        $driverC = (string)($team['driverC'] ?? '');
        $driverD = (string)($team['driverD'] ?? '');

        $netA = rrsg_driver_net($driverPoints, $driverA);
        $netB = rrsg_driver_net($driverPoints, $driverB);
        $netC = rrsg_driver_net($driverPoints, $driverC);
        $netD = rrsg_driver_net($driverPoints, $driverD);

        $weeklyTotal = $netA + $netB + $netC + $netD;

        $weeklyRows[] = [
            'teamName' => (string)($team['teamName'] ?? ''),
            'userName' => (string)($team['userName'] ?? ''),
            'driverA' => $driverA,
            'driverB' => $driverB,
            'driverC' => $driverC,
            'driverD' => $driverD,
            'netA' => $netA,
            'netB' => $netB,
            'netC' => $netC,
            'netD' => $netD,
            'weeklyTotal' => $weeklyTotal,
        ];
    }

    rrsg_sort_weekly_rows($weeklyRows);
    return $weeklyRows;
}

function rrsg_get_weekly_winner(array $weeklyRows): array
{
    if (empty($weeklyRows)) {
        return [
            'teamName' => '',
            'points' => 0,
        ];
    }

    $topPoints = (int)($weeklyRows[0]['weeklyTotal'] ?? 0);
    $winnerNames = [];

    foreach ($weeklyRows as $row) {
        $weeklyTotal = (int)($row['weeklyTotal'] ?? 0);
        if ($weeklyTotal !== $topPoints) {
            break;
        }

        $winnerNames[] = (string)($row['teamName'] ?? '');
    }

    return [
        'teamName' => implode(' / ', $winnerNames),
        'points' => $topPoints,
    ];
}

function rrsg_segment_from_race_number(int $raceNumber): string
{
    if ($raceNumber >= 1 && $raceNumber <= 8) {
        return 'S1';
    }
    if ($raceNumber >= 9 && $raceNumber <= 17) {
        return 'S2';
    }
    if ($raceNumber >= 18 && $raceNumber <= 26) {
        return 'S3';
    }
    if ($raceNumber >= 27 && $raceNumber <= 36) {
        return 'S4';
    }

    return 'S1';
}

function rrsg_segment_bounds(string $segment): array
{
    if ($segment === 'S1') return ['start' => 1, 'end' => 8];
    if ($segment === 'S2') return ['start' => 9, 'end' => 17];
    if ($segment === 'S3') return ['start' => 18, 'end' => 26];
    if ($segment === 'S4') return ['start' => 27, 'end' => 36];

    return ['start' => 1, 'end' => 8];
}

function rrsg_available_years(string $baseDir): array
{
    $years = [];
    $items = scandir($baseDir);

    if (!is_array($items)) {
        return [];
    }

    foreach ($items as $name) {
        if (!preg_match('/^\d{4}$/', (string)$name)) {
            continue;
        }

        $yearFolder = $baseDir . '/' . $name;
        if (!is_dir($yearFolder)) {
            continue;
        }

        if (is_file($yearFolder . '/_year_index.json')) {
            $years[] = (string)$name;
        }
    }

    rsort($years, SORT_STRING);
    return $years;
}

function rrsg_load_year_index_file(string $path): array
{
    $idx = rr_load_json($path);
    if (!is_array($idx)) return [];
    if (!isset($idx['races']) || !is_array($idx['races'])) return [];
    return $idx;
}

function rrsg_points_races_from_index(array $yearIndex, string $yearBaseFolder): array
{
    $rows = [];

    foreach ($yearIndex['races'] as $raceId => $row) {
        if (!is_array($row)) continue;

        $kind = (string)($row['kind'] ?? '');
        if ($kind !== 'R') continue;

        $number = (int)($row['number'] ?? 0);
        $folder = (string)($row['folder'] ?? '');
        $raceName = (string)($row['race_name'] ?? '');
        $raceUrl = (string)($row['race_url'] ?? '');

        if ($number <= 0 || $folder === '') continue;

        $rows[] = [
            'raceId' => (string)$raceId,
            'kind' => $kind,
            'number' => $number,
            'raceCode' => 'R' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'raceName' => $raceName,
            'raceUrl' => $raceUrl,
            'folder' => $folder,
            'raceFolder' => $yearBaseFolder . '/' . $folder,
        ];
    }

    usort($rows, function ($a, $b) {
        $an = (int)$a['number'];
        $bn = (int)$b['number'];

        if ($an !== $bn) {
            return ($bn <=> $an);
        }

        return strcmp((string)$a['raceId'], (string)$b['raceId']);
    });

    return $rows;
}

function rrsg_short_race_label(string $raceName): string
{
    $slug = rr_sanitize_for_folder($raceName);

    $map = [
        'EchoPark_Automotive_Grand_Prix' => 'COTA',
        'NASCAR_Cup_Series_at_Circuit_of_the_Americas' => 'COTA',
        'NASCAR_CUP_SERIES_AT_CIRCUIT_OF_THE_AMERICAS' => 'COTA',
        'World_Wide_Technology_Raceway' => 'World Wide Technology Raceway',
    ];

    if (isset($map[$slug])) {
        return $map[$slug];
    }

    $slug = preg_replace('/^MONSTER_ENERGY_NASCAR_CUP_SERIES_AT_/i', '', $slug);
    $slug = preg_replace('/^NASCAR_CUP_SERIES_AT_/i', '', $slug);
    $slug = preg_replace('/^NASCAR_Cup_Series_at_/i', '', $slug);

    $slug = trim((string)$slug, '_');

    if ($slug === '') {
        $slug = 'Race';
    }

    return str_replace('_', ' ', $slug);
}

function rrsg_add_validation(array &$validation, string $level, string $message): void
{
    if (!isset($validation[$level]) || !is_array($validation[$level])) {
        $validation[$level] = [];
    }
    $validation[$level][] = $message;
}

function rrsg_is_sorted_weekly_desc(array $weeklyRows): bool
{
    if (count($weeklyRows) <= 1) {
        return true;
    }

    for ($i = 0; $i < count($weeklyRows) - 1; $i++) {
        $curTotal = (int)($weeklyRows[$i]['weeklyTotal'] ?? 0);
        $nextTotal = (int)($weeklyRows[$i + 1]['weeklyTotal'] ?? 0);

        if ($curTotal < $nextTotal) {
            return false;
        }

        if ($curTotal === $nextTotal) {
            $curName = (string)($weeklyRows[$i]['teamName'] ?? '');
            $nextName = (string)($weeklyRows[$i + 1]['teamName'] ?? '');

            if (strcasecmp($curName, $nextName) > 0) {
                return false;
            }
        }
    }

    return true;
}

function rrsg_validation_status(array $validation): string
{
    if (!empty($validation['fail'])) {
        return 'FAIL';
    }
    if (!empty($validation['warn'])) {
        return 'WARN';
    }
    return 'PASS';
}

/* ------------------------------------------------------------------
   INPUTS
   ------------------------------------------------------------------ */

$baseDir = __DIR__;
$availableYears = rrsg_available_years($baseDir);

$selectedYear = isset($_GET['year']) ? trim((string)$_GET['year']) : '';
if (!in_array($selectedYear, $availableYears, true)) {
    $selectedYear = !empty($availableYears) ? $availableYears[0] : '2026';
}

$yearFolder = $baseDir . '/' . $selectedYear;
$yearIndexFile = $yearFolder . '/_year_index.json';
$yearIndex = rrsg_load_year_index_file($yearIndexFile);
$pointRaces = rrsg_points_races_from_index($yearIndex, $yearFolder);

$selectedRaceCode = isset($_GET['race']) ? trim((string)$_GET['race']) : '';
if ($selectedRaceCode === '' && !empty($pointRaces)) {
    $selectedRaceCode = (string)$pointRaces[0]['raceCode'];
}

$selectedRaceIndex = -1;
for ($i = 0; $i < count($pointRaces); $i++) {
    if ((string)$pointRaces[$i]['raceCode'] === $selectedRaceCode) {
        $selectedRaceIndex = $i;
        break;
    }
}

if ($selectedRaceIndex < 0 && !empty($pointRaces)) {
    $selectedRaceIndex = 0;
    $selectedRaceCode = (string)$pointRaces[$selectedRaceIndex]['raceCode'];
}

$selectedRace = ($selectedRaceIndex >= 0 && isset($pointRaces[$selectedRaceIndex]))
    ? $pointRaces[$selectedRaceIndex]
    : null;

$scoreYear = $selectedYear;
$scoreSegment = 'S1';
$segmentBounds = ['start' => 1, 'end' => 8];
$selectedRaceNumber = 0;

if ($selectedRace !== null) {
    $selectedRaceNumber = (int)$selectedRace['number'];
    $scoreSegment = rrsg_segment_from_race_number($selectedRaceNumber);
    $segmentBounds = rrsg_segment_bounds($scoreSegment);
}

$teamRows = rr_get_segment_team_picks($dbo ?? null, $dbconnect ?? null, $scoreYear, $scoreSegment);

$segmentTotals = [];
$seasonTotals = [];
$weeklyWinners = [];
$selectedRaceWeeklyRows = [];
$selectedRaceMeta = [
    'raceCode' => '',
    'raceLabel' => '',
    'snapshotFile' => '',
    'driverCount' => 0,
];

$validation = [
    'pass' => [],
    'warn' => [],
    'fail' => [],
];

foreach ($teamRows as $team) {
    $teamName = (string)($team['teamName'] ?? '');
    if ($teamName === '') {
        continue;
    }

    $segmentTotals[$teamName] = 0;
    $seasonTotals[$teamName] = 0;
}

if ($selectedRace !== null) {
    $racesAscending = $pointRaces;
    usort($racesAscending, function ($a, $b) {
        return ((int)$a['number']) <=> ((int)$b['number']);
    });

    foreach ($racesAscending as $race) {
        $raceCode = (string)$race['raceCode'];
        $raceLabel = rrsg_short_race_label((string)$race['raceName']);
        $raceFolder = (string)$race['raceFolder'];
        $raceNumber = (int)$race['number'];

        if ($raceNumber > $selectedRaceNumber) {
            continue;
        }

        $snapshotFile = rrsg_find_snapshot_file($raceFolder);
        $driverPoints = [];
        $weeklyRows = [];

        if ($snapshotFile !== '') {
            $driverPoints = rrs_load_snapshot_driver_points($snapshotFile);
            $weeklyRows = rrsg_build_weekly_rows($teamRows, $driverPoints);

            foreach ($weeklyRows as $row) {
                $teamName = (string)$row['teamName'];
                $weeklyTotal = (int)$row['weeklyTotal'];

                if (!isset($seasonTotals[$teamName])) {
                    $seasonTotals[$teamName] = 0;
                }
                $seasonTotals[$teamName] += $weeklyTotal;

                if ($raceNumber >= $segmentBounds['start'] && $raceNumber <= $segmentBounds['end']) {
                    if (!isset($segmentTotals[$teamName])) {
                        $segmentTotals[$teamName] = 0;
                    }
                    $segmentTotals[$teamName] += $weeklyTotal;
                }
            }

            $weeklyWinners[$raceCode] = rrsg_get_weekly_winner($weeklyRows);
        } else {
            $weeklyWinners[$raceCode] = [
                'teamName' => '',
                'points' => 0,
            ];
        }

        if ($raceCode === $selectedRaceCode) {
            $selectedRaceWeeklyRows = $weeklyRows;
            $selectedRaceMeta = [
                'raceCode' => $raceCode,
                'raceLabel' => $raceLabel,
                'snapshotFile' => $snapshotFile,
                'driverCount' => count($driverPoints),
            ];
        }
    }
}

/* ------------------------------------------------------------------
   VALIDATION
   ------------------------------------------------------------------ */

if ($selectedRace === null) {
    rrsg_add_validation($validation, 'fail', 'Selected race not found.');
} else {
    rrsg_add_validation($validation, 'pass', 'Selected race found: ' . $selectedRaceCode);
}

if ($selectedRaceMeta['snapshotFile'] !== '') {
    rrsg_add_validation($validation, 'pass', 'Snapshot found for selected race.');
} else {
    rrsg_add_validation($validation, 'fail', 'Selected race snapshot not found.');
}

if (count($teamRows) > 0) {
    rrsg_add_validation($validation, 'pass', 'Teams loaded: ' . count($teamRows));
} else {
    rrsg_add_validation($validation, 'fail', 'No teams loaded for selected segment.');
}

if (!empty($selectedRaceWeeklyRows)) {
    rrsg_add_validation($validation, 'pass', 'Weekly rows generated: ' . count($selectedRaceWeeklyRows));
} else {
    rrsg_add_validation($validation, 'fail', 'No weekly rows generated for selected race.');
}

$duplicateTeams = [];
$teamSeen = [];
$missingDrivers = 0;
$badTotals = 0;

foreach ($selectedRaceWeeklyRows as $row) {
    $teamName = (string)($row['teamName'] ?? '');

    if ($teamName !== '') {
        if (isset($teamSeen[$teamName])) {
            $duplicateTeams[] = $teamName;
        }
        $teamSeen[$teamName] = true;
    }

    $sumDrivers =
        (int)($row['netA'] ?? 0) +
        (int)($row['netB'] ?? 0) +
        (int)($row['netC'] ?? 0) +
        (int)($row['netD'] ?? 0);

    $weeklyTotal = (int)($row['weeklyTotal'] ?? 0);

    if ($sumDrivers !== $weeklyTotal) {
        $badTotals++;
    }

    $drivers = [
        (string)($row['driverA'] ?? ''),
        (string)($row['driverB'] ?? ''),
        (string)($row['driverC'] ?? ''),
        (string)($row['driverD'] ?? ''),
    ];

    $nets = [
        (int)($row['netA'] ?? 0),
        (int)($row['netB'] ?? 0),
        (int)($row['netC'] ?? 0),
        (int)($row['netD'] ?? 0),
    ];

    for ($i = 0; $i < 4; $i++) {
        if ($drivers[$i] !== '' && $nets[$i] === 0) {
            $missingDrivers++;
        }
    }
}

if ($badTotals === 0) {
    rrsg_add_validation($validation, 'pass', 'Weekly totals match sum of driver values.');
} else {
    rrsg_add_validation($validation, 'fail', 'Weekly total mismatch count: ' . $badTotals);
}

if (empty($duplicateTeams)) {
    rrsg_add_validation($validation, 'pass', 'No duplicate teams found in weekly results.');
} else {
    rrsg_add_validation($validation, 'fail', 'Duplicate teams found: ' . implode(', ', array_unique($duplicateTeams)));
}

if ($missingDrivers > 0) {
    rrsg_add_validation($validation, 'warn', 'Unexpected zero scores detected: ' . $missingDrivers);
} else {
    rrsg_add_validation($validation, 'pass', 'No unexpected zero scores detected.');
}

if (rrsg_is_sorted_weekly_desc($selectedRaceWeeklyRows)) {
    rrsg_add_validation($validation, 'pass', 'Weekly standings are sorted correctly.');
} else {
    rrsg_add_validation($validation, 'fail', 'Weekly standings are not sorted correctly.');
}

if (!empty($selectedRaceWeeklyRows)) {
    $winner = rrsg_get_weekly_winner($selectedRaceWeeklyRows);
    $topPoints = (int)($selectedRaceWeeklyRows[0]['weeklyTotal'] ?? 0);

    $expectedWinnerNames = [];
    foreach ($selectedRaceWeeklyRows as $row) {
        $weeklyTotal = (int)($row['weeklyTotal'] ?? 0);
        if ($weeklyTotal !== $topPoints) {
            break;
        }
        $expectedWinnerNames[] = (string)($row['teamName'] ?? '');
    }

    $expectedWinnerString = implode(' / ', $expectedWinnerNames);

    if ($winner['teamName'] === $expectedWinnerString && (int)$winner['points'] === $topPoints) {
        rrsg_add_validation($validation, 'pass', 'Weekly winner matches highest weekly total (including ties).');
    } else {
        rrsg_add_validation($validation, 'fail', 'Weekly winner does not match highest weekly total.');
    }
}

$validationStatus = rrsg_validation_status($validation);

$segmentStandings = rrsg_sort_total_rows($segmentTotals);
$seasonStandings = rrsg_sort_total_rows($seasonTotals);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Race Results Single Test</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            margin: 20px;
        }

        h1, h2 {
            margin: 0 0 10px 0;
        }

        .meta {
            margin-bottom: 18px;
            line-height: 1.5;
        }

        .block {
            margin-bottom: 28px;
        }

        .controls {
            margin-bottom: 20px;
            padding: 12px;
            border: 1px solid #bbb;
            background: #f7f7f7;
            max-width: 900px;
        }

        .controls form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 14px;
            align-items: center;
        }

        .controls label {
            font-weight: bold;
        }

        .controls select,
        .controls button {
            font: inherit;
            padding: 4px 8px;
        }

        .validation-box {
            max-width: 1200px;
            border: 1px solid #999;
            padding: 12px 14px;
            margin-bottom: 22px;
            background: #fcfcfc;
        }

        .validation-status {
            font-weight: bold;
            margin-bottom: 10px;
        }

        .validation-columns {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .validation-column {
            min-width: 260px;
            flex: 1 1 260px;
        }

        .validation-column h3 {
            margin: 0 0 8px 0;
            font-size: 15px;
        }

        .validation-column ul {
            margin: 0;
            padding-left: 20px;
        }

        .validation-column li {
            margin-bottom: 4px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            max-width: 1200px;
        }

        th, td {
            border: 1px solid #999;
            padding: 6px 8px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f2f2f2;
        }

        td.num {
            text-align: right;
            white-space: nowrap;
        }

        tr:nth-child(even) td {
            background: #fafafa;
        }
    </style>
</head>
<body>

<h1>Race Results Single Test</h1>

<div class="controls">
    <form method="get" action="">
        <label for="year">Year:</label>
        <select name="year" id="year" onchange="this.form.submit()">
            <?php foreach ($availableYears as $yearOpt): ?>
                <option value="<?php echo rrsg_h($yearOpt); ?>" <?php echo ($yearOpt === $selectedYear ? 'selected' : ''); ?>>
                    <?php echo rrsg_h($yearOpt); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="race">Race:</label>
        <select name="race" id="race">
            <?php foreach ($pointRaces as $raceOpt): ?>
                <option value="<?php echo rrsg_h($raceOpt['raceCode']); ?>" <?php echo ($raceOpt['raceCode'] === $selectedRaceCode ? 'selected' : ''); ?>>
                    <?php echo rrsg_h($raceOpt['raceCode'] . ' ' . rrsg_short_race_label((string)$raceOpt['raceName'])); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Show</button>
    </form>
</div>

<div class="meta">
    <strong>Scoring Year:</strong> <?php echo rrsg_h($scoreYear); ?><br>
    <strong>Scoring Segment:</strong> <?php echo rrsg_h($scoreSegment); ?><br>
    <strong>Selected Race:</strong> <?php echo rrsg_h($selectedRaceCode); ?><br>
    <strong>Teams Loaded:</strong> <?php echo count($teamRows); ?><br>
    <strong>Selected Snapshot:</strong> <?php echo rrsg_h($selectedRaceMeta['snapshotFile'] !== '' ? basename($selectedRaceMeta['snapshotFile']) : 'NOT FOUND'); ?><br>
    <strong>Drivers read from snapshot:</strong> <?php echo rrsg_h($selectedRaceMeta['driverCount']); ?>
</div>

<div class="validation-box">
    <div class="validation-status">
        Validation Status: <?php echo rrsg_h($validationStatus); ?>
    </div>

    <div class="validation-columns">
        <div class="validation-column">
            <h3>PASS</h3>
            <ul>
                <?php if (empty($validation['pass'])): ?>
                    <li>None</li>
                <?php else: ?>
                    <?php foreach ($validation['pass'] as $msg): ?>
                        <li><?php echo rrsg_h($msg); ?></li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <div class="validation-column">
            <h3>WARN</h3>
            <ul>
                <?php if (empty($validation['warn'])): ?>
                    <li>None</li>
                <?php else: ?>
                    <?php foreach ($validation['warn'] as $msg): ?>
                        <li><?php echo rrsg_h($msg); ?></li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <div class="validation-column">
            <h3>FAIL</h3>
            <ul>
                <?php if (empty($validation['fail'])): ?>
                    <li>None</li>
                <?php else: ?>
                    <?php foreach ($validation['fail'] as $msg): ?>
                        <li><?php echo rrsg_h($msg); ?></li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<div class="block">
    <h2><?php echo rrsg_h($selectedYear); ?> Weekly Winners Through <?php echo rrsg_h($selectedRaceCode); ?></h2>
    <table>
        <thead>
            <tr>
                <th>Race</th>
                <th>Winner</th>
                <th>Points</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($selectedRace === null): ?>
                <tr>
                    <td colspan="3">No race selected.</td>
                </tr>
            <?php else: ?>
                <?php
                $winnerRows = $pointRaces;
                usort($winnerRows, function ($a, $b) {
                    return ((int)$a['number']) <=> ((int)$b['number']);
                });
                ?>
                <?php foreach ($winnerRows as $race): ?>
                    <?php if ((int)$race['number'] > $selectedRaceNumber) continue; ?>
                    <?php $raceCode = (string)$race['raceCode']; ?>
                    <tr>
                        <td><?php echo rrsg_h($raceCode . ' ' . rrsg_short_race_label((string)$race['raceName'])); ?></td>
                        <td><?php echo rrsg_h($weeklyWinners[$raceCode]['teamName'] ?? ''); ?></td>
                        <td class="num"><?php echo rrsg_h($weeklyWinners[$raceCode]['points'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="block">
    <h2><?php echo rrsg_h($selectedYear . ' ' . $selectedRaceMeta['raceCode'] . ' ' . $selectedRaceMeta['raceLabel']); ?> Weekly Standings</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Team</th>
                <th>Weekly Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($selectedRaceWeeklyRows)): ?>
                <tr>
                    <td colspan="3">No weekly rows generated.</td>
                </tr>
            <?php else: ?>
                <?php $rank = 1; ?>
                <?php foreach ($selectedRaceWeeklyRows as $row): ?>
                    <tr>
                        <td class="num"><?php echo $rank; ?></td>
                        <td><?php echo rrsg_h($row['teamName']); ?></td>
                        <td class="num"><strong><?php echo rrsg_h($row['weeklyTotal']); ?></strong></td>
                    </tr>
                    <?php $rank++; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="block">
    =<h2><?php echo rrsg_h($selectedYear); ?> Segment Standings Through <?php echo rrsg_h($selectedRaceCode); ?></h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Team</th>
                <th>Segment Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($segmentStandings)): ?>
                <tr>
                    <td colspan="3">No segment standings generated.</td>
                </tr>
            <?php else: ?>
                <?php $rank = 1; ?>
                <?php foreach ($segmentStandings as $row): ?>
                    <tr>
                        <td class="num"><?php echo $rank; ?></td>
                        <td><?php echo rrsg_h($row['teamName']); ?></td>
                        <td class="num"><strong><?php echo rrsg_h($row['total']); ?></strong></td>
                    </tr>
                    <?php $rank++; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="block">
    <h2><?php echo rrsg_h($selectedYear); ?> Season Standings Through <?php echo rrsg_h($selectedRaceCode); ?></h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Team</th>
                <th>Season Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($seasonStandings)): ?>
                <tr>
                    <td colspan="3">No season standings generated.</td>
                </tr>
            <?php else: ?>
                <?php $rank = 1; ?>
                <?php foreach ($seasonStandings as $row): ?>
                    <tr>
                        <td class="num"><?php echo $rank; ?></td>
                        <td><?php echo rrsg_h($row['teamName']); ?></td>
                        <td class="num"><strong><?php echo rrsg_h($row['total']); ?></strong></td>
                    </tr>
                    <?php $rank++; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>