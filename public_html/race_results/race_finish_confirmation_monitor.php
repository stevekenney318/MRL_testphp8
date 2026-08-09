<?php
declare(strict_types=1);

/**
 * race_finish_confirmation_monitor.php
 *
 * VERSION: v006
 * LAST MODIFIED: 8/9/2026 4:47:04 pm
 *
 * DESCRIPTION:
 * Observation-only NASCAR race finish confirmation monitor.
 *
 * IMPORTANT:
 * - Does not alter or call any existing MRL monitor, scheduler, scoring, snapshot,
 *   standings, revision, email, flag, or final-detection process.
 * - Does not make decisions for MRL.
 * - Compares MRL race identity with NASCAR live status, Racing-Reference race results, Racing-Reference season stats, and Jayski near race completion.
 * - Uses the deterministic Racing-Reference race URL built from MRL year/race number.
 * - Reads the Jayski yearly race block, winner field, Results link, and linked results page.
 * - Designed to be launched every minute by the existing MRL master scheduler.
 * - Reads existing MRL JSON/cache files for lap and flag status.
 * - Uses the existing MRL NASCAR cache as the NASCAR source and requires Cup Series data before activation.
 * - Internal cadence determines when secondary-source observations are due.
 *
 * CHANGELOG:
 * v006 (8/9/2026 4:47:04 pm)
 * - Corrected false MRL/NASCAR race-identity mismatch by resolving/storing the MRL race number in the NASCAR cache data before building the comparison record.
 * - Diagnostic/display correction only; finish-watch activation, cadence, source polling, scoring, and all downstream MRL behavior are unchanged.
 *
 * v006 (8/9/2026 3:50:34 pm)
 * - Split Racing-Reference into separate Race Page and Season Page observations.
 * - Race Page now uses https://www.racing-reference.info/race/{year}-{race_number}/W.
 * - Race Page classification is intentionally simple: waiting phrase present = waiting_results; waiting phrase gone plus Position 1 row = results_posted.
 * - Added evidence text, per-source first-status timestamps, and first-posted timestamps for timeline comparison.
 * - Added separate raw hashes/history fields and log status fields for Racing-Reference Race Page and Season Page.
 * - Preserved the existing Cup-only activation safeguard, cadence, Jayski checks, MRL/NASCAR comparison, and observation-only behavior.
 *
 * v005 (7/26/2026 5:15:03 pm)
 * - Saves current MRL/ESPN race and lap progress below the 90% finish-watch threshold.
 * - Keeps NASCAR Flag dependent on NASCAR live-source data.
 * - Keeps Finish Watch waiting and does not contact Racing-Reference or Jayski early.
 *
 * v004 (7/26/2026 9:22:59 am)
 * - Added separate MRL and NASCAR comparison records for the dashboard.
 * - Added a strict Cup Series activation safeguard (NASCAR series_id 1 by default).
 * - Replaced the monitor HTTP user agent with the tested Chrome-like request headers and persistent cookies.
 * - Added explicit Cloudflare/challenge-page detection and one automatic retry.
 * - Installer clears prior v002/v003 runtime observations so v004 starts with a clean comparison history.
 *
 * v003 (7/26/2026 7:16:39 am)
 * - Replaced broad secondary-page keyword matching with source-specific Racing-Reference and Jayski checks.
 * - Racing-Reference now uses /race-results/{year}-{race_number}/W and the yearly season-stats page.
 * - Jayski now finds the known race-number block, detects winner population, extracts its Results link, and checks the linked page.
 * - Added race-number resolution from _race_results_schedule.json and richer saved evidence.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const RFCM_VERSION = 'v006';
const RFCM_SIGNATURE = 'MRL_RACE_FINISH_CONFIRMATION_MONITOR v006';

$baseDir = __DIR__;
$dataDir = $baseDir . '/_race_finish_confirmation';
$configFile = $dataDir . '/config.json';
$stateFile = $dataDir . '/state.json';
$latestFile = $dataDir . '/latest.json';
$historyDir = $dataDir . '/history';
$rawDir = $dataDir . '/raw';
$logFile = $dataDir . '/monitor.log';
$lockFile = $dataDir . '/monitor.lock';

rfcm_ensure_dir($dataDir);
rfcm_ensure_dir($historyDir);
rfcm_ensure_dir($rawDir);

$config = rfcm_load_json($configFile);
if (empty($config)) {
    $config = rfcm_default_config();
    rfcm_write_json_atomic($configFile, $config);
}

$state = rfcm_load_json($stateFile);
$now = time();
$force = isset($_GET['force']) || (PHP_SAPI === 'cli' && in_array('--force', $argv ?? [], true));
$verbose = PHP_SAPI !== 'cli' || in_array('--verbose', $argv ?? [], true);

$lockHandle = @fopen($lockFile, 'c+');
if ($lockHandle === false || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    rfcm_out($verbose, 'SKIP: another finish-confirmation monitor run is active.');
    exit(0);
}

$nextRunTs = isset($state['next_run_at']) ? strtotime((string)$state['next_run_at']) : false;
if (!$force && $nextRunTs !== false && $nextRunTs > $now) {
    rfcm_out($verbose, 'SKIP: next observation is due at ' . date('Y-m-d H:i:s', $nextRunTs) . '.');
    rfcm_release_lock($lockHandle);
    exit(0);
}

$year = rfcm_resolve_year($config, $argv ?? []);
$timeout = max(3, min(30, (int)($config['timeout_seconds'] ?? 10)));
$startedAt = microtime(true);
$checkedAt = rfcm_now();

$nascar = rfcm_read_existing_mrl_status($baseDir, $year, $config);
$raceIdentity = rfcm_resolve_race_identity($baseDir, $year, $nascar);
$nascar['mrl_race_number'] = (int)($raceIdentity['race_number'] ?? 0);
$nascar['mrl_race_code'] = (string)($raceIdentity['race_code'] ?? '');
$nascar['schedule_race_name'] = (string)($raceIdentity['race_name'] ?? '');
$nascar['schedule_track_name'] = (string)($raceIdentity['track_name'] ?? '');
$mrlStatus = rfcm_build_mrl_status($raceIdentity, $nascar);
$progress = (float)($nascar['progress_percent'] ?? 0.0);
$flagLabel = strtoupper((string)($nascar['flag_label'] ?? 'UNKNOWN'));
$activationPercent = (float)($config['finish_watch_start_percent'] ?? 90.0);
$sourceAgeMinutes = isset($nascar['source_age_minutes']) ? (float)$nascar['source_age_minutes'] : null;
$maxActivationAge = max(1, (int)($config['status_max_age_minutes_before_activation'] ?? 30));
$previouslyActive = !empty($state['finish_watch_active']);
$requiredSeriesId = (int)($config['required_nascar_series_id'] ?? 1);
$isRequiredSeries = (int)($nascar['series_id'] ?? 0) === $requiredSeriesId;
$statusFreshEnough = $sourceAgeMinutes === null || $sourceAgeMinutes <= $maxActivationAge || $previouslyActive;

$finishWindow = $isRequiredSeries && $statusFreshEnough && (
    $progress >= $activationPercent
    || $flagLabel === 'WHITE'
    || $flagLabel === 'CHECKERED'
    || $previouslyActive
);

if (!$finishWindow) {
    $idleReason = empty($nascar['ok'])
        ? 'No usable NASCAR lap/flag status was found in the existing MRL JSON files.'
        : (!$isRequiredSeries
            ? 'NASCAR cache is not Cup Series data; finish watch remains idle.'
            : ($statusFreshEnough
                ? 'Cup race progress is below the finish-watch activation threshold.'
                : 'Existing NASCAR Cup status is stale; ignoring old late-race/checkered data.'));

    /*
     * v005: publish a lightweight dashboard observation below 90%.
     * No Racing-Reference or Jayski requests are made in this path.
     */
    $idleObservation = [
        'signature' => RFCM_SIGNATURE,
        'version' => RFCM_VERSION,
        'observation_id' => 'idle_' . date('Ymd_His'),
        'checked_at' => $checkedAt,
        'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        'year' => $year,
        'finish_watch_start_percent' => $activationPercent,
        'finish_watch_active' => false,
        'race' => [
            'race_id' => (int)($nascar['race_id'] ?? 0),
            'series_id' => (int)($nascar['series_id'] ?? 0),
            'race_name' => (string)($raceIdentity['race_name'] ?? $nascar['race_name'] ?? ''),
            'track_name' => (string)($raceIdentity['track_name'] ?? $nascar['track_name'] ?? ''),
            'lap_number' => (int)($nascar['lap_number'] ?? 0),
            'laps_in_race' => (int)($nascar['laps_in_race'] ?? 0),
            'progress_percent' => $progress,
            'flag_label' => $flagLabel,
            'mrl_race_number' => (int)($raceIdentity['race_number'] ?? 0),
            'mrl_race_code' => (string)($raceIdentity['race_code'] ?? ''),
            'status_source' => (string)($nascar['status_source'] ?? ''),
            'source_generated_at' => (string)($nascar['source_generated_at'] ?? ''),
            'source_age_minutes' => $nascar['source_age_minutes'] ?? null,
            'required_nascar_series_id' => $requiredSeriesId,
            'nascar_series_match' => $isRequiredSeries,
        ],
        'sources' => [
            'mrl' => $mrlStatus,
            'nascar' => $nascar,
            'racing_reference_race' => [
                'status' => 'waiting',
                'message' => 'Finish watch has not reached the 90% activation threshold.',
                'checked_at' => 'Never',
                'results_posted' => false,
                'waiting_phrase_present' => false,
                'position_one_found' => false,
                'evidence' => '',
                'first_status_at' => '',
                'first_posted_at' => '',
            ],
            'racing_reference_season' => [
                'status' => 'waiting',
                'message' => 'Finish watch has not reached the 90% activation threshold.',
                'checked_at' => 'Never',
                'season_row_completed' => false,
                'evidence' => '',
                'first_status_at' => '',
                'first_posted_at' => '',
            ],
            'jayski' => [
                'status' => 'waiting',
                'message' => 'Finish watch has not reached the 90% activation threshold.',
                'checked_at' => 'Never',
                'winner' => 'Not populated',
                'results_link_found' => false,
                'results_page_posted' => false,
            ],
        ],
        'observation_only' => true,
        'decision' => 'NONE',
        'idle_reason' => $idleReason,
        'notes' => [
            'Dashboard status only; no secondary-source requests were made.',
        ],
    ];
    rfcm_write_json_atomic($latestFile, $idleObservation);

    $idleState = [
        'signature' => RFCM_SIGNATURE . ' STATE',
        'version' => RFCM_VERSION,
        'updated_at' => $checkedAt,
        'finish_watch_active' => false,
        'idle_reason' => $idleReason,
        'last_progress_percent' => $progress,
        'last_flag_label' => $flagLabel,
        'last_status_source' => (string)($nascar['status_source'] ?? ''),
        'last_status_checked_at' => (string)($nascar['source_generated_at'] ?? ''),
        'last_raw_hashes' => isset($state['last_raw_hashes']) && is_array($state['last_raw_hashes']) ? $state['last_raw_hashes'] : [],
        'source_first_transitions' => isset($state['source_first_transitions']) && is_array($state['source_first_transitions']) ? $state['source_first_transitions'] : [],
    ];
    rfcm_write_json_atomic($stateFile, $idleState);
    rfcm_append_log($logFile, sprintf(
        '%s IDLE progress=%.2f flag=%s source=%s reason=%s',
        $checkedAt,
        $progress,
        $flagLabel,
        (string)($nascar['status_source'] ?? ''),
        $idleReason
    ));
    rfcm_out($verbose, 'IDLE: ' . $idleReason);
    rfcm_release_lock($lockHandle);
    exit(0);
}

