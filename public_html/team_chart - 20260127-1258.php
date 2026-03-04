<?php
ob_start();
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

// Match team.php timing behavior
date_default_timezone_set('America/New_York');
$currentTimeIs = date("n/j/Y g:i a");

/**
 * Team Chart
 * - Year/segment dropdowns sourced from DB
 * - Defaults come from admin_setup via config_mrl.php ($raceYear, $segment, $formLockDate, $formLockTime, $formLocked)
 * - Retains dropdown selections after submit
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
    // Try to load PhpSpreadsheet autoloader
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
        // PhpSpreadsheet classes
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Team Chart');

        // Filename
        $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filenameBase);
        $filename = $safeBase . '.xlsx';

        // Colors (match your webpage)
        $cHeader = 'FABF8F'; // header fill
        $cTeam   = 'B7DEE8'; // team/owner/time fill
        $cA      = 'D9D9D9';
        $cB      = 'C4BD97';
        $cC      = 'B8CCE4';
        $cD      = 'D8E4BC';

        // Columns
        $headers = ['Team','Owner','Group A','Group B','Group C','Group D','Submission Time'];

        // Row 1: Title (merged A1:G1)
        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells('A1:G1');

        // Row 2: Header labels
        $sheet->fromArray($headers, null, 'A2');

        // Data rows begin at row 3
        $r = 3;
        foreach ($rows as $row) {
            $sheet->setCellValue("A{$r}", (string)($row['teamName'] ?? ''));
            $sheet->setCellValue("B{$r}", (string)($row['userName'] ?? ''));
            $sheet->setCellValue("C{$r}", (string)($row['driverA'] ?? ''));
            $sheet->setCellValue("D{$r}", (string)($row['driverB'] ?? ''));
            $sheet->setCellValue("E{$r}", (string)($row['driverC'] ?? ''));
            $sheet->setCellValue("F{$r}", (string)($row['driverD'] ?? ''));

            // Force “Submission Time” to stay EXACTLY like DB: YYYY-MM-DD HH:MM:SS (as text)
            $sheet->setCellValueExplicit(
                "G{$r}",
                (string)($row['entryDate'] ?? ''),
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );

            $r++;
        }

        // If no rows, add a single message row
        if ($r === 3) {
            $sheet->setCellValue('A3', 'No picks found for this year / segment.');
            $sheet->mergeCells('A3:G3');
            $r = 4;
        }

        $lastRow   = $r - 1;
        $rangeAll  = "A1:G{$lastRow}";
        $rangeTitle= "A1:G1";
        $rangeHdr  = "A2:G2";
        $rangeData = ($lastRow >= 3) ? "A3:G{$lastRow}" : "";

        // Fonts
        $sheet->getStyle($rangeAll)->getFont()->setName('Century Gothic')->setSize(12);

        // Title style (row 1)
        $sheet->getStyle($rangeTitle)->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => $cHeader],
            ],
        ]);

        // Header style (row 2)
        $sheet->getStyle($rangeHdr)->applyFromArray([
            'font' => ['bold' => true, 'size' => 13],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => $cHeader],
            ],
        ]);

        // Column fills for data rows
        if ($rangeData !== '') {
            // Team/Owner/Time
            $sheet->getStyle("A3:B{$lastRow}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRgb($cTeam);

            $sheet->getStyle("G3:G{$lastRow}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRgb($cTeam);

            // A/B/C/D groups
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

        // Borders: thin line around ALL cells
        $sheet->getStyle($rangeAll)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        // Alignment
        $sheet->getStyle($rangeAll)->getAlignment()
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        if ($rangeData !== '') {
            $sheet->getStyle($rangeData)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        }

        $sheet->getStyle($rangeAll)->getAlignment()->setWrapText(false);

        // Freeze panes under header row
        $sheet->freezePane('A3');

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(18);
        $sheet->getColumnDimension('F')->setWidth(18);
        $sheet->getColumnDimension('G')->setWidth(22);

        // --- Row heights (your request) ---
        // Title/header can be a little taller, and TEAM rows at 18 (instead of Excel default-ish 15)
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getRowDimension(2)->setRowHeight(20);
        if ($lastRow >= 3) {
            for ($i = 3; $i <= $lastRow; $i++) {
                $sheet->getRowDimension($i)->setRowHeight(18);
            }
        }

        // Output: clean buffered output so XLSX isn't corrupted
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
        // If anything goes wrong building the XLSX, don't throw a 500 — return readable text
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Spreadsheet export failed.\n\n";
        echo $e->getMessage();
        exit;
    }
}

// ---------- action + POST values ----------
$hasPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
$action  = $hasPost ? ($_POST['action'] ?? 'show') : 'show';

$postYear    = $hasPost ? ($_POST['year'] ?? '') : '';
$postSegment = $hasPost ? ($_POST['segment'] ?? '') : '';
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

// Normalize arrays to strings for comparisons
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

// ---------- selected values ----------
$selectedYear = (valid_year($postYear) && in_array((string)$postYear, $yearsStr, true))
    ? (string)$postYear
    : $defaultYear;

$selectedSegment = (valid_segment($postSegment) && in_array((string)$postSegment, $segmentsStr, true))
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

// ---------- submission gating (match team.php behavior) ----------
$currentRaceYear = isset($raceYear) ? (string)$raceYear : '';
$currentSegment  = isset($segment)  ? (string)$segment  : '';

$isCurrentSelection = ($selectedYear === $currentRaceYear && $selectedSegment === $currentSegment);

// Build lock timestamp from admin_setup values (best effort)
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

// Show submitted_teams.php instead of the current segment chart until deadline
$showSubmittedInsteadOfChart = false;
if (
    $hasPost
    && ($action === 'show' || $action === 'excel')
    && $isCurrentSelection
    && isset($formLocked) && $formLocked === 'no'
    && $lockTs > 0
    && $lockTs > $userTs
) {
    $showSubmittedInsteadOfChart = true;
}

// ---------- lock display pieces ----------
// FIX: Always format from $lockTs when available. This covers BOTH cases:
// 1) date-only + separate time, and
// 2) a full datetime stored in $formLockDate (with $formLockTime empty).
$lockTimeDisplay = '';
$lockDateDisplay = '';

if ($lockTs > 0) {
    $lockTimeDisplay = date('g:i A', $lockTs);
    $lockDateDisplay = date('n/j/Y', $lockTs);
} else {
    // Fallback (should be rare)
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

// ---------- load picks (only if we're showing chart OR exporting) ----------
$needsChartData = ($hasPost && ($action === 'show' || $action === 'excel') && !$showSubmittedInsteadOfChart);

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

// ---------- EXCEL EXPORT (REAL XLSX) ----------
$isExcel = ($hasPost && $action === 'excel');

if ($isExcel) {
    // If gated, do NOT export chart
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
    <link rel="stylesheet" href="/mrl-styles.css?v=20260123_xlsx1">

    <style>
        /* One-line controls */
        .teamchart-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: nowrap;
            white-space: nowrap;
        }

        /* Make action buttons match */
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

        /* Wrapper for Print/Spreadsheet so we can hide/show together */
        .teamchart-actions {
            display: inline-flex;
            gap: 10px;
            align-items: center;
        }
    </style>
