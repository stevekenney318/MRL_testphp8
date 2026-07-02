<?php
/**
 * install_mrl_at_a_glance_v001.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/28/2026 12:56:18 am
 *
 * CHANGELOG:
 * v001 (6/28/2026 12:56:18 am)
 * - Installs mrl_at_a_glance.php JSON endpoint for the bookmarklet/prototype.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

$target = __DIR__ . '/mrl_at_a_glance.php';
$timestamp = date('Ymd_His');
$backup = '';

$content = <<<'MRL_AT_A_GLANCE_PHP'
<?php
/**
 * mrl_at_a_glance.php
 *
 * VERSION: v001
 * LAST MODIFIED: 6/28/2026 12:56:18 am
 *
 * CHANGELOG:
 * v001 (6/28/2026 12:56:18 am)
 * - Initial JSON endpoint for the MRL At a Glance bookmarklet/prototype.
 * - Reads scheduler state files when available.
 * - Produces green/yellow/red summary rows for scheduler, race monitor, revision monitor, next race, revisions, and release history.
 * - Uses tolerant file discovery so it can work with the current scheduler without requiring an immediate scheduler rewrite.
 */

declare(strict_types=1);

date_default_timezone_set('America/New_York');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function mrlg_human_time(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }

    return date('n/j/Y g:i:s a', $ts);
}

function mrlg_age_text(int $seconds): string
{
    if ($seconds < 0) {
        $seconds = 0;
    }
    if ($seconds < 60) {
        return $seconds . ' sec ago';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . ' min ago';
    }
    if ($seconds < 86400) {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        return $h . ' hr' . ($h === 1 ? '' : 's') . ($m > 0 ? ' ' . $m . ' min' : '') . ' ago';
    }
    $d = floor($seconds / 86400);
    return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
}

function mrlg_read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function mrlg_first_existing_file(array $paths): string
{
    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return '';
}

