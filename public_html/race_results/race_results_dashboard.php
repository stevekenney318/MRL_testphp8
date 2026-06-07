<?php
declare(strict_types=1);

/**
 * race_results_dashboard.php
 *
 * VERSION: v015
 * LAST MODIFIED: 6/7/2026 10:51:12 am
 *
 * CHANGELOG:
 * v015 (6/7/2026)
 *   - NEW: Scheduler tab supports auto_revision_monitor status written by cron_master_scheduler.php v009.
 *   - NEW: Revision Scheduler can show post-race stabilization phase, interval, handoff race, and next due time.
 *   - CHANGE: Revision Scheduler remains compact while supporting race-aware post-final cadence.
 *   - CHANGE: Shortened Revision Scheduler table schedule text and shows Auto when next run is controlled by auto logic but no exact next-due timestamp is available.
 *
 * v014 (6/7/2026)
 *   - CHANGE: Scheduler tab now treats _scheduler/schedule.json as the single scheduler control file.
 *   - NEW: Adds Race Scheduler panel sourced from cron_master_scheduler.php v008 state.json auto_race_monitor decisions.
 *   - CHANGE: Revision Scheduler is shown separately from Race Scheduler.
 *   - CHANGE: Removes separate auto scheduler state/log presentation from the main scheduler flow.
 *   - NOTE: race_results_auto_scheduler.php is no longer part of the installed scheduling path.
 *
 * v013 (6/6/2026)
 *   - NEW: Added Auto Scheduler panel on Scheduler tab.
 *   - NEW: Reads _scheduler/auto_state.json written by race_results_auto_scheduler.php.
 *   - NEW: Shows auto phase, interval, last monitor run, next due, lap status, and last auto result.
 *   - NEW: Adds raw links for auto_state.json and auto_log.txt.
 *
 * v012 (6/6/2026)
 *   - CHANGE: Renamed monitor card from Current Race Status to Race Status.
 *   - NEW: Reads schedule-aware race_status from monitor state when available.
 *   - NEW: Race Status can show Next Race/Scheduled when the latest final race has handed off to revision monitoring.
 *   - FIX: Race Status no longer shows Open race page for scheduled races that do not yet have a race-page URL.
 *   - FIX: Hardened Race Status button logic so scheduled next-race rows cannot fall back to the previous completed race URL.
 *   - CHANGE: Preserves current_race_status fallback for older monitor state files.
 *
 * v011 (6/3/2026)
 *   - CHANGE: Updated Revision Summary action confirmation wording to refer to the source site instead of ESPN.
 *   - CHANGE: Renamed Revision Summary Run Now to Refresh Summary and added a separate Regenerate Summary action that runs the revision monitor.
 *   - FIX: Aligned collapsed JSON panel headers with the rest of the dashboard card headings.
 *   - CHANGE: Renamed Classification Last Run JSON panel to Revision Summary Data.
 *   - CHANGE: Changed Driver Details toggle now switches between Show and Hide wording.
 *   - CHANGE: Tightened JSON toggle header alignment for better visual consistency with other panels.
 *   - CHANGE: Restyled dashboard action buttons as blue rounded action controls to better distinguish clickable actions from status labels.
 *   - CHANGE: Revision Summary details now use a stable same-row toggle button with remembered open/closed state.
 *   - CHANGE: Run Now notice now auto-hides after display and removes status parameters from the browser URL.
 *   - CHANGE: Revision tab classifier block now presents as Revision Summary with title, generated timestamp, and Run Now action on one line.
 *   - CHANGE: Revision Summary metadata pills now live in an expandable Summary Details section instead of always taking vertical space.
 *   - NEW: Added confirmed Run Now action link for manually refreshing the trusted revision summary/classifier output.
 *   - CHANGE: Revision Summary header now keeps title, generated timestamp, Run Now, and Summary Details on one compact line.
 *   - CHANGE: Run Now now refreshes the trusted revision summary on the dashboard page instead of leaving the user on the classifier report tab.
 *   - FIX: Bundle JSON and scheduler summary now report the merged dashboard version instead of the older scheduler-dashboard version.
 *   - NEW: Added automatic cache-buster values to raw file links so active logs/state/heartbeat files open fresh.
 *   - NEW: Added scheduler heartbeat freshness status to separate current cron heartbeat from scheduler/task configuration.
 *   - CHANGE: Clarified top status wording to Scheduler / Cron / Mode, with dry-run shown as dry run / paused.
 *   - CHANGE: Last Status values now display status pill and exit code on one line, with message on the next line.
 *   - NEW: Raw-file and bundle links refresh their cache-buster value at click time so repeated clicks request fresh content.
 *   - CHANGE: Consolidated Log Lines and Refresh controls into single labeled dropdowns.
 *   - CHANGE: RD Status JSON, Monitor State JSON, and Classification Last Run JSON panels are collapsed by default with Open/Close toggles.
 *   - CHANGE: Styled JSON panel Open/Close toggles as compact blue dashboard controls.
 *
 * v010 (5/31/2026)
 *   - CHANGE: Monitor Current Race Status now reads current_race_status from monitor state JSON.
 *   - CHANGE: Removed dashboard-side ESPN fetch for current race status.
 *   - NOTE: race_results_monitor.php v131 is expected to populate the stored status.
 *
 * v009 (5/31/2026)
 *   - NEW: Monitor tab now shows a first-pass Current Race Status card.
 *   - NEW: Extracts latest ESPN race URL from monitor log/state, fetches page title, and displays simplified current race name.
 *   - NEW: Extracts in-progress lap text such as "leads on lap X of Y" and displays only "Lap X of Y".
 *   - NOTE: This is display-only status; final scoring/snapshot logic is unchanged.
 *
 * v008 (5/30/2026)
 *   - CHANGE: Tightened dashboard header spacing and reduced tab/control height.
 *   - CHANGE: Standardized scheduler/monitor/revision status rows so raw files open in browser.
 *   - NEW: Added next-run countdown rows in monitor/revision status sections.
 *   - CHANGE: Removed top-level scheduler export buttons from normal view.
 *   - CHANGE: Scheduler bundle JSON now opens inline as raw text instead of forcing download.
 *
 * v007 (5/30/2026)
 *   - CHANGE: Reduced top-page clutter by moving tabs above the status/control panel.
 *   - CHANGE: Replaced log-line and auto-refresh button groups with dropdown controls.
 *   - NEW: Added Next Run info to Monitor and Revision tabs.
 *   - CHANGE: Uses friendlier AM/PM-style display for dashboard dates/times where practical.
 *   - CHANGE: Removed normal-view manual classifier report links and moved classifier script info to diagnostics.
 *
 * v006 (5/30/2026)
 *   - NEW: First-pass merged dashboard using scheduler_dashboard.php styling.
 *   - NEW: Adds top-level tabs: Scheduler, Monitor, and Revision.
 *   - NEW: Preserves scheduler dashboard content and race-results monitor/revision dashboard content in one page.
 *   - NOTE: No manual run buttons or Guide tab yet; this build is intended as a layout proof.
 *
 * v005 (5/19/2026)
 *   - Previous race-results-only dashboard baseline.
 */

if (!headers_sent()) {
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

const RACE_RESULTS_DASHBOARD_VERSION = 'v015';


// -----------------------------------------------------------------------------
// Scheduler dashboard data/functions
// -----------------------------------------------------------------------------
date_default_timezone_set('America/New_York');

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

const SCHEDULER_DASHBOARD_VERSION = RACE_RESULTS_DASHBOARD_VERSION;

$baseDir = __DIR__;
$schedulerDir = $baseDir . '/_scheduler';

$schedulePath  = $schedulerDir . '/schedule.json';
$statePath     = $schedulerDir . '/state.json';
$heartbeatPath = $schedulerDir . '/heartbeat.txt';
$logPath       = $schedulerDir . '/log.txt';
$autoStatePath = $schedulerDir . '/auto_state.json';
$autoLogPath   = $schedulerDir . '/auto_log.txt';

function sd_html($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sd_read_text(string $path): string
{
    if (!is_file($path)) {
        return '';
    }

    $text = @file_get_contents($path);
    return is_string($text) ? $text : '';
}

function sd_read_json(string $path): array
{
    $text = sd_read_text($path);
    if ($text === '') {
        return [];
    }

    $data = json_decode($text, true);
    return is_array($data) ? $data : [];
}

function sd_download_text(string $filename, string $content, string $mime = 'text/plain'): void
{
    if (!headers_sent()) {
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    echo $content;
    exit;
}

function sd_export_safe_name(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $value);
    return trim((string)$value, '_');
}

function sd_file_status(string $path): array
{
    if (!is_file($path)) {
        return [
            'present' => false,
            'modified' => '',
            'size' => '',
        ];
    }

    $mtime = @filemtime($path);
    $size = @filesize($path);

    return [
        'present' => true,
        'modified' => $mtime ? rr_dash_display_timestamp((int)$mtime) : '',
        'size' => $size !== false ? sd_format_bytes((int)$size) : '',
    ];
}

function sd_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / 1048576, 2) . ' MB';
}

function sd_tail_lines(string $text, int $limit): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $lines = preg_split('/\R/', $text);
    if (!is_array($lines)) {
        return [];
    }

    $lines = array_values(array_filter($lines, function ($line) {
        return trim((string)$line) !== '';
    }));

    return array_slice($lines, -1 * $limit);
}

function sd_parse_dt(?string $value, DateTimeZone $tz): ?DateTimeImmutable
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value, $tz);
    } catch (Exception $e) {
        return null;
    }
}

function rr_dash_display_datetime($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (strtolower($value) === 'now') {
        return 'now';
    }

    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('America/New_York'));
        return $dt->format('n/j/Y g:i:s a');
    } catch (Exception $e) {
        return $value;
    }
}

function rr_dash_display_timestamp($timestamp): string
{
    if ($timestamp === false || $timestamp === null || (int)$timestamp <= 0) {
        return '';
    }

    return date('n/j/Y g:i:s a', (int)$timestamp);
}

function rr_dash_next_run_epoch(string $value, DateTimeZone $tz): string
{
    $value = trim($value);
    if ($value === '' || strtolower($value) === 'now') {
        return '';
    }

    try {
        $dt = new DateTimeImmutable($value, $tz);
        return (string)$dt->getTimestamp();
    } catch (Exception $e) {
        return '';
    }
}

function sd_time_in_window(DateTimeImmutable $now, string $start, string $end): bool
{
    $cur = $now->format('H:i');
    $start = trim($start);
    $end = trim($end);

    if ($start === '' || $end === '') {
        return false;
    }

    if ($start <= $end) {
        return ($cur >= $start && $cur <= $end);
    }

    // Overnight window, such as 22:00 -> 02:00.
    return ($cur >= $start || $cur <= $end);
}

function sd_effective_interval(array $task, DateTimeImmutable $now): array
{
    $default = isset($task['interval_minutes']) ? (int)$task['interval_minutes'] : 60;
    $reason = 'default interval';

    if (isset($task['windows']) && is_array($task['windows'])) {
        $today = (int)$now->format('N'); // 1=Mon, 7=Sun

        foreach ($task['windows'] as $name => $window) {
            if (!is_array($window)) {
                continue;
            }

            if (isset($window['enabled']) && !$window['enabled']) {
                continue;
            }

            $days = isset($window['days']) && is_array($window['days']) ? $window['days'] : [];
            $dayMatch = empty($days) || in_array($today, array_map('intval', $days), true);

            if (!$dayMatch) {
                continue;
            }

            $start = isset($window['start']) ? (string)$window['start'] : '';
            $end = isset($window['end']) ? (string)$window['end'] : '';

            if (!sd_time_in_window($now, $start, $end)) {
                continue;
            }

            $interval = isset($window['interval_minutes']) ? (int)$window['interval_minutes'] : $default;

            return [
                'minutes' => max(1, $interval),
                'reason' => 'window: ' . (string)$name,
            ];
        }
    }

    return [
        'minutes' => max(1, $default),
        'reason' => $reason,
    ];
}

function sd_interval_task_status(array $task, array $taskState, DateTimeImmutable $now, DateTimeZone $tz): array
{
    $effective = sd_effective_interval($task, $now);
    $interval = (int)$effective['minutes'];

    $lastAttempt = sd_parse_dt($taskState['last_attempt_at'] ?? '', $tz);
    $lastCompleted = sd_parse_dt($taskState['last_completed_at'] ?? '', $tz);

    if ($lastAttempt === null) {
        return [
            'due' => true,
            'due_text' => 'DUE',
            'next_run' => 'now',
            'schedule_text' => 'every ' . $interval . ' min (' . $effective['reason'] . ')',
            'last_attempt' => '',
            'last_completed' => $lastCompleted ? $lastCompleted->format('Y-m-d g:i:s A') : '',
        ];
    }

    $next = $lastAttempt->modify('+' . $interval . ' minutes');
    $due = ($now >= $next);

    return [
        'due' => $due,
        'due_text' => $due ? 'DUE' : 'not due',
        'next_run' => $due ? 'now' : $next->format('Y-m-d g:i:s A'),
        'schedule_text' => 'every ' . $interval . ' min (' . $effective['reason'] . ')',
        'last_attempt' => $lastAttempt->format('Y-m-d g:i:s A'),
        'last_completed' => $lastCompleted ? $lastCompleted->format('Y-m-d g:i:s A') : '',
    ];
}

function sd_auto_race_task_status(array $task, array $taskState, DateTimeImmutable $now, DateTimeZone $tz): array
{
    $auto = isset($taskState['auto_schedule']) && is_array($taskState['auto_schedule']) ? $taskState['auto_schedule'] : [];
    $decision = isset($auto['decision']) && is_array($auto['decision']) ? $auto['decision'] : [];
    $interval = isset($decision['interval_minutes']) ? (int)$decision['interval_minutes'] : 0;
    $due = !empty($decision['due']);
    $phase = (string)($decision['phase_label'] ?? $decision['phase'] ?? 'auto race-aware');
    $nextDue = (string)($decision['next_due_at'] ?? '');
    $lastAttempt = sd_parse_dt($taskState['last_attempt_at'] ?? '', $tz);
    $lastCompleted = sd_parse_dt($taskState['last_completed_at'] ?? '', $tz);

    return [
        'due' => $due,
        'due_text' => $due ? 'DUE' : 'not due',
        'next_run' => $due ? 'now' : $nextDue,
        'schedule_text' => $interval > 0 ? 'auto race-aware: ' . $phase . ' / every ' . $interval . ' min' : 'auto race-aware: ' . $phase . ' / disabled',
        'last_attempt' => $lastAttempt ? $lastAttempt->format('Y-m-d g:i:s A') : '',
        'last_completed' => $lastCompleted ? $lastCompleted->format('Y-m-d g:i:s A') : '',
    ];
}


