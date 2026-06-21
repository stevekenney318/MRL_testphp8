<?php
declare(strict_types=1);

/**
 * weekly_standings_release_history_builder.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/21/2026 8:30:12 am
 *
 * CHANGELOG:
 * v001 (6/21/2026 8:30:12 am)
 *   - CHANGE: Reworked JSON writing to use atomic temp-file replacement, one-file backup, cache-busting headers, and read-back verification.
 *   - CHANGE: Write page now reports bytes, SHA-256 hash, file modified time, and direct no-cache JSON link after writing.
 *
 * v001 (6/21/2026 6:53:46 am)
 *   - CHANGE: Builds one release-history record for every legitimate saved snapshot, not just the current/latest snapshot.
 *   - CHANGE: Updated snapshot records now include supersedes links, source snapshot pairs, MRL impact, change labels, and driver-change detail for audit/debug use.
 *   - CHANGE: Keeps public wording simple while storing richer metadata for future timeline/debug views.
 *
 * v001 (6/21/2026)
 *   - NEW: Builds a yearly weekly standings release metadata history file.
 *   - NEW: Bootstraps current initial-release records from existing saved race result snapshots.
 *   - NEW: Writes 2026/_weekly_standings_release_history.json only when ?write=1 is supplied.
 *
 * Purpose:
 *   Provide the metadata source used by weekly_standings.php Audit panels.
 *   This builder does not fetch ESPN or call remote pages. It scans local MRL artifacts only.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions_mrl.php';

date_default_timezone_set('America/New_York');

function wsrh_h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wsrh_load_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function wsrh_write_json_atomic(string $path, array $data): array
{
    $result = [
        'ok' => false,
        'message' => '',
        'path' => $path,
        'tmp_path' => '',
        'backup_path' => '',
        'bytes_written' => 0,
        'bytes_verified' => 0,
        'sha256' => '',
        'mtime_display' => '',
    ];

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $result['message'] = 'JSON encode failed.';
        return $result;
    }

    $json .= "\n";
    $dir = dirname($path);
    if (!is_dir($dir)) {
        $result['message'] = 'Output directory does not exist: ' . $dir;
        return $result;
    }
    if (!is_writable($dir)) {
        $result['message'] = 'Output directory is not writable: ' . $dir;
        return $result;
    }

    $tmpPath = $path . '.tmp.' . getmypid() . '.' . date('Ymd_His');
    $backupPath = $dir . '/_weekly_standings_release_history_previous.json';
    $result['tmp_path'] = $tmpPath;
    $result['backup_path'] = $backupPath;

    if (is_file($path)) {
        @copy($path, $backupPath);
    }

    $bytes = file_put_contents($tmpPath, $json, LOCK_EX);
    if ($bytes === false) {
        $result['message'] = 'Failed writing temporary file: ' . $tmpPath;
        return $result;
    }

    @chmod($tmpPath, 0644);

    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        $result['message'] = 'Failed replacing output file with temporary file.';
        return $result;
    }

    @chmod($path, 0644);
    clearstatcache(true, $path);

    $verify = file_get_contents($path);
    if ($verify === false) {
        $result['message'] = 'Output file was replaced, but read-back verification failed.';
        return $result;
    }

    $expectedHash = hash('sha256', $json);
    $actualHash = hash('sha256', $verify);
    if ($expectedHash !== $actualHash) {
        $result['message'] = 'Output file was replaced, but SHA-256 verification did not match.';
        $result['sha256'] = $actualHash;
        return $result;
    }

    $mtime = @filemtime($path);

    $result['ok'] = true;
    $result['message'] = 'History file written and verified.';
    $result['bytes_written'] = (int)$bytes;
    $result['bytes_verified'] = strlen($verify);
    $result['sha256'] = $actualHash;
    $result['mtime_display'] = $mtime ? date('n/j/Y g:i:s a', $mtime) : '';

    return $result;
}

function wsrh_short_race_label(string $raceName): string
{
    $map = [
        'NASCAR Cup Series at Circuit of the Americas' => 'COTA',
        'NASCAR Cup Series at World Wide Technology Raceway' => 'World Wide Tech',
        'NASCAR Cup Series at Charlotte Motor Speedway' => 'Charlotte',
        'NASCAR Cup Series at Las Vegas' => 'Las Vegas',
        'NASCAR Cup Series at Phoenix' => 'Phoenix',
        'NASCAR Cup Series at Atlanta' => 'Atlanta',
        'NASCAR Cup Series at Darlington' => 'Darlington',
        'NASCAR Cup Series at Martinsville' => 'Martinsville',
        'NASCAR Cup Series at Bristol' => 'Bristol',
        'NASCAR Cup Series at Kansas' => 'Kansas',
        'NASCAR Cup Series at Talladega' => 'Talladega',
        'NASCAR Cup Series at Texas' => 'Texas',
        'NASCAR Cup Series at Watkins Glen' => 'Watkins Glen',
        'NASCAR Cup Series at Charlotte' => 'Charlotte',
        'NASCAR Cup Series at Nashville' => 'Nashville',
        'NASCAR Cup Series at Michigan' => 'Michigan',
        'NASCAR Cup Series at Pocono' => 'Pocono',
    ];

    return $map[$raceName] ?? preg_replace('/^NASCAR Cup Series at\s+/i', '', $raceName) ?: $raceName;
}

function wsrh_snapshot_files(string $raceFolder): array
{
    $files = glob(rtrim($raceFolder, '/\\') . '/snapshot_*.html');
    if (!is_array($files)) {
        return [];
    }

    sort($files, SORT_STRING);
    return array_values($files);
}

function wsrh_snapshot_id(string $snapshotFile): string
{
    $base = basename($snapshotFile);
    if (!preg_match('/^snapshot_(\d{8}_\d{6}\d*)\.html$/', $base, $m)) {
        return '';
    }

    return (string)$m[1];
}

function wsrh_snapshot_raw_datetime(string $snapshotId): string
{
    if (!preg_match('/^(\d{8})_(\d{6})/', $snapshotId, $m)) {
        return '';
    }

    $dt = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], new DateTimeZone('America/New_York'));
    return $dt instanceof DateTime ? $dt->format('Y-m-d H:i:s') : '';
}

function wsrh_public_datetime(string $raw): string
{
    if ($raw === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('America/New_York'));
    return $dt instanceof DateTime ? $dt->format('M j, Y g:i A') : $raw;
}

function wsrh_segment_from_race_number(int $raceNumber): string
{
    if ($raceNumber >= 1 && $raceNumber <= 8) return 'S1';
    if ($raceNumber >= 9 && $raceNumber <= 17) return 'S2';
    if ($raceNumber >= 18 && $raceNumber <= 26) return 'S3';
    if ($raceNumber >= 27 && $raceNumber <= 36) return 'S4';
    return '';
}

function wsrh_pair_key(string $raceCode, string $previousSnapshot, string $currentSnapshot): string
{
    return $raceCode . '|' . basename($previousSnapshot) . '|' . basename($currentSnapshot);
}

function wsrh_pair_history_rows(array $history): array
{
    if (isset($history['rows']) && is_array($history['rows'])) {
        return $history['rows'];
    }

    if (isset($history['releases']) && is_array($history['releases'])) {
        return $history['releases'];
    }

    return [];
}

function wsrh_add_pair_history_rows(array &$index, array $rows): void
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $raceCode = (string)($row['race_code'] ?? '');
        $previous = (string)($row['previous_snapshot'] ?? '');
        $current = (string)($row['current_snapshot'] ?? '');
        if ($raceCode === '' || $previous === '' || $current === '') {
            continue;
        }

        $index[wsrh_pair_key($raceCode, $previous, $current)] = $row;
    }
}

function wsrh_load_pair_history_index(string $baseDir, string $raceCode, string $raceFolder): array
{
    $index = [];

    $global = wsrh_load_json($baseDir . '/_race_results_pair_classification_history.json');
    wsrh_add_pair_history_rows($index, wsrh_pair_history_rows($global));

    $local = wsrh_load_json($raceFolder . '/mrl_impact_pair_history.json');
    wsrh_add_pair_history_rows($index, wsrh_pair_history_rows($local));

    return $index;
}

function wsrh_find_pair_row(array $pairIndex, string $raceCode, string $previousSnapshot, string $currentSnapshot): array
{
    $key = wsrh_pair_key($raceCode, $previousSnapshot, $currentSnapshot);
    return (isset($pairIndex[$key]) && is_array($pairIndex[$key])) ? $pairIndex[$key] : [];
}

function wsrh_public_status_for_update($mrlImpact): string
{
    if ($mrlImpact === true) {
        return 'Revised standings release';
    }

    if ($mrlImpact === false) {
        return 'Informational result update';
    }

    return 'Updated standings release';
}

function wsrh_public_reason_for_update($mrlImpact): string
{
    if ($mrlImpact === true) {
        return 'Race results were updated after the previous release and MRL standings were affected.';
    }

    if ($mrlImpact === false) {
        return 'Race results were updated after the previous release; MRL standings were not affected.';
    }

    return 'Race results were updated after the previous release.';
}

function wsrh_bool_or_null(array $row, string $key)
{
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
        return null;
    }

    return !empty($row[$key]);
}

$year = isset($_GET['year']) ? preg_replace('/[^0-9]/', '', (string)$_GET['year']) : '2026';
if ($year === '') {
    $year = '2026';
}

$write = (isset($_GET['write']) && (string)$_GET['write'] === '1');
$baseDir = __DIR__;
$yearDir = $baseDir . '/' . $year;
$yearIndexPath = $yearDir . '/_year_index.json';
$outputPath = $yearDir . '/_weekly_standings_release_history.json';
$yearIndex = wsrh_load_json($yearIndexPath);
$releases = [];
$raceHistoryCount = 0;
$updatedReleaseCount = 0;
$pendingReleaseCount = 0;
$pairMatchedCount = 0;

if (isset($yearIndex['races']) && is_array($yearIndex['races'])) {
    foreach ($yearIndex['races'] as $raceId => $raceRow) {
        if (!is_array($raceRow)) {
            continue;
        }

        if ((string)($raceRow['kind'] ?? '') !== 'R') {
            continue;
        }

        $raceNumber = (int)($raceRow['number'] ?? 0);
        $folder = (string)($raceRow['folder'] ?? '');
        $raceName = (string)($raceRow['race_name'] ?? '');
        if ($raceNumber <= 0 || $folder === '') {
            continue;
        }

        $raceCode = 'R' . str_pad((string)$raceNumber, 2, '0', STR_PAD_LEFT);
        $raceFolder = $yearDir . '/' . $folder;
        $snapshotFiles = wsrh_snapshot_files($raceFolder);
        if (empty($snapshotFiles)) {
            continue;
        }

        $raceHistoryCount++;
        $underReview = is_file($raceFolder . '/under_review.flag');
        $shortName = wsrh_short_race_label($raceName);
        $segment = wsrh_segment_from_race_number($raceNumber);
        $pairIndex = wsrh_load_pair_history_index($baseDir, $raceCode, $raceFolder);
        $previousRelease = null;
        $snapshotTotal = count($snapshotFiles);

        foreach ($snapshotFiles as $idx => $snapshotFile) {
            $snapshotId = wsrh_snapshot_id($snapshotFile);
            $releasedAt = wsrh_snapshot_raw_datetime($snapshotId);
            $releaseId = ($snapshotId !== '') ? $snapshotId . '_' . $raceCode : $raceCode . '_snapshot_' . ($idx + 1);
            $isLatest = ($idx === $snapshotTotal - 1);
            $isPendingCurrent = ($underReview && $isLatest);
            $isInitial = ($idx === 0);
            $pairRow = [];
            $mrlImpact = null;
            $changeStatus = '';
            $changeStatusLabel = '';
            $changedDetails = [];
            $supersedes = null;
            $sourceType = $isInitial ? 'initial' : 'direct_revision';
            $releaseType = $isInitial ? 'initial' : 'updated';
            $status = 'released';
            $publicStatus = $isInitial ? 'Official standings release' : 'Updated standings release';
            $reasonPublic = $isInitial
                ? 'Initial standings release for ' . $raceCode . ' ' . $shortName . '.'
                : 'Race results were updated after the previous release.';

            if (!$isInitial) {
                $previousSnapshot = basename((string)$snapshotFiles[$idx - 1]);
                $currentSnapshot = basename((string)$snapshotFile);
                $pairRow = wsrh_find_pair_row($pairIndex, $raceCode, $previousSnapshot, $currentSnapshot);
                if (!empty($pairRow)) {
                    $pairMatchedCount++;
                    $mrlImpact = wsrh_bool_or_null($pairRow, 'mrl_impact');
                    $changeStatus = (string)($pairRow['change_status'] ?? '');
                    $changeStatusLabel = (string)($pairRow['change_status_label'] ?? '');
                    if (isset($pairRow['changed_driver_details']) && is_array($pairRow['changed_driver_details'])) {
                        $changedDetails = $pairRow['changed_driver_details'];
                    }
                }

                $updatedReleaseCount++;
                $publicStatus = wsrh_public_status_for_update($mrlImpact);
                $reasonPublic = wsrh_public_reason_for_update($mrlImpact);
                if ($previousRelease !== null) {
                    $supersedes = [
                        'release_id' => (string)$previousRelease['release_id'],
                        'generated_id' => (string)$previousRelease['generated_id'],
                        'released_at' => (string)$previousRelease['released_at'],
                        'released_at_display' => (string)$previousRelease['released_at_display'],
                        'snapshot_id' => (string)$previousRelease['snapshot_id'],
                        'snapshot_file' => (string)$previousRelease['snapshot_file'],
                    ];
                }
            }

            if ($isPendingCurrent) {
                $pendingReleaseCount++;
                $releaseType = 'pending_review';
                $status = 'pending_review';
                $publicStatus = 'Pending league review';
                $reasonPublic = 'Results generated automatically and awaiting league release.';
            }

            $record = [
                'release_id' => $releaseId,
                'generated_id' => $releaseId,
                'race_code' => $raceCode,
                'race_number' => $raceNumber,
                'race_id' => (string)$raceId,
                'race_name' => $raceName,
                'short_name' => $shortName,
                'segment' => $segment,
                'release_type' => $releaseType,
                'source_type' => $sourceType,
                'status' => $status,
                'public_status' => $publicStatus,
                'released_at' => $releasedAt,
                'released_at_display' => wsrh_public_datetime($releasedAt),
                'reason_public' => $reasonPublic,
                'supersedes' => $supersedes,
                'mrl_impact' => $mrlImpact,
                'change_status' => $changeStatus,
                'change_status_label' => $changeStatusLabel,
                'changed_mrl_drivers_count' => isset($pairRow['changed_mrl_drivers_count']) ? (int)$pairRow['changed_mrl_drivers_count'] : null,
                'changed_mrl_listed_drivers_count' => isset($pairRow['changed_mrl_listed_drivers_count']) ? (int)$pairRow['changed_mrl_listed_drivers_count'] : null,
                'changed_segment_picked_drivers_count' => isset($pairRow['changed_segment_picked_drivers_count']) ? (int)$pairRow['changed_segment_picked_drivers_count'] : null,
                'changed_all_drivers_count' => isset($pairRow['changed_all_drivers_count']) ? (int)$pairRow['changed_all_drivers_count'] : null,
                'changed_driver_details' => $changedDetails,
                'snapshot_id' => $snapshotId,
                'snapshot_file' => basename($snapshotFile),
                'previous_snapshot' => $isInitial ? '' : basename((string)$snapshotFiles[$idx - 1]),
                'current_snapshot' => basename($snapshotFile),
                'snapshot_index' => $idx + 1,
                'snapshot_count' => $snapshotTotal,
                'is_current_snapshot' => $isLatest,
                'pair_history_matched' => !$isInitial && !empty($pairRow),
                'debug' => [
                    'race_folder' => $folder,
                    'race_folder_rel' => $year . '/' . $folder,
                    'builder_note' => $isInitial
                        ? 'Bootstrapped initial release from first legitimate saved race result snapshot.'
                        : 'Bootstrapped updated release from adjacent saved race result snapshot pair.',
                    'pair_key' => !$isInitial ? ((string)($pairRow['pair_key'] ?? (basename((string)$snapshotFiles[$idx - 1]) . ' -> ' . basename($snapshotFile)))) : '',
                ],
            ];

            $releases[] = $record;
            $previousRelease = $record;
        }
    }
}

usort($releases, function (array $a, array $b): int {
    $raceCmp = ((int)($a['race_number'] ?? 0)) <=> ((int)($b['race_number'] ?? 0));
    if ($raceCmp !== 0) {
        return $raceCmp;
    }

    return ((int)($a['snapshot_index'] ?? 0)) <=> ((int)($b['snapshot_index'] ?? 0));
});

$history = [
    'signature' => 'WEEKLY_STANDINGS_RELEASE_HISTORY v001',
    'version' => 'v001',
    'generated_at' => date('Y-m-d H:i:s'),
    'timezone' => 'America/New_York',
    'source' => 'weekly_standings_release_history_builder.php',
    'scope' => 'local_mrl_artifacts_only',
    'notes' => [
        'Public weekly standings Audit panels should use simple release/update/supersedes language.',
        'This metadata may contain technical IDs and file references for admin/debug use, but public pages do not need to display all fields.',
        'Every legitimate saved race result snapshot is represented as a release-history version. Updated versions supersede the prior version for the same race/week.',
        'MRL Impact controls downstream revision needs; it does not control whether a release-history version exists.',
    ],
    'year' => $year,
    'output_file' => $year . '/_weekly_standings_release_history.json',
    'race_history_count' => $raceHistoryCount,
    'release_count' => count($releases),
    'updated_release_count' => $updatedReleaseCount,
    'pending_release_count' => $pendingReleaseCount,
    'pair_history_matched_count' => $pairMatchedCount,
    'releases' => $releases,
];

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
];
if ($write) {
    $writeResult = wsrh_write_json_atomic($outputPath, $history);
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
    <p><strong>Year:</strong> <?php echo wsrh_h($year); ?></p>
    <p><strong>Output:</strong> <code><?php echo wsrh_h($outputPath); ?></code></p>
    <p><strong>Mode:</strong> <?php echo $write ? '<span class="warn">WRITE</span>' : 'Preview only'; ?></p>
    <?php if ($write): ?>
        <div class="writebox">
            <p class="<?php echo $writeResult['ok'] ? 'ok' : 'warn'; ?>"><?php echo wsrh_h((string)$writeResult['message']); ?></p>
            <?php if ($writeResult['ok']): ?>
                <p><strong>Verified file modified:</strong> <?php echo wsrh_h((string)$writeResult['mtime_display']); ?></p>
                <p><strong>Verified bytes:</strong> <?php echo (int)$writeResult['bytes_verified']; ?></p>
                <p><strong>SHA-256:</strong> <code><?php echo wsrh_h((string)$writeResult['sha256']); ?></code></p>
                <p><strong>Backup:</strong> <code><?php echo wsrh_h((string)$writeResult['backup_path']); ?></code></p>
                <p><a class="button" href="<?php echo wsrh_h($year . '/_weekly_standings_release_history.json?v=' . time()); ?>" target="_blank" rel="noopener">Open written JSON no-cache</a></p>
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
        Races with snapshots: <?php echo (int)$raceHistoryCount; ?> ·
        Updated records: <?php echo (int)$updatedReleaseCount; ?> ·
        Pending records: <?php echo (int)$pendingReleaseCount; ?> ·
        Matched pair history rows: <?php echo (int)$pairMatchedCount; ?>
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
                    $impact = $row['mrl_impact'];
                    $impactText = ($impact === null) ? '—' : (!empty($impact) ? 'YES' : 'NO');
                    $impactClass = ($impact === null) ? '' : (!empty($impact) ? 'yes' : 'no');
                    $sup = $row['supersedes'] ?? null;
                    $supText = '—';
                    if (is_array($sup)) {
                        $supText = (string)($sup['released_at_display'] ?? $sup['release_id'] ?? 'Prior release');
                    }
                ?>
                <tr>
                    <td><?php echo wsrh_h((string)$row['race_code'] . ' ' . (string)$row['short_name']); ?></td>
                    <td><?php echo wsrh_h((string)$row['release_type']); ?></td>
                    <td><?php echo wsrh_h((string)$row['released_at_display']); ?></td>
                    <td><?php echo wsrh_h((string)$row['public_status']); ?></td>
                    <td class="<?php echo wsrh_h($impactClass); ?>"><?php echo wsrh_h($impactText); ?></td>
                    <td><?php echo wsrh_h((string)($row['change_status_label'] ?: '—')); ?></td>
                    <td><?php echo wsrh_h($supText); ?></td>
                    <td><code><?php echo wsrh_h((string)$row['release_id']); ?></code></td>
                    <td><?php echo wsrh_h((string)$row['snapshot_file']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