function mrlg_find_state_file(string $schedulerDir): string
{
    $candidates = [
        $schedulerDir . '/state.json',
        $schedulerDir . '/scheduler_state.json',
        $schedulerDir . '/cron_master_scheduler_state.json',
        $schedulerDir . '/cron_master_state.json',
        $schedulerDir . '/status.json',
    ];

    $found = mrlg_first_existing_file($candidates);
    if ($found !== '') {
        return $found;
    }

    $glob = glob($schedulerDir . '/*state*.json');
    if (is_array($glob) && !empty($glob)) {
        usort($glob, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        return $glob[0];
    }

    return '';
}

function mrlg_find_heartbeat_file(string $schedulerDir): string
{
    $candidates = [
        $schedulerDir . '/heartbeat.txt',
        $schedulerDir . '/cron_master_scheduler_heartbeat.txt',
        $schedulerDir . '/scheduler_heartbeat.txt',
    ];

    $found = mrlg_first_existing_file($candidates);
    if ($found !== '') {
        return $found;
    }

    $glob = glob($schedulerDir . '/*heartbeat*.txt');
    if (is_array($glob) && !empty($glob)) {
        usort($glob, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        return $glob[0];
    }

    return '';
}

function mrlg_item(string $key, string $label, string $status, string $text, string $detail = ''): array
{
    return [
        'key' => $key,
        'label' => $label,
        'status' => $status,
        'text' => $text,
        'detail' => $detail,
    ];
}

function mrlg_extract_int_from_tail(string $tail, string $label): ?int
{
    $pattern = '/' . preg_quote($label, '/') . '\s*:\s*(\d+)/i';
    if (preg_match($pattern, $tail, $m)) {
        return (int)$m[1];
    }
    return null;
}

$baseDir = __DIR__;
$schedulerDir = $baseDir . '/_scheduler';
$now = time();

$stateFile = is_dir($schedulerDir) ? mrlg_find_state_file($schedulerDir) : '';
$heartbeatFile = is_dir($schedulerDir) ? mrlg_find_heartbeat_file($schedulerDir) : '';

$stateDoc = $stateFile !== '' ? mrlg_read_json_file($stateFile) : null;
$state = is_array($stateDoc) && isset($stateDoc['state']) && is_array($stateDoc['state']) ? $stateDoc['state'] : (is_array($stateDoc) ? $stateDoc : []);

$tasks = isset($state['tasks']) && is_array($state['tasks']) ? $state['tasks'] : [];
$schedulerEnabled = $stateDoc['scheduler_enabled'] ?? $state['scheduler_enabled'] ?? null;

$items = [];

/* Scheduler */
if ($heartbeatFile !== '') {
    $age = $now - filemtime($heartbeatFile);
    $status = ($age <= 180) ? 'green' : (($age <= 600) ? 'yellow' : 'red');
    $text = 'Heartbeat ' . mrlg_age_text($age);
    if ($schedulerEnabled === false) {
        $status = 'red';
        $text = 'Disabled';
    }
    $items[] = mrlg_item('scheduler', 'Scheduler', $status, $text, basename($heartbeatFile));
} else {
    $items[] = mrlg_item('scheduler', 'Scheduler', 'red', 'Heartbeat not found', 'No scheduler heartbeat file found.');
}

/* Race Monitor */
$raceTask = isset($tasks['race_results_monitor']) && is_array($tasks['race_results_monitor']) ? $tasks['race_results_monitor'] : [];
$raceDecision = $raceTask['auto_schedule']['decision'] ?? [];
$raceNext = $raceTask['auto_schedule']['next_race'] ?? [];
$raceLastStatus = (string)($raceTask['last_status'] ?? '');
$racePhase = (string)($raceDecision['phase_label'] ?? $raceDecision['phase'] ?? '');
$raceDueReason = (string)($raceDecision['due_reason'] ?? '');
$raceNextDue = (string)($raceDecision['next_due_at'] ?? '');

if (!empty($raceTask)) {
    $status = ($raceLastStatus !== '' && strtolower($raceLastStatus) !== 'success') ? 'red' : 'green';
    $text = $racePhase !== '' ? $racePhase : 'State available';
    if ($raceDueReason !== '') {
        $text = preg_replace('/^not due;\s*/i', '', $raceDueReason);
        $text = ucfirst($text);
    }
    if ($raceNextDue !== '') {
        $text .= ' • next ' . date('g:i a', strtotime($raceNextDue));
    }
    $items[] = mrlg_item('race_monitor', 'Race Monitor', $status, $text, (string)($raceTask['last_message'] ?? ''));
} else {
    $items[] = mrlg_item('race_monitor', 'Race Monitor', 'yellow', 'No state found', 'race_results_monitor task not found in scheduler state.');
}

/* Revision Monitor */
$revTask = isset($tasks['race_results_revision_monitor']) && is_array($tasks['race_results_revision_monitor']) ? $tasks['race_results_revision_monitor'] : [];
$revDecision = $revTask['revision_schedule']['decision'] ?? [];
$revLastStatus = (string)($revTask['last_status'] ?? '');
$revPhase = (string)($revDecision['phase_label'] ?? $revDecision['phase'] ?? '');
$revNextDue = (string)($revDecision['next_due_at'] ?? '');

if (!empty($revTask)) {
    $status = ($revLastStatus !== '' && strtolower($revLastStatus) !== 'success') ? 'red' : 'green';
    $text = $revPhase !== '' ? $revPhase : 'State available';
    if ($revNextDue !== '') {
        $text .= ' • next ' . date('g:i a', strtotime($revNextDue));
    }
    $items[] = mrlg_item('revision_monitor', 'Revision Monitor', $status, $text, (string)($revTask['last_message'] ?? ''));
} else {
    $items[] = mrlg_item('revision_monitor', 'Revision Monitor', 'yellow', 'No state found', 'race_results_revision_monitor task not found in scheduler state.');
}

/* Next Race */
if (is_array($raceNext) && !empty($raceNext)) {
    $label = (string)($raceNext['label'] ?? $raceNext['short_name'] ?? 'Next race');
    $startText = (string)($raceNext['start_text'] ?? '');
    $secondsToStart = isset($raceDecision['seconds_to_start']) ? (int)$raceDecision['seconds_to_start'] : null;
    $status = 'green';
    if ($secondsToStart !== null && $secondsToStart <= 21600 && $secondsToStart > 0) {
        $status = 'yellow';
    }
    $text = trim($label . ($startText !== '' ? ' • ' . $startText : ''));
    $items[] = mrlg_item('next_race', 'Next Race', $status, $text, (string)($raceNext['race_name'] ?? ''));
} else {
    $items[] = mrlg_item('next_race', 'Next Race', 'yellow', 'Unknown', 'No next-race object found.');
}

/* Revisions */
$tail = (string)($revTask['last_output_tail'] ?? '');
$revised = mrlg_extract_int_from_tail($tail, 'Revised');
$errors = mrlg_extract_int_from_tail($tail, 'Errors/skips');
$unchanged = mrlg_extract_int_from_tail($tail, 'Unchanged');
if ($revised !== null || $errors !== null) {
    $status = 'green';
    $text = 'None detected';
    if (($errors ?? 0) > 0) {
        $status = 'red';
        $text = 'Errors/skips: ' . (string)$errors;
    } elseif (($revised ?? 0) > 0) {
        $status = 'yellow';
        $text = 'Revised: ' . (string)$revised;
    } elseif ($unchanged !== null) {
        $text = '0 revised • ' . $unchanged . ' unchanged';
    }
    $items[] = mrlg_item('revisions', 'Revisions', $status, $text, 'Last revision run: ' . (string)($revTask['last_completed_at'] ?? ''));
} else {
    $items[] = mrlg_item('revisions', 'Revisions', 'yellow', 'Needs review', 'Could not parse revision counts from last output.');
}

/* Release History / Last Snapshot proxy */
$releaseCount = null;
if (preg_match('/Release-history version metadata:\s*OK\s*\/\s*Releases:\s*(\d+)/i', $tail, $m)) {
    $releaseCount = (int)$m[1];
}
if ($releaseCount !== null) {
    $items[] = mrlg_item('release_history', 'Release History', 'green', $releaseCount . ' releases OK', 'Release-history version metadata refreshed by revision monitor.');
} else {
    $items[] = mrlg_item('release_history', 'Release History', 'yellow', 'Needs review', 'Release count not found in revision monitor tail.');
}

$out = [
    'ok' => true,
    'version' => 'v001',
    'generated_at' => date('Y-m-d H:i:s'),
    'generated_display' => date('n/j/Y g:i:s a'),
    'timezone' => 'America/New_York',
    'source_files' => [
        'state_file' => $stateFile !== '' ? basename($stateFile) : '',
        'heartbeat_file' => $heartbeatFile !== '' ? basename($heartbeatFile) : '',
    ],
    'items' => $items,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

MRL_AT_A_GLANCE_PHP;

echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>MRL At a Glance Installer</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:24px;line-height:1.45}.ok{color:#0a7a2f;font-weight:bold}.warn{color:#9a6500;font-weight:bold}pre{background:#f4f4f4;padding:12px;border:1px solid #ccc;white-space:pre-wrap}</style>";
echo "</head><body>";
echo "<h1>MRL At a Glance Installer</h1>";

if (is_file($target)) {
    $backup = $target . '.bak_' . $timestamp;
    if (!@copy($target, $backup)) {
        echo "<p class=\"warn\">WARNING — could not create backup.</p>";
    }
}

if (@file_put_contents($target, $content) === false) {
    echo "<p class=\"warn\">ERROR — could not write mrl_at_a_glance.php.</p>";
    echo "</body></html>";
    exit;
}

echo "<p class=\"ok\">SUCCESS — mrl_at_a_glance.php was installed.</p>";
echo "<h2>Report</h2><pre>";
if ($backup !== '') {
    echo "Backup created: " . htmlspecialchars(basename($backup), ENT_QUOTES, 'UTF-8') . "\n";
}
echo "Updated: mrl_at_a_glance.php\n";
echo "Endpoint: /race_results/mrl_at_a_glance.php\n";
echo "</pre>";
echo "<p>After a successful test, delete this installer.</p>";
echo "</body></html>";
