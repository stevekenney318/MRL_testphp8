<?php
declare(strict_types=1);

/**
 * race_results_revision_monitor.php
 *
 * VERSION: v007
 * LAST MODIFIED: 5/19/2026 6:53:40 pm
 *
 * CHANGELOG:
 * v007 (5/19/2026)
 *   - CHANGE: Revision monitor now prefers classifier v009 change/review fields when deciding whether a Rev or review is required.
 *   - CHANGE: review_required drives under_review.flag creation/removal.
 *   - CHANGE: revision_meta.json now stores change_detected, driver_scoring_change_detected, revision_required, review_required, change_status, and change_status_label.
 *   - CHANGE: Browser/log output now includes changed all, MRL-listed, and segment-picked driver counts.
 *   - CHANGE: Non-impact scoring-table changes are saved and logged without creating Pending Review or a normal revision email.
 *
 * v006 (5/19/2026)
 *   - FIX: under_review.flag is no longer created before classification runs.
 *   - FIX: Hash/snapshot changes with no driver scoring changes are now treated as non-scoring changes, not Pending Review.
 *   - FIX: revision_meta.json now sets pending_review=false when classification finds no MRL impact.
 *   - CHANGE: MRL-impacting revisions still create under_review.flag, receive visible Rev sequencing, and send the normal revision email.
 *   - CHANGE: All-driver-only scoring changes with no MRL impact are logged, saved, classified, and emailed as informational no-MRL-impact changes, but do not create under_review.flag.
 *   - CHANGE: Non-scoring page/hash changes are logged and saved for audit, but do not create under_review.flag and do not send email.
 *   - CHANGE: Classification-unavailable revisions remain conservative: they create under_review.flag and send the normal revision email because impact is unknown.
 *
 * v005 (4/29/2026)
 *   - CHANGE: Revised revision email subject to use plain ASCII formatting for safer delivery/readability.
 *   - CHANGE: Rebuilt revision email body as real HTML with clearer sections for race, impact, snapshots, and artifacts.
 *   - CHANGE: Revision email now formats classifier details and artifact lists with line breaks and emphasis for easier scanning.
 *
 * v004 (4/29/2026)
 *   - CHANGE: Added changedAllDriversCount support from classifier output.
 *   - CHANGE: revision_meta.json now stores changed_all_drivers_count.
 *   - CHANGE: Browser/log/email summary now includes changed all drivers count.
 *
 * v003 (4/26/2026)
 *   - NEW: Added safe include support for race_results_classify_revisions.php so revision monitor can call the classifier directly.
 *   - NEW: Added revision metadata artifact writing to revision_meta.json for downstream UI / audit use.
 *   - NEW: Added visible revision sequencing (Rev A / Rev B / etc.) that increments only for MRL-impacting revisions.
 *   - CHANGE: Revision email now includes classifier result summary when available.
 *   - CHANGE: Revision monitor now records previous/current snapshot names and classifier artifact paths in metadata.
 *   - CHANGE: Added fetch diagnostics for latest-race detection failures and no-scoring-table responses.
 *   - CHANGE: Browser/log output now reports fetched URL, HTTP status, HTML bytes, page title, and basic HTML markers when parsing fails.
 *
 * v002 (4/15/2026)
 *   - CHANGE: rrrev_get_completed_races() now filters to kind="R" (points races only).
 *     Exhibition races (kind="E") are excluded from revision scanning.
 *   - CHANGE: Uses 'number' field from year index (not 'race_number' or 'sort_order').
 *
 * v001 (4/15/2026)
 *   - Initial build.
 *   - Scans all completed current-season races (except the latest/live race).
 *   - Compares current ESPN scoring table hash against stored final_table_hash.txt.
 *   - On revision detected: saves new timestamped snapshot, updates hash,
 *     creates under_review.flag, sends immediate email alert.
 *   - Runs safely via cron (CLI) or browser.
 *   - Follows race_results_monitor.php architectural style.
 *   - Uses race_results_engine.php shared helpers throughout.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_race_results_revision_monitor_php_errors.log');
error_reporting(E_ALL);

const RR_REVISION_MONITOR_SIGNATURE = 'RACE_RESULTS_REVISION_MONITOR v007';

require_once __DIR__ . '/race_results_engine.php';

// ------------------------- DOCUMENT ROOT + INCLUDES -------------------------
$docRoot = rr_docroot_from_script_dir(__DIR__);

// CLI/cron safety
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}

require_once $docRoot . '/config.php';
require_once $docRoot . '/config_mrl.php';
require_once $docRoot . '/class.user.php';
require_once __DIR__ . '/race_results_classify_revisions.php';

$user_home = new USER();

// ------------------------- SETTINGS -------------------------
$year = 2026; // default; can be overridden by CLI arg

$notifyEmail = 'stevekenney318@gmail.com';

// Base files (in this folder)
$logFile       = __DIR__ . '/_race_results_revision_monitor.log';
$heartbeatFile = __DIR__ . '/_race_results_revision_monitor_heartbeat.txt';

// Year index produced by backfill
$yearIndexFile = __DIR__ . '/' . (string)$year . '/_year_index.json';

// Fetch behavior
$timeoutSeconds = 25;

// Snapshot behavior
$snapshotsEnabled = true;
$snapshotMaxBytes = 3000000; // PHP 7.3 safe

// Optional CLI override: php race_results_revision_monitor.php 2026
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && count($argv) >= 2) {
    $cliYear = (int)$argv[1];
    if ($cliYear >= 2000 && $cliYear <= 2100) {
        $year = $cliYear;
    }
    $yearIndexFile = __DIR__ . '/' . (string)$year . '/_year_index.json';
}

// ------------------------- OUTPUT HELPER -------------------------
function rrrev_out(string $line): void
{
    if (PHP_SAPI === 'cli') return;
    echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "<br>\n";
}

// ------------------------- YEAR INDEX HELPERS -------------------------
function rrrev_load_year_index(string $path): array
{
    $idx = rr_load_json($path);
    if (!is_array($idx)) return [];
    if (!isset($idx['races']) || !is_array($idx['races'])) return [];
    return $idx;
}

/**
 * Return all completed points races (kind="R") from the year index as an ordered array.
 * Exhibition races (kind="E") are intentionally excluded.
 * Each entry: ['race_id'=>'...', 'folder'=>'...', 'race_url'=>'...', 'race_name'=>'...']
 */