$checkeredStartedAt = (string)($state['checkered_started_at'] ?? '');
if ($flagLabel === 'CHECKERED' && $checkeredStartedAt === '') {
    $checkeredStartedAt = $checkedAt;
}

$postCheckeredMinutes = max(5, (int)($config['post_checkered_monitor_minutes'] ?? 180));
if ($flagLabel === 'CHECKERED' && $checkeredStartedAt !== '') {
    $checkeredTs = strtotime($checkeredStartedAt);
    if ($checkeredTs !== false && ($now - $checkeredTs) > ($postCheckeredMinutes * 60)) {
        $completeState = $state;
        $completeState['signature'] = RFCM_SIGNATURE . ' STATE';
        $completeState['version'] = RFCM_VERSION;
        $completeState['updated_at'] = $checkedAt;
        $completeState['finish_watch_active'] = false;
        $completeState['monitoring_complete'] = true;
        $completeState['monitoring_complete_at'] = $checkedAt;
        $completeState['idle_reason'] = 'Configured post-checkered observation window has ended.';
        rfcm_write_json_atomic($stateFile, $completeState);
        rfcm_append_log($logFile, $checkedAt . ' COMPLETE post-checkered observation window ended.');
        rfcm_out($verbose, 'COMPLETE: post-checkered observation window ended.');
        rfcm_release_lock($lockHandle);
        exit(0);
    }
}

$cadence = rfcm_choose_cadence($progress, $flagLabel, $config);
$nextRunTs = isset($state['next_run_at']) ? strtotime((string)$state['next_run_at']) : false;
if (!$force && $nextRunTs !== false && $nextRunTs > $now) {
    rfcm_out($verbose, 'SKIP: next secondary-source observation is due at ' . date('Y-m-d H:i:s', $nextRunTs) . '.');
    rfcm_release_lock($lockHandle);
    exit(0);
}

$raceNumber = (int)($raceIdentity['race_number'] ?? 0);
$sourceResults = [
    'mrl' => $mrlStatus,
    'nascar' => $nascar,
    'racing_reference_race' => rfcm_check_racing_reference_race(
        $year,
        $raceNumber,
        $config,
        $timeout,
        $rawDir,
        $state
    ),
    'racing_reference_season' => rfcm_check_racing_reference_season(
        $year,
        $raceNumber,
        $config,
        $timeout,
        $rawDir,
        $state
    ),
    'jayski' => rfcm_check_jayski(
        $year,
        $raceNumber,
        $nascar,
        $config,
        $timeout,
        $rawDir,
        $state
    ),
];

$sourceFirstTransitions = isset($state['source_first_transitions']) && is_array($state['source_first_transitions'])
    ? $state['source_first_transitions']
    : [];
foreach (['racing_reference_race', 'racing_reference_season', 'jayski'] as $transitionKey) {
    if (isset($sourceResults[$transitionKey]) && is_array($sourceResults[$transitionKey])) {
        rfcm_apply_source_transition_timestamps($sourceResults[$transitionKey], $transitionKey, $checkedAt, $sourceFirstTransitions);
    }
}

$nextRunAt = date('Y-m-d H:i:s', $now + ($cadence['minutes'] * 60));
$observationId = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

