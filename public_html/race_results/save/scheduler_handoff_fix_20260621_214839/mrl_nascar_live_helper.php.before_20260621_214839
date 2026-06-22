<?php
declare(strict_types=1);

/**
 * mrl_nascar_live_helper.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/21/2026 5:44:47 pm
 *
 * DESCRIPTION:
 * Helper functions for MRL's optional, info-only NASCAR At a Glance panel.
 * This file reads public NASCAR live JSON feeds and normalizes a small status
 * record for dashboard display.
 *
 * IMPORTANT:
 * - This does not drive MRL scoring.
 * - This does not drive final detection.
 * - This does not send emails.
 * - This does not replace ESPN/source-site snapshot logic.
 * - It is display/cache only.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const MRL_NASCAR_LIVE_HELPER_VERSION = 'v001';
const MRL_NASCAR_LIVE_HELPER_SIGNATURE = 'MRL_NASCAR_LIVE_HELPER v001';

function mrl_nascar_live_cache_path(string $baseDir): string
{
    return rtrim($baseDir, '/\\') . '/_mrl_nascar_live_status.json';
}

function mrl_nascar_live_raw_cache_path(string $baseDir): string
{
    return rtrim($baseDir, '/\\') . '/_mrl_nascar_live_status_raw.json';
}

function mrl_nascar_live_now_string(): string
{
    return date('Y-m-d H:i:s');
}

function mrl_nascar_live_write_atomic(string $path, string $content): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $tmp = $path . '.tmp_' . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function mrl_nascar_live_json_pretty(array $data): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{}';
}

function mrl_nascar_live_http_get(string $url, int $timeoutSeconds = 6): array
{
    $timeoutSeconds = max(2, min(20, $timeoutSeconds));

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_USERAGENT, 'MRL NASCAR Live Helper/' . MRL_NASCAR_LIVE_HELPER_VERSION);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json,text/plain,*/*',
                'Cache-Control: no-cache',
            ]);

            $body = curl_exec($ch);
            $error = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            return [
                'ok' => ($body !== false && $code >= 200 && $code < 400),
                'url' => $url,
                'http_code' => $code,
                'content_type' => $contentType,
                'body' => is_string($body) ? $body : '',
                'error' => $body === false ? $error : '',
            ];
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "User-Agent: MRL NASCAR Live Helper/" . MRL_NASCAR_LIVE_HELPER_VERSION . "\r\nAccept: application/json,text/plain,*/*\r\nCache-Control: no-cache\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $code = 0;
    $contentType = '';

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            $line = (string)$headerLine;
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
                $code = (int)$m[1];
            }
            if (stripos($line, 'Content-Type:') === 0) {
                $contentType = trim(substr($line, strlen('Content-Type:')));
            }
        }
    }

    return [
        'ok' => ($body !== false && ($code === 0 || ($code >= 200 && $code < 400))),
        'url' => $url,
        'http_code' => $code,
        'content_type' => $contentType,
        'body' => is_string($body) ? $body : '',
        'error' => $body === false ? 'Request failed.' : '',
    ];
}

function mrl_nascar_live_decode_json_response(array $response): array
{
    $body = (string)($response['body'] ?? '');
    if ($body === '') {
        return [];
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

function mrl_nascar_live_first_int(array $data, array $keys): int
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
            return (int)$data[$key];
        }
    }
    return 0;
}

function mrl_nascar_live_first_string(array $data, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data) && !is_array($data[$key]) && trim((string)$data[$key]) !== '') {
            return trim((string)$data[$key]);
        }
    }
    return '';
}

function mrl_nascar_live_elapsed_label($secondsValue): string
{
    if ($secondsValue === null || $secondsValue === '') {
        return '';
    }

    $seconds = (int)$secondsValue;
    if ($seconds < 0) {
        $seconds = 0;
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secondsPart = $seconds % 60;

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secondsPart);
}

function mrl_nascar_live_flag_label(int $flagState): string
{
    switch ($flagState) {
        case 1:
            return 'GREEN';
        case 2:
            return 'CAUTION';
        case 3:
            return 'RED';
        case 4:
            return 'WHITE';
        case 5:
            return 'CHECKERED';
        default:
            return $flagState > 0 ? 'FLAG ' . (string)$flagState : 'UNKNOWN';
    }
}

