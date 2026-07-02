<?php
/**
 * install_mrl_at_a_glance_v002.php
 *
 * VERSION: v002
 * LAST MODIFIED: 6/28/2026 11:26:47 am
 *
 * CHANGELOG:
 * v002 (6/28/2026 11:26:47 am)
 * - Installs mrl_at_a_glance.php v002.
 * - Monitor rows now publish next time, time remaining, and cadence.
 */

declare(strict_types=1);
date_default_timezone_set('America/New_York');

$target = __DIR__ . '/mrl_at_a_glance.php';
$backup = '';

$content = <<<'MRL_ENDPOINT'
<?php
/**
 * mrl_at_a_glance.php
 *
 * VERSION: v002
 * LAST MODIFIED: 6/28/2026 11:26:47 am
 *
 * CHANGELOG:
 * v002 (6/28/2026 11:26:47 am)
 * - Changed Race Monitor and Revision Monitor display to: next time • remaining • cadence.
 * - Changed Next Race color to reflect schedule health instead of race-day urgency.
 * - Added optional URL fields for future launchpad behavior.
 *
 * v001 (6/28/2026 12:56:18 am)
 * - Initial JSON endpoint for the MRL At a Glance bookmarklet/prototype.
 */

declare(strict_types=1);
date_default_timezone_set('America/New_York');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function mrlg_json_file(string $path): ?array {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function mrlg_find_file(string $dir, array $names, string $globPattern): string {
    foreach ($names as $name) {
        $p = rtrim($dir, '/\\') . '/' . $name;
        if (is_file($p)) return $p;
    }
    $glob = glob(rtrim($dir, '/\\') . '/' . $globPattern);
    if (is_array($glob) && !empty($glob)) {
        usort($glob, function($a, $b) { return filemtime($b) <=> filemtime($a); });
        return $glob[0];
    }
    return '';
}

function mrlg_age(int $seconds): string {
    if ($seconds < 0) $seconds = 0;
    if ($seconds < 60) return $seconds . ' sec ago';
    if ($seconds < 3600) return floor($seconds / 60) . ' min ago';
    if ($seconds < 86400) {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        return $h . ' hr' . ($h == 1 ? '' : 's') . ($m > 0 ? ' ' . $m . ' min' : '') . ' ago';
    }
    $d = floor($seconds / 86400);
    return $d . ' day' . ($d == 1 ? '' : 's') . ' ago';
}

function mrlg_remaining(?string $time): string {
    if ($time === null || trim($time) === '') return 'unknown';
    $ts = strtotime($time);
    if ($ts === false) return 'unknown';
    $diff = $ts - time();
    if ($diff <= -60) return 'overdue ' . mrlg_age(abs($diff));
    if ($diff <= 60) return 'now';
    if ($diff < 3600) return ceil($diff / 60) . ' min';
    if ($diff < 86400) {
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        return $h . ' hr' . ($h == 1 ? '' : 's') . ($m > 0 ? ' ' . $m . ' min' : '');
    }
    $d = floor($diff / 86400);
    $h = floor(($diff % 86400) / 3600);
    return $d . ' day' . ($d == 1 ? '' : 's') . ($h > 0 ? ' ' . $h . ' hr' : '');
}

function mrlg_time(?string $time): string {
    if ($time === null || trim($time) === '') return 'unknown';
    $ts = strtotime($time);
    return $ts === false ? $time : date('g:i a', $ts);
}

function mrlg_cadence($minutes, array $decision): string {
    if (is_numeric($minutes) && (int)$minutes > 0) {
        $m = (int)$minutes;
        if ($m < 60) return 'every ' . $m . ' min';
        if ($m % 60 === 0) {
            $h = (int)($m / 60);
            return 'every ' . $h . ' hr' . ($h === 1 ? '' : 's');
        }
        return 'every ' . floor($m / 60) . ' hr ' . ($m % 60) . ' min';
    }
    if (!empty($decision['uses_daily_times'])) return 'daily schedule';
    return 'cadence unknown';
}

function mrlg_item(string $key, string $label, string $status, string $text, string $detail = '', string $url = ''): array {
    return ['key'=>$key, 'label'=>$label, 'status'=>$status, 'text'=>$text, 'detail'=>$detail, 'url'=>$url];
}

function mrlg_count_from_tail(string $tail, string $label): ?int {
    if (preg_match('/' . preg_quote($label, '/') . '\s*:\s*(\d+)/i', $tail, $m)) return (int)$m[1];
    return null;
}

function mrlg_monitor_row(array $task, array $decision): string {
    $next = (string)($decision['next_due_at'] ?? '');
    $interval = $decision['interval_minutes'] ?? $task['last_interval_minutes'] ?? null;
    return mrlg_time($next) . ' • ' . mrlg_remaining($next) . ' • ' . mrlg_cadence($interval, $decision);
}

$base = __DIR__;
$schedulerDir = $base . '/_scheduler';
$public = 'https://manliusracingleague.com/race_results';

$stateFile = is_dir($schedulerDir) ? mrlg_find_file($schedulerDir, ['state.json','scheduler_state.json','cron_master_scheduler_state.json','cron_master_state.json','status.json'], '*state*.json') : '';
$heartbeatFile = is_dir($schedulerDir) ? mrlg_find_file($schedulerDir, ['heartbeat.txt','cron_master_scheduler_heartbeat.txt','scheduler_heartbeat.txt'], '*heartbeat*.txt') : '';

$doc = $stateFile !== '' ? mrlg_json_file($stateFile) : null;
$state = is_array($doc) && isset($doc['state']) && is_array($doc['state']) ? $doc['state'] : (is_array($doc) ? $doc : []);
$tasks = isset($state['tasks']) && is_array($state['tasks']) ? $state['tasks'] : [];

$items = [];

if ($heartbeatFile !== '') {
    $age = time() - filemtime($heartbeatFile);
    $status = ($age <= 180) ? 'green' : (($age <= 600) ? 'yellow' : 'red');
    $items[] = mrlg_item('scheduler', 'Scheduler', $status, 'Heartbeat ' . mrlg_age($age), basename($heartbeatFile), $public . '/_scheduler/');
} else {
    $items[] = mrlg_item('scheduler', 'Scheduler', 'red', 'Heartbeat not found', 'No scheduler heartbeat file found.', $public . '/_scheduler/');
}

$raceTask = isset($tasks['race_results_monitor']) && is_array($tasks['race_results_monitor']) ? $tasks['race_results_monitor'] : [];
$raceDecision = isset($raceTask['auto_schedule']['decision']) && is_array($raceTask['auto_schedule']['decision']) ? $raceTask['auto_schedule']['decision'] : [];
$raceNext = isset($raceTask['auto_schedule']['next_race']) && is_array($raceTask['auto_schedule']['next_race']) ? $raceTask['auto_schedule']['next_race'] : [];

if (!empty($raceTask)) {
    $status = (strtolower((string)($raceTask['last_status'] ?? 'success')) === 'success') ? 'green' : 'red';
    $text = mrlg_monitor_row($raceTask, $raceDecision);
    if (strpos($text, 'overdue') !== false && $status === 'green') $status = 'yellow';
    $items[] = mrlg_item('race_monitor', 'Race Monitor', $status, $text, (string)($raceTask['last_message'] ?? ''), $public . '/race_results_monitor.php');
} else {
    $items[] = mrlg_item('race_monitor', 'Race Monitor', 'yellow', 'No state found', '', $public . '/race_results_monitor.php');
}

$revTask = isset($tasks['race_results_revision_monitor']) && is_array($tasks['race_results_revision_monitor']) ? $tasks['race_results_revision_monitor'] : [];
$revDecision = isset($revTask['revision_schedule']['decision']) && is_array($revTask['revision_schedule']['decision']) ? $revTask['revision_schedule']['decision'] : [];

if (!empty($revTask)) {
    $status = (strtolower((string)($revTask['last_status'] ?? 'success')) === 'success') ? 'green' : 'red';
    $text = mrlg_monitor_row($revTask, $revDecision);
    if (strpos($text, 'overdue') !== false && $status === 'green') $status = 'yellow';
    $items[] = mrlg_item('revision_monitor', 'Revision Monitor', $status, $text, (string)($revTask['last_message'] ?? ''), $public . '/race_results_revision_monitor.php');
} else {
    $items[] = mrlg_item('revision_monitor', 'Revision Monitor', 'yellow', 'No state found', '', $public . '/race_results_revision_monitor.php');
}

if (!empty($raceNext)) {
    $label = (string)($raceNext['label'] ?? $raceNext['short_name'] ?? 'Next race');
    $start = (string)($raceNext['start_text'] ?? '');
    $items[] = mrlg_item('next_race', 'Next Race', 'green', trim($label . ($start !== '' ? ' • ' . $start : '')), (string)($raceNext['race_name'] ?? ''), $public . '/weekly_standings.php');
} else {
    $items[] = mrlg_item('next_race', 'Next Race', 'yellow', 'Unknown', 'No next race found.', $public . '/weekly_standings.php');
}

$tail = (string)($revTask['last_output_tail'] ?? '');
$revised = mrlg_count_from_tail($tail, 'Revised');
$errors = mrlg_count_from_tail($tail, 'Errors/skips');
$unchanged = mrlg_count_from_tail($tail, 'Unchanged');

if ($revised !== null || $errors !== null) {
    if (($errors ?? 0) > 0) {
        $items[] = mrlg_item('revisions', 'Revisions', 'red', 'Errors/skips: ' . $errors, '', $public . '/standings_timeline.php');
    } elseif (($revised ?? 0) > 0) {
        $items[] = mrlg_item('revisions', 'Revisions', 'yellow', 'Revised: ' . $revised, '', $public . '/standings_timeline.php');
    } else {
        $items[] = mrlg_item('revisions', 'Revisions', 'green', '0 revised • ' . (string)$unchanged . ' unchanged', '', $public . '/standings_timeline.php');
    }
} else {
    $items[] = mrlg_item('revisions', 'Revisions', 'yellow', 'Needs review', 'Could not parse revision counts.', $public . '/standings_timeline.php');
}

if (preg_match('/Release-history version metadata:\s*OK\s*\/\s*Releases:\s*(\d+)/i', $tail, $m)) {
    $items[] = mrlg_item('release_history', 'Release History', 'green', (int)$m[1] . ' releases OK', '', $public . '/weekly_standings.php');
} else {
    $items[] = mrlg_item('release_history', 'Release History', 'yellow', 'Needs review', 'Release count not found.', $public . '/weekly_standings.php');
}

echo json_encode([
    'ok' => true,
    'version' => 'v002',
    'generated_at' => date('Y-m-d H:i:s'),
    'generated_display' => date('n/j/Y g:i:s a'),
    'timezone' => 'America/New_York',
    'items' => $items,
    'source_files' => [
        'state_file' => $stateFile !== '' ? basename($stateFile) : '',
        'heartbeat_file' => $heartbeatFile !== '' ? basename($heartbeatFile) : ''
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

MRL_ENDPOINT;

echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>MRL At a Glance Installer</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:24px;line-height:1.45}.ok{color:#0a7a2f;font-weight:bold}pre{background:#f4f4f4;padding:12px;border:1px solid #ccc;white-space:pre-wrap}</style>";
echo "</head><body><h1>MRL At a Glance Installer</h1>";

if (is_file($target)) {
    $backup = $target . '.bak_' . date('Ymd_His');
    @copy($target, $backup);
}

if (@file_put_contents($target, $content) === false) {
    echo "<p>ERROR — could not write mrl_at_a_glance.php.</p></body></html>";
    exit;
}

echo "<p class=\"ok\">SUCCESS — mrl_at_a_glance.php v002 was installed.</p>";
echo "<h2>Report</h2><pre>";
if ($backup !== '') echo "Backup created: " . htmlspecialchars(basename($backup), ENT_QUOTES, 'UTF-8') . "\n";
echo "Updated: mrl_at_a_glance.php\n";
echo "Change: monitor rows now use next time • remaining • cadence\n";
echo "</pre><p>After a successful test, delete this installer.</p></body></html>";
