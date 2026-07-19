<?php
/*
MRL SNAPSHOT MRL VIEW BACKFILL
VERSION: v003
LAST MODIFIED: 7/19/2026 9:33:21 am
TIME ZONE: America/New_York

CHANGELOG:
v003 (7/19/2026 9:33:21 am)
- FIX: Preserves the POS / DRIVER / CAR / MANUFACTURER / LAPS / START / LED / PTS / BONUS / PENALTY header row.
- CHANGE: Generated view titles now include retained/source driver counts, such as (20/38).

v002 (7/19/2026 9:12:31 am)
- FIX: Recognizes the actual MRL Lite TD-based colhead row and DRIVER column.
- CHANGE: Condensed the browser and print report for practical 1920x1080 viewing.
- CHANGE: Reduced row padding, font sizes, panel spacing, and long-path wrapping.
- CHANGE: Added print rules to fit the dry-run report more naturally.

v001
- NEW: Initial dry-run/create backfill for _mrl and _mrl_segment views.

PURPOSE:
- Create snapshot_..._mrl.html from each existing snapshot_..._lite.html,
  keeping only drivers listed in the MRL A/B/C/D driver tables for the year.
- Create snapshot_..._mrl_segment.html from each existing snapshot_..._lite.html,
  keeping only drivers used anywhere in that race's segment, including regular,
  late-pick, and replacement-pick rows.

Upload to /race_results/, open in a browser, review the dry run,
then click Create MRL View Files. Delete this script afterward.

PHP 7.3 compatible.
*/

declare(strict_types=1);
date_default_timezone_set('America/New_York');

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';

$year = isset($_GET['year']) ? preg_replace('/[^0-9]/', '', (string)$_GET['year']) : '2026';
if ($year === '') {
    $year = '2026';
}

$yearDir = __DIR__ . DIRECTORY_SEPARATOR . $year;
$execute = isset($_GET['execute']) && $_GET['execute'] === '1';
$overwrite = isset($_GET['overwrite']) && $_GET['overwrite'] === '1';

function mrlbf_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function mrlbf_ns(string $v): string
{
    return trim((string)preg_replace('/\s+/', ' ', $v));
}

function mrlbf_name_key(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = mrlbf_ns($name);
    $name = str_replace(["\xC2\xA0", '’'], [' ', "'"], $name);
    $name = preg_replace('/\s+\([A-Za-z0-9 -]+\)$/', '', $name);
    return strtolower(trim((string)$name));
}

function mrlbf_race_code(string $folder): ?string
{
    if (!preg_match('/^R(\d{1,2})(?:_|$)/i', $folder, $m)) {
        return null;
    }

    return 'R' . str_pad((string)((int)$m[1]), 2, '0', STR_PAD_LEFT);
}

function mrlbf_race_number(string $raceCode): int
{
    if (!preg_match('/^R(\d{2})$/', $raceCode, $m)) {
        return 0;
    }

    return (int)$m[1];
}

function mrlbf_segment_from_race_number(int $raceNumber): string
{
    if ($raceNumber >= 1 && $raceNumber <= 8) return 'S1';
    if ($raceNumber >= 9 && $raceNumber <= 17) return 'S2';
    if ($raceNumber >= 18 && $raceNumber <= 26) return 'S3';
    if ($raceNumber >= 27 && $raceNumber <= 36) return 'S4';
    return '';
}

function mrlbf_snapshot_info(string $filename): ?array
{
    if (!preg_match('/^snapshot_(\d{8})_(\d{9})_lite\.html$/', $filename, $m)) {
        return null;
    }

    $dt = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . substr($m[2], 0, 6));
    if (!$dt) {
        return null;
    }

    return [
        'stamp' => $m[1] . '_' . $m[2],
        'display' => $dt->format('n/j/y g:ia'),
    ];
}

function mrlbf_source_lite_snapshots(string $raceDir): array
{
    $files = glob($raceDir . DIRECTORY_SEPARATOR . 'snapshot_*_lite.html') ?: [];
    $out = [];

    foreach ($files as $file) {
        if (mrlbf_snapshot_info(basename($file)) === null) {
            continue;
        }
        $out[] = $file;
    }

    sort($out, SORT_STRING);
    return $out;
}

