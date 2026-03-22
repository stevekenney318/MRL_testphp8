<?php
declare(strict_types=1);

/**
 * race_results_backfill.php
 *
 * VERSION: v003
 * LAST MODIFIED: 3/14/2026 5:50:22 AM
 *
 * CHANGELOG:
 * v003 (3/14/2026)
 *   - FIX: Skip empty placeholder race pages during backfill.
 *   - Backfill now ignores race pages that:
 *       - contain "No data available"
 *       - or contain zero real data rows in the results table
 *   - Prevents phantom races (such as the extra 2021 Daytona page)
 *     from being assigned race numbers, folders, snapshots, and year index entries.
 *   - Added explicit log/output message when a race is skipped for empty results.
 *
 * v002
 *   - Prior working version before empty-race placeholder filter.
 *
 * Email:
 *   - OFF by default.
 *
 * Usage (CLI):
 *   php race_results_backfill.php 2017
 *
 * Usage (Browser):
 *   /race_results/race_results_backfill.php?year=2017
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_race_results_backfill_php_errors.log');
error_reporting(E_ALL);

const RR_BACKFILL_SIGNATURE = 'RACE_RESULTS_BACKFILL v003';

require_once __DIR__ . '/race_results_engine.php';

// ------------------------- FILES -------------------------
$stateFile = __DIR__ . '/_race_results_backfill_state.json';
$logFile   = __DIR__ . '/_race_results_backfill.log';

// ------------------------- SHUTDOWN / FATAL LOGGER -------------------------
register_shutdown_function(function () use ($logFile) {
    $err = error_get_last();
    if (!$err) return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$err['type'], $fatalTypes, true)) return;

    $msg = "FATAL: {$err['message']} in {$err['file']}:{$err['line']}";
    rr_log_line($logFile, $msg);

    if (PHP_SAPI !== 'cli') {
        echo "\nERROR: backfill failed (details logged).\n";
    }
});

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
$year = 2026;
$timeoutSeconds = 25;

$notifyEmail  = 'stevekenney318@gmail.com';
$subjectFinal = '[MRL] ESPN Results - FINAL Results Detected';

// Snapshot settings
$snapshotsEnabled = true;
$snapshotMaxBytes = 3000000; // PHP 7.3 safe

// Backfill default: NO email
$emailOnFinal = false;

// ------------------------- YEAR INPUT -------------------------
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && count($argv) >= 2) {
    $cliYear = (int)$argv[1];
    if ($cliYear >= 2000 && $cliYear <= 2100) $year = $cliYear;
} elseif (isset($_GET['year'])) {
    $qYear = (int)$_GET['year'];
    if ($qYear >= 2000 && $qYear <= 2100) $year = $qYear;
}

// Browser output helper
function rr_backfill_out(string $line): void
{
    if (PHP_SAPI === 'cli') return;
    echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "<br>\n";
}

// ------------------------- HELPERS (naming / year index) -------------------------
function rr_points_include_phrases(): array
{
    // Your rule: points races are defined by INCLUDE phrases.
    // Everything else is exhibition/anomaly handling by the backfill logic.
    return [
        'DAYTONA 500',
        'NASCAR CUP SERIES AT',
        'NASCAR_CUP_SERIES_AT', // keep underscore variant just in case any text comes through that way
    ];
}

function rr_is_points_by_include(string $raceName): bool
{
    $u = strtoupper($raceName);
    $phrases = rr_points_include_phrases();
    for ($i = 0; $i < count($phrases); $i++) {
        if (strpos($u, strtoupper($phrases[$i])) !== false) return true;
    }
    return false;
}

function rr_backfill_save_year_index(int $year, string $yearFolder, array $index): void
{
    rr_ensure_dir($yearFolder);
    $path = $yearFolder . '/_year_index.json';
    rr_save_json($path, $index);
}

/**
 * Detect obvious empty / placeholder ESPN results pages.
 *
 * Returns:
 * [
 *   'is_empty' => bool,
 *   'reason' => string,
 *   'data_rows' => int,
 * ]
 */
