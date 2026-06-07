<?php
declare(strict_types=1);

/**
 * cron_master_scheduler.php
 *
 * VERSION: v009
 * LAST MODIFIED: 6/7/2026 11:02:17 am
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
 * v009 (6/7/2026)
 * - FIX: Auto revision normal-daily phase now writes the next upcoming daily run time into state for dashboard display.
 * - NEW: Adds auto_revision_monitor task type for post-race revision-monitor scheduling.
 * - NEW: Revision monitor can run frequently after a new final-result handoff, then scale back to 3-hour and 6-hour intervals before returning to normal daily times.
 * - NEW: Stores revision scheduler decision details inside _scheduler/state.json under tasks.race_results_revision_monitor.revision_schedule.
 * - NOTE: First run records the currently known final race as the baseline and does not retroactively trigger a post-race burst for older races.
 *
 * v008 (6/7/2026)
 * - NEW: Adds auto_race_monitor task type for race-aware monitor scheduling.
 * - NEW: Reads _race_results_schedule.json and _race_results_monitor_state.json as supporting data.
 * - CHANGE: race_results_monitor can now be scheduled from actual next-race start time while still using _scheduler/schedule.json as the control file.
 * - NEW: Stores auto race scheduler decision details inside _scheduler/state.json under tasks.race_results_monitor.auto_schedule.
 * - NOTE: No second Hostinger cron or separate auto scheduler script is required.
 *
 * v007 (5/28/2026)
 * - NEW: Added cache-busting scheduler query values to URL task calls.
 * - NEW: Heartbeat verification now retries once briefly before warning.
 * - PURPOSE: Improves reliability for short interval URL/LiteSpeed monitor runs.
 * - NEW: Added manual force-run flags: run_now_monitor.flag, run_now_revision.flag, run_now_all.flag.
 * - NOTE: Existing run_now.flag is kept as a legacy shortcut for the regular monitor.
 * - CHANGE: Monitor tasks now default to running through the local site URL instead of PHP child exec.
 * - PURPOSE: Uses the proven LiteSpeed/browser execution path while keeping scheduler timing local.
 * - NEW: URL is derived automatically from __DIR__; no hardcoded live/testphp8 full paths are needed.
 * - NEW: Supports optional task run_method values: auto, url, php.
 * - NEW: Stores last_run_method and last_url in scheduler state for easier debugging.
 *
 * v006 (5/25/2026)
 * - CHANGE: Child task runner now defaults to /usr/bin/php instead of PHP_BINARY.
 * - NEW: Optional schedule.json php_binary setting can override the child PHP executable.
 * - PURPOSE: Match the known-working Hostinger cron command behavior more closely.
 *
 * v005 (5/25/2026)
 * - FIX: Child PHP scripts now run from the scheduler base directory using cd before php execution.
 * - NEW: Captures command working directory in task state for easier debugging.
 * - NEW: Verifies configured task heartbeat files after successful child script runs.
 * - CHANGE: Normal skipped/not-due checks no longer overwrite the last actual task run status.
 * - CHANGE: State now keeps separate last_check_* and last_actual_* fields.
 *
 * v004 (5/25/2026)
 * - NEW: Interval tasks can run immediately when the scheduler resumes after being stopped.
 * - NEW: Adds resume_gap_minutes support in schedule.json; default is 3 minutes if omitted.
 * - NEW: Adds _scheduler/run_now.flag support to force interval tasks on the next scheduler wake-up.
 * - CHANGE: Scheduler state now records resume/force-run context for easier dashboard/debug review.
 *
 * v003 (5/25/2026)
 * - CHANGE: CLI/cron runs are now quiet by default so Hostinger View Output stays clean.
 * - NEW: Add --verbose CLI option for manual command-line output when needed.
 * - NOTE: Browser/manual web runs still show visible output.
 *
 * v002 (5/25/2026)
 * - CHANGE: Scheduler DONE log now labels task counters clearly with TASKS checked/ran/skipped/errors.
 * - CHANGE: Scheduler state summary now stores checked task count.
 *
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

const CMS_VERSION = 'v009';
const CMS_SIGNATURE = 'CRON_MASTER_SCHEDULER v009';

$baseDir = __DIR__;
$schedulerDir = $baseDir . '/_scheduler';
$scheduleFile = $schedulerDir . '/schedule.json';
$stateFile = $schedulerDir . '/state.json';
$heartbeatFile = $schedulerDir . '/heartbeat.txt';
$logFile = $schedulerDir . '/log.txt';
$locksDir = $schedulerDir . '/locks';
$runNowFlagFile = $schedulerDir . '/run_now.flag'; // Legacy: force regular monitor.
$runNowMonitorFlagFile = $schedulerDir . '/run_now_monitor.flag';
$runNowRevisionFlagFile = $schedulerDir . '/run_now_revision.flag';
$runNowAllFlagFile = $schedulerDir . '/run_now_all.flag';

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
$stateWasMissing = empty($state);

$previousSchedulerRunAt = '';
$previousSchedulerRunTs = false;
if (!$stateWasMissing) {
    $previousSchedulerRunAt = (string)($state['last_scheduler_run_at'] ?? '');
    if ($previousSchedulerRunAt !== '') {
        $previousSchedulerRunTs = strtotime($previousSchedulerRunAt);
    }
}

$resumeGapMinutes = (int)($schedule['resume_gap_minutes'] ?? 3);
if ($resumeGapMinutes < 1) $resumeGapMinutes = 1;

$schedulerResumeDetected = false;
$schedulerResumeReason = '';
if ($previousSchedulerRunTs !== false && $previousSchedulerRunTs > 0) {
    $resumeElapsedMinutes = (int)floor(($now - (int)$previousSchedulerRunTs) / 60);
    if ($resumeElapsedMinutes >= $resumeGapMinutes) {
        $schedulerResumeDetected = true;
        $schedulerResumeReason = 'scheduler resumed after ' . (string)$resumeElapsedMinutes . ' minutes idle';
    }
}

$forceRunFlagDetected = is_file($runNowFlagFile);
$forceRunMonitorFlagDetected = is_file($runNowMonitorFlagFile);
$forceRunRevisionFlagDetected = is_file($runNowRevisionFlagFile);
$forceRunAllFlagDetected = is_file($runNowAllFlagFile);

if ($stateWasMissing) {
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
$state['resume_gap_minutes'] = $resumeGapMinutes;
$state['scheduler_resume_detected'] = $schedulerResumeDetected;
$state['scheduler_resume_reason'] = $schedulerResumeReason;
$state['force_run_flag_detected'] = $forceRunFlagDetected;
$state['force_run_flags'] = [
    'run_now.flag' => $forceRunFlagDetected,
    'run_now_monitor.flag' => $forceRunMonitorFlagDetected,
    'run_now_revision.flag' => $forceRunRevisionFlagDetected,
    'run_now_all.flag' => $forceRunAllFlagDetected,
];

$defaultYear = (int)($schedule['year'] ?? $schedule['default_year'] ?? date('Y'));
$year = $yearOverride > 0 ? $yearOverride : $defaultYear;
$globalDryRun = !empty($schedule['dry_run']);
$childPhpBinary = cms_choose_child_php_binary((string)($schedule['php_binary'] ?? '/usr/bin/php'));
$publicBaseUrl = cms_public_base_url($baseDir, $schedule);
$state['child_php_binary'] = $childPhpBinary;
$state['public_base_url'] = $publicBaseUrl;
$tasks = isset($schedule['tasks']) && is_array($schedule['tasks']) ? $schedule['tasks'] : [];

$checkedCount = 0;
$ranCount = 0;
$skippedCount = 0;
$errorCount = 0;

foreach ($tasks as $taskName => $task) {
    $checkedCount++;
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
        cms_set_task_check_status($state, $taskName, 'disabled', 'Task disabled.');
        cms_out("SKIP {$taskName}: disabled");
        continue;
    }

    $taskForceFlagDetected = cms_task_force_flag_detected(
        $taskName,
        $forceRunFlagDetected,
        $forceRunMonitorFlagDetected,
        $forceRunRevisionFlagDetected,
        $forceRunAllFlagDetected
    );

    $dueInfo = cms_task_due($taskName, $task, $state, $now, $schedulerResumeDetected, $schedulerResumeReason, $taskForceFlagDetected, $baseDir, $year);
    if (isset($dueInfo['auto_schedule']) && is_array($dueInfo['auto_schedule'])) {
        $state['tasks'][$taskName]['auto_schedule'] = $dueInfo['auto_schedule'];
    }
    if (isset($dueInfo['revision_schedule']) && is_array($dueInfo['revision_schedule'])) {
        $state['tasks'][$taskName]['revision_schedule'] = $dueInfo['revision_schedule'];
    }
    if (empty($dueInfo['due'])) {
        $skippedCount++;
        cms_set_task_check_status($state, $taskName, 'not_due', (string)$dueInfo['reason']);
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
        cms_set_task_check_status($state, $taskName, 'locked', 'Existing lock active.');
        cms_log($logFile, "TASK SKIP {$taskName} lock active");
        cms_out("SKIP {$taskName}: lock active");
        continue;
    }

    $taskDryRun = $globalDryRun || !empty($task['dry_run']);
    $args = cms_expand_args(isset($task['args']) && is_array($task['args']) ? $task['args'] : ['{{year}}'], $year);
    $attemptAt = cms_now_string();

    $state['tasks'][$taskName]['last_attempt_at'] = $attemptAt; // legacy alias; actual run attempt
    $state['tasks'][$taskName]['last_actual_attempt_at'] = $attemptAt;
    $state['tasks'][$taskName]['last_due_reason'] = (string)$dueInfo['reason'];
    $state['tasks'][$taskName]['last_interval_minutes'] = isset($dueInfo['interval_minutes']) ? (int)$dueInfo['interval_minutes'] : null;
    $runMethod = cms_task_run_method($taskName, $task);
    $taskUrl = cms_task_url($publicBaseUrl, $script, $args, $runToken);

    $state['tasks'][$taskName]['last_command_script'] = $script;
    $state['tasks'][$taskName]['last_command_cwd'] = $baseDir;
    $state['tasks'][$taskName]['last_run_method'] = $runMethod;
    $state['tasks'][$taskName]['last_url'] = $runMethod === 'url' ? $taskUrl : '';
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

    cms_log($logFile, "TASK RUN {$taskName} method={$runMethod} script={$script} reason=" . (string)$dueInfo['reason']);
    cms_out("RUN {$taskName}: " . (string)$dueInfo['reason']);

    if ($runMethod === 'url') {
        $result = cms_run_url($taskUrl, (int)($task['timeout_seconds'] ?? 300));
    } else {
        $result = cms_run_php_script($scriptPath, $args, (int)($task['timeout_seconds'] ?? 300), $baseDir, $childPhpBinary);
    }
    $state['tasks'][$taskName]['last_exit_code'] = $result['exit_code'];
    $state['tasks'][$taskName]['last_output_tail'] = $result['output_tail'];
    $state['tasks'][$taskName]['last_command'] = $result['command'];
    $state['tasks'][$taskName]['last_completed_at'] = cms_now_string(); // legacy alias; actual run completed
    $state['tasks'][$taskName]['last_actual_completed_at'] = $state['tasks'][$taskName]['last_completed_at'];

    $verification = cms_verify_task_outputs($taskName, $task, $baseDir, $attemptAt);
    $state['tasks'][$taskName]['last_verification'] = $verification;

    if ((int)$result['exit_code'] === 0 && !empty($verification['ok'])) {
        $ranCount++;
        cms_set_task_status($state, $taskName, 'success', 'Completed successfully. ' . (string)($verification['message'] ?? ''));
        cms_mark_daily_run_if_needed($state, $taskName, $dueInfo);
        cms_log($logFile, "TASK SUCCESS {$taskName} exit=0 verify=" . (string)($verification['message'] ?? ''));
        cms_out("DONE {$taskName}: success");
    } elseif ((int)$result['exit_code'] === 0) {
        $ranCount++;
        $errorCount++;
        cms_set_task_status($state, $taskName, 'warning', 'Exit 0, but verification failed. ' . (string)($verification['message'] ?? ''));
        cms_log($logFile, "TASK WARNING {$taskName} exit=0 verify_failed=" . (string)($verification['message'] ?? ''));
        cms_out("WARNING {$taskName}: exit 0 but verification failed");
    } else {
        $ranCount++;
        $errorCount++;
        cms_set_task_status($state, $taskName, 'error', 'Exit code ' . (string)$result['exit_code']);
        cms_log($logFile, "TASK ERROR {$taskName} exit=" . (string)$result['exit_code'] . " output=" . cms_compact_log_text($result['output_tail']));
        cms_out("ERROR {$taskName}: exit " . (string)$result['exit_code']);
    }

    cms_release_lock($lockHandle, $lockPath);
}

cms_consume_force_flag($logFile, $runNowFlagFile, 'run_now.flag');
cms_consume_force_flag($logFile, $runNowMonitorFlagFile, 'run_now_monitor.flag');
cms_consume_force_flag($logFile, $runNowRevisionFlagFile, 'run_now_revision.flag');
cms_consume_force_flag($logFile, $runNowAllFlagFile, 'run_now_all.flag');

$state['last_scheduler_complete_at'] = cms_now_string();
$state['last_scheduler_summary'] = [
    'checked' => $checkedCount,
    'ran' => $ranCount,
    'skipped' => $skippedCount,
    'errors' => $errorCount,
    'resume_detected' => $schedulerResumeDetected,
    'force_run_flag_detected' => $forceRunFlagDetected,
    'force_run_monitor_flag_detected' => $forceRunMonitorFlagDetected,
    'force_run_revision_flag_detected' => $forceRunRevisionFlagDetected,
    'force_run_all_flag_detected' => $forceRunAllFlagDetected,
];

cms_write_file_atomic($stateFile, cms_json_pretty($state) . "\n");
cms_log($logFile, CMS_SIGNATURE . " DONE ; TASKS checked={$checkedCount} ran={$ranCount} skipped={$skippedCount} errors={$errorCount} token={$runToken}");

cms_out('---');
cms_out("Run complete: " . cms_now_string());
cms_out("Tasks checked: {$checkedCount}");
cms_out("Tasks ran: {$ranCount}");
cms_out("Tasks skipped: {$skippedCount}");
cms_out("Task errors: {$errorCount}");

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
        if (!cms_cli_verbose()) {
            return;
        }
        echo $line . PHP_EOL;
        return;
    }
    echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "<br>\n";
}

function cms_cli_verbose(): bool
{
    if (PHP_SAPI !== 'cli') return false;
    global $argv;
    if (!isset($argv) || !is_array($argv)) return false;
    foreach ($argv as $arg) {
        $arg = (string)$arg;
        if ($arg === '--verbose' || $arg === '-v') {
            return true;
        }
    }
    return false;
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

    // Legacy fields remain for dashboard compatibility, but now represent the last actual task result.
    $state['tasks'][$taskName]['last_status'] = $status;
    $state['tasks'][$taskName]['last_message'] = $message;
    $state['tasks'][$taskName]['last_status_at'] = cms_now_string();

    $state['tasks'][$taskName]['last_actual_status'] = $status;
    $state['tasks'][$taskName]['last_actual_message'] = $message;
    $state['tasks'][$taskName]['last_actual_status_at'] = cms_now_string();
}

function cms_set_task_check_status(array &$state, string $taskName, string $status, string $message): void
{
    if (!isset($state['tasks'][$taskName]) || !is_array($state['tasks'][$taskName])) {
        $state['tasks'][$taskName] = [];
    }

    $state['tasks'][$taskName]['last_check_at'] = cms_now_string();
    $state['tasks'][$taskName]['last_check_status'] = $status;
    $state['tasks'][$taskName]['last_check_message'] = $message;
}

function cms_consume_force_flag(string $logFile, string $flagFile, string $label): void
{
    if (is_file($flagFile)) {
        @unlink($flagFile);
        cms_log($logFile, 'FORCE RUN FLAG consumed and removed: ' . $label . ' path=' . $flagFile);
    }
}

function cms_task_force_flag_detected(
    string $taskName,
    bool $legacyRunNow,
    bool $monitorRunNow,
    bool $revisionRunNow,
    bool $allRunNow
): bool {
    if ($allRunNow) return true;

    if ($taskName === 'race_results_monitor') {
        return $legacyRunNow || $monitorRunNow;
    }

    if ($taskName === 'race_results_revision_monitor') {
        return $revisionRunNow;
    }

    return false;
}

function cms_task_due(string $taskName, array $task, array $state, int $now, bool $schedulerResumeDetected, string $schedulerResumeReason, bool $forceRunFlagDetected, string $baseDir, int $year): array
{
    $type = (string)($task['type'] ?? 'interval');

    if ($type === 'daily_times') {
        return cms_daily_time_task_due($taskName, $task, $state, $now, $forceRunFlagDetected);
    }

    if ($type === 'auto_race_monitor') {
        return cms_auto_race_monitor_task_due($taskName, $task, $state, $now, $forceRunFlagDetected, $baseDir, $year);
    }

    if ($type === 'auto_revision_monitor') {
        return cms_auto_revision_monitor_task_due($taskName, $task, $state, $now, $forceRunFlagDetected, $baseDir, $year);
    }

    return cms_interval_task_due($taskName, $task, $state, $now, $schedulerResumeDetected, $schedulerResumeReason, $forceRunFlagDetected);
}


function cms_parse_ny_datetime($value): int
{
    $s = trim((string)$value);
    if ($s === '') return 0;
    $ts = strtotime($s);
    return $ts === false ? 0 : (int)$ts;
}

function cms_format_ny_datetime_from_ts(int $ts): string
{
    if ($ts <= 0) return '';
    return date('Y-m-d H:i:s', $ts);
}

function cms_auto_rule_interval(array $task, string $key, int $default): int
{
    $rules = isset($task['auto_rules']) && is_array($task['auto_rules']) ? $task['auto_rules'] : [];
    if (array_key_exists($key, $rules)) {
        return (int)$rules[$key];
    }
    return $default;
}

function cms_latest_monitor_run_ts(array $state, array $monitorState, int $year, string $taskName): int
{
    $latest = 0;

    $taskState = isset($state['tasks'][$taskName]) && is_array($state['tasks'][$taskName]) ? $state['tasks'][$taskName] : [];
    foreach (['last_actual_attempt_at', 'last_attempt_at', 'last_actual_completed_at', 'last_completed_at'] as $key) {
        if (!empty($taskState[$key])) {
            $ts = cms_parse_ny_datetime($taskState[$key]);
            if ($ts > $latest) $latest = $ts;
        }
    }

    $yearState = isset($monitorState['byYear'][(string)$year]) && is_array($monitorState['byYear'][(string)$year])
        ? $monitorState['byYear'][(string)$year]
        : [];

    if (!empty($yearState['last_checked_at'])) {
        $ts = cms_parse_ny_datetime($yearState['last_checked_at']);
        if ($ts > $latest) $latest = $ts;
    }

    $raceStatus = isset($yearState['race_status']) && is_array($yearState['race_status'])
        ? $yearState['race_status']
        : (isset($yearState['current_race_status']) && is_array($yearState['current_race_status']) ? $yearState['current_race_status'] : []);

    if (!empty($raceStatus['checked_at'])) {
        $ts = cms_parse_ny_datetime($raceStatus['checked_at']);
        if ($ts > $latest) $latest = $ts;
    }

    return $latest;
}

function cms_monitor_lap_status(array $monitorState, int $year): array
{
    $yearState = isset($monitorState['byYear'][(string)$year]) && is_array($monitorState['byYear'][(string)$year])
        ? $monitorState['byYear'][(string)$year]
        : [];
    $raceStatus = isset($yearState['race_status']) && is_array($yearState['race_status'])
        ? $yearState['race_status']
        : (isset($yearState['current_race_status']) && is_array($yearState['current_race_status']) ? $yearState['current_race_status'] : []);

    return [
        'found' => !empty($raceStatus['lap_status_found']),
        'current' => array_key_exists('lap_current', $raceStatus) ? $raceStatus['lap_current'] : null,
        'total' => array_key_exists('lap_total', $raceStatus) ? $raceStatus['lap_total'] : null,
        'checked_at' => isset($raceStatus['checked_at']) ? (string)$raceStatus['checked_at'] : '',
    ];
}

function cms_auto_race_phase(array $task, int $now, int $startTs, bool $lapFound): array
{
    if ($startTs <= 0) {
        return [
            'phase' => 'no_next_race_start',
            'label' => 'No next race start time',
            'interval_minutes' => 0,
            'reason' => 'No next race start_at/start_ts found in _race_results_schedule.json.',
            'seconds_to_start' => null,
        ];
    }

    $seconds = $startTs - $now;

    if ($seconds > 24 * 3600) {
        return [
            'phase' => 'standby_more_than_24h',
            'label' => 'More than 24h before race',
            'interval_minutes' => cms_auto_rule_interval($task, 'more_than_24h_before_start', 0),
            'reason' => 'Race monitor is not needed more than 24 hours before scheduled start.',
            'seconds_to_start' => $seconds,
        ];
    }

    if ($seconds > 6 * 3600) {
        return [
            'phase' => 'race_day_24h_to_6h',
            'label' => '24h to 6h before start',
            'interval_minutes' => cms_auto_rule_interval($task, '24h_to_6h_before_start', 120),
            'reason' => 'Race is within 24 hours; light monitoring.',
            'seconds_to_start' => $seconds,
        ];
    }

    if ($seconds > 2 * 3600) {
        return [
            'phase' => 'race_day_6h_to_2h',
            'label' => '6h to 2h before start',
            'interval_minutes' => cms_auto_rule_interval($task, '6h_to_2h_before_start', 30),
            'reason' => 'Race is within 6 hours.',
            'seconds_to_start' => $seconds,
        ];
    }

    if ($seconds > 0) {
        return [
            'phase' => 'race_day_2h_to_start',
            'label' => '2h to scheduled start',
            'interval_minutes' => cms_auto_rule_interval($task, '2h_to_start', 15),
            'reason' => 'Race is within 2 hours.',
            'seconds_to_start' => $seconds,
        ];
    }

    if ($lapFound) {
        return [
            'phase' => 'lap_status_active_first_pass',
            'label' => 'Lap status active',
            'interval_minutes' => cms_auto_rule_interval($task, 'lap_found', 5),
            'reason' => 'Lap status has been detected; first pass keeps safe cadence.',
            'seconds_to_start' => $seconds,
        ];
    }

    return [
        'phase' => 'scheduled_start_waiting_for_lap',
        'label' => 'Scheduled start passed; waiting for lap status',
        'interval_minutes' => cms_auto_rule_interval($task, 'start_until_lap_found', 5),
        'reason' => 'Scheduled start has passed but lap status is not detected yet.',
        'seconds_to_start' => $seconds,
    ];
}

function cms_auto_race_monitor_task_due(string $taskName, array $task, array $state, int $now, bool $forceRunFlagDetected, string $baseDir, int $year): array
{
    $schedulePath = rtrim($baseDir, '/\\') . '/_race_results_schedule.json';
    $monitorStatePath = rtrim($baseDir, '/\\') . '/_race_results_monitor_state.json';
    $raceSchedule = cms_load_json($schedulePath);
    $monitorState = cms_load_json($monitorStatePath);

    $nextRace = isset($raceSchedule['next_race']) && is_array($raceSchedule['next_race']) ? $raceSchedule['next_race'] : [];
    if (empty($nextRace)) {
        $yearStateForSchedule = isset($monitorState['byYear'][(string)$year]) && is_array($monitorState['byYear'][(string)$year])
            ? $monitorState['byYear'][(string)$year]
            : [];
        if (isset($yearStateForSchedule['schedule_status']['next_race']) && is_array($yearStateForSchedule['schedule_status']['next_race'])) {
            $nextRace = $yearStateForSchedule['schedule_status']['next_race'];
        }
    }
    $startTs = 0;
    if (!empty($nextRace['start_ts'])) {
        $startTs = (int)$nextRace['start_ts'];
    }
    if ($startTs <= 0 && !empty($nextRace['start_at'])) {
        $startTs = cms_parse_ny_datetime($nextRace['start_at']);
    }

    $lap = cms_monitor_lap_status($monitorState, $year);
    $phase = cms_auto_race_phase($task, $now, $startTs, !empty($lap['found']));
    $interval = (int)$phase['interval_minutes'];
    $lastRunTs = cms_latest_monitor_run_ts($state, $monitorState, $year, $taskName);

    $due = false;
    $reason = '';
    $nextDueTs = 0;

    if ($forceRunFlagDetected) {
        $due = true;
        $reason = 'manual force-run flag';
    } elseif ($interval <= 0) {
        $due = false;
        $reason = 'phase interval is disabled; monitor not due';
    } elseif ($lastRunTs <= 0) {
        $due = true;
        $reason = 'no previous monitor run found';
    } else {
        $nextDueTs = $lastRunTs + ($interval * 60);
        if ($now >= $nextDueTs) {
            $due = true;
            $reason = 'interval ' . (string)$interval . ' minutes elapsed / auto race-aware';
        } else {
            $remaining = max(0, $nextDueTs - $now);
            $due = false;
            $reason = 'not due; ' . (string)((int)ceil($remaining / 60)) . ' minutes remaining';
        }
    }

    if ($nextDueTs <= 0 && $lastRunTs > 0 && $interval > 0) {
        $nextDueTs = $lastRunTs + ($interval * 60);
    }

    $raceLabel = trim((string)($nextRace['mrl_race_code'] ?? '') . ' ' . (string)($nextRace['short_name'] ?? $nextRace['race_name'] ?? ''));

    return [
        'due' => $due,
        'reason' => $reason,
        'interval_minutes' => $interval,
        'auto_schedule' => [
            'mode' => 'auto_race_monitor',
            'generated_at' => cms_now_string(),
            'schedule_source' => '_scheduler/schedule.json',
            'race_schedule_source' => '_race_results_schedule.json',
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
                'due_reason' => $reason,
                'seconds_to_start' => $phase['seconds_to_start'],
                'start_at' => $startTs > 0 ? cms_format_ny_datetime_from_ts($startTs) : '',
                'last_monitor_run_at' => cms_format_ny_datetime_from_ts($lastRunTs),
                'next_due_at' => cms_format_ny_datetime_from_ts($nextDueTs),
                'lap_status_found' => !empty($lap['found']),
                'lap_current' => $lap['current'],
                'lap_total' => $lap['total'],
                'lap_checked_at' => $lap['checked_at'],
            ],
        ],
    ];
}


function cms_latest_revision_run_ts(array $state, string $taskName): int
{
    $latest = 0;
    $taskState = isset($state['tasks'][$taskName]) && is_array($state['tasks'][$taskName]) ? $state['tasks'][$taskName] : [];
    foreach (['last_actual_attempt_at', 'last_attempt_at', 'last_actual_completed_at', 'last_completed_at'] as $key) {
        if (!empty($taskState[$key])) {
            $ts = cms_parse_ny_datetime($taskState[$key]);
            if ($ts > $latest) $latest = $ts;
        }
    }
    return $latest;
}

function cms_monitor_latest_final_info(array $monitorState, int $year): array
{
    $yearState = isset($monitorState['byYear'][(string)$year]) && is_array($monitorState['byYear'][(string)$year])
        ? $monitorState['byYear'][(string)$year]
        : [];

    $raceStatus = isset($yearState['race_status']) && is_array($yearState['race_status']) ? $yearState['race_status'] : [];

    $url = (string)($yearState['final_sent_for_url'] ?? '');
    if ($url === '') $url = (string)($raceStatus['latest_final_race_url'] ?? '');

    $name = (string)($raceStatus['latest_final_race_name'] ?? '');
    if ($name === '') $name = (string)($yearState['current_race_status']['race_name'] ?? '');

    $checkedAt = (string)($yearState['last_checked_at'] ?? '');
    if (!empty($yearState['final_check']) && is_array($yearState['final_check']) && !empty($yearState['final_check']['checked_at'])) {
        $checkedAt = (string)$yearState['final_check']['checked_at'];
    }

    $raceId = '';
    if ($url !== '' && preg_match('/raceId\/(\d+)/', $url, $m)) {
        $raceId = (string)$m[1];
    }

    return [
        'final_key' => $url !== '' ? $url : $raceId,
        'final_url' => $url,
        'race_id' => $raceId,
        'race_name' => $name,
        'checked_at' => $checkedAt,
    ];
}

function cms_auto_revision_rule_interval(array $task, string $key, int $default): int
{
    $rules = isset($task['auto_rules']) && is_array($task['auto_rules']) ? $task['auto_rules'] : [];
    if (array_key_exists($key, $rules)) {
        return (int)$rules[$key];
    }
    return $default;
}

function cms_auto_revision_normal_due(string $taskName, array $task, array $state, int $now, bool $forceRunFlagDetected): array
{
    $normalTimes = isset($task['normal_times']) && is_array($task['normal_times']) ? $task['normal_times'] : [];
    if (empty($normalTimes) && isset($task['times']) && is_array($task['times'])) {
        $normalTimes = $task['times'];
    }
    if (empty($normalTimes)) {
        $normalTimes = ['00:00', '06:00', '12:00', '18:00'];
    }

    $dailyTask = $task;
    $dailyTask['times'] = $normalTimes;
    $due = cms_daily_time_task_due($taskName, $dailyTask, $state, $now, $forceRunFlagDetected);
    $due['interval_minutes'] = null;
    return $due;
}

function cms_auto_revision_monitor_task_due(string $taskName, array $task, array $state, int $now, bool $forceRunFlagDetected, string $baseDir, int $year): array
{
    $monitorStatePath = rtrim($baseDir, '/\\') . '/_race_results_monitor_state.json';
    $monitorState = cms_load_json($monitorStatePath);
    $final = cms_monitor_latest_final_info($monitorState, $year);

    $taskState = isset($state['tasks'][$taskName]) && is_array($state['tasks'][$taskName]) ? $state['tasks'][$taskName] : [];
    $priorSchedule = isset($taskState['revision_schedule']) && is_array($taskState['revision_schedule']) ? $taskState['revision_schedule'] : [];
    $priorHandoff = isset($priorSchedule['handoff']) && is_array($priorSchedule['handoff']) ? $priorSchedule['handoff'] : [];

    $finalKey = (string)($final['final_key'] ?? '');
    $storedKey = (string)($priorHandoff['final_key'] ?? '');
    $storedAnchorAt = (string)($priorHandoff['anchor_at'] ?? '');
    $storedAnchorTs = cms_parse_ny_datetime($storedAnchorAt);

    $handoffStatus = 'none';
    $activePostRace = false;
    $anchorTs = 0;
    $anchorAt = '';

    if ($finalKey === '') {
        $handoffStatus = 'no_final_known';
    } elseif ($storedKey === '') {
        // First run after installing this scheduler mode: record the current final as baseline,
        // but do not treat an older already-known race as a fresh post-race handoff.
        $storedKey = $finalKey;
        $handoffStatus = 'baseline_recorded';
    } elseif ($storedKey !== $finalKey) {
        $handoffStatus = 'new_final_handoff_detected';
        $activePostRace = true;
        $anchorTs = $now;
        $anchorAt = cms_now_string();
    } elseif ($storedAnchorTs > 0) {
        $handoffStatus = 'active_or_completed_handoff';
        $activePostRace = true;
        $anchorTs = $storedAnchorTs;
        $anchorAt = $storedAnchorAt;
    } else {
        $handoffStatus = 'baseline_known';
    }

    $phase = 'normal_daily_times';
    $phaseLabel = 'Normal daily revision schedule';
    $interval = 0;
    $elapsedSeconds = null;
    $usesDaily = true;

    if ($activePostRace && $anchorTs > 0) {
        $elapsedSeconds = max(0, $now - $anchorTs);
        $usesDaily = false;
        if ($elapsedSeconds < 3 * 3600) {
            $phase = 'post_final_0_to_3h';
            $phaseLabel = 'Post-race stabilization: first 3 hours';
            $interval = cms_auto_revision_rule_interval($task, 'post_final_0_to_3h', 5);
        } elseif ($elapsedSeconds < 12 * 3600) {
            $phase = 'post_final_3h_to_12h';
            $phaseLabel = 'Post-race stabilization: 3h to 12h';
            $interval = cms_auto_revision_rule_interval($task, 'post_final_3h_to_12h', 180);
        } elseif ($elapsedSeconds < 48 * 3600) {
            $phase = 'post_final_12h_to_48h';
            $phaseLabel = 'Post-race follow-up: 12h to 48h';
            $interval = cms_auto_revision_rule_interval($task, 'post_final_12h_to_48h', 360);
        } else {
            $phase = 'normal_daily_times_after_post_race';
            $phaseLabel = 'Post-race window complete; normal daily revision schedule';
            $usesDaily = true;
            $interval = 0;
        }
    }

    $due = false;
    $reason = '';
    $nextDueTs = 0;
    $lastRunTs = cms_latest_revision_run_ts($state, $taskName);
    $dailyDueInfo = null;

    if ($forceRunFlagDetected) {
        $due = true;
        $reason = 'manual force-run flag';
    } elseif ($usesDaily) {
        $dailyDueInfo = cms_auto_revision_normal_due($taskName, $task, $state, $now, false);
        $due = !empty($dailyDueInfo['due']);
        $reason = (string)($dailyDueInfo['reason'] ?? 'normal daily schedule');

        $normalTimesForNext = isset($task['normal_times']) && is_array($task['normal_times']) ? $task['normal_times'] : [];
        if (empty($normalTimesForNext) && isset($task['times']) && is_array($task['times'])) {
            $normalTimesForNext = $task['times'];
        }
        if (empty($normalTimesForNext)) {
            $normalTimesForNext = ['00:00', '06:00', '12:00', '18:00'];
        }
        $dailyNextTask = $task;
        $dailyNextTask['times'] = $normalTimesForNext;
        $nextDueTs = cms_next_daily_time_ts($taskName, $dailyNextTask, $state, $now);
    } elseif ($interval <= 0) {
        $due = false;
        $reason = 'phase interval is disabled; revision monitor not due';
    } elseif ($lastRunTs <= 0) {
        $due = true;
        $reason = 'no previous revision monitor run found';
    } else {
        $nextDueTs = $lastRunTs + ($interval * 60);
        if ($now >= $nextDueTs) {
            $due = true;
            $reason = 'interval ' . (string)$interval . ' minutes elapsed / auto post-race revision schedule';
        } else {
            $remaining = max(0, $nextDueTs - $now);
            $due = false;
            $reason = 'not due; ' . (string)((int)ceil($remaining / 60)) . ' minutes remaining';
        }
    }

    if ($usesDaily && is_array($dailyDueInfo) && !empty($dailyDueInfo['daily_key'])) {
        $dailyKey = (string)$dailyDueInfo['daily_key'];
    } else {
        $dailyKey = '';
    }

    if ($nextDueTs <= 0 && !$usesDaily && $lastRunTs > 0 && $interval > 0) {
        $nextDueTs = $lastRunTs + ($interval * 60);
    }

    $handoff = [
        'final_key' => $storedKey !== '' ? $storedKey : $finalKey,
        'current_final_key' => $finalKey,
        'anchor_at' => $anchorAt,
        'status' => $handoffStatus,
        'race_name' => (string)($final['race_name'] ?? ''),
        'race_id' => (string)($final['race_id'] ?? ''),
        'final_url' => (string)($final['final_url'] ?? ''),
        'final_checked_at' => (string)($final['checked_at'] ?? ''),
    ];

    $revisionSchedule = [
        'mode' => 'auto_revision_monitor',
        'generated_at' => cms_now_string(),
        'schedule_source' => '_scheduler/schedule.json',
        'monitor_state_source' => '_race_results_monitor_state.json',
        'handoff' => $handoff,
        'decision' => [
            'phase' => $phase,
            'phase_label' => $phaseLabel,
            'interval_minutes' => $usesDaily ? null : $interval,
            'uses_daily_times' => $usesDaily,
            'daily_times' => isset($task['normal_times']) && is_array($task['normal_times']) ? $task['normal_times'] : (isset($task['times']) && is_array($task['times']) ? $task['times'] : ['00:00', '06:00', '12:00', '18:00']),
            'due' => $due,
            'due_reason' => $reason,
            'last_revision_run_at' => cms_format_ny_datetime_from_ts($lastRunTs),
            'next_due_at' => $nextDueTs > 0 ? cms_format_ny_datetime_from_ts($nextDueTs) : '',
            'elapsed_since_handoff_seconds' => $elapsedSeconds,
        ],
    ];

    $out = [
        'due' => $due,
        'reason' => $reason,
        'interval_minutes' => $usesDaily ? null : $interval,
        'revision_schedule' => $revisionSchedule,
    ];
    if ($dailyKey !== '') {
        $out['daily_key'] = $dailyKey;
    }
    return $out;
}

function cms_interval_task_due(string $taskName, array $task, array $state, int $now, bool $schedulerResumeDetected, string $schedulerResumeReason, bool $forceRunFlagDetected): array
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

    $runOnResume = array_key_exists('run_on_scheduler_resume', $task) ? !empty($task['run_on_scheduler_resume']) : true;
    if ($forceRunFlagDetected && $runOnResume) {
        return [
            'due' => true,
            'reason' => $windowName !== '' ? 'manual run_now flag / window ' . $windowName : 'manual run_now flag',
            'interval_minutes' => $interval,
        ];
    }

    if ($schedulerResumeDetected && $runOnResume) {
        return [
            'due' => true,
            'reason' => $windowName !== '' ? $schedulerResumeReason . ' / window ' . $windowName : $schedulerResumeReason,
            'interval_minutes' => $interval,
        ];
    }

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


function cms_next_daily_time_ts(string $taskName, array $task, array $state, int $now): int
{
    $times = isset($task['times']) && is_array($task['times']) ? $task['times'] : [];
    if (empty($times)) return 0;

    $taskState = isset($state['tasks'][$taskName]) && is_array($state['tasks'][$taskName]) ? $state['tasks'][$taskName] : [];
    $dailyRuns = isset($taskState['daily_runs']) && is_array($taskState['daily_runs']) ? $taskState['daily_runs'] : [];

    $today = date('Y-m-d', $now);
    $tomorrow = date('Y-m-d', $now + 86400);
    $best = 0;

    foreach ([$today, $tomorrow] as $day) {
        foreach ($times as $time) {
            $time = trim((string)$time);
            if (!preg_match('/^\d{2}:\d{2}$/', $time)) continue;

            $candidate = strtotime($day . ' ' . $time . ':00');
            if ($candidate === false) continue;
            if ($candidate <= $now) continue;

            $key = $day . ' ' . $time;
            if (!empty($dailyRuns[$key])) continue;

            if ($best <= 0 || $candidate < $best) {
                $best = $candidate;
            }
        }
    }

    return $best;
}

function cms_daily_time_task_due(string $taskName, array $task, array $state, int $now, bool $forceRunFlagDetected): array
{
    $times = isset($task['times']) && is_array($task['times']) ? $task['times'] : [];
    $currentHm = date('H:i', $now);
    $today = date('Y-m-d', $now);

    if ($forceRunFlagDetected) {
        return ['due' => true, 'reason' => 'manual force-run flag'];
    }

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

function cms_task_run_method(string $taskName, array $task): string
{
    $method = strtolower(trim((string)($task['run_method'] ?? 'auto')));

    if ($method === 'php' || $method === 'exec') {
        return 'php';
    }

    if ($method === 'url' || $method === 'http' || $method === 'https') {
        return 'url';
    }

    if ($taskName === 'race_results_monitor' || $taskName === 'race_results_revision_monitor') {
        return 'url';
    }

    return 'php';
}

function cms_public_base_url(string $baseDir, array $schedule): string
{
    $configured = trim((string)($schedule['public_base_url'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $normalized = str_replace('\\', '/', $baseDir);

    if (preg_match('~/public_html/testphp8/race_results/?$~', $normalized)) {
        return 'https://testphp8.manliusracingleague.com/race_results';
    }

    if (preg_match('~/public_html/race_results/?$~', $normalized)) {
        return 'https://manliusracingleague.com/race_results';
    }

    $host = '';
    if (!empty($_SERVER['HTTP_HOST'])) {
        $host = (string)$_SERVER['HTTP_HOST'];
    }

    if ($host !== '') {
        return 'https://' . $host . '/race_results';
    }

    return 'https://manliusracingleague.com/race_results';
}

function cms_task_url(string $publicBaseUrl, string $script, array $args, string $runToken): string
{
    $url = rtrim($publicBaseUrl, '/') . '/' . rawurlencode($script);

    $query = [];
    if (isset($args[0]) && preg_match('/^\d{4}$/', (string)$args[0])) {
        $query['year'] = (string)$args[0];
    }

    // Cache-busting values make every scheduled URL call unique.
    // This helps prevent LiteSpeed/browser-level cached output from being reused.
    $query['sched_token'] = $runToken;
    $query['sched_ts'] = date('Ymd_His');

    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

function cms_run_url(string $url, int $timeoutSeconds): array
{
    $outputText = '';
    $exitCode = 0;
    $httpCode = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(max($timeoutSeconds, 5), 30));
            curl_setopt($ch, CURLOPT_TIMEOUT, max($timeoutSeconds, 10));
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'MRL Cron Master Scheduler ' . CMS_VERSION);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                $error = (string)curl_error($ch);
                $exitCode = 1;
            } else {
                $outputText = (string)$response;
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode < 200 || $httpCode >= 400) {
                    $exitCode = 1;
                    $error = 'HTTP ' . (string)$httpCode;
                }
            }
            curl_close($ch);
        } else {
            $exitCode = 1;
            $error = 'curl_init failed';
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => max($timeoutSeconds, 10),
                'header' => "User-Agent: MRL Cron Master Scheduler " . CMS_VERSION . "\r\n"
                    . "Cache-Control: no-cache\r\n"
                    . "Pragma: no-cache\r\n",
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $exitCode = 1;
            $error = 'file_get_contents failed';
        } else {
            $outputText = (string)$response;
            $httpCode = 200;
        }
    }

    $plain = trim(strip_tags($outputText));
    $plain = html_entity_decode($plain, ENT_QUOTES, 'UTF-8');
    $tailLines = preg_split('/\R/', $plain);
    if (!is_array($tailLines)) {
        $tailLines = [$plain];
    }
    $tail = implode("\n", array_slice($tailLines, -25));

    if ($error !== '') {
        $tail = trim($tail . "\n" . $error);
    }

    return [
        'command' => $url,
        'url' => $url,
        'http_code' => $httpCode,
        'exit_code' => (int)$exitCode,
        'output_tail' => $tail,
    ];
}

function cms_choose_child_php_binary(string $configured): string
{
    $configured = trim($configured);
    if ($configured !== '' && is_file($configured)) {
        return $configured;
    }

    if (is_file('/usr/bin/php')) {
        return '/usr/bin/php';
    }

    if (PHP_BINARY !== '' && is_file(PHP_BINARY)) {
        return PHP_BINARY;
    }

    return '/usr/bin/php';
}

function cms_run_php_script(string $scriptPath, array $args, int $timeoutSeconds, string $workingDir, string $phpBinary): array
{
    $php = $phpBinary !== '' ? $phpBinary : '/usr/bin/php';
    if (!is_file($php)) {
        $php = '/usr/bin/php';
    }

    $scriptName = basename($scriptPath);
    $cmdParts = ['cd', escapeshellarg($workingDir), '&&', escapeshellarg($php), escapeshellarg($scriptName)];
    foreach ($args as $arg) {
        $cmdParts[] = escapeshellarg((string)$arg);
    }

    $cmd = implode(' ', $cmdParts) . ' 2>&1';
    $output = [];
    $exitCode = 0;

    // timeoutSeconds is stored for future proc_open refinement; exec is sufficient for now.
    @exec($cmd, $output, $exitCode);

    $tail = implode("\n", array_slice($output, -25));

    return [
        'command' => $cmd,
        'working_dir' => $workingDir,
        'exit_code' => (int)$exitCode,
        'output_tail' => $tail,
    ];
}

function cms_verify_task_outputs(string $taskName, array $task, string $baseDir, string $attemptAt): array
{
    $heartbeatFile = (string)($task['verify_heartbeat_file'] ?? '');

    if ($heartbeatFile === '') {
        if ($taskName === 'race_results_monitor') {
            $heartbeatFile = '_race_results_monitor_heartbeat.txt';
        } elseif ($taskName === 'race_results_revision_monitor') {
            $heartbeatFile = '_race_results_revision_monitor_heartbeat.txt';
        }
    }

    if ($heartbeatFile === '') {
        return [
            'ok' => true,
            'required' => false,
            'message' => 'No heartbeat verification configured.',
        ];
    }

    if (strpos($heartbeatFile, '..') !== false || strpos($heartbeatFile, '/') !== false || strpos($heartbeatFile, '\\') !== false) {
        return [
            'ok' => false,
            'required' => true,
            'message' => 'Invalid heartbeat verification path: ' . $heartbeatFile,
        ];
    }

    $path = rtrim($baseDir, '/\\') . '/' . $heartbeatFile;
    if (!is_file($path)) {
        return [
            'ok' => false,
            'required' => true,
            'heartbeat_file' => $heartbeatFile,
            'heartbeat_path' => $path,
            'message' => 'Heartbeat file missing after task run.',
        ];
    }

    $mtime = (int)@filemtime($path);
    $attemptTs = strtotime($attemptAt);
    $attemptTs = $attemptTs === false ? 0 : (int)$attemptTs;

    if ($mtime >= ($attemptTs - 5)) {
        return [
            'ok' => true,
            'required' => true,
            'heartbeat_file' => $heartbeatFile,
            'heartbeat_path' => $path,
            'heartbeat_modified_at' => date('Y-m-d H:i:s', $mtime),
            'message' => 'Heartbeat updated.',
        ];
    }

    // Short interval URL/LiteSpeed runs can return before the filesystem timestamp is visible.
    // Retry once before warning.
    usleep(1500000); // 1.5 seconds
    clearstatcache(true, $path);
    $retryMtime = (int)@filemtime($path);

    if ($retryMtime >= ($attemptTs - 5)) {
        return [
            'ok' => true,
            'required' => true,
            'heartbeat_file' => $heartbeatFile,
            'heartbeat_path' => $path,
            'heartbeat_modified_at' => date('Y-m-d H:i:s', $retryMtime),
            'message' => 'Heartbeat updated after retry.',
            'verification_retry_used' => true,
        ];
    }

    return [
        'ok' => false,
        'required' => true,
        'heartbeat_file' => $heartbeatFile,
        'heartbeat_path' => $path,
        'heartbeat_modified_at' => date('Y-m-d H:i:s', $retryMtime > 0 ? $retryMtime : $mtime),
        'attempt_at' => $attemptAt,
        'message' => 'Heartbeat did not update after task run.',
        'verification_retry_used' => true,
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