function rrrev_get_completed_races(array $yearIndex, string $yearFolder): array
{
    if (empty($yearIndex['races'])) return [];

    $races = [];

    foreach ($yearIndex['races'] as $raceId => $info) {
        if (!is_array($info)) continue;

        // Points races only — skip exhibitions (kind="E") and anomalies (kind="Z")
        $kind = (string)($info['kind'] ?? '');
        if ($kind !== 'R') continue;

        $folder = (string)($info['folder'] ?? '');
        if ($folder === '') continue;

        $raceUrl = (string)($info['race_url'] ?? '');
        if ($raceUrl === '') continue;

        $raceName = (string)($info['race_name'] ?? 'Race');
        $raceNum  = isset($info['number']) ? (int)$info['number'] : 0;

        // Only include races whose folder exists on disk (completed/known races)
        $fullFolder = $yearFolder . '/' . $folder;
        if (!is_dir($fullFolder)) continue;

        $races[] = [
            'race_id'     => (string)$raceId,
            'folder'      => $folder,
            'full_folder' => $fullFolder,
            'race_url'    => $raceUrl,
            'race_name'   => $raceName,
            'race_number' => $raceNum,
        ];
    }

    // Sort by race number ascending (chronological order)
    usort($races, function(array $a, array $b): int {
        return $a['race_number'] <=> $b['race_number'];
    });

    return $races;
}

function rrrev_race_code_from_folder(string $folderName): string
{
    if (preg_match('/^([REZ]\d+)/i', $folderName, $m)) {
        return strtoupper((string)$m[1]);
    }

    return '';
}

function rrrev_revision_letter(int $index): string
{
    if ($index <= 0) {
        return '';
    }

    $letter = '';
    $n = $index;

    while ($n > 0) {
        $n--;
        $letter = chr(65 + ($n % 26)) . $letter;
        $n = (int)floor($n / 26);
    }

    return $letter;
}

function rrrev_read_revision_meta(string $raceFolder): array
{
    $path = rtrim($raceFolder, '/\\') . '/revision_meta.json';
    $data = rr_load_json($path);

    return is_array($data) ? $data : [];
}

