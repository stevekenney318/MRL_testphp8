<?php
declare(strict_types=1);

/**
 * race_finish_confirmation_dashboard.php
 *
 * VERSION: v003
 * LAST MODIFIED: 7/26/2026 7:16:39 am
 *
 * DESCRIPTION:
 * Live read-only dashboard for race_finish_confirmation_monitor.php.
 *
 * PHP: 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const RFCD_VERSION = 'v003';

$baseDir = __DIR__;
$dataDir = $baseDir . '/_race_finish_confirmation';
$latestFile = $dataDir . '/latest.json';
$stateFile = $dataDir . '/state.json';
$historyDir = $dataDir . '/history';
$configFile = $dataDir . '/config.json';

$latest = rfcd_json($latestFile);
$state = rfcd_json($stateFile);
$config = rfcd_json($configFile);
$history = rfcd_history($historyDir, 100);

if (isset($_GET['download']) && is_string($_GET['download'])) {
    $requested = basename($_GET['download']);
    $path = $historyDir . '/' . $requested;
    if (is_file($path) && preg_match('/^observation_[A-Za-z0-9_]+\.json$/', $requested)) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $requested . '"');
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit('Not found');
}

$race = isset($latest['race']) && is_array($latest['race']) ? $latest['race'] : [];
$sources = isset($latest['sources']) && is_array($latest['sources']) ? $latest['sources'] : [];
$progress = (float)($race['progress_percent'] ?? 0);
$watch = !empty($latest['finish_watch_active']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="30">
<title>MRL Race Finish Confirmation Monitor</title>
<style>
:root{color-scheme:dark;--bg:#101318;--panel:#1a2028;--panel2:#222a34;--text:#edf2f7;--muted:#aab4c0;--border:#364150;--good:#56d364;--warn:#e3b341;--bad:#f85149;--accent:#58a6ff}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:1500px;margin:0 auto;padding:20px}
h1{font-size:28px;margin:0 0 6px}.sub{color:var(--muted);margin-bottom:18px}
.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:15px}
.label{color:var(--muted);font-size:13px;text-transform:uppercase;letter-spacing:.06em}.value{font-size:25px;font-weight:700;margin-top:5px}.small{font-size:13px;color:var(--muted);margin-top:5px;word-break:break-word}
.good{color:var(--good)}.warn{color:var(--warn)}.bad{color:var(--bad)}.accent{color:var(--accent)}
.progress{height:13px;border-radius:8px;background:#0b0d10;border:1px solid var(--border);overflow:hidden;margin-top:10px}.progress>div{height:100%;background:var(--accent);width:<?= htmlspecialchars((string)min(100,max(0,$progress)),ENT_QUOTES) ?>%}
.sources{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:14px}
.source-title{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}.source-title strong{font-size:18px}
.badge{border:1px solid var(--border);border-radius:999px;padding:4px 9px;font-size:12px;background:var(--panel2)}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--border);font-size:14px}
th,td{padding:9px 10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}th{position:sticky;top:0;background:var(--panel2)}
a{color:var(--accent)}.section-title{margin:22px 0 9px;font-size:20px}.notice{border-left:4px solid var(--accent);padding:12px 14px;background:var(--panel);margin-bottom:14px}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}.button{display:inline-block;background:var(--panel2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;text-decoration:none;color:var(--text)}
@media(max-width:900px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.sources{grid-template-columns:1fr}}
@media(max-width:520px){.grid{grid-template-columns:1fr}.wrap{padding:12px}h1{font-size:23px}}
</style>
</head>
<body>
<div class="wrap">
<h1>Race Finish Confirmation Monitor</h1>
<div class="sub">Read-only observation dashboard · Existing MRL lap/status data · <?= htmlspecialchars(RFCD_VERSION) ?> · Auto-refreshes every 30 seconds</div>

<div class="notice"><strong>No MRL decisions are made here.</strong> This monitor does not influence scoring, final detection, snapshots, revision monitoring, email, or standings. It is launched by the existing master scheduler and reads existing MRL lap/status JSON.</div>

<div class="actions">
<a class="button" href="race_finish_confirmation_monitor.php?force=1" target="_blank">Run observation now</a>
<a class="button" href="race_finish_confirmation_dashboard.php">Refresh dashboard</a>
</div>

<div class="grid">
<div class="card"><div class="label">Race</div><div class="value"><?= rfcd_h((string)($race['race_name'] ?? 'No observation yet')) ?></div><div class="small"><?= rfcd_h((string)($race['track_name'] ?? '')) ?></div></div>
<div class="card"><div class="label">Lap progress</div><div class="value"><?= rfcd_h((string)($race['lap_number'] ?? 0)) ?>/<?= rfcd_h((string)($race['laps_in_race'] ?? 0)) ?> · <?= number_format($progress,2) ?>%</div><div class="progress"><div></div></div></div>
<div class="card"><div class="label">NASCAR flag</div><div class="value <?= rfcd_status_class((string)($race['flag_label'] ?? 'UNKNOWN')) ?>"><?= rfcd_h((string)($race['flag_label'] ?? 'UNKNOWN')) ?></div><div class="small">MRL <?= rfcd_h((string)($race['mrl_race_code'] ?? '')) ?> · Race ID <?= rfcd_h((string)($race['race_id'] ?? 0)) ?></div></div>
<div class="card"><div class="label">Finish watch</div><div class="value <?= $watch ? 'good' : 'warn' ?>"><?= $watch ? 'ACTIVE' : 'WAITING' ?></div><div class="small">Starts at <?= rfcd_h((string)($latest['finish_watch_start_percent'] ?? 90)) ?>% · Next <?= rfcd_h((string)($latest['next_run_at'] ?? $state['next_run_at'] ?? 'Unknown')) ?></div></div>
</div>

<div class="sources">
<?php foreach (['nascar'=>'NASCAR Live JSON','racing_reference'=>'Racing-Reference','jayski'=>'Jayski'] as $key=>$label):
$s = isset($sources[$key]) && is_array($sources[$key]) ? $sources[$key] : [];
?>
<div class="card">
<div class="source-title"><strong><?= rfcd_h($label) ?></strong><span class="badge <?= rfcd_status_class((string)($s['status'] ?? 'unknown')) ?>"><?= rfcd_h((string)($s['status'] ?? 'unknown')) ?></span></div>
<div><?= rfcd_h((string)($s['message'] ?? 'No data yet.')) ?></div>
<div class="small">Checked: <?= rfcd_h((string)($s['checked_at'] ?? 'Never')) ?></div>
<?php if (isset($s['http_code'])): ?><div class="small">HTTP: <?= rfcd_h((string)$s['http_code']) ?> · Bytes: <?= rfcd_h((string)($s['body_bytes'] ?? 0)) ?></div><?php endif; ?>
<?php if (!empty($s['title'])): ?><div class="small">Title: <?= rfcd_h((string)$s['title']) ?></div><?php endif; ?>
<?php if (!empty($s['matched_tokens'])): ?><div class="small">Matched: <?= rfcd_h(implode(', ',(array)$s['matched_tokens'])) ?></div><?php endif; ?>
<?php if (!empty($s['completion_terms_found'])): ?><div class="small">Result terms: <?= rfcd_h(implode(', ',(array)$s['completion_terms_found'])) ?></div><?php endif; ?>
<?php if ($key === 'racing_reference'): ?>
<div class="small">Race page: <?= !empty($s['race_results_posted']) ? 'RESULTS POSTED' : (!empty($s['waiting_phrase_present']) ? 'WAITING PHRASE PRESENT' : 'UNCLASSIFIED') ?></div>
<div class="small">Driver rows: <?= rfcd_h((string)($s['driver_result_rows'] ?? 0)) ?> · Season row: <?= !empty($s['season_row_completed']) ? 'COMPLETED' : 'WAITING/UNKNOWN' ?></div>
<?php if (!empty($s['season_url'])): ?><div class="small"><a href="<?= rfcd_h((string)$s['season_url']) ?>" target="_blank" rel="noopener">Open season page</a></div><?php endif; ?>
<?php if (!empty($s['season_raw_file'])): ?><div class="small"><a href="_race_finish_confirmation/<?= rfcd_h((string)$s['season_raw_file']) ?>" target="_blank">Open saved season HTML</a></div><?php endif; ?>
<?php endif; ?>
<?php if ($key === 'jayski'): ?>
<div class="small">Winner: <?= rfcd_h((string)($s['winner'] ?? 'Not populated')) ?></div>
<div class="small">Results link: <?= !empty($s['results_link_found']) ? 'FOUND' : 'NOT FOUND' ?> · Results page: <?= !empty($s['results_page_posted']) ? 'POSTED' : 'WAITING/UNKNOWN' ?></div>
<?php if (!empty($s['results_url'])): ?><div class="small"><a href="<?= rfcd_h((string)$s['results_url']) ?>" target="_blank" rel="noopener">Open linked results page</a></div><?php endif; ?>
<?php if (!empty($s['results_raw_file'])): ?><div class="small"><a href="_race_finish_confirmation/<?= rfcd_h((string)$s['results_raw_file']) ?>" target="_blank">Open saved results HTML</a></div><?php endif; ?>
<?php endif; ?>
<?php if (!empty($s['source_url'])): ?><div class="small"><a href="<?= rfcd_h((string)$s['source_url']) ?>" target="_blank" rel="noopener">Open source</a></div><?php endif; ?>
<?php if (!empty($s['raw_file'])): ?><div class="small"><a href="_race_finish_confirmation/<?= rfcd_h((string)$s['raw_file']) ?>" target="_blank">Open saved raw page</a></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<h2 class="section-title">Saved observation history</h2>
<table>
<thead><tr><th>Checked</th><th>Race</th><th>Lap</th><th>Flag</th><th>Watch</th><th>Racing-Reference</th><th>Jayski</th><th>File</th></tr></thead>
<tbody>
<?php if (empty($history)): ?>
<tr><td colspan="8">No observations have been saved yet.</td></tr>
<?php else: foreach ($history as $row):
$r = isset($row['data']['race']) && is_array($row['data']['race']) ? $row['data']['race'] : [];
$ss = isset($row['data']['sources']) && is_array($row['data']['sources']) ? $row['data']['sources'] : [];
?>
<tr>
<td><?= rfcd_h((string)($row['data']['checked_at'] ?? '')) ?></td>
<td><?= rfcd_h((string)($r['race_name'] ?? '')) ?></td>
<td><?= rfcd_h((string)($r['lap_number'] ?? 0)) ?>/<?= rfcd_h((string)($r['laps_in_race'] ?? 0)) ?> (<?= number_format((float)($r['progress_percent'] ?? 0),1) ?>%)</td>
<td class="<?= rfcd_status_class((string)($r['flag_label'] ?? 'UNKNOWN')) ?>"><?= rfcd_h((string)($r['flag_label'] ?? 'UNKNOWN')) ?></td>
<td><?= !empty($row['data']['finish_watch_active']) ? 'ACTIVE' : 'WAITING' ?></td>
<td><?= rfcd_h((string)($ss['racing_reference']['status'] ?? '')) ?></td>
<td><?= rfcd_h((string)($ss['jayski']['status'] ?? '')) ?></td>
<td><a href="?download=<?= rawurlencode($row['file']) ?>">JSON</a></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>

<h2 class="section-title">Current configuration</h2>
<div class="card"><pre style="white-space:pre-wrap;margin:0"><?= rfcd_h(json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre></div>
</div>
</body>
</html>
<?php
function rfcd_json(string $path): array
{
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if (!is_string($raw)) return [];
    $data = json_decode($raw,true);
    return is_array($data) ? $data : [];
}
function rfcd_history(string $dir,int $limit): array
{
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/observation_*.json') ?: [];
    rsort($files,SORT_STRING);
    $rows = [];
    foreach (array_slice($files,0,$limit) as $path) {
        $rows[] = ['file'=>basename($path),'data'=>rfcd_json($path)];
    }
    return $rows;
}
function rfcd_h(string $v): string
{
    return htmlspecialchars($v,ENT_QUOTES,'UTF-8');
}
function rfcd_status_class(string $status): string
{
    $s = strtolower($status);
    if (strpos($s,'checkered') !== false || strpos($s,'green') !== false || strpos($s,'possible_results') !== false || strpos($s,'active') !== false) return 'good';
    if (strpos($s,'fail') !== false || strpos($s,'error') !== false || strpos($s,'unavailable') !== false || strpos($s,'red') !== false) return 'bad';
    return 'warn';
}
