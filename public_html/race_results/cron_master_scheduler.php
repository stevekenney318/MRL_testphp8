<?php
declare(strict_types=1);

/**
 * cron_master_scheduler.php
 *
 * VERSION: v001
 * LAST MODIFIED: 5/25/2026 12:31:55 pm
 *
 * DESCRIPTION:
 * Basic JSON-driven master scheduler for MRL race-results automation.
 * Intended to be launched frequently by one Hostinger cron job and decide,
 * from local JSON/state files, whether to run race_results_monitor.php and/or
 * race_results_revision_monitor.php.
 *
 * FILE PLACEMENT:
 * - Place this file in /race_results/ as cron_master_scheduler.php
 * - Place schedule.json in /race_results/_scheduler/schedule.json
 * - Scheduler runtime files are kept in /race_results/_scheduler/
 *
 * NORMAL FLOW:
 * cron_master_scheduler.php
 *   -> race_results_monitor.php
 *   -> race_results_revision_monitor.php
 *        -> race_results_classify_revisions.php when revisions are detected
 *
 * CHANGELOG:
 * v001 (5/25/2026)
 * - NEW: Initial JSON-driven master scheduler.
 * - NEW: Uses relative paths from __DIR__ so the same file works on live and testphp8.
 * - NEW: Stores scheduler heartbeat, log, state, and lock files in _scheduler/.
 * - NEW: Supports interval tasks and daily-time tasks.
 * - NEW: Supports optional interval override windows such as race-day faster polling.
 * - NEW: Prevents overlapping task runs with per-task lock files.
 * - NEW: Browser and CLI output for quick manual checks.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const CMS_VERSION = 'v001';
const CMS_SIGNATURE = 'CRON_MASTER_SCHEDULER v001';

$baseDir = __DIR__;
$schedulerDir = $baseDir . '/_scheduler';
$scheduleFile = $schedulerDir . '/schedule.json';
$stateFile = $schedulerDir . '/state.json';
$heartbeatFile = $schedulerDir . '/heartbeat.txt';
$logFile = $schedulerDir . '/log.txt';
$locksDir = $schedulerDir . '/locks';

cms_ensure_dir($schedulerDir);
cms_ensure_dir($locksDir);

$now = time();
$runToken = bin2hex(random_bytes(8));
$phpSapi = PHP_SAPI;
$scriptSha = cms_file_sha256(__FILE__);

$yearOverride = cms_cli_year_arg();

cms_write_file_atomic(
    $heartbeatFile,
    cms_now_string() . "  token={$runToken}  sig=" . CMS_SIGNATURE . "  sapi={$phpSapi}  sha={$scriptSha}\n"
);

cms_log($logFile, CMS_SIGNATURE . " RUN sapi={$phpSapi} token={$runToken} sha={$scriptSha}");
cms_out('=== ' . CMS_SIGNATURE . ' ===');
cms_out('Run started: ' . cms_now_string());
cms_out('Base dir: ' . $baseDir);
cms_out('Scheduler dir: ' . $schedulerDir);
cms_out('---');

$schedule = cms_load_json($scheduleFile);
if (empty($schedule)) {
    cms_log($logFile, 'ABORT schedule file missing or invalid: ' . $scheduleFile);
    cms_out('ABORT: schedule file missing or invalid: ' . $scheduleFile);
    exit(0);
}

if (empty($schedule['enabled'])) {
    cms_log($logFile, 'SCHEDULER DISABLED by schedule.json');
    cms_out('Scheduler disabled by schedule.json.');
    exit(0);
}

if (!empty($schedule['timezone']) && is_string($schedule['timezone'])) {
    @date_default_timezone_set($schedule['timezone']);
}

$state = cms_load_json($stateFile);
if (empty($state)) {
    $state = [
        'signature' => CMS_SIGNATURE,
        'version' => CMS_VERSION,
        'created_at' => cms_now_string(),
        'tasks' => [],
    ];
}

$state['signature'] = CMS_SIGNATURE;
$state['version'] = CMS_VERSION;
$state['last_scheduler_run_at'] = cms_now_string();
$state['last_scheduler_run_sapi'] = $phpSapi;
$state['last_scheduler_run_token'] = $runToken;
$state['base_dir'] = $baseDir;

$defaultYear = (int)($schedule['year'] ?? $schedule['default_year'] ?? date('Y'));
$year = $yearOverride > 0 ? $yearOverride : $defaultYear;
$globalDryRun = !empty($schedule['dry_run']);
$tasks = isset($schedule['tasks']) && is_array($schedule['tasks']) ? $schedule['tasks'] : [];

$ranCount = 0;
$skippedCount = 0;
$errorCount = 0;

foreach ($tasks as $taskName => $task) {
    if (!is_array($task)) {
        $skippedCount++;
        cms_log($logFile, "TASK SKIP {$taskName} invalid config");
        continue;
    }

    $taskName = cms_safe_task_name((string)$taskName);
    if ($taskName === '') {
        $skippedCount++;
        continue;
    }

    if (!isset($state['tasks'][$taskName]) || !is_array($state['tasks'][$taskName])) {
        $state['tasks'][$taskName] = [];
    }

    if (empty($task['enabled'])) {
        $skippedCount++;
        cms_set_task_status($state, $taskName, 'disabled', 'Task disabled.');
        cms_out("SKIP {$taskName}: disabled");
        continue;
    }

    $dueInfo = cms_task_due($taskName, $task, $state, $now);
    if (empty($dueInfo['due'])) {
        $skippedCount++;
        cms_set_task_status($state, $taskName, 'not_due', (string)$dueInfo['reason']);
        cms_out("SKIP {$taskName}: " . (string)$dueInfo['reason']);
        continue;
    }

    $script = (string)($task['script'] ?? '');
    if ($script === '' || strpos($script, '..') !== false || strpos($script, '/') !== false || strpos($script, '\\') !== false) {
        $skippedCount++;
        $errorCount++;
        cms_set_task_status($state, $taskName, 'error', 'Invalid script path.');
        cms_log($logFile, "TASK ERROR {$taskName} invalid script={$script}");
        cms_out("ERROR {$taskName}: invalid script path");
        continue;
    }

    $scriptPath = $baseDir . '/' . $script;
    if (!is_file($scriptPath)) {
        $skippedCount++;
        $errorCount++;
        cms_set_task_status($state, $taskName, 'error', 'Script not found: ' . $scriptPath);
        cms_log($logFile, "TASK ERROR {$taskName} script not found {$scriptPath}");
        cms_out("ERROR {$taskName}: script not found");
        continue;
    }

    $lockMinutes = (int)($task['lock_minutes'] ?? 10);
    if ($lockMinutes < 1) $lockMinutes = 1;
    $lockPath = $locksDir . '/' . $taskName . '.lock';

    $lockHandle = cms_acquire_lock($lockPath, $lockMinutes);
    if ($lockHandle === false) {
        $skippedCount++;
        cms_set_task_status($state, $taskName, 'locked', 'Existing lock active.');
        cms_log($logFile, "TASK SKIP {$taskName} lock active");
        cms_out("SKIP {$taskName}: lock active");
        continue;
    }

    $taskDryRun = $globalDryRun || !empty($task['dry_run']);
    $args = cms_expand_args(isset($task['args']) && is_array($task['args']) ? $task['args'] : ['{{year}}'], $year);
    $attemptAt = cms_now_string();

    $state['tasks'][$taskName]['last_attempt_at'] = $attemptAt;
    $state['tasks'][$taskName]['last_due_reason'] = (string)$dueInfo['reason'];
    $state['tasks'][$taskName]['last_interval_minutes'] = isset($dueInfo['interval_minutes']) ? (int)$dueInfo['interval_minutes'] : null;
    $state['tasks'][$taskName]['last_command_script'] = $script;
    $state['tasks'][$taskName]['last_run_token'] = $runToken;

    cms_write_file_atomic($stateFile, cms_json_pretty($state) . "\n");

    if ($taskDryRun) {
        $ranCount++;
        cms_set_task_status($state, $taskName, 'dry_run', 'Due but dry_run enabled.');
        cms_mark_daily_run_if_needed($state, $taskName, $dueInfo);
        cms_log($logFile, "TASK DRY_RUN {$taskName} reason=" . (string)$dueInfo['reason']);
        cms_out("DRY RUN {$taskName}: " . (string)$dueInfo['reason']);
        cms_release_lock($lockHandle, $lockPath);
        continue;
    }

    cms_log($logFile, "TASK RUN {$taskName} script={$script} reason=" . (string)$dueInfo['reason']);
    cms_out("RUN {$taskName}: " . (string)$dueInfo['reason']);

    $result = cms_run_php_script($scriptPath, $args, (int)($task['timeout_seconds'] ?? 300));
    $state['tasks'][$taskName]['last_exit_code'] = $result['exit_code'];
    $state['tasks'][$taskName]['last_output_tail'] = $result['output_tail'];
    $state['tasks'][$taskName]['last_completed_at'] = cms_now_string();

    if ((int)$result['exit_code'] === 0) {
        $ranCount++;
        cms_set_task_status($state, $taskName, 'success', 'Completed successfully.');
        cms_mark_daily_run_if_needed($state, $taskName, $dueInfo);
        cms_log($logFile, "TASK SUCCESS {$taskName} exit=0");
        cms_out("DONE {$taskName}: success");
    } else {
        $ranCount++;
        $errorCount++;
        cms_set_task_status($state, $taskName, 'error', 'Exit code ' . (string)$result['exit_code']);
        cms_log($logFile, "TASK ERROR {$taskName} exit=" . (string)$result['exit_code'] . " output=" . cms_compact_log_text($result['output_tail']));
        cms_out("ERROR {$taskName}: exit " . (string)$result['exit_code']);
    }

    cms_release_lock($lockHandle, $lockPath);
}

$state['last_scheduler_complete_at'] = cms_now_string();
$state['last_scheduler_summary'] = [
    'ran' => $ranCount,
    'skipped' => $skippedCount,
    'errors' => $errorCount,
];

cms_write_file_atomic($stateFile, cms_json_pretty($state) . "\n");
cms_log($logFile, CMS_SIGNATURE . " DONE ran={$ranCount} skipped={$skippedCount} errors={$errorCount} token={$runToken}");

cms_out('---');
cms_out("Run complete: " . cms_now_string());
cms_out("Tasks ran: {$ranCount}");
cms_out("Tasks skipped: {$skippedCount}");
cms_out("Errors: {$errorCount}");

exit(0);

function cms_cli_year_arg(): int
{
    if (PHP_SAPI !== 'cli') return 0;
    global $argv;
    if (!isset($argv) || !is_array($argv)) return 0;
    foreach ($argv as $arg) {
        if (preg_match('/^\d{4}$/', (string)$arg)) {
            $year = (int)$arg;
            if ($year >= 2000 && $year <= 2100) return $year;
        }
    }
    return 0;
}

function cms_ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function cms_now_string(): string
{
    return date('Y-m-d H:i:s');
}

function cms_out(string $line): void
{
    if (PHP_SAPI === 'cli') {
        echo $line . PHP_EOL;
        return;
    }
    echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "<br>\n";
}

function cms_log(string $logFile, string $line): void
{
    $entry = '[' . cms_now_string() . '] ' . $line . "\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function cms_load_json(string $path): array
{
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function cms_json_pretty(array $data): string
{
    return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function cms_write_file_atomic(string $path, string $content): void
{
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    @file_put_contents($tmp, $content, LOCK_EX);
    @rename($tmp, $path);
}

function cms_file_sha256(string $path): string
{
    return is_file($path) ? hash_file('sha256', $path) : '';
}

function cms_safe_task_name(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
    return trim((string)$name, '_-');
}

function cms_set_task_status(array &$state, string $taskName, string $status, string $message): void
{
    if (!isset($state['tasks'][$taskName]) || !is_array($state['tasks'][$taskName])) {
        $state['tasks'][$taskName] = [];
    }
    $state['tasks'][$taskName]['last_status'] = $status;
    $state['tasks'][$taskName]['last_message'] = $message;
    $state['tasks'][$taskName]['last_status_at'] = cms_now_string();
}

function cms_task_due(string $taskName, array $task, array $state, int $now): array
{
    $type = (string)($task['type'] ?? 'interval');

    if ($type === 'daily_times') {
        return cms_daily_time_task_due($taskName, $task, $state, $now);
    }

    return cms_interval_task_due($taskName, $task, $state, $now);
}

function cms_interval_task_due(string $taskName, array $task, array $state, int $now): array
{
    $interval = (int)($task['interval_minutes'] ?? 60);
    if ($interval < 1) $interval = 1;

    $windowName = '';
    if (isset($task['windows']) && is_array($task['windows'])) {
        foreach ($task['windows'] as $name => $window) {
            if (!is_array($window)) continue;
            if (cms_window_matches($window, $now)) {
                $winInterval = (int)($window['interval_minutes'] ?? $interval);
                if ($winInterval >= 1) {
                    $interval = $winInterval;
                    $windowName = (string)$name;
                    break;
                }
            }
        }
    }

    $taskState = isset($state['tasks'][$taskName]) && is_array($state['tasks'][$taskName]) ? $state['tasks'][$taskName] : [];
    $lastAttemptAt = (string)($taskState['last_attempt_at'] ?? '');
    $lastAttemptTs = $lastAttemptAt !== '' ? strtotime($lastAttemptAt) : false;

    if ($lastAttemptTs === false || $lastAttemptTs <= 0) {
        return [
            'due' => true,
            'reason' => $windowName !== '' ? "first run / window {$windowName}" : 'first run',
            'interval_minutes' => $interval,
        ];
    }

    $elapsed = (int)floor(($now - (int)$lastAttemptTs) / 60);
    if ($elapsed >= $interval) {
        return [
            'due' => true,
            'reason' => $windowName !== ''
                ? "interval {$interval} minutes elapsed / window {$windowName}"
                : "interval {$interval} minutes elapsed",
            'interval_minutes' => $interval,
        ];
    }

    return [
        'due' => false,
        'reason' => "not due; {$elapsed}/{$interval} minutes elapsed",
        'interval_minutes' => $interval,
    ];
}

function cms_daily_time_task_due(string $taskName, array $task, array $state, int $now): array
{
    $times = isset($task['times']) && is_array($task['times']) ? $task['times'] : [];
    $currentHm = date('H:i', $now);
    $today = date('Y-m-d', $now);

    foreach ($times as $time) {
        $time = trim((string)$time);
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) continue;
        if ($time !== $currentHm) continue;

        $taskState = isset($state['tasks'][$taskName]) && is_array($state['tasks'][$taskName]) ? $state['tasks'][$taskName] : [];
        $dailyRuns = isset($taskState['daily_runs']) && is_array($taskState['daily_runs']) ? $taskState['daily_runs'] : [];
        $key = $today . ' ' . $time;

        if (!empty($dailyRuns[$key])) {
            return ['due' => false, 'reason' => "daily time {$time} already ran today"];
        }

        return ['due' => true, 'reason' => "daily time {$time}", 'daily_key' => $key];
    }

    return ['due' => false, 'reason' => "not at scheduled daily time; now {$currentHm}"];
}

function cms_mark_daily_run_if_needed(array &$state, string $taskName, array $dueInfo): void
{
    if (empty($dueInfo['daily_key'])) return;
    if (!isset($state['tasks'][$taskName]) || !is_array($state['tasks'][$taskName])) {
        $state['tasks'][$taskName] = [];
    }
    if (!isset($state['tasks'][$taskName]['daily_runs']) || !is_array($state['tasks'][$taskName]['daily_runs'])) {
        $state['tasks'][$taskName]['daily_runs'] = [];
    }
    $state['tasks'][$taskName]['daily_runs'][(string)$dueInfo['daily_key']] = cms_now_string();

    // Keep this from growing forever.
    if (count($state['tasks'][$taskName]['daily_runs']) > 40) {
        $state['tasks'][$taskName]['daily_runs'] = array_slice($state['tasks'][$taskName]['daily_runs'], -20, null, true);
    }
}

function cms_window_matches(array $window, int $now): bool
{
    if (isset($window['enabled']) && empty($window['enabled'])) return false;

    if (isset($window['days']) && is_array($window['days']) && !empty($window['days'])) {
        $dow = (int)date('N', $now); // 1=Mon, 7=Sun
        $matchesDay = false;
        foreach ($window['days'] as $day) {
            if ((int)$day === $dow) {
                $matchesDay = true;
                break;
            }
        }
        if (!$matchesDay) return false;
    }

    $start = (string)($window['start'] ?? '00:00');
    $end = (string)($window['end'] ?? '23:59');
    if (!preg_match('/^\d{2}:\d{2}$/', $start)) $start = '00:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $end)) $end = '23:59';

    $current = date('H:i', $now);

    if ($start <= $end) {
        return $current >= $start && $current <= $end;
    }

    // Overnight window, for example 22:00 -> 02:00.
    return $current >= $start || $current <= $end;
}

function cms_expand_args(array $args, int $year): array
{
    $out = [];
    foreach ($args as $arg) {
        $arg = (string)$arg;
        $arg = str_replace(['{{year}}', '{{YEAR}}', '{{ year }}'], (string)$year, $arg);
        $out[] = $arg;
    }
    return $out;
}

function cms_acquire_lock(string $lockPath, int $lockMinutes)
{
    if (is_file($lockPath)) {
        $age = time() - (int)@filemtime($lockPath);
        if ($age > ($lockMinutes * 60)) {
            @unlink($lockPath);
        }
    }

    $handle = @fopen($lockPath, 'x');
    if ($handle === false) return false;
    fwrite($handle, cms_now_string() . ' pid=' . getmypid() . "\n");
    fflush($handle);
    return $handle;
}

function cms_release_lock($handle, string $lockPath): void
{
    if (is_resource($handle)) {
        @fclose($handle);
    }
    @unlink($lockPath);
}

function cms_run_php_script(string $scriptPath, array $args, int $timeoutSeconds): array
{
    $php = PHP_BINARY;
    if ($php === '' || !is_file($php)) {
        $php = '/usr/bin/php';
    }

    $cmdParts = [escapeshellarg($php), escapeshellarg($scriptPath)];
    foreach ($args as $arg) {
        $cmdParts[] = escapeshellarg((string)$arg);
    }

    $cmd = implode(' ', $cmdParts) . ' 2>&1';
    $output = [];
    $exitCode = 0;

    // timeoutSeconds is stored for future proc_open refinement; exec is sufficient for v001.
    @exec($cmd, $output, $exitCode);

    $tail = implode("\n", array_slice($output, -25));

    return [
        'command' => $cmd,
        'exit_code' => (int)$exitCode,
        'output_tail' => $tail,
    ];
}

function cms_compact_log_text(string $text): string
{
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim((string)$text);
    if (strlen($text) > 500) {
        $text = substr($text, 0, 500) . '...';
    }
    return $text;
}
