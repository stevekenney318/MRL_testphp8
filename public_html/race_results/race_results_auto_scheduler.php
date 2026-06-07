<?php
declare(strict_types=1);

/**
 * race_results_auto_scheduler.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/6/2026 10:55:38 pm
 *
 * CHANGELOG:
 * v001 (6/6/2026)
 *   - NEW: First-pass calendar-aware race-results auto scheduler.
 *   - NEW: Reads _race_results_schedule.json and _race_results_monitor_state.json.
 *   - NEW: Uses next race start time to decide whether race_results_monitor.php is due.
 *   - NEW: Writes _scheduler/auto_state.json and _scheduler/auto_log.txt for dashboard visibility.
 *   - NEW: Supports dry-run mode and force-run query option for testing.
 */

if (!headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

date_default_timezone_set('America/New_York');

const RACE_RESULTS_AUTO_SCHEDULER_VERSION = 'v001';
const RACE_RESULTS_AUTO_SCHEDULER_SIGNATURE = 'RACE_RESULTS_AUTO_SCHEDULER v001';

$baseDir = __DIR__;
$schedulerDir = $baseDir . '/_scheduler';
$scheduleJsonPath = $baseDir . '/_race_results_schedule.json';
$monitorStatePath = $baseDir . '/_race_results_monitor_state.json';
$manualSchedulerConfigPath = $schedulerDir . '/schedule.json';
$manualSchedulerStatePath = $schedulerDir . '/state.json';
$autoStatePath = $schedulerDir . '/auto_state.json';
$autoLogPath = $schedulerDir . '/auto_log.txt';
$autoLockPath = $schedulerDir . '/auto_scheduler.lock';

if (!is_dir($schedulerDir)) {
    @mkdir($schedulerDir, 0755, true);
}

function rras_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
}

function rras_read_text(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $text = @file_get_contents($path);
    return is_string($text) ? $text : '';
}

function rras_read_json(string $path): array
{
    $text = rras_read_text($path);
    if ($text === '') {
        return [];
    }
    $data = json_decode($text, true);
    return is_array($data) ? $data : [];
}

function rras_write_json(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }
    return @file_put_contents($path, $json . "
", LOCK_EX) !== false;
}

function rras_append_log(string $path, string $line): void
{
    @file_put_contents($path, '[' . rras_now()->format('Y-m-d H:i:s') . '] ' . $line . "
", FILE_APPEND | LOCK_EX);
}

function rras_parse_dt($value): ?DateTimeImmutable
{
    $s = trim((string)$value);
    if ($s === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($s, new DateTimeZone('America/New_York'));
    } catch (Exception $e) {
        return null;
    }
}

function rras_fmt(?DateTimeImmutable $dt): string
{
    if (!$dt) {
        return '';
    }
    return $dt->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d H:i:s');
}

function rras_tail(string $text, int $maxChars = 3000): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (strlen($text) <= $maxChars) {
        return $text;
    }
    return substr($text, -$maxChars);
}

function rras_get_year(array $manualConfig, array $raceSchedule, array $nextRace): int
{
    if (isset($_GET['year']) && preg_match('/^20[0-9]{2}$/', (string)$_GET['year'])) {
        return (int)$_GET['year'];
    }
    if (isset($manualConfig['year']) && (int)$manualConfig['year'] > 2000) {
        return (int)$manualConfig['year'];
    }
    if (isset($raceSchedule['year']) && (int)$raceSchedule['year'] > 2000) {
        return (int)$raceSchedule['year'];
    }
    if (isset($nextRace['year']) && (int)$nextRace['year'] > 2000) {
        return (int)$nextRace['year'];
    }
    return (int)rras_now()->format('Y');
}