function sd_auto_revision_task_status(array $task, array $taskState, DateTimeImmutable $now, DateTimeZone $tz): array
{
    $rev = isset($taskState['revision_schedule']) && is_array($taskState['revision_schedule']) ? $taskState['revision_schedule'] : [];
    $decision = isset($rev['decision']) && is_array($rev['decision']) ? $rev['decision'] : [];
    $interval = array_key_exists('interval_minutes', $decision) && $decision['interval_minutes'] !== null ? (int)$decision['interval_minutes'] : 0;
    $due = !empty($decision['due']);
    $phase = (string)($decision['phase_label'] ?? $decision['phase'] ?? 'auto revision-aware');
    $nextDue = (string)($decision['next_due_at'] ?? '');
    $lastAttempt = sd_parse_dt($taskState['last_attempt_at'] ?? '', $tz);
    $lastCompleted = sd_parse_dt($taskState['last_completed_at'] ?? '', $tz);

    $dailyTimes = isset($decision['daily_times']) && is_array($decision['daily_times'])
        ? $decision['daily_times']
        : (isset($task['normal_times']) && is_array($task['normal_times']) ? $task['normal_times'] : []);

    if (!empty($decision['uses_daily_times'])) {
        $scheduleText = 'Auto: daily times';
    } else {
        $scheduleText = $interval > 0
            ? 'Auto: every ' . $interval . ' min'
            : 'Auto: disabled';
    }

    $displayNextRun = $due ? 'now' : $nextDue;
    if ($displayNextRun === '') {
        $displayNextRun = 'Auto';
    }

    return [
        'due' => $due,
        'due_text' => $due ? 'DUE' : 'not due',
        'next_run' => $displayNextRun,
        'schedule_text' => $scheduleText,
        'last_attempt' => $lastAttempt ? $lastAttempt->format('Y-m-d g:i:s A') : '',
        'last_completed' => $lastCompleted ? $lastCompleted->format('Y-m-d g:i:s A') : '',
    ];
}

function sd_daily_task_status(array $task, array $taskState, DateTimeImmutable $now, DateTimeZone $tz): array
{
    $times = isset($task['times']) && is_array($task['times']) ? $task['times'] : (isset($task['normal_times']) && is_array($task['normal_times']) ? $task['normal_times'] : []);
    $times = array_values(array_filter(array_map('strval', $times), function ($value) {
        return trim($value) !== '';
    }));
    sort($times, SORT_STRING);

    $nextRun = '';
    $today = $now->format('Y-m-d');

    foreach ($times as $time) {
        $candidate = sd_parse_dt($today . ' ' . $time . ':00', $tz);
        if ($candidate !== null && $candidate >= $now) {
            $nextRun = $candidate->format('Y-m-d g:i:s A');
            break;
        }
    }

    if ($nextRun === '' && !empty($times)) {
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $candidate = sd_parse_dt($tomorrow . ' ' . $times[0] . ':00', $tz);
        if ($candidate !== null) {
            $nextRun = $candidate->format('Y-m-d g:i:s A');
        }
    }

    $curTime = $now->format('H:i');
    $due = in_array($curTime, $times, true);

    $lastAttempt = sd_parse_dt($taskState['last_attempt_at'] ?? '', $tz);
    $lastCompleted = sd_parse_dt($taskState['last_completed_at'] ?? '', $tz);

    return [
        'due' => $due,
        'due_text' => $due ? 'DUE' : 'not due',
        'next_run' => $due ? 'now' : $nextRun,
        'schedule_text' => 'daily at ' . implode(', ', $times),
        'last_attempt' => $lastAttempt ? $lastAttempt->format('Y-m-d g:i:s A') : '',
        'last_completed' => $lastCompleted ? $lastCompleted->format('Y-m-d g:i:s A') : '',
    ];
}

$schedule = sd_read_json($schedulePath);
$state = sd_read_json($statePath);
$heartbeat = sd_read_text($heartbeatPath);
$logText = sd_read_text($logPath);
$logLines = sd_tail_lines($logText, 25);

$timezoneName = isset($schedule['timezone']) && (string)$schedule['timezone'] !== ''
    ? (string)$schedule['timezone']
    : 'America/New_York';

try {
    $tz = new DateTimeZone($timezoneName);
} catch (Exception $e) {
    $tz = new DateTimeZone('America/New_York');
    $timezoneName = 'America/New_York';
}

$now = new DateTimeImmutable('now', $tz);
$schedulerEnabled = !empty($schedule['enabled']);
$dryRun = !empty($schedule['dry_run']);
$year = isset($schedule['year']) ? (string)$schedule['year'] : '';
$schedulerHeartbeatFreshness = rr_dash_file_freshness($heartbeatPath, $now, 120, 300);

$tasks = isset($schedule['tasks']) && is_array($schedule['tasks']) ? $schedule['tasks'] : [];
$taskCount = count($tasks);
$stateTasks = isset($state['tasks']) && is_array($state['tasks']) ? $state['tasks'] : [];

$fileRows = [
    'Schedule' => $schedulePath,
    'State' => $statePath,
    'Heartbeat' => $heartbeatPath,
    'Log' => $logPath,
];

$taskStatusRows = [];
foreach ($tasks as $taskNameForStatus => $taskForStatus) {
    if (!is_array($taskForStatus)) {
        continue;
    }

    $taskStateForStatus = isset($stateTasks[$taskNameForStatus]) && is_array($stateTasks[$taskNameForStatus]) ? $stateTasks[$taskNameForStatus] : [];
    $typeForStatus = isset($taskForStatus['type']) ? (string)$taskForStatus['type'] : 'interval';
    $taskStatusRows[$taskNameForStatus] = ($typeForStatus === 'daily_times')
        ? sd_daily_task_status($taskForStatus, $taskStateForStatus, $now, $tz)
        : sd_interval_task_status($taskForStatus, $taskStateForStatus, $now, $tz);
}

$monitorNextRunRaw = isset($taskStatusRows['race_results_monitor']['next_run'])
    ? (string)$taskStatusRows['race_results_monitor']['next_run']
    : '';
$revisionNextRunRaw = isset($taskStatusRows['race_results_revision_monitor']['next_run'])
    ? (string)$taskStatusRows['race_results_revision_monitor']['next_run']
    : '';

$monitorNextRun = $monitorNextRunRaw !== '' ? rr_dash_display_datetime($monitorNextRunRaw) : '';
$revisionNextRun = $revisionNextRunRaw !== '' ? rr_dash_display_datetime($revisionNextRunRaw) : '';

$monitorNextRunEpoch = rr_dash_next_run_epoch($monitorNextRunRaw, $tz);
$revisionNextRunEpoch = rr_dash_next_run_epoch($revisionNextRunRaw, $tz);

$export = isset($_GET['export']) ? strtolower(trim((string)$_GET['export'])) : '';
$exportStamp = $now->format('Ymd_His');
$exportBaseName = 'mrl_scheduler_' . sd_export_safe_name($year !== '' ? $year : 'unknown') . '_' . $exportStamp;

if ($export !== '') {
    if ($export === 'schedule') {
        sd_download_text($exportBaseName . '_schedule.json', sd_read_text($schedulePath), 'application/json');
    }

    if ($export === 'state') {
        sd_download_text($exportBaseName . '_state.json', sd_read_text($statePath), 'application/json');
    }

    if ($export === 'heartbeat') {
        sd_download_text($exportBaseName . '_heartbeat.txt', sd_read_text($heartbeatPath), 'text/plain');
    }

    if ($export === 'log') {
        sd_download_text($exportBaseName . '_log.txt', sd_read_text($logPath), 'text/plain');
    }

    if ($export === 'bundle') {
        $bundle = [
            'generated_at' => $now->format('Y-m-d H:i:s'),
            'dashboard_version' => RACE_RESULTS_DASHBOARD_VERSION,
            'base_dir' => $baseDir,
            'scheduler_dir' => $schedulerDir,
            'scheduler_enabled' => $schedulerEnabled,
            'dry_run' => $dryRun,
            'year' => $year,
            'timezone' => $timezoneName,
            'schedule' => $schedule,
            'state' => $state,
            'heartbeat' => trim($heartbeat),
            'scheduler_health' => $schedulerHeartbeatFreshness,
            'recent_log_lines' => $logLines,
            'files' => [],
        ];

        foreach ($fileRows as $label => $path) {
            $bundle['files'][$label] = sd_file_status($path);
        }

        sd_download_text(
            $exportBaseName . '_bundle.json',
            json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            'application/json'
        );
    }

    if ($export === 'summary') {
        $lines = [];
        $lines[] = 'MRL Scheduler Dashboard Export';
        $lines[] = 'Generated: ' . $now->format('Y-m-d g:i:s A');
        $lines[] = 'Dashboard: ' . RACE_RESULTS_DASHBOARD_VERSION;
        $lines[] = 'Base Dir: ' . $baseDir;
        $lines[] = 'Scheduler Dir: ' . $schedulerDir;
        $lines[] = 'Scheduler: ' . ($schedulerEnabled ? 'enabled' : 'disabled');
        $lines[] = 'Mode: ' . ($dryRun ? 'dry run' : 'active');
        $lines[] = 'Year: ' . ($year !== '' ? $year : 'not set');
        $lines[] = 'Timezone: ' . $timezoneName;
        $lines[] = '';
        $lines[] = 'TASKS';

        if (empty($tasks)) {
            $lines[] = '- No tasks found in schedule.json.';
        } else {
            foreach ($tasks as $taskName => $task) {
                if (!is_array($task)) {
                    continue;
                }

                $taskState = isset($stateTasks[$taskName]) && is_array($stateTasks[$taskName]) ? $stateTasks[$taskName] : [];
                $type = isset($task['type']) ? (string)$task['type'] : 'interval';
                $calc = ($type === 'daily_times')
                    ? sd_daily_task_status($task, $taskState, $now, $tz)
                    : (($type === 'auto_race_monitor')
                        ? sd_auto_race_task_status($task, $taskState, $now, $tz)
                        : sd_interval_task_status($task, $taskState, $now, $tz));

                $lines[] = '- ' . (string)$taskName;
                $lines[] = '  script: ' . (string)($task['script'] ?? '');
                $lines[] = '  enabled: ' . ((!isset($task['enabled']) || !empty($task['enabled'])) ? 'YES' : 'NO');
                $lines[] = '  schedule: ' . (string)$calc['schedule_text'];
                $lines[] = '  due_now: ' . (string)$calc['due_text'];
                $lines[] = '  next_run: ' . (string)$calc['next_run'];
                $lines[] = '  last_attempt: ' . (string)$calc['last_attempt'];
                $lines[] = '  last_completed: ' . (string)$calc['last_completed'];
                $lines[] = '  last_status: ' . (string)($taskState['last_status'] ?? '');
                $lines[] = '  last_exit_code: ' . (array_key_exists('last_exit_code', $taskState) ? (string)$taskState['last_exit_code'] : '');
                $lines[] = '  last_message: ' . (string)($taskState['last_message'] ?? '');
            }
        }

        $lines[] = '';
        $lines[] = 'SCHEDULER FILES';
        foreach ($fileRows as $label => $path) {
            $fileStatus = sd_file_status($path);
            $lines[] = '- ' . $label . ': ' . ($fileStatus['present'] ? 'Present' : 'Missing')
                . ' | modified=' . (string)$fileStatus['modified']
                . ' | size=' . (string)$fileStatus['size'];
        }

        $lines[] = '';
        $lines[] = 'HEARTBEAT';
        $lines[] = trim($heartbeat) !== '' ? trim($heartbeat) : '(none)';

        $lines[] = '';
        $lines[] = 'LAST SCHEDULER SUMMARY';
        $summary = isset($state['last_scheduler_summary']) && is_array($state['last_scheduler_summary'])
            ? $state['last_scheduler_summary']
            : [];
        $lines[] = 'Last Run: ' . (string)($state['last_scheduler_run_at'] ?? '');
        $lines[] = 'Last Completed: ' . (string)($state['last_scheduler_complete_at'] ?? '');
        $lines[] = 'SAPI: ' . (string)($state['last_scheduler_run_sapi'] ?? '');
        $lines[] = 'TASKS: checked=' . (string)($summary['checked'] ?? '')
            . ' ran=' . (string)($summary['ran'] ?? '')
            . ' skipped=' . (string)($summary['skipped'] ?? '')
            . ' errors=' . (string)($summary['errors'] ?? '');

        $lines[] = '';
        $lines[] = 'RECENT LOG LINES';
        if (empty($logLines)) {
            $lines[] = '(none)';
        } else {
            foreach ($logLines as $line) {
                $lines[] = (string)$line;
            }
        }

        sd_download_text($exportBaseName . '_summary.txt', implode(PHP_EOL, $lines) . PHP_EOL, 'text/plain');
    }
}



// -----------------------------------------------------------------------------
// Race results monitor/revision dashboard data/functions
// -----------------------------------------------------------------------------
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('America/New_York');

// -----------------------------------------------------------------------------
// Config
// -----------------------------------------------------------------------------
$baseDir = __DIR__;

// --- Live Monitor files ---
$heartbeatFile = $baseDir . '/_race_results_monitor_heartbeat.txt';
$stateFile     = $baseDir . '/_race_results_monitor_state.json';
$logFile       = $baseDir . '/_race_results_monitor.log';
$rdStatusFile  = $baseDir . '/_race_results_rd_status.json';

// --- Revision Monitor files ---
$revisionMonitorFile = $baseDir . '/race_results_revision_monitor.php';
$revHeartbeatFile    = $baseDir . '/_race_results_revision_monitor_heartbeat.txt';
$revLogFile          = $baseDir . '/_race_results_revision_monitor.log';
$classifierFile      = $baseDir . '/race_results_classify_revisions.php';
$classSummaryFile = $baseDir . '/_race_results_classification_summary.json';
$classLastRunFile = $baseDir . '/_race_results_classification_last_run.json';

