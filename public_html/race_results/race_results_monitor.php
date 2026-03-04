<?php
declare(strict_types=1);

/**
 * race_results_monitor.php
 *
 * VERSION: v1.02.00.02
 * LAST MODIFIED: 2026-02-28
 * BUILD TS: 20260228.060413
 *
 * CHANGELOG:
 * v1.02.00.02 (2026-02-28)
 *   - FIX: Prevent duplicate snapshots/emails when visible scoring-table data did not change:
 *       - Uses stable "data hash" from engine (not raw HTML hash).
 *       - If race folder has final_table_hash.txt matching current hash, treat as already captured.
 *         (Works even if monitor state JSON was reset.)
 *
 * v1.02.00.01 (2026-02-27)
 *   - NEW: Uses /race_results/<year>/_year_index.json if present
 *       to pick the correct folder name and preserve R/E/Z numbering.
 *   - Naming scheme aligned with backfill:
 *       * Rxx_... points
 *       * Exx_... exhibition
 *       * Zxx_... anomaly (rare)
 *
 * v1.01 (2026-02-25)
 *   - Cron-friendly monitor for the LATEST race on ESPN year page
 *   - Emails ONLY when scoring is REAL (non-zero)
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_race_results_monitor_php_errors.log');
error_reporting(E_ALL);

const RR_MONITOR_SIGNATURE = 'RACE_RESULTS_MONITOR v1.02.00.02';

require_once __DIR__ . '/race_results_engine.php';

// ------------------------- DOCUMENT ROOT + INCLUDES -------------------------
$docRoot = rr_docroot_from_script_dir(__DIR__);

// CLI/cron safety (some configs expect HTTP_HOST)
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}

require_once $docRoot . '/config.php';
require_once $docRoot . '/config_mrl.php';
require_once $docRoot . '/class.user.php';

$user_home = new USER();

// ------------------------- SETTINGS -------------------------
$year = 2026; // default; can be overridden by CLI arg
$notifyEmail = 'stevekenney318@gmail.com';

// Keep subject EXACT for your Gmail filter:
$subjectFinal = '[MRL] ESPN Results - FINAL Results Detected';

// Base files (in this folder)
$stateFile     = __DIR__ . '/_race_results_monitor_state.json';
$logFile       = __DIR__ . '/_race_results_monitor.log';
$heartbeatFile = __DIR__ . '/_race_results_monitor_heartbeat.txt';

// Year index produced by backfill (optional but preferred)
$yearIndexFile = __DIR__ . '/' . (string)$year . '/_year_index.json';

// Fetch behavior
$timeoutSeconds = 25;

// Snapshot behavior (per your preference)
$snapshotsEnabled = true;
$snapshotMaxBytes = 3000000; // PHP 7.3 safe

// Optional CLI override: php race_results_monitor.php 2026
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && count($argv) >= 2) {
    $cliYear = (int)$argv[1];
    if ($cliYear >= 2000 && $cliYear <= 2100) {
        $year = $cliYear;
    }
    $yearIndexFile = __DIR__ . '/' . (string)$year . '/_year_index.json';
}

// ------------------------- OUTPUT HELPER -------------------------
function rr_monitor_out(string $line): void
{
    if (PHP_SAPI === 'cli') return;
    echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "<br>\n";
}

// ------------------------- YEAR INDEX HELPERS -------------------------
function rr_load_year_index(string $path): array
{
    $idx = rr_load_json($path);
    if (!is_array($idx)) return [];
    // expected: ['races'=>[raceId=>['folder'=>...]]]
    if (!isset($idx['races']) || !is_array($idx['races'])) return [];
    return $idx;
}

function rr_year_index_folder_for_race(array $idx, string $raceId): ?string
{
    if (!isset($idx['races'][$raceId]) || !is_array($idx['races'][$raceId])) return null;
    $folder = (string)($idx['races'][$raceId]['folder'] ?? '');
    return $folder !== '' ? $folder : null;
}

// ------------------------- MAIN -------------------------
$scriptSha = rr_sha256_file_string(__FILE__);
$token = bin2hex(random_bytes(8));

$hb = rr_now_local_string()
    . "  token={$token}"
    . "  sig=" . RR_MONITOR_SIGNATURE
    . "  year={$year}"
    . "  sapi=" . PHP_SAPI
    . "  sha={$scriptSha}";

rr_atomic_write($heartbeatFile, $hb . "\n");

rr_log_line($logFile, RR_MONITOR_SIGNATURE . " RUN year={$year} sapi=" . PHP_SAPI . " sha={$scriptSha} token={$token}");

rr_monitor_out("Year {$year} — checking latest ESPN race results for update...");
rr_monitor_out("Signature: " . RR_MONITOR_SIGNATURE);

// Load state
$state = rr_load_json($stateFile);
if (!isset($state['byYear']) || !is_array($state['byYear'])) {
    $state['byYear'] = [];
}

$yKey = (string)$year;
if (!isset($state['byYear'][$yKey]) || !is_array($state['byYear'][$yKey])) {
    $state['byYear'][$yKey] = [
        'latest_url' => '',
        'last_checked_at' => '',
        'latest_debug' => [],
        'final_sent_for_url' => '',
        'final_table_hash' => '',
        'final_check' => [],
    ];
}
$yearState = $state['byYear'][$yKey];

// Load optional year index
$yearIndex = [];
if (is_file($yearIndexFile)) {
    $yearIndex = rr_load_year_index($yearIndexFile);
}

// 1) Find latest URL
[$ok, $latestUrl, $err, $debug] = rr_find_latest_race_results_url($year, $timeoutSeconds);

$yearState['last_checked_at'] = date('c');
$yearState['latest_debug'] = $debug;

if (!$ok) {
    $state['byYear'][$yKey] = $yearState;
    rr_save_json($stateFile, $state);
    rr_log_line($logFile, "ERROR latestUrl: {$err}");
    rr_monitor_out("ERROR: " . $err);
    exit(0);
}

// 2) Fetch year page again (to get readable name, etc.)
$yearPageUrl = "https://www.espn.com/racing/results/_/year/" . $year;
[$okY, $statusY, $yearHtml, $errY] = rr_fetch_url($yearPageUrl, $timeoutSeconds);

$races = $okY ? rr_parse_year_page_races($yearHtml) : [];
$latestRaceMeta = null;

for ($i = 0; $i < count($races); $i++) {
    if ((string)$races[$i]['race_url'] === (string)$latestUrl) {
        $latestRaceMeta = $races[$i];
        break;
    }
}

$raceId = rr_extract_race_id_from_url($latestUrl);
$raceName = $latestRaceMeta ? (string)$latestRaceMeta['race_name'] : 'Race';
$isExh = $latestRaceMeta ? (bool)$latestRaceMeta['is_exhibition'] : false;
$raceNum = $latestRaceMeta ? $latestRaceMeta['race_number'] : null;

// Folder naming: prefer year index if it has a known folder for this raceId
$yearFolder = __DIR__ . '/' . $yKey;
rr_ensure_dir($yearFolder);

$raceFolderName = rr_year_index_folder_for_race($yearIndex, $raceId);

if ($raceFolderName === null) {
    // Fallback (should be rare once backfill created year index)
    $raceSlug = rr_sanitize_for_folder($raceName);
    if ($isExh) {
        $raceFolderName = 'E00_' . $raceSlug . '_' . $raceId;
    } else {
        $n = ($raceNum === null) ? '00' : str_pad((string)$raceNum, 2, '0', STR_PAD_LEFT);
        $raceFolderName = 'R' . $n . '_' . $raceSlug . '_' . $raceId;
    }
}

$raceFolder = $yearFolder . '/' . $raceFolderName;

// Write meta
rr_write_meta($raceFolder, [
    'year' => $year,
    'race_id' => $raceId,
    'race_url' => $latestUrl,
    'race_name' => $raceName,
    'is_exhibition' => $isExh,
    'race_number' => $raceNum,
    'updated_at' => date('c'),
]);

$prevLatestUrl = (string)($yearState['latest_url'] ?? '');

if ($prevLatestUrl === '' || $prevLatestUrl !== $latestUrl) {
    $yearState['latest_url'] = $latestUrl;
    $yearState['final_sent_for_url'] = '';
    $yearState['final_table_hash'] = '';

    $state['byYear'][$yKey] = $yearState;
    rr_save_json($stateFile, $state);

    if ($prevLatestUrl === '') {
        rr_log_line($logFile, "INIT latest_url -> {$latestUrl} (waiting for non-zero scoring)");
    } else {
        rr_log_line($logFile, "LATEST URL CHANGED -> {$latestUrl} (prev {$prevLatestUrl}) Waiting for non-zero scoring before emailing.");
    }
}

// 3) Fetch race page
[$ok2, $status2, $html2, $err2] = rr_fetch_url($latestUrl, $timeoutSeconds);

if (!$ok2) {
    $state['byYear'][$yKey] = $yearState;
    rr_save_json($stateFile, $state);
    rr_log_line($logFile, "ERROR fetching race page (HTTP {$status2}): {$err2} url={$latestUrl}");
    rr_monitor_out("ERROR fetching race page: HTTP {$status2} {$err2}");
    exit(0);
}

// 4) Detect FINAL
[$isFinal, $reason, $details] = rr_detect_final_scoring_nonzero($html2);

$yearState['final_check'] = [
    'is_final' => $isFinal,
    'reason' => $reason,
    'checked_at' => date('c'),
    'mode' => (string)($details['mode'] ?? ''),
    'hash' => (string)($details['tableHash'] ?? ''), // stable data hash
    'rows_checked' => (int)($details['rowsChecked'] ?? 0),
    'non_zero_counts' => $details['nonZeroCounts'] ?? [],
    'col_index' => $details['colIndex'] ?? [],
    'tables_found' => (int)($details['tablesFound'] ?? 0),
    'header_row_found' => (bool)($details['headerRowFound'] ?? false),
];

$state['byYear'][$yKey] = $yearState;
rr_save_json($stateFile, $state);

if (!$isFinal) {
    rr_log_line($logFile, "NOT FINAL (no email) url={$latestUrl} reason={$reason}");
    rr_monitor_out("NOT FINAL (no email).");
    rr_monitor_out("Reason: " . $reason);
    exit(0);
}

// 5) Duplicate protection using folder hash file (authoritative)
$finalHashNow = (string)($yearState['final_check']['hash'] ?? '');
$hashFilePath = $raceFolder . '/final_table_hash.txt';

if ($finalHashNow !== '' && is_file($hashFilePath)) {
    $existing = trim((string)@file_get_contents($hashFilePath));
    if ($existing !== '' && hash_equals($existing, $finalHashNow)) {
        // Already captured (even if monitor state got reset)
        $yearState['final_sent_for_url'] = $latestUrl;
        $yearState['final_table_hash'] = $finalHashNow;
        $state['byYear'][$yKey] = $yearState;
        rr_save_json($stateFile, $state);

        rr_log_line($logFile, "FINAL detected but already captured by folder hash (no snapshot/email) url={$latestUrl}");
        rr_monitor_out("FINAL detected, already captured (no snapshot/email).");
        exit(0);
    }
}

// 6) Email gating (state-based)
$finalSentForUrl = (string)($yearState['final_sent_for_url'] ?? '');
$finalHashPrev   = (string)($yearState['final_table_hash'] ?? '');

$shouldEmail = false;
$emailReason = '';

if ($finalSentForUrl !== $latestUrl) {
    $shouldEmail = true;
    $emailReason = 'First non-zero scoring detection for this race URL.';
} elseif ($finalHashNow !== '' && $finalHashPrev !== '' && $finalHashNow !== $finalHashPrev) {
    $shouldEmail = true;
    $emailReason = 'Scoring/results changed (hash change).';
}

if (!$shouldEmail) {
    rr_log_line($logFile, "FINAL detected but no email needed (already notified) url={$latestUrl}");
    rr_monitor_out("FINAL detected, already notified (no email).");
    exit(0);
}

// Save snapshot
if ($snapshotsEnabled) {
    $tsFile = rr_preferred_timestamp(true);
    rr_save_snapshot_html($raceFolder, $tsFile, $html2, $snapshotMaxBytes);
    rr_save_snapshot_summary($raceFolder, $tsFile, $html2);
    rr_atomic_write($hashFilePath, $finalHashNow . "\n");
    rr_log_line($logFile, "SNAPSHOT SAVED in " . basename($raceFolder));
}

// Update state
$yearState['final_sent_for_url'] = $latestUrl;
$yearState['final_table_hash'] = $finalHashNow;

$state['byYear'][$yKey] = $yearState;
rr_save_json($stateFile, $state);

// Send email
$subject = $subjectFinal;

$message =
    "FINAL scoring appears to be posted on ESPN (non-zero scoring detected).\n\n" .
    "Year: {$year}\n" .
    "URL : {$latestUrl}\n\n" .
    "Reason: {$reason}\n" .
    "Note: This email will only repeat if ESPN changes the results again.\n";

$sentOk = false;

try {
    $sentOk = (bool)$user_home->send_mail($notifyEmail, $message, $subject);
} catch (Throwable $e) {
    rr_log_line($logFile, "EMAIL EXCEPTION: " . $e->getMessage());
    $sentOk = false;
}

rr_log_line(
    $logFile,
    $sentOk
        ? "EMAIL SENT (FINAL) to={$notifyEmail} url={$latestUrl} ({$emailReason})"
        : "EMAIL FAILED (FINAL) to={$notifyEmail} url={$latestUrl} ({$emailReason})"
);

rr_monitor_out($sentOk ? "EMAIL SENT (FINAL)." : "EMAIL FAILED (FINAL).");
exit(0);