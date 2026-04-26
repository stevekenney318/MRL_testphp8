<?php
declare(strict_types=1);

/**
 * race_results_classify_revisions.php
 *
 * VERSION: v002
 * LAST MODIFIED: 4/26/2026 1:02:41 am
 *
 * DESCRIPTION:
 * v001 skeleton for the MRL-only revision classification layer.
 * This script is intended to work alongside race_results_revision_monitor.php.
 * It does not detect revisions by itself; instead, it classifies whether
 * an already-detected race-table revision actually changes MRL-relevant scoring.
 *
 * CURRENT SCOPE OF THIS V002 BUILD:
 * - bootstrap environment
 * - discover candidate races
 * - discover snapshot pairs
 * - derive segment-based MRL driver pool
 * - extract per-driver scoring from stored snapshots
 * - build normalized MRL-only datasets
 * - hash and compare those datasets
 * - write JSON / hash / diff artifacts
 * - support both CLI and manual web testing
 * - support safe include/call usage from race_results_revision_monitor.php
 *
 * CHANGELOG:
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

if (!defined('RRCR_AUTO_RUN')) {
    define('RRCR_AUTO_RUN', true);
}

require_once __DIR__ . '/race_results_engine.php';

$docRoot = rr_docroot_from_script_dir(__DIR__);

// CLI/browser safety
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
    if ($raceNumber >= 1 && $raceNumber <= 8) {
        return 'S1';
    }
    if ($raceNumber >= 9 && $raceNumber <= 17) {
        return 'S2';
    }
    if ($raceNumber >= 18 && $raceNumber <= 26) {
        return 'S3';
    }
    if ($raceNumber >= 27 && $raceNumber <= 36) {
        return 'S4';
    }

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
        if (!is_array($row)) {
            continue;
        }

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

        if (!is_dir($raceFolder)) {
            continue;
        }

        if ($onlyRaceCode !== '' && (string)$race['raceCode'] !== $onlyRaceCode) {
            continue;
        }

        $snapshotFiles = glob($raceFolder . '/snapshot_*.html');
        if (!is_array($snapshotFiles) || count($snapshotFiles) < 2) {
            continue;
        }

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

function rrcr_get_segment_driver_pool(string $raceYear, string $segment, PDO $dbo): array
{
    $drivers = [];

    $sql = "
        SELECT
            driverA,
            driverB,
            driverC,
            driverD
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
        if (!is_array($row)) {
            continue;
        }

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
        if ($name === '' || !is_array($row)) {
            continue;
        }

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

function rrcr_build_mrl_dataset(array $driverPool, array $allDriverScores, array $meta = []): array
{
    $drivers = [];
    $sortedPool = array_values(array_unique($driverPool));
    sort($sortedPool, SORT_STRING | SORT_FLAG_CASE);

    foreach ($sortedPool as $driverName) {
        $name = rrcr_normalize_driver_name((string)$driverName);
        if ($name === '') {
            continue;
        }

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

function rrcr_compare_mrl_datasets(array $oldDataset, array $newDataset): array
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
    $lines = [];

    $lines[] = 'MRL IMPACT CLASSIFICATION';
    $lines[] = trim((string)($meta['year'] ?? '') . ' ' . (string)($meta['race_code'] ?? '') . ' ' . (string)($meta['race_name'] ?? ''));
    $lines[] = '';

    if (isset($meta['driver_pool_count'])) {
        $lines[] = 'Driver pool size: ' . (int)$meta['driver_pool_count'] . ' drivers';
        $lines[] = '';
    }

    if (!empty($comparison['changedDrivers'])) {
        $lines[] = 'Tracked driver changes:';
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
        $lines[] = 'Tracked driver changes:';
        $lines[] = 'none';
        $lines[] = '';
    }

    $lines[] = 'Impact classification:';
    $lines[] = !empty($comparison['impact']) ? 'YES' : 'NO';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function rrcr_write_artifacts(string $raceFolder, array $currentDataset, string $hash, string $diffText, array $summaryData, bool $writeArtifacts = true): array
{
    $files = [
        'data' => rtrim($raceFolder, '/\\') . '/mrl_impact_data.json',
        'hash' => rtrim($raceFolder, '/\\') . '/mrl_impact_hash.txt',
        'diff' => rtrim($raceFolder, '/\\') . '/mrl_impact_diff.txt',
        'summary' => rtrim($raceFolder, '/\\') . '/mrl_impact_summary.json',
    ];

    if ($writeArtifacts) {
        file_put_contents($files['data'], rrcr_json_encode_pretty($currentDataset) . PHP_EOL);
        file_put_contents($files['hash'], $hash . PHP_EOL);
        file_put_contents($files['diff'], $diffText);
        file_put_contents($files['summary'], rrcr_json_encode_pretty($summaryData) . PHP_EOL);
    }

    return $files;
}

function rrcr_classify_race_revision(array $raceInfo, PDO $dbo, bool $writeArtifacts = true, bool $verbose = false): array
{
    $snapshots = rrcr_get_race_snapshots((string)$raceInfo['raceFolder']);
    $pair = rrcr_get_comparison_pair($snapshots);

    if ($pair['previous'] === '' || $pair['current'] === '') {
        return [
            'raceCode' => (string)$raceInfo['raceCode'],
            'classified' => false,
            'impact' => false,
            'changedDriversCount' => 0,
            'message' => 'Not enough snapshots to compare.',
        ];
    }

    $driverPool = rrcr_get_segment_driver_pool((string)$raceInfo['year'], (string)$raceInfo['segment'], $dbo);
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

    $hash = rrcr_hash_mrl_dataset($newDataset);
    $comparison = rrcr_compare_mrl_datasets($oldDataset, $newDataset);

    $summaryData = [
        'year' => (string)$raceInfo['year'],
        'race_code' => (string)$raceInfo['raceCode'],
        'segment' => (string)$raceInfo['segment'],
        'race_id' => (string)$raceInfo['raceId'],
        'race_name' => (string)$raceInfo['raceName'],
        'impact' => !empty($comparison['impact']),
        'changed_drivers_count' => (int)$comparison['changedDriversCount'],
        'driver_pool_count' => count($driverPool),
        'previous_snapshot' => basename((string)$pair['previous']),
        'current_snapshot' => basename((string)$pair['current']),
    ];

    $diffText = rrcr_build_diff_summary($comparison, [
        'year' => (string)$raceInfo['year'],
        'race_code' => (string)$raceInfo['raceCode'],
        'race_name' => (string)$raceInfo['raceName'],
        'driver_pool_count' => count($driverPool),
    ]);

    $artifactFiles = rrcr_write_artifacts((string)$raceInfo['raceFolder'], $newDataset, $hash, $diffText, $summaryData, $writeArtifacts);

    if ($verbose) {
        rrcr_log(
            (string)$raceInfo['raceCode'] . ' classified. '
            . 'Impact=' . (!empty($comparison['impact']) ? 'YES' : 'NO')
            . ' changedDrivers=' . (string)$comparison['changedDriversCount']
        );
    }

    return [
        'raceCode' => (string)$raceInfo['raceCode'],
        'classified' => true,
        'impact' => !empty($comparison['impact']),
        'changedDriversCount' => (int)$comparison['changedDriversCount'],
        'driverPoolCount' => count($driverPool),
        'previousSnapshot' => basename((string)$pair['previous']),
        'currentSnapshot' => basename((string)$pair['current']),
        'artifactFiles' => $artifactFiles,
        'comparison' => $comparison,
        'message' => 'Classification complete.',
    ];
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
            'message' => 'Race not found or not enough snapshots to compare.',
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
        'year' => $year,
        'race_code' => $raceCode,
        'candidates' => [],
        'classifiedCount' => 0,
        'impactCount' => 0,
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

        if (!empty($result['classified'])) {
            $results['classifiedCount']++;
        }

        if (!empty($result['impact'])) {
            $results['impactCount']++;
        }
    }

    return $results;
}

function rrcr_render_web_summary(array $results): void
{
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>MRL Revision Classification</title>';
    echo '<style>';
    echo 'body { font-family: Arial, Helvetica, sans-serif; margin: 16px; line-height: 1.35; }';
    echo 'table { border-collapse: collapse; width: 100%; max-width: 1100px; }';
    echo 'th, td { border: 1px solid #444; padding: 6px 8px; text-align: left; vertical-align: top; }';
    echo 'th { background: #f2f2f2; }';
    echo '.yes { font-weight: bold; color: #0b6d2b; }';
    echo '.no { font-weight: bold; color: #8b0000; }';
    echo '</style></head><body>';

    echo '<h2>MRL Revision Classification</h2>';
    echo '<p><strong>Year:</strong> ' . rrcr_h($results['year']) . '</p>';
    echo '<p><strong>Classified:</strong> ' . rrcr_h((string)$results['classifiedCount']) . ' &nbsp; ';
    echo '<strong>Impact:</strong> ' . rrcr_h((string)$results['impactCount']) . '</p>';

    echo '<table>';
    echo '<tr><th>Race</th><th>Classified</th><th>Impact</th><th>Changed Drivers</th><th>Previous Snapshot</th><th>Current Snapshot</th><th>Message</th></tr>';

    $runs = isset($results['runs']) && is_array($results['runs']) ? $results['runs'] : [];
    foreach ($runs as $row) {
        echo '<tr>';
        echo '<td>' . rrcr_h((string)($row['raceCode'] ?? '')) . '</td>';
        echo '<td>' . (!empty($row['classified']) ? 'YES' : 'NO') . '</td>';
        echo '<td class="' . (!empty($row['impact']) ? 'yes' : 'no') . '">' . (!empty($row['impact']) ? 'YES' : 'NO') . '</td>';
        echo '<td>' . rrcr_h((string)($row['changedDriversCount'] ?? 0)) . '</td>';
        echo '<td>' . rrcr_h((string)($row['previousSnapshot'] ?? '')) . '</td>';
        echo '<td>' . rrcr_h((string)($row['currentSnapshot'] ?? '')) . '</td>';
        echo '<td>' . rrcr_h((string)($row['message'] ?? '')) . '</td>';
        echo '</tr>';
    }

    echo '</table></body></html>';
}

function rrcr_render_cli_summary(array $results): void
{
    rrcr_log('Classification summary');
    rrcr_log('Year: ' . (string)$results['year']);
    rrcr_log('Classified: ' . (string)$results['classifiedCount']);
    rrcr_log('Impact: ' . (string)$results['impactCount']);

    $runs = isset($results['runs']) && is_array($results['runs']) ? $results['runs'] : [];
    foreach ($runs as $row) {
        rrcr_log(
            (string)($row['raceCode'] ?? '') .
            ' classified=' . (!empty($row['classified']) ? 'YES' : 'NO') .
            ' impact=' . (!empty($row['impact']) ? 'YES' : 'NO') .
            ' changedDrivers=' . (string)($row['changedDriversCount'] ?? 0)
        );
    }
}

if (RRCR_AUTO_RUN) {
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
