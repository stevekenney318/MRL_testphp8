<?php
declare(strict_types=1);

/**
 * install_revision_monitor_watching_tile_fix_v001_20260621_104217pm.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/21/2026 10:42:17 pm
 *
 * DESCRIPTION:
 * One-time installer for MRL dashboard tile cleanup.
 * Updates race_results_dashboard.php so the System Snapshot panel shows
 * the current Revision Monitor watching handoff instead of the latest
 * revision/classification snapshot pair.
 *
 * FILE PLACEMENT:
 * - Upload to /race_results/
 * - Run once in browser
 * - Delete after successful run
 *
 * INSTALLS:
 * - race_results_dashboard.php v021
 *
 * CHANGELOG:
 * v001 (6/21/2026 10:42:17 pm)
 * - FIX: Replaces Last Revision Snapshot System Snapshot tile with Revision Monitor Watching tile.
 * - FIX: Uses revision monitor handoff/final snapshot race metadata so R17 San Diego appears after handoff.
 * - FIX: Removes temporary broken dashboard tile references from the prior quick patch.
 * - NEW: Creates backup before writing patched dashboard file.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

$baseDir = __DIR__;
$targetFile = $baseDir . '/race_results_dashboard.php';
$saveDir = $baseDir . '/save';
$stamp = date('Ymd_His');

function installer_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function installer_fail(string $message): void
{
    installer_page('ERROR', [$message], false);
    exit;
}

function installer_page(string $title, array $lines, bool $ok): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>MRL Revision Monitor Tile Installer</title>';
    echo '<style>body{font-family:Arial,Helvetica,sans-serif;background:#151515;color:#f1f1f1;padding:22px}.card{border:1px solid #5a5142;border-radius:16px;padding:18px;max-width:980px;background:#222}h1{color:#f2c98e}.ok{color:#55d77d}.bad{color:#ff7474}li{margin:6px 0}code{background:#111;padding:2px 5px;border-radius:5px}</style>';
    echo '</head><body><div class="card">';
    echo '<h1>MRL Revision Monitor Tile Installer</h1>';
    echo '<h2 class="' . ($ok ? 'ok' : 'bad') . '">' . installer_h($title) . '</h2>';
    echo '<ul>';
    foreach ($lines as $line) {
        echo '<li>' . installer_h($line) . '</li>';
    }
    echo '</ul>';
    if ($ok) {
        echo '<p class="ok"><strong>Ready.</strong> Refresh race_results_dashboard.php to confirm the System Snapshot tile.</p>';
    }
    echo '</div></body></html>';
}

if (!is_file($targetFile)) {
    installer_fail('race_results_dashboard.php was not found in ' . $baseDir);
}

$source = @file_get_contents($targetFile);
if (!is_string($source) || $source === '') {
    installer_fail('Unable to read race_results_dashboard.php.');
}

$original = $source;
$messages = [];

if (!is_dir($saveDir)) {
    @mkdir($saveDir, 0755, true);
}
$backupDir = is_dir($saveDir) ? $saveDir : $baseDir;
$backupFile = $backupDir . '/race_results_dashboard_before_revision_watching_tile_' . $stamp . '.php';
if (!@copy($targetFile, $backupFile)) {
    installer_fail('Unable to create backup: ' . $backupFile);
}
$messages[] = 'Backup created: ' . $backupFile;

// Header/version update.
$source = preg_replace('/VERSION:\s*v\d+/', 'VERSION: v021', $source, 1);
$source = preg_replace('/LAST MODIFIED:\s*[^\r\n]+/', 'LAST MODIFIED: 6/21/2026 10:42:17 pm', $source, 1);
$source = preg_replace("/const RACE_RESULTS_DASHBOARD_VERSION = 'v\\d+';/", "const RACE_RESULTS_DASHBOARD_VERSION = 'v021';", $source, 1);

if (strpos($source, 'v021 (6/21/2026)') === false) {
    $source = str_replace(
        " * CHANGELOG:\n *\n",
        " * CHANGELOG:\n *\n * v021 (6/21/2026)\n *   - CHANGE: System Snapshot now shows Revision Monitor Watching handoff status instead of latest revision/classification snapshot pair.\n *   - FIX: Confirms the most recently completed race is under revision-monitor watch after FINAL email handoff.\n *\n",
        $source
    );
}
$messages[] = 'Updated dashboard header to v021.';

// Add helper function if missing.
if (strpos($source, 'function rr_dash_revision_watching_summary(') === false) {
    $helperFunction = <<<'PHPHELPER'

function rr_dash_revision_watching_summary(string $baseDir, int $year, array $revisionSchedule): array
{
    $handoff = isset($revisionSchedule['handoff']) && is_array($revisionSchedule['handoff']) ? $revisionSchedule['handoff'] : [];

    $raceId = trim((string)($handoff['race_id'] ?? ''));
    $raceName = trim((string)($handoff['race_name'] ?? ''));
    $anchorAt = trim((string)($handoff['anchor_at'] ?? ''));
    if ($anchorAt === '') $anchorAt = trim((string)($handoff['final_checked_at'] ?? ''));

    $raceCode = '';
    $displayName = $raceName !== '' ? rr_dash_simplify_race_name($raceName) : '';

    $yearDir = rtrim($baseDir, '/\\') . '/' . (string)$year;
    if ($raceId !== '' && is_dir($yearDir)) {
        $folders = glob($yearDir . '/R*_*');
        if (is_array($folders)) {
            foreach ($folders as $folder) {
                if (!is_dir($folder)) continue;
                $metaPath = rtrim($folder, '/\\') . '/_meta.json';
                $meta = rr_dash_load_json_file($metaPath);
                $metaRaceId = trim((string)($meta['race_id'] ?? ''));
                if ($metaRaceId !== $raceId) continue;

                $baseName = basename($folder);
                if (preg_match('/^(R\d+)/i', $baseName, $m)) {
                    $raceCode = strtoupper($m[1]);
                }

                if ($displayName === '') {
                    $metaRaceName = trim((string)($meta['race_name'] ?? ''));
                    $displayName = $metaRaceName !== '' ? rr_dash_simplify_race_name($metaRaceName) : '';
                }
                break;
            }
        }
    }

    if ($raceCode === '' && preg_match('/\bR(\d{1,2})\b/i', $raceName, $m)) {
        $raceCode = 'R' . str_pad((string)((int)$m[1]), 2, '0', STR_PAD_LEFT);
    }

    $raceText = trim($raceCode . ' ' . $displayName);
    if ($raceText === '') {
        $raceText = 'Latest completed race';
    }

    $timeText = 'handoff time unknown';
    if ($anchorAt !== '') {
        try {
            $dt = new DateTimeImmutable($anchorAt, new DateTimeZone('America/New_York'));
            $timeText = $dt->format('n/j/Y g:i A');
        } catch (Exception $e) {
            $display = rr_dash_display_datetime($anchorAt);
            $timeText = $display !== '' ? $display : $anchorAt;
        }
    }

    return [
        'summary' => 'All completed races including',
        'race' => $raceText,
        'time' => $timeText,
    ];
}
PHPHELPER;

    $marker = 'function rr_dash_trusted_revision_classification_summary(string $summaryFile, string $lastRunFile, int $year): array';
    $pos = strpos($source, $marker);
    if ($pos === false) {
        installer_fail('Could not find insertion point for rr_dash_revision_watching_summary(). Backup was created but no changes were written.');
    }
    $source = substr($source, 0, $pos) . $helperFunction . "\n" . substr($source, $pos);
    $messages[] = 'Added rr_dash_revision_watching_summary() helper.';
} else {
    $messages[] = 'Revision watching helper already present.';
}

// Add dashboard variable if missing.
if (strpos($source, '$dashRevisionWatching = rr_dash_revision_watching_summary(') === false) {
    $needle = '$dashLastSnapshot = rr_dash_last_revision_snapshot(isset($classSummary[\'rows\']) && is_array($classSummary[\'rows\']) ? $classSummary[\'rows\'] : []);';
    $replacement = $needle . "\n" . '$dashRevisionWatching = rr_dash_revision_watching_summary($baseDir, (int)$classYear, $dashRevisionSchedule);';
    if (strpos($source, $needle) === false) {
        installer_fail('Could not find $dashLastSnapshot assignment for inserting $dashRevisionWatching. Backup was created but no changes were written.');
    }
    $source = str_replace($needle, $replacement, $source);
    $messages[] = 'Added $dashRevisionWatching dashboard data.';
} else {
    $messages[] = '$dashRevisionWatching dashboard data already present.';
}

// Remove accidental broken quick-patch references if they exist.
$source = str_replace('$dashLatestSnapshot = rr_dash_last_snapshot_captured($yearDir);' . "\n", '', $source);

// Replace the System Snapshot tile between its label and Next Revision tile.
$labelCandidates = [
    '<div class="snapshot-label">Last Revision Snapshot</div>',
    '<div class="snapshot-label">Revision Monitor Watching</div>',
];
$labelPos = false;
foreach ($labelCandidates as $candidate) {
    $labelPos = strpos($source, $candidate);
    if ($labelPos !== false) break;
}
if ($labelPos === false) {
    installer_fail('Could not find Last Revision Snapshot / Revision Monitor Watching tile label. Backup was created but no changes were written.');
}

$tileStart = strrpos(substr($source, 0, $labelPos), '<div class="snapshot-tile');
$nextTile = strpos($source, '<div class="snapshot-tile">' . "\n" . '            <div class="snapshot-label">Next Revision</div>', $labelPos);
if ($tileStart === false || $nextTile === false || $nextTile <= $tileStart) {
    installer_fail('Could not isolate the System Snapshot revision tile. Backup was created but no changes were written.');
}

$newTile = <<<'PHPTILE'
<div class="snapshot-tile">
            <div class="snapshot-label">Revision Monitor Watching</div>
            <div class="snapshot-main"><?php echo sd_html((string)$dashRevisionWatching['summary']); ?></div>
            <div class="snapshot-detail"><?php echo sd_html((string)$dashRevisionWatching['race']); ?></div>
            <div class="snapshot-impact-line"><span class="badge ok"><?php echo sd_html((string)$dashRevisionWatching['time']); ?></span></div>
        </div>
        
PHPTILE;

$source = substr($source, 0, $tileStart) . $newTile . substr($source, $nextTile);
$messages[] = 'Replaced System Snapshot tile with Revision Monitor Watching tile.';

if ($source === $original) {
    installer_fail('No changes were produced. Backup was created but dashboard was not modified.');
}

$tmpFile = $targetFile . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
if (@file_put_contents($tmpFile, $source, LOCK_EX) === false) {
    installer_fail('Unable to write temporary dashboard file.');
}
if (!@rename($tmpFile, $targetFile)) {
    @unlink($tmpFile);
    installer_fail('Unable to replace race_results_dashboard.php.');
}

$messages[] = 'Patched race_results_dashboard.php to v021.';
$messages[] = 'Delete this installer after verifying the dashboard.';
installer_page('Done', $messages, true);