function rr_backfill_empty_results_info(string $html): array
{
    $info = [
        'is_empty' => false,
        'reason' => '',
        'data_rows' => 0,
    ];

    if ($html === '') {
        $info['is_empty'] = true;
        $info['reason'] = 'Empty HTML response.';
        return $info;
    }

    if (stripos($html, 'No data available') !== false) {
        $info['is_empty'] = true;
        $info['reason'] = 'Page contains "No data available".';
        return $info;
    }

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $loaded = @$dom->loadHTML($html);

    if (!$loaded) {
        // If DOM parse fails, do not skip the page on this check alone.
        return $info;
    }

    $xpath = new DOMXPath($dom);

    // Count rows that appear to be actual table data rows.
    // We only count rows with at least 2 TDs and without "No data available".
    $rows = $xpath->query('//table//tr');
    $dataRows = 0;

    if ($rows !== false) {
        foreach ($rows as $tr) {
            /** @var DOMElement $tr */
            $tds = $xpath->query('./td', $tr);
            if ($tds === false || $tds->length < 2) {
                continue;
            }

            $rowText = trim(preg_replace('/\s+/', ' ', (string)$tr->textContent));
            if ($rowText === '') {
                continue;
            }

            if (stripos($rowText, 'No data available') !== false) {
                continue;
            }

            $dataRows++;
        }
    }

    $info['data_rows'] = $dataRows;

    if ($dataRows === 0) {
        $info['is_empty'] = true;
        $info['reason'] = 'No real data rows found in results table.';
    }

    return $info;
}

// ------------------------- MAIN -------------------------
rr_log_line($logFile, RR_BACKFILL_SIGNATURE . " START year={$year} sapi=" . PHP_SAPI);
rr_backfill_out("Backfill year {$year} started...");

// Load backfill state
$state = rr_load_json($stateFile);
if (!isset($state['byYear']) || !is_array($state['byYear'])) $state['byYear'] = [];
$yKey = (string)$year;

if (!isset($state['byYear'][$yKey]) || !is_array($state['byYear'][$yKey])) {
    $state['byYear'][$yKey] = [
        'started_at' => date('c'),
        'last_run_at' => '',
        'races' => [], // keyed by raceId
    ];
}
$yearState = $state['byYear'][$yKey];

// Fetch year page
$yearPageUrl = "https://www.espn.com/racing/results/_/year/" . $year;
[$okY, $statusY, $yearHtml, $errY] = rr_fetch_url($yearPageUrl, $timeoutSeconds);

if (!$okY) {
    rr_log_line($logFile, "ERROR fetching year page (HTTP {$statusY}): {$errY}");
    rr_backfill_out("ERROR fetching year page: HTTP {$statusY} {$errY}");
    exit(0);
}

$races = rr_parse_year_page_races($yearHtml);
rr_backfill_out("Races found on year page: " . count($races));
rr_log_line($logFile, "Year page races found: " . count($races));

$yearFolder = __DIR__ . '/' . $yKey;
rr_ensure_dir($yearFolder);

// Year index mapping for monitor
$yearIndex = [
    'year' => $year,
    'generated_at' => date('c'),
    'races' => [], // raceId => [folder, kind, number, url, name]
];

// Numbering state
$pointsNum = 0;
$exhNum = 0;
$anomNum = 0;

$processed = 0;
$finalFound = 0;

// track previous points-race-name to detect immediate duplicate placeholder anomaly
$prevPointsNameNorm = '';