$defaultTailLines = 10;
$maxTailLines     = 200;

$tailLines = isset($_GET['lines']) ? (int)$_GET['lines'] : $defaultTailLines;
if ($tailLines < 1)             $tailLines = $defaultTailLines;
if ($tailLines > $maxTailLines) $tailLines = $maxTailLines;

$autoRefresh = isset($_GET['refresh']) ? (int)$_GET['refresh'] : 30;
if ($autoRefresh < 0)    $autoRefresh = 0;
if ($autoRefresh > 3600) $autoRefresh = 3600;

$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'revision') ? 'revision' : 'live';

$classYear = isset($_GET['year']) ? (int)$_GET['year'] : 2026;
if ($classYear < 2000 || $classYear > 2100) $classYear = 2026;

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function rr_dash_read_file(string $path): string
{
    if (!is_file($path)) return '';
    $raw = @file_get_contents($path);
    return ($raw === false) ? '' : $raw;
}

function rr_dash_file_exists(string $path): bool
{
    return is_file($path);
}

function rr_dash_file_mtime_string(string $path): string
{
    if (!is_file($path)) return 'Missing';
    $ts = @filemtime($path);
    if ($ts === false) return 'Unknown';
    return rr_dash_display_timestamp((int)$ts);
}

function rr_dash_file_size_string(string $path): string
{
    if (!is_file($path)) return '—';
    $size = @filesize($path);
    if ($size === false) return '—';
    if ($size < 1024)        return $size . ' B';
    if ($size < 1048576)     return number_format($size / 1024, 1) . ' KB';
    return number_format($size / 1048576, 2) . ' MB';
}

function rr_dash_tail_lines(string $path, int $lineCount): string
{
    if (!is_file($path)) return '';

    $fh = @fopen($path, 'rb');
    if ($fh === false) return '';

    $buffer = '';
    $chunkSize = 4096;
    $pos = 0;
    $lineCounter = 0;

    @fseek($fh, 0, SEEK_END);
    $fileSize = @ftell($fh);
    if ($fileSize === false || $fileSize <= 0) {
        @fclose($fh);
        return '';
    }

    while ($fileSize + $pos > 0) {
        $seek = max(-$chunkSize, -($fileSize + $pos));
        $pos += $seek;
        @fseek($fh, $pos, SEEK_END);
        $chunk = @fread($fh, abs($seek));
        if ($chunk === false || $chunk === '') break;

        $buffer = $chunk . $buffer;
        $lineCounter = substr_count($buffer, "\n");
        if ($lineCounter > $lineCount) break;
    }

    @fclose($fh);

    $lines = preg_split("/\r\n|\n|\r/", $buffer);
    if (!is_array($lines)) return '';

    $lines = array_values(array_filter($lines, static function ($line) {
        return $line !== null && $line !== '';
    }));

    if (count($lines) <= $lineCount) return implode("\n", $lines);
    return implode("\n", array_slice($lines, -$lineCount));
}

function rr_dash_last_line(string $path): string
{
    return trim(rr_dash_tail_lines($path, 1));
}

function rr_dash_pretty_json(string $raw): string
{
    if ($raw === '') return '';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return $raw;
    $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return ($pretty === false) ? $raw : $pretty;
}

function rr_dash_status_class(bool $exists): string
{
    return $exists ? 'ok' : 'bad';
}

function rr_dash_status_label(bool $exists): string
{
    return $exists ? 'Present' : 'Missing';
}

function rr_dash_cache_busted_href(string $href, string $path = ''): string
{
    $buster = '';

    if ($path !== '' && is_file($path)) {
        $mtime = @filemtime($path);
        if ($mtime !== false && (int)$mtime > 0) {
            $buster = (string)(int)$mtime;
        }
    }

    if ($buster === '') {
        $buster = date('Ymd_His');
    }

    $separator = (strpos($href, '?') === false) ? '?' : '&';
    return $href . $separator . 't=' . rawurlencode($buster);
}

function rr_dash_age_text(int $seconds): string
{
    if ($seconds < 0) {
        $seconds = 0;
    }

    if ($seconds < 60) {
        return $seconds . ' sec old';
    }

    $minutes = (int)floor($seconds / 60);
    if ($minutes < 60) {
        return $minutes . ' min old';
    }

    $hours = (int)floor($minutes / 60);
    $remainingMinutes = $minutes % 60;

    if ($remainingMinutes === 0) {
        return $hours . ' hr old';
    }

    return $hours . ' hr ' . $remainingMinutes . ' min old';
}

function rr_dash_file_freshness(string $path, DateTimeImmutable $now, int $goodSeconds = 120, int $warnSeconds = 300): array
{
    if (!is_file($path)) {
        return [
            'class' => 'bad',
            'label' => 'missing',
            'age_seconds' => null,
            'age_text' => 'missing',
            'modified' => '',
        ];
    }

    $mtime = @filemtime($path);
    if ($mtime === false || (int)$mtime <= 0) {
        return [
            'class' => 'warn',
            'label' => 'unknown',
            'age_seconds' => null,
            'age_text' => 'unknown age',
            'modified' => '',
        ];
    }

    $ageSeconds = max(0, $now->getTimestamp() - (int)$mtime);
    $class = 'bad';
    $label = 'stale';

    if ($ageSeconds <= $goodSeconds) {
        $class = 'good';
        $label = 'running';
    } elseif ($ageSeconds <= $warnSeconds) {
        $class = 'warn';
        $label = 'delayed';
    }

    return [
        'class' => $class,
        'label' => $label,
        'age_seconds' => $ageSeconds,
        'age_text' => rr_dash_age_text($ageSeconds),
        'modified' => rr_dash_display_timestamp((int)$mtime),
    ];
}

function rr_dash_task_status_class(string $lastStatus, string $exitCode = ''): string
{
    $status = strtolower(trim($lastStatus));
    $exitCode = trim($exitCode);

    if ($status === 'success' || $status === 'ok' || $exitCode === '0') {
        return 'good';
    }

    if ($status === '' && $exitCode === '') {
        return 'warn';
    }

    if (strpos($status, 'warn') !== false || strpos($status, 'not_due') !== false || strpos($status, 'skipped') !== false) {
        return 'warn';
    }

    return 'bad';
}

function rr_dash_build_url(string $path, array $params): string
{
    return $path . '?' . http_build_query($params);
}

function rr_dash_json_value(string $raw, string $key): string
{
    if ($raw === '') return '';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return '';
    if (!array_key_exists($key, $decoded)) return '';
    if (is_array($decoded[$key])) return (string)json_encode($decoded[$key]);
    return (string)$decoded[$key];
}

function rr_dash_load_json_file(string $path): array
{
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function rr_dash_monitor_year_state(string $stateRaw, int $year): array
{
    if ($stateRaw === '') return [];

    $decoded = json_decode($stateRaw, true);
    if (!is_array($decoded)) return [];

    $yKey = (string)$year;
    if (isset($decoded['byYear']) && is_array($decoded['byYear'])
        && isset($decoded['byYear'][$yKey]) && is_array($decoded['byYear'][$yKey])
    ) {
        return $decoded['byYear'][$yKey];
    }

    return $decoded;
}

function rr_dash_monitor_status_from_state(array $yearState): array
{
    $statusRow = (isset($yearState['race_status']) && is_array($yearState['race_status']))
        ? $yearState['race_status']
        : ((isset($yearState['current_race_status']) && is_array($yearState['current_race_status'])) ? $yearState['current_race_status'] : []);

    $url = '';
    foreach (['race_url', 'url', 'latest_url'] as $key) {
        if (isset($statusRow[$key]) && is_string($statusRow[$key]) && $statusRow[$key] !== '') {
            $url = $statusRow[$key];
            break;
        }
    }

    $allowLatestUrlFallback = true;
    $modeForUrl = isset($statusRow['mode']) && is_string($statusRow['mode']) ? (string)$statusRow['mode'] : '';
    $labelForUrl = isset($statusRow['label']) && is_string($statusRow['label']) ? (string)$statusRow['label'] : '';
    if ($modeForUrl === 'next_scheduled' || strcasecmp($labelForUrl, 'Next Race') === 0) {
        $allowLatestUrlFallback = false;
    }

    if ($url === '' && $allowLatestUrlFallback && isset($yearState['latest_url']) && is_string($yearState['latest_url'])) {
        $url = (string)$yearState['latest_url'];
    }

    $raceName = '';
    foreach (['race_name', 'display_race_name', 'short_race_name'] as $key) {
        if (isset($statusRow[$key]) && is_string($statusRow[$key]) && trim($statusRow[$key]) !== '') {
            $raceName = trim((string)$statusRow[$key]);
            break;
        }
    }

    $status = '';
    if (isset($statusRow['status']) && is_string($statusRow['status'])) {
        $status = trim((string)$statusRow['status']);
    }

    $checkedAt = '';
    if (isset($statusRow['checked_at']) && is_string($statusRow['checked_at'])) {
        $checkedAt = rr_dash_display_datetime((string)$statusRow['checked_at']);
    }

    $label = 'Current Race';
    if (isset($statusRow['label']) && is_string($statusRow['label']) && trim($statusRow['label']) !== '') {
        $label = trim((string)$statusRow['label']);
    }

    $mode = (string)($statusRow['mode'] ?? '');
    $canOpenRacePage = ($url !== '' && strpos($url, '/racing/raceresults/') !== false);
    if ($mode === 'next_scheduled' || strcasecmp($label, 'Next Race') === 0) {
        // Scheduled future rows often do not have a result-page URL yet. Do not fall back to
        // the prior completed race page in that case.
        $canOpenRacePage = false;
    }

    return [
        'url' => $url,
        'race_name' => $raceName,
        'status' => $status,
        'checked_at' => $checkedAt,
        'label' => $label,
        'mode' => $mode,
        'can_open_race_page' => $canOpenRacePage,
        'owned_by' => (string)($statusRow['owned_by'] ?? ''),
        'found' => ($raceName !== '' || $status !== ''),
        'fetched' => !empty($statusRow),
    ];
}

function rr_dash_extract_latest_monitor_url(string $logText, string $lastLine, string $stateRaw): string
{
    $candidates = [];

    $state = json_decode($stateRaw, true);
    if (is_array($state)) {
        foreach (['latest_url', 'last_url', 'url', 'race_url', 'last_race_url'] as $key) {
            if (isset($state[$key]) && is_string($state[$key]) && strpos($state[$key], 'espn.com/racing/raceresults') !== false) {
                $candidates[] = $state[$key];
            }
        }
    }

    $combined = trim($logText . "\n" . $lastLine);
    if ($combined !== '' && preg_match_all('~https://www\.espn\.com/racing/raceresults/[^\s]+~i', $combined, $matches)) {
        foreach ($matches[0] as $url) {
            $candidates[] = rtrim($url, '.,;\')"]}');
        }
    }

    if (empty($candidates)) {
        return '';
    }

    return (string)end($candidates);
}

function rr_dash_fetch_url(string $url): string
{
    if ($url === '' || !preg_match('~^https://www\.espn\.com/racing/raceresults/~i', $url)) {
        return '';
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 4,
            'header' => "User-Agent: Mozilla/5.0 (MRL Dashboard)\r\nAccept: text/html,application/xhtml+xml\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    return is_string($raw) ? $raw : '';
}

function rr_dash_clean_html_text(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = strip_tags($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string)$value);
}

function rr_dash_simplify_race_name(string $title): string
{
    $title = rr_dash_clean_html_text($title);
    if ($title === '') {
        return '';
    }

    $title = preg_replace('/\s*[-|].*$/', '', $title);
    $title = preg_replace('/^\d{4}\s+/', '', $title);
    $title = preg_replace('/\s+Results$/i', '', (string)$title);
    $title = preg_replace('/\s+Race Results$/i', '', (string)$title);
    $title = trim((string)$title);

    if (preg_match('/\bat\s+(.+)$/i', $title, $m)) {
        return trim($m[1]);
    }

    return $title;
}

function rr_dash_extract_race_title_from_html(string $html): string
{
    if ($html === '') {
        return '';
    }

    if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $html, $m)) {
        $title = rr_dash_clean_html_text($m[1]);
        if ($title !== '') {
            return $title;
        }
    }

    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        $title = rr_dash_clean_html_text($m[1]);
        if ($title !== '') {
            return $title;
        }
    }

    return '';
}

function rr_dash_extract_lap_status_from_html(string $html): string
{
    if ($html === '') {
        return '';
    }

    $text = rr_dash_clean_html_text($html);
    if (preg_match('/leads\s+on\s+lap\s+(\d+)\s+of\s+(\d+)/i', $text, $m)) {
        return 'Lap ' . (string)((int)$m[1]) . ' of ' . (string)((int)$m[2]);
    }

    return '';
}

function rr_dash_monitor_current_race_status(string $url, string $html): array
{
    $title = rr_dash_extract_race_title_from_html($html);
    $raceName = rr_dash_simplify_race_name($title);
    $status = rr_dash_extract_lap_status_from_html($html);

    return [
        'url' => $url,
        'race_name' => $raceName,
        'status' => $status,
        'found' => ($raceName !== '' || $status !== ''),
        'fetched' => ($html !== ''),
    ];
}

function rr_dash_first_existing_int(array $data, array $keys): int
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            if (is_array($data[$key])) return count($data[$key]);
            return (int)$data[$key];
        }
    }

    return 0;
}

function rr_dash_first_existing_string(array $data, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            if (is_array($data[$key])) return (string)json_encode($data[$key]);
            return (string)$data[$key];
        }
    }

    return $default;
}

function rr_dash_first_existing_bool(array $data, array $keys): bool
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            $value = $data[$key];
            if (is_bool($value)) return $value;
            if (is_numeric($value)) return ((int)$value !== 0);
            $valueString = strtoupper(trim((string)$value));
            return in_array($valueString, ['1', 'YES', 'TRUE', 'Y'], true);
        }
    }

    return false;
}


function rr_dash_signed_delta(int $delta): string
{
    if ($delta > 0) return '+' . (string)$delta;
    return (string)$delta;
}