$observation = [
    'signature' => RFCM_SIGNATURE,
    'version' => RFCM_VERSION,
    'observation_id' => $observationId,
    'checked_at' => $checkedAt,
    'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
    'year' => $year,
    'finish_watch_start_percent' => (float)($config['finish_watch_start_percent'] ?? 90.0),
    'finish_watch_active' => $finishWindow,
    'cadence' => $cadence,
    'next_run_at' => $nextRunAt,
    'race' => [
        'race_id' => (int)($nascar['race_id'] ?? 0),
        'series_id' => (int)($nascar['series_id'] ?? 0),
        'race_name' => (string)($raceIdentity['race_name'] ?? $nascar['race_name'] ?? ''),
        'track_name' => (string)($raceIdentity['track_name'] ?? $nascar['track_name'] ?? ''),
        'lap_number' => (int)($nascar['lap_number'] ?? 0),
        'laps_in_race' => (int)($nascar['laps_in_race'] ?? 0),
        'progress_percent' => $progress,
        'flag_label' => $flagLabel,
        'mrl_race_number' => (int)($raceIdentity['race_number'] ?? 0),
        'mrl_race_code' => (string)($raceIdentity['race_code'] ?? ''),
        'status_source' => (string)($nascar['status_source'] ?? ''),
        'source_generated_at' => (string)($nascar['source_generated_at'] ?? ''),
        'source_age_minutes' => $nascar['source_age_minutes'] ?? null,
        'required_nascar_series_id' => $requiredSeriesId,
        'nascar_series_match' => $isRequiredSeries,
    ],
    'sources' => $sourceResults,
    'observation_only' => true,
    'decision' => 'NONE',
    'notes' => [
        'This monitor records observations only.',
        'No current MRL process is called, changed, or controlled by this file.',
        'Secondary-source matching is evidence collection, not an official determination.',
    ],
];

$historyName = 'observation_' . $observationId . '.json';
$historyPath = $historyDir . '/' . $historyName;
rfcm_write_json_atomic($historyPath, $observation);
rfcm_write_json_atomic($latestFile, $observation);

$state = [
    'signature' => RFCM_SIGNATURE . ' STATE',
    'version' => RFCM_VERSION,
    'updated_at' => $checkedAt,
    'last_observation_id' => $observationId,
    'last_observation_file' => 'history/' . $historyName,
    'next_run_at' => $nextRunAt,
    'cadence' => $cadence,
    'last_progress_percent' => $progress,
    'last_flag_label' => $flagLabel,
    'last_status_source' => (string)($nascar['status_source'] ?? ''),
    'last_status_checked_at' => (string)($nascar['source_generated_at'] ?? ''),
    'finish_watch_active' => true,
    'checkered_started_at' => $checkeredStartedAt,
    'monitoring_complete' => false,
    'last_raw_hashes' => rfcm_collect_raw_hashes($sourceResults, $state),
    'source_first_transitions' => $sourceFirstTransitions,
];
rfcm_write_json_atomic($stateFile, $state);

rfcm_append_log($logFile, sprintf(
    '%s race_id=%d lap=%d/%d progress=%.2f flag=%s watch=%s cadence=%dm rr_race=%s rr_season=%s jayski=%s observation=%s',
    $checkedAt,
    (int)($nascar['race_id'] ?? 0),
    (int)($nascar['lap_number'] ?? 0),
    (int)($nascar['laps_in_race'] ?? 0),
    $progress,
    $flagLabel,
    $finishWindow ? 'YES' : 'NO',
    (int)$cadence['minutes'],
    (string)($sourceResults['racing_reference_race']['status'] ?? ''),
    (string)($sourceResults['racing_reference_season']['status'] ?? ''),
    (string)($sourceResults['jayski']['status'] ?? ''),
    $observationId
));

rfcm_prune($historyDir, 'observation_', '.json', (int)($config['retention']['history_files'] ?? 2500));
rfcm_prune($rawDir, '', '', (int)($config['retention']['raw_files'] ?? 500));

rfcm_out($verbose, 'OK: observation saved: ' . $historyName);
rfcm_out($verbose, 'Race: ' . ((string)($nascar['race_name'] ?? '') ?: 'Unknown race'));
rfcm_out($verbose, 'Lap: ' . (string)($nascar['lap_number'] ?? 0) . '/' . (string)($nascar['laps_in_race'] ?? 0) . ' (' . number_format($progress, 2) . '%)');
rfcm_out($verbose, 'Flag: ' . $flagLabel);
rfcm_out($verbose, 'Next check: ' . $nextRunAt . ' (' . (string)$cadence['label'] . ')');

rfcm_release_lock($lockHandle);
exit(0);

function rfcm_default_config(): array
{
    return [
        'enabled' => true,
        'year' => (int)date('Y'),
        'timezone' => 'America/New_York',
        'finish_watch_start_percent' => 90,
        'required_nascar_series_id' => 1,
        'status_max_age_minutes_before_activation' => 30,
        'post_checkered_monitor_minutes' => 180,
        'timeout_seconds' => 10,
        'cadence_minutes' => [
            '90_to_96_999_percent' => 5,
            '97_to_99_999_percent' => 2,
            'white_flag' => 1,
            'checkered' => 1,
            'unknown_progress' => 5
        ],
        'existing_mrl_status_files' => [
            '_mrl_nascar_live_status.json',
            '_race_results_monitor_state.json'
        ],
        'sources' => [
            'racing_reference_race' => [
                'enabled' => true,
                'url_template' => 'https://www.racing-reference.info/race/{year}-{race_number}/W'
            ],
            'racing_reference_season' => [
                'enabled' => true,
                'url_template' => 'https://www.racing-reference.info/season-stats/{year}/W'
            ],
            'jayski' => [
                'enabled' => true,
                'index_url_template' => 'https://www.jayski.com/nascar-cup-series/{year}-nascar-cup-series-race-results/'
            ]
        ],
        'retention' => [
            'history_files' => 2500,
            'raw_files' => 500
        ]
    ];
}

function rfcm_resolve_year(array $config, array $argv): int
{
    foreach ($argv as $arg) {
        if (preg_match('/^\d{4}$/', (string)$arg)) {
            return (int)$arg;
        }
    }
    if (isset($_GET['year']) && preg_match('/^\d{4}$/', (string)$_GET['year'])) {
        return (int)$_GET['year'];
    }
    return (int)($config['year'] ?? date('Y'));
}