function mrl_nascar_live_flag_class(string $flagLabel): string
{
    $flagLabel = strtoupper(trim($flagLabel));
    if ($flagLabel === 'GREEN' || $flagLabel === 'CHECKERED') {
        return 'good';
    }
    if ($flagLabel === 'CAUTION' || $flagLabel === 'WHITE') {
        return 'warn';
    }
    if ($flagLabel === 'RED') {
        return 'bad';
    }
    return 'warn';
}

function mrl_nascar_live_extract_weekend_race(array $weekendData): array
{
    if (isset($weekendData['weekend_race']) && is_array($weekendData['weekend_race'])) {
        if (isset($weekendData['weekend_race'][0]) && is_array($weekendData['weekend_race'][0])) {
            return $weekendData['weekend_race'][0];
        }
        return $weekendData['weekend_race'];
    }

    return [];
}

function mrl_nascar_live_stage_from_data(array $liveData, array $raceMeta): array
{
    $lap = mrl_nascar_live_first_int($liveData, ['lap_number', 'lap', 'current_lap']);
    $totalLaps = mrl_nascar_live_first_int($liveData, ['laps_in_race', 'scheduled_laps', 'actual_laps']);
    if ($totalLaps <= 0) {
        $totalLaps = mrl_nascar_live_first_int($raceMeta, ['actual_laps', 'scheduled_laps']);
    }

    $stageData = [];
    if (isset($liveData['stage']) && is_array($liveData['stage'])) {
        $stageData = $liveData['stage'];
    }

    $stageNumber = mrl_nascar_live_first_int($stageData, ['stage_num', 'stage_number', 'number']);
    $stageCurrent = mrl_nascar_live_first_int($stageData, ['stage_lap', 'stage_lap_number', 'lap_number', 'lap']);
    $stageTotal = mrl_nascar_live_first_int($stageData, ['stage_laps', 'stage_total_laps', 'finish_at_lap', 'finish_lap']);

    $s1 = mrl_nascar_live_first_int($raceMeta, ['stage_1_laps']);
    $s2Raw = mrl_nascar_live_first_int($raceMeta, ['stage_2_laps']);

    if ($stageNumber <= 0 && $lap > 0 && $totalLaps > 0 && $s1 > 0) {
        $s2End = 0;
        if ($s2Raw > $s1 && $s2Raw < $totalLaps) {
            $s2End = $s2Raw;
        } elseif ($s2Raw > 0 && ($s1 + $s2Raw) < $totalLaps) {
            $s2End = $s1 + $s2Raw;
        }

        if ($lap <= $s1) {
            $stageNumber = 1;
            $stageCurrent = $lap;
            $stageTotal = $s1;
        } elseif ($s2End > 0 && $lap <= $s2End) {
            $stageNumber = 2;
            $stageCurrent = max(1, $lap - $s1);
            $stageTotal = max(1, $s2End - $s1);
        } else {
            $stageNumber = 3;
            $prior = $s2End > 0 ? $s2End : $s1;
            $stageCurrent = max(1, $lap - $prior);
            $stageTotal = $totalLaps > $prior ? ($totalLaps - $prior) : 0;
        }
    }

    if ($stageNumber <= 0 && $stageCurrent <= 0 && $stageTotal <= 0) {
        return [
            'stage_number' => 0,
            'stage_current' => 0,
            'stage_total' => 0,
            'stage_label' => '',
        ];
    }

    if ($stageNumber <= 0) {
        $stageNumber = 1;
    }

    if ($stageCurrent <= 0 && $lap > 0 && $stageNumber === 1) {
        $stageCurrent = $lap;
    }

    $label = 'Stage ' . (string)$stageNumber;
    if ($stageCurrent > 0 && $stageTotal > 0) {
        $label .= ': ' . (string)$stageCurrent . '/' . (string)$stageTotal;
    } elseif ($stageTotal > 0) {
        $label .= ': ' . (string)$stageTotal;
    }

    return [
        'stage_number' => $stageNumber,
        'stage_current' => $stageCurrent,
        'stage_total' => $stageTotal,
        'stage_label' => $label,
    ];
}

