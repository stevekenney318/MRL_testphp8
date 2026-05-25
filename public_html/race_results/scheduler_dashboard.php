<?php
declare(strict_types=1);

/**
 * scheduler_dashboard.php
 *
 * VERSION: v002
 * LAST MODIFIED: 5/25/2026 1:26:55 pm
 *
 * CHANGELOG:
 * v002 (5/25/2026)
 *   - CHANGE: Reduced native dashboard sizing to roughly 80% of prior visual scale.
 *   - CHANGE: Tightened page padding, card spacing, table cells, headings, pills, status badges, and monospaced log text so the dashboard fits better without browser zoom changes.
 *
 * v001 (5/25/2026)
 *   - NEW: Simple dashboard for cron_master_scheduler.php v001.
 *   - NEW: Reads _scheduler/schedule.json, state.json, heartbeat.txt, and log.txt.
 *   - NEW: Shows scheduler status, file status, task due/next-run information, last run status, and recent log tail.
 *   - NEW: Uses only relative paths so it works in both live and testphp8 race_results folders.
 */

date_default_timezone_set('America/New_York');

const SCHEDULER_DASHBOARD_VERSION = 'v002';

$baseDir = __DIR__;
$schedulerDir = $baseDir . '/_scheduler';

$schedulePath  = $schedulerDir . '/schedule.json';
$statePath     = $schedulerDir . '/state.json';
$heartbeatPath = $schedulerDir . '/heartbeat.txt';
$logPath       = $schedulerDir . '/log.txt';

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
        'modified' => $mtime ? date('Y-m-d g:i:s A', (int)$mtime) : '',
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