function rfcm_read_existing_mrl_status(string $baseDir, int $year, array $config): array
{
    $nascarPath = rtrim($baseDir, '/\\') . '/_mrl_nascar_live_status.json';
    $nascar = rfcm_load_json($nascarPath);
    if (!empty($nascar)) {
        $lap = (int)($nascar['lap_number'] ?? 0);
        $total = (int)($nascar['laps_in_race'] ?? 0);
        $generatedAt = (string)($nascar['generated_at'] ?? '');
        $progress = ($lap > 0 && $total > 0) ? min(999.999, ($lap / $total) * 100.0) : 0.0;
        return [
            'checked' => true,
            'ok' => !empty($nascar['ok']) || ($lap > 0 && $total > 0),
            'status' => strtolower((string)($nascar['flag_label'] ?? 'unknown')),
            'message' => 'Read existing MRL NASCAR live-status cache.',
            'checked_at' => rfcm_now(),
            'status_source' => '_mrl_nascar_live_status.json',
            'source_generated_at' => $generatedAt,
            'source_age_minutes' => rfcm_age_minutes($generatedAt),
            'source_url' => (string)($nascar['source_url'] ?? ''),
            'race_id' => (int)($nascar['race_id'] ?? 0),
            'series_id' => (int)($nascar['series_id'] ?? 0),
            'race_name' => (string)($nascar['race_name'] ?? ''),
            'track_name' => (string)($nascar['track_name'] ?? ''),
            'lap_number' => $lap,
            'laps_in_race' => $total,
            'progress_percent' => round($progress, 3),
            'flag_state' => (int)($nascar['flag_state'] ?? 0),
            'flag_label' => strtoupper((string)($nascar['flag_label'] ?? 'UNKNOWN')),
        ];
    }

    $monitorPath = rtrim($baseDir, '/\\') . '/_race_results_monitor_state.json';
    $monitor = rfcm_load_json($monitorPath);
    $yearState = isset($monitor['byYear'][(string)$year]) && is_array($monitor['byYear'][(string)$year])
        ? $monitor['byYear'][(string)$year]
        : [];
    $raceStatus = isset($yearState['race_status']) && is_array($yearState['race_status'])
        ? $yearState['race_status']
        : (isset($yearState['current_race_status']) && is_array($yearState['current_race_status']) ? $yearState['current_race_status'] : []);

    $lap = isset($raceStatus['lap_current']) ? (int)$raceStatus['lap_current'] : 0;
    $total = isset($raceStatus['lap_total']) ? (int)$raceStatus['lap_total'] : 0;
    $checked = (string)($raceStatus['checked_at'] ?? $yearState['last_checked_at'] ?? '');
    $progress = ($lap > 0 && $total > 0) ? min(999.999, ($lap / $total) * 100.0) : 0.0;

    return [
        'checked' => true,
        'ok' => !empty($raceStatus['lap_status_found']) && $lap > 0 && $total > 0,
        'status' => 'lap_status',
        'message' => 'Read existing MRL race-results monitor state.',
        'checked_at' => rfcm_now(),
        'status_source' => '_race_results_monitor_state.json',
        'source_generated_at' => $checked,
        'source_age_minutes' => rfcm_age_minutes($checked),
        'source_url' => '',
        'race_id' => (int)($raceStatus['race_id'] ?? 0),
        'series_id' => 0,
        'race_name' => (string)($raceStatus['full_race_name'] ?? $raceStatus['race_name'] ?? ''),
        'track_name' => (string)($raceStatus['track_name'] ?? ''),
        'lap_number' => $lap,
        'laps_in_race' => $total,
        'progress_percent' => round($progress, 3),
        'flag_state' => 0,
        'flag_label' => 'UNKNOWN',
    ];
}


function rfcm_resolve_race_identity(string $baseDir, int $year, array $nascar): array
{
    $path = rtrim($baseDir, '/\\') . '/_race_results_schedule.json';
    $schedule = rfcm_load_json($path);
    $candidates = [];
    if (isset($schedule['next_race']) && is_array($schedule['next_race'])) {
        $candidates[] = $schedule['next_race'];
    }
    if (isset($schedule['mrl_points_races']) && is_array($schedule['mrl_points_races'])) {
        foreach ($schedule['mrl_points_races'] as $race) {
            if (is_array($race)) $candidates[] = $race;
        }
    }

    $statusRaceName = strtolower(trim((string)($nascar['race_name'] ?? '')));
    $statusTrackName = strtolower(trim((string)($nascar['track_name'] ?? '')));
    $best = [];
    $bestScore = -1;
    foreach ($candidates as $race) {
        $raceYear = (int)($race['year'] ?? $year);
        if ($raceYear !== $year) continue;
        $score = 0;
        $raceName = strtolower(trim((string)($race['mrl_race_name'] ?? $race['race_name'] ?? $race['short_name'] ?? '')));
        $trackName = strtolower(trim((string)($race['track_name'] ?? '')));
        if ($statusRaceName !== '' && $raceName !== '') {
            if ($statusRaceName === $raceName) $score += 100;
            elseif (strpos($statusRaceName, $raceName) !== false || strpos($raceName, $statusRaceName) !== false) $score += 60;
        }
        if ($statusTrackName !== '' && $trackName !== '') {
            if ($statusTrackName === $trackName) $score += 80;
            elseif (strpos($statusTrackName, $trackName) !== false || strpos($trackName, $statusTrackName) !== false) $score += 40;
        }
        if (!empty($race['next_race']) || $race === ($schedule['next_race'] ?? null)) $score += 5;
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $race;
        }
    }

    if (empty($best) && isset($schedule['next_race']) && is_array($schedule['next_race'])) {
        $best = $schedule['next_race'];
    }

    $number = (int)($best['mrl_race_number'] ?? $best['race_number'] ?? 0);
    return [
        'source' => '_race_results_schedule.json',
        'race_number' => $number,
        'race_code' => (string)($best['mrl_race_code'] ?? ($number > 0 ? 'R' . str_pad((string)$number, 2, '0', STR_PAD_LEFT) : '')),
        'race_name' => (string)($best['mrl_race_name'] ?? $best['race_name'] ?? $best['short_name'] ?? ''),
        'track_name' => (string)($best['track_name'] ?? ''),
        'race_date' => (string)($best['mrl_race_date'] ?? ''),
        'match_score' => $bestScore,
    ];
}

function rfcm_build_mrl_status(array $raceIdentity, array $nascar): array
{
    $mrlRace = (int)($raceIdentity['race_number'] ?? 0);
    $nascarRace = (int)($nascar['mrl_race_number'] ?? 0);
    $mrlName = trim((string)($raceIdentity['race_name'] ?? ''));
    $mrlTrack = trim((string)($raceIdentity['track_name'] ?? ''));
    $nascarName = trim((string)($nascar['race_name'] ?? ''));
    $nascarTrack = trim((string)($nascar['track_name'] ?? ''));

    $raceNumberMatch = $mrlRace > 0 && $nascarRace > 0 && $mrlRace === $nascarRace;
    $nameMatch = rfcm_texts_overlap($mrlName, $nascarName);
    $trackMatch = rfcm_texts_overlap($mrlTrack, $nascarTrack);
    $match = $raceNumberMatch && ($nameMatch || $trackMatch);

    return [
        'checked' => true,
        'ok' => $mrlRace > 0,
        'status' => $match ? 'match' : 'mismatch',
        'message' => $match
            ? 'MRL scheduled race identity matches the NASCAR cache.'
            : 'MRL scheduled race identity does not match the current NASCAR cache.',
        'checked_at' => rfcm_now(),
        'source' => (string)($raceIdentity['source'] ?? '_race_results_schedule.json'),
        'race_number' => $mrlRace,
        'race_code' => (string)($raceIdentity['race_code'] ?? ''),
        'race_name' => $mrlName,
        'track_name' => $mrlTrack,
        'race_date' => (string)($raceIdentity['race_date'] ?? ''),
        'match_score' => (int)($raceIdentity['match_score'] ?? 0),
        'race_number_match' => $raceNumberMatch,
        'name_match' => $nameMatch,
        'track_match' => $trackMatch,
        'nascar_series_id' => (int)($nascar['series_id'] ?? 0),
        'observation_only' => true,
    ];
}

function rfcm_texts_overlap(string $a, string $b): bool
{
    $a = strtolower(trim($a));
    $b = strtolower(trim($b));
    if ($a === '' || $b === '') return false;
    return $a === $b || strpos($a, $b) !== false || strpos($b, $a) !== false;
}

function rfcm_age_minutes(string $value)
{
    if (trim($value) === '') return null;
    $ts = strtotime($value);
    if ($ts === false) return null;
    return round(max(0, time() - $ts) / 60, 2);
}

