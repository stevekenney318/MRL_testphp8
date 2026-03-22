<?php
declare(strict_types=1);

/**
 * race_results_segment_test.php
 *
 * VERSION: v1.00.01
 * LAST MODIFIED: 2026-03-12
 * BUILD TS: 20260312_030800000
 *
 * CHANGELOG:
 * v1.00.01 (2026-03-12)
 *   - FIX: Corrected R03 race folder name to match actual saved folder.
 *   - CLARIFY: This test page still uses explicitly defined race folders.
 *   - NOTE: Future version can read _year_index.json instead of hardcoded paths.
 *
 * v1.00.00 (2026-03-12)
 *   - Initial segment standings proof-of-concept page.
 *   - Uses dedicated scoring variables independent from admin_setup.
 *   - Loads team picks for one scoring year + scoring segment.
 *   - Loads multiple race snapshots for that segment.
 *   - Calculates:
 *       - weekly totals per race
 *       - segment totals through the selected set of races
 *       - season totals (same as segment for S1)
 *       - weekly winners
 *   - Renders a simple browser test page for validation.
 *
 * PHP: 7.3 compatible.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once __DIR__ . '/race_results_team_helper.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';

function rrst_h($val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

function rrst_driver_net(array $driverPoints, string $driverName): int
{
    if ($driverName === '') {
        return 0;
    }

    if (!isset($driverPoints[$driverName]) || !is_array($driverPoints[$driverName])) {
        return 0;
    }

    return (int)($driverPoints[$driverName]['net'] ?? 0);
}

function rrst_sort_assoc_desc_then_key(array $totals): array
{
    $rows = [];
    foreach ($totals as $teamName => $value) {
        $rows[] = [
            'teamName' => (string)$teamName,
            'total' => (int)$value,
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

function rrst_sort_weekly_rows(array &$rows): void
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

function rrst_find_snapshot_file(string $raceFolder): string
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

function rrst_build_weekly_rows(array $teamRows, array $driverPoints): array
{
    $weeklyRows = [];

    foreach ($teamRows as $team) {
        $driverA = (string)($team['driverA'] ?? '');
        $driverB = (string)($team['driverB'] ?? '');
        $driverC = (string)($team['driverC'] ?? '');
        $driverD = (string)($team['driverD'] ?? '');

        $netA = rrst_driver_net($driverPoints, $driverA);
        $netB = rrst_driver_net($driverPoints, $driverB);
        $netC = rrst_driver_net($driverPoints, $driverC);
        $netD = rrst_driver_net($driverPoints, $driverD);

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

    rrst_sort_weekly_rows($weeklyRows);
    return $weeklyRows;
}

function rrst_get_weekly_winner(array $weeklyRows): array
{
    if (empty($weeklyRows)) {
        return [
            'teamName' => '',
            'points' => 0,
        ];
    }

    return [
        'teamName' => (string)($weeklyRows[0]['teamName'] ?? ''),
        'points' => (int)($weeklyRows[0]['weeklyTotal'] ?? 0),
    ];
}

/* ------------------------------------------------------------------
   SCORING CONTROLS
   These are intentionally independent from admin_setup / current form
   ------------------------------------------------------------------ */

$scoreYear = '2026';
$scoreSegment = 'S1';

/*
    Explicit race folder list for proof-of-concept testing.

    NOTE:
    - These are intentionally hardcoded for the test phase.
    - Later, we can replace this with _year_index.json or DB-driven logic.
*/
$races = [
    [
        'raceCode'   => 'R01',
        'raceLabel'  => 'Daytona',
        'raceFolder' => __DIR__ . '/2026/R01_Daytona_500_202602150001',
    ],
    [
        'raceCode'   => 'R02',
        'raceLabel'  => 'Atlanta',
        'raceFolder' => __DIR__ . '/2026/R02_NASCAR_Cup_Series_at_Atlanta_202602220025',
    ],
    [
        'raceCode'   => 'R03',
        'raceLabel'  => 'COTA',
        'raceFolder' => __DIR__ . '/2026/R03_NASCAR_Cup_Series_at_Circuit_of_the_Americas_202603013998',
    ],
    [
        'raceCode'   => 'R04',
        'raceLabel'  => 'Phoenix',
        'raceFolder' => __DIR__ . '/2026/R04_NASCAR_Cup_Series_at_Phoenix_202603080023',
    ],
];

$teamRows = rr_get_segment_team_picks($dbo ?? null, $dbconnect ?? null, $scoreYear, $scoreSegment);

$resultsByRace = [];
$segmentTotals = [];
$seasonTotals = [];
$weeklyWinners = [];

foreach ($teamRows as $team) {
    $teamName = (string)($team['teamName'] ?? '');
    if ($teamName === '') {
        continue;
    }

    $segmentTotals[$teamName] = 0;
    $seasonTotals[$teamName] = 0;
}

