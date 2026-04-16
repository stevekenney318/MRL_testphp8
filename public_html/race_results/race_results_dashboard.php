<?php
declare(strict_types=1);

// HARD disable caching everywhere
if (!headers_sent()) {
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

/**
 * race_results_dashboard.php
 *
 * VERSION: v002
 * LAST MODIFIED: 4/15/2026 5:30:00 am
 *
 * CHANGELOG:
 * v002 (2026-04-15)
 *   - NEW: Tabbed layout — Live Monitor tab and Revision Monitor tab.
 *   - Tab switching is pure JS/CSS (no page reload).
 *   - Revision Monitor tab shows its own heartbeat, log tail, and file status.
 *   - Active tab highlighted in gold; inactive tab dimmed per mockup.
 *   - All timestamps NY time (America/New_York).
 *
 * v1.00.00.04 (2026-03-08)
 *   - Original single-monitor dashboard.
 *   - Shows heartbeat, monitor state JSON, last log line, log tail.
 *   - Mobile-friendly, auto-refresh, adjustable log line count.
 */

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

// --- Revision Monitor files ---
$revHeartbeatFile = $baseDir . '/_race_results_revision_monitor_heartbeat.txt';
$revLogFile       = $baseDir . '/_race_results_revision_monitor.log';

$defaultTailLines = 10;
$maxTailLines     = 200;

$tailLines = isset($_GET['lines']) ? (int)$_GET['lines'] : $defaultTailLines;
if ($tailLines < 1)           $tailLines = $defaultTailLines;
if ($tailLines > $maxTailLines) $tailLines = $maxTailLines;

$autoRefresh = isset($_GET['refresh']) ? (int)$_GET['refresh'] : 30;
if ($autoRefresh < 0)    $autoRefresh = 0;
if ($autoRefresh > 3600) $autoRefresh = 3600;

$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'revision') ? 'revision' : 'live';

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
    return date('Y-m-d g:i:s A', $ts);
}

function rr_dash_file_size_string(string $path): string
{
    if (!is_file($path)) return '—';
    $size = @filesize($path);
    if ($size === false) return '—';
    if ($size < 1024)             return $size . ' B';
    if ($size < 1024 * 1024)      return number_format($size / 1024, 1) . ' KB';
    return number_format($size / 1024 / 1024, 2) . ' MB';
}

