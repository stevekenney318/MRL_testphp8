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
 * VERSION: v005
 * LAST MODIFIED: 5/19/2026 3:26:43 am
 *
 * CHANGELOG:
 *
 * v005 (2026-05-19)
 *   - NEW: Added collapsible Changed Driver Details table using classifier v006 summary data.
 *   - NEW: Dashboard now displays Changed All, MRL-Listed, and Segment-Picked counts in the Revision Classification summary table.
 *   - CHANGE: MRL Impact NO remains green/good and YES remains red/attention.
 *   - CHANGE: Changed Driver Details open/closed state is preserved across auto-refresh.
 *   - CHANGE: Dashboard score-change display now uses a web arrow (→) instead of ASCII ->.
 *   - CHANGE: Changed Driver Details uses neutral YES/NO text for MRL-listed and segment-picked flags.
 *   - CHANGE: Trusted source wording no longer hardcodes classifier v004.
 *
 * v004 (2026-05-18)
 *   - NEW: Revision Classification now uses the trusted classifier v004 summary files instead of independently scanning race folders.
 *   - NEW: Added _race_results_classification_summary.json and _race_results_classification_last_run.json to Revision Monitor File Status.
 *   - NEW: Added trusted classifier signature/version/generated/SAPI/source-file details to the Revision Classification card.
 *   - NEW: Added Classification Last Run JSON display card for quick troubleshooting.
 *   - CHANGE: Dashboard no longer mixes stale revision_meta.json, mrl_impact_summary.json, all_driver_impact_summary.json, and under_review.flag artifacts to build classification totals.
 *   - CHANGE: Revision Classification rows now normalize v004 classifier fields, including status_label, pending_review, display_tag, changed MRL drivers, and changed all drivers.
 *   - CHANGE: If the trusted classifier summary is missing, dashboard now clearly asks to run race_results_classify_revisions.php?year=YYYY instead of inventing a folder-artifact summary.
 *
 * v003 (2026-05-03)
 *   - NEW: Revision Monitor tab now includes a Revision Classification summary using existing revision metadata/classifier artifacts.
 *   - NEW: Added classifier report status and direct link to race_results_classify_revisions.php?year=YYYY.
 *   - NEW: Live Monitor tab now includes RD Status JSON in the File Status section.
 *   - NEW: Added RD Status JSON display card so the dashboard can show the latest RD check result even when no teams are eligible.
 *   - CHANGE: Dashboard now reads _race_results_rd_status.json written by race_results_monitor.php v129.
 *
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
$rdStatusFile  = $baseDir . '/_race_results_rd_status.json';

// --- Revision Monitor files ---
$revHeartbeatFile = $baseDir . '/_race_results_revision_monitor_heartbeat.txt';
$revLogFile       = $baseDir . '/_race_results_revision_monitor.log';
$classifierFile   = $baseDir . '/race_results_classify_revisions.php';
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
    return date('Y-m-d g:i:s A', $ts);
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
            --warn:    #ffd45d;
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

        .header {
            background: linear-gradient(180deg, #1f1f1f, #1b1b1b);
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: var(--shadow);
            padding: 18px 20px;
            margin-bottom: 16px;
        }

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

        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }

        @media (min-width: 980px) {
            .grid { grid-template-columns: 1fr 1fr; }
            .full { grid-column: 1 / -1; }
        }

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

        .badge.ok   { background: rgba(111,208,140,.15); color: var(--ok);   border: 1px solid rgba(111,208,140,.35); }
        .badge.warn { background: rgba(255,212,93,.15);  color: var(--warn); border: 1px solid rgba(255,212,93,.35); }
        .badge.bad  { background: rgba(239,107,107,.15); color: var(--bad);  border: 1px solid rgba(239,107,107,.35); }

        .detail-toggle { cursor: pointer; width: fit-content; }
        .detail-flag { font-weight: 700; color: var(--text); }
        .detail-flag.muted-no { color: var(--muted); }

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

        .summary-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 14px;
        }

        table.dash-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            overflow: hidden;
            border-radius: 10px;
        }

        table.dash-table th,
        table.dash-table td {
            text-align: left;
            padding: 8px 10px;
            border-bottom: 1px solid rgba(255,255,255,.06);
            vertical-align: top;
        }

        table.dash-table th {
            color: var(--accent);
            background: rgba(0,0,0,.22);
            font-weight: 700;
        }

        table.dash-table tr:nth-child(even) td {
            background: rgba(255,255,255,.025);
        }

        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
    </style>