function rr_dash_format_score_change(array $detail, string $field): string
{
    $old = isset($detail['old']) && is_array($detail['old']) ? $detail['old'] : [];
    $new = isset($detail['new']) && is_array($detail['new']) ? $detail['new'] : [];
    $delta = isset($detail['delta']) && is_array($detail['delta']) ? $detail['delta'] : [];

    $oldValue = (int)($old[$field] ?? 0);
    $newValue = (int)($new[$field] ?? 0);

    if (array_key_exists($field, $delta)) {
        $deltaValue = (int)$delta[$field];
    } else {
        $deltaValue = $newValue - $oldValue;
    }

    return (string)$oldValue . ' → ' . (string)$newValue . ' (' . rr_dash_signed_delta($deltaValue) . ')';
}

function rr_dash_normalize_changed_driver_detail(array $detail): array
{
    return [
        'driver' => rr_dash_first_existing_string($detail, ['driver', 'driver_name', 'name'], ''),
        'mrl_listed' => rr_dash_first_existing_bool($detail, ['mrl_listed', 'mrlListed', 'mrl_driver', 'mrlDriver']),
        'segment_picked' => rr_dash_first_existing_bool($detail, ['segment_picked', 'segmentPicked', 'picked', 'segment_driver']),
        'old' => isset($detail['old']) && is_array($detail['old']) ? $detail['old'] : [],
        'new' => isset($detail['new']) && is_array($detail['new']) ? $detail['new'] : [],
        'delta' => isset($detail['delta']) && is_array($detail['delta']) ? $detail['delta'] : [],
    ];
}

function rr_dash_normalize_changed_driver_details(array $details): array
{
    $rows = [];

    foreach ($details as $detail) {
        if (!is_array($detail)) continue;
        $row = rr_dash_normalize_changed_driver_detail($detail);
        if ((string)$row['driver'] === '') continue;
        $rows[] = $row;
    }

    return $rows;
}

function rr_dash_yes_no(bool $value): string
{
    return $value ? 'YES' : 'NO';
}

function rr_dash_normalize_classifier_row(array $row): array
{
    $raceCode = rr_dash_first_existing_string($row, ['raceCode', 'race_code', 'race', 'race_code_display'], '');
    $raceName = rr_dash_first_existing_string($row, ['raceName', 'race_name', 'race_label', 'label'], '');
    $raceNumber = rr_dash_first_existing_int($row, ['raceNumber', 'race_number', 'number']);

    if ($raceCode === '' && $raceNumber > 0) {
        $raceCode = 'R' . str_pad((string)$raceNumber, 2, '0', STR_PAD_LEFT);
    }

    $classified = rr_dash_first_existing_bool($row, ['classified', 'is_classified']);
    if (!$classified) {
        $classified = rr_dash_first_existing_string($row, ['message'], '') === 'Classification complete.';
    }

    $mrlImpact = rr_dash_first_existing_bool($row, ['impact', 'mrl_impact', 'mrlImpact']);
    $allDriverImpact = rr_dash_first_existing_bool($row, ['allDriverImpact', 'all_driver_impact', 'all_driver_changed']);

    $changedMrlDrivers = rr_dash_first_existing_int($row, [
        'changedDriversCount',
        'changed_drivers_count',
        'changed_mrl_drivers',
        'changed_mrl_drivers_count'
    ]);

    $changedAllDrivers = rr_dash_first_existing_int($row, [
        'allDriverChangedCount',
        'all_driver_changed_count',
        'changed_all_drivers',
        'changed_all_drivers_count'
    ]);

    $changedMrlListedDrivers = rr_dash_first_existing_int($row, [
        'changedMrlListedDriversCount',
        'changed_mrl_listed_drivers_count',
        'changed_mrl_listed_drivers'
    ]);

    $changedSegmentPickedDrivers = rr_dash_first_existing_int($row, [
        'changedSegmentPickedDriversCount',
        'changed_segment_picked_drivers_count',
        'changed_segment_picked_drivers',
        'changedDriversCount',
        'changed_drivers_count',
        'changed_mrl_drivers',
        'changed_mrl_drivers_count'
    ]);

    if (!$allDriverImpact && $changedAllDrivers > 0) {
        $allDriverImpact = true;
    }

    $changedDriverDetails = [];
    foreach (['changedDriverDetails', 'changed_driver_details', 'driver_changes', 'changed_drivers'] as $detailsKey) {
        if (isset($row[$detailsKey]) && is_array($row[$detailsKey])) {
            $changedDriverDetails = rr_dash_normalize_changed_driver_details($row[$detailsKey]);
            break;
        }
    }

    $statusLabel = rr_dash_first_existing_string($row, ['status_label', 'statusLabel'], '');
    $status = rr_dash_first_existing_string($row, ['revision_status', 'status'], '');
    $pendingReview = rr_dash_first_existing_bool($row, ['pending_review', 'pendingReview']);

    if ($statusLabel === '') {
        if ($pendingReview && $status !== '') {
            $statusLabel = trim($status . ' / Pending Review');
        } elseif ($pendingReview) {
            $statusLabel = 'Pending Review';
        } elseif ($status !== '') {
            $statusLabel = $status;
        } elseif ($classified) {
            $statusLabel = 'Classified';
        } else {
            $statusLabel = 'Not classified';
        }
    }

    return [
        'race_number' => $raceNumber,
        'race_code' => $raceCode,
        'race_label' => $raceName,
        'classified' => $classified,
        'mrl_impact' => $mrlImpact,
        'all_driver_impact' => $allDriverImpact,
        'changed_mrl_drivers' => $changedMrlDrivers,
        'changed_all_drivers' => $changedAllDrivers,
        'changed_mrl_listed_drivers' => $changedMrlListedDrivers,
        'changed_segment_picked_drivers' => $changedSegmentPickedDrivers,
        'changed_driver_details' => $changedDriverDetails,
        'display_tag' => rr_dash_first_existing_string($row, ['display_tag', 'displayTag'], ''),
        'pending_review' => $pendingReview,
        'status_label' => $statusLabel,
        'previous_snapshot' => rr_dash_first_existing_string($row, ['previousSnapshot', 'previous_snapshot'], ''),
        'current_snapshot' => rr_dash_first_existing_string($row, ['currentSnapshot', 'current_snapshot'], ''),
        'message' => rr_dash_first_existing_string($row, ['message'], ''),
    ];
}

function rr_dash_trusted_revision_classification_summary(string $summaryFile, string $lastRunFile, int $year): array
{
    $summary = rr_dash_load_json_file($summaryFile);
    $lastRun = rr_dash_load_json_file($lastRunFile);

    if (empty($summary)) {
        return [
            'year' => $year,
            'trusted_source' => false,
            'rows' => [],
            'classified_count' => 0,
            'mrl_impact_count' => 0,
            'all_driver_change_race_count' => 0,
            'signature' => '',
            'version' => '',
            'generated_at' => '',
            'sapi' => '',
            'message' => 'Trusted classification summary is missing. Run race_results_classify_revisions.php?year=' . (string)$year . ' to generate it.',
            'last_run' => $lastRun,
        ];
    }

    $rawRows = [];
    foreach (['rows', 'runs', 'races', 'classifications'] as $key) {
        if (isset($summary[$key]) && is_array($summary[$key])) {
            $rawRows = $summary[$key];
            break;
        }
    }

    $rows = [];
    foreach ($rawRows as $row) {
        if (!is_array($row)) continue;
        $rows[] = rr_dash_normalize_classifier_row($row);
    }

    usort($rows, static function ($a, $b): int {
        $aNum = (int)($a['race_number'] ?? 0);
        $bNum = (int)($b['race_number'] ?? 0);
        if ($aNum !== $bNum) return $aNum <=> $bNum;
        return strcmp((string)($a['race_code'] ?? ''), (string)($b['race_code'] ?? ''));
    });

    $classifiedCount = rr_dash_first_existing_int($summary, ['classified_count', 'classifiedCount']);
    $mrlImpactCount = rr_dash_first_existing_int($summary, ['mrl_impact_count', 'impactCount', 'mrlImpactCount']);
    $allDriverChangeRaceCount = rr_dash_first_existing_int($summary, ['all_driver_change_race_count', 'allDriverImpactCount', 'allDriverChangeRaceCount']);

    if ($classifiedCount === 0 && !empty($rows)) {
        foreach ($rows as $row) {
            if (!empty($row['classified'])) $classifiedCount++;
            if (!empty($row['mrl_impact'])) $mrlImpactCount++;
            if (!empty($row['changed_all_drivers'])) $allDriverChangeRaceCount++;
        }
    }

    $summaryYear = rr_dash_first_existing_int($summary, ['year']);
    if ($summaryYear <= 0) $summaryYear = $year;

    return [
        'year' => $summaryYear,
        'trusted_source' => true,
        'rows' => $rows,
        'classified_count' => $classifiedCount,
        'mrl_impact_count' => $mrlImpactCount,
        'all_driver_change_race_count' => $allDriverChangeRaceCount,
        'signature' => rr_dash_first_existing_string($summary, ['signature', 'script_signature', 'source_signature'], ''),
        'version' => rr_dash_first_existing_string($summary, ['version', 'script_version'], ''),
        'generated_at' => rr_dash_first_existing_string($summary, ['generated_at', 'generatedAt', 'written_at', 'created_at'], ''),
        'sapi' => rr_dash_first_existing_string($summary, ['sapi', 'php_sapi'], ''),
        'message' => rr_dash_first_existing_string($summary, ['message'], 'Trusted classifier summary loaded.'),
        'last_run' => $lastRun,
    ];
}

// -----------------------------------------------------------------------------
// Read data — Live Monitor
// -----------------------------------------------------------------------------
$heartbeatRaw = rr_dash_read_file($heartbeatFile);
$stateRaw     = rr_dash_read_file($stateFile);
$rdStatusRaw  = rr_dash_read_file($rdStatusFile);
$logTailRaw   = rr_dash_tail_lines($logFile, $tailLines);
$lastLogLine  = rr_dash_last_line($logFile);
$statePretty  = rr_dash_pretty_json($stateRaw);
$rdStatusPretty = rr_dash_pretty_json($rdStatusRaw);

$heartbeatExists = rr_dash_file_exists($heartbeatFile);
$stateExists     = rr_dash_file_exists($stateFile);
$rdStatusExists  = rr_dash_file_exists($rdStatusFile);
$logExists       = rr_dash_file_exists($logFile);

$rdStatusCode = rr_dash_json_value($rdStatusRaw, 'status');
$rdStatusMessage = rr_dash_json_value($rdStatusRaw, 'message');

$monitorYearState = rr_dash_monitor_year_state($stateRaw, (int)$year);
$monitorCurrentRaceStatus = rr_dash_monitor_status_from_state($monitorYearState);
$monitorStatusUrl = (string)($monitorCurrentRaceStatus['url'] ?? '');

if ($monitorStatusUrl === '') {
    $monitorStatusUrl = rr_dash_extract_latest_monitor_url(rr_dash_tail_lines($logFile, 80), $lastLogLine, $stateRaw);
    $monitorCurrentRaceStatus['url'] = $monitorStatusUrl;
}

// -----------------------------------------------------------------------------
// Read data — Revision Monitor
// -----------------------------------------------------------------------------
$revHeartbeatRaw = rr_dash_read_file($revHeartbeatFile);
$revLogTailRaw   = rr_dash_tail_lines($revLogFile, $tailLines);
$revLastLogLine  = rr_dash_last_line($revLogFile);
$classSummaryRaw = rr_dash_read_file($classSummaryFile);
$classLastRunRaw = rr_dash_read_file($classLastRunFile);
$classLastRunPretty = rr_dash_pretty_json($classLastRunRaw);

$revHeartbeatExists = rr_dash_file_exists($revHeartbeatFile);
$revLogExists       = rr_dash_file_exists($revLogFile);
$classifierExists   = rr_dash_file_exists($classifierFile);
$classSummaryExists = rr_dash_file_exists($classSummaryFile);
$classLastRunExists = rr_dash_file_exists($classLastRunFile);
$classSummary       = rr_dash_trusted_revision_classification_summary($classSummaryFile, $classLastRunFile, $classYear);

$pageGenerated = date('n/j/Y g:i:s a');
$selfUrl = strtok($_SERVER['REQUEST_URI'] ?? 'race_results_dashboard.php', '?');


// -----------------------------------------------------------------------------
// Combined dashboard tab selection
// -----------------------------------------------------------------------------
$mainTab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'scheduler';
if (!in_array($mainTab, ['scheduler', 'monitor', 'revision'], true)) {
    $mainTab = 'scheduler';
}

function rr_combined_tab_url(string $selfUrl, string $tab, int $tailLines, int $autoRefresh, int $classYear): string
{
    return $selfUrl . '?' . http_build_query([
        'tab' => $tab,
        'lines' => $tailLines,
        'refresh' => $autoRefresh,
        'year' => $classYear,
    ]);
}