for ($i = 0; $i < count($races); $i++) {
    $race = $races[$i];

    $raceId   = (string)$race['race_id'];
    $raceUrl  = (string)$race['race_url'];
    $raceName = (string)$race['race_name'];
    $raceSlug = rr_sanitize_for_folder($raceName);

    // Fetch race page early so we can reject empty placeholder pages
    [$okR, $statusR, $htmlR, $errR] = rr_fetch_url($raceUrl, $timeoutSeconds);

    if (!$okR) {
        // Ensure state exists before writing fetch error info
        if (!isset($yearState['races'][$raceId]) || !is_array($yearState['races'][$raceId])) {
            $yearState['races'][$raceId] = [
                'race_url' => $raceUrl,
                'race_name' => $raceName,
                'race_number' => null,
                'exhibition_number' => null,
                'anomaly_number' => null,
                'is_exhibition' => false,
                'final_seen' => false,
                'final_table_hash' => '',
                'last_checked_at' => '',
                'last_reason' => '',
            ];
        }

        $yearState['races'][$raceId]['race_url'] = $raceUrl;
        $yearState['races'][$raceId]['race_name'] = $raceName;
        $yearState['races'][$raceId]['last_checked_at'] = date('c');
        $yearState['races'][$raceId]['last_reason'] = "HTTP {$statusR}: {$errR}";

        rr_log_line($logFile, "Race {$raceId} fetch ERROR (HTTP {$statusR})");
        continue;
    }

    // Skip empty placeholder pages before assigning numbering/folders/index entries
    $emptyInfo = rr_backfill_empty_results_info($htmlR);
    if ($emptyInfo['is_empty']) {
        rr_log_line(
            $logFile,
            "Race {$raceId} SKIPPED empty results page: {$emptyInfo['reason']} url={$raceUrl}"
        );
        rr_backfill_out(
            "SKIPPED race {$raceId}: {$raceName} — {$emptyInfo['reason']}"
        );
        continue;
    }

    $isPoints = rr_is_points_by_include($raceName);

    // Determine kind + numbering
    $kind = '';
    $numStr = '00';

    if ($isPoints) {
        // Normalize name for duplicate detection (case-insensitive, spaces/underscores treated similarly)
        $nameNorm = strtoupper($raceSlug);

        // Detect consecutive duplicate points-name:
        // If the first instance is NOT FINAL (zero scoring) and the next is FINAL, we want:
        // - the zero-scoring placeholder to become Z##
        // - the real FINAL race to consume the correct R##
        //
        // This is handled later after we fetch each race and know FINAL/zero.
        $kind = 'R'; // provisional
        $nameNormForDup = $nameNorm;
    } else {
        $kind = 'E';
        $nameNormForDup = '';
    }

    // Ensure per-race state exists
    if (!isset($yearState['races'][$raceId]) || !is_array($yearState['races'][$raceId])) {
        $yearState['races'][$raceId] = [
            'race_url' => $raceUrl,
            'race_name' => $raceName,
            'race_number' => null,
            'exhibition_number' => null,
            'anomaly_number' => null,
            'is_exhibition' => false,
            'final_seen' => false,
            'final_table_hash' => '',
            'last_checked_at' => '',
            'last_reason' => '',
        ];
    } else {
        $yearState['races'][$raceId]['race_url'] = $raceUrl;
        $yearState['races'][$raceId]['race_name'] = $raceName;
    }

    $yearState['races'][$raceId]['last_checked_at'] = date('c');

    // Detect final scoring (stable data hash now comes from engine)
    [$isFinal, $reason, $details] = rr_detect_final_scoring_nonzero($htmlR);
    $yearState['races'][$raceId]['last_reason'] = $reason;

    $processed++;

    // Decide final kind + numbering now that we know FINAL/zero
    $finalKind = $kind;

    if ($isPoints) {
        // If consecutive duplicate points-name AND this one is NOT FINAL (zero scoring), treat as anomaly Z
        $nameNorm = strtoupper($raceSlug);
        if ($prevPointsNameNorm !== '' && $nameNorm === $prevPointsNameNorm && !$isFinal) {
            $finalKind = 'Z';
        } else {
            $finalKind = 'R';
        }

        if ($finalKind === 'R') {
            $pointsNum++;
            $numStr = str_pad((string)$pointsNum, 2, '0', STR_PAD_LEFT);
            $yearState['races'][$raceId]['race_number'] = $pointsNum;
            $yearState['races'][$raceId]['is_exhibition'] = false;
            $yearState['races'][$raceId]['exhibition_number'] = null;
            $yearState['races'][$raceId]['anomaly_number'] = null;

            // Only update prevPointsNameNorm for TRUE points entries
            $prevPointsNameNorm = $nameNorm;
        } else {
            $anomNum++;
            $numStr = str_pad((string)$anomNum, 2, '0', STR_PAD_LEFT);
            $yearState['races'][$raceId]['race_number'] = null;
            $yearState['races'][$raceId]['is_exhibition'] = false;
            $yearState['races'][$raceId]['exhibition_number'] = null;
            $yearState['races'][$raceId]['anomaly_number'] = $anomNum;
            // Do NOT update prevPointsNameNorm for anomaly placeholders
        }
    } else {
        $exhNum++;
        $numStr = str_pad((string)$exhNum, 2, '0', STR_PAD_LEFT);
        $finalKind = 'E';
        $yearState['races'][$raceId]['race_number'] = null;
        $yearState['races'][$raceId]['is_exhibition'] = true;
        $yearState['races'][$raceId]['exhibition_number'] = $exhNum;
        $yearState['races'][$raceId]['anomaly_number'] = null;
    }

    $raceFolderName = $finalKind . $numStr . '_' . $raceSlug . '_' . $raceId;
    $raceFolder = $yearFolder . '/' . $raceFolderName;
    rr_ensure_dir($raceFolder);

    // Write meta
    rr_write_meta($raceFolder, [
        'year' => $year,
        'race_id' => $raceId,
        'race_url' => $raceUrl,
        'race_name' => $raceName,
        'kind' => $finalKind,
        'points_number' => ($finalKind === 'R') ? $yearState['races'][$raceId]['race_number'] : null,
        'exhibition_number' => ($finalKind === 'E') ? $yearState['races'][$raceId]['exhibition_number'] : null,
        'anomaly_number' => ($finalKind === 'Z') ? $yearState['races'][$raceId]['anomaly_number'] : null,
        'updated_at' => date('c'),
    ]);

    // Update year index for monitor
    $yearIndex['races'][$raceId] = [
        'folder' => $raceFolderName,
        'kind' => $finalKind,
        'number' => (int)$numStr,
        'race_url' => $raceUrl,
        'race_name' => $raceName,
    ];

    // If NOT FINAL, continue (but folders/meta/index still exist)
    if (!$isFinal) {
        rr_log_line($logFile, "Race {$raceId} NOT FINAL reason={$reason} folder={$raceFolderName}");
        continue;
    }

    $finalFound++;

    $hashNow  = (string)($details['tableHash'] ?? ''); // stable data hash
    $hashPrev = (string)($yearState['races'][$raceId]['final_table_hash'] ?? '');
    $alreadyFinal = (bool)($yearState['races'][$raceId]['final_seen'] ?? false);

    // Authoritative folder hash (prevents duplicates if state reset)
    $hashFilePath = $raceFolder . '/final_table_hash.txt';
    $folderHash = '';
    if (is_file($hashFilePath)) {
        $folderHash = trim((string)@file_get_contents($hashFilePath));
    }
    if ($folderHash !== '' && $hashNow !== '' && hash_equals($folderHash, $hashNow)) {
        // Treat as already captured
        $alreadyFinal = true;
        $hashPrev = $folderHash;
    }

    $needSnapshot = false;
    if (!$alreadyFinal) {
        $needSnapshot = true; // first final
    } elseif ($hashNow !== '' && $hashPrev !== '' && $hashNow !== $hashPrev) {
        $needSnapshot = true; // hash change
    }

    if ($needSnapshot && $snapshotsEnabled) {
        $tsFile = rr_preferred_timestamp(true);
        rr_save_snapshot_html($raceFolder, $tsFile, $htmlR, $snapshotMaxBytes);
        rr_save_snapshot_summary($raceFolder, $tsFile, $htmlR);
        rr_atomic_write($hashFilePath, $hashNow . "\n");
        rr_log_line($logFile, "Race {$raceId} SNAPSHOT SAVED folder=" . basename($raceFolder));
    } else {
        rr_log_line($logFile, "Race {$raceId} FINAL but no snapshot needed (hash unchanged) folder=" . basename($raceFolder));
    }

    $yearState['races'][$raceId]['final_seen'] = true;
    $yearState['races'][$raceId]['final_table_hash'] = $hashNow;

    // Optional email (OFF by default)
    if ($emailOnFinal && !$alreadyFinal) {
        $msg =
            "FINAL scoring detected during backfill.\n\n" .
            "Year: {$year}\n" .
            "Race: {$raceName}\n" .
            "URL : {$raceUrl}\n";
        try {
            $user_home->send_mail($notifyEmail, $msg, $subjectFinal);
            rr_log_line($logFile, "Race {$raceId} EMAIL SENT (backfill)");
        } catch (Throwable $e) {
            rr_log_line($logFile, "Race {$raceId} EMAIL EXCEPTION (backfill): " . $e->getMessage());
        }
    }
}

// Save year index (for monitor)
rr_backfill_save_year_index($year, $yearFolder, $yearIndex);

// Save state
$yearState['last_run_at'] = date('c');
$state['byYear'][$yKey] = $yearState;
rr_save_json($stateFile, $state);

rr_log_line($logFile, RR_BACKFILL_SIGNATURE . " DONE year={$year} processed={$processed} final={$finalFound}");
rr_backfill_out("DONE. Processed={$processed}, FINAL detected={$finalFound}");
exit(0);