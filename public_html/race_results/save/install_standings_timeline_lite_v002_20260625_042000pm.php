<?php
/**
 * install_standings_timeline_lite_v002_20260625_042000pm.php
 *
 * VERSION: v002
 * LAST MODIFIED: 6/25/2026 4:20:00 pm
 *
 * CHANGELOG:
 * v002 (6/25/2026 4:20:00 pm)
 *   - CHANGE: Replaces standings_timeline_lite.php with a simpler locked as-of browser.
 *   - CHANGE: Keeps the selected as-of snapshot fixed while allowing race dropdown / << / >> navigation among races that existed at that snapshot time.
 *   - CHANGE: Removes Full Timeline / Current action pills from the lite page top row.
 *   - CHANGE: Uses a weekly_standings-style top row and four-table layout.
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

function inst2_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function inst2_backup_file(string $path, string $stamp, array &$report): bool
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

function inst2_write_file(string $path, string $content, string $stamp, array &$report, array &$errors): void
{
    if (is_file($path)) {
        if (!inst2_backup_file($path, $stamp, $report)) {
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
 * VERSION: v002
 * LAST MODIFIED: 6/25/2026 4:20:00 pm
 *
 * CHANGELOG:
 * v002 (6/25/2026 4:20:00 pm)
 *   - CHANGE: Locked as-of snapshot remains fixed while the user navigates among race pages that existed at that point in time.
 *   - CHANGE: Top row now stays simple: disabled Live, year, race selector, arrows, and the as-of ID banner.
 *   - CHANGE: Removed Current and Full Timeline action pills from the lite page.
 *   - CHANGE: Weekly Winners table now uses Week / Winner / Points to match weekly_standings.php.
 *
 * v001 (6/25/2026 3:31:00 pm)
 *   - NEW: Public-friendly lite as-of standings page.
 *   - NEW: Reuses standings_timeline.php data-building logic, then renders a weekly_standings-like four-table view.
 *   - NEW: Accepts the same year/snapshot/race query values as standings_timeline.php and also accepts release= as a snapshot alias.
 *
 * Purpose:
 *   Show what weekly standings looked like at one locked as-of snapshot, while allowing race-to-race navigation within that as-of world.
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
    $compact = preg_replace('/^(\d{8}_\d{6}).*_(R\d{2})$/', '$1_$2', $value);
    return is_string($compact) && $compact !== '' ? $compact : $value;
}

function stlite_url(array $params): string
{
    $query = array_merge($_GET, $params);
    foreach ($query as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
        }
    }
    return 'standings_timeline_lite.php' . (empty($query) ? '' : '?' . http_build_query($query));
}

function stlite_render_score_table(array $rows, string $scoreKey, string $scoreLabel): void
{
    echo '<table>';
    echo '<thead><tr><th class="col-rank">#</th><th class="team-col">Team</th><th class="col-score">' . stlite_h($scoreLabel) . '</th></tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="3" class="empty-cell">No rows available.</td></tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td class="col-rank">' . stlite_h((string)($row['rank'] ?? '')) . '</td>';
            echo '<td class="team-col">' . stlite_h((string)($row['team_name'] ?? '')) . '</td>';
            echo '<td class="col-score">' . stlite_h((string)(int)($row[$scoreKey] ?? 0)) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
}

function stlite_render_weekly_winners_table(array $rows): void
{
    echo '<table>';
    echo '<thead><tr><th class="col-week">Week</th><th class="team-col">Winner</th><th class="col-score">Points</th></tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="3" class="empty-cell">No weekly winners available.</td></tr>';
    } else {
        foreach ($rows as $row) {
            $raceCode = (string)($row['race_code'] ?? '');
            $week = preg_match('/^R(\d+)$/', $raceCode, $m) ? (string)((int)$m[1]) : $raceCode;
            echo '<tr>';
            echo '<td class="col-week">' . stlite_h($week) . '</td>';
            echo '<td class="team-col">' . stlite_h((string)($row['team_name'] ?? '')) . '</td>';
            echo '<td class="col-score">' . stlite_h((string)(int)($row['points'] ?? 0)) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
}

function stlite_print_button_disabled_attr(): string
{
    return '';
}

$asOfRaceText = trim((string)($asOfRaceCode ?? '') . ' ' . (string)($asOfRaceLabel ?? ''));
$viewRaceText = trim((string)($selectedViewRaceCode ?? '') . ' ' . (string)($selectedViewRaceLabel ?? ''));
$versionLabel = (string)($selectedSnapshot['version_label'] ?? '');
$asOfDisplay = (string)($selectedSnapshotDisplay ?? '');
$selectedSnapshotValueText = (string)($selectedSnapshotValue ?? '');
$topBannerText = 'Viewing Standings as of ' . trim($asOfRaceText . ' ' . $versionLabel) . ' — ' . $asOfDisplay;

$yearDisplay = (string)($selectedYear ?? '');
$selectedViewRaceCodeText = (string)($selectedViewRaceCode ?? '');
$selectedViewRaceNumberText = (string)($selectedViewRaceNumber ?? '');
$selectedSegmentText = (string)($selectedSegment ?? '');
$selectedViewSnapshotDisplay = '';
if (isset($snapshotByRaceNumber) && is_array($snapshotByRaceNumber) && isset($snapshotByRaceNumber[(int)($selectedViewRaceNumber ?? 0)])) {
    $selectedViewSnapshotDisplay = st_snapshot_display(st_snapshot_key_from_file((string)$snapshotByRaceNumber[(int)$selectedViewRaceNumber]));
}
if ($selectedViewSnapshotDisplay === '') {
    $selectedViewSnapshotDisplay = $asOfDisplay;
}

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
        max-width: 1750px;
        margin: 0 auto;
    }
    .top-controls {
        display: flex;
        flex-wrap: nowrap;
        align-items: center;
        gap: 8px;
        margin-bottom: 64px;
    }
    .top-controls select,
    .top-controls button {
        font: inherit;
        padding: 1px 8px;
    }
    .top-controls button {
        cursor: pointer;
    }
    .live-btn {
        min-width: 66px;
        font-weight: bold;
        border-radius: 18px;
        background: #eef5fb;
        color: #9ba9b6;
        border: 3px solid #d7e3ed;
        cursor: default;
    }
    .year-select { width: 92px; }
    .race-select { min-width: 210px; }
    .nav-button {
        min-width: 34px;
        text-align: center;
        padding-left: 6px;
        padding-right: 6px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #111;
        background: #f2f2f2;
        border: 2px solid #999;
        border-radius: 2px;
        height: 30px;
        box-sizing: border-box;
    }
    .nav-button.disabled {
        opacity: 0.45;
        pointer-events: none;
        color: #999;
        background: #f3f3f3;
        border-color: #ccc;
    }
    .asof-banner {
        display: inline-flex;
        align-items: center;
        min-height: 30px;
        padding: 2px 12px;
        background: #241f32;
        color: #fff;
        border: 1px solid #55466e;
        font-weight: 900;
        font-size: 19px;
        line-height: 1.15;
        white-space: nowrap;
        box-shadow: 0 1px 4px rgba(0,0,0,0.22);
    }
    .top-actions {
        margin-left: auto;
        display: inline-flex;
        gap: 12px;
    }
    .report-action-btn {
        min-width: 112px;
        border: 2px solid #777;
        border-radius: 3px;
        background: #f2f2f2;
        color: #111;
    }
    .report-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        align-items: start;
    }
    .report-panel { min-width: 0; }
    .panel-title {
        font-size: 20px;
        margin: 0 0 4px 0;
        font-weight: normal;
    }
    .snapshot-footnote {
        margin-left: 5px;
        color: #667;
        font-size: 13px;
        font-style: italic;
        white-space: nowrap;
    }
    .table-wrap { overflow-x: auto; }
    table {
        border-collapse: collapse;
        width: 100%;
        table-layout: fixed;
        background: #fff;
    }
    th, td {
        border: 2px solid #151313;
        padding: 2px 8px;
        font-size: 20px;
        line-height: 1.15;
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
    .col-rank,
    .col-week { width: 56px; text-align: center; }
    .col-score { width: 72px; text-align: right; }
    .team-col { text-align: left; }
    .empty-cell { color: #666; font-style: italic; text-align: center; }
    .asof-id {
        margin-top: 10px;
        font-size: 11px;
        color: #777;
        text-align: center;
    }
    .footer {
        margin-top: 26px;
        color: #666;
        font-size: 15px;
        text-align: center;
    }
    @media (max-width: 1200px) {
        .top-controls { flex-wrap: wrap; margin-bottom: 24px; }
        .top-actions { margin-left: 0; }
        .report-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .asof-banner { white-space: normal; }
    }
    @media (max-width: 700px) {
        .report-grid { grid-template-columns: 1fr; }
        th, td { font-size: 16px; }
    }
    @media print {
        .top-controls { display: none; }
        body { margin: 8px; }
        .page-wrap { max-width: none; }
    }
</style>
</head>
<body>
<div class="page-wrap">
    <form method="get" class="top-controls" id="timelineLiteControls">
        <button type="button" class="live-btn" disabled>Live</button>

        <input type="hidden" name="year" value="<?php echo stlite_h($yearDisplay); ?>">
        <input type="hidden" name="snapshot" value="<?php echo stlite_h($selectedSnapshotValueText); ?>">

        <select class="year-select" aria-label="Year" disabled>
            <option selected><?php echo stlite_h($yearDisplay); ?></option>
        </select>

        <select name="race" class="race-select" aria-label="Race" onchange="this.form.submit();">
            <?php foreach (($availableTimelineRaces ?? []) as $raceOption): ?>
                <?php
                $raceCodeOpt = (string)($raceOption['race_code'] ?? '');
                $raceLabelOpt = trim($raceCodeOpt . ' ' . st_short_race_label((string)($raceOption['race_name'] ?? '')));
                ?>
                <option value="<?php echo stlite_h($raceCodeOpt); ?>" <?php echo ($raceCodeOpt === $selectedViewRaceCodeText ? 'selected' : ''); ?>><?php echo stlite_h($raceLabelOpt); ?></option>
            <?php endforeach; ?>
        </select>

        <a class="nav-button <?php echo ((string)($previousRaceCode ?? '') === '' ? 'disabled' : ''); ?>" href="<?php echo stlite_h((string)($previousRaceCode ?? '') !== '' ? stlite_url(['race' => (string)$previousRaceCode]) : '#'); ?>">&lt;&lt;</a>
        <a class="nav-button <?php echo ((string)($nextRaceCode ?? '') === '' ? 'disabled' : ''); ?>" href="<?php echo stlite_h((string)($nextRaceCode ?? '') !== '' ? stlite_url(['race' => (string)$nextRaceCode]) : '#'); ?>">&gt;&gt;</a>

        <div class="asof-banner"><?php echo stlite_h($topBannerText); ?></div>

        <div class="top-actions">
            <button type="button" class="report-action-btn" onclick="window.print();">Print</button>
        </div>
    </form>

    <div class="report-grid">
        <div class="report-panel">
            <div class="panel-title"><?php echo stlite_h($yearDisplay . ' ' . $viewRaceText); ?> <span class="snapshot-footnote"><?php echo stlite_h($selectedViewSnapshotDisplay); ?></span></div>
            <div class="table-wrap"><?php stlite_render_score_table($selectedWeeklyRows ?? [], 'weekly_total', 'Week ' . $selectedViewRaceNumberText); ?></div>
        </div>

        <div class="report-panel">
            <div class="panel-title"><?php echo stlite_h($yearDisplay . ' ' . $selectedSegmentText); ?></div>
            <div class="table-wrap"><?php stlite_render_score_table($segmentRows ?? [], 'total', $selectedSegmentText); ?></div>
        </div>

        <div class="report-panel">
            <div class="panel-title"><?php echo stlite_h($yearDisplay); ?></div>
            <div class="table-wrap"><?php stlite_render_score_table($seasonRows ?? [], 'total', $yearDisplay); ?></div>
        </div>

        <div class="report-panel">
            <div class="panel-title"><?php echo stlite_h($yearDisplay); ?> Weekly Winners</div>
            <div class="table-wrap"><?php stlite_render_weekly_winners_table($weeklyWinnerRows ?? []); ?></div>
        </div>
    </div>

    <div class="asof-id">Snapshot set: <?php echo stlite_h(stlite_compact_snapshot_value($selectedSnapshotValueText)); ?></div>
    <div class="footer">Copyright © 2017-<?php echo stlite_h($yearDisplay); ?> Manlius Racing League<br>All rights reserved.</div>
</div>
</body>
</html>
PHP_LITE;

inst2_write_file($baseDir . '/standings_timeline_lite.php', $liteContent, $stamp, $report, $errors);
$report[] = 'Installed standings_timeline_lite.php v002 locked as-of race browser.';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MRL Standings Timeline Lite v002 Installer</title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; margin: 24px; line-height: 1.35; }
    .ok { color: #146c43; font-weight: bold; }
    .bad { color: #b02a37; font-weight: bold; }
    pre { background: #f6f8fa; border: 1px solid #d0d7de; padding: 12px; border-radius: 6px; white-space: pre-wrap; }
</style>
</head>
<body>
<h1>MRL Standings Timeline Lite v002 Installer</h1>
<?php if (empty($errors)): ?>
    <p class="ok">SUCCESS — standings_timeline_lite.php v002 was installed.</p>
<?php else: ?>
    <p class="bad">INSTALL COMPLETED WITH ERRORS.</p>
<?php endif; ?>
<h2>Report</h2>
<pre><?php echo inst2_h(implode("\n", $report)); ?></pre>
<?php if (!empty($errors)): ?>
<h2>Errors</h2>
<pre><?php echo inst2_h(implode("\n", $errors)); ?></pre>
<?php endif; ?>
<p>After a successful install, open a weekly standings As-of link, test race navigation, then delete this installer.</p>
</body>
</html>
