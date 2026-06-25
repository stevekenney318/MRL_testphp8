<?php
/**
 * install_standings_timeline_lite_v001_20260625_033100pm.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/25/2026 3:31:00 pm
 *
 * CHANGELOG:
 * v001 (6/25/2026 3:31:00 pm)
 *   - NEW: Creates standings_timeline_lite.php as a public-friendly as-of standings view.
 *   - CHANGE: Points weekly_standings.php As-of links to standings_timeline_lite.php instead of the full timeline browser.
 *   - CHANGE: Bumps weekly_standings.php from v059 to v060 when patching the As-of link.
 *
 * Upload to /race_results/, run once, then delete after success.
 * PHP: 7.3 compatible.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

$baseDir = __DIR__;
$report = [];
$errors = [];
$stamp = date('Ymd_His');

function inst_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function inst_backup_file(string $path, string $stamp, array &$report): bool
{
    if (!is_file($path)) {
        return false;
    }
    $backup = $path . '.bak_' . $stamp;
    if (!@copy($path, $backup)) {
        return false;
    }
    $report[] = 'Backup created: ' . basename($backup);
    return true;
}

function inst_write_file(string $path, string $content, string $stamp, array &$report, array &$errors): void
{
    if (is_file($path)) {
        if (!inst_backup_file($path, $stamp, $report)) {
            $errors[] = 'Backup failed for ' . basename($path);
            return;
        }
    }

    $tmp = $path . '.tmp_' . $stamp;
    $bytes = @file_put_contents($tmp, $content, LOCK_EX);
    if ($bytes === false) {
        $errors[] = 'Write failed for temporary file ' . basename($tmp);
        return;
    }
    @chmod($tmp, 0644);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        $errors[] = 'Replace failed for ' . basename($path);
        return;
    }
    @chmod($path, 0644);
    $report[] = 'Updated: ' . basename($path);
}

$liteContent = <<<'PHP_LITE'
<?php

declare(strict_types=1);

/**
 * standings_timeline_lite.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/25/2026 3:31:00 pm
 *
 * CHANGELOG:
 * v001 (6/25/2026 3:31:00 pm)
 *   - NEW: Public-friendly lite as-of standings page.
 *   - NEW: Reuses standings_timeline.php data-building logic, then renders a weekly_standings-like four-table view.
 *   - NEW: Accepts the same year/snapshot/race query values as standings_timeline.php and also accepts release= as a snapshot alias.
 *
 * Purpose:
 *   Give weekly_standings.php a simple As-of target without exposing the full timeline browser controls.
 *
 * PHP: 7.3 compatible.
 */

if (!isset($_GET['snapshot']) && isset($_GET['release'])) {
    $_GET['snapshot'] = (string)$_GET['release'];
}

$timelineFile = __DIR__ . '/standings_timeline.php';
if (!is_file($timelineFile)) {
    http_response_code(500);
    echo 'standings_timeline.php not found.';
    exit;
}

ob_start();
require $timelineFile;
ob_end_clean();

function stlite_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function stlite_compact_snapshot_value(string $value): string
{
    return preg_replace('/^(\d{8}_\d{6}).*_(R\d{2})$/', '$1_$2', $value) ?: $value;
}

$asOfRaceText = trim((string)($asOfRaceCode ?? '') . ' ' . (string)($asOfRaceLabel ?? ''));
$viewRaceText = trim((string)($selectedViewRaceCode ?? '') . ' ' . (string)($selectedViewRaceLabel ?? ''));
$versionLabel = (string)($selectedSnapshot['version_label'] ?? '');
$asOfDisplay = (string)($selectedSnapshotDisplay ?? '');
$selectedSnapshotValueText = (string)($selectedSnapshotValue ?? '');
$changeLabel = function_exists('st_snapshot_change_label') && isset($selectedSnapshot) && is_array($selectedSnapshot)
    ? st_snapshot_change_label($selectedSnapshot)
    : '';

$currentUrl = 'weekly_standings.php';
if ((string)($selectedYear ?? '') !== '') {
    $currentUrl .= '?year=' . rawurlencode((string)$selectedYear);
    if ((string)($selectedViewRaceCode ?? '') !== '') {
        $currentUrl .= '&race=' . rawurlencode((string)$selectedViewRaceCode);
    }
}