function rfcm_check_nascar(int $year, int $timeout): array
{
    $urls = [
        'https://cf.nascar.com/live/feeds/live-feed.json',
        'https://cf.nascar.com/cacher/live/live-feed.json',
    ];
    $attempts = [];
    $live = [];
    $sourceUrl = '';

    foreach ($urls as $url) {
        $response = rfcm_http_get($url, $timeout, 'application/json,text/plain,*/*');
        $attempts[] = rfcm_response_summary($response);
        if (empty($response['ok'])) {
            continue;
        }
        $decoded = json_decode((string)$response['body'], true);
        if (is_array($decoded) && isset($decoded['race_id'])) {
            $live = $decoded;
            $sourceUrl = $url;
            break;
        }
    }

    if (empty($live)) {
        return [
            'checked' => true,
            'ok' => false,
            'status' => 'unavailable',
            'message' => 'No NASCAR live JSON feed loaded.',
            'checked_at' => rfcm_now(),
            'attempts' => $attempts,
            'race_id' => 0,
            'series_id' => 0,
            'race_name' => '',
            'track_name' => '',
            'lap_number' => 0,
            'laps_in_race' => 0,
            'progress_percent' => 0.0,
            'flag_state' => 0,
            'flag_label' => 'UNKNOWN',
        ];
    }

    $raceId = rfcm_first_int($live, ['race_id']);
    $seriesId = rfcm_first_int($live, ['series_id']);
    $lap = rfcm_first_int($live, ['lap_number', 'lap', 'current_lap']);
    $total = rfcm_first_int($live, ['laps_in_race', 'scheduled_laps', 'actual_laps']);
    $flagState = rfcm_first_int($live, ['flag_state', 'flag']);
    $flagLabel = rfcm_flag_label($flagState);
    $raceName = rfcm_first_string($live, ['race_name', 'run_name']);
    $trackName = rfcm_first_string($live, ['track_name']);

    $weekendUrl = '';
    $weekendHttp = 0;
    if ($raceId > 0 && $seriesId > 0) {
        $weekendUrl = 'https://cf.nascar.com/cacher/' . rawurlencode((string)$year) . '/' . rawurlencode((string)$seriesId) . '/' . rawurlencode((string)$raceId) . '/weekend-feed.json';
        $weekendResponse = rfcm_http_get($weekendUrl, $timeout, 'application/json,text/plain,*/*');
        $weekendHttp = (int)($weekendResponse['http_code'] ?? 0);
        if (!empty($weekendResponse['ok'])) {
            $weekend = json_decode((string)$weekendResponse['body'], true);
            $meta = rfcm_extract_weekend_race(is_array($weekend) ? $weekend : []);
            if ($raceName === '') {
                $raceName = rfcm_first_string($meta, ['race_name', 'run_name']);
            }
            if ($trackName === '') {
                $trackName = rfcm_first_string($meta, ['track_name']);
            }
            if ($total <= 0) {
                $total = rfcm_first_int($meta, ['actual_laps', 'scheduled_laps']);
            }
        }
    }

    $progress = ($lap > 0 && $total > 0) ? min(999.999, ($lap / $total) * 100.0) : 0.0;

    return [
        'checked' => true,
        'ok' => true,
        'status' => strtolower($flagLabel),
        'message' => 'NASCAR live feed loaded.',
        'checked_at' => rfcm_now(),
        'source_url' => $sourceUrl,
        'weekend_source_url' => $weekendUrl,
        'weekend_http_code' => $weekendHttp,
        'attempts' => $attempts,
        'race_id' => $raceId,
        'series_id' => $seriesId,
        'race_name' => $raceName,
        'track_name' => $trackName,
        'lap_number' => $lap,
        'laps_in_race' => $total,
        'progress_percent' => round($progress, 3),
        'flag_state' => $flagState,
        'flag_label' => $flagLabel,
    ];
}


function rfcm_check_racing_reference_race(int $year, int $raceNumber, array $config, int $timeout, string $rawDir, array $previousState): array
{
    if ($raceNumber <= 0) {
        return rfcm_source_error('race_number_unavailable', 'MRL race number could not be resolved from _race_results_schedule.json.');
    }

    $sourceConfig = isset($config['sources']['racing_reference_race']) && is_array($config['sources']['racing_reference_race'])
        ? $config['sources']['racing_reference_race'] : [];
    $template = (string)($sourceConfig['url_template'] ?? 'https://www.racing-reference.info/race/{year}-{race_number}/W');
    $url = rfcm_expand_source_url($template, $year, $raceNumber);

    $response = rfcm_http_get($url, $timeout, 'text/html,application/xhtml+xml,*/*');
    $body = (string)($response['body'] ?? '');
    $text = rfcm_html_text($body);
    $waitingPhrase = stripos($text, 'Results not available yet.') !== false
        || stripos($text, 'Results not available yet') !== false;
    $positionOneEvidence = rfcm_racing_reference_position_one_evidence($body);
    $positionOneFound = $positionOneEvidence !== '';
    $resultsPosted = !empty($response['ok']) && !$waitingPhrase && $positionOneFound;
    $raw = rfcm_save_changed_raw('racing_reference_race', $body, $rawDir, $previousState);

    $status = 'race_page_changed_unclassified';
    $message = 'Race page loaded, but neither the waiting phrase nor a Position 1 result row was confirmed.';
    $evidence = '';

    if (!empty($response['challenge_detected'])) {
        $status = 'blocked_by_challenge';
        $message = 'Racing-Reference Race Page returned a browser challenge after retry.';
        $evidence = 'Browser challenge detected.';
    } elseif (empty($response['ok'])) {
        $status = 'request_failed';
        $message = (string)($response['error'] ?? 'Racing-Reference Race Page request failed.');
        $evidence = 'HTTP ' . (string)($response['http_code'] ?? 0);
    } elseif ($waitingPhrase) {
        $status = 'waiting_results';
        $message = 'Race page is available; results are not posted yet.';
        $evidence = 'Results not available yet.';
    } elseif ($resultsPosted) {
        $status = 'results_posted';
        $message = 'Position 1 result row is present and the waiting phrase is gone.';
        $evidence = $positionOneEvidence;
    }

    return [
        'checked' => true,
        'ok' => !empty($response['ok']),
        'status' => $status,
        'message' => $message,
        'checked_at' => rfcm_now(),
        'source_url' => $url,
        'http_code' => (int)($response['http_code'] ?? 0),
        'challenge_detected' => !empty($response['challenge_detected']),
        'attempt_count' => (int)($response['attempt_count'] ?? 1),
        'title' => rfcm_html_title($body),
        'race_number' => $raceNumber,
        'waiting_phrase_present' => $waitingPhrase,
        'position_one_found' => $positionOneFound,
        'position_one_evidence' => $positionOneEvidence,
        'results_posted' => $resultsPosted,
        'evidence' => $evidence,
        'body_bytes' => strlen($body),
        'content_sha256' => (string)($raw['hash'] ?? ''),
        'content_changed' => !empty($raw['changed']),
        'raw_file' => (string)($raw['raw_file'] ?? ''),
        'observation_only' => true,
    ];
}