function rras_public_base_url(array $manualState): string
{
    if (isset($manualState['public_base_url']) && trim((string)$manualState['public_base_url']) !== '') {
        return rtrim((string)$manualState['public_base_url'], '/');
    }

    if (isset($_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME'])) {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
        $scheme = $https ? 'https' : 'http';
        $dir = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;
    }

    return '';
}

function rras_latest_monitor_run(array $autoState, array $manualState, array $monitorState, int $year): ?DateTimeImmutable
{
    $candidates = [];

    $autoTask = $autoState['tasks']['race_results_monitor'] ?? [];
    if (is_array($autoTask)) {
        foreach (['last_attempt_at', 'last_completed_at', 'last_actual_attempt_at', 'last_actual_completed_at'] as $key) {
            if (!empty($autoTask[$key])) {
                $candidates[] = rras_parse_dt($autoTask[$key]);
            }
        }
    }

    $manualTask = $manualState['tasks']['race_results_monitor'] ?? [];
    if (is_array($manualTask)) {
        foreach (['last_actual_attempt_at', 'last_attempt_at', 'last_actual_completed_at', 'last_completed_at'] as $key) {
            if (!empty($manualTask[$key])) {
                $candidates[] = rras_parse_dt($manualTask[$key]);
            }
        }
    }

    $yearState = $monitorState['byYear'][(string)$year] ?? [];
    if (is_array($yearState)) {
        foreach (['last_checked_at'] as $key) {
            if (!empty($yearState[$key])) {
                $candidates[] = rras_parse_dt($yearState[$key]);
            }
        }
        $raceStatus = $yearState['race_status'] ?? [];
        if (is_array($raceStatus) && !empty($raceStatus['checked_at'])) {
            $candidates[] = rras_parse_dt($raceStatus['checked_at']);
        }
    }

    $latest = null;
    foreach ($candidates as $candidate) {
        if (!$candidate) {
            continue;
        }
        if ($latest === null || $candidate->getTimestamp() > $latest->getTimestamp()) {
            $latest = $candidate;
        }
    }

    return $latest;
}

function rras_monitor_lap_status(array $monitorState, int $year): array
{
    $yearState = $monitorState['byYear'][(string)$year] ?? [];
    if (!is_array($yearState)) {
        return [false, null, null, ''];
    }
    $raceStatus = $yearState['race_status'] ?? [];
    if (!is_array($raceStatus)) {
        $raceStatus = $yearState['current_race_status'] ?? [];
    }
    if (!is_array($raceStatus)) {
        return [false, null, null, ''];
    }
    $found = !empty($raceStatus['lap_status_found']);
    $current = array_key_exists('lap_current', $raceStatus) ? $raceStatus['lap_current'] : null;
    $total = array_key_exists('lap_total', $raceStatus) ? $raceStatus['lap_total'] : null;
    $checkedAt = isset($raceStatus['checked_at']) ? (string)$raceStatus['checked_at'] : '';
    return [$found, $current, $total, $checkedAt];
}

function rras_pick_phase(DateTimeImmutable $now, array $nextRace, bool $lapFound): array
{
    $start = rras_parse_dt($nextRace['start_at'] ?? '');
    if (!$start && !empty($nextRace['start_ts'])) {
        $start = (new DateTimeImmutable('@' . (int)$nextRace['start_ts']))->setTimezone(new DateTimeZone('America/New_York'));
    }

    if (!$start) {
        return [
            'phase' => 'no_next_race_start',
            'label' => 'No next race start time',
            'interval_minutes' => 0,
            'reason' => 'No next race start_at/start_ts found in _race_results_schedule.json.',
            'seconds_to_start' => null,
            'start_at' => '',
        ];
    }

    $seconds = $start->getTimestamp() - $now->getTimestamp();
    $hours = $seconds / 3600;

    if ($seconds > 24 * 3600) {
        return [
            'phase' => 'standby_more_than_24h',
            'label' => 'More than 24h before race',
            'interval_minutes' => 0,
            'reason' => 'Race monitor is not needed more than 24 hours before scheduled start.',
            'seconds_to_start' => $seconds,
            'start_at' => rras_fmt($start),
        ];
    }

    if ($seconds > 6 * 3600) {
        return [
            'phase' => 'race_day_24h_to_6h',
            'label' => '24h to 6h before start',
            'interval_minutes' => 120,
            'reason' => 'Race is within 24 hours; light monitoring every 2 hours.',
            'seconds_to_start' => $seconds,
            'start_at' => rras_fmt($start),
        ];
    }

    if ($seconds > 2 * 3600) {
        return [
            'phase' => 'race_day_6h_to_2h',
            'label' => '6h to 2h before start',
            'interval_minutes' => 30,
            'reason' => 'Race is within 6 hours; monitor every 30 minutes.',
            'seconds_to_start' => $seconds,
            'start_at' => rras_fmt($start),
        ];
    }

    if ($seconds > 0) {
        return [
            'phase' => 'race_day_2h_to_start',
            'label' => '2h to scheduled start',
            'interval_minutes' => 15,
            'reason' => 'Race is within 2 hours; monitor every 15 minutes.',
            'seconds_to_start' => $seconds,
            'start_at' => rras_fmt($start),
        ];
    }

    if ($lapFound) {
        return [
            'phase' => 'lap_status_active_first_pass',
            'label' => 'Lap status active',
            'interval_minutes' => 5,
            'reason' => 'Lap status has been detected; first pass keeps safe 5-minute cadence.',
            'seconds_to_start' => $seconds,
            'start_at' => rras_fmt($start),
        ];
    }

    return [
        'phase' => 'scheduled_start_waiting_for_lap',
        'label' => 'Scheduled start passed; waiting for lap status',
        'interval_minutes' => 5,
        'reason' => 'Scheduled start has passed but lap status is not detected yet; monitor every 5 minutes.',
        'seconds_to_start' => $seconds,
        'start_at' => rras_fmt($start),
    ];
}

function rras_run_monitor(string $publicBaseUrl, int $year, string $token): array
{
    if ($publicBaseUrl === '') {
        return [
            'ok' => false,
            'exit_code' => 1,
            'url' => '',
            'output_tail' => '',
            'message' => 'No public_base_url available. Run from browser once or keep _scheduler/state.json with public_base_url.',
        ];
    }

    $url = rtrim($publicBaseUrl, '/') . '/race_results_monitor.php?year=' . rawurlencode((string)$year)
        . '&auto_sched_token=' . rawurlencode($token)
        . '&auto_sched_ts=' . rawurlencode(rras_now()->format('Ymd_His'));

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 300,
            'ignore_errors' => true,
            'header' => "Cache-Control: no-cache
Pragma: no-cache
",
        ],
    ]);

    $output = @file_get_contents($url, false, $context);
    $outputText = is_string($output) ? $output : '';

    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string)$headerLine, $m)) {
                $httpCode = (int)$m[1];
                break;
            }
        }
    }

    $ok = ($output !== false && ($httpCode === 0 || ($httpCode >= 200 && $httpCode < 400)));

    return [
        'ok' => $ok,
        'exit_code' => $ok ? 0 : 1,
        'url' => $url,
        'output_tail' => rras_tail($outputText),
        'message' => $ok ? 'race_results_monitor.php completed via URL.' : 'race_results_monitor.php request failed. HTTP ' . $httpCode,
        'http_code' => $httpCode,
    ];
}