function mrlbf_query_year_drivers(string $year, $dbo, $dbconnect): array
{
    $names = [];
    $tables = ['A Drivers', 'B Drivers', 'C Drivers', 'D Drivers'];

    if ($dbo instanceof PDO) {
        foreach ($tables as $table) {
            $sql = 'SELECT driverName FROM `' . $table . '` WHERE driverYear = :year';
            $stmt = $dbo->prepare($sql);
            $stmt->execute([':year' => $year]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = trim((string)($row['driverName'] ?? ''));
                if ($name !== '') {
                    $names[mrlbf_name_key($name)] = $name;
                }
            }
        }
        return $names;
    }

    if ($dbconnect instanceof mysqli) {
        foreach ($tables as $table) {
            $sql = 'SELECT driverName FROM `' . $table . '` WHERE driverYear = ?';
            $stmt = mysqli_prepare($dbconnect, $sql);
            if (!$stmt) {
                continue;
            }

            mysqli_stmt_bind_param($stmt, 's', $year);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            while ($row = mysqli_fetch_assoc($result)) {
                $name = trim((string)($row['driverName'] ?? ''));
                if ($name !== '') {
                    $names[mrlbf_name_key($name)] = $name;
                }
            }

            mysqli_stmt_close($stmt);
        }
    }

    return $names;
}

function mrlbf_query_segment_drivers(string $year, string $segment, $dbo, $dbconnect): array
{
    $names = [];

    if ($dbo instanceof PDO) {
        $sql = "
            SELECT driverA, driverB, driverC, driverD
            FROM user_picks
            WHERE raceYear = :year
              AND segment = :segment
        ";
        $stmt = $dbo->prepare($sql);
        $stmt->execute([
            ':year' => $year,
            ':segment' => $segment,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            foreach (['driverA', 'driverB', 'driverC', 'driverD'] as $field) {
                $name = trim((string)($row[$field] ?? ''));
                if ($name !== '') {
                    $names[mrlbf_name_key($name)] = $name;
                }
            }
        }

        return $names;
    }

    if ($dbconnect instanceof mysqli) {
        $sql = "
            SELECT driverA, driverB, driverC, driverD
            FROM user_picks
            WHERE raceYear = ?
              AND segment = ?
        ";
        $stmt = mysqli_prepare($dbconnect, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ss', $year, $segment);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            while ($row = mysqli_fetch_assoc($result)) {
                foreach (['driverA', 'driverB', 'driverC', 'driverD'] as $field) {
                    $name = trim((string)($row[$field] ?? ''));
                    if ($name !== '') {
                        $names[mrlbf_name_key($name)] = $name;
                    }
                }
            }

            mysqli_stmt_close($stmt);
        }
    }

    return $names;
}

function mrlbf_outer_html(DOMDocument $dom, DOMNode $node): string
{
    return (string)$dom->saveHTML($node);
}

function mrlbf_find_results_table(DOMXPath $xp): ?DOMElement
{
    $nodes = $xp->query('//table[contains(concat(" ", normalize-space(@class), " "), " tablehead ")]');
    if ($nodes !== false && $nodes->length && $nodes->item(0) instanceof DOMElement) {
        return $nodes->item(0);
    }

    $nodes = $xp->query('//table[.//th[contains(translate(normalize-space(.), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "DRIVER")]]');
    if ($nodes !== false && $nodes->length && $nodes->item(0) instanceof DOMElement) {
        return $nodes->item(0);
    }

    return null;
}

function mrlbf_driver_column_index(DOMXPath $xp, DOMElement $table): int
{
    /*
     * MRL Lite snapshots use a TD-based ESPN header row:
     * <tr class="colhead"><td>POS</td><td>DRIVER</td>...</tr>
     *
     * Prefer that exact row, then fall back to any row containing DRIVER.
     */
    $headerRows = $xp->query(
        './/tr[contains(concat(" ", normalize-space(@class), " "), " colhead ")]'
        . ' | .//tr[td or th]'
    );

    if ($headerRows === false || !$headerRows->length) {
        return -1;
    }

    foreach ($headerRows as $row) {
        $cells = $xp->query('./th|./td', $row);
        if ($cells === false) {
            continue;
        }

        for ($i = 0; $i < $cells->length; $i++) {
            $label = strtoupper(mrlbf_ns((string)$cells->item($i)->textContent));
            if ($label === 'DRIVER') {
                return $i;
            }
        }
    }

    /*
     * The current MRL Lite format always places DRIVER in column 2.
     * Keep this explicit fallback so a harmless class-name change does not
     * prevent a dry-run from identifying the known MRL Lite table format.
     */
    return 1;
}