function rfcm_check_racing_reference_season(int $year, int $raceNumber, array $config, int $timeout, string $rawDir, array $previousState): array
{
    if ($raceNumber <= 0) {
        return rfcm_source_error('race_number_unavailable', 'MRL race number could not be resolved from _race_results_schedule.json.');
    }

    $sourceConfig = isset($config['sources']['racing_reference_season']) && is_array($config['sources']['racing_reference_season'])
        ? $config['sources']['racing_reference_season'] : [];
    $template = (string)($sourceConfig['url_template'] ?? 'https://www.racing-reference.info/season-stats/{year}/W');
    $url = rfcm_expand_source_url($template, $year, $raceNumber);

    $response = rfcm_http_get($url, $timeout, 'text/html,application/xhtml+xml,*/*');
    $body = (string)($response['body'] ?? '');
    $seasonRow = rfcm_find_table_row_by_first_cell($body, (string)$raceNumber);
    $seasonRowText = rfcm_html_text($seasonRow);
    $seasonCompleted = $seasonRowText !== '' && rfcm_racing_reference_season_row_completed($seasonRowText);
    $raw = rfcm_save_changed_raw('racing_reference_season', $body, $rawDir, $previousState);

    $status = 'waiting_results';
    $message = 'Season page row has not yet been classified as completed.';
    $evidence = $seasonRowText;

    if (!empty($response['challenge_detected'])) {
        $status = 'blocked_by_challenge';
        $message = 'Racing-Reference Season Page returned a browser challenge after retry.';
        $evidence = 'Browser challenge detected.';
    } elseif (empty($response['ok'])) {
        $status = 'request_failed';
        $message = (string)($response['error'] ?? 'Racing-Reference Season Page request failed.');
        $evidence = 'HTTP ' . (string)($response['http_code'] ?? 0);
    } elseif ($seasonCompleted) {
        $status = 'season_row_completed';
        $message = 'Season page row for the current MRL race appears completed.';
    } elseif ($seasonRowText === '') {
        $status = 'season_row_not_found';
        $message = 'Season page loaded, but the current MRL race row was not found.';
        $evidence = '';
    }

    return [
        'checked' => true,
        'ok' => !empty($response['ok']),
        'status' => $status,
        'message' => $message,
        'checked_at' => rfcm_now(),
        'source_url' => $url,
        'http_code' => (int)($response['http_code'] ?? 0),
        'challenge_detected' => !empty($response['challenge_detected']),
        'attempt_count' => (int)($response['attempt_count'] ?? 1),
        'title' => rfcm_html_title($body),
        'race_number' => $raceNumber,
        'season_row_found' => $seasonRowText !== '',
        'season_row_text' => $seasonRowText,
        'season_row_completed' => $seasonCompleted,
        'evidence' => $evidence,
        'body_bytes' => strlen($body),
        'content_sha256' => (string)($raw['hash'] ?? ''),
        'content_changed' => !empty($raw['changed']),
        'raw_file' => (string)($raw['raw_file'] ?? ''),
        'observation_only' => true,
    ];
}

function rfcm_check_jayski(int $year, int $raceNumber, array $nascar, array $config, int $timeout, string $rawDir, array $previousState): array
{
    if ($raceNumber <= 0) {
        return rfcm_source_error('race_number_unavailable', 'MRL race number could not be resolved from _race_results_schedule.json.');
    }

    $sourceConfig = isset($config['sources']['jayski']) && is_array($config['sources']['jayski'])
        ? $config['sources']['jayski'] : [];
    $indexUrl = rfcm_expand_source_url((string)($sourceConfig['index_url_template'] ?? ''), $year, $raceNumber);
    $indexResponse = rfcm_http_get($indexUrl, $timeout, 'text/html,application/xhtml+xml,*/*');
    $indexBody = (string)($indexResponse['body'] ?? '');
    $indexRaw = rfcm_save_changed_raw('jayski_index', $indexBody, $rawDir, $previousState);

    $block = rfcm_extract_jayski_race_block($indexBody, $raceNumber);
    $blockText = rfcm_html_text($block);
    $winner = rfcm_extract_jayski_winner($blockText);
    $resultsUrl = rfcm_extract_jayski_results_url($block, $indexUrl);

    $resultsResponse = ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'No Results link found.'];
    $resultsBody = '';
    $resultsRaw = ['hash' => '', 'changed' => false, 'raw_file' => ''];
    $resultsHeading = false;
    $pdfLink = '';
    if ($resultsUrl !== '') {
        $resultsResponse = rfcm_http_get($resultsUrl, $timeout, 'text/html,application/xhtml+xml,*/*');
        $resultsBody = (string)($resultsResponse['body'] ?? '');
        $resultsRaw = rfcm_save_changed_raw('jayski_results', $resultsBody, $rawDir, $previousState);
        $resultsText = rfcm_html_text($resultsBody);
        $resultsHeading = stripos($resultsText, 'Race Results') !== false;
        $pdfLink = rfcm_extract_first_pdf_url($resultsBody, $resultsUrl);
    }

    $winnerPosted = $winner !== '';
    $resultsPagePosted = !empty($resultsResponse['ok']) && $resultsHeading && $pdfLink !== '';
    if (!empty($indexResponse['challenge_detected'])) {
        $status = 'index_blocked_by_challenge';
        $message = 'Jayski returned a browser challenge after retry.';
    } elseif (empty($indexResponse['ok'])) {
        $status = 'index_request_failed';
        $message = (string)($indexResponse['error'] ?? 'Jayski yearly results page request failed.');
    } elseif ($block === '') {
        $status = 'race_block_not_found';
        $message = 'Jayski yearly page loaded, but the requested race-number block was not found.';
    } elseif ($winnerPosted && $resultsPagePosted) {
        $status = 'winner_and_results_posted';
        $message = 'Winner is populated and the linked results page contains a Race Results heading and PDF link.';
    } elseif ($resultsPagePosted) {
        $status = 'results_page_posted';
        $message = 'The linked Jayski results page appears populated.';
    } elseif ($winnerPosted) {
        $status = 'winner_posted';
        $message = 'The Jayski yearly race block has a populated winner field.';
    } else {
        $status = 'waiting_results';
        $message = 'Race block found; winner and populated results page have not been confirmed.';
    }

    return [
        'checked' => true,
        'ok' => !empty($indexResponse['ok']),
        'status' => $status,
        'message' => $message,
        'checked_at' => rfcm_now(),
        'source_url' => $indexUrl,
        'index_url' => $indexUrl,
        'results_url' => $resultsUrl,
        'index_http_code' => (int)($indexResponse['http_code'] ?? 0),
        'index_challenge_detected' => !empty($indexResponse['challenge_detected']),
        'index_attempt_count' => (int)($indexResponse['attempt_count'] ?? 1),
        'results_http_code' => (int)($resultsResponse['http_code'] ?? 0),
        'title' => rfcm_html_title($indexBody),
        'race_number' => $raceNumber,
        'race_block_found' => $block !== '',
        'race_block_text' => $blockText,
        'winner' => $winner,
        'winner_posted' => $winnerPosted,
        'results_link_found' => $resultsUrl !== '',
        'results_heading_present' => $resultsHeading,
        'results_pdf_url' => $pdfLink,
        'results_page_posted' => $resultsPagePosted,
        'body_bytes' => strlen($indexBody),
        'content_sha256' => (string)($indexRaw['hash'] ?? ''),
        'content_changed' => !empty($indexRaw['changed']),
        'raw_file' => (string)($indexRaw['raw_file'] ?? ''),
        'index_raw_file' => (string)($indexRaw['raw_file'] ?? ''),
        'results_content_sha256' => (string)($resultsRaw['hash'] ?? ''),
        'results_content_changed' => !empty($resultsRaw['changed']),
        'results_raw_file' => (string)($resultsRaw['raw_file'] ?? ''),
        'observation_only' => true,
    ];
}

function rfcm_source_error(string $status, string $message): array
{
    return [
        'checked' => false,
        'ok' => false,
        'status' => $status,
        'message' => $message,
        'checked_at' => rfcm_now(),
        'observation_only' => true,
    ];
}

function rfcm_expand_source_url(string $template, int $year, int $raceNumber): string
{
    return str_replace(
        ['{year}', '{race_number}'],
        [rawurlencode((string)$year), rawurlencode((string)$raceNumber)],
        trim($template)
    );
}