</head>
<body>

<?php
if (isset($adminStatusLine)) {
    echo $adminStatusLine;
}
?>

<div class="teamchart-container">

    <?php
    // Buttons should appear ONLY after a chart is displayed (not gated, no db error)
    $chartDisplayed = ($hasPost && $action === 'show' && !$showSubmittedInsteadOfChart && $dbError === '');
    ?>

    <form id="teamchartForm" method="post" class="teamchart-form teamchart-no-print">
        <input type="hidden" name="action" id="action" value="show">

        <div class="teamchart-row">
            <label class="teamchart-label" for="year">Choose year:</label>
            <select id="year" name="year" class="teamchart-select" required>
                <?php foreach ($yearsStr as $yStr): ?>
                    <option value="<?=h($yStr)?>" <?=($yStr === $selectedYear ? 'selected' : '')?>>
                        <?=h($yStr)?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="teamchart-label" for="segment">Choose segment:</label>
            <select id="segment" name="segment" class="teamchart-select" required>
                <?php foreach ($segmentsStr as $sStr): ?>
                    <option value="<?=h($sStr)?>" <?=($sStr === $selectedSegment ? 'selected' : '')?>>
                        <?=h($sStr)?>
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
<?php if ($hasPost && $action === 'show'): ?>

    <?php if ($showSubmittedInsteadOfChart): ?>

        <div style="color:red; font-size:16pt; margin:10px 0;">
            Team Chart for <?=h($selectedYear)?> / <?=h($selectedSegment)?>
            will be available at <?=h($lockTimeDisplay)?> on <?=h($lockDateDisplay)?>
        </div>

        <?php include 'submitted_teams.php'; ?>

    <?php else: ?>

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

<?php endif; ?>

</div>

<script>
(function () {
    const form = document.getElementById('teamchartForm');
    const actionInput = document.getElementById('action');
    const yearSel = document.getElementById('year');
    const segSel  = document.getElementById('segment');

    const actionsWrap = document.getElementById('chartActions');
    const btnPrint = document.getElementById('btnPrint');
    const btnExcel = document.getElementById('btnExcel');

    // IMPORTANT:
    // After a download, browsers often restore form state (including hidden inputs).
    // Force the default action back to "show" every time the page loads.
    actionInput.value = 'show';

    function resetToShowAndHideActions() {
        actionInput.value = 'show';
        if (actionsWrap) actionsWrap.style.display = 'none';
    }

    yearSel.addEventListener('change', resetToShowAndHideActions);
    segSel.addEventListener('change', resetToShowAndHideActions);

    // Spreadsheet: submit same form with action=excel (server returns .xlsx download)
    if (btnExcel) {
        btnExcel.addEventListener('click', function () {
            actionInput.value = 'excel';
            form.submit();

            // Best-effort reset in case the browser keeps the page alive (some do)
            setTimeout(function () {
                actionInput.value = 'show';
            }, 0);
        });
    }

    // Print: keep current working print behavior and set the title for PDF filename
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
