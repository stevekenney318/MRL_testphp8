<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

/**
 * race_results_weekly_winner_diagnostic.php
 *
 * VERSION: v030
 * LAST MODIFIED: 3/13/2026 5:31:00 PM
 *
 * PURPOSE:
 *   - Diagnostic tool to detect weekly winner drift across the season.
 *   - For a selected year, calculates weekly winners repeatedly:
 *       - through R01
 *       - through R02
 *       - ...
 *       - through last race
 *   - Then compares each week's winner/points against the first time that
 *     week should have been finalized.
 *   - Helps identify bugs where earlier weeks change later in the season.
 *
 * NOTES:
 *   - This is a diagnostic helper only.
 *   - It does not modify any data.
 *   - It uses the same helper logic as race_results_single_test.php.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once __DIR__ . '/race_results_team_helper.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';
require_once __DIR__ . '/race_results_engine.php';

function rrwd_h($val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

function rrwd_driver_net(array $driverPoints, string $driverName): int
{
    if ($driverName === '') {
        return 0;
    }

    if (!isset($driverPoints[$driverName]) || !is_array($driverPoints[$driverName])) {
        return 0;
    }

    return (int)($driverPoints[$driverName]['net'] ?? 0);
}

function rrwd_sort_weekly_rows(array &$rows): void
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

function rrwd_find_snapshot_file(string $raceFolder): string
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

function rrwd_build_weekly_rows(array $teamRows, array $driverPoints): array
{
    $weeklyRows = [];

    foreach ($teamRows as $team) {
        $driverA = (string)($team['driverA'] ?? '');
        $driverB = (string)($team['driverB'] ?? '');
        $driverC = (string)($team['driverC'] ?? '');
        $driverD = (string)($team['driverD'] ?? '');

        $netA = rrwd_driver_net($driverPoints, $driverA);
        $netB = rrwd_driver_net($driverPoints, $driverB);
        $netC = rrwd_driver_net($driverPoints, $driverC);
        $netD = rrwd_driver_net($driverPoints, $driverD);

        $weeklyTotal = $netA + $netB + $netC + $netD;

        $weeklyRows[] = [
            'teamName' => (string)($team['teamName'] ?? ''),
            'weeklyTotal' => $weeklyTotal,
        ];
    }

    rrwd_sort_weekly_rows($weeklyRows);
    return $weeklyRows;
}

function rrwd_get_weekly_winner(array $weeklyRows): array
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

function rrwd_segment_from_race_number(int $raceNumber): string
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

function rrwd_available_years(string $baseDir): array
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

function rrwd_load_year_index_file(string $path): array
{
    $idx = rr_load_json($path);
    if (!is_array($idx)) return [];
    if (!isset($idx['races']) || !is_array($idx['races'])) return [];
    return $idx;
}

function rrwd_points_races_from_index(array $yearIndex, string $yearBaseFolder): array
{
    $rows = [];

    foreach ($yearIndex['races'] as $raceId => $row) {
        if (!is_array($row)) continue;

        $kind = (string)($row['kind'] ?? '');
        if ($kind !== 'R') continue;

        $number = (int)($row['number'] ?? 0);
        $folder = (string)($row['folder'] ?? '');
        $raceName = (string)($row['race_name'] ?? '');

        if ($number <= 0 || $folder === '') continue;

        $rows[] = [
            'raceId' => (string)$raceId,
            'number' => $number,
            'raceCode' => 'R' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'raceName' => $raceName,
            'raceFolder' => $yearBaseFolder . '/' . $folder,
        ];
    }

    usort($rows, function ($a, $b) {
        return ((int)$a['number']) <=> ((int)$b['number']);
    });

    return $rows;
}

function rrwd_short_race_label(string $raceName): string
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

$baseDir = __DIR__;
$availableYears = rrwd_available_years($baseDir);

$selectedYear = isset($_GET['year']) ? trim((string)$_GET['year']) : '';
if (!in_array($selectedYear, $availableYears, true)) {
    $selectedYear = !empty($availableYears) ? $availableYears[0] : '2026';
}

$yearFolder = $baseDir . '/' . $selectedYear;
$yearIndexFile = $yearFolder . '/_year_index.json';
$yearIndex = rrwd_load_year_index_file($yearIndexFile);
$pointRaces = rrwd_points_races_from_index($yearIndex, $yearFolder);

/*
 * Matrix:
 * $winnerMatrix['R08']['R03'] = winner for week R03 when page is calculated through R08
 */
$winnerMatrix = [];
$baseline = []; // first valid value for each week
$diagnosticRows = [];