function rfcm_save_changed_raw(string $key, string $body, string $rawDir, array $previousState): array
{
    $hash = $body !== '' ? hash('sha256', $body) : '';
    $previousHash = (string)($previousState['last_raw_hashes'][$key] ?? '');
    $changed = $hash !== '' && $hash !== $previousHash;
    $rawFile = '';
    if ($body !== '' && ($changed || $previousHash === '')) {
        $rawFile = $key . '_' . date('Ymd_His') . '_' . substr($hash, 0, 12) . '.html';
        rfcm_write_file_atomic($rawDir . '/' . $rawFile, $body);
    }
    return [
        'hash' => $hash,
        'changed' => $changed,
        'raw_file' => $rawFile !== '' ? 'raw/' . $rawFile : '',
    ];
}

function rfcm_racing_reference_position_one_evidence(string $html): string
{
    if ($html === '') return '';
    if (!preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $html, $rows)) return '';

    foreach ($rows[1] as $row) {
        $rowHtml = (string)$row;
        $cells = [];
        if (preg_match_all('#<t[dh][^>]*>(.*?)</t[dh]>#is', $rowHtml, $cellMatches)) {
            foreach ($cellMatches[1] as $cellHtml) {
                $cells[] = trim(rfcm_html_text((string)$cellHtml));
            }
        }
        if (empty($cells)) continue;

        $first = preg_replace('/[^0-9]/', '', (string)$cells[0]);
        if ($first !== '1') continue;

        $rowText = implode(' | ', array_values(array_filter($cells, static function ($value) {
            return trim((string)$value) !== '';
        })));
        if ($rowText === '') continue;

        $driverLinkPresent = preg_match('#/driver/[^"\']+#i', $rowHtml) === 1;
        $enoughCells = count($cells) >= 3;
        if ($driverLinkPresent || $enoughCells) {
            return $rowText;
        }
    }
    return '';
}

function rfcm_find_table_row_by_first_cell(string $html, string $wanted): string
{
    if ($html === '' || $wanted === '') return '';
    if (!preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $html, $rows)) return '';
    foreach ($rows[0] as $index => $fullRow) {
        $inner = (string)$rows[1][$index];
        if (preg_match('#<t[dh][^>]*>(.*?)</t[dh]>#is', $inner, $cell)) {
            $first = trim(rfcm_html_text((string)$cell[1]));
            if ($first === $wanted) return (string)$fullRow;
        }
    }
    return '';
}

function rfcm_racing_reference_season_row_completed(string $rowText): bool
{
    if ($rowText === '') return false;
    $lower = strtolower($rowText);
    if (strpos($lower, 'presented by') !== false && !preg_match('/\b\d{2,3}\.\d{3}\b/', $rowText)) return false;
    return preg_match('/\b\d+\b.*\b[A-Za-z][A-Za-z .\'-]+\b.*\b(Toyota|Ford|Chevrolet)\b/i', $rowText) === 1
        && preg_match('/\b\d{2,3}\b/', $rowText) === 1;
}

function rfcm_extract_jayski_race_block(string $html, int $raceNumber): string
{
    if ($html === '' || $raceNumber <= 0) return '';
    $pattern = '/Race\s*#\s*' . preg_quote((string)$raceNumber, '/') . '\s*of\s*36/i';
    if (!preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE)) return '';
    $start = (int)$m[0][1];
    $tail = substr($html, $start);
    $nextPattern = '/Race\s*#\s*' . preg_quote((string)($raceNumber + 1), '/') . '\s*of\s*36/i';
    if (preg_match($nextPattern, $tail, $n, PREG_OFFSET_CAPTURE)) {
        return substr($tail, 0, (int)$n[0][1]);
    }
    return substr($tail, 0, 50000);
}

function rfcm_extract_jayski_winner(string $blockText): string
{
    if ($blockText === '') return '';
    if (preg_match('/Race Winner\s*(?:\|\s*)?(#\s*\d+\s*-\s*.+?)(?=Entry\/Practice|Qualifying\/Lineup|Race\s+Results|Results|Driver Points|Owner Points|Cumulative|Infractions|$)/is', $blockText, $m)) {
        return trim(preg_replace('/\s+/', ' ', (string)$m[1]));
    }
    return '';
}

