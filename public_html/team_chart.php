<?php
// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);
// echo "<div style='font:11px/1.2 monospace; color:#999; text-align:center; margin:0; padding:0;'>"
//    . "FILE: " . basename(__FILE__) . " | " . date('Y-m-d H:i:s')
//    . "</div>";

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

// team_chart.php usually is NOT admin-only; keep adminStatusLine safe if missing
if (!isset($adminStatusLine)) {
    $adminStatusLine = '';
}

// Match team.php timing behavior
date_default_timezone_set('America/New_York');
$currentTimeIs = date("n/j/Y g:i a");

/**
 * Team Chart (PRG)
 * - "Show" uses POST -> session -> redirect (clean URL, no resubmission warning)
 * - "Spreadsheet" uses POST in a NEW TAB (clean URL, no resubmission warning)
 * - Year/segment dropdowns sourced from DB
 * - Defaults come from admin_setup via config_mrl.php ($raceYear, $segment, $formLockDate, $formLockTime, $formLocked)
 * - Gate current segment chart until deadline (show submitted_teams.php instead)
 * - Print: prints currently displayed chart (client-side window.print)
 * - Spreadsheet: downloads TRUE .xlsx (no Excel warning) formatted like the chart
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

/**
 * Sends a real XLSX file (no warning) using PhpSpreadsheet.
 * If PhpSpreadsheet is not installed, exits with a readable error.
 */
