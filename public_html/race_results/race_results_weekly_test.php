<?php
declare(strict_types=1);

/**
 * race_results_weekly_test.php
 *
 * VERSION: v1.00.00
 * LAST MODIFIED: 2026-03-12
 * BUILD TS: 20260312_001900000
 *
 * CHANGELOG:
 * v1.00.00 (2026-03-12)
 *   - Initial weekly standings proof-of-concept page.
 *   - Loads team picks for a year + segment.
 *   - Loads one race snapshot HTML file.
 *   - Matches each team's 4 drivers to snapshot results.
 *   - Calculates weekly total using NET = PTS - PENALTY.
 *
 * PHP: 7.3 compatible.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once __DIR__ . '/race_results_team_helper.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';

function rrwt_h($val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

function rrwt_driver_net(array $driverPoints, string $driverName): int
{
    if ($driverName === '') {
        return 0;
    }

    if (!isset($driverPoints[$driverName]) || !is_array($driverPoints[$driverName])) {
        return 0;
    }

    return (int)($driverPoints[$driverName]['net'] ?? 0);
}

/**
 * Sort by weeklyTotal DESC, then teamName ASC.
 */
function rrwt_sort_rows(array &$rows): void
{
    usort($rows, function ($a, $b) {
        $aTotal = (int)($a['weeklyTotal'] ?? 0);
        $bTotal = (int)($b['weeklyTotal'] ?? 0);

        if ($aTotal !== $bTotal) {
            return ($bTotal <=> $aTotal);
        }

        $aName = (string)($a['teamName'] ?? '');
        $bName = (string)($b['teamName'] ?? '');

        return strcasecmp($aName, $bName);
    });
}

$year = '2026';
$segment = 'S1';
$raceLabel = 'R04 Phoenix';

// Change this path any time you want to test another race snapshot.
$snapshotFile = __DIR__ . '/2026/R04_NASCAR_Cup_Series_at_Phoenix_202603080023/snapshot_20260308_205601920.html';

$teamRows = rr_get_segment_team_picks($dbo ?? null, $dbconnect ?? null, $year, $segment);
$driverPoints = rrs_load_snapshot_driver_points($snapshotFile);

$weeklyRows = [];

foreach ($teamRows as $team) {
    $driverA = (string)($team['driverA'] ?? '');
    $driverB = (string)($team['driverB'] ?? '');
    $driverC = (string)($team['driverC'] ?? '');
    $driverD = (string)($team['driverD'] ?? '');

    $netA = rrwt_driver_net($driverPoints, $driverA);
    $netB = rrwt_driver_net($driverPoints, $driverB);
    $netC = rrwt_driver_net($driverPoints, $driverC);
    $netD = rrwt_driver_net($driverPoints, $driverD);

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

rrwt_sort_rows($weeklyRows);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Race Results Weekly Test</title>
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
            margin-bottom: 16px;
            line-height: 1.5;
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

<h1>Race Results Weekly Test</h1>

<div class="meta">
    <strong>Year:</strong> <?php echo rrwt_h($year); ?><br>
    <strong>Segment:</strong> <?php echo rrwt_h($segment); ?><br>
    <strong>Race:</strong> <?php echo rrwt_h($raceLabel); ?><br>
    <strong>Snapshot:</strong> <?php echo rrwt_h(basename($snapshotFile)); ?><br>
    <strong>Teams Loaded:</strong> <?php echo count($teamRows); ?><br>
    <strong>Drivers Loaded:</strong> <?php echo count($driverPoints); ?>
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
        <?php if (empty($weeklyRows)): ?>
            <tr>
                <td colspan="12">No weekly rows generated.</td>
            </tr>
        <?php else: ?>
            <?php $rank = 1; ?>
            <?php foreach ($weeklyRows as $row): ?>
                <tr>
                    <td class="num"><?php echo $rank; ?></td>
                    <td><?php echo rrwt_h($row['teamName']); ?></td>
                    <td><?php echo rrwt_h($row['userName']); ?></td>

                    <td><?php echo rrwt_h($row['driverA']); ?></td>
                    <td class="num"><?php echo rrwt_h($row['netA']); ?></td>

                    <td><?php echo rrwt_h($row['driverB']); ?></td>
                    <td class="num"><?php echo rrwt_h($row['netB']); ?></td>

                    <td><?php echo rrwt_h($row['driverC']); ?></td>
                    <td class="num"><?php echo rrwt_h($row['netC']); ?></td>

                    <td><?php echo rrwt_h($row['driverD']); ?></td>
                    <td class="num"><?php echo rrwt_h($row['netD']); ?></td>

                    <td class="num"><strong><?php echo rrwt_h($row['weeklyTotal']); ?></strong></td>
                </tr>
                <?php $rank++; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

</body>
</html>