</head>
<body>
<div class="wrap">

    <div class="header">
        <div class="tab-bar">
            <a class="tab-btn <?= $activeTab === 'live' ? 'active' : 'inactive' ?>"
               href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>'live', 'lines'=>$tailLines, 'refresh'=>$autoRefresh]))?>"
               id="tab-live-link">Race Results Dashboard</a>
            <a class="tab-btn <?= $activeTab === 'revision' ? 'active' : 'inactive' ?>"
               href="<?=h(rr_dash_build_url($selfUrl, ['tab'=>'revision', 'lines'=>$tailLines, 'refresh'=>$autoRefresh]))?>"
               id="tab-revision-link">Race Results Revision Dashboard</a>
        </div>

        <p class="subtitle">
            <?= $activeTab === 'live'
                ? 'Quick view for heartbeat, monitor state, RD status, and recent log activity'
                : 'Quick view for revision monitor heartbeat, classifier summary, and recent revision log activity' ?>
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
                        <div class="label">RD Status</div>
                        <div class="badge <?=h(rr_dash_status_class($rdStatusExists))?>"><?=h(rr_dash_status_label($rdStatusExists))?></div>
                        <div class="meta">
                            Modified: <?=h(rr_dash_file_mtime_string($rdStatusFile))?> |
                            Size: <?=h(rr_dash_file_size_string($rdStatusFile))?> |
                            <a class="inline-link" href="_race_results_rd_status.json" target="_blank" rel="noopener">Open raw file</a>
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
                <h2>RD Status JSON</h2>
                <?php if ($rdStatusPretty !== ''): ?>
                <?php if ($rdStatusMessage !== ''): ?>
                <div class="last-line" style="margin-bottom:12px;"><?=h($rdStatusMessage)?></div>
                <?php endif; ?>
                <pre><?=h($rdStatusPretty)?></pre>
                <?php else: ?>
                <div class="empty">RD status JSON file is missing or empty. It will appear after the monitor runs an RD check.</div>
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
    </div>

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

                    <div class="status-row">
                        <div class="label">Classifier</div>
                        <div class="badge <?=h(rr_dash_status_class($classifierExists))?>"><?=h(rr_dash_status_label($classifierExists))?></div>
                        <div class="meta">
                            Modified: <?=h(rr_dash_file_mtime_string($classifierFile))?> |
                            Size: <?=h(rr_dash_file_size_string($classifierFile))?> |
                            <a class="inline-link" href="race_results_classify_revisions.php?year=<?=h((string)$classYear)?>" target="_blank" rel="noopener">Open full classification report</a>
                        </div>
                    </div>

                    <div class="status-row">
                        <div class="label">Class Summary</div>
                        <div class="badge <?=h(rr_dash_status_class($classSummaryExists))?>"><?=h(rr_dash_status_label($classSummaryExists))?></div>
                        <div class="meta">
                            Modified: <?=h(rr_dash_file_mtime_string($classSummaryFile))?> |
                            Size: <?=h(rr_dash_file_size_string($classSummaryFile))?> |
                            <a class="inline-link" href="_race_results_classification_summary.json" target="_blank" rel="noopener">Open raw file</a>
                        </div>
                    </div>

                    <div class="status-row">
                        <div class="label">Class Last Run</div>
                        <div class="badge <?=h(rr_dash_status_class($classLastRunExists))?>"><?=h(rr_dash_status_label($classLastRunExists))?></div>
                        <div class="meta">
                            Modified: <?=h(rr_dash_file_mtime_string($classLastRunFile))?> |
                            Size: <?=h(rr_dash_file_size_string($classLastRunFile))?> |
                            <a class="inline-link" href="_race_results_classification_last_run.json" target="_blank" rel="noopener">Open raw file</a>
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
                <h2>Revision Classification</h2>

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
                    <span class="pill">Generated: <?=h((string)$classSummary['generated_at'])?></span>
                    <?php endif; ?>
                    <?php if ((string)$classSummary['sapi'] !== ''): ?>
                    <span class="pill">SAPI: <?=h((string)$classSummary['sapi'])?></span>
                    <?php endif; ?>
                    <a class="btn" href="race_results_classify_revisions.php?year=<?=h((string)$classYear)?>" target="_blank" rel="noopener">Open Full Classification Report</a>
                </div>

                <?php if ((string)$classSummary['message'] !== ''): ?>
                <div class="last-line" style="margin-bottom:12px;"><?=h((string)$classSummary['message'])?></div>
                <?php endif; ?>

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

            <div class="card full">
                <h2>Classification Last Run JSON</h2>
                <?php if ($classLastRunPretty !== ''): ?>
                <pre><?=h($classLastRunPretty)?></pre>
                <?php else: ?>
                <div class="empty">Classification last-run JSON file is missing or empty. Run the full classifier report to create it.</div>
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
    </div>

    <div class="footer">
        MRL Race Results Dashboard • Drop-in page for /race_results/
    </div>

</div>


<script>
(function () {
    var detailsEl = document.getElementById('changed-driver-details');
    if (!detailsEl || !window.localStorage) return;

    var storageKey = 'mrlDashboardChangedDriverDetailsOpen';
    var savedState = localStorage.getItem(storageKey);

    if (savedState === '1') {
        detailsEl.open = true;
    }

    detailsEl.addEventListener('toggle', function () {
        localStorage.setItem(storageKey, detailsEl.open ? '1' : '0');
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