$now = rras_now();
$runToken = bin2hex(random_bytes(8));
$manualConfig = rras_read_json($manualSchedulerConfigPath);
$manualState = rras_read_json($manualSchedulerStatePath);
$autoStatePrevious = rras_read_json($autoStatePath);
$raceSchedule = rras_read_json($scheduleJsonPath);
$monitorState = rras_read_json($monitorStatePath);
$nextRace = isset($raceSchedule['next_race']) && is_array($raceSchedule['next_race']) ? $raceSchedule['next_race'] : [];
$year = rras_get_year($manualConfig, $raceSchedule, $nextRace);
$dryRun = (!empty($manualConfig['dry_run']) || !empty($_GET['dry_run']));
$force = (!empty($_GET['force']) || !empty($_GET['run_now']));

if (is_file($autoLockPath)) {
    $age = time() - (int)@filemtime($autoLockPath);
    if ($age >= 0 && $age < 600) {
        $state = [
            'signature' => RACE_RESULTS_AUTO_SCHEDULER_SIGNATURE,
            'version' => RACE_RESULTS_AUTO_SCHEDULER_VERSION,
            'generated_at' => $now->format('Y-m-d H:i:s'),
            'last_status' => 'locked',
            'last_message' => 'Auto scheduler lock is still fresh; another run may be in progress.',
            'lock_age_seconds' => $age,
            'tasks' => $autoStatePrevious['tasks'] ?? [],
        ];
        rras_write_json($autoStatePath, $state);
        echo RACE_RESULTS_AUTO_SCHEDULER_SIGNATURE . "
";
        echo "Locked; another auto scheduler run may be in progress.
";
        exit;
    }
}

