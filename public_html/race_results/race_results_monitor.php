<?php
declare(strict_types=1);

/**
 * race_results_monitor.php
 *
 * VERSION: v125
 * LAST MODIFIED: 3/23/2026 1:10:14 am
 *
 *
 * CHANGELOG:
 *
 * v125 (3/23/2026)
 *   - CHANGE: Added automatic under_review.flag creation when a new or revised snapshot is written.
 *   - CHANGE: Pending review state now reappears automatically when result changes trigger a new snapshot.
 *   - CHANGE: Updated header versioning to v125 format while preserving existing monitor behavior.
 *
 * CHANGELOG:
 * v1.02.00.04 (2026-03-12)
 *   - CHANGE: Tightened FINAL / email trigger logic.
 *   - NEW: Race is NOT treated as ready unless:
 *       - scoring table has non-zero PTS
 *       - AND LED column is present with at least one non-zero value
 *   - This helps avoid early intermediate ESPN tables that have partial scoring
 *     but still show all-zero laps-led.
 *
 * v1.02.00.03 (2026-03-12)
 *   - FIX: Monitor now updates /race_results/<year>/_year_index.json when a new race appears.
 *   - If raceId already exists in year index, existing folder mapping is reused.
 *   - If raceId is new:
 *       - points race => assigns next R## (or uses parsed race_number if available)
 *       - exhibition race => assigns next E##
 *   - CHANGE: Email recipient changed to manliusracingleague@gmail.com.
 *   - CHANGE: Email subject is now dynamic, e.g.:
 *       [MRL] Results Detected: 2026_R04_Phoenix
 *   - CHANGE: Email body is now HTML with:
 *       - Race Results hyperlink
 *       - MRL Snapshot hyperlink
 *       - clean spacing
 *       - <hr> separator
 *   - CHANGE: MRL snapshot link is built from current host when possible, with
 *       fallback to manliusracingleague.com.
 *   - NOTE: Existing state/hash-based repeat-email behavior is preserved in this version.
 *
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

const RR_MONITOR_SIGNATURE = 'RACE_RESULTS_MONITOR v125';

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
$notifyEmail = 'manliusracingleague@gmail.com';

// Keep stable prefix for Gmail filter:
$subjectPrefix = '[MRL] Results Detected: ';

// Base files (in this folder)
$stateFile     = __DIR__ . '/_race_results_monitor_state.json';
$logFile       = __DIR__ . '/_race_results_monitor.log';
$heartbeatFile = __DIR__ . '/_race_results_monitor_heartbeat.txt';

// Year index produced by backfill / maintained by monitor
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

    if (!isset($idx['year'])) {
        $idx['year'] = null;
    }

    if (!isset($idx['generated_at'])) {
        $idx['generated_at'] = '';
    }

    if (!isset($idx['races']) || !is_array($idx['races'])) {
        $idx['races'] = [];
    }

    return $idx;
}

function rr_year_index_folder_for_race(array $idx, string $raceId): ?string
{
    if (!isset($idx['races'][$raceId]) || !is_array($idx['races'][$raceId])) return null;
    $folder = (string)($idx['races'][$raceId]['folder'] ?? '');
    return $folder !== '' ? $folder : null;
}

function rr_monitor_save_year_index(int $year, string $yearFolder, array $idx): void
{
    rr_ensure_dir($yearFolder);
    $idx['year'] = $year;
    $idx['generated_at'] = date('c');

    if (!isset($idx['races']) || !is_array($idx['races'])) {
        $idx['races'] = [];
    }

    rr_save_json($yearFolder . '/_year_index.json', $idx);
}

function rr_monitor_next_kind_number(array $yearIndex, string $kind): int
{
    $max = 0;

    if (!isset($yearIndex['races']) || !is_array($yearIndex['races'])) {
        return 1;
    }

    foreach ($yearIndex['races'] as $raceId => $row) {
        if (!is_array($row)) continue;

        $rowKind = (string)($row['kind'] ?? '');
        if ($rowKind !== $kind) continue;

        $n = (int)($row['number'] ?? 0);
        if ($n > $max) {
            $max = $n;
        }
    }

    return $max + 1;
}

function rr_monitor_assign_folder_and_update_index(
    int $year,
    string $raceId,
    string $raceUrl,
    string $raceName,
    bool $isExhibition,
    ?int $raceNum,
    string $yearFolder,
    array &$yearIndex
): string {
    $existing = rr_year_index_folder_for_race($yearIndex, $raceId);
    if ($existing !== null) {
        return $existing;
    }

    $raceSlug = rr_sanitize_for_folder($raceName);
    $kind = '';
    $number = 0;

    if ($isExhibition) {
        $kind = 'E';
        $number = rr_monitor_next_kind_number($yearIndex, 'E');
    } else {
        $kind = 'R';
        if ($raceNum !== null && $raceNum > 0) {
            $number = $raceNum;
        } else {
            $number = rr_monitor_next_kind_number($yearIndex, 'R');
        }
    }

    $numStr = str_pad((string)$number, 2, '0', STR_PAD_LEFT);
    $folder = $kind . $numStr . '_' . $raceSlug . '_' . $raceId;

    if (!isset($yearIndex['races']) || !is_array($yearIndex['races'])) {
        $yearIndex['races'] = [];
    }

    $yearIndex['races'][$raceId] = [
        'folder' => $folder,
        'kind' => $kind,
        'number' => $number,
        'race_url' => $raceUrl,
        'race_name' => $raceName,
    ];

    rr_monitor_save_year_index($year, $yearFolder, $yearIndex);

    return $folder;
}

// ------------------------- EMAIL HELPERS -------------------------
function rr_monitor_public_host(string $docRoot, string $scriptDir): string
{
    $candidates = [];

    if (!empty($_SERVER['HTTP_HOST'])) {
        $candidates[] = (string)$_SERVER['HTTP_HOST'];
    }

    if (!empty($_SERVER['SERVER_NAME'])) {
        $candidates[] = (string)$_SERVER['SERVER_NAME'];
    }

    $candidates[] = $docRoot;
    $candidates[] = $scriptDir;

    for ($i = 0; $i < count($candidates); $i++) {
        $cand = (string)$candidates[$i];
        if ($cand === '') continue;

        if (preg_match('~([A-Za-z0-9.-]*manliusracingleague\.com)~i', $cand, $m)) {
            return strtolower((string)$m[1]);
        }
    }

    return 'manliusracingleague.com';
}

function rr_monitor_short_race_label(string $raceName): string
{
    $slug = rr_sanitize_for_folder($raceName);

    $map = [
        'Daytona_500' => 'Daytona',
        'EchoPark_Automotive_Grand_Prix' => 'COTA',
        'NASCAR_Cup_Series_at_Circuit_of_the_Americas' => 'COTA',
        'NASCAR_Cup_Series_at_Atlanta' => 'Atlanta',
        'NASCAR_Cup_Series_at_Phoenix' => 'Phoenix',
    ];

    if (isset($map[$slug])) {
        return $map[$slug];
    }

    $slug = preg_replace('/^NASCAR_Cup_Series_at_/', '', $slug);
    $slug = preg_replace('/^NASCAR_Cup_Series_/', '', $slug);
    $slug = preg_replace('/^AT_/', '', $slug);
    $slug = trim((string)$slug, '_');

    if ($slug === '') {
        $slug = 'Race';
    }

    return $slug;
}

function rr_monitor_subject_token(int $year, string $raceFolderName, string $raceName): string
{
    $raceCode = 'R00';
    if (preg_match('/^(R|E|Z)\d{2}/', $raceFolderName, $m)) {
        $raceCode = (string)$m[0];
    }

    $label = rr_monitor_short_race_label($raceName);

    return $year . '_' . $raceCode . '_' . $label;
}

// ------------------------- LED COMPLETENESS CHECK -------------------------
function rr_monitor_norm_header(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return strtoupper($s);
}

function rr_monitor_parse_int_cell(string $s): ?int
{
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = preg_replace('/[^0-9\-]/', '', $s);

    if ($s === '' || $s === '-') return null;
    if (!preg_match('/^-?\d+$/', $s)) return null;

    return (int)$s;
}

/**
 * Returns:
 * [
 *   'has_led_column' => bool,
 *   'rows_checked'   => int,
 *   'led_non_zero'   => int
 * ]
 */
