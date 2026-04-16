<?php
declare(strict_types=1);

/**
 * race_results_revision_monitor.php
 *
 * VERSION: v002
 * LAST MODIFIED: 4/15/2026 4:50:54am
 *
 * CHANGELOG:
 * v002 (2026-04-15)
 *   - CHANGE: rrrev_get_completed_races() now filters to kind="R" (points races only).
 *     Exhibition races (kind="E") are excluded from revision scanning.
 *   - CHANGE: Uses 'number' field from year index (not 'race_number' or 'sort_order').
 *
 * v001 (2026-04-15)
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

const RR_REVISION_MONITOR_SIGNATURE = 'RACE_RESULTS_REVISION_MONITOR v002';

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

$yKey      = (string)$year;
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
[$okLatest, $latestUrl, $errLatest] = rr_find_latest_race_results_url($year, $timeoutSeconds);

$skipUrl = '';
if ($okLatest && $latestUrl !== '') {
    $skipUrl = $latestUrl;
    $skipRaceId = rr_extract_race_id_from_url($latestUrl);
    rr_log_line($logFile, "LATEST (live) race URL identified — will skip: {$latestUrl}");
    rrrev_out("Skipping latest/live race (owned by live monitor): " . $skipRaceId);
} else {
    // Non-fatal: if we can't determine the latest, log a warning but continue.
    // Worst case we check all races including the live one — harmless for revision detection.
    rr_log_line($logFile, "WARNING: Could not determine latest race URL ({$errLatest}) — will scan all known races.");
    rrrev_out("WARNING: Could not determine latest race URL. Scanning all known races.");
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

    // Skip the live/latest race
    if ($skipUrl !== '' && (string)$raceUrl === (string)$skipUrl) {
        $skipped++;
        rr_log_line($logFile, "SKIP (latest/live) raceId={$raceId} folder={$folderName}");
        rrrev_out("SKIP (latest/live): {$folderName}");
        continue;
    }

    // Also skip by raceId match as a secondary guard
    if (isset($skipRaceId) && $skipRaceId !== '' && $raceId === $skipRaceId) {
        $skipped++;
        rr_log_line($logFile, "SKIP (latest/live by raceId) raceId={$raceId} folder={$folderName}");
        rrrev_out("SKIP (latest/live): {$folderName}");
        continue;
    }

    $scanned++;
    rrrev_out("Checking [{$scanned}]: {$folderName}");

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
        rrrev_out("  SKIP: No baseline hash on file — race not yet finalized in system.");
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
        rr_log_line($logFile, "NO SCORING TABLE raceId={$raceId} reason={$reason} folder={$folderName}");
        rrrev_out("  SKIP: No valid scoring table returned — reason: {$reason}");
        continue;
    }

    // Compare hashes
    if (hash_equals($storedHash, $currentHash)) {
        $unchanged++;
        rr_log_line($logFile, "UNCHANGED raceId={$raceId} folder={$folderName}");
        rrrev_out("  Unchanged.");
        continue;
    }

    // ---- REVISION DETECTED ----
    $revised++;

    rr_log_line($logFile, "REVISION DETECTED raceId={$raceId} folder={$folderName} storedHash={$storedHash} newHash={$currentHash}");
    rrrev_out("  *** REVISION DETECTED: {$folderName} ***");

    // 1. Save new timestamped snapshot
    if ($snapshotsEnabled) {
        $tsFile = rr_preferred_timestamp(true);
        rr_save_snapshot_html($raceFolder, $tsFile, $html, $snapshotMaxBytes);
        rr_save_snapshot_summary($raceFolder, $tsFile, $html);
        rr_log_line($logFile, "SNAPSHOT SAVED folder={$folderName} ts={$tsFile}");
        rrrev_out("  Snapshot saved: snapshot_{$tsFile}");
    }

    // 2. Update stored hash
    rr_atomic_write($hashFilePath, $currentHash . "\n");
    rr_log_line($logFile, "HASH UPDATED folder={$folderName}");

    // 3. Create under_review.flag
    $flagPath = $raceFolder . '/under_review.flag';
    rr_atomic_write($flagPath, rr_now_local_string() . "\n");
    rr_log_line($logFile, "UNDER_REVIEW FLAG SET folder={$folderName}");
    rrrev_out("  under_review.flag created.");

    // 4. Send email alert
    $raceCode = strtoupper(basename($folderName)); // e.g. R08_Bristol_...

    // Build a human-friendly race label for the subject line
    $raceLabel = $raceName !== '' ? $raceName : $folderName;

    // Derive short race code from folder prefix (e.g. "R08" from "R08_Bristol_...")
    $shortCode = '';
    if (preg_match('/^([REZ]\d+)/i', $folderName, $cm)) {
        $shortCode = strtoupper($cm[1]) . ' ';
    }

    $subject = "[MRL] REVISION DETECTED – {$year} {$shortCode}{$raceLabel}";

    $message =
        "A revision was detected for a previously completed race.\n\n" .
        "Year      : {$year}\n" .
        "Race      : {$raceLabel}\n" .
        "Folder    : {$folderName}\n" .
        "URL       : {$raceUrl}\n\n" .
        "Stored hash : {$storedHash}\n" .
        "New hash    : {$currentHash}\n\n" .
        "A new snapshot has been saved and the race has been flagged as Under Review.\n" .
        "Please check the race folder and review the change before accepting revised standings.\n\n" .
        "Run: " . rr_now_local_string() . "\n" .
        "Sig: " . RR_REVISION_MONITOR_SIGNATURE . "\n";

    $sentOk = false;
    try {
        $sentOk = (bool)$user_home->send_mail($notifyEmail, $message, $subject);
    } catch (Throwable $e) {
        rr_log_line($logFile, "EMAIL EXCEPTION raceId={$raceId}: " . $e->getMessage());
        $sentOk = false;
    }

    rr_log_line(
        $logFile,
        $sentOk
            ? "EMAIL SENT (REVISION) to={$notifyEmail} raceId={$raceId} folder={$folderName}"
            : "EMAIL FAILED (REVISION) to={$notifyEmail} raceId={$raceId} folder={$folderName}"
    );
    rrrev_out("  Email: " . ($sentOk ? "SENT to {$notifyEmail}" : "FAILED (check log)"));

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