function rr_dash_public_script_url(string $scriptName, array $params = []): string
{
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
    if ($host === '') {
        return '';
    }

    $https = isset($_SERVER['HTTPS']) ? strtolower((string)$_SERVER['HTTPS']) : '';
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';

    $scriptDir = isset($_SERVER['SCRIPT_NAME']) ? dirname((string)$_SERVER['SCRIPT_NAME']) : '';
    $scriptDir = str_replace('\\', '/', $scriptDir);
    $scriptDir = rtrim($scriptDir, '/');
    if ($scriptDir === '.' || $scriptDir === '/') {
        $scriptDir = '';
    }

    $url = $scheme . '://' . $host . $scriptDir . '/' . ltrim($scriptName, '/');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

function rr_dash_fetch_url_quietly(string $url, int $timeoutSeconds = 600): array
{
    if ($url === '') {
        return [
            'ok' => false,
            'message' => 'Unable to build local revision-monitor URL.',
            'http_code' => 0,
        ];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_USERAGENT, 'MRL Dashboard Revision Summary Regenerate');
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'ok' => $body !== false && $code >= 200 && $code < 400,
                'message' => $body === false ? $err : 'HTTP ' . (string)$code,
                'http_code' => $code,
            ];
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "User-Agent: MRL Dashboard Revision Summary Regenerate\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string)$headerLine, $m)) {
                $code = (int)$m[1];
                break;
            }
        }
    }

    return [
        'ok' => $body !== false && ($code === 0 || ($code >= 200 && $code < 400)),
        'message' => $body === false ? 'Request failed.' : ($code > 0 ? 'HTTP ' . (string)$code : 'Request completed.'),
        'http_code' => $code,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['rr_dash_action'] ?? ''), ['refresh_revision_summary', 'regenerate_revision_summary'], true)) {
    $dashAction = (string)($_POST['rr_dash_action'] ?? '');
    $postYear = isset($_POST['year']) ? (int)$_POST['year'] : $classYear;
    if ($postYear < 2000 || $postYear > 2100) {
        $postYear = $classYear;
    }

    $runOk = false;
    $runMessage = '';

    ob_start();
    try {
        if ($dashAction === 'refresh_revision_summary') {
            if (!defined('RRCR_AUTO_RUN')) {
                define('RRCR_AUTO_RUN', false);
            }

            require_once $classifierFile;

            if (!isset($dbo) || !($dbo instanceof PDO)) {
                throw new RuntimeException('PDO handle $dbo is not available for Revision Summary refresh.');
            }

            $rrcrOptions = [
                'year' => (string)$postYear,
                'race_code' => '',
                'verbose' => false,
                'write_artifacts' => true,
                'base_dir' => $baseDir,
            ];

            rrcr_run($rrcrOptions, $dbo);
            $runOk = true;
            $runMessage = 'revision_summary_refreshed';
        } elseif ($dashAction === 'regenerate_revision_summary') {
            if (!is_file($revisionMonitorFile)) {
                throw new RuntimeException('race_results_revision_monitor.php was not found.');
            }

            $revisionMonitorUrl = rr_dash_public_script_url('race_results_revision_monitor.php', [
                'year' => (string)$postYear,
                'dashboard_regenerate' => '1',
                't' => date('Ymd_His'),
            ]);

            $fetchResult = rr_dash_fetch_url_quietly($revisionMonitorUrl, 600);
            if (empty($fetchResult['ok'])) {
                throw new RuntimeException('Revision monitor request failed: ' . (string)($fetchResult['message'] ?? 'unknown error'));
            }

            $runOk = true;
            $runMessage = 'revision_summary_regenerated';
        }
    } catch (Throwable $e) {
        $runOk = false;
        $runMessage = $dashAction === 'regenerate_revision_summary'
            ? 'revision_summary_regenerate_failed'
            : 'revision_summary_refresh_failed';
        @file_put_contents($baseDir . '/_race_results_dashboard_errors.log', '[' . date('Y-m-d H:i:s') . '] Revision Summary action failed (' . $dashAction . '): ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $redirectUrl = $selfUrl . '?' . http_build_query([
        'tab' => 'revision',
        'lines' => $tailLines,
        'refresh' => $autoRefresh,
        'year' => $postYear,
        'rr_run' => $runOk ? 'ok' : 'error',
        'rr_msg' => $runMessage,
    ]);

    if (!headers_sent()) {
        header('Location: ' . $redirectUrl);
        exit;
    }

    echo '<meta http-equiv="refresh" content="0;url=' . h($redirectUrl) . '">';
    exit;
}

$revisionRunNotice = '';
$revisionRunNoticeClass = 'ok';
$revisionRunMessageCode = (string)($_GET['rr_msg'] ?? '');
if ((string)($_GET['rr_run'] ?? '') === 'ok') {
    if ($revisionRunMessageCode === 'revision_summary_regenerated') {
        $revisionRunNotice = 'Revision monitor ran. Revision Summary regenerated.';
    } else {
        $revisionRunNotice = 'Revision Summary refreshed from stored snapshots.';
    }
    $revisionRunNoticeClass = 'ok';
} elseif ((string)($_GET['rr_run'] ?? '') === 'error') {
    if ($revisionRunMessageCode === 'revision_summary_regenerate_failed') {
        $revisionRunNotice = 'Revision Summary regenerate failed. Check the dashboard error log.';
    } else {
        $revisionRunNotice = 'Revision Summary refresh failed. Check the dashboard error log.';
    }
    $revisionRunNoticeClass = 'bad';
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MRL Race Results Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root {
        --bg: #151515;
        --panel: #232323;
        --panel2: #1b1b1b;
        --line: #3a3328;
        --text: #f1f1f1;
        --muted: #c9c9c9;
        --gold: #f2c98e;
        --blue: #9fd0ff;
        --green: #55d77d;
        --green-bg: #263f30;
        --red: #ff7474;
        --red-bg: #4a2727;
        --yellow: #ffd76e;
        --yellow-bg: #493d20;
        --shadow: 0 9px 25px rgba(0,0,0,0.25);
        --mono: Consolas, Monaco, "Courier New", monospace;
        --sans: Arial, Helvetica, sans-serif;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        padding: 18px;
        background: var(--bg);
        color: var(--text);
        font-family: var(--sans);
        font-size: 15px;
    }

    h1, h2, h3 {
        color: var(--gold);
        margin: 0 0 12px 0;
    }

    h1 {
        font-size: 32px;
        line-height: 1.15;
    }

    h2 {
        font-size: 25px;
        line-height: 1.15;
    }

    h3 {
        font-size: 19px;
        line-height: 1.15;
    }

    .topline {
        display: flex;
        flex-wrap: wrap;
        gap: 9px;
        margin-bottom: 14px;
    }

    .pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid #5a5142;
        border-radius: 999px;
        padding: 7px 12px;
        background: #252525;
        color: var(--text);
        font-size: 14px;
        line-height: 1.2;
        text-decoration: none;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border: 3px solid rgb(12, 70, 150);
        border-radius: 25px;
        padding: 2px 8px;
        background: rgb(25, 103, 210);
        color: #ffffff;
        font: inherit;
        font-weight: bold;
        line-height: 1.2;
        min-height: 28px;
        text-decoration: none;
        cursor: pointer;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,0.08);
    }

    .pill.good {
        color: var(--green);
        background: var(--green-bg);
        border-color: #477a59;
        font-weight: 700;
    }

    .pill.bad {
        color: var(--red);
        background: var(--red-bg);
        border-color: #7a4b4b;
        font-weight: 700;
    }

    .pill.warn {
        color: var(--yellow);
        background: var(--yellow-bg);
        border-color: #7c6736;
        font-weight: 700;
    }

    .btn:hover {
        background: rgb(35, 120, 230);
        border-color: rgb(16, 82, 175);
        text-decoration: none;
        filter: brightness(0.98);
    }

    .btn[disabled],
    .btn.disabled {
        opacity: 0.55;
        cursor: default;
        filter: none;
    }

    .btn[disabled]:hover,
    .btn.disabled:hover {
        background: rgb(25, 103, 210);
        border-color: rgb(12, 70, 150);
        filter: none;
    }

    .pill.linklike:hover {
        background: #303030;
        text-decoration: none;
    }

    .tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin: 0 0 16px 0;
    }

    .tab {
        display: inline-block;
        border: 1px solid #5a5142;
        border-radius: 14px;
        background: #252525;
        color: var(--muted);
        padding: 11px 18px;
        font-size: 18px;
        font-weight: 700;
        line-height: 1.15;
        text-decoration: none;
    }

    .tab.active {
        color: var(--gold);
        border-color: var(--gold);
        background: rgba(242,201,142,0.08);
    }

    .tab:hover {
        color: var(--gold);
        text-decoration: none;
        border-color: var(--gold);
    }

    .toolbar, .exportbar {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 0 0 16px 0;
    }

    .grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 16px;
    }

    @media (min-width: 1100px) {
        .grid.two {
            grid-template-columns: 1fr 1fr;
        }

        .race-grid {
            grid-template-columns: 1fr 1fr;
        }

        .full {
            grid-column: 1 / -1;
        }
    }

    .card {
        border: 1px solid #5a5142;
        background: linear-gradient(180deg, #222, #1e1e1e);
        border-radius: 16px;
        padding: 16px;
        box-shadow: var(--shadow);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background: var(--panel);
        border-radius: 11px;
        overflow: hidden;
    }

    th, td {
        padding: 8px 10px;
        border-bottom: 1px solid #333;
        text-align: left;
        vertical-align: top;
        line-height: 1.25;
    }

    th {
        color: var(--gold);
        background: var(--panel2);
        font-size: 14px;
    }

    td {
        font-size: 14px;
    }

    tr:last-child td {
        border-bottom: none;
    }

    .status, .badge {
        font-weight: 700;
        border-radius: 999px;
        padding: 4px 9px;
        display: inline-block;
        font-size: 13px;
        line-height: 1.15;
    }

    .status.good, .badge.ok {
        color: var(--green);
        background: var(--green-bg);
        border: 1px solid #477a59;
    }

    .status.bad, .badge.bad {
        color: var(--red);
        background: var(--red-bg);
        border: 1px solid #7a4b4b;
    }

    .status.warn, .badge.warn {
        color: var(--yellow);
        background: var(--yellow-bg);
        border: 1px solid #7c6736;
    }

    .mono, pre {
        font-family: var(--mono);
        white-space: pre-wrap;
        word-break: break-word;
        font-size: 13px;
        line-height: 1.35;
    }

    pre, .last-line {
        margin: 0;
        background: rgba(0,0,0,0.25);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 10px;
        padding: 14px;
        color: #f0f0f0;
        overflow-x: auto;
    }

    .last-line {
        font-family: var(--mono);
        font-size: 14px;
        word-break: break-word;
    }

    .small, .meta {
        color: var(--muted);
        font-size: 13px;
        line-height: 1.25;
    }

    a {
        color: var(--blue);
        text-decoration: none;
    }

    a:hover {
        text-decoration: underline;
    }

    .msg, .empty {
        color: var(--muted);
    }

    .empty {
        font-style: italic;
    }

    .status-list {
        display: grid;
        gap: 10px;
    }

    .status-row {
        display: grid;
        grid-template-columns: 150px 100px 1fr;
        gap: 10px;
        align-items: center;
        padding: 10px 12px;
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.05);
        border-radius: 10px;
    }

    @media (max-width: 720px) {
        .status-row { grid-template-columns: 1fr; }
    }

    .label {
        font-weight: 700;
        color: var(--text);
    }

    .summary-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 14px;
    }

    .revision-summary-header {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 12px;
    }

    .revision-summary-header h2 {
        margin: 0;
        margin-right: 4px;
    }

    .inline-form {
        display: inline-flex;
        margin: 0;
    }

    .revision-run-button {
        font-weight: 800;
        cursor: pointer;
    }

    .summary-details-toggle {
        cursor: pointer;
        font-weight: 700;
        white-space: nowrap;
    }

    .summary-details-panel {
        display: block;
        width: 100%;
        margin-top: 10px;
    }

    .summary-details-panel[hidden] {
        display: none !important;
    }

    .revision-run-notice {
        flex-basis: 100%;
        width: 100%;
        margin-top: 2px;
    }

    .revision-run-notice.is-hidden {
        display: none !important;
    }

    @media (max-width: 720px) {
        .revision-summary-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .revision-summary-actions {
            justify-content: flex-start;
        }
    }

    table.dash-table tr:nth-child(even) td {
        background: rgba(255,255,255,0.025);
    }

    .detail-toggle {
        cursor: pointer;
        width: fit-content;
    }

    summary.detail-toggle {
        list-style: none;
    }

    summary.detail-toggle::-webkit-details-marker {
        display: none;
    }

    .json-toggle-card {
        padding: 0;
        overflow: hidden;
    }

    .json-toggle-card summary {
        list-style: none;
        cursor: pointer;
        padding: 14px;
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0;
    }

    .json-toggle-card summary::-webkit-details-marker {
        display: none;
    }

    .json-toggle-card summary::marker {
        content: '';
    }

    .json-toggle-card summary h2 {
        margin: 0;
        padding: 0;
        line-height: 1.15;
    }

    .json-toggle-label::before {
        content: 'Open';
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 62px;
        padding: 2px 8px;
        border-radius: 25px;
        border: 3px solid rgb(12, 70, 150);
        background: rgb(25, 103, 210);
        color: #ffffff;
        font: inherit;
        line-height: 1.2;
        font-weight: bold;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,0.08);
        text-decoration: none;
    }

    .json-toggle-card summary:hover .json-toggle-label::before {
        background: rgb(35, 120, 230);
        border-color: rgb(16, 82, 175);
        filter: brightness(0.98);
    }

    .json-toggle-card[open] .json-toggle-label::before {
        content: 'Close';
    }

    .json-toggle-body {
        padding: 0 14px 14px 14px;
    }

    .detail-flag {
        font-weight: 700;
        color: var(--text);
    }

    .detail-flag.muted-no {
        color: var(--muted);
    }

    .footer {
        color: #999;
        margin-top: 12px;
        font-size: 12px;
        text-align: center;
    }

    .dashboard-top {
        display: flex;
        justify-content: center;
        margin: 0 0 8px 0;
    }

    .control-panel {
        border: 2px solid rgba(201,201,201,0.35);
        border-radius: 14px;
        padding: 10px 12px;
        margin: 0 0 12px 0;
        background: rgba(255,255,255,0.015);
    }

    .control-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        margin-top: 8px;
    }

    .control-select {
        border: 1px solid #5a5142;
        border-radius: 999px;
        padding: 7px 32px 7px 12px;
        background: #252525;
        color: var(--text);
        font-size: 14px;
        line-height: 1.2;
        min-height: 34px;
    }

    .info-strip {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        margin: 0 0 16px 0;
    }

    .info-strip .pill {
        border-radius: 12px;
    }

    .diagnostic-row {
        margin-top: 14px;
        display: grid;
        grid-template-columns: 150px 120px 1fr;
        gap: 10px;
        align-items: center;
        padding: 10px 12px;
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.05);
        border-radius: 10px;
    }

    @media (max-width: 720px) {
        .diagnostic-row { grid-template-columns: 1fr; }
    }


    /* v008 compact unified dashboard refinements */
    body {
        padding: 10px 18px;
    }

    .dashboard-top {
        margin: 0 0 6px 0;
    }

    .tabs {
        gap: 8px;
        margin: 0;
        justify-content: center;
    }

    .tab {
        padding: 8px 18px;
        font-size: 17px;
        border-radius: 12px;
    }

    .control-panel {
        padding: 8px 10px;
        margin: 0 0 10px 0;
        border-width: 1px;
    }

    .topline {
        gap: 7px;
        margin-bottom: 6px;
    }

    .control-row {
        gap: 7px;
        margin-top: 6px;
    }

    .pill {
        padding: 6px 11px;
        font-size: 14px;
    }

    .btn {
        padding: 2px 8px;
    }

    .control-select {
        padding: 6px 30px 6px 12px;
        min-height: 32px;
        min-width: 92px;
    }

    .card {
        padding: 14px;
    }

    .card.status-card {
        padding: 12px;
    }

    .status-list {
        gap: 8px;
    }

    .status-row {
        grid-template-columns: 150px 120px 1fr;
        padding: 9px 11px;
    }

    .next-run-row {
        margin-bottom: 10px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
    }

    .countdown-pill {
        font-size: 18px;
        color: var(--muted);
    }

    .section-spacer {
        margin-top: 16px;
    }

    .raw-link-row {
        margin-top: 10px;
    }


    .race-progress-card {
        margin-bottom: 16px;
    }

    .race-progress-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .race-progress-row .pill strong {
        color: var(--gold);
    }

