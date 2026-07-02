<?php
/**
 * mrl_at_a_glance.php
 *
 * VERSION: v004
 * LAST MODIFIED: 6/28/2026 12:33:29 pm
 *
 * CHANGELOG:
 * v004 (6/28/2026 12:33:29 pm)
 * - Updated Latest Snapshot to use the same snapshot-label model as standings_timeline.php:
 *   race label + version label + timestamp.
 * - Uses _year_index.json and available release/audit JSON when present.
 * - Keeps folder scan only as a fallback.
 *
 * v003
 * - Added Latest Snapshot row.
 *
 * v002
 * - Changed Race Monitor and Revision Monitor display to: next time • remaining • cadence.
 * - Changed Next Race color to reflect schedule health instead of race-day urgency.
 * - Added optional URL fields for future launchpad behavior.
 *
 * v001
 * - Initial JSON endpoint for the MRL At a Glance bookmarklet/prototype.
 */

declare(strict_types=1);
date_default_timezone_set('America/New_York');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function mrlg_json_file($path) {
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function mrlg_find_file($dir, $names, $globPattern) {
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

function mrlg_age($seconds) {
    $seconds = max(0, (int)$seconds);
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

function mrlg_remaining($time) {
    if ($time === null || trim((string)$time) === '') return 'unknown';
    $ts = strtotime((string)$time);
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

function mrlg_time($time) {
    if ($time === null || trim((string)$time) === '') return 'unknown';
    $ts = strtotime((string)$time);
    return $ts === false ? (string)$time : date('g:i a', $ts);
}

function mrlg_snapshot_display($snapshotKey) {
    if (!preg_match('/^(\d{8})_(\d{6})/', (string)$snapshotKey, $m)) return (string)$snapshotKey;
    $dt = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], new DateTimeZone('America/New_York'));
    return $dt instanceof DateTime ? $dt->format('n/j/Y g:i:s a') : (string)$snapshotKey;
}

function mrlg_cadence($minutes, $decision) {
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

function mrlg_item($key, $label, $status, $text, $detail = '', $url = '') {
    return array('key'=>$key, 'label'=>$label, 'status'=>$status, 'text'=>$text, 'detail'=>$detail, 'url'=>$url);
}

function mrlg_count_from_tail($tail, $label) {
    if (preg_match('/' . preg_quote($label, '/') . '\s*:\s*(\d+)/i', (string)$tail, $m)) return (int)$m[1];
    return null;
}

function mrlg_monitor_row($task, $decision) {
    $next = (string)($decision['next_due_at'] ?? '');
    $interval = $decision['interval_minutes'] ?? $task['last_interval_minutes'] ?? null;
    return mrlg_time($next) . ' • ' . mrlg_remaining($next) . ' • ' . mrlg_cadence($interval, $decision);
}

function mrlg_short_race_label($raceName) {
    $raceName = trim((string)$raceName);
    if ($raceName === '') return '';
    $name = preg_replace('/^NASCAR\s+Cup\s+Series\s+at\s+/i', '', $raceName);
    $name = preg_replace('/^NASCAR\s+Cup\s+Series\s+/i', '', (string)$name);
    $name = trim((string)$name);
    $map = array(
        'Circuit of the Americas' => 'COTA',
        'World Wide Technology Raceway' => 'World Wide Tech',
        'Indianapolis Road Course' => 'Indianapolis RC',
    );
    return $map[$name] ?? $name;
}

function mrlg_snapshot_key_from_file($path) {
    $base = basename($path);
    if (!preg_match('/^snapshot_(\d{8}_\d{6}\d*)\.html$/', $base, $m)) return '';
    return (string)$m[1];
}

function mrlg_races_from_year_index($yearFolder) {
    $idx = mrlg_json_file(rtrim($yearFolder, '/\\') . '/_year_index.json');
    if (!is_array($idx)) return array();
    $rows = array();
    $races = isset($idx['races']) && is_array($idx['races']) ? $idx['races'] : array();
    foreach ($races as $raceId => $row) {
        if (!is_array($row)) continue;
        if ((string)($row['kind'] ?? '') !== 'R') continue;
        $number = (int)($row['number'] ?? 0);
        $folder = (string)($row['folder'] ?? '');
        if ($number <= 0 || $folder === '') continue;
        $rows[] = array(
            'number' => $number,
            'race_code' => 'R' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'race_label' => mrlg_short_race_label((string)($row['race_name'] ?? '')),
            'folder' => rtrim($yearFolder, '/\\') . '/' . $folder,
        );
    }
    usort($rows, function($a, $b) { return ((int)$a['number']) <=> ((int)$b['number']); });
    return $rows;
}

function mrlg_collect_snapshots($races) {
    $items = array();
    foreach ($races as $race) {
        $folder = (string)($race['folder'] ?? '');
        if ($folder === '' || !is_dir($folder)) continue;
        $files = glob(rtrim($folder, '/\\') . '/snapshot_*.html');
        if (!is_array($files)) continue;
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $key = mrlg_snapshot_key_from_file($file);
            if ($key === '') continue;
            $raceCode = (string)($race['race_code'] ?? '');
            $items[] = array(
                'value' => $key . '_' . $raceCode,
                'snapshot_key' => $key,
                'race_code' => $raceCode,
                'race_number' => (int)($race['number'] ?? 0),
                'race_label' => (string)($race['race_label'] ?? ''),
                'display_time' => mrlg_snapshot_display($key),
                'file' => (string)$file,
            );
        }
    }
    usort($items, function($a, $b) {
        $cmp = strcmp((string)$b['snapshot_key'], (string)$a['snapshot_key']);
        if ($cmp !== 0) return $cmp;
        return ((int)$b['race_number']) <=> ((int)$a['race_number']);
    });
    return $items;
}

function mrlg_recursive_release_records($node) {
    $records = array();
    if (!is_array($node)) return $records;
    $has = false;
    foreach (array('release_id','releaseID','releaseId','id') as $key) {
        if (isset($node[$key]) && is_scalar($node[$key]) && preg_match('/^\d{8}_\d{6}\d*_R\d{2}$/', (string)$node[$key])) {
            $has = true;
            break;
        }
    }
    if ($has) $records[] = $node;
    foreach ($node as $child) {
        if (is_array($child)) $records = array_merge($records, mrlg_recursive_release_records($child));
    }
    return $records;
}

function mrlg_audit_context_by_release_id($yearFolder) {
    $paths = array(
        rtrim($yearFolder, '/\\') . '/_weekly_standings_release_history.json',
        rtrim($yearFolder, '/\\') . '/weekly_standings_release_history.json',
        rtrim($yearFolder, '/\\') . '/_weekly_standings_audit_index.json',
        rtrim($yearFolder, '/\\') . '/weekly_standings_audit_index.json',
        dirname(rtrim($yearFolder, '/\\')) . '/_weekly_standings_audit_index.json',
    );
    $byId = array();
    foreach ($paths as $path) {
        $data = mrlg_json_file($path);
        if (!is_array($data)) continue;
        foreach (mrlg_recursive_release_records($data) as $record) {
            $releaseId = '';
            foreach (array('release_id','releaseID','releaseId','id') as $key) {
                $candidate = isset($record[$key]) && is_scalar($record[$key]) ? (string)$record[$key] : '';
                if (preg_match('/^\d{8}_\d{6}\d*_R\d{2}$/', $candidate)) {
                    $releaseId = $candidate;
                    break;
                }
            }
            if ($releaseId === '') continue;
            $byId[$releaseId] = array(
                'change' => (string)($record['change_status_label'] ?? $record['changeStatusLabel'] ?? $record['change_label'] ?? $record['changeLabel'] ?? $record['change'] ?? ''),
            );
        }
    }
    return $byId;
}

function mrlg_enrich_snapshots($items, $audit) {
    $ascending = $items;
    usort($ascending, function($a, $b) {
        $cmp = ((int)($a['race_number'] ?? 0)) <=> ((int)($b['race_number'] ?? 0));
        if ($cmp !== 0) return $cmp;
        return strcmp((string)($a['snapshot_key'] ?? ''), (string)($b['snapshot_key'] ?? ''));
    });
    $counts = array();
    $versionByValue = array();
    foreach ($ascending as $item) {
        $raceCode = (string)($item['race_code'] ?? '');
        $value = (string)($item['value'] ?? '');
        if ($raceCode === '' || $value === '') continue;
        if (!isset($counts[$raceCode])) $counts[$raceCode] = 0;
        $counts[$raceCode]++;
        $versionByValue[$value] = $counts[$raceCode];
    }
    foreach ($items as $idx => $item) {
        $value = (string)($item['value'] ?? '');
        $versionNumber = (int)($versionByValue[$value] ?? 0);
        $items[$idx]['version_label'] = $versionNumber > 0 ? ('v' . $versionNumber) : '';
        $items[$idx]['audit'] = isset($audit[$value]) && is_array($audit[$value]) ? $audit[$value] : array();
    }
    return $items;
}

function mrlg_latest_snapshot_label($base, $year) {
    $yearFolder = rtrim($base, '/\\') . '/' . (int)$year;
    $races = mrlg_races_from_year_index($yearFolder);
    $items = mrlg_collect_snapshots($races);
    if (empty($items)) return null;
    $items = mrlg_enrich_snapshots($items, mrlg_audit_context_by_release_id($yearFolder));
    $item = $items[0];
    $race = trim((string)($item['race_code'] ?? '') . ' ' . (string)($item['race_label'] ?? ''));
    $version = (string)($item['version_label'] ?? '');
    $main = trim($race . ($version !== '' ? ' ' . $version : ''));
    $time = (string)($item['display_time'] ?? '');
    return array(
        'text' => $main . ($time !== '' ? ' • ' . $time : ''),
        'detail' => 'Release ID: ' . (string)($item['value'] ?? ''),
    );
}

$base = __DIR__;
$schedulerDir = $base . '/_scheduler';
$public = 'https://manliusracingleague.com/race_results';

$stateFile = is_dir($schedulerDir) ? mrlg_find_file($schedulerDir, array('state.json','scheduler_state.json','cron_master_scheduler_state.json','cron_master_state.json','status.json'), '*state*.json') : '';
$heartbeatFile = is_dir($schedulerDir) ? mrlg_find_file($schedulerDir, array('heartbeat.txt','cron_master_scheduler_heartbeat.txt','scheduler_heartbeat.txt'), '*heartbeat*.txt') : '';

$doc = $stateFile !== '' ? mrlg_json_file($stateFile) : null;
$state = is_array($doc) && isset($doc['state']) && is_array($doc['state']) ? $doc['state'] : (is_array($doc) ? $doc : array());
$tasks = isset($state['tasks']) && is_array($state['tasks']) ? $state['tasks'] : array();

$items = array();

if ($heartbeatFile !== '') {
    $age = time() - filemtime($heartbeatFile);
    $status = ($age <= 180) ? 'green' : (($age <= 600) ? 'yellow' : 'red');
    $items[] = mrlg_item('scheduler', 'Scheduler', $status, 'Heartbeat ' . mrlg_age($age), basename($heartbeatFile), $public . '/_scheduler/');
} else {
    $items[] = mrlg_item('scheduler', 'Scheduler', 'red', 'Heartbeat not found', 'No scheduler heartbeat file found.', $public . '/_scheduler/');
}

$raceTask = isset($tasks['race_results_monitor']) && is_array($tasks['race_results_monitor']) ? $tasks['race_results_monitor'] : array();
$raceDecision = isset($raceTask['auto_schedule']['decision']) && is_array($raceTask['auto_schedule']['decision']) ? $raceTask['auto_schedule']['decision'] : array();
$raceNext = isset($raceTask['auto_schedule']['next_race']) && is_array($raceTask['auto_schedule']['next_race']) ? $raceTask['auto_schedule']['next_race'] : array();

if (!empty($raceTask)) {
    $status = (strtolower((string)($raceTask['last_status'] ?? 'success')) === 'success') ? 'green' : 'red';
    $text = mrlg_monitor_row($raceTask, $raceDecision);
    if (strpos($text, 'overdue') !== false && $status === 'green') $status = 'yellow';
    $items[] = mrlg_item('race_monitor', 'Race Monitor', $status, $text, (string)($raceTask['last_message'] ?? ''), $public . '/race_results_monitor.php');
} else {
    $items[] = mrlg_item('race_monitor', 'Race Monitor', 'yellow', 'No state found', '', $public . '/race_results_monitor.php');
}

$revTask = isset($tasks['race_results_revision_monitor']) && is_array($tasks['race_results_revision_monitor']) ? $tasks['race_results_revision_monitor'] : array();
$revDecision = isset($revTask['revision_schedule']['decision']) && is_array($revTask['revision_schedule']['decision']) ? $revTask['revision_schedule']['decision'] : array();

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

$currentYear = isset($raceNext['year']) && is_numeric($raceNext['year']) ? (int)$raceNext['year'] : (int)date('Y');
$latest = mrlg_latest_snapshot_label($base, $currentYear);
if (is_array($latest)) {
    $items[] = mrlg_item('latest_snapshot', 'Latest Snapshot', 'green', (string)$latest['text'], (string)$latest['detail'], $public . '/weekly_standings.php');
} else {
    $items[] = mrlg_item('latest_snapshot', 'Latest Snapshot', 'yellow', 'Needs review', 'Could not determine latest snapshot from timeline-style data.', $public . '/weekly_standings.php');
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

echo json_encode(array(
    'ok' => true,
    'version' => 'v004',
    'generated_at' => date('Y-m-d H:i:s'),
    'generated_display' => date('n/j/Y g:i:s a'),
    'timezone' => 'America/New_York',
    'items' => $items,
    'source_files' => array(
        'state_file' => $stateFile !== '' ? basename($stateFile) : '',
        'heartbeat_file' => $heartbeatFile !== '' ? basename($heartbeatFile) : ''
    )
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
