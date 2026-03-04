<?php
session_start();

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

/**
 * Team Chart
 * - Defaults come from admin_setup via config_mrl.php
 * - Retains dropdown selections after submit
 * - Presentation: NO WRAPPING (matches legacy look)
 * - No schema changes
 */

// ---------- helpers ----------
function h($val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

function valid_year($y): bool {
    return preg_match('/^\d{4}$/', (string)$y) === 1;
}

function valid_segment($s): bool {
    return preg_match('/^S[1-9]\d*$/', (string)$s) === 1;
}

// ---------- POST values ----------
$postYear    = $_POST['year']    ?? '';
$postSegment = $_POST['segment'] ?? '';

// ---------- load years + segments from DB ----------
$years    = [];
$segments = [];

try {
    if (isset($dbo) && $dbo instanceof PDO) {

        $stmt = $dbo->query("SELECT year FROM years WHERE year > 0 ORDER BY year ASC");
        $years = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];

        $stmt = $dbo->query("SELECT segment FROM segments ORDER BY segment ASC");
        $segments = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];

    } elseif (isset($dbconnect)) {

        $res = mysqli_query($dbconnect, "SELECT year FROM years WHERE year > 0 ORDER BY year ASC");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $years[] = $r['year'];
        }

        $res = mysqli_query($dbconnect, "SELECT segment FROM segments ORDER BY segment ASC");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $segments[] = $r['segment'];
        }
    }
} catch (Throwable $e) {
    // fail soft – page will still render
}

// ---------- defaults from admin_setup (config_mrl.php) ----------
$defaultYear = '';
if (isset($raceYear) && valid_year($raceYear) && in_array((string)$raceYear, array_map('strval', $years), true)) {
    $defaultYear = (string)$raceYear;
} elseif (!empty($years)) {
    $defaultYear = (string)max(array_map('intval', $years));
} else {
    $defaultYear = date('Y');
}

$defaultSegment = '';
if (isset($segment) && valid_segment($segment) && in_array((string)$segment, array_map('strval', $segments), true)) {
    $defaultSegment = (string)$segment;
} elseif (!empty($segments)) {
    $defaultSegment = (string)$segments[0];
} else {
    $defaultSegment = 'S1';
}

// ---------- selected values ----------
$selectedYear = (valid_year($postYear) && in_array((string)$postYear, array_map('strval', $years), true))
    ? (string)$postYear
    : $defaultYear;

$selectedSegment = (valid_segment($postSegment) && in_array((string)$postSegment, array_map('strval', $segments), true))
    ? (string)$postSegment
    : $defaultSegment;

// ---------- segment display names ----------
$segmentNames = [
    'S1' => 'Segment #1',
    'S2' => 'Segment #2',
    'S3' => 'Segment #3',
    'S4' => 'Playoffs'
];

$segmentLabel = $segmentNames[$selectedSegment] ?? $selectedSegment;

// ---------- load picks after submit ----------
$hasSubmit = ($_SERVER['REQUEST_METHOD'] === 'POST');
$picks     = [];
$dbError   = '';

if ($hasSubmit) {
    try {
        if (isset($dbo) && $dbo instanceof PDO) {

            $sql = "
                SELECT teamName, userName, driverA, driverB, driverC, driverD, entryDate
                FROM picks
                WHERE raceYear = :year
                  AND segment  = :segment
                  AND userName != 'MRL'
                ORDER BY userID ASC
            ";

            $stmt = $dbo->prepare($sql);
            $stmt->execute([
                ':year'    => $selectedYear,
                ':segment' => $selectedSegment
            ]);

            $picks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif (isset($dbconnect)) {

            $sql = "
                SELECT teamName, userName, driverA, driverB, driverC, driverD, entryDate
                FROM picks
                WHERE raceYear = ?
                  AND segment  = ?
                  AND userName != 'MRL'
                ORDER BY userID ASC
            ";

            $stmt = mysqli_prepare($dbconnect, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $selectedYear, $selectedSegment);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($r = mysqli_fetch_assoc($res))) {
                    $picks[] = $r;
                }
                mysqli_stmt_close($stmt);
            } else {
                $dbError = 'Unable to prepare picks query.';
            }
        } else {
            $dbError = 'Database connection not available.';
        }
    } catch (Throwable $e) {
        $dbError = 'Database error while loading picks.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Team Chart</title>

    <!-- Cache-bust to force browser to load latest stylesheet -->
    <link rel="stylesheet" href="/mrl-styles.css?v=20260120a">
</head>
<body>

<?php
if (isset($adminStatusLine)) {
    echo $adminStatusLine;
}
?>

<div class="teamchart-container">

    <form method="post" class="teamchart-form">
        <div class="teamchart-row">
            <label class="teamchart-label" for="year">Choose year:</label>
            <select id="year" name="year" class="teamchart-select" required>
                <?php foreach ($years as $y): ?>
                    <?php $yStr = (string)$y; ?>
                    <option value="<?=h($yStr)?>" <?=($yStr === $selectedYear ? 'selected' : '')?>>
                        <?=h($yStr)?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="teamchart-label" for="segment">Choose segment:</label>
            <select id="segment" name="segment" class="teamchart-select" required>
                <?php foreach ($segments as $s): ?>
                    <?php $sStr = (string)$s; ?>
                    <option value="<?=h($sStr)?>" <?=($sStr === $selectedSegment ? 'selected' : '')?>>
                        <?=h($sStr)?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="teamchart-button">Show</button>
        </div>
    </form>

<?php if ($hasSubmit): ?>

    <?php if ($dbError): ?>
        <div class="notice-error"><?=h($dbError)?></div>
    <?php else: ?>

        <div class="teamchart-scroll">
            <table class="teamchart-table">
                <thead>
                    <tr class="teamchart-title-row">
                        <th colspan="7"><?=h($selectedYear)?> <?=h($segmentLabel)?> Team Chart</th>
                    </tr>
                    <tr class="teamchart-header-row">
                        <th>Team</th>
                        <th>Owner</th>
                        <th>Group A</th>
                        <th>Group B</th>
                        <th>Group C</th>
                        <th>Group D</th>
                        <th>Submission Time</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($picks)): ?>
                        <tr>
                            <td colspan="7" class="teamchart-empty">No picks found for this year / segment.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($picks as $row): ?>
                            <tr>
                                <td class="teamchart-cell-team"><?=h($row['teamName'] ?? '')?></td>
                                <td class="teamchart-cell-owner"><?=h($row['userName'] ?? '')?></td>
                                <td class="teamchart-cell-a"><?=h($row['driverA'] ?? '')?></td>
                                <td class="teamchart-cell-b"><?=h($row['driverB'] ?? '')?></td>
                                <td class="teamchart-cell-c"><?=h($row['driverC'] ?? '')?></td>
                                <td class="teamchart-cell-d"><?=h($row['driverD'] ?? '')?></td>
                                <td class="teamchart-cell-time"><?=h($row['entryDate'] ?? '')?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

<?php endif; ?>

</div>

</body>
</html>