function rr_monitor_led_check(string $html): array
{
    $out = [
        'has_led_column' => false,
        'rows_checked' => 0,
        'led_non_zero' => 0,
    ];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    if (!$loaded) {
        return $out;
    }

    $xp = new DOMXPath($dom);
    $tables = $xp->query('//table');

    if (!$tables || $tables->length === 0) {
        return $out;
    }

    $bestTable = null;
    $bestHeaderRow = null;
    $idxLed = null;

    for ($t = 0; $t < $tables->length; $t++) {
        $tbl = $tables->item($t);
        if (!$tbl instanceof DOMElement) continue;

        $rows = $xp->query('.//tr', $tbl);
        if (!$rows || $rows->length === 0) continue;

        for ($r = 0; $r < $rows->length; $r++) {
            $row = $rows->item($r);
            if (!$row instanceof DOMElement) continue;

            $cells = $xp->query('./th|./td', $row);
            if (!$cells || $cells->length < 5) continue;

            $headers = [];
            $hasPts = false;
            $hasBonus = false;
            $hasPenalty = false;
            $foundLed = null;

            for ($i = 0; $i < $cells->length; $i++) {
                $txt = rr_monitor_norm_header((string)$cells->item($i)->textContent);
                $headers[] = $txt;

                if (strpos($txt, 'PTS') !== false || strpos($txt, 'POINT') !== false) $hasPts = true;
                if (strpos($txt, 'BONUS') !== false) $hasBonus = true;
                if (strpos($txt, 'PENALTY') !== false) $hasPenalty = true;
                if ($foundLed === null && strpos($txt, 'LED') !== false) $foundLed = $i;
            }

            if ($hasPts && ($hasBonus || $hasPenalty) && $foundLed !== null) {
                $bestTable = $tbl;
                $bestHeaderRow = $row;
                $idxLed = $foundLed;
                break 2;
            }
        }
    }

    if (!$bestTable instanceof DOMElement || !$bestHeaderRow instanceof DOMElement || $idxLed === null) {
        return $out;
    }

    $out['has_led_column'] = true;

    $rows = $xp->query('.//tr[td]', $bestTable);
    if (!$rows || $rows->length === 0) {
        return $out;
    }

    $headerSeen = false;

    for ($r = 0; $r < $rows->length; $r++) {
        $row = $rows->item($r);
        if (!$row instanceof DOMElement) continue;

        if ($row->isSameNode($bestHeaderRow)) {
            $headerSeen = true;
            continue;
        }
        if (!$headerSeen) continue;

        $tds = $xp->query('./td', $row);
        if (!$tds || $tds->length === 0) continue;

        $firstCell = trim((string)$tds->item(0)->textContent);
        $posDigits = preg_replace('/\D+/', '', $firstCell);
        if ($posDigits === '' || !preg_match('/^\d+$/', $posDigits)) continue;

        $out['rows_checked']++;

        if ($idxLed >= 0 && $idxLed < $tds->length) {
            $v = rr_monitor_parse_int_cell((string)$tds->item($idxLed)->textContent);
            if ($v !== null && $v !== 0) {
                $out['led_non_zero']++;
            }
        }
    }

    return $out;
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
if (!isset($yearIndex['races']) || !is_array($yearIndex['races'])) {
    $yearIndex['races'] = [];
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

// Folder naming: prefer year index if it has a known folder for this raceId;
// if not, assign/update year index now.
$yearFolder = __DIR__ . '/' . $yKey;
rr_ensure_dir($yearFolder);

$raceFolderName = rr_monitor_assign_folder_and_update_index(
    $year,
    $raceId,
    $latestUrl,
    $raceName,
    $isExh,
    $raceNum,
    $yearFolder,
    $yearIndex
);

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

// Additional completeness gate: LED must not be all zero
$ledCheck = rr_monitor_led_check($html2);
$ledReady = ($ledCheck['has_led_column'] && (int)$ledCheck['led_non_zero'] > 0);

if ($isFinal && !$ledReady) {
    $isFinal = false;
    $reason = 'Scoring table has non-zero PTS, but LED column is still all zero.';
}

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
    'led_has_column' => (bool)$ledCheck['has_led_column'],
    'led_rows_checked' => (int)$ledCheck['rows_checked'],
    'led_non_zero' => (int)$ledCheck['led_non_zero'],
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
$snapshotPath = '';
if ($snapshotsEnabled) {
    $tsFile = rr_preferred_timestamp(true);
    $snapshotPath = rr_save_snapshot_html($raceFolder, $tsFile, $html2, $snapshotMaxBytes);
    rr_save_snapshot_summary($raceFolder, $tsFile, $html2);
    rr_atomic_write($hashFilePath, $finalHashNow . "\n");
    touch($raceFolder . '/under_review.flag');
    rr_log_line($logFile, "SNAPSHOT SAVED in " . basename($raceFolder));
}

// Update state BEFORE email
$yearState['final_sent_for_url'] = $latestUrl;
$yearState['final_table_hash'] = $finalHashNow;

$state['byYear'][$yKey] = $yearState;
rr_save_json($stateFile, $state);

// Build subject + HTML email
$publicHost = rr_monitor_public_host($docRoot, __DIR__);
$subjectToken = rr_monitor_subject_token($year, $raceFolderName, $raceName);
$subject = $subjectPrefix . $subjectToken;

$raceResultsLink = $latestUrl;
$mrlSnapshotLink = '';

if ($snapshotPath !== '') {
    $snapshotBase = basename($snapshotPath);
    $mrlSnapshotLink = 'https://' . $publicHost
        . '/race_results/' . rawurlencode($yKey)
        . '/' . rawurlencode($raceFolderName)
        . '/' . rawurlencode($snapshotBase);
}

$message =
    'Results Detected for ' . htmlspecialchars($subjectToken, ENT_QUOTES, 'UTF-8') . '<br>' .
    '<a href="' . htmlspecialchars($raceResultsLink, ENT_QUOTES, 'UTF-8') . '">Race Results</a><br>' .
    (
        $mrlSnapshotLink !== ''
            ? '<a href="' . htmlspecialchars($mrlSnapshotLink, ENT_QUOTES, 'UTF-8') . '">MRL Snapshot</a>'
            : ''
    ) .
    '<hr>' .
    'Reason: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '<br>' .
    'Note: This email will only repeat if changes to the results have been detected.';

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
