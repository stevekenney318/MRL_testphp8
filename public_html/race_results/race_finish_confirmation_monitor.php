<?php
declare(strict_types=1);

/**
 * race_finish_confirmation_monitor.php
 *
 * VERSION: v002
 * LAST MODIFIED: 7/24/2026 4:34:17 pm
 *
 * DESCRIPTION:
 * Observation-only NASCAR race finish confirmation monitor.
 *
 * IMPORTANT:
 * - Does not alter or call any existing MRL monitor, scheduler, scoring, snapshot,
 *   standings, revision, email, flag, or final-detection process.
 * - Does not make decisions for MRL.
 * - Records what NASCAR, Racing-Reference, and Jayski show near race completion.
 * - Designed to be launched every minute by the existing MRL master scheduler.
 * - Reads existing MRL JSON/cache files for lap and flag status.
 * - Makes no independent NASCAR network request.
 * - Internal cadence determines when secondary-source observations are due.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const RFCM_VERSION = 'v002';
const RFCM_SIGNATURE = 'MRL_RACE_FINISH_CONFIRMATION_MONITOR v002';

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

$sourceResults = [
    'nascar' => $nascar,
    'racing_reference' => rfcm_check_secondary_source(
        'racing_reference',
        rfcm_expand_url((string)($config['sources']['racing_reference']['url'] ?? ''), $year),
        $nascar,
        $timeout,
        $rawDir,
        $state
    ),
    'jayski' => rfcm_check_secondary_source(
        'jayski',
        rfcm_expand_url((string)($config['sources']['jayski']['url'] ?? ''), $year),
        $nascar,
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
                'url' => 'https://www.racing-reference.info/yeardet/{year}/W'
            ],
            'jayski' => [
                'enabled' => true,
                'url' => 'https://www.jayski.com/nascar-cup-series/{year}-nascar-cup-series-race-results/'
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

function rfcm_check_secondary_source(string $key, string $url, array $nascar, int $timeout, string $rawDir, array $previousState): array
{
    if ($url === '') {
        return [
            'checked' => false,
            'ok' => false,
            'status' => 'not_configured',
            'message' => 'No source URL is configured.',
            'checked_at' => rfcm_now(),
        ];
    }

    $response = rfcm_http_get($url, $timeout, 'text/html,application/xhtml+xml,*/*');
    $body = (string)($response['body'] ?? '');
    $hash = $body !== '' ? hash('sha256', $body) : '';
    $text = rfcm_html_text($body);
    $title = rfcm_html_title($body);
    $raceName = (string)($nascar['race_name'] ?? '');
    $trackName = (string)($nascar['track_name'] ?? '');
    $tokens = rfcm_match_tokens($raceName . ' ' . $trackName);
    $matched = [];
    foreach ($tokens as $token) {
        if ($token !== '' && stripos($text, $token) !== false) {
            $matched[] = $token;
        }
    }

    $completionTerms = ['race results', 'official results', 'results', 'winner', 'post-race'];
    $completionHits = [];
    foreach ($completionTerms as $term) {
        if (stripos($text, $term) !== false) {
            $completionHits[] = $term;
        }
    }

    $raceMatch = count($matched) >= min(2, max(1, count($tokens)));
    $completionEvidence = $raceMatch && !empty($completionHits);
    $previousHash = (string)($previousState['last_raw_hashes'][$key] ?? '');
    $changed = $hash !== '' && $hash !== $previousHash;
    $rawFile = '';

    if ($body !== '' && ($changed || $previousHash === '')) {
        $rawFile = $key . '_' . date('Ymd_His') . '_' . substr($hash, 0, 12) . '.html';
        rfcm_write_file_atomic($rawDir . '/' . $rawFile, $body);
    }

    return [
        'checked' => true,
        'ok' => !empty($response['ok']),
        'status' => !empty($response['ok'])
            ? ($completionEvidence ? 'possible_results_evidence' : 'loaded_no_matching_results_evidence')
            : 'request_failed',
        'message' => !empty($response['ok'])
            ? ($completionEvidence ? 'Race/track wording and results wording were both observed.' : 'Page loaded; matching finish evidence was not observed.')
            : ((string)($response['error'] ?? 'Request failed.')),
        'checked_at' => rfcm_now(),
        'source_url' => $url,
        'http_code' => (int)($response['http_code'] ?? 0),
        'content_type' => (string)($response['content_type'] ?? ''),
        'title' => $title,
        'body_bytes' => strlen($body),
        'content_sha256' => $hash,
        'content_changed' => $changed,
        'raw_file' => $rawFile !== '' ? 'raw/' . $rawFile : '',
        'race_match' => $raceMatch,
        'matched_tokens' => array_values(array_unique($matched)),
        'completion_terms_found' => array_values(array_unique($completionHits)),
        'possible_completion_evidence' => $completionEvidence,
        'observation_only' => true,
    ];
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
    foreach (['racing_reference', 'jayski'] as $key) {
        $hash = (string)($sources[$key]['content_sha256'] ?? '');
        if ($hash !== '') {
            $hashes[$key] = $hash;
        }
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
