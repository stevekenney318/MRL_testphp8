<?php
declare(strict_types=1);

/**
 * weekly_standings_release_history_builder.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/21/2026 3:19:00 pm
 *
 * CHANGELOG:
 * v001 (6/21/2026 3:19:00 pm)
 *   - CHANGE: Manual builder now uses weekly_standings_release_history_helper.php for shared rebuild/write logic.
 *   - PURPOSE: Keeps the manual fallback aligned with race monitor and revision monitor automatic writes.
 *
 * v001 (6/21/2026 8:30:12 am)
 *   - CHANGE: Reworked JSON writing to use atomic temp-file replacement, one-file backup, cache-busting headers, and read-back verification.
 *
 * v001 (6/21/2026 6:53:46 am)
 *   - CHANGE: Builds one release-history record for every legitimate saved snapshot, not just the current/latest snapshot.
 *
 * v001 (6/21/2026)
 *   - NEW: Builds a yearly weekly standings release metadata history file.
 *
 * Purpose:
 *   Manual fallback/rebuild tool for weekly standings release metadata.
 *   This builder does not fetch remote pages. It scans local MRL artifacts only.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions_mrl.php';
require_once __DIR__ . '/weekly_standings_release_history_helper.php';

date_default_timezone_set('America/New_York');

function wsrhb_h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$year = isset($_GET['year']) ? preg_replace('/[^0-9]/', '', (string)$_GET['year']) : '2026';
if ($year === '') {
    $year = '2026';
}

$write = (isset($_GET['write']) && (string)$_GET['write'] === '1');
$baseDir = __DIR__;
$outputPath = wsrel_history_path($baseDir, $year);
$history = wsrel_build_history_from_artifacts($baseDir, $year);
$releases = isset($history['releases']) && is_array($history['releases']) ? $history['releases'] : [];

$writeResult = [
    'ok' => false,
    'message' => 'Preview only.',
    'path' => $outputPath,
    'tmp_path' => '',
    'backup_path' => '',
    'bytes_written' => 0,
    'bytes_verified' => 0,
    'sha256' => '',
    'mtime_display' => '',
    'size_label' => '',
];

if ($write) {
    $writeResult = wsrel_write_json_atomic($outputPath, $history);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Weekly Standings Release History Builder</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 18px; color: #222; }
        .ok { color: #0f6b2f; font-weight: bold; }
        .warn { color: #9a6700; font-weight: bold; }
        table { border-collapse: collapse; width: 100%; margin-top: 12px; font-size: 13px; }
        th, td { border: 1px solid #bbb; padding: 5px 7px; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; }
        code { background: #f7f7f7; padding: 1px 4px; }
        .actions { margin: 12px 0; }
        a.button { display: inline-block; padding: 6px 10px; border: 1px solid #888; border-radius: 5px; text-decoration: none; background: #f7f7f7; color: #111; }
        .muted { color: #666; font-size: 12px; }
        .writebox { margin: 10px 0; padding: 10px; border: 1px solid #c9c9c9; background: #f9f9f9; }
        .writebox p { margin: 4px 0; }
        .yes { color: #b42318; font-weight: bold; }
        .no { color: #0f6b2f; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Weekly Standings Release History Builder</h1>
    <p><strong>Year:</strong> <?php echo wsrhb_h($year); ?></p>
    <p><strong>Output:</strong> <code><?php echo wsrhb_h($outputPath); ?></code></p>
    <p><strong>Mode:</strong> <?php echo $write ? '<span class="warn">WRITE</span>' : 'Preview only'; ?></p>
    <p><strong>Shared helper:</strong> <code>weekly_standings_release_history_helper.php</code></p>
    <?php if ($write): ?>
        <div class="writebox">
            <p class="<?php echo $writeResult['ok'] ? 'ok' : 'warn'; ?>"><?php echo wsrhb_h((string)$writeResult['message']); ?></p>
            <?php if ($writeResult['ok']): ?>
                <p><strong>Verified file modified:</strong> <?php echo wsrhb_h((string)$writeResult['mtime_display']); ?></p>
                <p><strong>Verified bytes:</strong> <?php echo (int)$writeResult['bytes_verified']; ?> <?php echo wsrhb_h((string)($writeResult['size_label'] ?? '')); ?></p>
                <p><strong>SHA-256:</strong> <code><?php echo wsrhb_h((string)$writeResult['sha256']); ?></code></p>
                <p><strong>Backup:</strong> <code><?php echo wsrhb_h((string)$writeResult['backup_path']); ?></code></p>
                <p><a class="button" href="<?php echo wsrhb_h($year . '/_weekly_standings_release_history.json?v=' . time()); ?>" target="_blank" rel="noopener">Open written JSON no-cache</a></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="actions">
        <a class="button" href="?year=<?php echo rawurlencode($year); ?>&write=1&cb=<?php echo time(); ?>">Write history file</a>
        <a class="button" href="?year=<?php echo rawurlencode($year); ?>&cb=<?php echo time(); ?>">Preview</a>
    </div>
    <p class="muted">Write mode uses atomic replacement, read-back verification, a single previous-file backup, and no-cache headers to reduce Hostinger/browser cache confusion.</p>
    <h2>Release Records: <?php echo count($releases); ?></h2>
    <p class="muted">
        Races with snapshots: <?php echo (int)($history['race_history_count'] ?? 0); ?> ·
        Updated records: <?php echo (int)($history['updated_release_count'] ?? 0); ?> ·
        Pending records: <?php echo (int)($history['pending_release_count'] ?? 0); ?> ·
        Matched pair history rows: <?php echo (int)($history['pair_history_matched_count'] ?? 0); ?>
    </p>
    <table>
        <thead>
            <tr>
                <th>Race</th>
                <th>Release</th>
                <th>Released</th>
                <th>Status</th>
                <th>MRL Impact</th>
                <th>Change</th>
                <th>Supersedes</th>
                <th>Release ID</th>
                <th>Snapshot</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($releases as $row): ?>
                <?php
                    if (!is_array($row)) continue;
                    $impact = $row['mrl_impact'] ?? null;
                    $impactText = ($impact === null) ? '—' : (!empty($impact) ? 'YES' : 'NO');
                    $impactClass = ($impact === null) ? '' : (!empty($impact) ? 'yes' : 'no');
                    $sup = $row['supersedes'] ?? null;
                    $supText = '—';
                    if (is_array($sup)) {
                        $supText = (string)($sup['released_at_display'] ?? $sup['release_id'] ?? 'Prior release');
                    }
                ?>
                <tr>
                    <td><?php echo wsrhb_h((string)$row['race_code'] . ' ' . (string)$row['short_name']); ?></td>
                    <td><?php echo wsrhb_h((string)$row['release_type']); ?></td>
                    <td><?php echo wsrhb_h((string)$row['released_at_display']); ?></td>
                    <td><?php echo wsrhb_h((string)$row['public_status']); ?></td>
                    <td class="<?php echo wsrhb_h($impactClass); ?>"><?php echo wsrhb_h($impactText); ?></td>
                    <td><?php echo wsrhb_h((string)(($row['change_status_label'] ?? '') ?: '—')); ?></td>
                    <td><?php echo wsrhb_h($supText); ?></td>
                    <td><code><?php echo wsrhb_h((string)$row['release_id']); ?></code></td>
                    <td><?php echo wsrhb_h((string)$row['snapshot_file']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
