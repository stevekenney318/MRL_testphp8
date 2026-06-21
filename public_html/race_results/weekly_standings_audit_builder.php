<?php
/*
 * weekly_standings_audit_builder.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/20/2026 8:06:44 pm
 *
 * DESCRIPTION:
 * First-pass local MRL weekly standings audit/index helper.
 * Scans saved race_results artifacts only; does not call ESPN, does not fetch remote pages,
 * and does not modify existing race/revision data. Optional write mode saves a generated
 * _weekly_standings_audit_index.json file for later timeline/viewer work.
 *
 * CHANGELOG:
 *
 * v001 (6/19/2026)
 *   - NEW: Added read-only local scan of race folders, snapshots, snapshot summaries, under-review flags, revision metadata, and MRL/all-driver impact artifacts.
 *   - NEW: Generates a structured audit index with race status, original/current/superseded snapshots, direct revision events, and downstream indirect candidates for MRL-impacting changes.
 *   - NEW: Adds browser UI with summary cards, race inventory, direct revision event table, indirect candidate table, file gap table, and raw JSON preview/download.
 *   - NEW: Optional ?write=1 mode writes _weekly_standings_audit_index.json without changing any existing files.
 *   - NEW: Optional ?format=json mode returns the generated index as JSON.
 *   - NEW: Reads pair-level classifier history when _race_results_pair_classification_history.json or per-race mrl_impact_pair_history.json exists.
 *   - CHANGE: Direct Revision Events now prefer exact adjacent-pair rows over the legacy latest-pair-only classifier summary.
 *   - FIX: Stale revision_meta.json cannot override the real under_review.flag pending state.
 *   - CHANGE: Indirect candidate projection now skips pending-review weeks entirely; pending review means unreleased/not eligible for revision-chain purposes.
   - NEW: Added a plain-language timeline explainer to define direct events, indirect candidates, pending review, released/captured, and renamed/bad snapshots.
 *
 * PHP: 7.3 compatible.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

const WSAUDIT_VERSION = 'v001';
const WSAUDIT_SIGNATURE = 'WEEKLY_STANDINGS_AUDIT_BUILDER v001';
const WSAUDIT_OUTPUT_FILE = '_weekly_standings_audit_index.json';

if (!headers_sent()) {
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

function wsa_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function wsa_bool($value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'YES' || $value === 'yes';
}

function wsa_read_json(string $path)
{
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

function wsa_write_json(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function wsa_file_info(string $path): array
{
    if (!is_file($path)) {
        return array('present' => false, 'modified' => '', 'size_bytes' => 0, 'size_label' => '');
    }

    $size = filesize($path);
    if ($size === false) {
        $size = 0;
    }

    return array(
        'present' => true,
        'modified' => date('n/j/Y g:i:s a', filemtime($path) ?: time()),
        'size_bytes' => $size,
        'size_label' => wsa_format_bytes((int)$size),
    );
}

function wsa_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function wsa_snapshot_id_from_file(string $filename): string
{
    if (preg_match('/snapshot_(\d{8}_\d+)\.html$/', $filename, $m)) {
        return $m[1];
    }
    return '';
}

function wsa_snapshot_display_from_id(string $id): string
{
    if (preg_match('/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $id, $m)) {
        $ts = strtotime($m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5] . ':' . $m[6]);
        if ($ts !== false) {
            return date('n/j/Y g:i:s a', $ts);
        }
    }
    return $id;
}

function wsa_race_code_from_folder(string $folder): string
{
    if (preg_match('/^(R\d{2})_/', $folder, $m)) {
        return $m[1];
    }
    return '';
}

function wsa_race_number_from_code(string $raceCode): int
{
    if (preg_match('/^R(\d{2})$/', $raceCode, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function wsa_short_race_name(string $raceName, string $folder): string
{
    $name = trim($raceName);
    $prefixes = array(
        'NASCAR Cup Series at ',
        'NASCAR Cup Series ',
        'The ',
    );

    foreach ($prefixes as $prefix) {
        if (strpos($name, $prefix) === 0) {
            $name = substr($name, strlen($prefix));
        }
    }

    if ($name === 'Circuit of the Americas') {
        return 'COTA';
    }

    if ($name !== '') {
        return $name;
    }

    $code = wsa_race_code_from_folder($folder);
    $fallback = $folder;
    if ($code !== '') {
        $fallback = preg_replace('/^' . preg_quote($code, '/') . '_/', '', $fallback);
    }
    $fallback = preg_replace('/_\d+$/', '', (string)$fallback);
    $fallback = str_replace('_', ' ', (string)$fallback);
    return trim((string)$fallback);
}

function wsa_find_summary_row(array $classificationSummary, string $raceCode): array
{
    if (!isset($classificationSummary['rows']) || !is_array($classificationSummary['rows'])) {
        return array();
    }

    foreach ($classificationSummary['rows'] as $row) {
        if (is_array($row) && (string)($row['race_code'] ?? '') === $raceCode) {
            return $row;
        }
    }

    return array();
}

function wsa_build_pair_history_index(array $pairHistory): array
{
    $index = array();

    if (!isset($pairHistory['rows']) || !is_array($pairHistory['rows'])) {
        return $index;
    }

    foreach ($pairHistory['rows'] as $row) {
        if (!is_array($row)) continue;
        $raceCode = (string)($row['race_code'] ?? '');
        $previous = (string)($row['previous_snapshot'] ?? '');
        $current = (string)($row['current_snapshot'] ?? '');
        if ($raceCode === '' || $previous === '' || $current === '') continue;
        $index[$raceCode . '|' . $previous . '|' . $current] = $row;
    }

    return $index;
}

function wsa_find_pair_history_row(array $pairHistoryIndex, string $raceCode, string $previousSnapshot, string $currentSnapshot): array
{
    $key = $raceCode . '|' . $previousSnapshot . '|' . $currentSnapshot;
    if (isset($pairHistoryIndex[$key]) && is_array($pairHistoryIndex[$key])) {
        return $pairHistoryIndex[$key];
    }
    return array();
}

function wsa_load_race_pair_history_rows(string $raceDir): array
{
    $data = wsa_read_json($raceDir . '/mrl_impact_pair_history.json');
    if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
        return array();
    }
    return $data['rows'];
}

function wsa_add_race_pair_history_to_index(array &$pairHistoryIndex, string $raceCode, array $rows): void
{
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $previous = (string)($row['previous_snapshot'] ?? '');
        $current = (string)($row['current_snapshot'] ?? '');
        if ($raceCode === '' || $previous === '' || $current === '') continue;
        $pairHistoryIndex[$raceCode . '|' . $previous . '|' . $current] = $row;
    }
}

function wsa_pair_history_latest_row(array $pairHistoryIndex, string $raceCode, array $snapshots): array
{
    $count = count($snapshots);
    if ($count < 2) {
        return array();
    }

    $previous = (string)($snapshots[$count - 2]['snapshot_file'] ?? '');
    $current = (string)($snapshots[$count - 1]['snapshot_file'] ?? '');
    return wsa_find_pair_history_row($pairHistoryIndex, $raceCode, $previous, $current);
}

function wsa_normalize_snapshot_list(string $raceDir): array
{
    $items = array();
    $files = glob($raceDir . '/snapshot_*.html');
    if (!is_array($files)) {
        return $items;
    }

    sort($files, SORT_STRING);

    foreach ($files as $file) {
        $base = basename($file);
        $id = wsa_snapshot_id_from_file($base);
        $summaryFile = 'snapshot_summary_' . $id . '.txt';
        $summaryPath = dirname($file) . '/' . $summaryFile;

        $items[] = array(
            'snapshot_id' => $id,
            'snapshot_file' => $base,
            'snapshot_display' => wsa_snapshot_display_from_id($id),
            'modified' => date('n/j/Y g:i:s a', filemtime($file) ?: time()),
            'size_bytes' => filesize($file) ?: 0,
            'summary_file' => is_file($summaryPath) ? $summaryFile : '',
            'summary_present' => is_file($summaryPath),
        );
    }

    return $items;
}

function wsa_snapshot_by_file(array $snapshots, string $file): array
{
    foreach ($snapshots as $snapshot) {
        if ((string)($snapshot['snapshot_file'] ?? '') === $file) {
            return $snapshot;
        }
    }
    return array();
}

function wsa_snapshot_file_in_list(array $snapshots, string $file): bool
{
    if ($file === '') {
        return false;
    }

    foreach ($snapshots as $snapshot) {
        if ((string)($snapshot['snapshot_file'] ?? '') === $file) {
            return true;
        }
    }

    return false;
}

function wsa_summary_pair_is_valid(array $summary, array $snapshots): bool
{
    $previous = (string)($summary['previous_snapshot'] ?? '');
    $current = (string)($summary['current_snapshot'] ?? '');

    if ($previous === '' || $current === '') {
        return false;
    }

    return wsa_snapshot_file_in_list($snapshots, $previous) && wsa_snapshot_file_in_list($snapshots, $current);
}

function wsa_normalize_change_status_label(string $label, bool $mrlImpact, bool $pendingReview): string
{
    if ($pendingReview && !$mrlImpact && stripos($label, 'MRL Impact') !== false) {
        return 'Pending Review - No MRL Impact';
    }

    return $label;
}

function wsa_build_adjacent_revision_events(array $race, array $summary, array $pairHistoryIndex): array
{
    $events = array();
    $snapshots = $race['snapshots'];
    $count = count($snapshots);

    if ($count < 2) {
        return $events;
    }

    $summaryPrev = (string)($summary['previous_snapshot'] ?? '');
    $summaryCur = (string)($summary['current_snapshot'] ?? '');

    for ($i = 1; $i < $count; $i++) {
        $old = $snapshots[$i - 1];
        $new = $snapshots[$i];
        $oldFile = (string)$old['snapshot_file'];
        $newFile = (string)$new['snapshot_file'];
        $pairSummary = wsa_find_pair_history_row($pairHistoryIndex, (string)$race['race_code'], $oldFile, $newFile);
        if (empty($pairSummary) && $summaryPrev !== '' && $summaryCur !== '' && $summaryPrev === $oldFile && $summaryCur === $newFile) {
            $pairSummary = $summary;
        }

        $matched = !empty($pairSummary);

        $mrlImpact = $matched ? wsa_bool($pairSummary['mrl_impact'] ?? $pairSummary['impact'] ?? false) : false;
        $changedAll = $matched ? (int)($pairSummary['changed_all_drivers_count'] ?? 0) : null;
        $changedMrlListed = $matched ? (int)($pairSummary['changed_mrl_listed_drivers_count'] ?? 0) : null;
        $changedSegmentPicked = $matched ? (int)($pairSummary['changed_segment_picked_drivers_count'] ?? 0) : null;
        $changeStatus = $matched ? (string)($pairSummary['change_status'] ?? '') : 'snapshot_pair_only_no_classifier_row';
        $changeStatusLabel = $matched ? (string)($pairSummary['change_status_label'] ?? '') : 'No classifier row for this pair';
        $changeStatusLabel = wsa_normalize_change_status_label($changeStatusLabel, $mrlImpact, (string)$race['status'] === 'pending_review');

        $events[] = array(
            'event_id' => (string)$new['snapshot_id'],
            'event_display' => (string)$new['snapshot_display'],
            'event_type' => 'direct_race_result_revision',
            'source_type' => 'direct',
            'race_code' => (string)$race['race_code'],
            'race_number' => (int)$race['race_number'],
            'race_name' => (string)$race['race_name'],
            'short_name' => (string)$race['short_name'],
            'race_status' => (string)$race['status'],
            'previous_snapshot' => $oldFile,
            'current_snapshot' => $newFile,
            'previous_snapshot_id' => (string)$old['snapshot_id'],
            'current_snapshot_id' => (string)$new['snapshot_id'],
            'classification_matched' => $matched,
            'mrl_impact' => $mrlImpact,
            'standings_effect' => $mrlImpact ? 'direct_week_and_downstream_indirect' : 'none_detected_for_mrl_standings',
            'changed_all_drivers_count' => $changedAll,
            'changed_mrl_listed_drivers_count' => $changedMrlListed,
            'changed_segment_picked_drivers_count' => $changedSegmentPicked,
            'change_status' => $changeStatus,
            'change_status_label' => $changeStatusLabel,
            'pending_review' => (string)$race['status'] === 'pending_review',
            'changed_driver_details' => $matched && isset($pairSummary['changed_driver_details']) && is_array($pairSummary['changed_driver_details']) ? $pairSummary['changed_driver_details'] : array(),
            'notes' => $matched ? array() : array('Snapshot pair exists, but no pair-level classifier history row was found for this exact pair.'),
        );
    }

    return $events;
}

function wsa_build_indirect_candidates(array $directEvents, array $races): array
{
    $items = array();

    foreach ($directEvents as $event) {
        if (!wsa_bool($event['mrl_impact'] ?? false)) {
            continue;
        }

        $sourceRaceNumber = (int)($event['race_number'] ?? 0);
        $sourceEventId = (string)($event['event_id'] ?? '');

        foreach ($races as $race) {
            $raceNumber = (int)($race['race_number'] ?? 0);
            if ($raceNumber < $sourceRaceNumber) {
                continue;
            }
            if ((int)($race['snapshot_count'] ?? 0) < 1) {
                continue;
            }

            // League/audit convention: pending-review weeks are not released yet, so they
            // do not exist for revision-chain purposes and should not be listed as
            // downstream indirect candidates.
            $raceStatus = (string)($race['status'] ?? '');
            $isPendingReview = $raceStatus === 'pending_review' || wsa_bool($race['pending_review'] ?? false) || wsa_bool($race['under_review_flag'] ?? false);
            if ($isPendingReview) {
                continue;
            }

            $sourceType = ($raceNumber === $sourceRaceNumber) ? 'direct' : 'indirect';
            $effectLabel = ($sourceType === 'direct') ? 'Direct source week' : 'Indirect downstream week';

            $items[] = array(
                'event_id' => $sourceEventId . '_' . (string)($race['race_code'] ?? ''),
                'caused_by_event_id' => $sourceEventId,
                'caused_by_race_code' => (string)($event['race_code'] ?? ''),
                'caused_by_race_name' => (string)($event['short_name'] ?? ''),
                'affected_race_code' => (string)($race['race_code'] ?? ''),
                'affected_race_number' => $raceNumber,
                'affected_race_name' => (string)($race['short_name'] ?? ''),
                'source_type' => $sourceType,
                'effect_label' => $effectLabel,
                'affected_status' => $raceStatus,
                'pending_review' => false,
                'generated_snapshot_needed' => true,
                'generated_snapshot_id_suggestion' => $sourceEventId !== '' ? $sourceEventId . '_' . (string)($race['race_code'] ?? '') : '',
            );
        }
    }

    return $items;
}

function wsa_scan_year(string $baseDir, string $year): array
{
    $yearDir = $baseDir . '/' . $year;
    $classificationSummaryPath = $baseDir . '/_race_results_classification_summary.json';
    $classificationLastRunPath = $baseDir . '/_race_results_classification_last_run.json';
    $classificationSummary = wsa_read_json($classificationSummaryPath);
    if (!is_array($classificationSummary)) {
        $classificationSummary = array();
    }

    $pairHistoryPath = $baseDir . '/_race_results_pair_classification_history.json';
    $pairHistory = wsa_read_json($pairHistoryPath);
    if (!is_array($pairHistory)) {
        $pairHistory = array();
    }
    $pairHistoryIndex = wsa_build_pair_history_index($pairHistory);

    $folderPaths = glob($yearDir . '/R[0-9][0-9]_*', GLOB_ONLYDIR);
    if (!is_array($folderPaths)) {
        $folderPaths = array();
    }
    sort($folderPaths, SORT_STRING);

    $races = array();
    $directEvents = array();
    $gaps = array();

    foreach ($folderPaths as $raceDir) {
        $folder = basename($raceDir);
        $raceCode = wsa_race_code_from_folder($folder);
        $raceNumber = wsa_race_number_from_code($raceCode);
        $meta = wsa_read_json($raceDir . '/_meta.json');
        if (!is_array($meta)) {
            $meta = array();
        }

        $raceName = (string)($meta['race_name'] ?? '');
        $shortName = wsa_short_race_name($raceName, $folder);
        $raceId = (string)($meta['race_id'] ?? '');
        $snapshots = wsa_normalize_snapshot_list($raceDir);
        $snapshotCount = count($snapshots);
        $firstSnapshot = $snapshotCount > 0 ? $snapshots[0] : array();
        $latestSnapshot = $snapshotCount > 0 ? $snapshots[$snapshotCount - 1] : array();
        $superseded = array();
        if ($snapshotCount > 1) {
            for ($i = 0; $i < $snapshotCount - 1; $i++) {
                $superseded[] = $snapshots[$i];
            }
        }

        $underReview = is_file($raceDir . '/under_review.flag');
        $status = $underReview ? 'pending_review' : ($snapshotCount > 0 ? 'released_or_captured' : 'no_snapshot');
        $summary = wsa_find_summary_row($classificationSummary, $raceCode);
        $localMrlImpact = wsa_read_json($raceDir . '/mrl_impact_summary.json');
        $localAllImpact = wsa_read_json($raceDir . '/all_driver_impact_summary.json');
        $revisionMeta = wsa_read_json($raceDir . '/revision_meta.json');
        $localPairHistoryRows = wsa_load_race_pair_history_rows($raceDir);
        wsa_add_race_pair_history_to_index($pairHistoryIndex, $raceCode, $localPairHistoryRows);
        $latestPairHistoryRow = wsa_pair_history_latest_row($pairHistoryIndex, $raceCode, $snapshots);

        if (!empty($latestPairHistoryRow)) {
            $summary = $latestPairHistoryRow;
        }

        if (empty($summary) && is_array($localMrlImpact)) {
            $summary = $localMrlImpact;
            if (!isset($summary['race_code'])) {
                $summary['race_code'] = $raceCode;
            }
        }

        $hasClassificationArtifact = !empty($summary) || is_array($localMrlImpact) || is_array($localAllImpact) || !empty($localPairHistoryRows);
        $classificationPairValid = !empty($summary) && wsa_summary_pair_is_valid($summary, $snapshots);
        $classificationStale = $hasClassificationArtifact && !$classificationPairValid && !empty($summary);

        $mrlImpact = $classificationPairValid ? wsa_bool($summary['mrl_impact'] ?? $summary['impact'] ?? false) : false;
        $changedAll = ($classificationPairValid && isset($summary['changed_all_drivers_count'])) ? (int)$summary['changed_all_drivers_count'] : null;
        $changedMrlListed = ($classificationPairValid && isset($summary['changed_mrl_listed_drivers_count'])) ? (int)$summary['changed_mrl_listed_drivers_count'] : null;
        $changedSegmentPicked = ($classificationPairValid && isset($summary['changed_segment_picked_drivers_count'])) ? (int)$summary['changed_segment_picked_drivers_count'] : null;
        $changeStatus = $classificationPairValid ? (string)($summary['change_status'] ?? '') : '';
        $changeStatusLabel = $classificationPairValid ? (string)($summary['change_status_label'] ?? '') : '';

        if ($classificationStale) {
            $changeStatus = 'stale_or_mismatched_classifier_artifact';
            $changeStatusLabel = 'Stale or mismatched classifier artifact';
        }

        $changeStatusLabel = wsa_normalize_change_status_label($changeStatusLabel, $mrlImpact, $underReview);

        $race = array(
            'race_code' => $raceCode,
            'race_number' => $raceNumber,
            'race_id' => $raceId,
            'race_name' => $raceName,
            'short_name' => $shortName,
            'folder' => $folder,
            'folder_rel' => $year . '/' . $folder,
            'status' => $status,
            'pending_review' => $underReview,
            'under_review_flag' => $underReview,
            'snapshot_count' => $snapshotCount,
            'snapshots' => $snapshots,
            'first_snapshot' => $firstSnapshot,
            'latest_snapshot' => $latestSnapshot,
            'current_snapshot' => $latestSnapshot,
            'superseded_snapshots' => $superseded,
            'has_classification' => $classificationPairValid,
            'has_classification_artifact' => $hasClassificationArtifact,
            'classification_pair_valid' => $classificationPairValid,
            'classification_stale_or_mismatched' => $classificationStale,
            'mrl_impact' => $mrlImpact,
            'changed_all_drivers_count' => $changedAll,
            'changed_mrl_listed_drivers_count' => $changedMrlListed,
            'changed_segment_picked_drivers_count' => $changedSegmentPicked,
            'change_status' => $changeStatus,
            'change_status_label' => $changeStatusLabel,
            'previous_snapshot' => (string)($summary['previous_snapshot'] ?? ''),
            'classified_current_snapshot' => (string)($summary['current_snapshot'] ?? ''),
            'revision_meta_present' => is_array($revisionMeta),
            'revision_meta' => is_array($revisionMeta) ? $revisionMeta : array(),
            'artifact_files' => array(
                'meta' => wsa_file_info($raceDir . '/_meta.json'),
                'final_table_hash' => wsa_file_info($raceDir . '/final_table_hash.txt'),
                'mrl_impact_summary' => wsa_file_info($raceDir . '/mrl_impact_summary.json'),
                'all_driver_impact_summary' => wsa_file_info($raceDir . '/all_driver_impact_summary.json'),
                'mrl_impact_pair_history' => wsa_file_info($raceDir . '/mrl_impact_pair_history.json'),
                'revision_meta' => wsa_file_info($raceDir . '/revision_meta.json'),
                'under_review_flag' => wsa_file_info($raceDir . '/under_review.flag'),
            ),
        );

        if ($snapshotCount < 1) {
            $gaps[] = array(
                'race_code' => $raceCode,
                'race_name' => $shortName,
                'gap_type' => 'missing_snapshot',
                'message' => 'Race folder exists but no saved snapshot_*.html was found.',
            );
        }

        if ($snapshotCount > 1 && !$hasClassificationArtifact) {
            $gaps[] = array(
                'race_code' => $raceCode,
                'race_name' => $shortName,
                'gap_type' => 'missing_classification_for_revision_pair',
                'message' => 'Multiple snapshots exist but no classifier summary artifact was found.',
            );
        }

        $events = wsa_build_adjacent_revision_events($race, $summary, $pairHistoryIndex);
        foreach ($events as $event) {
            $directEvents[] = $event;
        }

        $races[] = $race;
    }

    usort($races, function ($a, $b) {
        return (int)($a['race_number'] ?? 0) <=> (int)($b['race_number'] ?? 0);
    });

    usort($directEvents, function ($a, $b) {
        return strcmp((string)($a['event_id'] ?? ''), (string)($b['event_id'] ?? ''));
    });

    $indirectCandidates = wsa_build_indirect_candidates($directEvents, $races);

    $counts = array(
        'race_folders' => count($races),
        'races_with_snapshots' => 0,
        'pending_review' => 0,
        'released_or_captured' => 0,
        'direct_revision_events' => count($directEvents),
        'direct_mrl_impact_events' => 0,
        'indirect_candidates' => count($indirectCandidates),
        'superseded_snapshots' => 0,
        'gaps' => count($gaps),
    );

    foreach ($races as $race) {
        if ((int)($race['snapshot_count'] ?? 0) > 0) {
            $counts['races_with_snapshots']++;
        }
        if ((string)($race['status'] ?? '') === 'pending_review') {
            $counts['pending_review']++;
        }
        if ((string)($race['status'] ?? '') === 'released_or_captured') {
            $counts['released_or_captured']++;
        }
        $counts['superseded_snapshots'] += count($race['superseded_snapshots'] ?? array());
    }

    foreach ($directEvents as $event) {
        if (wsa_bool($event['mrl_impact'] ?? false)) {
            $counts['direct_mrl_impact_events']++;
        }
    }

    $index = array(
        'signature' => WSAUDIT_SIGNATURE,
        'version' => WSAUDIT_VERSION,
        'generated_at' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get(),
        'source' => 'weekly_standings_audit_builder.php',
        'scope' => 'local_mrl_artifacts_only',
        'notes' => array(
            'This helper does not call ESPN or fetch remote pages.',
            'Pending review is stored as metadata and does not block audit-chain discovery.',
            'Direct events come from saved race snapshot pairs. Pair-level classifier history is preferred when present.',
            'Indirect candidates are projected only from direct events with MRL Impact YES.',
            'Existing history may have gaps if weekly standings snapshots were not being archived at the time.',
        ),
        'year' => $year,
        'base_dir' => $baseDir,
        'year_dir' => $yearDir,
        'output_file' => WSAUDIT_OUTPUT_FILE,
        'input_files' => array(
            'classification_summary' => array_merge(array('path' => basename($classificationSummaryPath)), wsa_file_info($classificationSummaryPath)),
            'classification_last_run' => array_merge(array('path' => basename($classificationLastRunPath)), wsa_file_info($classificationLastRunPath)),
            'pair_classification_history' => array_merge(array('path' => basename($pairHistoryPath)), wsa_file_info($pairHistoryPath)),
            'year_index' => array_merge(array('path' => $year . '/_year_index.json'), wsa_file_info($yearDir . '/_year_index.json')),
            'weekly_standings_php' => array_merge(array('path' => 'weekly_standings.php'), wsa_file_info($baseDir . '/weekly_standings.php')),
        ),
        'counts' => $counts,
        'races' => $races,
        'direct_revision_events' => $directEvents,
        'indirect_candidates' => $indirectCandidates,
        'gaps' => $gaps,
    );

    return $index;
}

function wsa_render_badge($text, $class = ''): string
{
    return '<span class="badge ' . wsa_h($class) . '">' . wsa_h($text) . '</span>';
}

$baseDir = __DIR__;
$year = isset($_GET['year']) ? preg_replace('/[^0-9]/', '', (string)$_GET['year']) : date('Y');
if ($year === '' || !is_dir($baseDir . '/' . $year)) {
    $year = '2026';
}
if (!is_dir($baseDir . '/' . $year)) {
    $dirs = glob($baseDir . '/20[0-9][0-9]', GLOB_ONLYDIR);
    if (is_array($dirs) && count($dirs) > 0) {
        sort($dirs, SORT_STRING);
        $year = basename($dirs[count($dirs) - 1]);
    }
}

$index = wsa_scan_year($baseDir, $year);
$outputPath = $baseDir . '/' . WSAUDIT_OUTPUT_FILE;
$writeRequested = isset($_GET['write']) && (string)$_GET['write'] === '1';
$writeResult = null;

if ($writeRequested) {
    $writeResult = wsa_write_json($outputPath, $index);
    $index['write_result'] = array(
        'requested' => true,
        'ok' => $writeResult,
        'path' => $outputPath,
        'file' => WSAUDIT_OUTPUT_FILE,
        'written_at' => date('Y-m-d H:i:s'),
    );
}

if (isset($_GET['format']) && (string)$_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$docRoot = strtolower($_SERVER['DOCUMENT_ROOT'] ?? '');
$isTestSite = strpos($host, 'testphp8') !== false || strpos($docRoot, 'testphp8') !== false;
$siteLabel = $isTestSite ? 'TESTPHP8 DEMO SITE' : 'LIVE MRL SITE';
$siteSubLabel = $host !== '' ? $host : 'local file system';
$sitePill = $isTestSite ? 'Demo site' : 'Production site';
if ($isTestSite) {
    $sandboxFile = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/sandbox.html';
    if ($sandboxFile !== '' && is_file($sandboxFile)) {
        require_once $sandboxFile;
    }
}

$counts = $index['counts'];
$jsonPreview = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($jsonPreview === false) {
    $jsonPreview = '{"error":"Unable to encode audit index preview."}';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Weekly Standings Audit Builder</title>
<style>
:root {
    --bg: #111;
    --panel: #1b1b1b;
    --panel2: #242424;
    --text: #f2f2f2;
    --muted: #bcbcbc;
    --gold: #ffd28a;
    --line: #3a3a3a;
    --green-bg: #1f4b34;
    --green-border: #438d61;
    --green-text: #78e89e;
    --red-bg: #5c1e1e;
    --red-border: #a64b4b;
    --red-text: #ff9a9a;
    --blue: #1769cc;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: Arial, Helvetica, sans-serif;
    font-size: 15px;
    line-height: 1.32;
}
a { color: #8cbcff; }
.wrap { max-width: 1900px; margin: 0 auto; padding: 14px 24px 28px; }
.site-id {
    border: 1px solid rgba(120,232,158,.42);
    background: linear-gradient(180deg, rgba(31,75,52,.55), rgba(23,23,23,.95));
    border-radius: 18px;
    padding: 14px 18px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
}
.site-id.demo { border-color: rgba(255,210,138,.55); background: linear-gradient(180deg, rgba(74,58,24,.55), rgba(23,23,23,.95)); }
.site-title { color: #fff; font-size: 28px; font-weight: 900; letter-spacing: .12em; line-height: 1; }
.site-sub { color: var(--muted); margin-top: 3px; font-size: 14px; }
.site-pill { display: inline-block; border-radius: 999px; padding: 6px 13px; font-weight: 800; color: var(--green-text); background: rgba(31,75,52,.75); border: 1px solid var(--green-border); white-space: nowrap; }
.site-id.demo .site-pill { color: var(--gold); background: rgba(74,58,24,.75); border-color: #8c6f2f; }
.header {
    border: 1px solid var(--line);
    background: linear-gradient(180deg, #202020, #171717);
    border-radius: 18px;
    padding: 18px;
    margin-bottom: 18px;
}
h1 { color: var(--gold); font-size: 32px; margin: 0 0 6px; }
h2 { color: var(--gold); font-size: 24px; margin: 22px 0 10px; }
h3 { color: var(--gold); margin: 0 0 8px; font-size: 18px; }
.sub { color: var(--muted); font-size: 14px; }
.actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 18px; }
.btn {
    display: inline-block;
    background: var(--blue);
    color: #fff;
    text-decoration: none;
    padding: 8px 13px;
    border-radius: 999px;
    font-weight: 700;
    border: 1px solid rgba(255,255,255,.18);
}
.btn.secondary { background: #333; }
.btn.warn { background: #6d4a16; }
.cards { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin: 14px 0; }
.card {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 13px;
}
.card .label { color: var(--gold); font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }
.card .value { font-size: 24px; font-weight: 800; margin-top: 4px; }
.card .note { color: var(--muted); margin-top: 4px; font-size: 13px; }
.notice {
    border: 1px solid var(--line);
    background: #181818;
    border-radius: 14px;
    padding: 12px 14px;
    margin: 14px 0;
}
.notice.good { border-color: var(--green-border); background: rgba(31,75,52,.35); }
.notice.bad { border-color: var(--red-border); background: rgba(92,30,30,.45); }
.explainer {
    border: 1px solid rgba(255,210,138,.28);
    background: linear-gradient(180deg, rgba(45,38,22,.62), rgba(24,24,24,.96));
    border-radius: 16px;
    padding: 14px 16px;
    margin: 16px 0 18px;
}
.explainer-title { color: var(--gold); font-size: 20px; font-weight: 900; margin: 0 0 8px; }
.explainer-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-top: 10px; }
.explainer-item { background: rgba(255,255,255,.035); border: 1px solid rgba(255,255,255,.08); border-radius: 12px; padding: 10px; }
.explainer-item strong { color: #fff; display: block; margin-bottom: 4px; }
.explainer-item span { color: var(--muted); font-size: 13px; }
.timeline-rule { color: var(--muted); margin-top: 10px; }
.tablebox {
    border: 1px solid var(--line);
    border-radius: 16px;
    overflow-x: auto;
    background: var(--panel);
    margin-bottom: 22px;
}
table { border-collapse: collapse; width: 100%; min-width: 980px; font-size: 14px; }
th, td { border-bottom: 1px solid var(--line); padding: 8px 10px; text-align: left; vertical-align: top; }
th { color: var(--gold); background: #171717; font-weight: 800; }
.col-race { min-width: 120px; }
.col-event { min-width: 170px; }
.col-snapshot-pair { min-width: 330px; }
.col-classification { min-width: 180px; }
.col-metric, .metric { white-space: nowrap; text-align: center; }
.nowrap { white-space: nowrap; }
tr:nth-child(even) td { background: rgba(255,255,255,.025); }
.muted { color: var(--muted); }
.mono { font-family: Consolas, Monaco, monospace; }
.badge {
    display: inline-block;
    border-radius: 999px;
    padding: 3px 8px;
    border: 1px solid var(--line);
    background: #2b2b2b;
    color: var(--text);
    font-weight: 800;
    white-space: nowrap;
}
.badge.good { background: var(--green-bg); border-color: var(--green-border); color: var(--green-text); }
.badge.bad { background: var(--red-bg); border-color: var(--red-border); color: var(--red-text); }
.badge.pending { background: #4a3a18; border-color: #8c6f2f; color: var(--gold); }
.badge.warn { background: #5b4610; border-color: #d7a932; color: #ffe39c; }
.badge.direct { background: #193b5c; border-color: #3d79b2; color: #a9d5ff; }
.badge.indirect { background: #3d285a; border-color: #7150a1; color: #d9c3ff; }
pre {
    background: #101010;
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 14px;
    overflow: auto;
    max-height: 520px;
    font-family: Consolas, Monaco, monospace;
    font-size: 12px;
}
.footer { color: var(--muted); font-size: 13px; margin: 30px 0 10px; }
@media (max-width: 1100px) {
    .explainer-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 900px) {
    .wrap { padding: 12px; }
    h1 { font-size: 28px; }
    .site-title { font-size: 22px; }
    .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .card .value { font-size: 22px; }
}
@media (max-width: 560px) {
    .cards { grid-template-columns: 1fr; }
    .explainer-grid { grid-template-columns: 1fr; }
    .header { padding: 18px; }
}
</style>
</head>
<body>
<div class="wrap">
    <div class="site-id <?= $isTestSite ? 'demo' : '' ?>">
        <div>
            <div class="site-title"><?= wsa_h($siteLabel) ?></div>
            <div class="site-sub"><?= wsa_h($siteSubLabel) ?></div>
        </div>
        <div class="site-pill"><?= wsa_h($sitePill) ?></div>
    </div>
    <div class="header">
        <h1>Weekly Standings Audit Builder</h1>
        <div class="sub">
            <?= wsa_h(WSAUDIT_SIGNATURE) ?> · Generated <?= wsa_h($index['generated_at']) ?> · Year <?= wsa_h($year) ?><br>
            Scope: local MRL artifacts only. No ESPN requests, no remote fetches.
        </div>
        <div class="actions">
            <a class="btn secondary" href="?year=<?= wsa_h($year) ?>">Scan / Refresh</a>
            <a class="btn warn" href="?year=<?= wsa_h($year) ?>&amp;write=1">Save <?= wsa_h(WSAUDIT_OUTPUT_FILE) ?></a>
            <a class="btn" href="?year=<?= wsa_h($year) ?>&amp;format=json">Open JSON</a>
            <?php if (is_file($outputPath)): ?>
                <a class="btn secondary" href="<?= wsa_h(WSAUDIT_OUTPUT_FILE) ?>?cb=<?= time() ?>">Open Saved Index</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($writeRequested): ?>
        <?php if ($writeResult): ?>
            <div class="notice good">Saved <?= wsa_h(WSAUDIT_OUTPUT_FILE) ?> successfully.</div>
        <?php else: ?>
            <div class="notice bad">Save failed. Check file permissions for the race_results folder.</div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="cards">
        <div class="card"><div class="label">Race folders</div><div class="value"><?= (int)$counts['race_folders'] ?></div><div class="note">Regular season race folders scanned</div></div>
        <div class="card"><div class="label">With snapshots</div><div class="value"><?= (int)$counts['races_with_snapshots'] ?></div><div class="note">Saved snapshot_*.html present</div></div>
        <div class="card"><div class="label">Pending review</div><div class="value"><?= (int)$counts['pending_review'] ?></div><div class="note">Tracked as metadata only</div></div>
        <div class="card"><div class="label">Direct events</div><div class="value"><?= (int)$counts['direct_revision_events'] ?></div><div class="note">Adjacent snapshot pairs</div></div>
        <div class="card"><div class="label">Indirect candidates</div><div class="value"><?= (int)$counts['indirect_candidates'] ?></div><div class="note">Projected from MRL-impact direct events</div></div>
    </div>

    <div class="notice">
        This first pass builds the audit foundation. Existing race revisions are discovered from saved snapshot pairs and classifier files. Weekly standings historical snapshots are expected to be sparse or missing until we add the future snapshot package process.
    </div>

    <div class="explainer">
        <div class="explainer-title">What this means</div>
        <div class="muted">Use this as the audit/timeline glossary before chasing alternate timelines.</div>
        <div class="explainer-grid">
            <div class="explainer-item"><strong>Direct event</strong><span>The race-result snapshot pair changed. This is the source event.</span></div>
            <div class="explainer-item"><strong>MRL Impact</strong><span>A segment-picked driver changed net scoring, so standings may need revision.</span></div>
            <div class="explainer-item"><strong>Indirect candidate</strong><span>A released downstream weekly standings page may need regeneration because an earlier direct event had MRL impact.</span></div>
            <div class="explainer-item"><strong>Pending Review</strong><span>Unreleased. For revision-chain purposes, this week does not exist yet and is skipped.</span></div>
            <div class="explainer-item"><strong>Renamed bad snapshots</strong><span>Files no longer matching snapshot_*.html are ignored and removed from the audit timeline.</span></div>
        </div>
        <div class="timeline-rule">Timeline rule: released/captured snapshots are part of the official timeline; pending-review snapshots are not; direct MRL-impact events can project indirect candidates only into released downstream weeks.</div>
    </div>

    <h2>Race Inventory</h2>
    <div class="tablebox">
        <table>
            <thead>
                <tr>
                    <th class="col-race">Race</th>
                    <th>Status</th>
                    <th class="metric">Snapshots</th>
                    <th>Current snapshot</th>
                    <th class="metric">Superseded</th>
                    <th class="metric">MRL Impact</th>
                    <th class="metric">Changed All</th>
                    <th class="metric">MRL-Listed</th>
                    <th class="metric">Segment-Picked</th>
                    <th>Change Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($index['races'] as $race): ?>
                    <tr>
                        <td class="col-race"><strong><?= wsa_h($race['race_code']) ?></strong><br><span class="nowrap"><?= wsa_h($race['short_name']) ?></span></td>
                        <td>
                            <?php if ($race['status'] === 'pending_review'): ?>
                                <?= wsa_render_badge('Pending review', 'pending') ?>
                            <?php elseif ($race['status'] === 'released_or_captured'): ?>
                                <?= wsa_render_badge('Captured', 'good') ?>
                            <?php else: ?>
                                <?= wsa_render_badge('No snapshot') ?>
                            <?php endif; ?>
                        </td>
                        <td class="metric">
                            <?php if ((int)$race['snapshot_count'] > 1): ?>
                                <?= wsa_render_badge((string)(int)$race['snapshot_count'], 'warn') ?>
                            <?php else: ?>
                                <?= (int)$race['snapshot_count'] ?>
                            <?php endif; ?>
                        </td>
                        <td class="mono"><?= wsa_h($race['latest_snapshot']['snapshot_id'] ?? '') ?><br><span class="muted"><?= wsa_h($race['latest_snapshot']['snapshot_display'] ?? '') ?></span></td>
                        <td class="metric"><?= count($race['superseded_snapshots']) ?></td>
                        <td class="metric"><?= $race['mrl_impact'] ? wsa_render_badge('YES', 'bad') : wsa_render_badge('NO', 'good') ?></td>
                        <td class="metric"><?= $race['changed_all_drivers_count'] === null ? '<span class="muted">—</span>' : (int)$race['changed_all_drivers_count'] ?></td>
                        <td class="metric"><?= $race['changed_mrl_listed_drivers_count'] === null ? '<span class="muted">—</span>' : (int)$race['changed_mrl_listed_drivers_count'] ?></td>
                        <td class="metric"><?= $race['changed_segment_picked_drivers_count'] === null ? '<span class="muted">—</span>' : (int)$race['changed_segment_picked_drivers_count'] ?></td>
                        <td><?= wsa_h($race['change_status_label'] ?: $race['change_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="notice">
        Snapshot counts greater than 1 are highlighted because they create snapshot-pair history. A "Snapshot pair only" event means the files exist, but no pair-level classifier history row was found for that exact pair. After running the pair-history backfill, this should disappear for existing saved pairs.
    </div>

    <h2>Direct Revision Events</h2>
    <div class="tablebox">
        <table>
            <thead>
                <tr>
                    <th class="col-event">Event</th>
                    <th class="col-race">Race</th>
                    <th class="col-snapshot-pair">Snapshot Pair</th>
                    <th class="col-classification">Classification</th>
                    <th class="metric">MRL Impact</th>
                    <th>Standings Effect</th>
                    <th>Changed Driver Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($index['direct_revision_events'])): ?>
                    <tr><td colspan="7" class="muted">No direct revision events found.</td></tr>
                <?php endif; ?>
                <?php $lastDirectRaceCode = ''; ?>
                <?php foreach ($index['direct_revision_events'] as $event): ?>
                    <?php
                        $directRaceCode = (string)($event['race_code'] ?? '');
                        $showDirectRace = ($directRaceCode !== $lastDirectRaceCode);
                        $lastDirectRaceCode = $directRaceCode;
                    ?>
                    <tr>
                        <td class="mono col-event"><?= wsa_h($event['event_id']) ?><br><span class="muted"><?= wsa_h($event['event_display']) ?></span></td>
                        <td class="col-race">
                            <?php if ($showDirectRace): ?>
                                <strong><?= wsa_h($event['race_code']) ?></strong><br><span class="nowrap"><?= wsa_h($event['short_name']) ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="mono col-snapshot-pair"><?= wsa_h($event['previous_snapshot']) ?><br>→ <?= wsa_h($event['current_snapshot']) ?></td>
                        <td class="col-classification">
                            <?php if ($event['classification_matched']): ?>
                                <?= wsa_render_badge('Matched', 'good') ?>
                            <?php else: ?>
                                <?= wsa_render_badge('Snapshot pair only', 'warn') ?>
                            <?php endif; ?>
                            <br><span class="muted"><?= wsa_h($event['change_status_label']) ?></span>
                        </td>
                        <td class="metric"><?= $event['mrl_impact'] ? wsa_render_badge('YES', 'bad') : wsa_render_badge('NO', 'good') ?></td>
                        <td><?= wsa_h($event['standings_effect']) ?></td>
                        <td>
                            <?php
                            $details = $event['changed_driver_details'];
                            if (empty($details)) {
                                echo '<span class="muted">—</span>';
                            } else {
                                $pieces = array();
                                foreach ($details as $detail) {
                                    if (!is_array($detail)) {
                                        continue;
                                    }
                                    $driver = (string)($detail['driver'] ?? '');
                                    $netDelta = $detail['delta']['net'] ?? null;
                                    $pieces[] = $driver . ($netDelta !== null ? ' (' . (($netDelta > 0) ? '+' : '') . $netDelta . ')' : '');
                                }
                                echo wsa_h(implode(', ', $pieces));
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2>Indirect Weekly Standings Candidates</h2>
    <div class="tablebox">
        <table>
            <thead>
                <tr>
                    <th>Caused By</th>
                    <th>Affected Week/Race</th>
                    <th>Type</th>
                    <th>Status Metadata</th>
                    <th>Suggested Generated ID</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($index['indirect_candidates'])): ?>
                    <tr><td colspan="5" class="muted">No indirect candidates yet. This is expected unless a direct MRL-impact revision exists.</td></tr>
                <?php endif; ?>
                <?php foreach ($index['indirect_candidates'] as $item): ?>
                    <tr>
                        <td><strong><?= wsa_h($item['caused_by_race_code']) ?></strong> <?= wsa_h($item['caused_by_race_name']) ?><br><span class="mono muted"><?= wsa_h($item['caused_by_event_id']) ?></span></td>
                        <td><strong><?= wsa_h($item['affected_race_code']) ?></strong> <?= wsa_h($item['affected_race_name']) ?></td>
                        <td><?= $item['source_type'] === 'direct' ? wsa_render_badge('Direct', 'direct') : wsa_render_badge('Indirect', 'indirect') ?><br><span class="muted"><?= wsa_h($item['effect_label']) ?></span></td>
                        <td><?= $item['pending_review'] ? wsa_render_badge('Pending review', 'pending') : wsa_render_badge($item['affected_status'], 'good') ?></td>
                        <td class="mono"><?= wsa_h($item['generated_snapshot_id_suggestion']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2>Gaps / Missing Context</h2>
    <div class="tablebox">
        <table>
            <thead><tr><th>Race</th><th>Gap Type</th><th>Message</th></tr></thead>
            <tbody>
                <?php if (empty($index['gaps'])): ?>
                    <tr><td colspan="3" class="muted">No scan gaps detected.</td></tr>
                <?php endif; ?>
                <?php foreach ($index['gaps'] as $gap): ?>
                    <tr>
                        <td><strong><?= wsa_h($gap['race_code']) ?></strong><br><?= wsa_h($gap['race_name']) ?></td>
                        <td class="mono"><?= wsa_h($gap['gap_type']) ?></td>
                        <td><?= wsa_h($gap['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2>Raw Audit Index Preview</h2>
    <pre><?= wsa_h($jsonPreview) ?></pre>

    <div class="footer">
        <?= wsa_h(WSAUDIT_SIGNATURE) ?> · <?= wsa_h($baseDir) ?> · Output file: <?= wsa_h(WSAUDIT_OUTPUT_FILE) ?>
    </div>
</div>
</body>
</html>