function sd_daily_task_status(array $task, array $taskState, DateTimeImmutable $now, DateTimeZone $tz): array
{
    $times = isset($task['times']) && is_array($task['times']) ? $task['times'] : [];
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

$tasks = isset($schedule['tasks']) && is_array($schedule['tasks']) ? $schedule['tasks'] : [];
$stateTasks = isset($state['tasks']) && is_array($state['tasks']) ? $state['tasks'] : [];

$fileRows = [
    'Schedule' => $schedulePath,
    'State' => $statePath,
    'Heartbeat' => $heartbeatPath,
    'Log' => $logPath,
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MRL Scheduler Dashboard</title>
<meta http-equiv="refresh" content="60">
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
    }

    body {
        margin: 0;
        padding: 16px;
        background: var(--bg);
        color: var(--text);
        font-family: Arial, Helvetica, sans-serif;
        font-size: 14px;
    }

    h1, h2, h3 {
        color: var(--gold);
        margin: 0 0 10px 0;
    }

    h1 {
        font-size: 29px;
        line-height: 1.15;
    }

    h2 {
        font-size: 22px;
        line-height: 1.15;
    }

    h3 {
        font-size: 17px;
        line-height: 1.15;
    }

    .topline {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 12px;
    }

    .pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        border: 1px solid #5a5142;
        border-radius: 999px;
        padding: 6px 10px;
        background: #252525;
        font-size: 13px;
        line-height: 1.2;
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

    .grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 14px;
    }

    @media (min-width: 1100px) {
        .grid.two {
            grid-template-columns: 1fr 1fr;
        }
    }

    .card {
        border: 1px solid #5a5142;
        background: linear-gradient(180deg, #222, #1e1e1e);
        border-radius: 14px;
        padding: 14px;
        box-shadow: 0 8px 22px rgba(0,0,0,0.25);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background: var(--panel);
        border-radius: 10px;
        overflow: hidden;
    }

    th, td {
        padding: 7px 8px;
        border-bottom: 1px solid #333;
        text-align: left;
        vertical-align: top;
        line-height: 1.25;
    }

    th {
        color: var(--gold);
        background: var(--panel2);
        font-size: 13px;
    }

    td {
        font-size: 13px;
    }

    tr:last-child td {
        border-bottom: none;
    }

    .status {
        font-weight: 700;
        border-radius: 999px;
        padding: 4px 8px;
        display: inline-block;
        font-size: 12px;
        line-height: 1.15;
    }

    .status.good {
        color: var(--green);
        background: var(--green-bg);
        border: 1px solid #477a59;
    }

    .status.bad {
        color: var(--red);
        background: var(--red-bg);
        border: 1px solid #7a4b4b;
    }

    .status.warn {
        color: var(--yellow);
        background: var(--yellow-bg);
        border: 1px solid #7c6736;
    }

    .mono {
        font-family: Consolas, Monaco, "Courier New", monospace;
        white-space: pre-wrap;
        word-break: break-word;
        font-size: 12px;
        line-height: 1.35;
    }

    .small {
        color: var(--muted);
        font-size: 12px;
        line-height: 1.25;
    }

    a {
        color: var(--blue);
        text-decoration: none;
    }

    a:hover {
        text-decoration: underline;
    }

    .msg {
        color: var(--muted);
    }

    .footer {
        color: #999;
        margin-top: 12px;
        font-size: 11px;
        text-align: center;
    }
</style>
</head>
<body>

<h1>MRL Scheduler Dashboard</h1>

<div class="topline">
    <span class="pill <?php echo $schedulerEnabled ? 'good' : 'bad'; ?>">
        Scheduler: <?php echo $schedulerEnabled ? 'enabled' : 'disabled'; ?>
    </span>
    <span class="pill <?php echo $dryRun ? 'warn' : 'good'; ?>">
        Mode: <?php echo $dryRun ? 'dry run' : 'active'; ?>
    </span>
    <span class="pill">Year: <?php echo sd_html($year !== '' ? $year : 'not set'); ?></span>
    <span class="pill">Timezone: <?php echo sd_html($timezoneName); ?></span>
    <span class="pill">Now: <?php echo sd_html($now->format('Y-m-d g:i:s A')); ?></span>
    <span class="pill">Dashboard: <?php echo sd_html(SCHEDULER_DASHBOARD_VERSION); ?></span>
</div>

<div class="grid">
    <div class="card">
        <h2>Tasks</h2>

        <?php if (empty($tasks)): ?>
            <p class="msg">No tasks found in schedule.json.</p>
        <?php else: ?>
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
                    if (!is_array($task)) {
                        continue;
                    }

                    $taskState = isset($stateTasks[$taskName]) && is_array($stateTasks[$taskName]) ? $stateTasks[$taskName] : [];
                    $type = isset($task['type']) ? (string)$task['type'] : 'interval';

                    if ($type === 'daily_times') {
                        $calc = sd_daily_task_status($task, $taskState, $now, $tz);
                    } else {
                        $calc = sd_interval_task_status($task, $taskState, $now, $tz);
                    }

                    $enabled = !isset($task['enabled']) || !empty($task['enabled']);
                    $lastStatus = isset($taskState['last_status']) ? (string)$taskState['last_status'] : '';
                    $lastMessage = isset($taskState['last_message']) ? (string)$taskState['last_message'] : '';
                    $exitCode = array_key_exists('last_exit_code', $taskState) ? (string)$taskState['last_exit_code'] : '';

                    $dueClass = $calc['due'] ? 'warn' : 'good';
                    $enabledClass = $enabled ? 'good' : 'bad';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo sd_html($taskName); ?></strong><br>
                            <span class="small"><?php echo sd_html($task['script'] ?? ''); ?></span>
                        </td>
                        <td><span class="status <?php echo $enabledClass; ?>"><?php echo $enabled ? 'YES' : 'NO'; ?></span></td>
                        <td><?php echo sd_html($calc['schedule_text']); ?></td>
                        <td><span class="status <?php echo $dueClass; ?>"><?php echo sd_html($calc['due_text']); ?></span></td>
                        <td><?php echo sd_html($calc['next_run']); ?></td>
                        <td><?php echo sd_html($calc['last_attempt']); ?></td>
                        <td><?php echo sd_html($calc['last_completed']); ?></td>
                        <td>
                            <?php echo sd_html($lastStatus !== '' ? $lastStatus : ''); ?>
                            <?php if ($exitCode !== ''): ?>
                                <br><span class="small">exit: <?php echo sd_html($exitCode); ?></span>
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
        <div class="card">
            <h2>Scheduler Files</h2>
            <table>
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Status</th>
                        <th>Modified</th>
                        <th>Size</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fileRows as $label => $path): ?>
                        <?php $status = sd_file_status($path); ?>
                        <tr>
                            <td>
                                <?php if ($status['present']): ?>
                                    <a href="<?php echo sd_html('_scheduler/' . basename($path)); ?>" target="_blank"><?php echo sd_html($label); ?></a>
                                <?php else: ?>
                                    <?php echo sd_html($label); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status <?php echo $status['present'] ? 'good' : 'bad'; ?>">
                                    <?php echo $status['present'] ? 'Present' : 'Missing'; ?>
                                </span>
                            </td>
                            <td><?php echo sd_html($status['modified']); ?></td>
                            <td><?php echo sd_html($status['size']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Heartbeat</h2>
            <?php if (trim($heartbeat) === ''): ?>
                <p class="msg">No heartbeat found yet.</p>
            <?php else: ?>
                <div class="mono"><?php echo sd_html(trim($heartbeat)); ?></div>
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
                        echo 'ran=' . sd_html($summary['ran'] ?? '')
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

<div class="footer">
    Auto-refreshes every 60 seconds. Generated by scheduler_dashboard.php <?php echo sd_html(SCHEDULER_DASHBOARD_VERSION); ?>.
</div>

</body>
</html>