@file_put_contents($autoLockPath, $runToken . "
", LOCK_EX);

try {
    [$lapFound, $lapCurrent, $lapTotal, $lapCheckedAt] = rras_monitor_lap_status($monitorState, $year);
    $phase = rras_pick_phase($now, $nextRace, $lapFound);
    $interval = (int)$phase['interval_minutes'];
    $lastMonitorRun = rras_latest_monitor_run($autoStatePrevious, $manualState, $monitorState, $year);

    $nextDue = null;
    $due = false;
    $dueReason = '';

    if ($force) {
        $due = true;
        $dueReason = 'force run requested';
    } elseif ($interval <= 0) {
        $due = false;
        $dueReason = 'phase interval is disabled; monitor not due';
    } elseif (!$lastMonitorRun) {
        $due = true;
        $dueReason = 'no previous monitor run found';
    } else {
        $nextDue = $lastMonitorRun->modify('+' . $interval . ' minutes');
        if ($now->getTimestamp() >= $nextDue->getTimestamp()) {
            $due = true;
            $dueReason = 'interval ' . $interval . ' minutes elapsed';
        } else {
            $due = false;
            $remaining = max(0, $nextDue->getTimestamp() - $now->getTimestamp());
            $dueReason = 'not due; ' . (int)ceil($remaining / 60) . ' minutes remaining';
        }
    }

    if ($nextDue === null && $interval > 0 && $lastMonitorRun) {
        $nextDue = $lastMonitorRun->modify('+' . $interval . ' minutes');
    }

    $runResult = [
        'ok' => null,
        'exit_code' => null,
        'url' => '',
        'output_tail' => '',
        'message' => '',
    ];
    $ran = false;
    $status = 'not_due';
    $message = $dueReason;

    if ($due) {
        if ($dryRun) {
            $status = 'dry_run_due';
            $message = 'Dry run: monitor was due but was not executed. ' . $dueReason;
        } else {
            $publicBaseUrl = rras_public_base_url($manualState);
            $runResult = rras_run_monitor($publicBaseUrl, $year, $runToken);
            $ran = true;
            $status = !empty($runResult['ok']) ? 'success' : 'error';
            $message = (string)$runResult['message'];
            $lastMonitorRun = $now;
            $nextDue = $interval > 0 ? $now->modify('+' . $interval . ' minutes') : null;
        }
    }

    $raceLabel = trim((string)($nextRace['mrl_race_code'] ?? '') . ' ' . (string)($nextRace['short_name'] ?? $nextRace['race_name'] ?? ''));

    $state = [
        'signature' => RACE_RESULTS_AUTO_SCHEDULER_SIGNATURE,
        'version' => RACE_RESULTS_AUTO_SCHEDULER_VERSION,
        'generated_at' => $now->format('Y-m-d H:i:s'),
        'last_status' => $status,
        'last_message' => $message,
        'dry_run' => $dryRun,
        'force_requested' => $force,
        'year' => $year,
        'base_dir' => $baseDir,
        'schedule_source' => '_race_results_schedule.json',
        'monitor_state_source' => '_race_results_monitor_state.json',
        'next_race' => [
            'label' => $raceLabel,
            'race_name' => (string)($nextRace['race_name'] ?? ''),
            'short_name' => (string)($nextRace['short_name'] ?? ''),
            'mrl_race_code' => (string)($nextRace['mrl_race_code'] ?? ''),
            'mrl_race_number' => $nextRace['mrl_race_number'] ?? null,
            'start_at' => (string)($nextRace['start_at'] ?? ''),
            'start_text' => trim((string)($nextRace['date_text'] ?? '') . ' ' . (string)($nextRace['time_text'] ?? '')),
        ],
        'decision' => [
            'phase' => $phase['phase'],
            'phase_label' => $phase['label'],
            'interval_minutes' => $interval,
            'due' => $due,
            'due_reason' => $dueReason,
            'seconds_to_start' => $phase['seconds_to_start'],
            'start_at' => $phase['start_at'],
            'last_monitor_run_at' => rras_fmt($lastMonitorRun),
            'next_due_at' => rras_fmt($nextDue),
            'lap_status_found' => $lapFound,
            'lap_current' => $lapCurrent,
            'lap_total' => $lapTotal,
            'lap_checked_at' => $lapCheckedAt,
        ],
        'tasks' => [
            'race_results_monitor' => [
                'last_check_at' => $now->format('Y-m-d H:i:s'),
                'last_check_status' => $status,
                'last_check_message' => $message,
                'last_due_reason' => $dueReason,
                'last_interval_minutes' => $interval,
                'last_due' => $due,
                'last_attempt_at' => $ran ? $now->format('Y-m-d H:i:s') : (string)($autoStatePrevious['tasks']['race_results_monitor']['last_attempt_at'] ?? ''),
                'last_completed_at' => $ran ? rras_now()->format('Y-m-d H:i:s') : (string)($autoStatePrevious['tasks']['race_results_monitor']['last_completed_at'] ?? ''),
                'last_status' => $status,
                'last_message' => $message,
                'last_exit_code' => $runResult['exit_code'],
                'last_output_tail' => $runResult['output_tail'],
                'last_url' => $runResult['url'],
                'last_run_token' => $ran ? $runToken : (string)($autoStatePrevious['tasks']['race_results_monitor']['last_run_token'] ?? ''),
            ],
        ],
    ];

    rras_write_json($autoStatePath, $state);
    rras_append_log($autoLogPath, 'status=' . $status . ' phase=' . $phase['phase'] . ' interval=' . $interval . ' due=' . ($due ? 'yes' : 'no') . ' ran=' . ($ran ? 'yes' : 'no') . ' reason=' . $message);

    echo RACE_RESULTS_AUTO_SCHEDULER_SIGNATURE . "
";
    echo 'Status: ' . $status . "
";
    echo 'Race: ' . ($raceLabel !== '' ? $raceLabel : 'unknown') . "
";
    echo 'Phase: ' . $phase['label'] . "
";
    echo 'Interval: ' . ($interval > 0 ? (string)$interval . ' minutes' : 'disabled') . "
";
    echo 'Due: ' . ($due ? 'yes' : 'no') . "
";
    echo 'Ran monitor: ' . ($ran ? 'yes' : 'no') . "
";
    echo 'Message: ' . $message . "
";
} finally {
    @unlink($autoLockPath);
}