$fullTimelineUrl = 'standings_timeline.php?year=' . rawurlencode((string)($selectedYear ?? ''))
    . '&snapshot=' . rawurlencode($selectedSnapshotValueText)
    . '&race=' . rawurlencode((string)($selectedViewRaceCode ?? ''));

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MRL Standings Timeline Lite</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    html { scrollbar-gutter: stable; }
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 16px;
        line-height: 1.3;
        margin: 12px;
        color: #111;
        background: #fff;
    }
    .page-wrap {
        max-width: 1400px;
        margin: 0 auto;
    }
    .lite-banner {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px 10px;
        margin: 0 0 10px 0;
        padding: 9px 12px;
        border-radius: 22px;
        background: #241f32;
        color: #fff;
        border: 1px solid #55466e;
        box-shadow: 0 2px 8px rgba(0,0,0,0.18);
    }
    .lite-title {
        font-size: 17px;
        font-weight: 800;
        white-space: nowrap;
    }
    .lite-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        padding: 3px 9px;
        font-size: 13px;
        font-weight: 800;
        color: #084298;
        background: #d9ecff;
        border: 2px solid #7db7ff;
        text-decoration: none;
        white-space: nowrap;
    }
    .lite-chip.version {
        color: #fff;
        background: #2e8b57;
        border-color: #1f5f3b;
    }
    .lite-chip.change {
        color: #fff;
        background: #2e8b57;
        border-color: #1f5f3b;
    }
    .lite-links {
        margin-left: auto;
        display: inline-flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .lite-subline {
        margin: -3px 0 10px 2px;
        color: #555;
        font-size: 12px;
    }
    .report-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        align-items: start;
    }
    .report-panel {
        min-width: 0;
    }
    .panel-title {
        font-size: 16px;
        margin: 0 0 4px 0;
        font-weight: normal;
    }
    .snapshot-footnote {
        margin-left: 5px;
        color: #667;
        font-size: 12px;
        font-style: italic;
        white-space: nowrap;
    }
    .table-wrap {
        overflow-x: auto;
    }
    table {
        border-collapse: collapse;
        width: 100%;
        table-layout: fixed;
        background: #fff;
    }
    th, td {
        border: 2px solid #151313;
        padding: 2px 8px;
        font-size: 14px;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    th {
        background: #fbff00;
        color: #000;
        font-weight: bold;
        text-align: left;
    }
    tbody tr:nth-child(even) td { background: #d2e5f7; }
    tbody tr:nth-child(odd) td { background: #fff; }
    .col-rank { width: 42px; text-align: center; }
    .col-score { width: 70px; text-align: right; }
    .team-col { text-align: left; }
    .num { text-align: right; }
    .empty-cell { color: #666; font-style: italic; text-align: center; }
    .snapshot-set {
        margin-top: 14px;
        padding: 8px 10px;
        border: 2px solid #9ec5fe;
        border-radius: 8px;
        background: #eef6ff;
        font-size: 12px;
        color: #25364a;
    }
    .snapshot-set summary {
        cursor: pointer;
        font-weight: bold;
        font-size: 13px;
    }
    .snapshot-set table { margin-top: 8px; table-layout: auto; }
    .snapshot-set th, .snapshot-set td {
        border: 1px solid #bfd9ff;
        padding: 4px 6px;
        font-size: 12px;
        background: #fff;
    }
    .snapshot-set th { background: #ddecff; }
    .footer {
        margin-top: 10px;
        color: #666;
        font-size: 11px;
        text-align: center;
    }
    @media (max-width: 1100px) {
        .report-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lite-links { margin-left: 0; width: 100%; }
    }
    @media (max-width: 700px) {
        .report-grid { grid-template-columns: 1fr; }
        .lite-title { white-space: normal; }
    }
</style>
</head>
<body>
<div class="page-wrap">
    <div class="lite-banner">
        <span class="lite-title">Viewing Standings as of <?php echo stlite_h($asOfRaceText); ?> <?php echo stlite_h($versionLabel); ?> — <?php echo stlite_h($asOfDisplay); ?></span>
        <?php if ($versionLabel !== ''): ?><span class="lite-chip version"><?php echo stlite_h($versionLabel); ?></span><?php endif; ?>
        <?php if ($changeLabel !== ''): ?><span class="lite-chip change"><?php echo stlite_h($changeLabel); ?></span><?php endif; ?>
        <span class="lite-chip">Viewing <?php echo stlite_h($viewRaceText); ?></span>
        <span class="lite-links">
            <a class="lite-chip" href="<?php echo stlite_h($currentUrl); ?>">Current</a>
            <a class="lite-chip" href="<?php echo stlite_h($fullTimelineUrl); ?>">Full Timeline</a>
        </span>
    </div>

    <div class="lite-subline">
        Snapshot set: <?php echo stlite_h(stlite_compact_snapshot_value($selectedSnapshotValueText)); ?>
    </div>

    <div class="report-grid">
        <div class="report-panel">
            <div class="panel-title"><?php echo stlite_h((string)($selectedYear ?? '') . ' ' . $viewRaceText); ?> <span class="snapshot-footnote"><?php echo stlite_h($asOfDisplay); ?></span></div>
            <div class="table-wrap"><?php st_render_score_table($selectedWeeklyRows ?? [], 'weekly_total', 'Week ' . (string)($selectedViewRaceNumber ?? '')); ?></div>
        </div>

        <div class="report-panel">
            <div class="panel-title"><?php echo stlite_h((string)($selectedYear ?? '') . ' ' . st_segment_label((string)($selectedSegment ?? ''), (string)($selectedYear ?? ''))); ?></div>
            <div class="table-wrap"><?php st_render_score_table($segmentRows ?? [], 'total', (string)($selectedSegment ?? '')); ?></div>
        </div>

        <div class="report-panel">
            <div class="panel-title"><?php echo stlite_h((string)($selectedYear ?? '')); ?></div>
            <div class="table-wrap"><?php st_render_score_table($seasonRows ?? [], 'total', (string)($selectedYear ?? '')); ?></div>
        </div>

        <div class="report-panel">
            <div class="panel-title"><?php echo stlite_h((string)($selectedYear ?? '')); ?> Weekly Winners</div>
            <div class="table-wrap"><?php st_render_weekly_winners_table($weeklyWinnerRows ?? []); ?></div>
        </div>
    </div>

    <details class="snapshot-set">
        <summary>Snapshot Set Used</summary>
        <div class="table-wrap"><?php st_render_snapshot_set_table($snapshotSetRows ?? []); ?></div>
    </details>

    <div class="footer">standings_timeline_lite.php v001 · built from standings_timeline.php data · generated <?php echo stlite_h(date('n/j/Y g:i:s a')); ?></div>
</div>
</body>
</html>
PHP_LITE;

inst_write_file($baseDir . '/standings_timeline_lite.php', $liteContent, $stamp, $report, $errors);
$report[] = 'Created lite as-of page: standings_timeline_lite.php';

$weeklyPath = $baseDir . '/weekly_standings.php';
if (is_file($weeklyPath)) {
    $weekly = (string)@file_get_contents($weeklyPath);
    $original = $weekly;

    if (strpos($weekly, 'standings_timeline.php') !== false) {
        $weekly = str_replace('standings_timeline.php', 'standings_timeline_lite.php', $weekly);
        $report[] = 'Patched weekly As-of target to standings_timeline_lite.php';
    } else {
        $report[] = 'Weekly As-of target already appears to avoid standings_timeline.php, or target text was not found.';
    }

    if (strpos($weekly, 'VERSION: v059') !== false) {
        $weekly = str_replace('VERSION: v059', 'VERSION: v060', $weekly);
    }
    if (strpos($weekly, "const RRSG_SIGNATURE = 'WEEKLY_STANDINGS v059'") !== false) {
        $weekly = str_replace("const RRSG_SIGNATURE = 'WEEKLY_STANDINGS v059'", "const RRSG_SIGNATURE = 'WEEKLY_STANDINGS v060'", $weekly);
    }

    $changelogNeedle = " * CHANGELOG:\n *";
    if (strpos($weekly, 'v060 (6/25/2026 3:31:00 pm)') === false && strpos($weekly, $changelogNeedle) !== false) {
        $weekly = str_replace(
            $changelogNeedle,
            " * CHANGELOG:\n *\n * v060 (6/25/2026 3:31:00 pm)\n *   - CHANGE: As-of button now opens standings_timeline_lite.php for a public-friendly as-of view.\n *",
            $weekly
        );
        $report[] = 'Patched weekly changelog v060';
    }

    $weekly = preg_replace('/LAST MODIFIED:\s*[^\n]+/', 'LAST MODIFIED: 6/25/2026 3:31:00 pm', $weekly, 1) ?? $weekly;

    if ($weekly !== $original) {
        inst_write_file($weeklyPath, $weekly, $stamp, $report, $errors);
    } else {
        $report[] = 'No weekly_standings.php changes were needed.';
    }
} else {
    $errors[] = 'weekly_standings.php not found.';
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MRL Standings Timeline Lite Installer</title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; margin: 24px; line-height: 1.35; }
    .ok { color: #146c43; font-weight: bold; }
    .bad { color: #b02a37; font-weight: bold; }
    pre { background: #f6f8fa; border: 1px solid #d0d7de; padding: 12px; border-radius: 6px; white-space: pre-wrap; }
</style>
</head>
<body>
<h1>MRL Standings Timeline Lite Installer</h1>
<?php if (empty($errors)): ?>
    <p class="ok">SUCCESS — standings_timeline_lite.php was installed.</p>
<?php else: ?>
    <p class="bad">INSTALL COMPLETED WITH ERRORS.</p>
<?php endif; ?>
<h2>Report</h2>
<pre><?php echo inst_h(implode("\n", $report)); ?></pre>
<?php if (!empty($errors)): ?>
<h2>Errors</h2>
<pre><?php echo inst_h(implode("\n", $errors)); ?></pre>
<?php endif; ?>
<p>After a successful install, open a weekly standings As-of link, then delete this installer.</p>
</body>
</html>