function mrl_nascar_live_fetch_weekend_meta(int $year, int $seriesId, int $raceId, int $timeoutSeconds = 6): array
{
    if ($year < 2000 || $seriesId <= 0 || $raceId <= 0) {
        return [
            'meta' => [],
            'source_url' => '',
            'http_code' => 0,
        ];
    }

    $url = 'https://cf.nascar.com/cacher/' . rawurlencode((string)$year) . '/' . rawurlencode((string)$seriesId) . '/' . rawurlencode((string)$raceId) . '/weekend-feed.json';
    $response = mrl_nascar_live_http_get($url, $timeoutSeconds);
    $data = !empty($response['ok']) ? mrl_nascar_live_decode_json_response($response) : [];

    return [
        'meta' => mrl_nascar_live_extract_weekend_race($data),
        'source_url' => $url,
        'http_code' => (int)($response['http_code'] ?? 0),
    ];
}

function mrl_nascar_live_fetch_live_data(int $timeoutSeconds = 6): array
{
    $urls = [
        'https://cf.nascar.com/live/feeds/live-feed.json',
        'https://cf.nascar.com/cacher/live/live-feed.json',
    ];

    $attempts = [];
    foreach ($urls as $url) {
        $response = mrl_nascar_live_http_get($url, $timeoutSeconds);
        $attempts[] = [
            'url' => $url,
            'ok' => !empty($response['ok']),
            'http_code' => (int)($response['http_code'] ?? 0),
            'content_type' => (string)($response['content_type'] ?? ''),
            'error' => (string)($response['error'] ?? ''),
        ];

        if (empty($response['ok'])) {
            continue;
        }

        $data = mrl_nascar_live_decode_json_response($response);
        if (!empty($data) && isset($data['race_id'])) {
            return [
                'ok' => true,
                'source_url' => $url,
                'data' => $data,
                'attempts' => $attempts,
                'message' => 'Live feed loaded.',
            ];
        }
    }

    return [
        'ok' => false,
        'source_url' => '',
        'data' => [],
        'attempts' => $attempts,
        'message' => 'No NASCAR live feed JSON loaded.',
    ];
}

function mrl_nascar_live_normalize_status(array $liveFetch, int $year): array
{
    $liveData = isset($liveFetch['data']) && is_array($liveFetch['data']) ? $liveFetch['data'] : [];
    $raceId = mrl_nascar_live_first_int($liveData, ['race_id']);
    $seriesId = mrl_nascar_live_first_int($liveData, ['series_id']);
    $runId = mrl_nascar_live_first_int($liveData, ['run_id']);
    $trackId = mrl_nascar_live_first_int($liveData, ['track_id']);

    $weekend = mrl_nascar_live_fetch_weekend_meta($year, $seriesId, $raceId, 6);
    $raceMeta = isset($weekend['meta']) && is_array($weekend['meta']) ? $weekend['meta'] : [];

    $lapNumber = mrl_nascar_live_first_int($liveData, ['lap_number', 'lap', 'current_lap']);
    $lapsInRace = mrl_nascar_live_first_int($liveData, ['laps_in_race', 'scheduled_laps', 'actual_laps']);
    if ($lapsInRace <= 0) {
        $lapsInRace = mrl_nascar_live_first_int($raceMeta, ['actual_laps', 'scheduled_laps']);
    }

    $lapsToGo = mrl_nascar_live_first_int($liveData, ['laps_to_go']);
    if ($lapsToGo <= 0 && $lapNumber > 0 && $lapsInRace > 0) {
        $lapsToGo = max(0, $lapsInRace - $lapNumber);
    }

    $flagState = mrl_nascar_live_first_int($liveData, ['flag_state', 'flag']);
    $flagLabel = mrl_nascar_live_flag_label($flagState);
    $stage = mrl_nascar_live_stage_from_data($liveData, $raceMeta);

    $raceName = mrl_nascar_live_first_string($raceMeta, ['race_name', 'run_name']);
    if ($raceName === '') {
        $raceName = mrl_nascar_live_first_string($liveData, ['race_name', 'run_name']);
    }

    $trackName = mrl_nascar_live_first_string($raceMeta, ['track_name']);
    if ($trackName === '') {
        $trackName = mrl_nascar_live_first_string($liveData, ['track_name']);
    }

    $elapsedRaw = array_key_exists('elapsed_time', $liveData) ? $liveData['elapsed_time'] : null;

    return [
        'signature' => MRL_NASCAR_LIVE_HELPER_SIGNATURE,
        'version' => MRL_NASCAR_LIVE_HELPER_VERSION,
        'generated_at' => mrl_nascar_live_now_string(),
        'ok' => !empty($liveFetch['ok']),
        'message' => (string)($liveFetch['message'] ?? ''),
        'source_url' => (string)($liveFetch['source_url'] ?? ''),
        'weekend_source_url' => (string)($weekend['source_url'] ?? ''),
        'year' => $year,
        'race_id' => $raceId,
        'series_id' => $seriesId,
        'run_id' => $runId,
        'track_id' => $trackId,
        'race_name' => $raceName,
        'track_name' => $trackName,
        'flag_state' => $flagState,
        'flag_label' => $flagLabel,
        'flag_class' => mrl_nascar_live_flag_class($flagLabel),
        'lap_number' => $lapNumber,
        'laps_in_race' => $lapsInRace,
        'laps_to_go' => $lapsToGo,
        'lap_label' => ($lapNumber > 0 && $lapsInRace > 0) ? ((string)$lapNumber . '/' . (string)$lapsInRace) : '',
        'elapsed_seconds' => $elapsedRaw === null || $elapsedRaw === '' ? null : (int)$elapsedRaw,
        'elapsed_label' => mrl_nascar_live_elapsed_label($elapsedRaw),
        'stage_number' => (int)$stage['stage_number'],
        'stage_current' => (int)$stage['stage_current'],
        'stage_total' => (int)$stage['stage_total'],
        'stage_label' => (string)$stage['stage_label'],
        'attempts' => isset($liveFetch['attempts']) && is_array($liveFetch['attempts']) ? $liveFetch['attempts'] : [],
    ];
}