function mrlbf_filter_lite(
    string $sourcePath,
    array $allowedDrivers,
    string $viewLabel,
    string $sourceBase
): array {
    $raw = @file_get_contents($sourcePath);
    if ($raw === false || trim($raw) === '') {
        return ['ok' => false, 'error' => 'Source unreadable or empty.'];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = @$dom->loadHTML($raw);
    libxml_clear_errors();

    if (!$loaded) {
        return ['ok' => false, 'error' => 'Could not parse source HTML.'];
    }

    $xp = new DOMXPath($dom);
    $table = mrlbf_find_results_table($xp);
    if (!$table) {
        return ['ok' => false, 'error' => 'Race results table not found.'];
    }

    $driverIndex = mrlbf_driver_column_index($xp, $table);
    if ($driverIndex < 0) {
        return ['ok' => false, 'error' => 'Driver column not found.'];
    }

    $kept = 0;
    $removed = 0;
    $sourceDriverCount = 0;
    $rows = $xp->query('.//tr[td]', $table);

    if ($rows !== false) {
        for ($i = $rows->length - 1; $i >= 0; $i--) {
            $row = $rows->item($i);
            if (!$row || !$row->parentNode) {
                continue;
            }

            $rowClass = ' ' . mrlbf_ns((string)($row->attributes && $row->attributes->getNamedItem('class')
                ? $row->attributes->getNamedItem('class')->nodeValue
                : '')) . ' ';

            // Preserve the MRL Lite column-header row.
            if (strpos($rowClass, ' colhead ') !== false) {
                continue;
            }

            $cells = $xp->query('./td', $row);
            if ($cells === false || $cells->length <= $driverIndex) {
                continue;
            }

            $sourceDriverCount++;

            $driverName = mrlbf_ns((string)$cells->item($driverIndex)->textContent);
            $driverKey = mrlbf_name_key($driverName);

            if ($driverKey !== '' && isset($allowedDrivers[$driverKey])) {
                $kept++;
            } else {
                $row->parentNode->removeChild($row);
                $removed++;
            }
        }
    }

    $titleNodes = $xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " mrl-lite-title ")]');
    if ($titleNodes !== false && $titleNodes->length) {
        $titleNode = $titleNodes->item(0);
        if ($titleNode) {
            $baseTitle = mrlbf_ns((string)$titleNode->textContent);
            while ($titleNode->firstChild) {
                $titleNode->removeChild($titleNode->firstChild);
            }
            $titleNode->appendChild(
                $dom->createTextNode(
                    $baseTitle . ' — ' . $viewLabel . ' (' . $kept . '/' . $sourceDriverCount . ')'
                )
            );
        }
    }

    $sourceComments = $xp->query('//comment()[contains(., "Source:")]');
    if ($sourceComments !== false) {
        foreach ($sourceComments as $comment) {
            if ($comment->parentNode) {
                $comment->parentNode->replaceChild(
                    $dom->createComment(' Source: ' . $sourceBase . ' | View: ' . $viewLabel . ' '),
                    $comment
                );
            }
        }
    }

    $html = $dom->saveHTML();
    if (!is_string($html) || trim($html) === '') {
        return ['ok' => false, 'error' => 'Filtered HTML could not be generated.'];
    }

    return [
        'ok' => true,
        'html' => $html,
        'kept' => $kept,
        'removed' => $removed,
    ];
}

$report = [];
$totals = [
    'sources' => 0,
    'would' => 0,
    'created' => 0,
    'skipped' => 0,
    'errors' => 0,
];

$yearDrivers = mrlbf_query_year_drivers($year, $dbo ?? null, $dbconnect ?? null);
$segmentDriverCache = [];

if (empty($yearDrivers)) {
    $report[] = [
        'status' => 'ERROR',
        'source' => '',
        'target' => '',
        'message' => 'No MRL year drivers were found for ' . $year . '.',
    ];
    $totals['errors']++;
}