function rr_dash_tail_lines(string $path, int $lineCount): string
{
    if (!is_file($path)) return '';

    $fh = @fopen($path, 'rb');
    if ($fh === false) return '';

    $buffer    = '';
    $chunkSize = 4096;
    $pos       = 0;
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

function rr_dash_build_url(string $path, array $params): string
{
    return $path . '?' . http_build_query($params);
}

// -----------------------------------------------------------------------------
// Read data — Live Monitor
// -----------------------------------------------------------------------------
$heartbeatRaw = rr_dash_read_file($heartbeatFile);
$stateRaw     = rr_dash_read_file($stateFile);
$logTailRaw   = rr_dash_tail_lines($logFile, $tailLines);
$lastLogLine  = rr_dash_last_line($logFile);
$statePretty  = rr_dash_pretty_json($stateRaw);

$heartbeatExists = rr_dash_file_exists($heartbeatFile);
$stateExists     = rr_dash_file_exists($stateFile);
$logExists       = rr_dash_file_exists($logFile);

// -----------------------------------------------------------------------------
// Read data — Revision Monitor
// -----------------------------------------------------------------------------
$revHeartbeatRaw  = rr_dash_read_file($revHeartbeatFile);
$revLogTailRaw    = rr_dash_tail_lines($revLogFile, $tailLines);
$revLastLogLine   = rr_dash_last_line($revLogFile);

$revHeartbeatExists = rr_dash_file_exists($revHeartbeatFile);
$revLogExists       = rr_dash_file_exists($revLogFile);

$pageGenerated = date('Y-m-d g:i:s A');
$selfUrl = strtok($_SERVER['REQUEST_URI'] ?? 'race_results_dashboard.php', '?');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MRL Race Results Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg:      #171717;
            --panel:   #222222;
            --panel2:  #262626;
            --text:    #f2f2f2;
            --muted:   #c9c9c9;
            --accent:  #d8ba86;
            --accent2: #b99352;
            --line:    rgba(216,186,134,.25);
            --ok:      #6fd08c;
            --bad:     #ef6b6b;
            --link:    #8cc8ff;
            --shadow:  0 10px 28px rgba(0,0,0,.28);
            --mono:    Consolas, Menlo, Monaco, "Courier New", monospace;
            --sans:    Arial, Helvetica, sans-serif;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background:
                radial-gradient(circle at top right, rgba(216,186,134,.10), transparent 35%),
                radial-gradient(circle at top left,  rgba(255,255,255,.04), transparent 25%),
                var(--bg);
            color: var(--text);
            font-family: var(--sans);
            line-height: 1.45;
        }

        .wrap {
            max-width: 1200px;
            margin: 0 auto;
            padding: 18px;
        }

        /* ---- HEADER ---- */
        .header {
            background: linear-gradient(180deg, #1f1f1f, #1b1b1b);
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: var(--shadow);
            padding: 18px 20px;
            margin-bottom: 16px;
        }

        /* ---- TABS ---- */
        .tab-bar {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .tab-btn {
            display: inline-block;
            text-decoration: none;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: .3px;
            padding: 10px 22px;
            border-radius: 12px;
            border: 2px solid transparent;
            transition: border-color .15s, color .15s, background .15s;
            cursor: pointer;
        }

        .tab-btn.active {
            color: var(--accent);
            border-color: var(--accent);
            background: rgba(216,186,134,.07);
        }

        .tab-btn.inactive {
            color: #666666;
            border-color: #3a3a3a;
            background: transparent;
        }

        .tab-btn.inactive:hover {
            color: #999999;
            border-color: #555555;
        }

        .subtitle {
            margin: 0 0 0 4px;
            color: var(--muted);
            font-size: 15px;
        }

        /* ---- TOOLBAR ---- */
        .toolbar {
            margin-top: 14px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .btn, .pill {
            display: inline-block;
            text-decoration: none;
            color: var(--text);
            background: #2b2b2b;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 13px;
        }

        .btn:hover { background: #333333; }

        /* ---- GRID ---- */
        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }

        @media (min-width: 980px) {
            .grid { grid-template-columns: 1fr 1fr; }
            .full { grid-column: 1 / -1; }
        }

        /* ---- CARDS ---- */
        .card {
            background: linear-gradient(180deg, var(--panel), var(--panel2));
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: var(--shadow);
            padding: 16px;
        }

        .card h2 {
            margin: 0 0 12px 0;
            color: var(--accent);
            font-size: 28px;
        }

        /* ---- STATUS LIST ---- */
        .status-list { display: grid; gap: 10px; }

        .status-row {
            display: grid;
            grid-template-columns: 150px 100px 1fr;
            gap: 10px;
            align-items: center;
            padding: 10px 12px;
            background: rgba(255,255,255,.03);
            border: 1px solid rgba(255,255,255,.05);
            border-radius: 10px;
        }

        @media (max-width: 720px) {
            .status-row { grid-template-columns: 1fr; }
        }

        .label { font-weight: 700; color: var(--text); }

        .badge {
            display: inline-block;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 700;
            width: fit-content;
        }

        .badge.ok  { background: rgba(111,208,140,.15); color: var(--ok);  border: 1px solid rgba(111,208,140,.35); }
        .badge.bad { background: rgba(239,107,107,.15); color: var(--bad); border: 1px solid rgba(239,107,107,.35); }

        .meta { color: var(--muted); font-size: 14px; }

        pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            font-family: var(--mono);
            font-size: 13px;
            background: rgba(0,0,0,.22);
            border: 1px solid rgba(255,255,255,.06);
            border-radius: 10px;
            padding: 14px;
            color: #f0f0f0;
            overflow-x: auto;
        }

        .empty { color: var(--muted); font-style: italic; }

        .footer {
            margin-top: 16px;
            color: var(--muted);
            font-size: 13px;
            text-align: center;
        }

        a.inline-link { color: var(--link); text-decoration: none; }
        a.inline-link:hover { text-decoration: underline; }

        .last-line {
            font-family: var(--mono);
            font-size: 14px;
            background: rgba(0,0,0,.22);
            border: 1px solid rgba(255,255,255,.06);
            border-radius: 10px;
            padding: 14px;
            color: #f0f0f0;
            word-break: break-word;
        }

        /* ---- TAB PANELS ---- */
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
    </style>
</head>
<body>
<div class="wrap">

    <!-- HEADER -->
    <div class="header">

        <div class="tab-bar">
            <a class="tab-btn <?= $activeTab === 'live' ? 'active' : 'inactive' ?>"
               href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>'live',     'lines'=>$tailLines, 'refresh'=>$autoRefresh]))?>"
               id="tab-live-link">Race Results Dashboard</a>
            <a class="tab-btn <?= $activeTab === 'revision' ? 'active' : 'inactive' ?>"
               href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>'revision', 'lines'=>$tailLines, 'refresh'=>$autoRefresh]))?>"
               id="tab-revision-link">Race Results Revision Dashboard</a>
        </div>

        <p class="subtitle">
            <?= $activeTab === 'live'
                ? 'Quick view for heartbeat, monitor state, and recent log activity'
                : 'Quick view for revision monitor heartbeat and recent revision log activity' ?>
        </p>

        <div class="toolbar">
            <span class="pill">Page Generated: <?=h($pageGenerated)?></span>
            <span class="pill">Log Lines: <?=h((string)$tailLines)?></span>
            <span class="pill">Auto Refresh: <?= $autoRefresh > 0 ? h((string)$autoRefresh).'s' : 'Off' ?></span>
            <?php if ($autoRefresh > 0): ?>
            <span class="pill">Next Refresh: <span id="countdown"><?=h((string)$autoRefresh)?></span>s</span>
            <?php endif; ?>

            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>2,   'refresh'=>$autoRefresh]))?>">2 log lines</a>
            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>4,   'refresh'=>$autoRefresh]))?>">4 log lines</a>
            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>10,  'refresh'=>$autoRefresh]))?>">10 log lines</a>
            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>25,  'refresh'=>$autoRefresh]))?>">25 log lines</a>
            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>50,  'refresh'=>$autoRefresh]))?>">50 log lines</a>
            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>100, 'refresh'=>$autoRefresh]))?>">100 log lines</a>

            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>$tailLines,'refresh'=>0]))?>">Refresh Off</a>
            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>$tailLines,'refresh'=>15]))?>">15s</a>
            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>$tailLines,'refresh'=>30]))?>">30s</a>
            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>$tailLines,'refresh'=>60]))?>">1 min</a>
            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>$tailLines,'refresh'=>120]))?>">2 min</a>
            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>$tailLines,'refresh'=>300]))?>">5 min</a>
            <a class="btn" href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>$activeTab,'lines'=>$tailLines,'refresh'=>$autoRefresh]))?>">Reload Now</a>
        </div>
    </div>

    <!-- ================================================================ -->
    <!-- TAB: LIVE MONITOR                                                 -->
    <!-- ================================================================ -->
    <div class="tab-panel <?= $activeTab === 'live' ? 'active' : '' ?>" id="panel-live">

        <div class="card" style="margin-bottom:16px;">
            <h2>Last Log Line</h2>
            <?php if ($lastLogLine !== ''): ?>
            <div class="last-line"><?=h($lastLogLine)?></div>
            <?php else: ?>
            <div class="empty">Monitor log file is missing or empty.</div>
            <?php endif; ?>
        </div>

        <div class="grid">

            <div class="card">
                <h2>File Status</h2>
                <div class="status-list">

                    <div class="status-row">
                        <div class="label">Heartbeat</div>
                        <div class="badge <?=h(rr_dash_status_class($heartbeatExists))?>"><?=h(rr_dash_status_label($heartbeatExists))?></div>
                        <div class="meta">
                            Modified: <?=h(rr_dash_file_mtime_string($heartbeatFile))?> |
                            Size: <?=h(rr_dash_file_size_string($heartbeatFile))?> |
                            <a class="inline-link" href="_race_results_monitor_heartbeat.txt" target="_blank" rel="noopener">Open raw file</a>
                        </div>
                    </div>

                    <div class="status-row">
                        <div class="label">State JSON</div>
                        <div class="badge <?=h(rr_dash_status_class($stateExists))?>"><?=h(rr_dash_status_label($stateExists))?></div>
                        <div class="meta">
                            Modified: <?=h(rr_dash_file_mtime_string($stateFile))?> |
                            Size: <?=h(rr_dash_file_size_string($stateFile))?> |
                            <a class="inline-link" href="_race_results_monitor_state.json" target="_blank" rel="noopener">Open raw file</a>
                        </div>
                    </div>

                    <div class="status-row">
                        <div class="label">Monitor Log</div>
                        <div class="badge <?=h(rr_dash_status_class($logExists))?>"><?=h(rr_dash_status_label($logExists))?></div>
                        <div class="meta">
                            Modified: <?=h(rr_dash_file_mtime_string($logFile))?> |
                            Size: <?=h(rr_dash_file_size_string($logFile))?> |
                            <a class="inline-link" href="_race_results_monitor.log" target="_blank" rel="noopener">Open raw file</a>
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

            <div class="card full">
                <h2>Monitor State JSON</h2>
                <?php if ($statePretty !== ''): ?>
                <pre><?=h($statePretty)?></pre>
                <?php else: ?>
                <div class="empty">State JSON file is missing or empty.</div>
                <?php endif; ?>
            </div>

            <div class="card full">
                <h2>Last <?=h((string)$tailLines)?> Log Lines</h2>
                <?php if ($logTailRaw !== ''): ?>
                <pre><?=h(trim($logTailRaw))?></pre>
                <?php else: ?>
                <div class="empty">Monitor log file is missing or empty.</div>
                <?php endif; ?>
            </div>

        </div>
    </div><!-- /panel-live -->

    <!-- ================================================================ -->
    <!-- TAB: REVISION MONITOR                                             -->
    <!-- ================================================================ -->
    <div class="tab-panel <?= $activeTab === 'revision' ? 'active' : '' ?>" id="panel-revision">

        <div class="card" style="margin-bottom:16px;">
            <h2>Last Log Line</h2>
            <?php if ($revLastLogLine !== ''): ?>
            <div class="last-line"><?=h($revLastLogLine)?></div>
            <?php else: ?>
            <div class="empty">Revision monitor log file is missing or empty.</div>
            <?php endif; ?>
        </div>

        <div class="grid">

            <div class="card">
                <h2>File Status</h2>
                <div class="status-list">

                    <div class="status-row">
                        <div class="label">Heartbeat</div>
                        <div class="badge <?=h(rr_dash_status_class($revHeartbeatExists))?>"><?=h(rr_dash_status_label($revHeartbeatExists))?></div>
                        <div class="meta">
                            Modified: <?=h(rr_dash_file_mtime_string($revHeartbeatFile))?> |
                            Size: <?=h(rr_dash_file_size_string($revHeartbeatFile))?> |
                            <a class="inline-link" href="_race_results_revision_monitor_heartbeat.txt" target="_blank" rel="noopener">Open raw file</a>
                        </div>
                    </div>

                    <div class="status-row">
                        <div class="label">Revision Log</div>
                        <div class="badge <?=h(rr_dash_status_class($revLogExists))?>"><?=h(rr_dash_status_label($revLogExists))?></div>
                        <div class="meta">
                            Modified: <?=h(rr_dash_file_mtime_string($revLogFile))?> |
                            Size: <?=h(rr_dash_file_size_string($revLogFile))?> |
                            <a class="inline-link" href="_race_results_revision_monitor.log" target="_blank" rel="noopener">Open raw file</a>
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
            </div>

            <div class="card full">
                <h2>Last <?=h((string)$tailLines)?> Log Lines</h2>
                <?php if ($revLogTailRaw !== ''): ?>
                <pre><?=h(trim($revLogTailRaw))?></pre>
                <?php else: ?>
                <div class="empty">Revision monitor log file is missing or empty.</div>
                <?php endif; ?>
            </div>

        </div>
    </div><!-- /panel-revision -->

    <div class="footer">
        MRL Race Results Dashboard • Drop-in page for /race_results/
    </div>

</div><!-- /wrap -->

<?php if ($autoRefresh > 0): ?>
<script>
(function () {
    var refreshSeconds = <?= (int)$autoRefresh ?>;
    var countdownEl    = document.getElementById('countdown');
    var remaining      = refreshSeconds;

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