foreach ($pointRaces as $throughRace) {
    $throughRaceNumber = (int)$throughRace['number'];
    $throughRaceCode = (string)$throughRace['raceCode'];

    $winnersThisRun = [];

    foreach ($pointRaces as $race) {
        $raceNumber = (int)$race['number'];
        if ($raceNumber > $throughRaceNumber) {
            continue;
        }

        $scoreSegment = rrwd_segment_from_race_number($raceNumber);
        $teamRows = rr_get_segment_team_picks($dbo ?? null, $dbconnect ?? null, $selectedYear, $scoreSegment);

        $snapshotFile = rrwd_find_snapshot_file((string)$race['raceFolder']);
        $winner = [
            'teamName' => '',
            'points' => 0,
        ];

        if ($snapshotFile !== '') {
            $driverPoints = rrs_load_snapshot_driver_points($snapshotFile);
            $weeklyRows = rrwd_build_weekly_rows($teamRows, $driverPoints);
            $winner = rrwd_get_weekly_winner($weeklyRows);
        }

        $raceCode = (string)$race['raceCode'];

        $winnersThisRun[$raceCode] = [
            'teamName' => (string)$winner['teamName'],
            'points' => (int)$winner['points'],
        ];

        if (!isset($baseline[$raceCode])) {
            $baseline[$raceCode] = $winnersThisRun[$raceCode];
        }
    }

    $winnerMatrix[$throughRaceCode] = $winnersThisRun;
}

foreach ($pointRaces as $race) {
    $raceCode = (string)$race['raceCode'];
    $raceLabel = rrwd_short_race_label((string)$race['raceName']);

    $row = [
        'raceCode' => $raceCode,
        'raceLabel' => $raceLabel,
        'baselineTeam' => (string)($baseline[$raceCode]['teamName'] ?? ''),
        'baselinePoints' => (int)($baseline[$raceCode]['points'] ?? 0),
        'changedAt' => [],
        'allMatch' => true,
    ];

    foreach ($winnerMatrix as $throughRaceCode => $winnersThisRun) {
        if (!isset($winnersThisRun[$raceCode])) {
            continue;
        }

        $curTeam = (string)$winnersThisRun[$raceCode]['teamName'];
        $curPoints = (int)$winnersThisRun[$raceCode]['points'];

        if (
            $curTeam !== $row['baselineTeam'] ||
            $curPoints !== $row['baselinePoints']
        ) {
            $row['allMatch'] = false;
            $row['changedAt'][] = [
                'throughRaceCode' => $throughRaceCode,
                'teamName' => $curTeam,
                'points' => $curPoints,
            ];
        }
    }

    $diagnosticRows[] = $row;
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Weekly Winner Diagnostic</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            margin: 20px;
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
        table {
            border-collapse: collapse;
            width: 100%;
            max-width: 1400px;
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
        .ok {
            color: #0a7a0a;
            font-weight: bold;
        }
        .bad {
            color: #b30000;
            font-weight: bold;
        }
        ul {
            margin: 0;
            padding-left: 18px;
        }
    </style>
</head>
<body>

<h1>Weekly Winner Diagnostic</h1>

<div class="controls">
    <form method="get" action="">
        <label for="year">Year:</label>
        <select name="year" id="year">
            <?php foreach ($availableYears as $yearOpt): ?>
                <option value="<?php echo rrwd_h($yearOpt); ?>" <?php echo ($yearOpt === $selectedYear ? 'selected' : ''); ?>>
                    <?php echo rrwd_h($yearOpt); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Run Diagnostic</button>
    </form>
</div>

<table>
    <thead>
        <tr>
            <th>Race</th>
            <th>Baseline Winner</th>
            <th>Baseline Points</th>
            <th>Status</th>
            <th>Changed Later?</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($diagnosticRows as $row): ?>
            <tr>
                <td><?php echo rrwd_h($row['raceCode'] . ' ' . $row['raceLabel']); ?></td>
                <td><?php echo rrwd_h($row['baselineTeam']); ?></td>
                <td><?php echo rrwd_h($row['baselinePoints']); ?></td>
                <td class="<?php echo $row['allMatch'] ? 'ok' : 'bad'; ?>">
                    <?php echo $row['allMatch'] ? 'OK' : 'CHANGED'; ?>
                </td>
                <td>
                    <?php if ($row['allMatch']): ?>
                        None
                    <?php else: ?>
                        <ul>
                            <?php foreach ($row['changedAt'] as $chg): ?>
                                <li>
                                    Through <?php echo rrwd_h($chg['throughRaceCode']); ?>:
                                    <?php echo rrwd_h($chg['teamName']); ?>
                                    (<?php echo rrwd_h($chg['points']); ?>)
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>