if (!is_dir($yearDir)) {
    $report[] = [
        'status' => 'ERROR',
        'source' => $yearDir,
        'target' => '',
        'message' => 'Year directory not found.',
    ];
    $totals['errors']++;
} elseif (empty($yearDrivers)) {
    // Stop before scanning because both outputs depend on valid year data.
} else {
    $folders = glob($yearDir . DIRECTORY_SEPARATOR . 'R*', GLOB_ONLYDIR) ?: [];
    sort($folders, SORT_STRING);

    foreach ($folders as $raceDir) {
        $folder = basename($raceDir);
        $raceCode = mrlbf_race_code($folder);
        if ($raceCode === null) {
            continue;
        }

        $raceNumber = mrlbf_race_number($raceCode);
        $segment = mrlbf_segment_from_race_number($raceNumber);
        if ($segment === '') {
            continue;
        }

        if (!isset($segmentDriverCache[$segment])) {
            $segmentDriverCache[$segment] = mrlbf_query_segment_drivers(
                $year,
                $segment,
                $dbo ?? null,
                $dbconnect ?? null
            );
        }

        $segmentDrivers = $segmentDriverCache[$segment];
        $sources = mrlbf_source_lite_snapshots($raceDir);

        foreach ($sources as $sourcePath) {
            $totals['sources']++;
            $sourceBase = basename($sourcePath);

            $targets = [
                [
                    'suffix' => '_mrl.html',
                    'label' => 'MRL Year Drivers',
                    'drivers' => $yearDrivers,
                ],
                [
                    'suffix' => '_mrl_segment.html',
                    'label' => 'MRL Segment ' . $segment . ' Drivers',
                    'drivers' => $segmentDrivers,
                ],
            ];

            foreach ($targets as $targetDef) {
                $targetBase = preg_replace('/_lite\.html$/', $targetDef['suffix'], $sourceBase);
                $targetPath = $raceDir . DIRECTORY_SEPARATOR . $targetBase;

                if (is_file($targetPath) && !$overwrite) {
                    $report[] = [
                        'status' => 'SKIP',
                        'source' => $folder . '/' . $sourceBase,
                        'target' => $targetBase,
                        'message' => 'Target already exists.',
                    ];
                    $totals['skipped']++;
                    continue;
                }

                if (empty($targetDef['drivers'])) {
                    $report[] = [
                        'status' => 'ERROR',
                        'source' => $folder . '/' . $sourceBase,
                        'target' => $targetBase,
                        'message' => 'No allowed drivers found for ' . $targetDef['label'] . '.',
                    ];
                    $totals['errors']++;
                    continue;
                }

                $result = mrlbf_filter_lite(
                    $sourcePath,
                    $targetDef['drivers'],
                    $targetDef['label'],
                    $sourceBase
                );

                if (!$result['ok']) {
                    $report[] = [
                        'status' => 'ERROR',
                        'source' => $folder . '/' . $sourceBase,
                        'target' => $targetBase,
                        'message' => $result['error'],
                    ];
                    $totals['errors']++;
                    continue;
                }

                $detail = $targetDef['label']
                    . ' — kept ' . (int)$result['kept']
                    . ', removed ' . (int)$result['removed'];

                if (!$execute) {
                    $report[] = [
                        'status' => 'WOULD CREATE',
                        'source' => $folder . '/' . $sourceBase,
                        'target' => $targetBase,
                        'message' => $detail,
                    ];
                    $totals['would']++;
                    continue;
                }

                $bytes = @file_put_contents($targetPath, $result['html']);
                if ($bytes === false) {
                    $report[] = [
                        'status' => 'ERROR',
                        'source' => $folder . '/' . $sourceBase,
                        'target' => $targetBase,
                        'message' => 'Could not write target file.',
                    ];
                    $totals['errors']++;
                    continue;
                }

                $mtime = @filemtime($sourcePath);
                if ($mtime !== false) {
                    @touch($targetPath, $mtime, $mtime);
                }

                $report[] = [
                    'status' => 'CREATED',
                    'source' => $folder . '/' . $sourceBase,
                    'target' => $targetBase,
                    'message' => $detail . ' — ' . number_format($bytes) . ' bytes',
                ];
                $totals['created']++;
            }
        }
    }
}

