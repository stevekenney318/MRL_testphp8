<?php
declare(strict_types=1);

/**
 * race_finish_confirmation_monitor.php
 *
 * VERSION: v003
 * LAST MODIFIED: 7/26/2026 7:16:39 am
 *
 * DESCRIPTION:
 * Observation-only NASCAR race finish confirmation monitor.
 *
 * IMPORTANT:
 * - Does not alter or call any existing MRL monitor, scheduler, scoring, snapshot,
 *   standings, revision, email, flag, or final-detection process.
 * - Does not make decisions for MRL.
 * - Records what existing MRL race status, Racing-Reference, and Jayski show near race completion.
 * - Uses the deterministic Racing-Reference race URL built from MRL year/race number.
 * - Reads the Jayski yearly race block, winner field, Results link, and linked results page.
 * - Designed to be launched every minute by the existing MRL master scheduler.
 * - Reads existing MRL JSON/cache files for lap and flag status.
 * - Makes no independent NASCAR network request.
 * - Internal cadence determines when secondary-source observations are due.
 *
 * CHANGELOG:
 * v003 (7/26/2026 7:16:39 am)
 * - Replaced broad secondary-page keyword matching with source-specific Racing-Reference and Jayski checks.
 * - Racing-Reference now uses /race-results/{year}-{race_number}/W and the yearly season-stats page.
 * - Jayski now finds the known race-number block, detects winner population, extracts its Results link, and checks the linked page.
 * - Added race-number resolution from _race_results_schedule.json and richer saved evidence.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const RFCM_VERSION = 'v003';
const RFCM_SIGNATURE = 'MRL_RACE_FINISH_CONFIRMATION_MONITOR v003';

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
$progress = (float)($nascar['progress_percent'] ?? 0.0);
$flagLabel = strtoupper((string)($nascar['flag_label'] ?? 'UNKNOWN'));
$activationPercent = (float)($config['finish_watch_start_percent'] ?? 90.0);
$sourceAgeMinutes = isset($nascar['source_age_minutes']) ? (float)$nascar['source_age_minutes'] : null;
$maxActivationAge = max(1, (int)($config['status_max_age_minutes_before_activation'] ?? 30));
$previouslyActive = !empty($state['finish_watch_active']);
$statusFreshEnough = $sourceAgeMinutes === null || $sourceAgeMinutes <= $maxActivationAge || $previouslyActive;

$finishWindow = $statusFreshEnough && (
    $progress >= $activationPercent
    || $flagLabel === 'WHITE'
    || $flagLabel === 'CHECKERED'
    || $previouslyActive
);

if (!$finishWindow) {
    $idleReason = empty($nascar['ok'])
        ? 'No usable lap/flag status was found in the existing MRL JSON files.'
        : ($statusFreshEnough
            ? 'Race progress is below the finish-watch activation threshold.'
            : 'Existing MRL race status is stale; ignoring old late-race/checkered data.');

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
    'nascar' => $nascar,
    'racing_reference' => rfcm_check_racing_reference(
        $year,
        $raceNumber,
        $nascar,
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
        'race_name' => (string)($nascar['race_name'] ?? ''),
        'track_name' => (string)($nascar['track_name'] ?? ''),
        'lap_number' => (int)($nascar['lap_number'] ?? 0),
        'laps_in_race' => (int)($nascar['laps_in_race'] ?? 0),
        'progress_percent' => $progress,
        'flag_label' => $flagLabel,
        'mrl_race_number' => (int)($raceIdentity['race_number'] ?? 0),
        'mrl_race_code' => (string)($raceIdentity['race_code'] ?? ''),
        'status_source' => (string)($nascar['status_source'] ?? ''),
        'source_generated_at' => (string)($nascar['source_generated_at'] ?? ''),
        'source_age_minutes' => $nascar['source_age_minutes'] ?? null,
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
];
rfcm_write_json_atomic($stateFile, $state);

rfcm_append_log($logFile, sprintf(
    '%s race_id=%d lap=%d/%d progress=%.2f flag=%s watch=%s cadence=%dm observation=%s',
    $checkedAt,
    (int)($nascar['race_id'] ?? 0),
    (int)($nascar['lap_number'] ?? 0),
    (int)($nascar['laps_in_race'] ?? 0),
    $progress,
    $flagLabel,
    $finishWindow ? 'YES' : 'NO',
    (int)$cadence['minutes'],
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
            'racing_reference' => [
                'enabled' => true,
                'race_url_template' => 'https://www.racing-reference.info/race-results/{year}-{race_number}/W',
                'season_url_template' => 'https://www.racing-reference.info/season-stats/{year}/W'
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


function rfcm_check_racing_reference(int $year, int $raceNumber, array $nascar, array $config, int $timeout, string $rawDir, array $previousState): array
{
    if ($raceNumber <= 0) {
        return rfcm_source_error('race_number_unavailable', 'MRL race number could not be resolved from _race_results_schedule.json.');
    }

    $sourceConfig = isset($config['sources']['racing_reference']) && is_array($config['sources']['racing_reference'])
        ? $config['sources']['racing_reference'] : [];
    $raceUrl = rfcm_expand_source_url((string)($sourceConfig['race_url_template'] ?? ''), $year, $raceNumber);
    $seasonUrl = rfcm_expand_source_url((string)($sourceConfig['season_url_template'] ?? ''), $year, $raceNumber);

    $raceResponse = rfcm_http_get($raceUrl, $timeout, 'text/html,application/xhtml+xml,*/*');
    $raceBody = (string)($raceResponse['body'] ?? '');
    $raceText = rfcm_html_text($raceBody);
    $waitingPhrase = stripos($raceText, 'Results not available yet') !== false;
    $headerPresent = stripos($raceText, 'POS') !== false && stripos($raceText, 'DRIVER') !== false && stripos($raceText, 'LAPS') !== false;
    $driverRows = rfcm_count_racing_reference_driver_rows($raceBody);
    $resultsPosted = !empty($raceResponse['ok']) && !$waitingPhrase && $headerPresent && $driverRows > 0;
    $raceRaw = rfcm_save_changed_raw('racing_reference_race', $raceBody, $rawDir, $previousState);

    $seasonResponse = rfcm_http_get($seasonUrl, $timeout, 'text/html,application/xhtml+xml,*/*');
    $seasonBody = (string)($seasonResponse['body'] ?? '');
    $seasonRow = rfcm_find_table_row_by_first_cell($seasonBody, (string)$raceNumber);
    $seasonRowText = rfcm_html_text($seasonRow);
    $seasonCompleted = $seasonRowText !== '' && rfcm_racing_reference_season_row_completed($seasonRowText);
    $seasonRaw = rfcm_save_changed_raw('racing_reference_season', $seasonBody, $rawDir, $previousState);

    $status = 'waiting_results';
    $message = 'Race page loaded; results are not posted yet.';
    if (empty($raceResponse['ok'])) {
        $status = 'race_page_request_failed';
        $message = (string)($raceResponse['error'] ?? 'Racing-Reference race page request failed.');
    } elseif ($resultsPosted && $seasonCompleted) {
        $status = 'race_and_season_results_posted';
        $message = 'Race page has populated driver results and the season row appears completed.';
    } elseif ($resultsPosted) {
        $status = 'race_results_posted';
        $message = 'Race page has populated driver results.';
    } elseif ($seasonCompleted) {
        $status = 'season_row_completed';
        $message = 'Season results row appears completed while the race page is still being evaluated.';
    } elseif (!$waitingPhrase) {
        $status = 'race_page_changed_unclassified';
        $message = 'Waiting phrase is absent, but populated driver rows were not confirmed.';
    }

    return [
        'checked' => true,
        'ok' => !empty($raceResponse['ok']) || !empty($seasonResponse['ok']),
        'status' => $status,
        'message' => $message,
        'checked_at' => rfcm_now(),
        'source_url' => $raceUrl,
        'race_url' => $raceUrl,
        'season_url' => $seasonUrl,
        'race_http_code' => (int)($raceResponse['http_code'] ?? 0),
        'season_http_code' => (int)($seasonResponse['http_code'] ?? 0),
        'title' => rfcm_html_title($raceBody),
        'race_number' => $raceNumber,
        'waiting_phrase_present' => $waitingPhrase,
        'results_header_present' => $headerPresent,
        'driver_result_rows' => $driverRows,
        'race_results_posted' => $resultsPosted,
        'season_row_found' => $seasonRowText !== '',
        'season_row_text' => $seasonRowText,
        'season_row_completed' => $seasonCompleted,
        'body_bytes' => strlen($raceBody),
        'content_sha256' => (string)($raceRaw['hash'] ?? ''),
        'content_changed' => !empty($raceRaw['changed']),
        'raw_file' => (string)($raceRaw['raw_file'] ?? ''),
        'race_raw_file' => (string)($raceRaw['raw_file'] ?? ''),
        'season_content_sha256' => (string)($seasonRaw['hash'] ?? ''),
        'season_content_changed' => !empty($seasonRaw['changed']),
        'season_raw_file' => (string)($seasonRaw['raw_file'] ?? ''),
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
    if (empty($indexResponse['ok'])) {
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

function rfcm_count_racing_reference_driver_rows(string $html): int
{
    if ($html === '') return 0;
    $count = 0;
    if (preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $html, $rows)) {
        foreach ($rows[1] as $row) {
            $text = rfcm_html_text((string)$row);
            if (preg_match('/^\s*\d+\s+\d+\s+\d+\s+.+\s+\d+\s+(running|finished|accident|engine|transmission|brakes|electrical|dvp|disqualified|crash|rear gear|suspension|vibration|overheating|fuel pump|steering|oil leak|ignition|clutch|battery|handling|driveshaft|hub|wheel|tire|gear|radiator|water pump|power steering|camshaft|piston|valve|carburetor|fire|header|axle|mechanical|parked|withdrawn|did not start)/i', $text)) {
                $count++;
            } elseif (preg_match('#/driver/[^"\']+#i', (string)$row) && preg_match('/\b\d+\b/', $text)) {
                $count++;
            }
        }
    }
    return $count;
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
        'racing_reference_race' => (string)($sources['racing_reference']['content_sha256'] ?? ''),
        'racing_reference_season' => (string)($sources['racing_reference']['season_content_sha256'] ?? ''),
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
    $timeout = max(2, min(30, $timeout));
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_USERAGENT, 'MRL Finish Confirmation Monitor/' . RFCM_VERSION);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: ' . $accept,
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ]);
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            return [
                'ok' => $body !== false && $code >= 200 && $code < 400,
                'url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
                'http_code' => $code,
                'content_type' => $type,
                'body' => is_string($body) ? $body : '',
                'error' => $body === false ? $error : '',
            ];
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "User-Agent: MRL Finish Confirmation Monitor/" . RFCM_VERSION . "\r\nAccept: " . $accept . "\r\nCache-Control: no-cache\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $code = 0;
    $type = '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string)$line, $m)) {
                $code = (int)$m[1];
            }
            if (stripos((string)$line, 'Content-Type:') === 0) {
                $type = trim(substr((string)$line, 13));
            }
        }
    }
    return [
        'ok' => $body !== false && ($code === 0 || ($code >= 200 && $code < 400)),
        'url' => $url,
        'http_code' => $code,
        'content_type' => $type,
        'body' => is_string($body) ? $body : '',
        'error' => $body === false ? 'Request failed.' : '',
    ];
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
