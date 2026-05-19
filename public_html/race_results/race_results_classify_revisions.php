<?php
declare(strict_types=1);

/**
 * race_results_classify_revisions.php
 *
 * VERSION: v006
 * LAST MODIFIED: 5/19/2026 2:26:02 am
 *
 * DESCRIPTION:
 * MRL-only revision classification layer plus admin/audit diff output.
 * This script works alongside race_results_revision_monitor.php.
 * It does not detect revisions by itself; instead, it classifies whether
 * an already-detected race-table revision changes MRL-relevant scoring,
 * while also writing a full all-driver audit summary for admin review.
 *
 * CURRENT SCOPE OF THIS V006 BUILD:
 * - bootstrap environment
 * - discover candidate races
 * - discover snapshot pairs
 * - derive segment-based MRL driver pool
 * - extract per-driver scoring from stored snapshots
 * - build normalized MRL-only datasets
 * - hash and compare those datasets
 * - build full all-driver comparison datasets
 * - write MRL-only JSON / hash / diff artifacts
 * - write all-driver audit JSON / diff artifacts
 * - support both CLI and manual web testing
 * - support safe include/call usage from race_results_revision_monitor.php
 * - write a trusted full-year classification summary JSON for dashboard use
 * - avoid auto-running when included by revision monitor or other scripts
 * - include clearer revision status fields in classifier output
 * - separate all-driver, MRL-listed-driver, and segment-picked-driver change counts
 * - include changed-driver detail records in reports and JSON summaries
 *
 * CHANGELOG:
 *
 * v006 (5/19/2026)
 * - NEW: Added changed_driver_details / changedDriverDetails arrays identifying each changed scoring-table driver.
 * - NEW: Changed-driver details include old/new PTS, BONUS, PENALTY, NET values plus MRL-listed and segment-picked flags.
 * - CHANGE: Removed redundant visible Classified column from the web report while preserving classified=true/false in JSON artifacts.
 * - CHANGE: Removed visible Status column from the web report while preserving status_label / revision_status in JSON artifacts.
 * - CHANGE: Added detailed changed-driver section to the web report.
 * - CHANGE: Removed Changed Drivers from the main summary table to keep the report width manageable.
 * - CHANGE: MRL Impact now displays NO as the green/good state and YES as the red/attention state.
 *
 * v005 (5/19/2026)
 * - NEW: Added MRL-listed driver classification between all-driver changes and active segment-picked changes.
 * - NEW: Added changed_mrl_listed_drivers_count / changed_segment_picked_drivers_count fields to summaries.
 * - NEW: Added compatible changedAllDriversCount alias for revision monitor consumption.
 * - CHANGE: Web/CLI report now distinguishes Changed All, MRL-Listed, and Segment-Picked drivers.
 * - CHANGE: Classifier display trims "NASCAR Cup Series at" from visible race names while preserving ESPN names in artifacts.

 *
 * v004 (5/18/2026)
 * - NEW: Added RRCR_VERSION / RRCR_SIGNATURE constants for clear report and artifact identity.
 * - NEW: Added trusted full-year classification summary artifact: _race_results_classification_summary.json.
 * - NEW: Added last-run classification artifact: _race_results_classification_last_run.json.
 * - NEW: Added revision status metadata in classifier output, including pending_review, revision_status, display_tag, and under_review_flag.
 * - CHANGE: Auto-run now only occurs when this file is executed directly, preventing accidental full classifier execution when included by race_results_revision_monitor.php.
 * - CHANGE: Web and CLI reports now identify the classifier version/signature and use clearer status wording.
 * - CHANGE: Single-race runs no longer overwrite the trusted full-year dashboard summary.
 *
 * v003 (4/28/2026)
 * - NEW: Added all-driver comparison layer so admin review can see every ESPN scoring-table change, not just MRL-driver changes.
 * - NEW: Added all_driver_impact_summary.json artifact with full-table changed-driver counts and snapshot references.
 * - NEW: Added all_driver_impact_diff.txt artifact showing PTS / BONUS / PENALTY / NET old->new values for every changed driver.
 * - NEW: Added all_driver_impact_data_old.json and all_driver_impact_data_new.json artifacts for audit/debug comparison snapshots.
 * - CHANGE: Clarified web output column label from "Changed Drivers" to "Changed MRL Drivers".
 * - CHANGE: Added separate "Changed All Drivers" column to web summary.
 * - CHANGE: rrcr_run_single_race() and rrcr_classify_race_revision() now return both MRL-only and all-driver comparison summaries for downstream monitor/UI use.
 *
 * v002 (4/26/2026)
 * - FIX: race_results_engine.php is now loaded before rr_docroot_from_script_dir() is called, preventing a browser 500 fatal error during bootstrap.
 * - CHANGE: Added explicit docroot resolution bootstrap so CLI/browser execution and include/call usage share the same config-loading path safely.
 * - CHANGE: Added RRCR_AUTO_RUN constant guard so the file can be included by race_results_revision_monitor.php without auto-executing the full script.
 * - CHANGE: Added rrcr_run_single_race() helper for direct per-race classification calls from the revision monitor.
 * - CHANGE: Added mrl_impact_summary.json output artifact alongside existing JSON/hash/diff outputs.
 *
 * v001 (4/15/2026)
 * - NEW: Initial full-file skeleton for race_results_classify_revisions.php.
 * - NEW: Added bootstrap, candidate race discovery, snapshot pairing, driver-pool, dataset, hash, compare, diff, artifact, and run orchestration function structure.
 * - NEW: Added CLI/web execution support with conservative defaults and no destructive behavior.
 * - NEW: Added deterministic JSON writing helpers and human-readable result reporting.
 * - CHANGE: Expanded diff summary output to include PTS / BONUS / PENALTY / NET old→new values with signed deltas for each changed tracked driver.
 * - CHANGE: Tightened segment driver-pool query to use user_picks only and explicitly include pick_type values SEG, RD, and LP.
 * - CHANGE: Simplified segment driver-pool query to use all drivers found in user_picks for the target raceYear and segment, without distinguishing pick_type.
 * - CHANGE: Replaced Unicode arrow characters in diff output with ASCII '->' for safer cross-editor readability.
 * - CHANGE: Added driver pool size to the human-readable diff summary output.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const RRCR_VERSION = 'v006';
const RRCR_SIGNATURE = 'RACE_RESULTS_CLASSIFY_REVISIONS v006';
const RRCR_SUMMARY_FILE = '_race_results_classification_summary.json';
const RRCR_LAST_RUN_FILE = '_race_results_classification_last_run.json';

if (!defined('RRCR_AUTO_RUN')) {
    define('RRCR_AUTO_RUN', true);
}

require_once __DIR__ . '/race_results_engine.php';

$docRoot = rr_docroot_from_script_dir(__DIR__);

if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}

require_once $docRoot . '/config.php';
require_once $docRoot . '/config_mrl.php';
require_once $docRoot . '/functions_mrl.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';
require_once __DIR__ . '/race_results_team_helper.php';

if (function_exists('disableCaching')) {
    disableCaching();
}

function rrcr_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rrcr_log(string $message, bool $echo = true): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;

    if (PHP_SAPI === 'cli') {
        echo $line . PHP_EOL;
        return;
    }

    if ($echo) {
        echo rrcr_h($line) . "<br>\n";
    }
}

function rrcr_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function rrcr_current_year(): string
{
    return date('Y');
}

function rrcr_race_results_base_dir(): string
{
    return __DIR__;
}

function rrcr_bootstrap(): array
{
    $baseDir = rrcr_race_results_base_dir();

    $options = [
        'year' => rrcr_current_year(),
        'race_code' => '',
        'verbose' => false,
        'write_artifacts' => true,
        'base_dir' => $baseDir,
    ];

    if (rrcr_is_cli()) {
        global $argv;

        if (isset($argv[1]) && preg_match('/^\d{4}$/', (string)$argv[1])) {
            $options['year'] = (string)$argv[1];
        }

        if (isset($argv[2]) && preg_match('/^R\d{2}$/', (string)$argv[2])) {
            $options['race_code'] = strtoupper((string)$argv[2]);
        }

        if (isset($argv) && is_array($argv) && in_array('--verbose', $argv, true)) {
            $options['verbose'] = true;
        }

        if (isset($argv) && is_array($argv) && in_array('--dry-run', $argv, true)) {
            $options['write_artifacts'] = false;
        }
    } else {
        if (isset($_GET['year']) && preg_match('/^\d{4}$/', (string)$_GET['year'])) {
            $options['year'] = (string)$_GET['year'];
        }

        if (isset($_GET['race']) && preg_match('/^R\d{2}$/', strtoupper((string)$_GET['race']))) {
            $options['race_code'] = strtoupper((string)$_GET['race']);
        }

        if (isset($_GET['verbose'])) {
            $options['verbose'] = true;
        }

        if (isset($_GET['dry_run'])) {
            $options['write_artifacts'] = false;
        }
    }

    return $options;
}

function rrcr_segment_from_race_number(int $raceNumber): string
{
    if ($raceNumber >= 1 && $raceNumber <= 8) return 'S1';
    if ($raceNumber >= 9 && $raceNumber <= 17) return 'S2';
    if ($raceNumber >= 18 && $raceNumber <= 26) return 'S3';
    if ($raceNumber >= 27 && $raceNumber <= 36) return 'S4';
    return 'S1';
}

function rrcr_load_year_index(string $year, string $baseDir): array
{
    $yearFolder = rtrim($baseDir, '/\\') . '/' . $year;
    $indexFile = $yearFolder . '/_year_index.json';

    if (!is_file($indexFile)) {
        return [];
    }

    $data = rr_load_json($indexFile);
    if (!is_array($data) || !isset($data['races']) || !is_array($data['races'])) {
        return [];
    }

    return $data;
}

function rrcr_points_races_from_index(array $yearIndex, string $year, string $baseDir): array
{
    $rows = [];
    $yearFolder = rtrim($baseDir, '/\\') . '/' . $year;

    foreach ($yearIndex['races'] as $raceId => $row) {
        if (!is_array($row)) continue;

        $kind = (string)($row['kind'] ?? '');
        $number = (int)($row['number'] ?? 0);
        $folder = (string)($row['folder'] ?? '');
        $raceName = (string)($row['race_name'] ?? '');
        $raceUrl = (string)($row['race_url'] ?? '');

        if ($kind !== 'R' || $number <= 0 || $folder === '') {
            continue;
        }

        $rows[] = [
            'raceId' => (string)$raceId,
            'raceCode' => 'R' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'number' => $number,
            'segment' => rrcr_segment_from_race_number($number),
            'raceName' => $raceName,
            'raceUrl' => $raceUrl,
            'folder' => $folder,
            'raceFolder' => $yearFolder . '/' . $folder,
            'year' => $year,
        ];
    }

    usort($rows, function ($a, $b) {
        if ((int)$a['number'] !== (int)$b['number']) {
            return ((int)$a['number'] <=> (int)$b['number']);
        }
        return strcmp((string)$a['raceCode'], (string)$b['raceCode']);
    });

    return $rows;
}

function rrcr_get_candidate_races(string $year, string $baseDir, string $onlyRaceCode = ''): array
{
    $yearIndex = rrcr_load_year_index($year, $baseDir);
    if (empty($yearIndex)) {
        return [];
    }

    $races = rrcr_points_races_from_index($yearIndex, $year, $baseDir);
    $candidates = [];

    foreach ($races as $race) {
        $raceFolder = (string)$race['raceFolder'];
        if (!is_dir($raceFolder)) continue;
        if ($onlyRaceCode !== '' && (string)$race['raceCode'] !== $onlyRaceCode) continue;

        $snapshotFiles = glob($raceFolder . '/snapshot_*.html');
        if (!is_array($snapshotFiles) || count($snapshotFiles) < 2) continue;

        $candidates[] = $race;
    }

    return $candidates;
}

function rrcr_get_race_snapshots(string $raceFolder): array
{
    $files = glob(rtrim($raceFolder, '/\\') . '/snapshot_*.html');
    if (!is_array($files) || empty($files)) {
        return [];
    }
    sort($files, SORT_STRING);
    return array_values($files);
}

function rrcr_get_comparison_pair(array $snapshots): array
{
    $count = count($snapshots);
    if ($count < 2) {
        return ['previous' => '', 'current' => ''];
    }

    return [
        'previous' => (string)$snapshots[$count - 2],
        'current' => (string)$snapshots[$count - 1],
    ];
}

function rrcr_normalize_driver_name(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name);
    return (string)$name;
}

function rrcr_short_race_name(string $raceName): string
{
    $label = trim($raceName);
    $label = preg_replace('/^NASCAR Cup Series at\s+/i', '', $label);
    $label = preg_replace('/^NASCAR Cup Series\s+/i', '', (string)$label);
    $label = trim((string)$label);
    return $label !== '' ? $label : $raceName;
}

function rrcr_table_columns(PDO $dbo, string $tableName): array
{
    $columns = [];

    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
        return [];
    }

    try {
        $stmt = $dbo->query('SHOW COLUMNS FROM `' . $tableName . '`');
        if (!$stmt) return [];
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $columns[] = $field;
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $columns;
}

function rrcr_pick_first_existing_column(array $columns, array $candidates): string
{
    $lookup = [];
    foreach ($columns as $column) {
        $lookup[strtolower((string)$column)] = (string)$column;
    }

    foreach ($candidates as $candidate) {
        $key = strtolower((string)$candidate);
        if (isset($lookup[$key])) {
            return $lookup[$key];
        }
    }

    return '';
}

function rrcr_get_mrl_listed_driver_pool(string $raceYear, PDO $dbo): array
{
    $drivers = [];

    $columns = rrcr_table_columns($dbo, 'drivers');
    if (!empty($columns)) {
        $nameColumn = rrcr_pick_first_existing_column($columns, [
            'driverName', 'driver_name', 'driver', 'name', 'fullName', 'full_name', 'driverFullName'
        ]);
        $yearColumn = rrcr_pick_first_existing_column($columns, [
            'raceYear', 'race_year', 'year', 'season', 'driverYear', 'driver_year'
        ]);
        $activeColumn = rrcr_pick_first_existing_column($columns, [
            'active', 'isActive', 'is_active', 'enabled'
        ]);

        if ($nameColumn !== '') {
            try {
                $sql = 'SELECT `' . $nameColumn . '` AS driver_name FROM `drivers`';
                $where = [];
                $params = [];

                if ($yearColumn !== '') {
                    $where[] = '`' . $yearColumn . '` = :raceYear';
                    $params[':raceYear'] = $raceYear;
                }

                if ($activeColumn !== '') {
                    $where[] = '(`' . $activeColumn . '` = 1 OR `' . $activeColumn . '` = "1" OR `' . $activeColumn . '` = "Y" OR `' . $activeColumn . '` = "YES" OR `' . $activeColumn . '` = "true")';
                }

                if (!empty($where)) {
                    $sql .= ' WHERE ' . implode(' AND ', $where);
                }

                $stmt = $dbo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (!is_array($row)) continue;
                        $name = rrcr_normalize_driver_name((string)($row['driver_name'] ?? ''));
                        if ($name !== '') {
                            $drivers[$name] = true;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Fall through to user_picks backup source below.
            }
        }
    }

    // Backup source: all drivers selected anywhere in the requested season.
    // This keeps the classification useful even if the drivers table schema changes.
    if (empty($drivers)) {
        try {
            $sql = "
                SELECT driverA, driverB, driverC, driverD
                FROM user_picks
                WHERE raceYear = :raceYear
            ";
            $stmt = $dbo->prepare($sql);
            $stmt->execute([':raceYear' => $raceYear]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    foreach (['driverA', 'driverB', 'driverC', 'driverD'] as $field) {
                        $name = rrcr_normalize_driver_name((string)($row[$field] ?? ''));
                        if ($name !== '') {
                            $drivers[$name] = true;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            return [];
        }
    }

    $pool = array_keys($drivers);
    usort($pool, function ($a, $b) {
        return strcasecmp((string)$a, (string)$b);
    });

    return $pool;
}

function rrcr_get_segment_driver_pool(string $raceYear, string $segment, PDO $dbo): array
{
    $drivers = [];

    $sql = "
        SELECT driverA, driverB, driverC, driverD
        FROM user_picks
        WHERE raceYear = :raceYear
          AND segment = :segment
    ";

    $stmt = $dbo->prepare($sql);
    $stmt->execute([
        ':raceYear' => $raceYear,
        ':segment' => $segment,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }

    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        foreach (['driverA', 'driverB', 'driverC', 'driverD'] as $field) {
            $name = rrcr_normalize_driver_name((string)($row[$field] ?? ''));
            if ($name !== '') {
                $drivers[$name] = true;
            }
        }
    }

    $pool = array_keys($drivers);
    usort($pool, function ($a, $b) {
        return strcasecmp((string)$a, (string)$b);
    });

    return $pool;
}

function rrcr_extract_driver_scoring_from_snapshot(string $snapshotFile): array
{
    $scores = [];
    if (!is_file($snapshotFile)) {
        return $scores;
    }

    $driverPoints = rrs_load_snapshot_driver_points($snapshotFile);
    if (!is_array($driverPoints)) {
        return $scores;
    }

    foreach ($driverPoints as $driverName => $row) {
        $name = rrcr_normalize_driver_name((string)$driverName);
        if ($name === '' || !is_array($row)) continue;

        $scores[$name] = [
            'pts' => (int)($row['pts'] ?? 0),
            'bonus' => (int)($row['bonus'] ?? 0),
            'penalty' => (int)($row['penalty'] ?? 0),
            'net' => (int)($row['net'] ?? 0),
        ];
    }

    ksort($scores, SORT_STRING | SORT_FLAG_CASE);
    return $scores;
}

function rrcr_json_encode_pretty(array $data): string
{
    return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function rrcr_now_string(): string
{
    return date('Y-m-d H:i:s');
}

function rrcr_read_json_file(string $path): array
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

function rrcr_status_label_from_code(string $statusCode): string
{
    $statusCode = trim($statusCode);

    if ($statusCode === '') {
        return 'Classified';
    }

    $map = [
        'pending_review' => 'Pending Review',
        'detected_no_mrl_impact' => 'Detected - No MRL Impact',
        'classified' => 'Classified',
        'accepted' => 'Accepted',
        'superseded' => 'Superseded',
    ];

    if (isset($map[$statusCode])) {
        return $map[$statusCode];
    }

    $label = str_replace(['_', '-'], ' ', $statusCode);
    $label = preg_replace('/\s+/', ' ', $label);
    return ucwords((string)$label);
}

function rrcr_revision_status_for_race(string $raceFolder): array
{
    $base = rtrim($raceFolder, '/\\');
    $metaPath = $base . '/revision_meta.json';
    $flagPath = $base . '/under_review.flag';

    $meta = rrcr_read_json_file($metaPath);
    $hasMeta = !empty($meta);
    $hasFlag = is_file($flagPath);

    $statusCode = (string)($meta['status'] ?? '');
    $pendingReview = $hasFlag || !empty($meta['pending_review']) || $statusCode === 'pending_review';
    $displayTag = (string)($meta['display_tag'] ?? '');
    $revisionDetected = !empty($meta['revision_detected']) || $hasMeta || $hasFlag;

    $statusLabel = $pendingReview ? 'Pending Review' : rrcr_status_label_from_code($statusCode);

    if (!$revisionDetected && !$pendingReview) {
        $statusLabel = 'Classified';
    }

    if ($displayTag !== '' && strpos($statusLabel, $displayTag) === false) {
        $statusLabel .= ' (' . $displayTag . ')';
    }

    return [
        'revision_detected' => $revisionDetected,
        'pending_review' => $pendingReview,
        'revision_status' => $statusCode,
        'status_label' => $statusLabel,
        'display_tag' => $displayTag,
        'under_review_flag' => $hasFlag,
        'revision_meta_exists' => $hasMeta,
        'revision_meta_path' => $hasMeta ? $metaPath : '',
        'under_review_flag_path' => $hasFlag ? $flagPath : '',
    ];
}

function rrcr_build_mrl_dataset(array $driverPool, array $allDriverScores, array $meta = []): array
{
    $drivers = [];
    $sortedPool = array_values(array_unique($driverPool));
    sort($sortedPool, SORT_STRING | SORT_FLAG_CASE);

    foreach ($sortedPool as $driverName) {
        $name = rrcr_normalize_driver_name((string)$driverName);
        if ($name === '') continue;

        $row = isset($allDriverScores[$name]) && is_array($allDriverScores[$name])
            ? $allDriverScores[$name]
            : ['pts' => 0, 'bonus' => 0, 'penalty' => 0, 'net' => 0];

        $drivers[$name] = [
            'pts' => (int)($row['pts'] ?? 0),
            'bonus' => (int)($row['bonus'] ?? 0),
            'penalty' => (int)($row['penalty'] ?? 0),
            'net' => (int)($row['net'] ?? 0),
        ];
    }

    return [
        'year' => (string)($meta['year'] ?? ''),
        'race_code' => (string)($meta['race_code'] ?? ''),
        'segment' => (string)($meta['segment'] ?? ''),
        'race_id' => (string)($meta['race_id'] ?? ''),
        'source_snapshot' => (string)($meta['source_snapshot'] ?? ''),
        'drivers' => $drivers,
    ];
}

function rrcr_build_all_driver_dataset(array $allDriverScores, array $meta = []): array
{
    $drivers = [];
    $allNames = array_keys($allDriverScores);
    sort($allNames, SORT_STRING | SORT_FLAG_CASE);

    foreach ($allNames as $driverName) {
        $name = rrcr_normalize_driver_name((string)$driverName);
        if ($name === '') continue;

        $row = isset($allDriverScores[$name]) && is_array($allDriverScores[$name])
            ? $allDriverScores[$name]
            : ['pts' => 0, 'bonus' => 0, 'penalty' => 0, 'net' => 0];

        $drivers[$name] = [
            'pts' => (int)($row['pts'] ?? 0),
            'bonus' => (int)($row['bonus'] ?? 0),
            'penalty' => (int)($row['penalty'] ?? 0),
            'net' => (int)($row['net'] ?? 0),
        ];
    }

    return [
        'year' => (string)($meta['year'] ?? ''),
        'race_code' => (string)($meta['race_code'] ?? ''),
        'segment' => (string)($meta['segment'] ?? ''),
        'race_id' => (string)($meta['race_id'] ?? ''),
        'source_snapshot' => (string)($meta['source_snapshot'] ?? ''),
        'drivers' => $drivers,
    ];
}

function rrcr_hash_mrl_dataset(array $dataset): string
{
    return hash('sha256', rrcr_json_encode_pretty($dataset));
}

function rrcr_compare_datasets(array $oldDataset, array $newDataset): array
{
    $changedDrivers = [];
    $oldDrivers = isset($oldDataset['drivers']) && is_array($oldDataset['drivers']) ? $oldDataset['drivers'] : [];
    $newDrivers = isset($newDataset['drivers']) && is_array($newDataset['drivers']) ? $newDataset['drivers'] : [];

    $allNames = array_unique(array_merge(array_keys($oldDrivers), array_keys($newDrivers)));
    sort($allNames, SORT_STRING | SORT_FLAG_CASE);

    foreach ($allNames as $driverName) {
        $oldRow = isset($oldDrivers[$driverName]) ? $oldDrivers[$driverName] : ['pts' => 0, 'bonus' => 0, 'penalty' => 0, 'net' => 0];
        $newRow = isset($newDrivers[$driverName]) ? $newDrivers[$driverName] : ['pts' => 0, 'bonus' => 0, 'penalty' => 0, 'net' => 0];

        if (
            (int)$oldRow['pts'] !== (int)$newRow['pts'] ||
            (int)$oldRow['bonus'] !== (int)$newRow['bonus'] ||
            (int)$oldRow['penalty'] !== (int)$newRow['penalty'] ||
            (int)$oldRow['net'] !== (int)$newRow['net']
        ) {
            $changedDrivers[] = [
                'driver' => $driverName,
                'old' => $oldRow,
                'new' => $newRow,
            ];
        }
    }

    return [
        'impact' => !empty($changedDrivers),
        'changedDrivers' => $changedDrivers,
        'changedDriversCount' => count($changedDrivers),
    ];
}


function rrcr_bool_yes_no(bool $value): string
{
    return $value ? 'YES' : 'NO';
}

function rrcr_driver_pool_lookup(array $driverPool): array
{
    $lookup = [];

    foreach ($driverPool as $driverName) {
        $name = rrcr_normalize_driver_name((string)$driverName);
        if ($name !== '') {
            $lookup[strtolower($name)] = true;
        }
    }

    return $lookup;
}

function rrcr_build_changed_driver_details(array $allDriverComparison, array $mrlListedDriverPool, array $segmentDriverPool): array
{
    $details = [];
    $mrlListedLookup = rrcr_driver_pool_lookup($mrlListedDriverPool);
    $segmentLookup = rrcr_driver_pool_lookup($segmentDriverPool);
    $changedDrivers = isset($allDriverComparison['changedDrivers']) && is_array($allDriverComparison['changedDrivers'])
        ? $allDriverComparison['changedDrivers']
        : [];

    foreach ($changedDrivers as $row) {
        if (!is_array($row)) continue;

        $driverName = rrcr_normalize_driver_name((string)($row['driver'] ?? ''));
        if ($driverName === '') continue;

        $old = isset($row['old']) && is_array($row['old']) ? $row['old'] : [];
        $new = isset($row['new']) && is_array($row['new']) ? $row['new'] : [];

        $oldPts = (int)($old['pts'] ?? 0);
        $newPts = (int)($new['pts'] ?? 0);
        $oldBonus = (int)($old['bonus'] ?? 0);
        $newBonus = (int)($new['bonus'] ?? 0);
        $oldPenalty = (int)($old['penalty'] ?? 0);
        $newPenalty = (int)($new['penalty'] ?? 0);
        $oldNet = (int)($old['net'] ?? 0);
        $newNet = (int)($new['net'] ?? 0);
        $key = strtolower($driverName);

        $details[] = [
            'driver' => $driverName,
            'mrl_listed' => isset($mrlListedLookup[$key]),
            'segment_picked' => isset($segmentLookup[$key]),
            'old' => [
                'pts' => $oldPts,
                'bonus' => $oldBonus,
                'penalty' => $oldPenalty,
                'net' => $oldNet,
            ],
            'new' => [
                'pts' => $newPts,
                'bonus' => $newBonus,
                'penalty' => $newPenalty,
                'net' => $newNet,
            ],
            'delta' => [
                'pts' => $newPts - $oldPts,
                'bonus' => $newBonus - $oldBonus,
                'penalty' => $newPenalty - $oldPenalty,
                'net' => $newNet - $oldNet,
            ],
        ];
    }

    usort($details, function ($a, $b) {
        return strcasecmp((string)($a['driver'] ?? ''), (string)($b['driver'] ?? ''));
    });

    return $details;
}

function rrcr_changed_driver_details_compact(array $details): string
{
    if (empty($details)) {
        return 'none';
    }

    $parts = [];
    foreach ($details as $row) {
        if (!is_array($row)) continue;
        $driverName = (string)($row['driver'] ?? '');
        if ($driverName === '') continue;

        $tags = [];
        $tags[] = !empty($row['mrl_listed']) ? 'MRL-listed' : 'non-MRL';
        $tags[] = !empty($row['segment_picked']) ? 'segment-picked' : 'not segment-picked';

        $delta = isset($row['delta']) && is_array($row['delta']) ? $row['delta'] : [];
        $netDelta = (int)($delta['net'] ?? 0);
        $netText = $netDelta === 0 ? 'net 0' : 'net ' . ($netDelta > 0 ? '+' : '') . (string)$netDelta;

        $parts[] = $driverName . ' (' . implode(', ', $tags) . ', ' . $netText . ')';
    }

    return !empty($parts) ? implode('; ', $parts) : 'none';
}

function rrcr_format_signed_delta(int $oldValue, int $newValue): string
{
    $delta = $newValue - $oldValue;
    if ($delta > 0) {
        return '+' . (string)$delta;
    }
    return (string)$delta;
}

function rrcr_build_diff_summary(array $comparison, array $meta): string
{
    $label = isset($meta['label']) && $meta['label'] !== '' ? (string)$meta['label'] : 'MRL driver';

    $lines = [];
    $lines[] = strtoupper($label) . ' IMPACT CLASSIFICATION';
    $lines[] = trim((string)($meta['year'] ?? '') . ' ' . (string)($meta['race_code'] ?? '') . ' ' . (string)($meta['race_name'] ?? ''));
    $lines[] = '';

    if (isset($meta['driver_pool_count'])) {
        $lines[] = 'Driver pool size: ' . (int)$meta['driver_pool_count'] . ' drivers';
        $lines[] = '';
    }

    if (!empty($comparison['changedDrivers'])) {
        $lines[] = 'Changed ' . strtolower($label) . 's:';
        $lines[] = '';

        foreach ($comparison['changedDrivers'] as $row) {
            $driverName = (string)($row['driver'] ?? '');
            $old = isset($row['old']) && is_array($row['old']) ? $row['old'] : [];
            $new = isset($row['new']) && is_array($row['new']) ? $row['new'] : [];

            $oldPts = (int)($old['pts'] ?? 0);
            $newPts = (int)($new['pts'] ?? 0);
            $oldBonus = (int)($old['bonus'] ?? 0);
            $newBonus = (int)($new['bonus'] ?? 0);
            $oldPenalty = (int)($old['penalty'] ?? 0);
            $newPenalty = (int)($new['penalty'] ?? 0);
            $oldNet = (int)($old['net'] ?? 0);
            $newNet = (int)($new['net'] ?? 0);

            $lines[] = $driverName;
            $lines[] = 'PTS: ' . $oldPts . ' -> ' . $newPts . ' (' . rrcr_format_signed_delta($oldPts, $newPts) . ')';
            $lines[] = 'BONUS: ' . $oldBonus . ' -> ' . $newBonus . ' (' . rrcr_format_signed_delta($oldBonus, $newBonus) . ')';
            $lines[] = 'PENALTY: ' . $oldPenalty . ' -> ' . $newPenalty . ' (' . rrcr_format_signed_delta($oldPenalty, $newPenalty) . ')';
            $lines[] = 'NET: ' . $oldNet . ' -> ' . $newNet . ' (' . rrcr_format_signed_delta($oldNet, $newNet) . ')';
            $lines[] = '';
        }
    } else {
        $lines[] = 'Changed ' . strtolower($label) . 's:';
        $lines[] = 'none';
        $lines[] = '';
    }

    $lines[] = 'Impact classification:';
    $lines[] = !empty($comparison['impact']) ? 'YES' : 'NO';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function rrcr_write_artifacts(
    string $raceFolder,
    array $currentDataset,
    string $hash,
    string $diffText,
    array $summaryData,
    array $allDriverOldDataset,
    array $allDriverNewDataset,
    string $allDriverDiffText,
    array $allDriverSummaryData,
    bool $writeArtifacts = true
): array {
    $base = rtrim($raceFolder, '/\\');

    $files = [
        'data' => $base . '/mrl_impact_data.json',
        'hash' => $base . '/mrl_impact_hash.txt',
        'diff' => $base . '/mrl_impact_diff.txt',
        'summary' => $base . '/mrl_impact_summary.json',
        'all_driver_old_data' => $base . '/all_driver_impact_data_old.json',
        'all_driver_new_data' => $base . '/all_driver_impact_data_new.json',
        'all_driver_diff' => $base . '/all_driver_impact_diff.txt',
        'all_driver_summary' => $base . '/all_driver_impact_summary.json',
    ];

    if ($writeArtifacts) {
        file_put_contents($files['data'], rrcr_json_encode_pretty($currentDataset) . PHP_EOL);
        file_put_contents($files['hash'], $hash . PHP_EOL);
        file_put_contents($files['diff'], $diffText);
        file_put_contents($files['summary'], rrcr_json_encode_pretty($summaryData) . PHP_EOL);

        file_put_contents($files['all_driver_old_data'], rrcr_json_encode_pretty($allDriverOldDataset) . PHP_EOL);
        file_put_contents($files['all_driver_new_data'], rrcr_json_encode_pretty($allDriverNewDataset) . PHP_EOL);
        file_put_contents($files['all_driver_diff'], $allDriverDiffText);
        file_put_contents($files['all_driver_summary'], rrcr_json_encode_pretty($allDriverSummaryData) . PHP_EOL);
    }

    return $files;
}

function rrcr_classify_race_revision(array $raceInfo, PDO $dbo, bool $writeArtifacts = true, bool $verbose = false): array
{
    $snapshots = rrcr_get_race_snapshots((string)$raceInfo['raceFolder']);
    $pair = rrcr_get_comparison_pair($snapshots);

    if ($pair['previous'] === '' || $pair['current'] === '') {
        $revisionStatus = rrcr_revision_status_for_race((string)$raceInfo['raceFolder']);

        return array_merge([
            'raceCode' => (string)$raceInfo['raceCode'],
            'raceName' => (string)($raceInfo['raceName'] ?? ''),
            'raceId' => (string)($raceInfo['raceId'] ?? ''),
            'raceNumber' => (int)($raceInfo['number'] ?? 0),
            'segment' => (string)($raceInfo['segment'] ?? ''),
            'raceFolder' => (string)($raceInfo['raceFolder'] ?? ''),
            'classified' => false,
            'impact' => false,
            'changedDriversCount' => 0,
            'changedSegmentPickedDriversCount' => 0,
            'changedMrlListedDriversCount' => 0,
            'changedAllDriversCount' => 0,
            'driverPoolCount' => 0,
            'mrlListedDriverPoolCount' => 0,
            'mrlListedDriverImpact' => false,
            'allDriverImpact' => false,
            'allDriverChangedCount' => 0,
            'changedDriverDetails' => [],
            'message' => 'Not enough snapshots to compare.',
        ], $revisionStatus);
    }

    $driverPool = rrcr_get_segment_driver_pool((string)$raceInfo['year'], (string)$raceInfo['segment'], $dbo);
    $mrlListedDriverPool = rrcr_get_mrl_listed_driver_pool((string)$raceInfo['year'], $dbo);
    $oldScores = rrcr_extract_driver_scoring_from_snapshot((string)$pair['previous']);
    $newScores = rrcr_extract_driver_scoring_from_snapshot((string)$pair['current']);

    $oldDataset = rrcr_build_mrl_dataset($driverPool, $oldScores, [
        'year' => (string)$raceInfo['year'],
        'race_code' => (string)$raceInfo['raceCode'],
        'segment' => (string)$raceInfo['segment'],
        'race_id' => (string)$raceInfo['raceId'],
        'source_snapshot' => basename((string)$pair['previous']),
    ]);

    $newDataset = rrcr_build_mrl_dataset($driverPool, $newScores, [
        'year' => (string)$raceInfo['year'],
        'race_code' => (string)$raceInfo['raceCode'],
        'segment' => (string)$raceInfo['segment'],
        'race_id' => (string)$raceInfo['raceId'],
        'source_snapshot' => basename((string)$pair['current']),
    ]);

    $mrlListedOldDataset = rrcr_build_mrl_dataset($mrlListedDriverPool, $oldScores, [
        'year' => (string)$raceInfo['year'],
        'race_code' => (string)$raceInfo['raceCode'],
        'segment' => (string)$raceInfo['segment'],
        'race_id' => (string)$raceInfo['raceId'],
        'source_snapshot' => basename((string)$pair['previous']),
    ]);

    $mrlListedNewDataset = rrcr_build_mrl_dataset($mrlListedDriverPool, $newScores, [
        'year' => (string)$raceInfo['year'],
        'race_code' => (string)$raceInfo['raceCode'],
        'segment' => (string)$raceInfo['segment'],
        'race_id' => (string)$raceInfo['raceId'],
        'source_snapshot' => basename((string)$pair['current']),
    ]);

    $allDriverOldDataset = rrcr_build_all_driver_dataset($oldScores, [
        'year' => (string)$raceInfo['year'],
        'race_code' => (string)$raceInfo['raceCode'],
        'segment' => (string)$raceInfo['segment'],
        'race_id' => (string)$raceInfo['raceId'],
        'source_snapshot' => basename((string)$pair['previous']),
    ]);

    $allDriverNewDataset = rrcr_build_all_driver_dataset($newScores, [
        'year' => (string)$raceInfo['year'],
        'race_code' => (string)$raceInfo['raceCode'],
        'segment' => (string)$raceInfo['segment'],
        'race_id' => (string)$raceInfo['raceId'],
        'source_snapshot' => basename((string)$pair['current']),
    ]);

    $hash = rrcr_hash_mrl_dataset($newDataset);
    $comparison = rrcr_compare_datasets($oldDataset, $newDataset);
    $mrlListedComparison = rrcr_compare_datasets($mrlListedOldDataset, $mrlListedNewDataset);
    $allDriverComparison = rrcr_compare_datasets($allDriverOldDataset, $allDriverNewDataset);
    $changedDriverDetails = rrcr_build_changed_driver_details($allDriverComparison, $mrlListedDriverPool, $driverPool);

    $summaryData = [
        'year' => (string)$raceInfo['year'],
        'race_code' => (string)$raceInfo['raceCode'],
        'segment' => (string)$raceInfo['segment'],
        'race_id' => (string)$raceInfo['raceId'],
        'race_name' => (string)$raceInfo['raceName'],
        'impact' => !empty($comparison['impact']),
        'changed_drivers_count' => (int)$comparison['changedDriversCount'],
        'changed_drivers_label' => 'Segment-picked drivers only',
        'changed_segment_picked_drivers_count' => (int)$comparison['changedDriversCount'],
        'changed_mrl_listed_drivers_count' => (int)$mrlListedComparison['changedDriversCount'],
        'changed_all_drivers_count' => (int)$allDriverComparison['changedDriversCount'],
        'mrl_listed_driver_impact' => !empty($mrlListedComparison['impact']),
        'segment_picked_driver_impact' => !empty($comparison['impact']),
        'driver_pool_count' => count($driverPool),
        'mrl_listed_driver_pool_count' => count($mrlListedDriverPool),
        'previous_snapshot' => basename((string)$pair['previous']),
        'current_snapshot' => basename((string)$pair['current']),
        'changed_driver_details' => $changedDriverDetails,
    ];

    $allDriverSummaryData = [
        'year' => (string)$raceInfo['year'],
        'race_code' => (string)$raceInfo['raceCode'],
        'segment' => (string)$raceInfo['segment'],
        'race_id' => (string)$raceInfo['raceId'],
        'race_name' => (string)$raceInfo['raceName'],
        'impact' => !empty($allDriverComparison['impact']),
        'changed_drivers_count' => (int)$allDriverComparison['changedDriversCount'],
        'changed_drivers_label' => 'All scoring-table drivers',
        'changed_mrl_listed_drivers_count' => (int)$mrlListedComparison['changedDriversCount'],
        'changed_segment_picked_drivers_count' => (int)$comparison['changedDriversCount'],
        'previous_snapshot' => basename((string)$pair['previous']),
        'current_snapshot' => basename((string)$pair['current']),
        'changed_driver_details' => $changedDriverDetails,
    ];

    $diffText = rrcr_build_diff_summary($comparison, [
        'year' => (string)$raceInfo['year'],
        'race_code' => (string)$raceInfo['raceCode'],
        'race_name' => (string)$raceInfo['raceName'],
        'driver_pool_count' => count($driverPool),
        'label' => 'Segment-picked driver',
    ]);

    $allDriverDiffText = rrcr_build_diff_summary($allDriverComparison, [
        'year' => (string)$raceInfo['year'],
        'race_code' => (string)$raceInfo['raceCode'],
        'race_name' => (string)$raceInfo['raceName'],
        'label' => 'All driver',
    ]);

    $artifactFiles = rrcr_write_artifacts(
        (string)$raceInfo['raceFolder'],
        $newDataset,
        $hash,
        $diffText,
        $summaryData,
        $allDriverOldDataset,
        $allDriverNewDataset,
        $allDriverDiffText,
        $allDriverSummaryData,
        $writeArtifacts
    );

    if ($verbose) {
        rrcr_log(
            (string)$raceInfo['raceCode']
            . ' classified. Impact=' . (!empty($comparison['impact']) ? 'YES' : 'NO')
            . ' changedSegmentPickedDrivers=' . (string)$comparison['changedDriversCount']
            . ' changedMRLListedDrivers=' . (string)$mrlListedComparison['changedDriversCount']
            . ' changedAllDrivers=' . (string)$allDriverComparison['changedDriversCount']
        );
    }

    $revisionStatus = rrcr_revision_status_for_race((string)$raceInfo['raceFolder']);

    return array_merge([
        'raceCode' => (string)$raceInfo['raceCode'],
        'raceName' => (string)$raceInfo['raceName'],
        'raceId' => (string)$raceInfo['raceId'],
        'raceNumber' => (int)$raceInfo['number'],
        'segment' => (string)$raceInfo['segment'],
        'raceFolder' => (string)$raceInfo['raceFolder'],
        'classified' => true,
        'impact' => !empty($comparison['impact']),
        'changedDriversCount' => (int)$comparison['changedDriversCount'],
        'changedSegmentPickedDriversCount' => (int)$comparison['changedDriversCount'],
        'changedMrlListedDriversCount' => (int)$mrlListedComparison['changedDriversCount'],
        'changedAllDriversCount' => (int)$allDriverComparison['changedDriversCount'],
        'driverPoolCount' => count($driverPool),
        'mrlListedDriverPoolCount' => count($mrlListedDriverPool),
        'mrlListedDriverImpact' => !empty($mrlListedComparison['impact']),
        'allDriverImpact' => !empty($allDriverComparison['impact']),
        'allDriverChangedCount' => (int)$allDriverComparison['changedDriversCount'],
        'mrlListedComparison' => $mrlListedComparison,
        'allDriverComparison' => $allDriverComparison,
        'changedDriverDetails' => $changedDriverDetails,
        'previousSnapshot' => basename((string)$pair['previous']),
        'currentSnapshot' => basename((string)$pair['current']),
        'artifactFiles' => $artifactFiles,
        'comparison' => $comparison,
        'message' => 'Classification complete.',
    ], $revisionStatus);
}

function rrcr_summarize_run_row(array $row): array
{
    return [
        'race_code' => (string)($row['raceCode'] ?? ''),
        'race_name' => rrcr_short_race_name((string)($row['raceName'] ?? '')),
        'race_name_full' => (string)($row['raceName'] ?? ''),
        'race_id' => (string)($row['raceId'] ?? ''),
        'race_number' => (int)($row['raceNumber'] ?? 0),
        'segment' => (string)($row['segment'] ?? ''),
        'classified' => !empty($row['classified']),
        'mrl_impact' => !empty($row['impact']),
        'changed_mrl_drivers_count' => (int)($row['changedDriversCount'] ?? 0),
        'changed_segment_picked_drivers_count' => (int)($row['changedSegmentPickedDriversCount'] ?? ($row['changedDriversCount'] ?? 0)),
        'mrl_listed_driver_impact' => !empty($row['mrlListedDriverImpact']),
        'changed_mrl_listed_drivers_count' => (int)($row['changedMrlListedDriversCount'] ?? 0),
        'all_driver_impact' => !empty($row['allDriverImpact']),
        'changed_all_drivers_count' => (int)($row['changedAllDriversCount'] ?? ($row['allDriverChangedCount'] ?? 0)),
        'driver_pool_count' => (int)($row['driverPoolCount'] ?? 0),
        'mrl_listed_driver_pool_count' => (int)($row['mrlListedDriverPoolCount'] ?? 0),
        'pending_review' => !empty($row['pending_review']),
        'revision_detected' => !empty($row['revision_detected']),
        'revision_status' => (string)($row['revision_status'] ?? ''),
        'status_label' => (string)($row['status_label'] ?? ''),
        'display_tag' => (string)($row['display_tag'] ?? ''),
        'under_review_flag' => !empty($row['under_review_flag']),
        'previous_snapshot' => (string)($row['previousSnapshot'] ?? ''),
        'current_snapshot' => (string)($row['currentSnapshot'] ?? ''),
        'changed_driver_details' => isset($row['changedDriverDetails']) && is_array($row['changedDriverDetails']) ? $row['changedDriverDetails'] : [],
        'message' => (string)($row['message'] ?? ''),
    ];
}

function rrcr_build_run_summary(array $results, array $options): array
{
    $runs = isset($results['runs']) && is_array($results['runs']) ? $results['runs'] : [];
    $rows = [];

    foreach ($runs as $row) {
        if (!is_array($row)) continue;
        $rows[] = rrcr_summarize_run_row($row);
    }

    $mrlListedImpactCount = 0;
    $mrlListedChangeRaceCount = 0;
    $segmentPickedChangeRaceCount = 0;

    foreach ($rows as $summaryRow) {
        if (!empty($summaryRow['mrl_listed_driver_impact'])) $mrlListedImpactCount++;
        if ((int)($summaryRow['changed_mrl_listed_drivers_count'] ?? 0) > 0) $mrlListedChangeRaceCount++;
        if ((int)($summaryRow['changed_segment_picked_drivers_count'] ?? 0) > 0) $segmentPickedChangeRaceCount++;
    }

    return [
        'signature' => RRCR_SIGNATURE,
        'version' => RRCR_VERSION,
        'generated_at' => rrcr_now_string(),
        'sapi' => PHP_SAPI,
        'source' => basename(__FILE__),
        'year' => (string)($results['year'] ?? ''),
        'race_code_filter' => (string)($results['race_code'] ?? ''),
        'is_full_year_summary' => (string)($results['race_code'] ?? '') === '',
        'write_artifacts' => !empty($options['write_artifacts']),
        'classified_count' => (int)($results['classifiedCount'] ?? 0),
        'mrl_impact_count' => (int)($results['impactCount'] ?? 0),
        'all_driver_change_race_count' => (int)($results['allDriverImpactCount'] ?? 0),
        'mrl_listed_driver_change_race_count' => $mrlListedChangeRaceCount,
        'segment_picked_driver_change_race_count' => $segmentPickedChangeRaceCount,
        'mrl_listed_driver_impact_count' => $mrlListedImpactCount,
        'candidate_count' => isset($results['candidates']) && is_array($results['candidates']) ? count($results['candidates']) : 0,
        'rows' => $rows,
    ];
}

function rrcr_write_run_summary_files(array $results, array $options): array
{
    $baseDir = (string)($options['base_dir'] ?? rrcr_race_results_base_dir());
    $baseDir = rtrim($baseDir, '/\\');
    $summary = rrcr_build_run_summary($results, $options);

    $files = [
        'last_run' => $baseDir . '/' . RRCR_LAST_RUN_FILE,
    ];

    file_put_contents($files['last_run'], rrcr_json_encode_pretty($summary) . PHP_EOL);

    if (!empty($summary['is_full_year_summary'])) {
        $files['summary'] = $baseDir . '/' . RRCR_SUMMARY_FILE;
        file_put_contents($files['summary'], rrcr_json_encode_pretty($summary) . PHP_EOL);
    }

    return $files;
}

function rrcr_should_auto_run(): bool
{
    if (!RRCR_AUTO_RUN) {
        return false;
    }

    $scriptFilename = isset($_SERVER['SCRIPT_FILENAME']) ? (string)$_SERVER['SCRIPT_FILENAME'] : '';
    if ($scriptFilename === '') {
        return true;
    }

    $scriptReal = realpath($scriptFilename);
    $thisReal = realpath(__FILE__);

    if ($scriptReal === false || $thisReal === false) {
        return basename($scriptFilename) === basename(__FILE__);
    }

    return $scriptReal === $thisReal;
}

function rrcr_run_single_race(string $year, string $raceCode, PDO $dbo, bool $writeArtifacts = true, bool $verbose = false): array
{
    $baseDir = rrcr_race_results_base_dir();
    $candidates = rrcr_get_candidate_races($year, $baseDir, $raceCode);

    if (empty($candidates)) {
        return [
            'raceCode' => $raceCode,
            'classified' => false,
            'impact' => false,
            'changedDriversCount' => 0,
            'changedSegmentPickedDriversCount' => 0,
            'changedMrlListedDriversCount' => 0,
            'changedAllDriversCount' => 0,
            'mrlListedDriverImpact' => false,
            'allDriverImpact' => false,
            'allDriverChangedCount' => 0,
            'changedDriverDetails' => [],
            'message' => 'Race not found or not enough snapshots to compare.',
            'status_label' => 'Not Classified',
            'pending_review' => false,
        ];
    }

    return rrcr_classify_race_revision($candidates[0], $dbo, $writeArtifacts, $verbose);
}

function rrcr_run(array $options, PDO $dbo): array
{
    $year = (string)$options['year'];
    $raceCode = (string)$options['race_code'];
    $verbose = !empty($options['verbose']);
    $writeArtifacts = !empty($options['write_artifacts']);
    $baseDir = (string)$options['base_dir'];

    $results = [
        'signature' => RRCR_SIGNATURE,
        'version' => RRCR_VERSION,
        'generatedAt' => rrcr_now_string(),
        'sapi' => PHP_SAPI,
        'year' => $year,
        'race_code' => $raceCode,
        'candidates' => [],
        'classifiedCount' => 0,
        'impactCount' => 0,
        'allDriverImpactCount' => 0,
        'runs' => [],
    ];

    $candidates = rrcr_get_candidate_races($year, $baseDir, $raceCode);
    $results['candidates'] = $candidates;

    if ($verbose) {
        rrcr_log('Candidates found: ' . count($candidates));
    }

    foreach ($candidates as $raceInfo) {
        $result = rrcr_classify_race_revision($raceInfo, $dbo, $writeArtifacts, $verbose);
        $results['runs'][] = $result;

        if (!empty($result['classified'])) $results['classifiedCount']++;
        if (!empty($result['impact'])) $results['impactCount']++;
        if (!empty($result['allDriverImpact'])) $results['allDriverImpactCount']++;
    }

    if ($writeArtifacts) {
        $results['summaryFiles'] = rrcr_write_run_summary_files($results, $options);
    } else {
        $results['summaryFiles'] = [];
    }

    return $results;
}

function rrcr_render_web_summary(array $results): void
{
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>MRL Revision Classification</title>';
    echo '<style>';
    echo 'body { font-family: Arial, Helvetica, sans-serif; margin: 16px; line-height: 1.35; color: #222; }';
    echo 'table { border-collapse: collapse; width: 100%; max-width: 1450px; }';
    echo 'th, td { border: 1px solid #444; padding: 6px 8px; text-align: left; vertical-align: top; }';
    echo 'th { background: #f2f2f2; }';
    echo '.yes { font-weight: bold; color: #0b6d2b; }';
    echo '.no { font-weight: bold; color: #8b0000; }';
    echo '.impact-no { font-weight: bold; color: #0b6d2b; }';
    echo '.impact-yes { font-weight: bold; color: #8b0000; }';
    echo '.muted { color: #666; font-size: 13px; }';
    echo '.badge { display: inline-block; padding: 2px 7px; border-radius: 999px; background: #eee; font-size: 12px; }';
    echo '.pending { background: #fff3cd; border: 1px solid #e0c36a; }';
    echo '.ok { background: #e6f4ea; border: 1px solid #8cc69b; }';
    echo '</style></head><body>';

    echo '<h2>MRL Revision Classification</h2>';
    echo '<p class="muted"><strong>Source:</strong> ' . rrcr_h(RRCR_SIGNATURE) . ' &nbsp; ';
    echo '<strong>Generated:</strong> ' . rrcr_h((string)($results['generatedAt'] ?? rrcr_now_string())) . ' &nbsp; ';
    echo '<strong>SAPI:</strong> ' . rrcr_h(PHP_SAPI) . '</p>';

    echo '<p><strong>Year:</strong> ' . rrcr_h($results['year']) . '</p>';
    echo '<p><strong>Classified:</strong> ' . rrcr_h((string)$results['classifiedCount']) . ' &nbsp; ';
    echo '<strong>MRL Impact:</strong> ' . rrcr_h((string)$results['impactCount']) . ' &nbsp; ';
    echo '<strong>All-Driver Changes:</strong> ' . rrcr_h((string)$results['allDriverImpactCount']) . '</p>';

    $summaryFiles = isset($results['summaryFiles']) && is_array($results['summaryFiles']) ? $results['summaryFiles'] : [];
    if (!empty($summaryFiles)) {
        echo '<p class="muted"><strong>Summary artifacts:</strong> ';
        $parts = [];
        foreach ($summaryFiles as $label => $path) {
            $parts[] = rrcr_h((string)$label) . '=' . rrcr_h(basename((string)$path));
        }
        echo implode(' &nbsp; ', $parts) . '</p>';
    }

    echo '<table>';
    echo '<tr>';
    echo '<th>Race</th><th>Race Name</th><th>MRL Impact</th><th>Changed All</th><th>MRL-Listed</th><th>Segment-Picked</th><th>Previous Snapshot</th><th>Current Snapshot</th><th>Message</th>';
    echo '</tr>';

    $runs = isset($results['runs']) && is_array($results['runs']) ? $results['runs'] : [];
    foreach ($runs as $row) {
        echo '<tr>';
        echo '<td>' . rrcr_h((string)($row['raceCode'] ?? '')) . '</td>';
        echo '<td>' . rrcr_h(rrcr_short_race_name((string)($row['raceName'] ?? ''))) . '</td>';
        echo '<td class="' . (!empty($row['impact']) ? 'impact-yes' : 'impact-no') . '">' . (!empty($row['impact']) ? 'YES' : 'NO') . '</td>';
        echo '<td>' . rrcr_h((string)($row['changedAllDriversCount'] ?? ($row['allDriverChangedCount'] ?? 0))) . '</td>';
        echo '<td>' . rrcr_h((string)($row['changedMrlListedDriversCount'] ?? 0)) . '</td>';
        echo '<td>' . rrcr_h((string)($row['changedSegmentPickedDriversCount'] ?? ($row['changedDriversCount'] ?? 0))) . '</td>';
        echo '<td>' . rrcr_h((string)($row['previousSnapshot'] ?? '')) . '</td>';
        echo '<td>' . rrcr_h((string)($row['currentSnapshot'] ?? '')) . '</td>';
        echo '<td>' . rrcr_h((string)($row['message'] ?? '')) . '</td>';
        echo '</tr>';
    }

    echo '</table>';

    $detailRows = [];
    foreach ($runs as $row) {
        if (!is_array($row)) continue;
        $changedDetails = isset($row['changedDriverDetails']) && is_array($row['changedDriverDetails']) ? $row['changedDriverDetails'] : [];
        if (!empty($changedDetails)) {
            $detailRows[] = $row;
        }
    }

    if (!empty($detailRows)) {
        echo '<h3>Changed Driver Details</h3>';
        echo '<table>';
        echo '<tr><th>Race</th><th>Driver</th><th>MRL-Listed</th><th>Segment-Picked</th><th>PTS</th><th>BONUS</th><th>PENALTY</th><th>NET</th></tr>';

        foreach ($detailRows as $row) {
            $raceCode = (string)($row['raceCode'] ?? '');
            $changedDetails = isset($row['changedDriverDetails']) && is_array($row['changedDriverDetails']) ? $row['changedDriverDetails'] : [];

            foreach ($changedDetails as $detail) {
                if (!is_array($detail)) continue;
                $old = isset($detail['old']) && is_array($detail['old']) ? $detail['old'] : [];
                $new = isset($detail['new']) && is_array($detail['new']) ? $detail['new'] : [];
                $driverName = (string)($detail['driver'] ?? '');
                if ($driverName === '') continue;

                $oldPts = (int)($old['pts'] ?? 0);
                $newPts = (int)($new['pts'] ?? 0);
                $oldBonus = (int)($old['bonus'] ?? 0);
                $newBonus = (int)($new['bonus'] ?? 0);
                $oldPenalty = (int)($old['penalty'] ?? 0);
                $newPenalty = (int)($new['penalty'] ?? 0);
                $oldNet = (int)($old['net'] ?? 0);
                $newNet = (int)($new['net'] ?? 0);

                echo '<tr>';
                echo '<td>' . rrcr_h($raceCode) . '</td>';
                echo '<td>' . rrcr_h($driverName) . '</td>';
                echo '<td>' . rrcr_h(rrcr_bool_yes_no(!empty($detail['mrl_listed']))) . '</td>';
                echo '<td>' . rrcr_h(rrcr_bool_yes_no(!empty($detail['segment_picked']))) . '</td>';
                echo '<td>' . rrcr_h((string)$oldPts . ' -> ' . (string)$newPts . ' (' . rrcr_format_signed_delta($oldPts, $newPts) . ')') . '</td>';
                echo '<td>' . rrcr_h((string)$oldBonus . ' -> ' . (string)$newBonus . ' (' . rrcr_format_signed_delta($oldBonus, $newBonus) . ')') . '</td>';
                echo '<td>' . rrcr_h((string)$oldPenalty . ' -> ' . (string)$newPenalty . ' (' . rrcr_format_signed_delta($oldPenalty, $newPenalty) . ')') . '</td>';
                echo '<td>' . rrcr_h((string)$oldNet . ' -> ' . (string)$newNet . ' (' . rrcr_format_signed_delta($oldNet, $newNet) . ')') . '</td>';
                echo '</tr>';
            }
        }

        echo '</table>';
    }

    echo '</body></html>';
}

function rrcr_render_cli_summary(array $results): void
{
    rrcr_log(RRCR_SIGNATURE . ' classification summary');
    rrcr_log('Year: ' . (string)$results['year']);
    rrcr_log('Classified: ' . (string)$results['classifiedCount']);
    rrcr_log('MRL impact: ' . (string)$results['impactCount']);
    rrcr_log('All-driver changes: ' . (string)$results['allDriverImpactCount']);

    $runs = isset($results['runs']) && is_array($results['runs']) ? $results['runs'] : [];
    foreach ($runs as $row) {
        rrcr_log(
            (string)($row['raceCode'] ?? '')
            . ' classified=' . (!empty($row['classified']) ? 'YES' : 'NO')
            . ' impact=' . (!empty($row['impact']) ? 'YES' : 'NO')
            . ' changedAllDrivers=' . (string)($row['changedAllDriversCount'] ?? ($row['allDriverChangedCount'] ?? 0))
            . ' changedMRLListedDrivers=' . (string)($row['changedMrlListedDriversCount'] ?? 0)
            . ' changedSegmentPickedDrivers=' . (string)($row['changedSegmentPickedDriversCount'] ?? ($row['changedDriversCount'] ?? 0))
            . ' changedDrivers="' . rrcr_changed_driver_details_compact(isset($row['changedDriverDetails']) && is_array($row['changedDriverDetails']) ? $row['changedDriverDetails'] : []) . '"'
            . ' status="' . (string)($row['status_label'] ?? 'Classified') . '"'
        );
    }
}

if (rrcr_should_auto_run()) {
    $options = rrcr_bootstrap();

    if (!isset($dbo) || !($dbo instanceof PDO)) {
        $message = 'PDO handle $dbo is not available.';

        if (rrcr_is_cli()) {
            rrcr_log($message);
            exit(1);
        }

        echo '<p>' . rrcr_h($message) . '</p>';
        exit;
    }

    $results = rrcr_run($options, $dbo);

    if (rrcr_is_cli()) {
        rrcr_render_cli_summary($results);
        exit(0);
    }

    rrcr_render_web_summary($results);
}