</style>
</head>
<body>

<div class="dashboard-top">
    <div class="tabs">
        <a class="tab <?php echo $mainTab === 'scheduler' ? 'active' : ''; ?>" href="<?php echo h(rr_combined_tab_url($selfUrl, 'scheduler', $tailLines, $autoRefresh, $classYear)); ?>">Scheduler</a>
        <a class="tab <?php echo $mainTab === 'monitor' ? 'active' : ''; ?>" href="<?php echo h(rr_combined_tab_url($selfUrl, 'monitor', $tailLines, $autoRefresh, $classYear)); ?>">Monitor</a>
        <a class="tab <?php echo $mainTab === 'revision' ? 'active' : ''; ?>" href="<?php echo h(rr_combined_tab_url($selfUrl, 'revision', $tailLines, $autoRefresh, $classYear)); ?>">Revision</a>
    </div>
</div>

<div class="control-panel">
    <div class="topline">
        <span class="pill <?php echo $schedulerEnabled ? 'good' : 'bad'; ?>">
            Scheduler: <?php echo $schedulerEnabled ? 'enabled' : 'disabled'; ?>
        </span>
        <span class="pill <?php echo sd_html((string)$schedulerHeartbeatFreshness['class']); ?>">
            Cron: <?php echo sd_html((string)$schedulerHeartbeatFreshness['label']); ?>
            <span class="small">(<?php echo sd_html((string)$schedulerHeartbeatFreshness['age_text']); ?>)</span>
        </span>
        <span class="pill <?php echo $dryRun ? 'warn' : 'good'; ?>" title="Dry run checks scheduled tasks but does not run monitor or revision scripts.">
            Mode: <?php echo $dryRun ? 'dry run / paused' : 'live'; ?>
        </span>
        <span class="pill">Year: <?php echo sd_html($year !== '' ? $year : (string)$classYear); ?></span>
        <span class="pill">Timezone: <?php echo sd_html($timezoneName); ?></span>
        <span class="pill">Now: <?php echo sd_html($now->format('n/j/Y g:i:s a')); ?></span>
        <span class="pill">Dashboard: <?php echo sd_html(RACE_RESULTS_DASHBOARD_VERSION); ?></span>
    </div>

    <div class="control-row">
        <span class="pill">Page Generated: <?php echo h($pageGenerated); ?></span>

        <select id="linesSelect" class="control-select" aria-label="Log Lines" onchange="if (this.value) window.location.href=this.value;">
            <?php foreach ([2, 4, 10, 25, 50, 100] as $lineOption): ?>
                <option value="<?php echo h(rr_combined_tab_url($selfUrl, $mainTab, $lineOption, $autoRefresh, $classYear)); ?>" <?php echo $tailLines === $lineOption ? 'selected' : ''; ?>>Log Lines: <?php echo h((string)$lineOption); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="refreshSelect" class="control-select" aria-label="Refresh" onchange="if (this.value) window.location.href=this.value;">
            <?php $refreshOptions = [0 => 'Off', 15 => '15s', 30 => '30s', 60 => '1 min', 120 => '2 min', 300 => '5 min']; ?>
            <?php foreach ($refreshOptions as $refreshValue => $refreshLabel): ?>
                <option value="<?php echo h(rr_combined_tab_url($selfUrl, $mainTab, $tailLines, (int)$refreshValue, $classYear)); ?>" <?php echo $autoRefresh === (int)$refreshValue ? 'selected' : ''; ?>>Refresh: <?php echo h($refreshLabel); ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ($autoRefresh > 0): ?>
            <span class="pill">Next Refresh: <span id="countdown"><?php echo h((string)$autoRefresh); ?></span>s</span>
        <?php endif; ?>

        <a class="btn" href="<?php echo h(rr_combined_tab_url($selfUrl, $mainTab, $tailLines, $autoRefresh, $classYear)); ?>">Reload Now</a>
    </div>
</div>

<?php if ($mainTab === 'scheduler'): ?>

