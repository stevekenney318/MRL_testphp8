<?php
declare(strict_types=1);

/**
 * weekly_standings_release_history_helper.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/21/2026 3:19:00 pm
 *
 * CHANGELOG:
 * v001 (6/21/2026 3:19:00 pm)
 *   - NEW: Shared helper for weekly standings release-history metadata.
 *   - NEW: Provides atomic write/backup/verify handling reused by builder, race monitor, and revision monitor.
 *   - NEW: Provides local-artifact rebuild/backfill used by manual builder fallback.
 *   - NEW: Provides record helpers for initial and direct updated release records.
 *
 * Purpose:
 *   Keep release-history metadata logic in one place while preserving the manual builder as a fallback/rebuild tool.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

if (!function_exists('wsrel_load_json')) {

function wsrel_load_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function wsrel_now_raw(): string
{
    return date('Y-m-d H:i:s');
}

function wsrel_now_display(): string
{
    return date('M j, Y g:i A');
}

function wsrel_human_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

function wsrel_history_path(string $baseDir, $year): string
{
    return rtrim($baseDir, '/\\') . '/' . (string)$year . '/_weekly_standings_release_history.json';
}

function wsrel_default_history($year, string $source = 'weekly_standings_release_history_helper.php'): array
{
    return [
        'signature' => 'WEEKLY_STANDINGS_RELEASE_HISTORY v001',
        'version' => 'v001',
        'generated_at' => wsrel_now_raw(),
        'timezone' => 'America/New_York',
        'source' => $source,
        'scope' => 'local_mrl_artifacts_only',
        'notes' => [
            'Public weekly standings Audit panels should use simple release/update/supersedes language.',
            'This metadata may contain technical IDs and file references for admin/debug use, but public pages do not need to display all fields.',
            'Every legitimate saved race result snapshot is represented as a release-history version. Updated versions supersede the prior version for the same race/week.',
            'MRL Impact controls downstream revision needs; it does not control whether a release-history version exists.',
        ],
        'year' => (string)$year,
        'output_file' => (string)$year . '/_weekly_standings_release_history.json',
        'race_history_count' => 0,
        'release_count' => 0,
        'updated_release_count' => 0,
        'pending_release_count' => 0,
        'pair_history_matched_count' => 0,
        'releases' => [],
    ];
}

function wsrel_normalize_history(array $history, $year, string $source = 'weekly_standings_release_history_helper.php'): array
{
    $base = wsrel_default_history($year, $source);
    foreach ($base as $k => $v) {
        if (!array_key_exists($k, $history)) {
            $history[$k] = $v;
        }
    }
    if (!isset($history['releases']) || !is_array($history['releases'])) {
        $history['releases'] = [];
    }
    $history['signature'] = 'WEEKLY_STANDINGS_RELEASE_HISTORY v001';
    $history['version'] = 'v001';
    $history['generated_at'] = wsrel_now_raw();
    $history['timezone'] = 'America/New_York';
    $history['year'] = (string)$year;
    $history['output_file'] = (string)$year . '/_weekly_standings_release_history.json';
    return $history;
}

function wsrel_write_json_atomic(string $path, array $data): array
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
        'size_label' => '',
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

    $bytes = @file_put_contents($tmpPath, $json, LOCK_EX);
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

    $verify = @file_get_contents($path);
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
    $result['size_label'] = wsrel_human_bytes(strlen($verify));
    return $result;
}

function wsrel_write_history(string $baseDir, $year, array $history): array
{
    $path = wsrel_history_path($baseDir, $year);
    $history = wsrel_finalize_history($history, $year, 'weekly_standings_release_history_helper.php');
    return wsrel_write_json_atomic($path, $history);
}

function wsrel_load_history(string $baseDir, $year): array
{
    return wsrel_normalize_history(wsrel_load_json(wsrel_history_path($baseDir, $year)), $year);
}

function wsrel_snapshot_id_from_file(string $snapshotFile): string
{
    $base = basename($snapshotFile);
    if (!preg_match('/^snapshot_(\d{8}_\d{6}\d*)\.html$/', $base, $m)) {
        return '';
    }
    return (string)$m[1];
}

function wsrel_snapshot_raw_datetime(string $snapshotId): string
{
    if (!preg_match('/^(\d{8})_(\d{6})/', $snapshotId, $m)) {
        return '';
    }
    $dt = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], new DateTimeZone('America/New_York'));
    return $dt instanceof DateTime ? $dt->format('Y-m-d H:i:s') : '';
}

function wsrel_public_datetime(string $raw): string
{
    if ($raw === '') return '';
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('America/New_York'));
    return $dt instanceof DateTime ? $dt->format('M j, Y g:i A') : $raw;
}

function wsrel_short_race_label(string $raceName): string
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
        'NASCAR Cup Series at San Diego' => 'San Diego',
        'Daytona 500' => 'Daytona 500',
    ];
    if (isset($map[$raceName])) return $map[$raceName];
    $short = preg_replace('/^NASCAR Cup Series at\s+/i', '', $raceName);
    return is_string($short) && $short !== '' ? $short : $raceName;
}

function wsrel_segment_from_race_number(int $raceNumber): string
{
    if ($raceNumber >= 1 && $raceNumber <= 8) return 'S1';
    if ($raceNumber >= 9 && $raceNumber <= 17) return 'S2';
    if ($raceNumber >= 18 && $raceNumber <= 26) return 'S3';
    if ($raceNumber >= 27 && $raceNumber <= 36) return 'S4';
    return '';
}

function wsrel_race_code_from_number(int $raceNumber): string
{
    return 'R' . str_pad((string)$raceNumber, 2, '0', STR_PAD_LEFT);
}

function wsrel_snapshot_files(string $raceFolder): array
{
    $files = glob(rtrim($raceFolder, '/\\') . '/snapshot_*.html');
    if (!is_array($files)) return [];
    sort($files, SORT_STRING);
    return array_values($files);
}

function wsrel_pair_key(string $raceCode, string $previousSnapshot, string $currentSnapshot): string
{
    return $raceCode . '|' . basename($previousSnapshot) . '|' . basename($currentSnapshot);
}

function wsrel_pair_history_rows(array $history): array
{
    if (isset($history['rows']) && is_array($history['rows'])) return $history['rows'];
    if (isset($history['releases']) && is_array($history['releases'])) return $history['releases'];
    return [];
}

function wsrel_add_pair_history_rows(array &$index, array $rows): void
{
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $raceCode = (string)($row['race_code'] ?? '');
        $previous = (string)($row['previous_snapshot'] ?? '');
        $current = (string)($row['current_snapshot'] ?? '');
        if ($raceCode === '' || $previous === '' || $current === '') continue;
        $index[wsrel_pair_key($raceCode, $previous, $current)] = $row;
    }
}

function wsrel_load_pair_history_index(string $baseDir, string $raceCode, string $raceFolder): array
{
    $index = [];
    $global = wsrel_load_json(rtrim($baseDir, '/\\') . '/_race_results_pair_classification_history.json');
    wsrel_add_pair_history_rows($index, wsrel_pair_history_rows($global));
    $local = wsrel_load_json(rtrim($raceFolder, '/\\') . '/mrl_impact_pair_history.json');
    wsrel_add_pair_history_rows($index, wsrel_pair_history_rows($local));
    return $index;
}

function wsrel_find_pair_row(array $pairIndex, string $raceCode, string $previousSnapshot, string $currentSnapshot): array
{
    $key = wsrel_pair_key($raceCode, $previousSnapshot, $currentSnapshot);
    return (isset($pairIndex[$key]) && is_array($pairIndex[$key])) ? $pairIndex[$key] : [];
}

function wsrel_bool_or_null(array $row, string $key)
{
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') return null;
    return !empty($row[$key]);
}

function wsrel_public_status_for_update($mrlImpact): string
{
    if ($mrlImpact === true) return 'Revised standings release';
    if ($mrlImpact === false) return 'Informational result update';
    return 'Updated standings release';
}

function wsrel_public_reason_for_update($mrlImpact): string
{
    if ($mrlImpact === true) return 'Race results were updated after the previous release and MRL standings were affected.';
    if ($mrlImpact === false) return 'Race results were updated after the previous release; MRL standings were not affected.';
    return 'Race results were updated after the previous release.';
}

function wsrel_release_sort(array $a, array $b): int
{
    $raceCmp = ((int)($a['race_number'] ?? 0)) <=> ((int)($b['race_number'] ?? 0));
    if ($raceCmp !== 0) return $raceCmp;
    $snapCmp = ((int)($a['snapshot_index'] ?? 0)) <=> ((int)($b['snapshot_index'] ?? 0));
    if ($snapCmp !== 0) return $snapCmp;
    return strcmp((string)($a['released_at'] ?? ''), (string)($b['released_at'] ?? ''));
}

function wsrel_finalize_history(array $history, $year, string $source): array
{
    $history = wsrel_normalize_history($history, $year, $source);
    usort($history['releases'], 'wsrel_release_sort');
    $raceCodes = [];
    $updated = 0;
    $pending = 0;
    $matched = 0;
    foreach ($history['releases'] as $row) {
        if (!is_array($row)) continue;
        $raceCode = (string)($row['race_code'] ?? '');
        if ($raceCode !== '') $raceCodes[$raceCode] = true;
        $releaseType = (string)($row['release_type'] ?? '');
        if ($releaseType === 'updated') $updated++;
        if ((string)($row['status'] ?? '') === 'pending_review' || $releaseType === 'pending_review') $pending++;
        if (!empty($row['pair_history_matched'])) $matched++;
    }
    $history['race_history_count'] = count($raceCodes);
    $history['release_count'] = count($history['releases']);
    $history['updated_release_count'] = $updated;
    $history['pending_release_count'] = $pending;
    $history['pair_history_matched_count'] = $matched;
    $history['generated_at'] = wsrel_now_raw();
    $history['source'] = $source;
    return $history;
}

function wsrel_find_release_index_by_id(array $releases, string $releaseId): int
{
    foreach ($releases as $i => $row) {
        if (is_array($row) && (string)($row['release_id'] ?? '') === $releaseId) return (int)$i;
    }
    return -1;
}

function wsrel_find_latest_release_for_race(array $releases, string $raceCode, string $excludeReleaseId = ''): array
{
    $candidates = [];
    foreach ($releases as $row) {
        if (!is_array($row)) continue;
        if ((string)($row['race_code'] ?? '') !== $raceCode) continue;
        if ($excludeReleaseId !== '' && (string)($row['release_id'] ?? '') === $excludeReleaseId) continue;
        $candidates[] = $row;
    }
    usort($candidates, 'wsrel_release_sort');
    if (empty($candidates)) return [];
    return $candidates[count($candidates)-1];
}

function wsrel_find_release_for_snapshot(array $releases, string $raceCode, string $snapshotFile): array
{
    $snapshotBase = basename($snapshotFile);
    foreach ($releases as $row) {
        if (!is_array($row)) continue;
        if ((string)($row['race_code'] ?? '') === $raceCode && (string)($row['snapshot_file'] ?? '') === $snapshotBase) {
            return $row;
        }
    }
    return [];
}

function wsrel_supersedes_from_release(array $release): ?array
{
    if (empty($release)) return null;
    return [
        'release_id' => (string)($release['release_id'] ?? ''),
        'generated_id' => (string)($release['generated_id'] ?? ''),
        'released_at' => (string)($release['released_at'] ?? ''),
        'released_at_display' => (string)($release['released_at_display'] ?? ''),
        'snapshot_id' => (string)($release['snapshot_id'] ?? ''),
        'snapshot_file' => (string)($release['snapshot_file'] ?? ''),
    ];
}

function wsrel_build_record(array $ctx, array $existingReleases = []): array
{
    $raceNumber = (int)($ctx['race_number'] ?? 0);
    $raceCode = (string)($ctx['race_code'] ?? '');
    if ($raceCode === '' && $raceNumber > 0) $raceCode = wsrel_race_code_from_number($raceNumber);

    $snapshotFile = basename((string)($ctx['snapshot_file'] ?? $ctx['current_snapshot'] ?? ''));
    $snapshotId = (string)($ctx['snapshot_id'] ?? '');
    if ($snapshotId === '') $snapshotId = wsrel_snapshot_id_from_file($snapshotFile);
    $releasedAt = (string)($ctx['released_at'] ?? '');
    if ($releasedAt === '') $releasedAt = wsrel_snapshot_raw_datetime($snapshotId);
    if ($releasedAt === '') $releasedAt = wsrel_now_raw();

    $releaseId = (string)($ctx['release_id'] ?? '');
    if ($releaseId === '') $releaseId = ($snapshotId !== '' ? $snapshotId . '_' . $raceCode : $raceCode . '_' . date('Ymd_His'));

    $raceName = (string)($ctx['race_name'] ?? 'Race');
    $shortName = (string)($ctx['short_name'] ?? '');
    if ($shortName === '') $shortName = wsrel_short_race_label($raceName);

    $previousSnapshot = basename((string)($ctx['previous_snapshot'] ?? ''));
    $pairRow = (isset($ctx['pair_row']) && is_array($ctx['pair_row'])) ? $ctx['pair_row'] : [];
    if (empty($pairRow) && isset($ctx['classification']) && is_array($ctx['classification'])) {
        $pairRow = $ctx['classification'];
    }

    $mrlImpact = array_key_exists('mrl_impact', $ctx) ? $ctx['mrl_impact'] : null;
    if ($mrlImpact === null && !empty($pairRow)) {
        $mrlImpact = wsrel_bool_or_null($pairRow, isset($pairRow['impact']) && !isset($pairRow['mrl_impact']) ? 'impact' : 'mrl_impact');
    }

    $hasExistingForRace = false;
    foreach ($existingReleases as $existingReleaseRow) {
        if (is_array($existingReleaseRow) && (string)($existingReleaseRow['race_code'] ?? '') === $raceCode && (string)($existingReleaseRow['release_id'] ?? '') !== $releaseId) {
            $hasExistingForRace = true;
            break;
        }
    }
    $isInitial = !$hasExistingForRace || (string)($ctx['release_type'] ?? '') === 'initial';
    $releaseType = (string)($ctx['release_type'] ?? ($isInitial ? 'initial' : 'updated'));
    $sourceType = (string)($ctx['source_type'] ?? ($isInitial ? 'initial' : 'direct_revision'));
    $underReview = !empty($ctx['under_review']) || !empty($ctx['under_review_flag']) || !empty($ctx['pending_review']);
    $status = (string)($ctx['status'] ?? 'released');

    $publicStatus = $isInitial ? 'Official standings release' : wsrel_public_status_for_update($mrlImpact);
    $reasonPublic = $isInitial
        ? 'Initial standings release for ' . $raceCode . ' ' . $shortName . '.'
        : wsrel_public_reason_for_update($mrlImpact);

    if ($underReview) {
        $releaseType = 'pending_review';
        $status = 'pending_review';
        $publicStatus = 'Pending league review';
        $reasonPublic = 'Results generated automatically and awaiting league release.';
    }

    if (isset($ctx['public_status']) && (string)$ctx['public_status'] !== '') $publicStatus = (string)$ctx['public_status'];
    if (isset($ctx['reason_public']) && (string)$ctx['reason_public'] !== '') $reasonPublic = (string)$ctx['reason_public'];

    $supersedes = null;
    if (isset($ctx['supersedes']) && is_array($ctx['supersedes'])) {
        $supersedes = $ctx['supersedes'];
    } elseif (!$isInitial) {
        $prior = [];
        if ($previousSnapshot !== '') $prior = wsrel_find_release_for_snapshot($existingReleases, $raceCode, $previousSnapshot);
        if (empty($prior)) $prior = wsrel_find_latest_release_for_race($existingReleases, $raceCode, $releaseId);
        $supersedes = wsrel_supersedes_from_release($prior);
    }

    $changedDetails = [];
    if (isset($ctx['changed_driver_details']) && is_array($ctx['changed_driver_details'])) {
        $changedDetails = $ctx['changed_driver_details'];
    } elseif (isset($pairRow['changed_driver_details']) && is_array($pairRow['changed_driver_details'])) {
        $changedDetails = $pairRow['changed_driver_details'];
    }

    $snapshotIndex = (int)($ctx['snapshot_index'] ?? 0);
    $snapshotCount = (int)($ctx['snapshot_count'] ?? 0);

    return [
        'release_id' => $releaseId,
        'generated_id' => (string)($ctx['generated_id'] ?? $releaseId),
        'race_code' => $raceCode,
        'race_number' => $raceNumber,
        'race_id' => (string)($ctx['race_id'] ?? ''),
        'race_name' => $raceName,
        'short_name' => $shortName,
        'segment' => (string)($ctx['segment'] ?? wsrel_segment_from_race_number($raceNumber)),
        'release_type' => $releaseType,
        'source_type' => $sourceType,
        'status' => $status,
        'public_status' => $publicStatus,
        'released_at' => $releasedAt,
        'released_at_display' => wsrel_public_datetime($releasedAt),
        'reason_public' => $reasonPublic,
        'supersedes' => $supersedes,
        'mrl_impact' => $mrlImpact,
        'change_status' => (string)($ctx['change_status'] ?? $pairRow['change_status'] ?? ''),
        'change_status_label' => (string)($ctx['change_status_label'] ?? $pairRow['change_status_label'] ?? ''),
        'changed_mrl_drivers_count' => isset($ctx['changed_mrl_drivers_count']) ? (int)$ctx['changed_mrl_drivers_count'] : (isset($pairRow['changed_mrl_drivers_count']) ? (int)$pairRow['changed_mrl_drivers_count'] : null),
        'changed_mrl_listed_drivers_count' => isset($ctx['changed_mrl_listed_drivers_count']) ? (int)$ctx['changed_mrl_listed_drivers_count'] : (isset($pairRow['changed_mrl_listed_drivers_count']) ? (int)$pairRow['changed_mrl_listed_drivers_count'] : null),
        'changed_segment_picked_drivers_count' => isset($ctx['changed_segment_picked_drivers_count']) ? (int)$ctx['changed_segment_picked_drivers_count'] : (isset($pairRow['changed_segment_picked_drivers_count']) ? (int)$pairRow['changed_segment_picked_drivers_count'] : null),
        'changed_all_drivers_count' => isset($ctx['changed_all_drivers_count']) ? (int)$ctx['changed_all_drivers_count'] : (isset($pairRow['changed_all_drivers_count']) ? (int)$pairRow['changed_all_drivers_count'] : null),
        'changed_driver_details' => $changedDetails,
        'snapshot_id' => $snapshotId,
        'snapshot_file' => $snapshotFile,
        'previous_snapshot' => $previousSnapshot,
        'current_snapshot' => $snapshotFile,
        'snapshot_index' => $snapshotIndex,
        'snapshot_count' => $snapshotCount,
        'is_current_snapshot' => !empty($ctx['is_current_snapshot']),
        'pair_history_matched' => !empty($ctx['pair_history_matched']) || (!$isInitial && !empty($pairRow)),
        'debug' => [
            'race_folder' => (string)($ctx['race_folder'] ?? ''),
            'race_folder_rel' => (string)($ctx['race_folder_rel'] ?? ''),
            'builder_note' => (string)($ctx['builder_note'] ?? ($isInitial ? 'Initial release record created.' : 'Updated release record created.')),
            'pair_key' => !$isInitial ? (string)($ctx['pair_key'] ?? $pairRow['pair_key'] ?? ($previousSnapshot !== '' ? ($previousSnapshot . ' -> ' . $snapshotFile) : '')) : '',
        ],
    ];
}

function wsrel_upsert_record(string $baseDir, $year, array $record, string $source = 'weekly_standings_release_history_helper.php'): array
{
    $history = wsrel_load_history($baseDir, $year);
    $idx = wsrel_find_release_index_by_id($history['releases'], (string)($record['release_id'] ?? ''));
    if ($idx >= 0) {
        $history['releases'][$idx] = array_replace_recursive($history['releases'][$idx], $record);
    } else {
        $history['releases'][] = $record;
    }
    $history = wsrel_finalize_history($history, $year, $source);
    $write = wsrel_write_json_atomic(wsrel_history_path($baseDir, $year), $history);
    $write['release_id'] = (string)($record['release_id'] ?? '');
    return $write;
}

function wsrel_record_snapshot_release(string $baseDir, $year, array $ctx): array
{
    $history = wsrel_load_history($baseDir, $year);
    $record = wsrel_build_record($ctx, $history['releases']);
    return wsrel_upsert_record($baseDir, $year, $record, 'weekly_standings_release_history_helper.php');
}

function wsrel_build_history_from_artifacts(string $baseDir, $year): array
{
    $year = (string)$year;
    $yearDir = rtrim($baseDir, '/\\') . '/' . $year;
    $yearIndex = wsrel_load_json($yearDir . '/_year_index.json');
    $releases = [];
    $raceHistoryCount = 0;
    $updatedReleaseCount = 0;
    $pendingReleaseCount = 0;
    $pairMatchedCount = 0;

    if (isset($yearIndex['races']) && is_array($yearIndex['races'])) {
        foreach ($yearIndex['races'] as $raceId => $raceRow) {
            if (!is_array($raceRow)) continue;
            if ((string)($raceRow['kind'] ?? '') !== 'R') continue;

            $raceNumber = (int)($raceRow['number'] ?? 0);
            $folder = (string)($raceRow['folder'] ?? '');
            $raceName = (string)($raceRow['race_name'] ?? '');
            if ($raceNumber <= 0 || $folder === '') continue;

            $raceCode = wsrel_race_code_from_number($raceNumber);
            $raceFolder = $yearDir . '/' . $folder;
            $snapshotFiles = wsrel_snapshot_files($raceFolder);
            if (empty($snapshotFiles)) continue;

            $raceHistoryCount++;
            $underReview = is_file($raceFolder . '/under_review.flag');
            $shortName = wsrel_short_race_label($raceName);
            $segment = wsrel_segment_from_race_number($raceNumber);
            $pairIndex = wsrel_load_pair_history_index($baseDir, $raceCode, $raceFolder);
            $previousRelease = null;
            $snapshotTotal = count($snapshotFiles);

            foreach ($snapshotFiles as $idx => $snapshotFile) {
                $snapshotId = wsrel_snapshot_id_from_file($snapshotFile);
                $isLatest = ($idx === $snapshotTotal - 1);
                $isInitial = ($idx === 0);
                $previousSnapshot = $isInitial ? '' : basename((string)$snapshotFiles[$idx - 1]);
                $currentSnapshot = basename((string)$snapshotFile);
                $pairRow = [];
                $mrlImpact = null;
                if (!$isInitial) {
                    $pairRow = wsrel_find_pair_row($pairIndex, $raceCode, $previousSnapshot, $currentSnapshot);
                    if (!empty($pairRow)) {
                        $pairMatchedCount++;
                        $mrlImpact = wsrel_bool_or_null($pairRow, 'mrl_impact');
                    }
                }

                $ctx = [
                    'race_code' => $raceCode,
                    'race_number' => $raceNumber,
                    'race_id' => (string)$raceId,
                    'race_name' => $raceName,
                    'short_name' => $shortName,
                    'segment' => $segment,
                    'race_folder' => $folder,
                    'race_folder_rel' => $year . '/' . $folder,
                    'snapshot_file' => $currentSnapshot,
                    'previous_snapshot' => $previousSnapshot,
                    'snapshot_index' => $idx + 1,
                    'snapshot_count' => $snapshotTotal,
                    'is_current_snapshot' => $isLatest,
                    'under_review' => ($underReview && $isLatest),
                    'release_type' => $isInitial ? 'initial' : 'updated',
                    'source_type' => $isInitial ? 'initial' : 'direct_revision',
                    'pair_row' => $pairRow,
                    'mrl_impact' => $mrlImpact,
                    'pair_history_matched' => !$isInitial && !empty($pairRow),
                    'builder_note' => $isInitial ? 'Bootstrapped initial release from first legitimate saved race result snapshot.' : 'Bootstrapped updated release from adjacent saved race result snapshot pair.',
                ];

                $record = wsrel_build_record($ctx, $previousRelease === null ? $releases : $releases);
                if (!$isInitial && $previousRelease !== null && empty($record['supersedes'])) {
                    $record['supersedes'] = wsrel_supersedes_from_release($previousRelease);
                }
                $releases[] = $record;
                $previousRelease = $record;
                if ((string)$record['release_type'] === 'updated') $updatedReleaseCount++;
                if ((string)$record['status'] === 'pending_review' || (string)$record['release_type'] === 'pending_review') $pendingReleaseCount++;
            }
        }
    }

    $history = wsrel_default_history($year, 'weekly_standings_release_history_builder.php');
    $history['releases'] = $releases;
    $history = wsrel_finalize_history($history, $year, 'weekly_standings_release_history_builder.php');
    $history['race_history_count'] = $raceHistoryCount;
    $history['updated_release_count'] = $updatedReleaseCount;
    $history['pending_release_count'] = $pendingReleaseCount;
    $history['pair_history_matched_count'] = $pairMatchedCount;
    return $history;
}

} // function_exists guard