foreach ($races as $race) {
    $raceCode = (string)($race['raceCode'] ?? '');
    $raceLabel = (string)($race['raceLabel'] ?? '');
    $raceFolder = (string)($race['raceFolder'] ?? '');

    $snapshotFile = rrst_find_snapshot_file($raceFolder);

    $driverPoints = [];
    $weeklyRows = [];

    if ($snapshotFile !== '') {
        $driverPoints = rrs_load_snapshot_driver_points($snapshotFile);
        $weeklyRows = rrst_build_weekly_rows($teamRows, $driverPoints);

        foreach ($weeklyRows as $row) {
            $teamName = (string)$row['teamName'];
            $weeklyTotal = (int)$row['weeklyTotal'];

            if (!isset($segmentTotals[$teamName])) {
                $segmentTotals[$teamName] = 0;
            }
            if (!isset($seasonTotals[$teamName])) {
                $seasonTotals[$teamName] = 0;
            }

            $segmentTotals[$teamName] += $weeklyTotal;
            $seasonTotals[$teamName] += $weeklyTotal;
        }

        $weeklyWinners[$raceCode] = rrst_get_weekly_winner($weeklyRows);
    } else {
        $weeklyWinners[$raceCode] = [
            'teamName' => '',
            'points' => 0,
        ];
    }

    $resultsByRace[$raceCode] = [
        'raceCode' => $raceCode,
        'raceLabel' => $raceLabel,
        'raceFolder' => $raceFolder,
        'snapshotFile' => $snapshotFile,
        'driverCount' => count($driverPoints),
        'weeklyRows' => $weeklyRows,
    ];
}

$segmentStandings = rrst_sort_assoc_desc_then_key($segmentTotals);
$seasonStandings = rrst_sort_assoc_desc_then_key($seasonTotals);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Race Results Segment Test</title>
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

<h1>Race Results Segment Test</h1>

<div class="meta">
    <strong>Scoring Year:</strong> <?php echo rrst_h($scoreYear); ?><br>
    <strong>Scoring Segment:</strong> <?php echo rrst_h($scoreSegment); ?><br>
    <strong>Teams Loaded:</strong> <?php echo count($teamRows); ?><br>
    <strong>Races Defined:</strong> <?php echo count($races); ?>
</div>

<div class="block">
    <h2>Weekly Winners</h2>
    <table>
        <thead>
            <tr>
                <th>Race</th>
                <th>Winner</th>
                <th>Points</th>
                <th>Snapshot</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($resultsByRace as $raceCode => $raceData): ?>
                <tr>
                    <td><?php echo rrst_h($raceCode . ' ' . $raceData['raceLabel']); ?></td>
                    <td><?php echo rrst_h($weeklyWinners[$raceCode]['teamName'] ?? ''); ?></td>
                    <td class="num"><?php echo rrst_h($weeklyWinners[$raceCode]['points'] ?? 0); ?></td>
                    <td><?php echo rrst_h($raceData['snapshotFile'] !== '' ? basename($raceData['snapshotFile']) : 'NOT FOUND'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php foreach ($resultsByRace as $raceCode => $raceData): ?>
    <div class="block">
        <h2><?php echo rrst_h($raceCode . ' ' . $raceData['raceLabel']); ?> Weekly Standings</h2>

        <div class="meta">
            <strong>Snapshot:</strong>
            <?php echo rrst_h($raceData['snapshotFile'] !== '' ? basename($raceData['snapshotFile']) : 'NOT FOUND'); ?><br>
            <strong>Drivers Loaded:</strong> <?php echo rrst_h($raceData['driverCount']); ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Team</th>
                    <th>Owner</th>
                    <th>Driver A</th>
                    <th>A Net</th>
                    <th>Driver B</th>
                    <th>B Net</th>
                    <th>Driver C</th>
                    <th>C Net</th>
                    <th>Driver D</th>
                    <th>D Net</th>
                    <th>Weekly Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($raceData['weeklyRows'])): ?>
                    <tr>
                        <td colspan="12">No weekly rows generated.</td>
                    </tr>
                <?php else: ?>
                    <?php $rank = 1; ?>
                    <?php foreach ($raceData['weeklyRows'] as $row): ?>
                        <tr>
                            <td class="num"><?php echo $rank; ?></td>
                            <td><?php echo rrst_h($row['teamName']); ?></td>
                            <td><?php echo rrst_h($row['userName']); ?></td>

                            <td><?php echo rrst_h($row['driverA']); ?></td>
                            <td class="num"><?php echo rrst_h($row['netA']); ?></td>

                            <td><?php echo rrst_h($row['driverB']); ?></td>
                            <td class="num"><?php echo rrst_h($row['netB']); ?></td>

                            <td><?php echo rrst_h($row['driverC']); ?></td>
                            <td class="num"><?php echo rrst_h($row['netC']); ?></td>

                            <td><?php echo rrst_h($row['driverD']); ?></td>
                            <td class="num"><?php echo rrst_h($row['netD']); ?></td>

                            <td class="num"><strong><?php echo rrst_h($row['weeklyTotal']); ?></strong></td>
                        </tr>
                        <?php $rank++; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>

<div class="block">
    <h2>Segment Standings</h2>
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
                        <td><?php echo rrst_h($row['teamName']); ?></td>
                        <td class="num"><strong><?php echo rrst_h($row['total']); ?></strong></td>
                    </tr>
                    <?php $rank++; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="block">
    <h2>Season Standings</h2>
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
                        <td><?php echo rrst_h($row['teamName']); ?></td>
                        <td class="num"><strong><?php echo rrst_h($row['total']); ?></strong></td>
                    </tr>
                    <?php $rank++; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>