function send_excel_xlsx(string $filenameBase, array $rows, string $title): void
{
    $autoloadPath = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Spreadsheet export requires PhpSpreadsheet.\n\n";
        echo "Missing: " . $autoloadPath . "\n\n";
        echo "Fix:\n";
        echo "Option A (server): composer require phpoffice/phpspreadsheet\n";
        echo "Option B (local): run composer locally and upload the /vendor folder next to team_chart.php\n";
        exit;
    }
    require_once $autoloadPath;

    try {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Team Chart');

        $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filenameBase);
        $filename = $safeBase . '.xlsx';

        $cHeader = 'FABF8F';
        $cTeam   = 'B7DEE8';
        $cA      = 'D9D9D9';
        $cB      = 'C4BD97';
        $cC      = 'B8CCE4';
        $cD      = 'D8E4BC';

        $headers = ['Team','Owner','Group A','Group B','Group C','Group D','Submission Time'];

        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells('A1:G1');

        $sheet->fromArray($headers, null, 'A2');

        $r = 3;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$r}", (string)($row['teamName'] ?? ''));
            $sheet->setCellValue("B{$r}", (string)($row['userName'] ?? ''));
            $sheet->setCellValue("C{$r}", (string)($row['driverA'] ?? ''));
            $sheet->setCellValue("D{$r}", (string)($row['driverB'] ?? ''));
            $sheet->setCellValue("E{$r}", (string)($row['driverC'] ?? ''));
            $sheet->setCellValue("F{$r}", (string)($row['driverD'] ?? ''));

            $sheet->setCellValueExplicit(
                "G{$r}",
                (string)($row['entryDate'] ?? ''),
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );

            $r++;
        }

        if ($r === 3) {
            $sheet->setCellValue('A3', 'No picks found for this year / segment.');
            $sheet->mergeCells('A3:G3');
            $r = 4;
        }

        $lastRow    = $r - 1;
        $rangeAll   = "A1:G{$lastRow}";
        $rangeTitle = "A1:G1";
        $rangeHdr   = "A2:G2";
        $rangeData  = ($lastRow >= 3) ? "A3:G{$lastRow}" : "";

        $sheet->getStyle($rangeAll)->getFont()->setName('Century Gothic')->setSize(12);

        $sheet->getStyle($rangeTitle)->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => $cHeader],
            ],
        ]);

        $sheet->getStyle($rangeHdr)->applyFromArray([
            'font' => ['bold' => true, 'size' => 13],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => $cHeader],
            ],
        ]);

        if ($rangeData !== '') {
            $sheet->getStyle("A3:B{$lastRow}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRgb($cTeam);

            $sheet->getStyle("G3:G{$lastRow}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRgb($cTeam);

            $sheet->getStyle("C3:C{$lastRow}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRgb($cA);

            $sheet->getStyle("D3:D{$lastRow}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRgb($cB);

            $sheet->getStyle("E3:E{$lastRow}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRgb($cC);

            $sheet->getStyle("F3:F{$lastRow}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRgb($cD);
        }

        $sheet->getStyle($rangeAll)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        $sheet->getStyle($rangeAll)->getAlignment()
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        if ($rangeData !== '') {
            $sheet->getStyle($rangeData)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        }

        $sheet->getStyle($rangeAll)->getAlignment()->setWrapText(false);
        $sheet->freezePane('A3');

        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(18);
        $sheet->getColumnDimension('F')->setWidth(18);
        $sheet->getColumnDimension('G')->setWidth(22);

        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getRowDimension(2)->setRowHeight(20);
        if ($lastRow >= 3) {
            for ($i = 3; $i <= $lastRow; $i++) {
                $sheet->getRowDimension($i)->setRowHeight(18);
            }
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;

    } catch (Throwable $e) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Spreadsheet export failed.\n\n";
        echo $e->getMessage();
        exit;
    }
}
// ---------- load years + segments from DB ----------
$years    = [];
$segments = [];

try {
    if (isset($dbo) && $dbo instanceof PDO) {

        $stmt  = $dbo->query("SELECT year FROM years WHERE year > 0 ORDER BY year ASC");
        $years = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];

        $stmt     = $dbo->query("SELECT segment FROM segments ORDER BY segment ASC");
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
    // fail soft
}

$yearsStr    = array_map('strval', $years);
$segmentsStr = array_map('strval', $segments);

// ---------- defaults from admin_setup (config_mrl.php) ----------
$defaultYear = '';
if (isset($raceYear) && valid_year($raceYear) && in_array((string)$raceYear, $yearsStr, true)) {
    $defaultYear = (string)$raceYear;
} elseif (!empty($yearsStr)) {
    $defaultYear = (string)max(array_map('intval', $yearsStr));
} else {
    $defaultYear = date('Y');
}

$defaultSegment = '';
if (isset($segment) && valid_segment($segment) && in_array((string)$segment, $segmentsStr, true)) {
    $defaultSegment = (string)$segment;
} elseif (!empty($segmentsStr)) {
    $defaultSegment = (string)$segmentsStr[0];
} else {
    $defaultSegment = 'S1';
}

// ---------- PRG / request state ----------
$hasPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
$postAction  = $hasPost ? (string)($_POST['action'] ?? 'show') : '';
$postYear    = $hasPost ? (string)($_POST['year'] ?? '') : '';
$postSegment = $hasPost ? (string)($_POST['segment'] ?? '') : '';

$self = basename($_SERVER['PHP_SELF']);

// SHOW: store -> redirect (clean URL)
if ($hasPost && $postAction === 'show') {

    $useYear = (valid_year($postYear) && in_array($postYear, $yearsStr, true))
        ? $postYear
        : $defaultYear;

    $useSeg = (valid_segment($postSegment) && in_array($postSegment, $segmentsStr, true))
        ? $postSegment
        : $defaultSegment;

    $_SESSION['teamchart_year']    = $useYear;
    $_SESSION['teamchart_segment'] = $useSeg;
    $_SESSION['teamchart_has']     = true;

    // mark this as the PRG landing
    $_SESSION['teamchart_from_prg'] = true;

    // PRG redirect (303 is best practice after POST)
    header("Location: {$self}", true, 303);
    exit;
}

// Do we currently have a selection?
$hasSelection = (isset($_SESSION['teamchart_has']) && $_SESSION['teamchart_has'] === true);

// NORMAL PAGE ENTRY (not right after PRG):
// always reset to admin_setup defaults
if (!$hasPost && empty($_SESSION['teamchart_from_prg'])) {
    $_SESSION['teamchart_year']    = $defaultYear;
    $_SESSION['teamchart_segment'] = $defaultSegment;
    $_SESSION['teamchart_has']     = true;
    $hasSelection = true;
}

// PRG flag is one-time use
unset($_SESSION['teamchart_from_prg']);

// Final selected values
$selectedYear    = $defaultYear;
$selectedSegment = $defaultSegment;

if ($hasSelection) {
    $sy = (string)($_SESSION['teamchart_year'] ?? '');
    $ss = (string)($_SESSION['teamchart_segment'] ?? '');

    if (valid_year($sy) && in_array($sy, $yearsStr, true)) {
        $selectedYear = $sy;
    }
    if (valid_segment($ss) && in_array($ss, $segmentsStr, true)) {
        $selectedSegment = $ss;
    }
}


// Spreadsheet: allow override + keep session in sync
$isExcelPost = ($hasPost && $postAction === 'excel');
if ($isExcelPost) {
    $excelYear = (valid_year($postYear) && in_array($postYear, $yearsStr, true)) ? $postYear : $selectedYear;
    $excelSeg  = (valid_segment($postSegment) && in_array($postSegment, $segmentsStr, true)) ? $postSegment : $selectedSegment;

    $_SESSION['teamchart_year']    = $excelYear;
    $_SESSION['teamchart_segment'] = $excelSeg;
    $_SESSION['teamchart_has']     = true;

    $selectedYear = $excelYear;
    $selectedSegment = $excelSeg;
}

$segmentNames = [
    'S1' => 'Segment #1',
    'S2' => 'Segment #2',
    'S3' => 'Segment #3',
    'S4' => 'Playoffs'
];
$segmentLabel = $segmentNames[$selectedSegment] ?? $selectedSegment;

// ---------- submission gating (match team.php behavior) ----------
$currentRaceYear = isset($raceYear) ? (string)$raceYear : '';
$currentSegment  = isset($segment)  ? (string)$segment  : '';

$isCurrentSelection = ($selectedYear === $currentRaceYear && $selectedSegment === $currentSegment);

$formLockDateRaw = trim((string)($formLockDate ?? ''));
$formLockTimeRaw = trim((string)($formLockTime ?? ''));

$lockTs = 0;
if ($formLockDateRaw !== '') {
    $lockStr = ($formLockTimeRaw !== '') ? ($formLockDateRaw . ' ' . $formLockTimeRaw) : $formLockDateRaw;
    $tmp = strtotime($lockStr);
    $lockTs = ($tmp === false) ? 0 : (int)$tmp;
}

$userTs = strtotime($currentTimeIs);
$userTs = ($userTs === false) ? time() : (int)$userTs;

$showSubmittedInsteadOfChart = false;
if (
    $hasSelection
    && $isCurrentSelection
    && isset($formLocked) && $formLocked === 'no'
    && $lockTs > 0
    && $lockTs > $userTs
) {
    $showSubmittedInsteadOfChart = true;
}

$lockTimeDisplay = '';
$lockDateDisplay = '';

if ($lockTs > 0) {
    $lockTimeDisplay = date('g:i A', $lockTs);
    $lockDateDisplay = date('n/j/Y', $lockTs);
} else {
    if ($formLockTimeRaw !== '') {
        $lockTimeDisplay = $formLockTimeRaw;
        $t = strtotime($formLockTimeRaw);
        if ($t !== false) $lockTimeDisplay = date('g:i A', $t);
    }
    if ($formLockDateRaw !== '') {
        $lockDateDisplay = $formLockDateRaw;
        $d = strtotime($formLockDateRaw);
        if ($d !== false) $lockDateDisplay = date('n/j/Y', $d);
    }
}

// ---------- load picks ----------
$needsChartData = (($hasSelection && !$showSubmittedInsteadOfChart) || $isExcelPost);

$picks   = [];
$dbError = '';

if ($needsChartData) {
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

// ---------- EXCEL EXPORT ----------
if ($isExcelPost) {
    if ($showSubmittedInsteadOfChart) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Team Chart for {$selectedYear} / {$selectedSegment} will be available at {$lockTimeDisplay} on {$lockDateDisplay}";
        exit;
    }

    if ($dbError) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $dbError;
        exit;
    }

    $title = $selectedYear . ' ' . $segmentLabel . ' Team Chart';
    send_excel_xlsx("Team_Chart_{$selectedYear}_{$selectedSegment}", $picks, $title);
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Team Chart</title>
    <link rel="stylesheet" href="/mrl-styles.css?v=20260123_prg1">

    <style>
        .teamchart-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: nowrap;
            white-space: nowrap;
        }

        .teamchart-actionbtn {
            height: 32px;
            padding: 0 12px;
            border: 1px solid #999;
            background: #eee;
            border-radius: 4px;
            cursor: pointer;
            font-family: inherit;
            font-size: 13pt;
            line-height: normal;
        }

        .teamchart-actions {
            display: inline-flex;
            gap: 10px;
            align-items: center;
        }
    </style>
</head>
<body>

<?php echo $adminStatusLine; ?>

<div class="teamchart-container">

<?php
$chartDisplayed = ($hasSelection && !$showSubmittedInsteadOfChart && $dbError === '');
?>

    <form id="teamchartForm" method="post" class="teamchart-form teamchart-no-print" action="<?php echo h($self); ?>">
        <input type="hidden" name="action" value="show">

        <div class="teamchart-row">
            <label class="teamchart-label" for="year">Choose year:</label>
            <select id="year" name="year" class="teamchart-select" required>
                <?php foreach ($yearsStr as $yStr): ?>
                    <option value="<?php echo h($yStr); ?>" <?php echo ($yStr === $selectedYear ? 'selected' : ''); ?>>
                        <?php echo h($yStr); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="teamchart-label" for="segment">Choose segment:</label>
            <select id="segment" name="segment" class="teamchart-select" required>
                <?php foreach ($segmentsStr as $sStr): ?>
                    <option value="<?php echo h($sStr); ?>" <?php echo ($sStr === $selectedSegment ? 'selected' : ''); ?>>
                        <?php echo h($sStr); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="teamchart-button">Show</button>

            <?php if ($chartDisplayed): ?>
                <span id="chartActions" class="teamchart-actions">
                    <button type="button" id="btnPrint" class="teamchart-actionbtn">Print</button>
                    <button type="button" id="btnExcel" class="teamchart-actionbtn">Spreadsheet</button>
                </span>
            <?php endif; ?>
        </div>
    </form>

    <form id="excelForm" method="post" action="<?php echo h($self); ?>" target="_blank" style="display:none;">
        <input type="hidden" name="action" value="excel">
        <input type="hidden" name="year" id="excelYear" value="">
        <input type="hidden" name="segment" id="excelSegment" value="">
    </form>

<?php if ($hasSelection): ?>

    <?php if ($showSubmittedInsteadOfChart): ?>

        <div style="color:red; font-size:16pt; margin:10px 0;">
            Team Chart for <?php echo h($selectedYear); ?> / <?php echo h($selectedSegment); ?>
            will be available at <?php echo h($lockTimeDisplay); ?> on <?php echo h($lockDateDisplay); ?>
        </div>

        <?php include 'submitted_teams.php'; ?>

    <?php else: ?>

        <?php if ($dbError): ?>
            <div class="notice-error"><?php echo h($dbError); ?></div>
        <?php else: ?>

            <div class="teamchart-scroll">
                <table class="teamchart-table">
                    <thead>
                        <tr class="teamchart-title-row">
                            <th colspan="7"><?php echo h($selectedYear); ?> <?php echo h($segmentLabel); ?> Team Chart</th>
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
                                    <td class="teamchart-cell-team"><?php echo h($row['teamName'] ?? ''); ?></td>
                                    <td class="teamchart-cell-owner"><?php echo h($row['userName'] ?? ''); ?></td>
                                    <td class="teamchart-cell-a"><?php echo h($row['driverA'] ?? ''); ?></td>
                                    <td class="teamchart-cell-b"><?php echo h($row['driverB'] ?? ''); ?></td>
                                    <td class="teamchart-cell-c"><?php echo h($row['driverC'] ?? ''); ?></td>
                                    <td class="teamchart-cell-d"><?php echo h($row['driverD'] ?? ''); ?></td>
                                    <td class="teamchart-cell-time"><?php echo h($row['entryDate'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    <?php endif; ?>

<?php endif; ?>

</div>

<script>
(function () {
    const yearSel = document.getElementById('year');
    const segSel  = document.getElementById('segment');

    const actionsWrap = document.getElementById('chartActions');
    const btnPrint = document.getElementById('btnPrint');
    const btnExcel = document.getElementById('btnExcel');

    const excelForm = document.getElementById('excelForm');
    const excelYear = document.getElementById('excelYear');
    const excelSeg  = document.getElementById('excelSegment');

    function hideActionsWhenChanged() {
        if (actionsWrap) actionsWrap.style.display = 'none';
    }

    if (yearSel) yearSel.addEventListener('change', hideActionsWhenChanged);
    if (segSel)  segSel.addEventListener('change', hideActionsWhenChanged);

    if (btnExcel && excelForm && excelYear && excelSeg) {
        btnExcel.addEventListener('click', function () {
            excelYear.value = yearSel.value || '';
            excelSeg.value  = segSel.value || '';
            excelForm.submit();
        });
    }

    if (btnPrint) {
        btnPrint.addEventListener('click', function () {
            const oldTitle = document.title;

            const y = yearSel.value || '';
            const s = segSel.value || '';
            const fileTitle = 'Team_Chart_' + y + '_' + s;

            document.title = fileTitle;
            window.print();

            setTimeout(function () {
                document.title = oldTitle;
            }, 500);
        });
    }
})();
</script>

</body>
</html>