function mrl_nascar_live_update_cache(string $baseDir, int $year, int $timeoutSeconds = 6): array
{
    $baseDir = rtrim($baseDir, '/\\');
    $liveFetch = mrl_nascar_live_fetch_live_data($timeoutSeconds);

    if (empty($liveFetch['ok'])) {
        $status = [
            'signature' => MRL_NASCAR_LIVE_HELPER_SIGNATURE,
            'version' => MRL_NASCAR_LIVE_HELPER_VERSION,
            'generated_at' => mrl_nascar_live_now_string(),
            'ok' => false,
            'message' => (string)($liveFetch['message'] ?? 'No NASCAR live feed JSON loaded.'),
            'source_url' => '',
            'year' => $year,
            'race_id' => 0,
            'series_id' => 0,
            'flag_label' => 'UNKNOWN',
            'flag_class' => 'warn',
            'lap_label' => '',
            'stage_label' => '',
            'elapsed_label' => '',
            'attempts' => isset($liveFetch['attempts']) && is_array($liveFetch['attempts']) ? $liveFetch['attempts'] : [],
        ];
    } else {
        $status = mrl_nascar_live_normalize_status($liveFetch, $year);
    }

    $statusPath = mrl_nascar_live_cache_path($baseDir);
    $rawPath = mrl_nascar_live_raw_cache_path($baseDir);

    $wroteStatus = mrl_nascar_live_write_atomic($statusPath, mrl_nascar_live_json_pretty($status) . "\n");

    $rawPayload = [
        'signature' => MRL_NASCAR_LIVE_HELPER_SIGNATURE . ' RAW',
        'generated_at' => mrl_nascar_live_now_string(),
        'source_url' => (string)($liveFetch['source_url'] ?? ''),
        'data' => isset($liveFetch['data']) && is_array($liveFetch['data']) ? $liveFetch['data'] : [],
    ];
    mrl_nascar_live_write_atomic($rawPath, mrl_nascar_live_json_pretty($rawPayload) . "\n");

    $status['cache_path'] = $statusPath;
    $status['raw_cache_path'] = $rawPath;
    $status['cache_written'] = $wroteStatus;

    return $status;
}