function rrrev_write_revision_meta(string $raceFolder, array $meta): string
{
    $path = rtrim($raceFolder, '/\\') . '/revision_meta.json';
    rr_atomic_write($path, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    return $path;
}

function rrrev_extract_title(string $html): string
{
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        $title = html_entity_decode(strip_tags((string)$m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = trim(preg_replace('/\s+/', ' ', $title));
        return (string)$title;
    }

    return '';
}

function rrrev_build_fetch_debug(string $html, array $details = []): array
{
    $debug = [
        'html_bytes' => strlen($html),
        'title' => rrrev_extract_title($html),
        'table_count' => 0,
        'has___NEXT_DATA__' => (strpos($html, '__NEXT_DATA__') !== false),
        'has_raceresults_path' => (strpos($html, '/racing/raceresults') !== false),
        'has_raceId_path' => (strpos($html, '/raceId/') !== false),
        'header_row_found' => (bool)($details['headerRowFound'] ?? false),
        'tables_found_by_detector' => (int)($details['tablesFound'] ?? 0),
        'rows_checked' => (int)($details['rowsChecked'] ?? 0),
        'non_zero_counts' => isset($details['nonZeroCounts']) && is_array($details['nonZeroCounts']) ? $details['nonZeroCounts'] : [],
        'table_hash' => (string)($details['tableHash'] ?? ''),
        'html_snippet' => substr(trim(preg_replace('/\s+/', ' ', strip_tags($html))), 0, 300),
    ];

    if (preg_match_all('~<table\b~i', $html, $m)) {
        $debug['table_count'] = is_array($m) && isset($m[0]) ? count($m[0]) : 0;
    }

    return $debug;
}

function rrrev_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function rrrev_send_email(USER $user_home, string $notifyEmail, string $message, string $subject, string $logFile, string $raceId, string $folderName, string $emailType): bool
{
    $sentOk = false;
    try {
        $sentOk = (bool)$user_home->send_mail($notifyEmail, $message, $subject);
    } catch (Throwable $e) {
        rr_log_line($logFile, "EMAIL EXCEPTION type={$emailType} raceId={$raceId}: " . $e->getMessage());
        $sentOk = false;
    }

    rr_log_line(
        $logFile,
        $sentOk
            ? "EMAIL SENT ({$emailType}) to={$notifyEmail} raceId={$raceId} folder={$folderName}"
            : "EMAIL FAILED ({$emailType}) to={$notifyEmail} raceId={$raceId} folder={$folderName}"
    );

    return $sentOk;
}

function rrrev_build_revision_email_html(
    int $year,
    string $raceLabel,
    string $folderName,
    string $raceUrl,
    string $storedHash,
    string $currentHash,
    string $impactLine,
    string $visibleLine,
    int $changedMrlDrivers,
    int $changedAllDrivers,
    int $driverPoolCount,
    string $previousSnapshot,
    string $currentSnapshot,
    string $revisionMetaPath,
    array $artifactFiles,
    bool $reviewRequired,
    string $statusLine
): string {
    $html = '';
    $html .= '<div style="font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.45; color: #222;">';
    $html .= '<p style="margin:0 0 16px 0;">A revision check detected a changed scoring-table hash for a previously completed race.</p>';

    $html .= '<table style="border-collapse: collapse; margin: 0 0 18px 0;">';
    $rows = [
        'Year' => (string)$year,
        'Race' => $raceLabel,
        'Folder' => $folderName,
        'URL' => $raceUrl,
        'Stored hash' => $storedHash,
        'New hash' => $currentHash,
        'MRL impact' => $impactLine,
        'Visible rev' => $visibleLine,
        'Review status' => $statusLine,
        'Changed MRL drivers' => (string)$changedMrlDrivers,
        'Changed all drivers' => (string)$changedAllDrivers,
        'Driver pool' => (string)$driverPoolCount,
        'Previous snapshot' => $previousSnapshot,
        'Current snapshot' => $currentSnapshot,
        'revision_meta.json' => basename($revisionMetaPath),
    ];

    foreach ($rows as $label => $value) {
        $html .= '<tr>';
        $html .= '<td style="padding: 2px 12px 2px 0; vertical-align: top;"><strong>' . rrrev_html($label) . ':</strong></td>';
        if ($label === 'URL') {
            $html .= '<td style="padding: 2px 0;"><a href="' . rrrev_html($value) . '">' . rrrev_html($value) . '</a></td>';
        } else {
            $html .= '<td style="padding: 2px 0;">' . rrrev_html($value) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';

    if (!empty($artifactFiles)) {
        $html .= '<p style="margin: 0 0 6px 0;"><strong>Classifier artifacts</strong></p>';
        $html .= '<ul style="margin: 0 0 18px 20px; padding: 0;">';
        foreach ($artifactFiles as $label => $artifactPath) {
            $html .= '<li><strong>' . rrrev_html((string)$label) . ':</strong> ' . rrrev_html(basename((string)$artifactPath)) . '</li>';
        }
        $html .= '</ul>';
    }

    if ($reviewRequired) {
        $html .= '<p style="margin: 0 0 6px 0;">A new snapshot has been saved and the race has been flagged as <strong>Under Review</strong>.</p>';
        $html .= '<p style="margin: 0 0 16px 0;">Please check the race folder and review the change before accepting revised standings.</p>';
    } else {
        $html .= '<p style="margin: 0 0 6px 0;">A new snapshot has been saved for audit history, but this race was <strong>not</strong> flagged as Under Review.</p>';
        $html .= '<p style="margin: 0 0 16px 0;">No visible Rev label is required unless a later review finds league-relevant scoring changed.</p>';
    }

    $html .= '<p style="margin: 0; color: #666; font-size: 13px;">Run: ' . rrrev_html(rr_now_local_string()) . '<br>';
    $html .= 'Sig: ' . rrrev_html(RR_REVISION_MONITOR_SIGNATURE) . '</p>';
    $html .= '</div>';

    return $html;
}

// ------------------------- MAIN -------------------------
$scriptSha = rr_sha256_file_string(__FILE__);
$token     = bin2hex(random_bytes(8));

$hb = rr_now_local_string()
    . "  token={$token}"
    . "  sig=" . RR_REVISION_MONITOR_SIGNATURE
    . "  year={$year}"
    . "  sapi=" . PHP_SAPI
    . "  sha={$scriptSha}";

rr_atomic_write($heartbeatFile, $hb . "\n");

rr_log_line($logFile, RR_REVISION_MONITOR_SIGNATURE . " RUN year={$year} sapi=" . PHP_SAPI . " sha={$scriptSha} token={$token}");

rrrev_out("=== " . RR_REVISION_MONITOR_SIGNATURE . " ===");
rrrev_out("Year: {$year}");
rrrev_out("Run started: " . rr_now_local_string());
rrrev_out("---");

// ------------------------- LOAD YEAR INDEX -------------------------
if (!is_file($yearIndexFile)) {
    $msg = "Year index not found: {$yearIndexFile}";
    rr_log_line($logFile, "ABORT: {$msg}");
    rrrev_out("ABORT: {$msg}");
    exit(0);
}

$yKey       = (string)$year;
$yearFolder = __DIR__ . '/' . $yKey;
$yearIndex  = rrrev_load_year_index($yearIndexFile);

if (empty($yearIndex)) {
    $msg = "Year index empty or invalid: {$yearIndexFile}";
    rr_log_line($logFile, "ABORT: {$msg}");
    rrrev_out("ABORT: {$msg}");
    exit(0);
}

// ------------------------- FIND THE LATEST (LIVE) RACE URL -------------------------
// We skip the latest race — the live monitor owns that one.
[$okLatest, $latestUrl, $errLatest, $latestDebug] = rr_find_latest_race_results_url($year, $timeoutSeconds);

$skipUrl = '';
$skipRaceId = '';
if ($okLatest && $latestUrl !== '') {
    $skipUrl = $latestUrl;
    $skipRaceId = rr_extract_race_id_from_url($latestUrl);
    rr_log_line($logFile, "LATEST (live) race URL identified - will skip: {$latestUrl}");
    rrrev_out("Skipping latest/live race (owned by live monitor): " . $skipRaceId);
} else {
    // Non-fatal: if we can't determine the latest, log a warning but continue.
    // Worst case we check all races including the live one — harmless for revision detection.
    $latestDiag = is_array($latestDebug ?? null) ? json_encode($latestDebug, JSON_UNESCAPED_SLASHES) : '';
    rr_log_line($logFile, "WARNING: Could not determine latest race URL ({$errLatest}) - will scan all known races. debug={$latestDiag}");
    rrrev_out("WARNING: Could not determine latest race URL. Scanning all known races.");
    if (!empty($latestDebug) && is_array($latestDebug)) {
        rrrev_out("Latest-race diagnostics: HTTP " . (string)($latestDebug['httpStatus'] ?? '') . " / bytes " . (string)($latestDebug['htmlBytes'] ?? '') . " / races found " . (string)($latestDebug['raceCount'] ?? ''));
    }
}

rrrev_out("---");

// ------------------------- GET COMPLETED RACE LIST -------------------------
$completedRaces = rrrev_get_completed_races($yearIndex, $yearFolder);

if (count($completedRaces) === 0) {
    $msg = "No completed races found in year index for {$year}.";
    rr_log_line($logFile, $msg);
    rrrev_out($msg);
    exit(0);
}

rrrev_out("Completed races found in year index: " . count($completedRaces));
rrrev_out("---");

// ------------------------- SCAN EACH COMPLETED RACE -------------------------
$scanned    = 0;
$skipped    = 0;
$unchanged  = 0;
$revised    = 0;
$errors     = 0;

foreach ($completedRaces as $race) {

    $raceId     = $race['race_id'];
    $raceUrl    = $race['race_url'];
    $raceName   = $race['race_name'];
    $raceFolder = $race['full_folder'];
    $folderName = $race['folder'];
    $raceCode   = rrrev_race_code_from_folder($folderName);

    // Skip the live/latest race
    if ($skipUrl !== '' && (string)$raceUrl === (string)$skipUrl) {
        $skipped++;
        rr_log_line($logFile, "SKIP (latest/live) raceId={$raceId} folder={$folderName}");
        rrrev_out("SKIP (latest/live): {$folderName}");
        continue;
    }

    // Also skip by raceId match as a secondary guard
    if ($skipRaceId !== '' && $raceId === $skipRaceId) {
        $skipped++;
        rr_log_line($logFile, "SKIP (latest/live by raceId) raceId={$raceId} folder={$folderName}");
        rrrev_out("SKIP (latest/live): {$folderName}");
        continue;
    }

    $scanned++;
    rrrev_out("Checking [{$scanned}]: {$folderName}");
    rrrev_out("  URL: {$raceUrl}");

    // Load stored hash
    $hashFilePath  = $raceFolder . '/final_table_hash.txt';
    $storedHash    = '';

    if (is_file($hashFilePath)) {
        $storedHash = trim((string)@file_get_contents($hashFilePath));
    }

    if ($storedHash === '') {
        // No stored hash — race folder exists but was never finalized through monitor.
        // Log and skip; we only revision-check races that already have a known-good baseline.
        $errors++;
        rr_log_line($logFile, "SKIP (no baseline hash) raceId={$raceId} folder={$folderName}");
        rrrev_out("  SKIP: No baseline hash on file - race not yet finalized in system.");
        continue;
    }

    // Fetch current ESPN race page
    [$okFetch, $statusFetch, $html, $errFetch] = rr_fetch_url($raceUrl, $timeoutSeconds);

    if (!$okFetch) {
        $errors++;
        rr_log_line($logFile, "FETCH ERROR raceId={$raceId} HTTP={$statusFetch} err={$errFetch} url={$raceUrl}");
        rrrev_out("  ERROR fetching ESPN page (HTTP {$statusFetch}): {$errFetch}");
        continue;
    }

    // Detect and hash current scoring table
    [$isFinal, $reason, $details] = rr_detect_final_scoring_nonzero($html);
    $currentHash = (string)($details['tableHash'] ?? '');

    if (!$isFinal || $currentHash === '') {
        // Page didn't return a valid scoring table — could be a temporary ESPN issue.
        // Log but do NOT treat as revision.
        $errors++;
        $fetchDebug = rrrev_build_fetch_debug($html, $details);
        rr_log_line(
            $logFile,
            "NO SCORING TABLE raceId={$raceId} folder={$folderName} url={$raceUrl} http={$statusFetch} reason={$reason} debug="
            . json_encode($fetchDebug, JSON_UNESCAPED_SLASHES)
        );
        rrrev_out("  SKIP: No valid scoring table returned - reason: {$reason}");
        rrrev_out("  Fetch diagnostics: HTTP {$statusFetch} / bytes " . (string)$fetchDebug['html_bytes'] . " / tables " . (string)$fetchDebug['table_count']);
        if ($fetchDebug['title'] !== '') {
            rrrev_out("  Title: " . (string)$fetchDebug['title']);
        }
        continue;
    }

    // Compare hashes
    if (hash_equals($storedHash, $currentHash)) {
        $unchanged++;
        rr_log_line($logFile, "UNCHANGED raceId={$raceId} folder={$folderName}");
        rrrev_out("  Unchanged.");
        continue;
    }

    // ---- REVISION / PAGE HASH CHANGE DETECTED ----
    $revised++;

    rr_log_line($logFile, "CHANGE DETECTED raceId={$raceId} folder={$folderName} storedHash={$storedHash} newHash={$currentHash}");
    rrrev_out("  *** CHANGE DETECTED: {$folderName} ***");

    $previousSnapshotBase = '';
    $currentSnapshotBase = '';

    // 1. Save new timestamped snapshot
    if ($snapshotsEnabled) {
        $tsFile = rr_preferred_timestamp(true);
        rr_save_snapshot_html($raceFolder, $tsFile, $html, $snapshotMaxBytes);
        rr_save_snapshot_summary($raceFolder, $tsFile, $html);
        $currentSnapshotBase = 'snapshot_' . $tsFile . '.html';
        rr_log_line($logFile, "SNAPSHOT SAVED folder={$folderName} ts={$tsFile}");
        rrrev_out("  Snapshot saved: snapshot_{$tsFile}");
    }

    $snapshotFiles = glob($raceFolder . '/snapshot_*.html');
    if (is_array($snapshotFiles) && !empty($snapshotFiles)) {
        sort($snapshotFiles, SORT_STRING);
        $countSnapshots = count($snapshotFiles);
        if ($countSnapshots >= 2) {
            $previousSnapshotBase = basename((string)$snapshotFiles[$countSnapshots - 2]);
            if ($currentSnapshotBase === '') {
                $currentSnapshotBase = basename((string)$snapshotFiles[$countSnapshots - 1]);
            }
        } elseif ($currentSnapshotBase === '') {
            $currentSnapshotBase = basename((string)$snapshotFiles[$countSnapshots - 1]);
        }
    }

    // 2. Update stored hash after saving the audit snapshot.
    rr_atomic_write($hashFilePath, $currentHash . "\n");
    rr_log_line($logFile, "HASH UPDATED folder={$folderName}");

    // 3. Classify MRL impact using the latest snapshot pair.
    $classification = [
        'classified' => false,
        'impact' => false,
        'changedDriversCount' => 0,
        'changedAllDriversCount' => 0,
        'changedMrlListedDriversCount' => 0,
        'changedSegmentPickedDriversCount' => 0,
        'driverPoolCount' => 0,
        'mrlListedDriverPoolCount' => 0,
        'change_detected' => true,
        'driver_scoring_change_detected' => false,
        'revision_required' => true,
        'review_required' => true,
        'change_status' => 'detected_unclassified',
        'change_status_label' => 'Classification unavailable - review required',
        'message' => 'Classification not run.',
        'artifactFiles' => [],
        'comparison' => [],
        'previousSnapshot' => $previousSnapshotBase,
        'currentSnapshot' => $currentSnapshotBase,
    ];

    if (function_exists('rrcr_run_single_race') && isset($dbo) && ($dbo instanceof PDO) && $raceCode !== '') {
        try {
            $classification = rrcr_run_single_race((string)$year, $raceCode, $dbo, true, false);
            rr_log_line(
                $logFile,
                "CLASSIFICATION raceCode={$raceCode} classified="
                . (!empty($classification['classified']) ? 'YES' : 'NO')
                . " impact=" . (!empty($classification['impact']) ? 'YES' : 'NO')
                . " changedAllDrivers=" . (string)($classification['changedAllDriversCount'] ?? 0)
                . " changedMRLListedDrivers=" . (string)($classification['changedMrlListedDriversCount'] ?? 0)
                . " changedSegmentPickedDrivers=" . (string)($classification['changedSegmentPickedDriversCount'] ?? ($classification['changedDriversCount'] ?? 0))
                . " revisionRequired=" . (!empty($classification['revision_required']) ? 'YES' : 'NO')
                . " reviewRequired=" . (!empty($classification['review_required']) ? 'YES' : 'NO')
            );
            rrrev_out(
                "  Classification: "
                . (!empty($classification['classified']) ? 'YES' : 'NO')
                . " / Impact: "
                . (!empty($classification['impact']) ? 'YES' : 'NO')
                . " / Changed All: "
                . (string)($classification['changedAllDriversCount'] ?? 0)
                . " / MRL-Listed: "
                . (string)($classification['changedMrlListedDriversCount'] ?? 0)
                . " / Segment-Picked: "
                . (string)($classification['changedSegmentPickedDriversCount'] ?? ($classification['changedDriversCount'] ?? 0))
            );
        } catch (Throwable $e) {
            $classification['message'] = 'Classification exception: ' . $e->getMessage();
            rr_log_line($logFile, "CLASSIFICATION EXCEPTION raceCode={$raceCode}: " . $e->getMessage());
            rrrev_out("  Classification exception logged.");
        }
    } else {
        $classification['message'] = 'Classification skipped (rrcr_run_single_race unavailable, PDO missing, or raceCode missing).';
        rr_log_line($logFile, "CLASSIFICATION SKIPPED raceId={$raceId} folder={$folderName}");
        rrrev_out("  Classification skipped.");
    }

    // 4. Decide review/notification handling after classification.
    $classified = !empty($classification['classified']);
    $mrlImpact = !empty($classification['impact']);
    $changedMrlDrivers = (int)($classification['changedDriversCount'] ?? 0); // legacy alias: segment-picked drivers
    $changedSegmentPickedDrivers = (int)($classification['changedSegmentPickedDriversCount'] ?? $changedMrlDrivers);
    $changedMrlListedDrivers = (int)($classification['changedMrlListedDriversCount'] ?? 0);
    $changedAllDrivers = (int)($classification['changedAllDriversCount'] ?? 0);
    $driverPoolCount = (int)($classification['driverPoolCount'] ?? 0);
    $mrlListedDriverPoolCount = (int)($classification['mrlListedDriverPoolCount'] ?? 0);

    $classificationUnknown = !$classified;

    $driverScoringChangeDetected = array_key_exists('driver_scoring_change_detected', $classification)
        ? !empty($classification['driver_scoring_change_detected'])
        : ($changedAllDrivers > 0);

    $revisionRequired = array_key_exists('revision_required', $classification)
        ? !empty($classification['revision_required'])
        : ($mrlImpact || $classificationUnknown);

    $reviewRequired = array_key_exists('review_required', $classification)
        ? !empty($classification['review_required'])
        : ($mrlImpact || $classificationUnknown);

    $status = (string)($classification['change_status'] ?? '');
    $statusLine = (string)($classification['change_status_label'] ?? '');

    if ($status === '' || $statusLine === '') {
        if ($classificationUnknown) {
            $status = 'detected_unclassified';
            $statusLine = 'Classification unavailable - review required';
        } elseif ($mrlImpact) {
            $status = 'pending_review_mrl_impact';
            $statusLine = 'Pending Review - MRL Impact';
        } elseif (!$driverScoringChangeDetected) {
            $status = 'detected_page_only_change';
            $statusLine = 'Page/Table Hash Changed - No Driver Scoring Change';
        } elseif ($changedMrlListedDrivers > 0 && $changedSegmentPickedDrivers === 0) {
            $status = 'detected_mrl_listed_not_segment_picked';
            $statusLine = 'MRL-Listed Driver Changed - No Segment Impact';
        } elseif ($changedAllDrivers > 0 && $changedMrlListedDrivers === 0) {
            $status = 'detected_non_mrl_driver_change';
            $statusLine = 'Non-MRL Driver Change';
        } else {
            $status = 'detected_no_mrl_impact';
            $statusLine = 'No review required - no MRL impact';
        }
    }

    $nonScoringChange = ($classified && !$driverScoringChangeDetected);
    $noReviewScoringChange = ($classified && $driverScoringChangeDetected && !$reviewRequired);

    $sendNormalRevisionEmail = $reviewRequired;
    $sendNoMrlImpactEmail = $noReviewScoringChange;
    $sendNoEmail = $nonScoringChange;

    rrrev_out(
        "  Decision: Revision Required: "
        . ($revisionRequired ? 'YES' : 'NO')
        . " / Review Required: "
        . ($reviewRequired ? 'YES' : 'NO')
    );

    // 5. Create under_review.flag only when review is required.
    $flagPath = $raceFolder . '/under_review.flag';
    if ($reviewRequired) {
        rr_atomic_write($flagPath, "");
        rr_log_line($logFile, "UNDER_REVIEW FLAG SET folder={$folderName} reason={$status}");
        rrrev_out("  under_review.flag created.");
    } else {
        if (is_file($flagPath)) {
            @unlink($flagPath);
            rr_log_line($logFile, "UNDER_REVIEW FLAG REMOVED folder={$folderName} reason={$status}");
            rrrev_out("  under_review.flag removed/not required.");
        } else {
            rr_log_line($logFile, "UNDER_REVIEW FLAG NOT SET folder={$folderName} reason={$status}");
            rrrev_out("  under_review.flag not required.");
        }
    }

    // 6. Write revision metadata artifact.
    $existingMeta = rrrev_read_revision_meta($raceFolder);
    $detectedRevisionCount = (int)($existingMeta['detected_revision_count_total'] ?? 0) + 1;
    $visibleRevisionCount  = (int)($existingMeta['visible_revision_count'] ?? 0);

    if ($mrlImpact) {
        $visibleRevisionCount++;
    }

    $revisionLetter = $mrlImpact ? rrrev_revision_letter($visibleRevisionCount) : '';
    $displayTag = $mrlImpact && $revisionLetter !== '' ? 'Rev ' . $revisionLetter : '';

    $revisionMeta = [
        'year' => (string)$year,
        'race_id' => (string)$raceId,
        'race_code' => (string)$raceCode,
        'race_name' => (string)$raceName,
        'race_folder' => (string)$folderName,
        'revision_type' => 'source',
        'status' => $status,
        'status_label' => $statusLine,
        'pending_review' => $reviewRequired,
        'under_review_flag' => $reviewRequired,
        'revision_detected' => true,
        'change_detected' => true,
        'driver_scoring_change_detected' => $driverScoringChangeDetected,
        'revision_required' => $revisionRequired,
        'review_required' => $reviewRequired,
        'change_status' => $status,
        'change_status_label' => $statusLine,
        'non_scoring_change' => $nonScoringChange,
        'no_review_scoring_change' => $noReviewScoringChange,
        'classification_unknown' => $classificationUnknown,
        'mrl_impact' => $mrlImpact,
        'display_rev' => $revisionRequired && $mrlImpact,
        'detected_revision_count_total' => $detectedRevisionCount,
        'visible_revision_count' => $visibleRevisionCount,
        'revision_letter' => $revisionLetter,
        'display_tag' => $displayTag,
        'first_detected_at' => isset($existingMeta['first_detected_at']) && $existingMeta['first_detected_at'] !== ''
            ? (string)$existingMeta['first_detected_at']
            : rr_now_local_string(),
        'last_detected_at' => rr_now_local_string(),
        'previous_snapshot' => (string)($classification['previousSnapshot'] ?? $previousSnapshotBase),
        'current_snapshot' => (string)($classification['currentSnapshot'] ?? $currentSnapshotBase),
        'stored_hash_before' => $storedHash,
        'stored_hash_after' => $currentHash,
        'changed_drivers_count' => $changedMrlDrivers,
        'changed_segment_picked_drivers_count' => $changedSegmentPickedDrivers,
        'changed_mrl_listed_drivers_count' => $changedMrlListedDrivers,
        'changed_all_drivers_count' => $changedAllDrivers,
        'driver_pool_count' => $driverPoolCount,
        'mrl_listed_driver_pool_count' => $mrlListedDriverPoolCount,
        'classifier_message' => (string)($classification['message'] ?? ''),
        'artifact_files' => isset($classification['artifactFiles']) && is_array($classification['artifactFiles'])
            ? $classification['artifactFiles']
            : [],
    ];

    $revisionMetaPath = rrrev_write_revision_meta($raceFolder, $revisionMeta);
    rr_log_line($logFile, "REVISION META WRITTEN folder={$folderName} path={$revisionMetaPath} status={$status} pending=" . ($reviewRequired ? 'YES' : 'NO'));
    rrrev_out("  revision_meta.json updated.");
    rrrev_out("  Status: {$statusLine}");

    // 7. Send email only when useful.
    $raceLabel = $raceName !== '' ? $raceName : $folderName;
    $shortCode = $raceCode !== '' ? ($raceCode . ' ') : '';
    $impactLine = $mrlImpact ? 'YES' : 'NO';
    $visibleLine = $displayTag !== '' ? $displayTag : 'none';

    $message = rrrev_build_revision_email_html(
        (int)$year,
        $raceLabel,
        $folderName,
        $raceUrl,
        $storedHash,
        $currentHash,
        $impactLine,
        $visibleLine,
        $changedMrlDrivers,
        $changedAllDrivers,
        $driverPoolCount,
        (string)($revisionMeta['previous_snapshot'] ?? ''),
        (string)($revisionMeta['current_snapshot'] ?? ''),
        $revisionMetaPath,
        isset($classification['artifactFiles']) && is_array($classification['artifactFiles']) ? $classification['artifactFiles'] : [],
        $reviewRequired,
        $statusLine
    );

    if ($sendNormalRevisionEmail) {
        $subject = '[MRL] REVISION DETECTED - ' . $year . ' ' . trim($shortCode . $raceLabel);
        if ($displayTag !== '') {
            $subject .= ' (' . $displayTag . ')';
        }

        $sentOk = rrrev_send_email($user_home, $notifyEmail, $message, $subject, $logFile, (string)$raceId, (string)$folderName, 'REVISION');
        rrrev_out("  Email: " . ($sentOk ? "SENT to {$notifyEmail}" : "FAILED (check log)"));
    } elseif ($sendNoMrlImpactEmail) {
        $subject = '[MRL] ESPN CHANGE DETECTED - NO MRL IMPACT - ' . $year . ' ' . trim($shortCode . $raceLabel);
        $sentOk = rrrev_send_email($user_home, $notifyEmail, $message, $subject, $logFile, (string)$raceId, (string)$folderName, 'NO_MRL_IMPACT');
        rrrev_out("  Email: " . ($sentOk ? "SENT no-MRL-impact notice to {$notifyEmail}" : "FAILED (check log)"));
    } elseif ($sendNoEmail) {
        rr_log_line($logFile, "EMAIL SKIPPED (NON_SCORING_CHANGE) raceId={$raceId} folder={$folderName}");
        rrrev_out("  Email: SKIPPED (no scoring changes).");
    } else {
        rr_log_line($logFile, "EMAIL SKIPPED raceId={$raceId} folder={$folderName} status={$status}");
        rrrev_out("  Email: SKIPPED.");
    }

} // end foreach race

// ------------------------- SUMMARY -------------------------
rrrev_out("---");
rrrev_out("Run complete: " . rr_now_local_string());
rrrev_out("Races scanned : {$scanned}");
rrrev_out("Skipped       : {$skipped}");
rrrev_out("Unchanged     : {$unchanged}");
rrrev_out("Revised       : {$revised}");
rrrev_out("Errors/skips  : {$errors}");

rr_log_line(
    $logFile,
    RR_REVISION_MONITOR_SIGNATURE . " DONE"
    . " scanned={$scanned}"
    . " skipped={$skipped}"
    . " unchanged={$unchanged}"
    . " revised={$revised}"
    . " errors={$errors}"
    . " token={$token}"
);

exit(0);