<div class="grid">

    <div class="card status-card">
        <h2>Race Scheduler</h2>
        <?php
        $raceTask = isset($tasks['race_results_monitor']) && is_array($tasks['race_results_monitor']) ? $tasks['race_results_monitor'] : [];
        $raceTaskState = isset($stateTasks['race_results_monitor']) && is_array($stateTasks['race_results_monitor']) ? $stateTasks['race_results_monitor'] : [];
        $raceAuto = isset($raceTaskState['auto_schedule']) && is_array($raceTaskState['auto_schedule']) ? $raceTaskState['auto_schedule'] : [];
        $autoDecision = isset($raceAuto['decision']) && is_array($raceAuto['decision']) ? $raceAuto['decision'] : [];
        $autoNextRace = isset($raceAuto['next_race']) && is_array($raceAuto['next_race']) ? $raceAuto['next_race'] : [];
        $autoStatus = isset($raceTaskState['last_check_status']) ? (string)$raceTaskState['last_check_status'] : (string)($raceTaskState['last_status'] ?? '');
        $autoMessage = isset($raceTaskState['last_check_message']) ? (string)$raceTaskState['last_check_message'] : (string)($raceTaskState['last_message'] ?? '');
        $autoStatusClass = rr_dash_task_status_class($autoStatus, isset($raceTaskState['last_exit_code']) ? (string)$raceTaskState['last_exit_code'] : '');
        $autoInterval = isset($autoDecision['interval_minutes']) ? (int)$autoDecision['interval_minutes'] : 0;
        $autoLapFound = !empty($autoDecision['lap_status_found']);
        $autoLapText = $autoLapFound
            ? ('Lap ' . sd_html((string)($autoDecision['lap_current'] ?? '')) . ' of ' . sd_html((string)($autoDecision['lap_total'] ?? '')))
            : 'not found yet';
        ?>
        <?php if (empty($raceTask)): ?>
            <p class="msg">No race_results_monitor task found in _scheduler/schedule.json.</p>
        <?php elseif (empty($raceAuto)): ?>
            <p class="msg">Race scheduler state has not been written yet. Wait for cron_master_scheduler.php v008 to run once.</p>
            <p class="small">Configured mode: <?php echo sd_html((string)($raceTask['type'] ?? '')); ?></p>
        <?php else: ?>
            <div class="race-progress-row">
                <span class="pill"><strong>Task:</strong> <?php echo sd_html((string)($raceTask['script'] ?? 'race_results_monitor.php')); ?></span>
                <span class="pill"><strong>Mode:</strong> <?php echo sd_html((string)($raceTask['type'] ?? '')); ?></span>
                <span class="pill"><strong>Next Race:</strong> <?php echo sd_html($autoNextRace['label'] ?? ''); ?></span>
                <span class="pill"><strong>Start:</strong> <?php echo sd_html($autoNextRace['start_text'] ?? $autoNextRace['start_at'] ?? ''); ?></span>
                <span class="pill"><strong>Phase:</strong> <?php echo sd_html($autoDecision['phase_label'] ?? $autoDecision['phase'] ?? ''); ?></span>
                <span class="pill"><strong>Interval:</strong> <?php echo $autoInterval > 0 ? 'every ' . sd_html((string)$autoInterval) . ' min' : 'disabled'; ?></span>
                <span class="pill"><strong>Lap Status:</strong> <?php echo $autoLapText; ?></span>
                <span class="pill <?php echo sd_html($autoStatusClass); ?>"><strong>Last Status:</strong> <?php echo sd_html($autoStatus !== '' ? $autoStatus : 'unknown'); ?></span>
            </div>

            <table class="section-spacer">
                <tbody>
                    <tr>
                        <th>Generated</th>
                        <td><?php echo sd_html(rr_dash_display_datetime((string)($raceAuto['generated_at'] ?? ''))); ?></td>
                    </tr>
                    <tr>
                        <th>Last Monitor Run Used</th>
                        <td><?php echo sd_html(rr_dash_display_datetime((string)($autoDecision['last_monitor_run_at'] ?? ''))); ?></td>
                    </tr>
                    <tr>
                        <th>Next Due</th>
                        <td><?php echo sd_html(rr_dash_display_datetime((string)($autoDecision['next_due_at'] ?? ''))); ?></td>
                    </tr>
                    <tr>
                        <th>Due Reason</th>
                        <td><?php echo sd_html($autoDecision['due_reason'] ?? $autoMessage); ?></td>
                    </tr>
                    <tr>
                        <th>Monitor Output</th>
                        <td>
                            <?php if (!empty($raceTaskState['last_output_tail'])): ?>
                                <div class="mono"><?php echo sd_html((string)$raceTaskState['last_output_tail']); ?></div>
                            <?php else: ?>
                                <span class="small">No monitor output from an auto race-aware run yet.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="small">Scheduler config: _scheduler/schedule.json | Race data: _race_results_schedule.json | Monitor state: _race_results_monitor_state.json</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Revision Scheduler</h2>

        <?php if (empty($tasks) || empty($tasks['race_results_revision_monitor'])): ?>
            <p class="msg">No race_results_revision_monitor task found in schedule.json.</p>
        <?php else: ?>
            <?php
            $revisionTaskState = isset($stateTasks['race_results_revision_monitor']) && is_array($stateTasks['race_results_revision_monitor']) ? $stateTasks['race_results_revision_monitor'] : [];
            $revisionSchedule = isset($revisionTaskState['revision_schedule']) && is_array($revisionTaskState['revision_schedule']) ? $revisionTaskState['revision_schedule'] : [];
            $revisionDecision = isset($revisionSchedule['decision']) && is_array($revisionSchedule['decision']) ? $revisionSchedule['decision'] : [];
            $revisionHandoff = isset($revisionSchedule['handoff']) && is_array($revisionSchedule['handoff']) ? $revisionSchedule['handoff'] : [];
            ?>
            <?php if (!empty($revisionSchedule)): ?>
                <table class="section-spacer">
                    <tbody>
                        <tr>
                            <th>Mode</th>
                            <td><?php echo sd_html((string)($revisionSchedule['mode'] ?? '')); ?></td>
                            <th>Phase</th>
                            <td><?php echo sd_html((string)($revisionDecision['phase_label'] ?? $revisionDecision['phase'] ?? '')); ?></td>
                        </tr>
                        <tr>
                            <th>Latest Final</th>
                            <td><?php echo sd_html((string)($revisionHandoff['race_name'] ?? '')); ?></td>
                            <th>Interval</th>
                            <td><?php echo array_key_exists('interval_minutes', $revisionDecision) && $revisionDecision['interval_minutes'] !== null ? 'every ' . sd_html((string)$revisionDecision['interval_minutes']) . ' min' : 'daily times'; ?></td>
                        </tr>
                        <tr>
                            <th>Handoff</th>
                            <td><?php echo sd_html((string)($revisionHandoff['status'] ?? '')); ?></td>
                            <th>Next Due</th>
                            <td><?php echo sd_html(rr_dash_display_datetime((string)($revisionDecision['next_due_at'] ?? ''))); ?></td>
                        </tr>
                        <tr>
                            <th>Due Reason</th>
                            <td colspan="3"><?php echo sd_html((string)($revisionDecision['due_reason'] ?? '')); ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
            <table>
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Enabled</th>
                        <th>Schedule</th>
                        <th>Due Now</th>
                        <th>Next Run</th>
                        <th>Last Attempt</th>
                        <th>Last Completed</th>
                        <th>Last Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $taskName => $task): ?>
                    <?php
                    if ($taskName !== 'race_results_revision_monitor') {
                        continue;
                    }
                    if (!is_array($task)) {
                        continue;
                    }

                    $taskState = isset($stateTasks[$taskName]) && is_array($stateTasks[$taskName]) ? $stateTasks[$taskName] : [];
                    $type = isset($task['type']) ? (string)$task['type'] : 'interval';

                    if ($type === 'daily_times') {
                        $calc = sd_daily_task_status($task, $taskState, $now, $tz);
                    } elseif ($type === 'auto_revision_monitor') {
                        $calc = sd_auto_revision_task_status($task, $taskState, $now, $tz);
                    } elseif ($type === 'auto_race_monitor') {
                        $calc = sd_auto_race_task_status($task, $taskState, $now, $tz);
                    } else {
                        $calc = sd_interval_task_status($task, $taskState, $now, $tz);
                    }

                    $enabled = !isset($task['enabled']) || !empty($task['enabled']);
                    $lastStatus = isset($taskState['last_status']) ? (string)$taskState['last_status'] : '';
                    $lastMessage = isset($taskState['last_message']) ? (string)$taskState['last_message'] : '';
                    $exitCode = array_key_exists('last_exit_code', $taskState) ? (string)$taskState['last_exit_code'] : '';

                    $dueClass = $calc['due'] ? 'warn' : 'good';
                    $enabledClass = $enabled ? 'good' : 'bad';
                    $lastStatusClass = rr_dash_task_status_class($lastStatus, $exitCode);
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo sd_html($taskName); ?></strong><br>
                            <span class="small"><?php echo sd_html($task['script'] ?? ''); ?></span>
                        </td>
                        <td><span class="status <?php echo $enabledClass; ?>"><?php echo $enabled ? 'YES' : 'NO'; ?></span></td>
                        <td><?php echo sd_html($calc['schedule_text']); ?></td>
                        <td><span class="status <?php echo $dueClass; ?>"><?php echo sd_html($calc['due_text']); ?></span></td>
                        <td><?php echo sd_html(rr_dash_display_datetime((string)$calc['next_run'])); ?></td>
                        <td><?php echo sd_html(rr_dash_display_datetime((string)$calc['last_attempt'])); ?></td>
                        <td><?php echo sd_html(rr_dash_display_datetime((string)$calc['last_completed'])); ?></td>
                        <td>
                            <span class="status <?php echo sd_html($lastStatusClass); ?>"><?php echo sd_html($lastStatus !== '' ? $lastStatus : 'unknown'); ?></span>
                            <?php if ($exitCode !== ''): ?>
                                <span class="small">exit: <?php echo sd_html($exitCode); ?></span>
                            <?php endif; ?>
                            <?php if ($lastMessage !== ''): ?>
                                <br><span class="small"><?php echo sd_html($lastMessage); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="grid two">
        <div class="card status-card">
            <div class="status-list">
                <?php foreach ($fileRows as $label => $path): ?>
                    <?php $status = sd_file_status($path); ?>
                    <div class="status-row">
                        <div class="label"><?php echo sd_html($label); ?></div>
                        <div>
                            <span class="status <?php echo $status['present'] ? 'good' : 'bad'; ?>">
                                <?php echo $status['present'] ? 'Present' : 'Missing'; ?>
                            </span>
                        </div>
                        <div class="meta">
                            Modified: <?php echo sd_html($status['modified']); ?> |
                            Size: <?php echo sd_html($status['size']); ?>
                            <?php if ($status['present']): ?>
                                | <a class="inline-link" href="<?php echo sd_html(rr_dash_cache_busted_href('_scheduler/' . basename($path), $path)); ?>" target="_blank" rel="noopener">Open raw file</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="status-row">
                    <div class="label">Bundle JSON</div>
                    <div><span class="status good">Available</span></div>
                    <div class="meta">
                        Generated on demand |
                        <a class="inline-link" href="<?php echo sd_html(rr_dash_cache_busted_href('?tab=scheduler&export=bundle')); ?>" target="_blank" rel="noopener">Open raw view</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Heartbeat</h2>
            <?php if (trim($heartbeat) === ''): ?>
                <p class="msg">No heartbeat found yet.</p>
            <?php else: ?>
                <div class="mono"><?php echo sd_html(trim($heartbeat)); ?></div>
                <p class="small">Health: <span class="status <?php echo sd_html((string)$schedulerHeartbeatFreshness['class']); ?>"><?php echo sd_html((string)$schedulerHeartbeatFreshness['label']); ?></span> <?php echo sd_html((string)$schedulerHeartbeatFreshness['age_text']); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>Last Scheduler Summary</h2>
        <table>
            <tbody>
                <tr>
                    <th>Last Run</th>
                    <td><?php echo sd_html($state['last_scheduler_run_at'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Last Completed</th>
                    <td><?php echo sd_html($state['last_scheduler_complete_at'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>SAPI</th>
                    <td><?php echo sd_html($state['last_scheduler_run_sapi'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Summary</th>
                    <td>
                        <?php
                        $summary = isset($state['last_scheduler_summary']) && is_array($state['last_scheduler_summary'])
                            ? $state['last_scheduler_summary']
                            : [];
                        echo 'TASKS: checked=' . sd_html($summary['checked'] ?? '')
                            . ' ran=' . sd_html($summary['ran'] ?? '')
                            . ' skipped=' . sd_html($summary['skipped'] ?? '')
                            . ' errors=' . sd_html($summary['errors'] ?? '');
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>Base Dir</th>
                    <td class="mono"><?php echo sd_html($state['base_dir'] ?? $baseDir); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Recent Scheduler Log</h2>
        <?php if (empty($logLines)): ?>
            <p class="msg">No scheduler log lines found yet.</p>
        <?php else: ?>
            <div class="mono"><?php echo sd_html(implode("\n", $logLines)); ?></div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($mainTab === 'monitor'): ?>

<div class="card" style="margin-bottom:16px;">
    <h2>Last Log Line</h2>
    <?php if ($lastLogLine !== ''): ?>
    <div class="last-line"><?=h($lastLogLine)?></div>
    <?php else: ?>
    <div class="empty">Monitor log file is missing or empty.</div>
    <?php endif; ?>
</div>

<div class="card race-progress-card">
    <h2>Race Status</h2>
    <?php if (!empty($monitorCurrentRaceStatus['found'])): ?>
        <div class="race-progress-row">
            <?php if ((string)$monitorCurrentRaceStatus['race_name'] !== ''): ?>
                <span class="pill"><strong><?php echo h((string)($monitorCurrentRaceStatus['label'] ?? 'Current Race')); ?>:</strong> <?php echo h((string)$monitorCurrentRaceStatus['race_name']); ?></span>
            <?php endif; ?>
            <?php if ((string)$monitorCurrentRaceStatus['status'] !== ''): ?>
                <span class="pill"><strong>Status:</strong> <?php echo h((string)$monitorCurrentRaceStatus['status']); ?></span>
            <?php else: ?>
                <span class="pill warn"><strong>Status:</strong> lap status not found yet</span>
            <?php endif; ?>
            <?php if (!empty($monitorCurrentRaceStatus['checked_at'])): ?>
                <span class="pill"><strong>Checked:</strong> <?php echo h((string)$monitorCurrentRaceStatus['checked_at']); ?></span>
            <?php endif; ?>
            <?php if (!empty($monitorCurrentRaceStatus['can_open_race_page']) && (string)$monitorCurrentRaceStatus['url'] !== ''): ?>
                <a class="btn" href="<?php echo h((string)$monitorCurrentRaceStatus['url']); ?>" target="_blank" rel="noopener">Open race page</a>
            <?php endif; ?>
        </div>
    <?php elseif ($monitorStatusUrl !== ''): ?>
        <div class="race-progress-row">
            <span class="pill warn"><strong>Race Status:</strong> race page found, status not readable yet</span>
            <a class="btn" href="<?php echo h($monitorStatusUrl); ?>" target="_blank" rel="noopener">Open race page</a>
        </div>
    <?php else: ?>
        <div class="empty">No stored race status found yet. It should appear after race_results_monitor.php runs.</div>
    <?php endif; ?>
</div>

<div class="grid race-grid">

    <div class="card status-card">
        <div class="next-run-row">
            <span class="pill"><strong>Next Run:</strong> <?php echo h($monitorNextRun !== '' ? $monitorNextRun : 'not scheduled'); ?></span>
            <?php if ($monitorNextRunEpoch !== ''): ?>
                <span class="pill countdown-pill" data-next-run-ts="<?php echo h($monitorNextRunEpoch); ?>">calculating...</span>
            <?php endif; ?>
        </div>
        <div class="status-list">

            <div class="status-row">
                <div class="label">Heartbeat</div>
                <div class="badge <?=h(rr_dash_status_class($heartbeatExists))?>"><?=h(rr_dash_status_label($heartbeatExists))?></div>
                <div class="meta">
                    Modified: <?=h(rr_dash_file_mtime_string($heartbeatFile))?> |
                    Size: <?=h(rr_dash_file_size_string($heartbeatFile))?> |
                    <a class="inline-link" href="<?=h(rr_dash_cache_busted_href('_race_results_monitor_heartbeat.txt', $heartbeatFile))?>" target="_blank" rel="noopener">Open raw file</a>
                </div>
            </div>

            <div class="status-row">
                <div class="label">State JSON</div>
                <div class="badge <?=h(rr_dash_status_class($stateExists))?>"><?=h(rr_dash_status_label($stateExists))?></div>
                <div class="meta">
                    Modified: <?=h(rr_dash_file_mtime_string($stateFile))?> |
                    Size: <?=h(rr_dash_file_size_string($stateFile))?> |
                    <a class="inline-link" href="<?=h(rr_dash_cache_busted_href('_race_results_monitor_state.json', $stateFile))?>" target="_blank" rel="noopener">Open raw file</a>
                </div>
            </div>

            <div class="status-row">
                <div class="label">RD Status</div>
                <div class="badge <?=h(rr_dash_status_class($rdStatusExists))?>"><?=h(rr_dash_status_label($rdStatusExists))?></div>
                <div class="meta">
                    Modified: <?=h(rr_dash_file_mtime_string($rdStatusFile))?> |
                    Size: <?=h(rr_dash_file_size_string($rdStatusFile))?> |
                    <a class="inline-link" href="<?=h(rr_dash_cache_busted_href('_race_results_rd_status.json', $rdStatusFile))?>" target="_blank" rel="noopener">Open raw file</a>
                    <?php if ($rdStatusCode !== ''): ?>
                        | Status: <?=h($rdStatusCode)?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="status-row">
                <div class="label">Monitor Log</div>
                <div class="badge <?=h(rr_dash_status_class($logExists))?>"><?=h(rr_dash_status_label($logExists))?></div>
                <div class="meta">
                    Modified: <?=h(rr_dash_file_mtime_string($logFile))?> |
                    Size: <?=h(rr_dash_file_size_string($logFile))?> |
                    <a class="inline-link" href="<?=h(rr_dash_cache_busted_href('_race_results_monitor.log', $logFile))?>" target="_blank" rel="noopener">Open raw file</a>
                </div>
            </div>

        </div>
    </div>

    <div class="card">
        <h2>Heartbeat</h2>
        <?php if ($heartbeatRaw !== ''): ?>
        <pre><?=h(trim($heartbeatRaw))?></pre>
        <?php else: ?>
        <div class="empty">Heartbeat file is missing or empty.</div>
        <?php endif; ?>
    </div>

    <details class="card full json-toggle-card">
        <summary><h2>RD Status JSON</h2> <span class="json-toggle-label"></span></summary>
        <div class="json-toggle-body">
            <?php if ($rdStatusPretty !== ''): ?>
            <?php if ($rdStatusMessage !== ''): ?>
            <div class="last-line" style="margin-bottom:12px;"><?=h($rdStatusMessage)?></div>
            <?php endif; ?>
            <pre><?=h($rdStatusPretty)?></pre>
            <?php else: ?>
            <div class="empty">RD status JSON file is missing or empty. It will appear after the monitor runs an RD check.</div>
            <?php endif; ?>
        </div>
    </details>

    <details class="card full json-toggle-card">
        <summary><h2>Monitor State JSON</h2> <span class="json-toggle-label"></span></summary>
        <div class="json-toggle-body">
            <?php if ($statePretty !== ''): ?>
            <pre><?=h($statePretty)?></pre>
            <?php else: ?>
            <div class="empty">State JSON file is missing or empty.</div>
            <?php endif; ?>
        </div>
    </details>

    <div class="card full">
        <h2>Last <?=h((string)$tailLines)?> Log Lines</h2>
        <?php if ($logTailRaw !== ''): ?>
        <pre><?=h(trim($logTailRaw))?></pre>
        <?php else: ?>
        <div class="empty">Monitor log file is missing or empty.</div>
        <?php endif; ?>
    </div>

</div>

<?php else: ?>

<div class="card" style="margin-bottom:16px;">
    <h2>Last Log Line</h2>
    <?php if ($revLastLogLine !== ''): ?>
    <div class="last-line"><?=h($revLastLogLine)?></div>
    <?php else: ?>
    <div class="empty">Revision monitor log file is missing or empty.</div>
    <?php endif; ?>
</div>

<div class="grid race-grid">

    <div class="card status-card">
        <div class="next-run-row">
            <span class="pill"><strong>Next Run:</strong> <?php echo h($revisionNextRun !== '' ? $revisionNextRun : 'not scheduled'); ?></span>
            <?php if ($revisionNextRunEpoch !== ''): ?>
                <span class="pill countdown-pill" data-next-run-ts="<?php echo h($revisionNextRunEpoch); ?>">calculating...</span>
            <?php endif; ?>
        </div>
        <div class="status-list">

            <div class="status-row">
                <div class="label">Heartbeat</div>
                <div class="badge <?=h(rr_dash_status_class($revHeartbeatExists))?>"><?=h(rr_dash_status_label($revHeartbeatExists))?></div>
                <div class="meta">
                    Modified: <?=h(rr_dash_file_mtime_string($revHeartbeatFile))?> |
                    Size: <?=h(rr_dash_file_size_string($revHeartbeatFile))?> |
                    <a class="inline-link" href="<?=h(rr_dash_cache_busted_href('_race_results_revision_monitor_heartbeat.txt', $revHeartbeatFile))?>" target="_blank" rel="noopener">Open raw file</a>
                </div>
            </div>

            <div class="status-row">
                <div class="label">Revision Log</div>
                <div class="badge <?=h(rr_dash_status_class($revLogExists))?>"><?=h(rr_dash_status_label($revLogExists))?></div>
                <div class="meta">
                    Modified: <?=h(rr_dash_file_mtime_string($revLogFile))?> |
                    Size: <?=h(rr_dash_file_size_string($revLogFile))?> |
                    <a class="inline-link" href="<?=h(rr_dash_cache_busted_href('_race_results_revision_monitor.log', $revLogFile))?>" target="_blank" rel="noopener">Open raw file</a>
                </div>
            </div>
            <div class="status-row">
                <div class="label">Class Summary</div>
                <div class="badge <?=h(rr_dash_status_class($classSummaryExists))?>"><?=h(rr_dash_status_label($classSummaryExists))?></div>
                <div class="meta">
                    Modified: <?=h(rr_dash_file_mtime_string($classSummaryFile))?> |
                    Size: <?=h(rr_dash_file_size_string($classSummaryFile))?> |
                    <a class="inline-link" href="<?=h(rr_dash_cache_busted_href('_race_results_classification_summary.json', $classSummaryFile))?>" target="_blank" rel="noopener">Open raw file</a>
                </div>
            </div>

            <div class="status-row">
                <div class="label">Class Last Run</div>
                <div class="badge <?=h(rr_dash_status_class($classLastRunExists))?>"><?=h(rr_dash_status_label($classLastRunExists))?></div>
                <div class="meta">
                    Modified: <?=h(rr_dash_file_mtime_string($classLastRunFile))?> |
                    Size: <?=h(rr_dash_file_size_string($classLastRunFile))?> |
                    <a class="inline-link" href="<?=h(rr_dash_cache_busted_href('_race_results_classification_last_run.json', $classLastRunFile))?>" target="_blank" rel="noopener">Open raw file</a>
                </div>
            </div>

        </div>
    </div>

    <div class="card">
        <h2>Heartbeat</h2>
        <?php if ($revHeartbeatRaw !== ''): ?>
        <pre><?=h(trim($revHeartbeatRaw))?></pre>
        <?php else: ?>
        <div class="empty">Revision monitor heartbeat file is missing or empty.</div>
        <?php endif; ?>

        <div class="diagnostic-row">
            <div class="label">Classifier</div>
            <div class="badge <?=h(rr_dash_status_class($classifierExists))?>"><?=h(rr_dash_status_label($classifierExists))?></div>
            <div class="meta">
                Script modified: <?=h(rr_dash_file_mtime_string($classifierFile))?> |
                Size: <?=h(rr_dash_file_size_string($classifierFile))?>
            </div>
        </div>
    </div>

    <div class="card full">
        <div class="revision-summary-header">
            <h2>Revision Summary</h2>
            <span class="pill">Generated: <?=h((string)$classSummary['generated_at'] !== '' ? rr_dash_display_datetime((string)$classSummary['generated_at']) : '—')?></span>
            <form method="post" class="inline-form" onsubmit="return confirm('Refresh Revision Summary now?\n\nThis will re-read the snapshots already stored in the race folders and refresh the dashboard summary. It will not check the source site or create new snapshots.');">
                <input type="hidden" name="rr_dash_action" value="refresh_revision_summary">
                <input type="hidden" name="tab" value="revision">
                <input type="hidden" name="lines" value="<?=h((string)$tailLines)?>">
                <input type="hidden" name="refresh" value="<?=h((string)$autoRefresh)?>">
                <input type="hidden" name="year" value="<?=h((string)$classYear)?>">
                <button type="submit" class="btn revision-run-button">Refresh Summary</button>
            </form>
            <form method="post" class="inline-form" onsubmit="return confirm('Regenerate Revision Summary now?\n\nThis will run the revision monitor, check the source site for revised completed-race results, save new snapshots if found, and regenerate the Revision Summary.');">
                <input type="hidden" name="rr_dash_action" value="regenerate_revision_summary">
                <input type="hidden" name="tab" value="revision">
                <input type="hidden" name="lines" value="<?=h((string)$tailLines)?>">
                <input type="hidden" name="refresh" value="<?=h((string)$autoRefresh)?>">
                <input type="hidden" name="year" value="<?=h((string)$classYear)?>">
                <button type="submit" class="btn revision-run-button">Regenerate Summary</button>
            </form>
            <button type="button" class="btn detail-toggle summary-details-toggle" id="summary-details-toggle" aria-expanded="false" aria-controls="summary-details-panel">Show Summary Details</button>
            <?php if ($revisionRunNotice !== ''): ?>
            <div class="revision-run-notice" id="revision-run-notice"><span class="badge <?=h($revisionRunNoticeClass)?>"><?=h($revisionRunNotice)?></span></div>
            <?php endif; ?>
        </div>

        <div class="summary-details-panel" id="summary-details-panel" hidden>
            <div class="summary-pills">
                <span class="pill">Year: <?=h((string)$classSummary['year'])?></span>
                <span class="pill">Source: <?=!empty($classSummary['trusted_source']) ? 'Trusted summary' : 'Missing trusted summary'?></span>
                <span class="pill">Classified: <?=h((string)$classSummary['classified_count'])?></span>
                <span class="pill">MRL Impact: <?=h((string)$classSummary['mrl_impact_count'])?></span>
                <span class="pill">All-Driver Change Races: <?=h((string)$classSummary['all_driver_change_race_count'])?></span>
                <?php if ((string)$classSummary['signature'] !== ''): ?>
                <span class="pill">Signature: <?=h((string)$classSummary['signature'])?></span>
                <?php endif; ?>
                <?php if ((string)$classSummary['generated_at'] !== ''): ?>
                <span class="pill">Generated: <?=h(rr_dash_display_datetime((string)$classSummary['generated_at']))?></span>
                <?php endif; ?>
                <?php if ((string)$classSummary['sapi'] !== ''): ?>
                <span class="pill">SAPI: <?=h((string)$classSummary['sapi'])?></span>
                <?php endif; ?>
            </div>
            <?php if ((string)$classSummary['message'] !== ''): ?>
            <div class="last-line"><?=h((string)$classSummary['message'])?></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($classSummary['rows']) && is_array($classSummary['rows'])): ?>
        <table class="dash-table">
            <thead>
                <tr>
                    <th>Race</th>
                    <th>MRL Impact</th>
                    <th>Changed All</th>
                    <th>MRL-Listed</th>
                    <th>Segment-Picked</th>
                    <th>Display</th>
                    <th>Status</th>
                    <th>Snapshots</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classSummary['rows'] as $row): ?>
                <?php
                    $impactBadge = !empty($row['mrl_impact']) ? 'bad' : 'ok';
                    $displayText = (string)($row['display_tag'] ?? '');
                    if ($displayText === '') $displayText = '—';
                    $statusText = (string)($row['status_label'] ?? '');
                    if ($statusText === '') $statusText = !empty($row['classified']) ? 'Classified' : 'Not classified';
                    $snapText = trim((string)($row['previous_snapshot'] ?? '') . ' → ' . (string)($row['current_snapshot'] ?? ''));
                    if ($snapText === '→') $snapText = '—';
                ?>
                <tr>
                    <td><?=h((string)$row['race_code'])?> <?=h((string)$row['race_label'])?></td>
                    <td><span class="badge <?=h($impactBadge)?>"><?=!empty($row['mrl_impact']) ? 'YES' : 'NO'?></span></td>
                    <td><?=h((string)$row['changed_all_drivers'])?></td>
                    <td><?=h((string)($row['changed_mrl_listed_drivers'] ?? 0))?></td>
                    <td><?=h((string)($row['changed_segment_picked_drivers'] ?? $row['changed_mrl_drivers']))?></td>
                    <td><?=h($displayText)?></td>
                    <td><?=h($statusText)?></td>
                    <td><?=h($snapText)?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
            $changedDetailRows = [];
            foreach ($classSummary['rows'] as $detailSourceRow) {
                if (!is_array($detailSourceRow)) continue;
                $details = isset($detailSourceRow['changed_driver_details']) && is_array($detailSourceRow['changed_driver_details'])
                    ? $detailSourceRow['changed_driver_details']
                    : [];

                foreach ($details as $detailRow) {
                    if (!is_array($detailRow)) continue;
                    $changedDetailRows[] = [
                        'race_code' => (string)($detailSourceRow['race_code'] ?? ''),
                        'race_label' => (string)($detailSourceRow['race_label'] ?? ''),
                        'detail' => $detailRow,
                    ];
                }
            }
        ?>

        <?php if (!empty($changedDetailRows)): ?>
        <details id="changed-driver-details" style="margin-top:14px;">
            <summary class="btn detail-toggle">Show Changed Driver Details</summary>
            <table class="dash-table" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th>Race</th>
                        <th>Driver</th>
                        <th>MRL-Listed</th>
                        <th>Segment-Picked</th>
                        <th>PTS</th>
                        <th>BONUS</th>
                        <th>PENALTY</th>
                        <th>NET</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($changedDetailRows as $changedDetailRow): ?>
                    <?php
                        $detail = isset($changedDetailRow['detail']) && is_array($changedDetailRow['detail']) ? $changedDetailRow['detail'] : [];
                        $mrlListed = !empty($detail['mrl_listed']);
                        $segmentPicked = !empty($detail['segment_picked']);
                    ?>
                    <tr>
                        <td><?=h(trim((string)$changedDetailRow['race_code'] . ' ' . (string)$changedDetailRow['race_label']))?></td>
                        <td><?=h((string)($detail['driver'] ?? ''))?></td>
                        <td><span class="detail-flag <?=h($mrlListed ? '' : 'muted-no')?>"><?=h(rr_dash_yes_no($mrlListed))?></span></td>
                        <td><span class="detail-flag <?=h($segmentPicked ? '' : 'muted-no')?>"><?=h(rr_dash_yes_no($segmentPicked))?></span></td>
                        <td><?=h(rr_dash_format_score_change($detail, 'pts'))?></td>
                        <td><?=h(rr_dash_format_score_change($detail, 'bonus'))?></td>
                        <td><?=h(rr_dash_format_score_change($detail, 'penalty'))?></td>
                        <td><?=h(rr_dash_format_score_change($detail, 'net'))?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php endif; ?>
        <?php else: ?>
        <div class="empty"><?=h((string)$classSummary['message'])?></div>
        <?php endif; ?>
    </div>

    <details class="card full json-toggle-card">
        <summary><h2>Revision Summary Data</h2> <span class="json-toggle-label"></span></summary>
        <div class="json-toggle-body">
            <?php if ($classLastRunPretty !== ''): ?>
            <pre><?=h($classLastRunPretty)?></pre>
            <?php else: ?>
            <div class="empty">Revision summary data file is missing or empty. Run the Revision Summary to create it.</div>
            <?php endif; ?>
        </div>
    </details>

    <div class="card full">
        <h2>Last <?=h((string)$tailLines)?> Log Lines</h2>
        <?php if ($revLogTailRaw !== ''): ?>
        <pre><?=h(trim($revLogTailRaw))?></pre>
        <?php else: ?>
        <div class="empty">Revision monitor log file is missing or empty.</div>
        <?php endif; ?>
    </div>

</div>

<?php endif; ?>

<div class="footer">
    Auto-refreshes according to selected refresh setting. Generated by race_results_dashboard.php <?php echo h(RACE_RESULTS_DASHBOARD_VERSION); ?>.
</div>

<script>
(function () {
    var detailsEl = document.getElementById('changed-driver-details');
    if (!detailsEl || !window.localStorage) return;

    var storageKey = 'mrlDashboardChangedDriverDetailsOpen';
    var savedState = localStorage.getItem(storageKey);

    var summaryEl = detailsEl.querySelector('summary');

    function updateChangedDriverDetailsLabel() {
        if (!summaryEl) return;
        summaryEl.textContent = detailsEl.open ? 'Hide Changed Driver Details' : 'Show Changed Driver Details';
    }

    if (savedState === '1') {
        detailsEl.open = true;
    }

    updateChangedDriverDetailsLabel();

    detailsEl.addEventListener('toggle', function () {
        localStorage.setItem(storageKey, detailsEl.open ? '1' : '0');
        updateChangedDriverDetailsLabel();
    });
})();
</script>

<script>
(function () {
    var toggle = document.getElementById('summary-details-toggle');
    var panel = document.getElementById('summary-details-panel');
    var storageKey = 'mrlDashboardRevisionSummaryDetailsOpen';

    if (toggle && panel) {
        function setOpen(isOpen) {
            panel.hidden = !isOpen;
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.textContent = isOpen ? 'Hide Summary Details' : 'Show Summary Details';
            if (window.localStorage) {
                localStorage.setItem(storageKey, isOpen ? '1' : '0');
            }
        }

        var savedState = window.localStorage ? localStorage.getItem(storageKey) : '';
        setOpen(savedState === '1');

        toggle.addEventListener('click', function () {
            setOpen(panel.hidden);
        });
    }

    var notice = document.getElementById('revision-run-notice');
    if (notice) {
        window.setTimeout(function () {
            notice.classList.add('is-hidden');
        }, 8000);

        if (window.history && window.history.replaceState && window.URL) {
            try {
                var url = new URL(window.location.href);
                if (url.searchParams.has('rr_run') || url.searchParams.has('rr_msg')) {
                    url.searchParams.delete('rr_run');
                    url.searchParams.delete('rr_msg');
                    window.history.replaceState(null, document.title, url.pathname + url.search + url.hash);
                }
            } catch (e) {}
        }
    }
})();
</script>

<script>
(function () {
    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function formatDuration(totalSeconds) {
        totalSeconds = Math.max(0, Math.floor(totalSeconds));
        var days = Math.floor(totalSeconds / 86400);
        var remainder = totalSeconds % 86400;
        var hours = Math.floor(remainder / 3600);
        remainder = remainder % 3600;
        var minutes = Math.floor(remainder / 60);
        var seconds = remainder % 60;

        var clock = hours + ':' + pad2(minutes) + ':' + pad2(seconds);
        if (days > 0) {
            return days + 'd ' + clock + ' from now';
        }
        return clock + ' from now';
    }

    function updateNextRunCountdowns() {
        var nowSeconds = Math.floor(Date.now() / 1000);
        document.querySelectorAll('[data-next-run-ts]').forEach(function (el) {
            var target = parseInt(el.getAttribute('data-next-run-ts') || '0', 10);
            if (!target) {
                el.textContent = '';
                return;
            }
            var remaining = target - nowSeconds;
            if (remaining <= 0) {
                el.textContent = 'due now';
                return;
            }
            el.textContent = formatDuration(remaining);
        });
    }

    updateNextRunCountdowns();
    window.setInterval(updateNextRunCountdowns, 1000);
})();
</script>

<script>
(function () {
    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function clickTimestamp() {
        var d = new Date();
        return String(d.getFullYear()) +
            pad2(d.getMonth() + 1) +
            pad2(d.getDate()) + '_' +
            pad2(d.getHours()) +
            pad2(d.getMinutes()) +
            pad2(d.getSeconds());
    }

    document.querySelectorAll('a.inline-link[target="_blank"][href*="t="]').forEach(function (link) {
        link.addEventListener('click', function () {
            try {
                var url = new URL(link.getAttribute('href'), window.location.href);
                url.searchParams.set('t', clickTimestamp());
                link.setAttribute('href', url.pathname + url.search + url.hash);
            } catch (e) {
                var href = link.getAttribute('href') || '';
                var fresh = clickTimestamp();
                if (href.indexOf('t=') >= 0) {
                    href = href.replace(/([?&]t=)[^&#]*/, '$1' + fresh);
                } else {
                    href += (href.indexOf('?') === -1 ? '?' : '&') + 't=' + fresh;
                }
                link.setAttribute('href', href);
            }
        });
    });
})();
</script>

<?php if ($autoRefresh > 0): ?>
<script>
(function () {
    var refreshSeconds = <?= (int)$autoRefresh ?>;
    var countdownEl = document.getElementById('countdown');
    var remaining = refreshSeconds;

    function tick() {
        if (countdownEl) countdownEl.textContent = String(remaining);
        if (remaining <= 0) { window.location.reload(); return; }
        remaining -= 1;
        window.setTimeout(tick, 1000);
    }

    tick();
})();
</script>
<?php endif; ?>

</body>
</html>