function rfcm_extract_jayski_results_url(string $blockHtml, string $baseUrl): string
{
    if ($blockHtml === '') return '';
    if (preg_match('#<a[^>]+href=["\']([^"\']+)["\'][^>]*>\s*Results\s*</a>#is', $blockHtml, $m)) {
        return rfcm_absolute_url($baseUrl, html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}

function rfcm_extract_first_pdf_url(string $html, string $baseUrl): string
{
    if ($html === '') return '';
    if (preg_match('#<a[^>]+href=["\']([^"\']+\.pdf(?:\?[^"\']*)?)["\']#is', $html, $m)) {
        return rfcm_absolute_url($baseUrl, html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}

function rfcm_absolute_url(string $baseUrl, string $href): string
{
    $href = trim($href);
    if ($href === '') return '';
    if (preg_match('#^https?://#i', $href)) return $href;
    $parts = parse_url($baseUrl);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return $href;
    $root = $parts['scheme'] . '://' . $parts['host'];
    if (strpos($href, '//') === 0) return $parts['scheme'] . ':' . $href;
    if (strpos($href, '/') === 0) return $root . $href;
    $path = isset($parts['path']) ? (string)$parts['path'] : '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    return $root . $dir . $href;
}

function rfcm_not_checked(string $message): array
{
    return [
        'checked' => false,
        'ok' => true,
        'status' => 'not_due',
        'message' => $message,
        'checked_at' => rfcm_now(),
        'observation_only' => true,
    ];
}

function rfcm_apply_source_transition_timestamps(array &$source, string $sourceKey, string $checkedAt, array &$transitions): void
{
    $status = trim((string)($source['status'] ?? ''));
    if ($status === '') return;

    if (!isset($transitions[$sourceKey]) || !is_array($transitions[$sourceKey])) {
        $transitions[$sourceKey] = [];
    }
    if (empty($transitions[$sourceKey][$status])) {
        $transitions[$sourceKey][$status] = $checkedAt;
    }

    $source['first_status_at'] = (string)$transitions[$sourceKey][$status];
    $firstPostedAt = '';
    foreach ($transitions[$sourceKey] as $knownStatus => $knownAt) {
        if (rfcm_status_is_posted((string)$knownStatus)) {
            if ($firstPostedAt === '' || strtotime((string)$knownAt) < strtotime($firstPostedAt)) {
                $firstPostedAt = (string)$knownAt;
            }
        }
    }
    $source['first_posted_at'] = $firstPostedAt;
}

function rfcm_status_is_posted(string $status): bool
{
    $status = strtolower($status);
    return strpos($status, 'results_posted') !== false
        || strpos($status, 'results_page_posted') !== false
        || strpos($status, 'winner_and_results_posted') !== false
        || strpos($status, 'season_row_completed') !== false;
}

function rfcm_choose_cadence(float $progress, string $flagLabel, array $config): array
{
    $c = isset($config['cadence_minutes']) && is_array($config['cadence_minutes'])
        ? $config['cadence_minutes']
        : [];

    if ($flagLabel === 'CHECKERED') {
        return ['minutes' => max(1, (int)($c['checkered'] ?? 1)), 'label' => 'Checkered flag observation'];
    }
    if ($flagLabel === 'WHITE') {
        return ['minutes' => max(1, (int)($c['white_flag'] ?? 1)), 'label' => 'White flag observation'];
    }
    if ($progress >= 97.0) {
        return ['minutes' => max(1, (int)($c['97_to_99_999_percent'] ?? 2)), 'label' => '97%-finish observation'];
    }
    if ($progress >= 90.0) {
        return ['minutes' => max(1, (int)($c['90_to_96_999_percent'] ?? 5)), 'label' => '90%-96.999% observation'];
    }
    if ($progress > 0.0) {
        return ['minutes' => max(1, (int)($c['below_90_percent'] ?? 5)), 'label' => 'Below 90% observation'];
    }
    return ['minutes' => max(1, (int)($c['unknown_progress'] ?? 5)), 'label' => 'Unknown progress observation'];
}

function rfcm_collect_raw_hashes(array $sources, array $previousState): array
{
    $hashes = isset($previousState['last_raw_hashes']) && is_array($previousState['last_raw_hashes'])
        ? $previousState['last_raw_hashes']
        : [];
    $map = [
        'racing_reference_race' => (string)($sources['racing_reference_race']['content_sha256'] ?? ''),
        'racing_reference_season' => (string)($sources['racing_reference_season']['content_sha256'] ?? ''),
        'jayski_index' => (string)($sources['jayski']['content_sha256'] ?? ''),
        'jayski_results' => (string)($sources['jayski']['results_content_sha256'] ?? ''),
    ];
    foreach ($map as $key => $hash) {
        if ($hash !== '') $hashes[$key] = $hash;
    }
    return $hashes;
}


function rfcm_http_get(string $url, int $timeout, string $accept): array
{
    $first = rfcm_http_get_once($url, $timeout, $accept);
    if (!empty($first['ok']) && empty($first['challenge_detected'])) {
        $first['attempt_count'] = 1;
        return $first;
    }

    usleep(350000);
    $second = rfcm_http_get_once($url, $timeout, $accept);
    $second['attempt_count'] = 2;
    $second['first_attempt_http_code'] = (int)($first['http_code'] ?? 0);
    $second['first_attempt_challenge_detected'] = !empty($first['challenge_detected']);
    return $second;
}

function rfcm_http_get_once(string $url, int $timeout, string $accept): array
{
    $timeout = max(2, min(30, $timeout));
    $browserUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36';
    $headers = [
        'Accept: ' . $accept,
        'Accept-Language: en-US,en;q=0.9',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
    ];

    if (function_exists('curl_init')) {
        $cookieHost = preg_replace('/[^a-z0-9._-]+/i', '_', (string)parse_url($url, PHP_URL_HOST));
        $cookieFile = sys_get_temp_dir() . '/mrl_rfcm_' . $cookieHost . '_cookies.txt';
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_USERAGENT, $browserUa);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            $body = is_string($body) ? $body : '';
            $challenge = rfcm_detect_challenge($code, $body);
            return [
                'ok' => $body !== '' && $code >= 200 && $code < 400 && empty($challenge),
                'url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
                'http_code' => $code,
                'content_type' => $type,
                'body' => $body,
                'error' => $body === '' ? $error : '',
                'challenge_detected' => !empty($challenge),
                'challenge_reasons' => $challenge,
            ];
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "User-Agent: " . $browserUa . "\r\n" . implode("\r\n", $headers) . "\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $context);
    $body = is_string($body) ? $body : '';
    $code = 0;
    $type = '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string)$line, $m)) $code = (int)$m[1];
            if (stripos((string)$line, 'Content-Type:') === 0) $type = trim(substr((string)$line, 13));
        }
    }
    $challenge = rfcm_detect_challenge($code, $body);
    return [
        'ok' => $body !== '' && ($code === 0 || ($code >= 200 && $code < 400)) && empty($challenge),
        'url' => $url,
        'http_code' => $code,
        'content_type' => $type,
        'body' => $body,
        'error' => $body === '' ? 'Request failed.' : '',
        'challenge_detected' => !empty($challenge),
        'challenge_reasons' => $challenge,
    ];
}

function rfcm_detect_challenge(int $httpCode, string $body): array
{
    $reasons = [];
    $lower = strtolower($body);
    if ($httpCode === 403) $reasons[] = 'HTTP 403';
    if (strpos($lower, '<title>just a moment') !== false) $reasons[] = 'Just a moment challenge title';
    if (strpos($lower, 'cf-chl-') !== false) $reasons[] = 'Cloudflare challenge markup';
    if (strpos($lower, 'enable javascript and cookies to continue') !== false) $reasons[] = 'JavaScript/cookie challenge';
    return array_values(array_unique($reasons));
}

function rfcm_response_summary(array $response): array
{
    return [
        'url' => (string)($response['url'] ?? ''),
        'ok' => !empty($response['ok']),
        'http_code' => (int)($response['http_code'] ?? 0),
        'content_type' => (string)($response['content_type'] ?? ''),
        'error' => (string)($response['error'] ?? ''),
    ];
}

function rfcm_extract_weekend_race(array $data): array
{
    if (isset($data['weekend_race']) && is_array($data['weekend_race'])) {
        if (isset($data['weekend_race'][0]) && is_array($data['weekend_race'][0])) {
            return $data['weekend_race'][0];
        }
        return $data['weekend_race'];
    }
    return [];
}

function rfcm_first_int(array $data, array $keys): int
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
            return (int)$data[$key];
        }
    }
    return 0;
}

function rfcm_first_string(array $data, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data) && !is_array($data[$key]) && trim((string)$data[$key]) !== '') {
            return trim((string)$data[$key]);
        }
    }
    return '';
}

function rfcm_flag_label(int $state): string
{
    switch ($state) {
        case 1: return 'GREEN';
        case 2: return 'CAUTION';
        case 3: return 'RED';
        case 4: return 'WHITE';
        case 5:
        case 9: return 'CHECKERED';
        default: return $state > 0 ? 'FLAG ' . $state : 'UNKNOWN';
    }
}

function rfcm_match_tokens(string $value): array
{
    $value = strtolower(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    $stop = ['nascar','cup','series','race','the','at','of','and','presented','by','speedway','motor','international','raceway'];
    $tokens = [];
    foreach (preg_split('/\s+/', trim((string)$value)) as $token) {
        if (strlen($token) < 4 || in_array($token, $stop, true)) {
            continue;
        }
        $tokens[] = $token;
    }
    return array_values(array_unique(array_slice($tokens, 0, 10)));
}

function rfcm_html_text(string $html): string
{
    if ($html === '') return '';
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
    $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', (string)$html);
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function rfcm_html_title(string $html): string
{
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
    return '';
}

function rfcm_expand_url(string $url, int $year): string
{
    return str_replace('{year}', rawurlencode((string)$year), trim($url));
}

function rfcm_now(): string
{
    return date('Y-m-d H:i:s');
}

function rfcm_load_json(string $path): array
{
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function rfcm_write_json_atomic(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return is_string($json) && rfcm_write_file_atomic($path, $json . "\n");
}

function rfcm_write_file_atomic(string $path, string $content): bool
{
    rfcm_ensure_dir(dirname($path));
    $tmp = $path . '.tmp_' . bin2hex(random_bytes(5));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function rfcm_append_log(string $path, string $line): void
{
    @file_put_contents($path, '[' . rfcm_now() . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function rfcm_ensure_dir(string $path): void
{
    if (!is_dir($path)) @mkdir($path, 0755, true);
}

function rfcm_prune(string $dir, string $prefix, string $suffix, int $keep): void
{
    if ($keep < 1 || !is_dir($dir)) return;
    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        if ($prefix !== '' && strpos($name, $prefix) !== 0) continue;
        if ($suffix !== '' && substr($name, -strlen($suffix)) !== $suffix) continue;
        $path = $dir . '/' . $name;
        if (is_file($path)) $files[$path] = filemtime($path) ?: 0;
    }
    arsort($files);
    foreach (array_slice(array_keys($files), $keep) as $path) @unlink($path);
}

function rfcm_out(bool $verbose, string $message): void
{
    if (!$verbose) return;
    if (PHP_SAPI === 'cli') {
        echo $message . PHP_EOL;
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message . "\n";
    }
}

function rfcm_release_lock($handle): void
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}