$queryBase = '?year=' . rawurlencode($year);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MRL Snapshot MRL View Backfill</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;margin:10px;background:#f3f3f3;color:#111;font-size:13px}
h1{margin:0 0 4px;font-size:24px}
.panel{background:#fff;border:1px solid #bbb;border-radius:6px;padding:8px 10px;margin:7px 0}
.summary{display:flex;gap:12px;flex-wrap:wrap;font-weight:700;line-height:1.2}
a.button{display:inline-block;padding:6px 10px;border-radius:5px;border:1px solid #555;background:#1769c2;color:#fff;text-decoration:none;font-weight:700}
a.secondary{background:#555}
table{border-collapse:collapse;width:100%;background:#fff;table-layout:fixed;font-size:11px}
th,td{border:1px solid #bbb;padding:3px 5px;text-align:left;vertical-align:top;line-height:1.15}
th{background:#ddd}
th:nth-child(1),td:nth-child(1){width:82px}
th:nth-child(2),td:nth-child(2){width:39%}
th:nth-child(3),td:nth-child(3){width:31%}
th:nth-child(4),td:nth-child(4){width:auto}
td code{white-space:normal;overflow-wrap:anywhere;word-break:break-word}
tr:nth-child(even) td{background:#f7f7f7}
.ok{color:#087b2e;font-weight:700}
.skip{color:#666;font-weight:700}
.warn{color:#a15c00;font-weight:700}
.error{color:#c00000;font-weight:700}
code{font-family:Consolas,monospace}
.small{font-size:11px;color:#555;line-height:1.25;margin:5px 0}
p{margin:6px 0}
@media print{
  @page{size:landscape;margin:0.25in}
  body{margin:0;background:#fff;font-size:9px}
  h1{font-size:17px}
  .panel{padding:5px 7px;margin:4px 0;border-radius:0}
  a.button{display:none}
  table{font-size:8px}
  th,td{padding:2px 3px;line-height:1.08}
  .small{font-size:8px}
}
</style>
</head>
<body>
<h1>MRL Snapshot MRL View Backfill</h1>
<div><strong>Year:</strong> <?= mrlbf_h($year) ?> &nbsp; <strong>Mode:</strong> <?= $execute ? 'CREATE FILES' : 'DRY RUN ONLY' ?></div>

<div class="panel">
<div class="summary">
<span>MRL year drivers: <?= count($yearDrivers) ?></span>
<?php foreach ($segmentDriverCache as $segmentName => $segmentNames): ?>
<span><?= mrlbf_h($segmentName) ?> drivers: <?= count($segmentNames) ?></span>
<?php endforeach; ?>
</div>
<p class="small">
<strong>_mrl:</strong> all drivers listed in the year's A/B/C/D MRL driver tables.<br>
<strong>_mrl_segment:</strong> every driver appearing in any user_picks row for that segment, including SEG, LP, and RD rows.
</p>
</div>

<div class="panel">
<div class="summary">
<span>Lite sources: <?= (int)$totals['sources'] ?></span>
<span>Would create: <?= (int)$totals['would'] ?></span>
<span>Created: <?= (int)$totals['created'] ?></span>
<span>Skipped: <?= (int)$totals['skipped'] ?></span>
<span>Errors: <?= (int)$totals['errors'] ?></span>
</div>
<p><?= $execute ? 'Backfill complete. Verify several files, then delete this script.' : 'Review the list below. Nothing has been written yet.' ?></p>
<?php if (!$execute): ?>
<a class="button" href="<?= mrlbf_h($queryBase . '&execute=1') ?>">Create MRL View Files</a>
<?php else: ?>
<a class="button secondary" href="<?= mrlbf_h($queryBase) ?>">Run Dry Check Again</a>
<?php endif; ?>
</div>

<table>
<thead>
<tr>
<th>Status</th>
<th>Source</th>
<th>Target</th>
<th>Details</th>
</tr>
</thead>
<tbody>
<?php foreach ($report as $row): ?>
<?php
$statusClass = 'error';
if ($row['status'] === 'CREATED') $statusClass = 'ok';
elseif ($row['status'] === 'SKIP') $statusClass = 'skip';
elseif ($row['status'] === 'WOULD CREATE') $statusClass = 'warn';
?>
<tr>
<td class="<?= $statusClass ?>"><?= mrlbf_h($row['status']) ?></td>
<td><code><?= mrlbf_h($row['source']) ?></code></td>
<td><code><?= mrlbf_h($row['target']) ?></code></td>
<td><?= mrlbf_h($row['message']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
