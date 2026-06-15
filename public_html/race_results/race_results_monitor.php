<?php
declare(strict_types=1);

/**
 * race_results_monitor.php
 *
 * VERSION: v135
 * LAST MODIFIED: 6/14/2026 12:24:50 pm
 *
 * CHANGELOG:
 *
 * v135 (6/14/2026)
 *   - FIX: Race monitor now reports Waiting for race start/current race results when ESPN latest results still points to a prior completed race.
 *   - NEW: Stores durable final_handoff metadata anchored to the actual snapshot/email event time for revision scheduler handoff.
 *
 * v134 (6/13/2026)
 *   - CHANGE: Removed the temporary 2026 San Diego folder-creation correction now that ESPN is supplying the corrected Cup Series schedule row/name.
 *
 * v133 (6/6/2026)
 *   - CHANGE: Schedule JSON now includes a filtered mrl_points_races list and uses that list for next-race selection.
 *   - CHANGE: Race Status no longer relies on raw schedule row numbering as an MRL R-race number.
 *   - FIX: Annotates schedule rows with MRL R## identity using the existing year index before choosing the next race.
 *   - FIX: Schedule JSON now separates all schedule rows from MRL points races.
 *   - CHANGE: Uses corrected engine schedule identity so projected next races continue after the latest known R folder.
 *   - NEW: Added schedule-aware Race Status foundation using the ESPN yearly schedule source.
 *   - NEW: Writes _race_results_schedule.json with parsed schedule rows and next scheduled race data.
 *   - NEW: Writes monitor ownership state so completed/final-email races hand off to the revision monitor.
 *   - CHANGE: Dashboard-ready race_status can now show the next scheduled race after the latest completed race has been finalized.
 *
 * v132 (5/31/2026)
 *   - FIX: Live race status extraction now targets the Race Results leader row and captures lap progress from that row.
 *   - CHANGE: Keeps dashboard display to race name plus lap status only; leader name is not displayed.
 *   - NOTE: Final-result detection/scoring/snapshot behavior is unchanged.
 *
 * v131 (5/31/2026)
 *   - NEW: Extracts live in-progress race status from ESPN race page text.
 *   - NEW: Stores dashboard-ready current_race_status in _race_results_monitor_state.json.
 *   - NEW: Displays lap progress only, such as "Lap 173 of 300", without displaying leader name.
 *   - NOTE: Final-result detection/scoring/snapshot behavior is unchanged.
 *
 * v130 (5/25/2026)
 *   - NEW: Added an immediate preflight heartbeat/log write before helper includes and USER initialization.
 *   - PURPOSE: Makes scheduler-launched runs visible even if the monitor exits before normal main startup.
 *   - CHANGE: Monitor signature updated to RACE_RESULTS_MONITOR v130.
 *   - NOTE: Final-result detection/scoring behavior is unchanged.
 *
 * v129 (5/3/2026)
 *   - FIX: RD detection now evaluates only completed segment races with saved snapshot data, preventing future/unrun races from creating false RD eligibility.
 *   - CHANGE: RD detection details now write to _race_results_rd.log instead of cluttering the main monitor health log.
 *   - CHANGE: Suppressed repeated per-team MANUAL_SELECTION_REQUIRED lines from _race_results_monitor.log.
 *   - CHANGE: Added _race_results_rd_status.json so the dashboard can show RD check status even when no teams are eligible.
 *
 * v128 (4/5/2026)
 *   - CHANGE: RD pending JSON is now written in each race folder where eligibility still exists.
 *   - CHANGE: MRL reminder email is now sent for each race folder where RD eligibility still exists.
 *   - CHANGE: Per-race-folder RD JSON/email no longer depend on whether an earlier race folder already had the same pending state.
 *
 * v127 (4/5/2026)
 *   - FIX: RD detection now still runs when FINAL results were already captured or already notified.
 *   - CHANGE: Existing duplicate protection for FINAL snapshots and FINAL emails remains unchanged.
 *   - CHANGE: RD detection can now backfill pending JSON/email on manual reruns without requiring a new snapshot.
 *
 * v126 (4/5/2026)
 *   - NEW: Added RD eligibility detection after FINAL snapshot capture.
 *   - NEW: Writes one JSON confirmation file per newly detected RD-eligible team in the current race folder.
 *   - NEW: Sends MRL-only RD eligibility email when a new RD pending JSON file is created or changed.
 *   - CHANGE: RD detection uses existing snapshot files + segment_race_ranges + current team pick helpers.
 *   - CHANGE: Existing FINAL results detection, snapshot, and under_review.flag behavior preserved.
 *
 * v125 (3/23/2026)
 *   - CHANGE: Added automatic under_review.flag creation when a new or revised snapshot is written.
 *   - CHANGE: Pending review state now reappears automatically when result changes trigger a new snapshot.
 *   - CHANGE: Updated header versioning to v125 format while preserving existing monitor behavior.
 *
 * PHP: 7.3 compatible.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_race_results_monitor_php_errors.log');
error_reporting(E_ALL);

const RR_MONITOR_SIGNATURE = 'RACE_RESULTS_MONITOR v135';

// ------------------------- PREFLIGHT HEARTBEAT -------------------------
// This intentionally happens before helper includes and USER initialization.
// If a dependency exits early, this still proves the monitor script was entered.
$__rr_preflight_year = 2026;
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && count($argv) >= 2) {
    $__rr_cli_year = (int)$argv[1];
    if ($__rr_cli_year >= 2000 && $__rr_cli_year <= 2100) {
        $__rr_preflight_year = $__rr_cli_year;
    }
}

$__rr_preflight_token = '';
try {
    $__rr_preflight_token = bin2hex(random_bytes(8));
} catch (Throwable $e) {
    $__rr_preflight_token = substr(str_replace('.', '', uniqid('', true)), 0, 16);
}

$__rr_preflight_sha = is_file(__FILE__) ? hash_file('sha256', __FILE__) : '';
$__rr_preflight_now = date('Y-m-d H:i:s');
$__rr_preflight_hb = __DIR__ . '/_race_results_monitor_heartbeat.txt';
$__rr_preflight_log = __DIR__ . '/_race_results_monitor.log';

$__rr_preflight_line = $__rr_preflight_now
    . "  token={$__rr_preflight_token}"
    . "  sig=" . RR_MONITOR_SIGNATURE
    . "  year={$__rr_preflight_year}"
    . "  sapi=" . PHP_SAPI
    . "  stage=preflight"
    . "  sha={$__rr_preflight_sha}";

@file_put_contents($__rr_preflight_hb, $__rr_preflight_line . "\n", LOCK_EX);
@file_put_contents(
    $__rr_preflight_log,
    '[' . $__rr_preflight_now . '] ' . RR_MONITOR_SIGNATURE
    . " PREFLIGHT year={$__rr_preflight_year} sapi=" . PHP_SAPI
    . " sha={$__rr_preflight_sha} token={$__rr_preflight_token}\n",
    FILE_APPEND | LOCK_EX
);

require_once __DIR__ . '/race_results_engine.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';
require_once __DIR__ . '/race_results_team_helper.php';
require_once __DIR__ . '/race_results_rd_helper.php';

// ------------------------- DOCUMENT ROOT + INCLUDES -------------------------
$docRoot = rr_docroot_from_script_dir(__DIR__);

if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}

require_once $docRoot . '/config.php';
require_once $docRoot . '/config_mrl.php';
require_once $docRoot . '/class.user.php';

$user_home = new USER();

// ------------------------- SETTINGS -------------------------
$year = 2026;
$notifyEmail = 'manliusracingleague@gmail.com';
$subjectPrefix = '[MRL] Results Detected: ';
$rdSubjectPrefix = '[MRL] RD Eligible: ';

$stateFile     = __DIR__ . '/_race_results_monitor_state.json';
$logFile       = __DIR__ . '/_race_results_monitor.log';
$heartbeatFile = __DIR__ . '/_race_results_monitor_heartbeat.txt';
$rdLogFile     = __DIR__ . '/_race_results_rd.log';
$rdStatusFile  = __DIR__ . '/_race_results_rd_status.json';
$scheduleFile  = __DIR__ . '/_race_results_schedule.json';

$yearIndexFile = __DIR__ . '/' . (string)$year . '/_year_index.json';

$timeoutSeconds = 25;
$snapshotsEnabled = true;
$snapshotMaxBytes = 3000000;

if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && count($argv) >= 2) {
    $cliYear = (int)$argv[1];
    if ($cliYear >= 2000 && $cliYear <= 2100) {
        $year = $cliYear;
    }
    $yearIndexFile = __DIR__ . '/' . (string)$year . '/_year_index.json';
}

function rr_monitor_out(string $line): void
{
    if (PHP_SAPI === 'cli') return;
    echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "<br>\n";
}

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

function rr_monitor_apply_known_race_corrections(int $year, string $raceId, string &$raceName, bool &$isExhibition, ?int &$raceNum): void
{
    // Intentionally no current race-name corrections.
    // ESPN corrected the 2026 San Diego schedule row/name, so folder identity
    // should now follow the normal schedule/year-index matching path.
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

function rr_monitor_segment_from_race_number(int $raceNumber): string
{
    if ($raceNumber >= 1 && $raceNumber <= 8) return 'S1';
    if ($raceNumber >= 9 && $raceNumber <= 17) return 'S2';
    if ($raceNumber >= 18 && $raceNumber <= 26) return 'S3';
    if ($raceNumber >= 27 && $raceNumber <= 36) return 'S4';
    return 'S1';
}

function rr_monitor_find_snapshot_file(string $raceFolder): string
{
    if (!is_dir($raceFolder)) {
        return '';
    }

    $files = glob($raceFolder . '/snapshot_*.html');
    if (!is_array($files) || empty($files)) {
        return '';
    }

    sort($files, SORT_STRING);
    return (string)end($files);
}

function rr_monitor_point_races_by_number(array $yearIndex, string $yearFolder): array
{
    $rows = [];

    if (!isset($yearIndex['races']) || !is_array($yearIndex['races'])) {
        return $rows;
    }

    foreach ($yearIndex['races'] as $raceId => $row) {
        if (!is_array($row)) {
            continue;
        }

        $kind = (string)($row['kind'] ?? '');
        if ($kind !== 'R') {
            continue;
        }

        $number = (int)($row['number'] ?? 0);
        $folder = (string)($row['folder'] ?? '');
        $raceName = (string)($row['race_name'] ?? '');

        if ($number <= 0 || $folder === '') {
            continue;
        }

        $rows[$number] = [
            'raceId' => (string)$raceId,
            'number' => $number,
            'folder' => $folder,
            'raceName' => $raceName,
            'raceCode' => 'R' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'raceFolder' => $yearFolder . '/' . $folder,
        ];
    }

    ksort($rows, SORT_NUMERIC);
    return $rows;
}

function rr_monitor_build_segment_driver_points(
    int $year,
    string $segment,
    int $throughRaceNumber,
    array $yearIndex,
    string $yearFolder
): array {
    global $dbo;

    $raceDriverPoints = [];
    $pointRaces = rr_monitor_point_races_by_number($yearIndex, $yearFolder);
    $bounds = mrl_rd_try_get_segment_bounds($dbo, $year, $segment);

    if (!is_array($bounds)) {
        return [];
    }

    for ($n = (int)$bounds['start']; $n <= (int)$bounds['end']; $n++) {
        if ($n > $throughRaceNumber) {
            continue;
        }

        if (!isset($pointRaces[$n])) {
            continue;
        }

        $snapshotFile = rr_monitor_find_snapshot_file((string)$pointRaces[$n]['raceFolder']);
        if ($snapshotFile === '') {
            continue;
        }

        $driverRows = rrs_load_snapshot_driver_points($snapshotFile);
        if (!is_array($driverRows) || empty($driverRows)) {
            continue;
        }

        $raceDriverPoints[$n] = [];

        foreach ($driverRows as $driverName => $driverData) {
            if (!is_array($driverData)) {
                continue;
            }

            $raceDriverPoints[$n][(string)$driverName] = (int)($driverData['net'] ?? 0);
        }

        if (empty($raceDriverPoints[$n])) {
            unset($raceDriverPoints[$n]);
        }
    }

    ksort($raceDriverPoints, SORT_NUMERIC);
    return $raceDriverPoints;
}

function rr_monitor_detect_team_rd_eligibility_completed_only(
    PDO $dbo,
    int $year,
    string $segment,
    array $teamRow,
    array $raceDriverPoints
): array {
    $teamName = (string)($teamRow['teamName'] ?? '');

    if ($teamName === '') {
        return [
            'teamName' => '',
            'segment' => $segment,
            'base_pick_row' => $teamRow,
            'qualifiers' => [],
            'qualifier_count' => 0,
            'status' => 'NO_TEAM_NAME',
            'auto_select_allowed' => false,
        ];
    }

    if (mrl_rd_team_used_rd_this_year($dbo, (string)$year, $teamName)) {
        return [
            'teamName' => $teamName,
            'segment' => $segment,
            'base_pick_row' => $teamRow,
            'qualifiers' => [],
            'qualifier_count' => 0,
            'status' => 'RD_ALREADY_USED',
            'auto_select_allowed' => false,
        ];
    }

    $completedRaceNumbers = array_keys($raceDriverPoints);
    sort($completedRaceNumbers, SORT_NUMERIC);

    $qualifiers = [];
    $slots = [
        'A' => (string)($teamRow['driverA'] ?? ''),
        'B' => (string)($teamRow['driverB'] ?? ''),
        'C' => (string)($teamRow['driverC'] ?? ''),
        'D' => (string)($teamRow['driverD'] ?? ''),
    ];

    foreach ($slots as $slot => $driverName) {
        $driverName = trim($driverName);
        if ($driverName === '') {
            continue;
        }

        $zeroStreak = 0;
        $zeroRaces = [];

        foreach ($completedRaceNumbers as $raceNumber) {
            $raceNumber = (int)$raceNumber;
            if (!isset($raceDriverPoints[$raceNumber]) || !is_array($raceDriverPoints[$raceNumber])) {
                continue;
            }

            $net = 0;
            if (array_key_exists($driverName, $raceDriverPoints[$raceNumber])) {
                $net = (int)$raceDriverPoints[$raceNumber][$driverName];
            }

            if ($net === 0) {
                $zeroStreak++;
                $zeroRaces[] = $raceNumber;

                if ($zeroStreak >= 2) {
                    $effectiveRace = mrl_rd_next_race_in_segment($dbo, $year, $segment, $raceNumber);
                    if ($effectiveRace !== null) {
                        $qualifiers[] = [
                            'qualified' => true,
                            'slot' => $slot,
                            'driver' => $driverName,
                            'zero_races' => array_slice($zeroRaces, -2),
                            'effective_race' => $effectiveRace,
                        ];
                    }
                    break;
                }
            } else {
                $zeroStreak = 0;
                $zeroRaces = [];
            }
        }
    }

    $qualifierCount = count($qualifiers);
    $status = 'NO_RD';
    $autoSelectAllowed = false;

    if ($qualifierCount === 1) {
        $status = 'RD_AVAILABLE';
        $autoSelectAllowed = true;
    } elseif ($qualifierCount > 1) {
        $status = 'MANUAL_SELECTION_REQUIRED';
    }

    return [
        'teamName' => $teamName,
        'segment' => $segment,
        'base_pick_row' => $teamRow,
        'qualifiers' => $qualifiers,
        'qualifier_count' => $qualifierCount,
        'status' => $status,
        'auto_select_allowed' => $autoSelectAllowed,
    ];
}

function rr_monitor_rd_pending_path(string $raceFolder, string $teamName): string
{
    $slug = rr_sanitize_for_folder($teamName);
    return $raceFolder . '/_rd_pending_' . $slug . '.json';
}

function rr_monitor_rd_payload(array $eligibility): array
{
    $base = isset($eligibility['base_pick_row']) && is_array($eligibility['base_pick_row'])
        ? $eligibility['base_pick_row']
        : [];

    $qualifier = [];
    if (isset($eligibility['qualifiers']) && is_array($eligibility['qualifiers']) && !empty($eligibility['qualifiers'])) {
        $qualifier = $eligibility['qualifiers'][0];
    }

    $triggerCodes = [];
    if (isset($qualifier['zero_races']) && is_array($qualifier['zero_races'])) {
        foreach ($qualifier['zero_races'] as $zeroRace) {
            $triggerCodes[] = 'R' . str_pad((string)((int)$zeroRace), 2, '0', STR_PAD_LEFT);
        }
    }

    $effectiveRaceCode = '';
    $effectiveRace = (int)($qualifier['effective_race'] ?? 0);
    if ($effectiveRace > 0) {
        $effectiveRaceCode = 'R' . str_pad((string)$effectiveRace, 2, '0', STR_PAD_LEFT);
    }

    return [
        'userID' => (int)($base['userID'] ?? 0),
        'teamName' => (string)($eligibility['teamName'] ?? ''),
        'segment' => (string)($eligibility['segment'] ?? ''),
        'slot' => (string)($qualifier['slot'] ?? ''),
        'driver' => (string)($qualifier['driver'] ?? ''),
        'trigger_races' => $triggerCodes,
        'effective_race' => $effectiveRaceCode,
        'detected_at' => date('Y-m-d\TH:i:s'),
    ];
}

function rr_monitor_write_rd_pending_json(string $path, array $payload): bool
{
    $existing = [];
    if (is_file($path)) {
        $existing = rr_load_json($path);
    }

    $existingCompare = $existing;
    $payloadCompare = $payload;
    unset($existingCompare['detected_at'], $payloadCompare['detected_at']);

    if ($existingCompare === $payloadCompare) {
        return false;
    }

    rr_save_json($path, $payload);
    return true;
}

function rr_monitor_send_rd_email($user_home, string $notifyEmail, string $subjectPrefix, array $payload, string $jsonPath, string $publicHost, string $raceFolderName, int $year): bool
{
    $teamName = (string)($payload['teamName'] ?? '');
    $segment = (string)($payload['segment'] ?? '');
    $slot = (string)($payload['slot'] ?? '');
    $driver = (string)($payload['driver'] ?? '');
    $effectiveRace = (string)($payload['effective_race'] ?? '');
    $triggerRaces = isset($payload['trigger_races']) && is_array($payload['trigger_races'])
        ? implode(', ', $payload['trigger_races'])
        : '';

    $jsonBase = basename($jsonPath);
    $jsonLink = 'https://' . $publicHost
        . '/race_results/' . rawurlencode((string)$year)
        . '/' . rawurlencode($raceFolderName)
        . '/' . rawurlencode($jsonBase);

    $currentRaceCode = '';
    if (preg_match('/^(R\d{2})_/', $raceFolderName, $m)) {
        $currentRaceCode = (string)$m[1];
    }

    $isReminder = ($currentRaceCode !== '' && $effectiveRace !== '' && strcmp($currentRaceCode, $effectiveRace) > 0);

    $subject = $subjectPrefix
        . ($isReminder ? 'Reminder: ' : '')
        . $year . '_' . $segment . '_' . rr_sanitize_for_folder($teamName);

    $message =
        'Replacement Driver eligible.<br>' .
        'Team: ' . htmlspecialchars($teamName, ENT_QUOTES, 'UTF-8') . '<br>' .
        'Segment: ' . htmlspecialchars($segment, ENT_QUOTES, 'UTF-8') . '<br>' .
        'Slot: ' . htmlspecialchars($slot, ENT_QUOTES, 'UTF-8') . '<br>' .
        'Driver: ' . htmlspecialchars($driver, ENT_QUOTES, 'UTF-8') . '<br>' .
        'Trigger races: ' . htmlspecialchars($triggerRaces, ENT_QUOTES, 'UTF-8') . '<br>' .
        'Effective race: ' . htmlspecialchars($effectiveRace, ENT_QUOTES, 'UTF-8') . '<br>' .
        '<a href="' . htmlspecialchars($jsonLink, ENT_QUOTES, 'UTF-8') . '">RD Pending JSON</a>';

    try {
        return (bool)$user_home->send_mail($notifyEmail, $message, $subject);
    } catch (Throwable $e) {
        return false;
    }
}


function rr_monitor_write_rd_status(string $path, array $status): void
{
    $status['written_at'] = date('Y-m-d\TH:i:s');
    rr_save_json($path, $status);
}

function rr_monitor_rd_status_payload(
    int $year,
    int $latestRaceNumber,
    string $raceFolderName,
    string $segment,
    string $status,
    string $message,
    array $extra = []
): array {
    $raceCode = '';
    if ($latestRaceNumber > 0) {
        $raceCode = 'R' . str_pad((string)$latestRaceNumber, 2, '0', STR_PAD_LEFT);
    }

    $payload = [
        'checked_at' => date('Y-m-d H:i:s'),
        'year' => $year,
        'race_number' => $latestRaceNumber,
        'race_code' => $raceCode,
        'race_folder' => $raceFolderName,
        'segment' => $segment,
        'status' => $status,
        'message' => $message,
    ];

    foreach ($extra as $key => $value) {
        $payload[(string)$key] = $value;
    }

    return $payload;
}

function rr_monitor_run_rd_detection(
    int $year,
    int $latestRaceNumber,
    string $raceFolder,
    string $raceFolderName,
    array $yearIndex,
    string $yearFolder,
    $dbo,
    $dbconnect,
    string $notifyEmail,
    $user_home,
    string $rdSubjectPrefix,
    string $rdLogFile,
    string $rdStatusFile,
    string $publicHost
): void {
    $segment = ($latestRaceNumber > 0)
        ? rr_monitor_segment_from_race_number($latestRaceNumber)
        : '';

    if (!($dbo instanceof PDO)) {
        rr_monitor_write_rd_status(
            $rdStatusFile,
            rr_monitor_rd_status_payload(
                $year,
                $latestRaceNumber,
                $raceFolderName,
                $segment,
                'SKIPPED',
                'PDO not available.'
            )
        );
        rr_log_line($rdLogFile, 'RD DETECTION SKIPPED: PDO not available.');
        return;
    }

    if ($latestRaceNumber <= 0) {
        rr_monitor_write_rd_status(
            $rdStatusFile,
            rr_monitor_rd_status_payload(
                $year,
                $latestRaceNumber,
                $raceFolderName,
                $segment,
                'SKIPPED',
                'Latest race number unavailable.'
            )
        );
        rr_log_line($rdLogFile, 'RD DETECTION SKIPPED: latest race number unavailable.');
        return;
    }

    $raceDriverPoints = rr_monitor_build_segment_driver_points($year, $segment, $latestRaceNumber, $yearIndex, $yearFolder);
    $completedRaceNumbers = array_keys($raceDriverPoints);
    sort($completedRaceNumbers, SORT_NUMERIC);

    if (empty($raceDriverPoints)) {
        rr_monitor_write_rd_status(
            $rdStatusFile,
            rr_monitor_rd_status_payload(
                $year,
                $latestRaceNumber,
                $raceFolderName,
                $segment,
                'SKIPPED',
                'No completed race driver points built.',
                [
                    'completed_race_count' => 0,
                    'completed_races' => [],
                    'team_count' => 0,
                    'eligible_count' => 0,
                    'manual_required_count' => 0,
                    'json_written_count' => 0,
                    'email_sent_count' => 0,
                    'email_failed_count' => 0,
                ]
            )
        );
        rr_log_line($rdLogFile, 'RD DETECTION SKIPPED: no completed race driver points built for ' . $year . ' ' . $segment . '.');
        return;
    }

    $teamRows = rr_get_segment_team_picks($dbo, $dbconnect, (string)$year, $segment);
    if (empty($teamRows)) {
        rr_monitor_write_rd_status(
            $rdStatusFile,
            rr_monitor_rd_status_payload(
                $year,
                $latestRaceNumber,
                $raceFolderName,
                $segment,
                'SKIPPED',
                'No baseline team rows found.',
                [
                    'completed_race_count' => count($completedRaceNumbers),
                    'completed_races' => array_values($completedRaceNumbers),
                    'team_count' => 0,
                    'eligible_count' => 0,
                    'manual_required_count' => 0,
                    'json_written_count' => 0,
                    'email_sent_count' => 0,
                    'email_failed_count' => 0,
                ]
            )
        );
        rr_log_line($rdLogFile, 'RD DETECTION SKIPPED: no baseline team rows found for ' . $year . ' ' . $segment . '.');
        return;
    }

    $teamCount = 0;
    $eligibleCount = 0;
    $manualRequiredCount = 0;
    $jsonWrittenCount = 0;
    $emailSentCount = 0;
    $emailFailedCount = 0;
    $eligibleTeams = [];
    $manualTeams = [];

    foreach ($teamRows as $teamRow) {
        $teamName = (string)($teamRow['teamName'] ?? '');
        if ($teamName === '') {
            continue;
        }

        $teamCount++;

        $eligibility = rr_monitor_detect_team_rd_eligibility_completed_only(
            $dbo,
            $year,
            $segment,
            $teamRow,
            $raceDriverPoints
        );

        $status = (string)($eligibility['status'] ?? '');
        if ($status !== 'RD_AVAILABLE') {
            if ($status === 'MANUAL_SELECTION_REQUIRED') {
                $manualRequiredCount++;
                $manualTeams[] = $teamName;
                rr_log_line($rdLogFile, 'RD MANUAL SELECTION REQUIRED team=' . $teamName . ' segment=' . $segment);
            }
            continue;
        }

        $eligibleCount++;
        $eligibleTeams[] = $teamName;

        $payload = rr_monitor_rd_payload($eligibility);
        $jsonPath = rr_monitor_rd_pending_path($raceFolder, $teamName);
        $createdOrChanged = rr_monitor_write_rd_pending_json($jsonPath, $payload);

        if ($createdOrChanged) {
            $jsonWrittenCount++;
            rr_log_line($rdLogFile, 'RD JSON WRITTEN team=' . $teamName . ' file=' . basename($jsonPath));

            $sentOk = rr_monitor_send_rd_email(
                $user_home,
                $notifyEmail,
                $rdSubjectPrefix,
                $payload,
                $jsonPath,
                $publicHost,
                $raceFolderName,
                $year
            );

            if ($sentOk) {
                $emailSentCount++;
            } else {
                $emailFailedCount++;
            }

            rr_log_line(
                $rdLogFile,
                $sentOk
                    ? 'RD EMAIL SENT team=' . $teamName . ' file=' . basename($jsonPath)
                    : 'RD EMAIL FAILED team=' . $teamName . ' file=' . basename($jsonPath)
            );
        }
    }

    $finalStatus = 'OK';
    $message = 'No RD eligibility found.';

    if ($manualRequiredCount > 0) {
        $finalStatus = 'MANUAL_REVIEW';
        $message = (string)$manualRequiredCount . ' team(s) require manual RD selection review.';
    } elseif ($eligibleCount > 0) {
        $finalStatus = 'ACTION';
        $message = (string)$eligibleCount . ' RD eligible team(s) found.';
    }

    rr_monitor_write_rd_status(
        $rdStatusFile,
        rr_monitor_rd_status_payload(
            $year,
            $latestRaceNumber,
            $raceFolderName,
            $segment,
            $finalStatus,
            $message,
            [
                'completed_race_count' => count($completedRaceNumbers),
                'completed_races' => array_values($completedRaceNumbers),
                'team_count' => $teamCount,
                'eligible_count' => $eligibleCount,
                'manual_required_count' => $manualRequiredCount,
                'json_written_count' => $jsonWrittenCount,
                'email_sent_count' => $emailSentCount,
                'email_failed_count' => $emailFailedCount,
                'eligible_teams' => $eligibleTeams,
                'manual_required_teams' => $manualTeams,
            ]
        )
    );
}


function rr_monitor_norm_header(string $s): ?string
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

function rr_monitor_extract_lap_status_from_text(string $text): array
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim((string)$text);

    $out = [
        'found' => false,
        'lap_current' => null,
        'lap_total' => null,
        'status' => '',
    ];

    if ($text === '') {
        return $out;
    }

    if (preg_match('/\b(?:leads\s+on\s+)?lap\s+(\d+)\s+of\s+(\d+)\b/i', $text, $m)) {
        $current = (int)$m[1];
        $total = (int)$m[2];

        if ($current > 0 && $total > 0) {
            $out['found'] = true;
            $out['lap_current'] = $current;
            $out['lap_total'] = $total;
            $out['status'] = 'Lap ' . $current . ' of ' . $total;
        }
    }

    return $out;
}

function rr_monitor_extract_live_race_status(string $html, int $year, string $raceUrl, string $raceId, string $raceName, bool $isFinal, string $reason): array
{
    $shortName = rr_monitor_short_race_label($raceName);
    $shortName = str_replace('_', ' ', $shortName);
    $shortName = trim($shortName);
    if ($shortName === '') {
        $shortName = 'Race';
    }

    $out = [
        'checked_at' => date('c'),
        'year' => $year,
        'race_url' => $raceUrl,
        'race_id' => $raceId,
        'race_name' => $shortName,
        'full_race_name' => $raceName,
        'status' => '',
        'lap_current' => null,
        'lap_total' => null,
        'lap_status_found' => false,
        'is_final' => $isFinal,
        'final_reason' => $reason,
        'parse_method' => '',
        'tables_found' => 0,
        'race_result_rows_checked' => 0,
        'source' => RR_MONITOR_SIGNATURE,
    ];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    if ($loaded) {
        $xp = new DOMXPath($dom);
        $tables = $xp->query('//table');
        $out['tables_found'] = $tables ? (int)$tables->length : 0;

        if ($tables && $tables->length > 0) {
            for ($t = 0; $t < $tables->length; $t++) {
                $tbl = $tables->item($t);
                if (!$tbl instanceof DOMElement) {
                    continue;
                }

                $rows = $xp->query('.//tr', $tbl);
                if (!$rows || $rows->length === 0) {
                    continue;
                }

                $looksLikeRaceResults = false;
                $headerIndex = -1;

                for ($r = 0; $r < $rows->length; $r++) {
                    $row = $rows->item($r);
                    if (!$row instanceof DOMElement) {
                        continue;
                    }

                    $cells = $xp->query('./th|./td', $row);
                    if (!$cells || $cells->length < 2) {
                        continue;
                    }

                    $headerText = strtoupper(preg_replace('/\s+/', ' ', trim((string)$row->textContent)));
                    if (strpos($headerText, 'POS') !== false && strpos($headerText, 'DRIVER') !== false) {
                        $looksLikeRaceResults = true;
                        $headerIndex = $r;
                        break;
                    }
                }

                if (!$looksLikeRaceResults) {
                    continue;
                }

                for ($r = $headerIndex + 1; $r < $rows->length; $r++) {
                    $row = $rows->item($r);
                    if (!$row instanceof DOMElement) {
                        continue;
                    }

                    $tds = $xp->query('./td', $row);
                    if (!$tds || $tds->length < 2) {
                        continue;
                    }

                    $posText = trim((string)$tds->item(0)->textContent);
                    $posDigits = preg_replace('/\D+/', '', $posText);
                    if ($posDigits === '' || !preg_match('/^\d+$/', $posDigits)) {
                        continue;
                    }

                    $out['race_result_rows_checked']++;
                    $rowText = (string)$row->textContent;
                    $lap = rr_monitor_extract_lap_status_from_text($rowText);

                    if ($lap['found']) {
                        $out['status'] = (string)$lap['status'];
                        $out['lap_current'] = $lap['lap_current'];
                        $out['lap_total'] = $lap['lap_total'];
                        $out['lap_status_found'] = true;
                        $out['parse_method'] = 'race_results_row';
                        return $out;
                    }

                    // The live lap marker should appear on the leader/position-1 row.
                    // If it is not there, stop after the first real race-results row and use fallback below.
                    break;
                }
            }
        }
    }

    $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/\s+/', ' ', $plain);
    $lap = rr_monitor_extract_lap_status_from_text((string)$plain);

    if ($lap['found']) {
        $out['status'] = (string)$lap['status'];
        $out['lap_current'] = $lap['lap_current'];
        $out['lap_total'] = $lap['lap_total'];
        $out['lap_status_found'] = true;
        $out['parse_method'] = 'page_text_fallback';
    }

    return $out;
}


function rr_monitor_short_schedule_name(array $race): string
{
    $name = trim((string)($race['short_name'] ?? ''));
    if ($name === '') {
        $name = trim((string)($race['race_name'] ?? ''));
    }
    $name = preg_replace('/^NASCAR Cup Series at\s+/i', '', (string)$name);
    $name = trim((string)$name);
    return $name !== '' ? $name : 'Race';
}

function rr_monitor_format_schedule_start(array $race): string
{
    $startAt = trim((string)($race['start_at'] ?? ''));
    $timeText = trim((string)($race['time_text'] ?? ''));
    $dateText = trim((string)($race['date_text'] ?? ''));

    if ($startAt !== '') {
        try {
            $dt = new DateTimeImmutable($startAt, new DateTimeZone('America/New_York'));
            return $dt->format('l g:i a');
        } catch (Exception $e) {
            // fall through
        }
    }

    if ($dateText !== '' && $timeText !== '') {
        return $dateText . ' ' . $timeText;
    }

    if ($dateText !== '') {
        return $dateText;
    }

    return '';
}


function rr_monitor_schedule_start_ts(array $race): int
{
    $startAt = trim((string)($race['start_at'] ?? ''));
    if ($startAt === '') return 0;

    try {
        $dt = new DateTimeImmutable($startAt, new DateTimeZone('America/New_York'));
        return $dt->getTimestamp();
    } catch (Exception $e) {
        return 0;
    }
}

function rr_monitor_latest_results_are_prior_to_next(?int $latestRaceNum, array $nextRace): bool
{
    if ($latestRaceNum === null || $latestRaceNum <= 0) return false;

    $nextNum = 0;
    foreach (['mrl_race_number', 'race_number', 'schedule_sequence'] as $key) {
        if (isset($nextRace[$key]) && (int)$nextRace[$key] > 0) {
            $nextNum = (int)$nextRace[$key];
            break;
        }
    }

    return ($nextNum > 0 && $latestRaceNum < $nextNum);
}

function rr_monitor_build_waiting_status(array $nextRace, string $message): array
{
    return [
        'checked_at' => date('c'),
        'source' => RR_MONITOR_SIGNATURE,
        'mode' => 'waiting_for_scheduled_race',
        'label' => 'Next Race',
        'race_name' => rr_monitor_short_schedule_name($nextRace),
        'full_race_name' => (string)($nextRace['race_name'] ?? ''),
        'status' => $message,
        'race_url' => (string)($nextRace['race_url'] ?? ''),
        'race_id' => (string)($nextRace['race_id'] ?? ''),
        'start_at' => (string)($nextRace['start_at'] ?? ''),
        'start_text' => rr_monitor_format_schedule_start($nextRace),
        'owned_by' => 'race_results_monitor',
        'monitor_owned' => true,
        'final_email_sent' => false,
        'is_final' => false,
        'final_reason' => '',
        'lap_current' => null,
        'lap_total' => null,
        'lap_status_found' => false,
    ];
}

function rr_monitor_fetch_and_store_schedule(int $year, int $timeoutSeconds, string $scheduleFile, string $logFile, array $yearIndex = []): array
{
    $result = [
        'ok' => false,
        'message' => '',
        'races' => [],
        'mrl_points_races' => [],
        'next_race' => [],
        'debug' => [],
    ];

    if (!function_exists('rr_fetch_year_schedule')) {
        $result['message'] = 'Schedule helpers are not available.';
        rr_log_line($logFile, 'SCHEDULE: ' . $result['message']);
        return $result;
    }

    [$ok, $races, $err, $debug] = rr_fetch_year_schedule($year, $timeoutSeconds);
    if ($ok && function_exists('rr_schedule_annotate_mrl_race_numbers')) {
        $races = rr_schedule_annotate_mrl_race_numbers($races, $yearIndex);
        if (is_array($debug)) {
            $debug['mrl_identity_source'] = 'year_index';
        }
    }
    $mrlPointsRaces = ($ok && function_exists('rr_filter_mrl_schedule_points_races')) ? rr_filter_mrl_schedule_points_races($races) : [];
    $nextRace = $ok ? rr_find_next_scheduled_race($mrlPointsRaces, time()) : [];

    $result['ok'] = $ok;
    $result['message'] = $ok ? 'Schedule refreshed.' : $err;
    $result['races'] = $races;
    $result['mrl_points_races'] = $mrlPointsRaces;
    $result['next_race'] = $nextRace;
    $result['debug'] = $debug;

    $payload = [
        'generated_at' => date('c'),
        'source' => RR_MONITOR_SIGNATURE,
        'year' => $year,
        'schedule_url' => function_exists('rr_schedule_source_url') ? rr_schedule_source_url($year) : '',
        'ok' => $ok,
        'message' => $result['message'],
        'debug' => $debug,
        'next_race' => $nextRace,
        'mrl_points_races' => $mrlPointsRaces,
        'races' => $races,
    ];

    rr_save_json($scheduleFile, $payload);

    rr_log_line(
        $logFile,
        'SCHEDULE ' . ($ok ? 'OK' : 'ERROR')
        . ' races=' . (string)count($races)
        . ' mrl_points=' . (string)count($mrlPointsRaces)
        . ' next=' . (string)($nextRace['short_name'] ?? $nextRace['race_name'] ?? '')
        . ' message=' . $result['message']
    );

    return $result;
}

function rr_monitor_build_race_status(array $liveStatus, array $nextRace, bool $isFinal, bool $finalEmailSent, string $latestUrl, string $reason): array
{
    $status = [
        'checked_at' => date('c'),
        'source' => RR_MONITOR_SIGNATURE,
        'mode' => '',
        'label' => '',
        'race_name' => '',
        'full_race_name' => '',
        'status' => '',
        'race_url' => '',
        'race_id' => '',
        'start_at' => '',
        'start_text' => '',
        'owned_by' => '',
        'monitor_owned' => false,
        'final_email_sent' => $finalEmailSent,
        'is_final' => $isFinal,
        'final_reason' => $reason,
        'lap_current' => null,
        'lap_total' => null,
        'lap_status_found' => false,
    ];

    $liveRaceName = trim((string)($liveStatus['race_name'] ?? ''));
    $liveUrl = trim((string)($liveStatus['race_url'] ?? $latestUrl));
    $liveRaceId = trim((string)($liveStatus['race_id'] ?? ''));

    if ($isFinal && $finalEmailSent && !empty($nextRace)) {
        $status['mode'] = 'next_scheduled';
        $status['label'] = 'Next Race';
        $status['race_name'] = rr_monitor_short_schedule_name($nextRace);
        $status['full_race_name'] = (string)($nextRace['race_name'] ?? '');
        $status['race_url'] = (string)($nextRace['race_url'] ?? '');
        $status['race_id'] = (string)($nextRace['race_id'] ?? '');
        $status['start_at'] = (string)($nextRace['start_at'] ?? '');
        $status['start_text'] = rr_monitor_format_schedule_start($nextRace);
        $status['status'] = $status['start_text'] !== '' ? 'Scheduled - ' . $status['start_text'] : 'Scheduled';
        $status['owned_by'] = 'race_results_monitor';
        $status['monitor_owned'] = true;
        $status['latest_final_race_url'] = $liveUrl;
        $status['latest_final_race_name'] = $liveRaceName;
        $status['latest_final_owned_by'] = 'race_results_revision_monitor';
        return $status;
    }

    if ($isFinal && $finalEmailSent) {
        $status['mode'] = 'latest_final_handoff';
        $status['label'] = 'Latest Final';
        $status['race_name'] = $liveRaceName;
        $status['full_race_name'] = (string)($liveStatus['full_race_name'] ?? $liveRaceName);
        $status['race_url'] = $liveUrl;
        $status['race_id'] = $liveRaceId;
        $status['status'] = 'Final captured - handed off to revision monitor';
        $status['owned_by'] = 'race_results_revision_monitor';
        $status['monitor_owned'] = false;
        return $status;
    }

    $status['mode'] = $isFinal ? 'final_pending_email' : (!empty($liveStatus['lap_status_found']) ? 'live' : 'monitoring');
    $status['label'] = $isFinal ? 'Current Race' : 'Current Race';
    $status['race_name'] = $liveRaceName;
    $status['full_race_name'] = (string)($liveStatus['full_race_name'] ?? $liveRaceName);
    $status['race_url'] = $liveUrl;
    $status['race_id'] = $liveRaceId;
    $status['status'] = trim((string)($liveStatus['status'] ?? ''));
    if ($status['status'] === '') {
        $status['status'] = $isFinal ? 'Final detected - preparing notification' : 'Monitoring for live/final status';
    }
    $status['owned_by'] = 'race_results_monitor';
    $status['monitor_owned'] = true;
    $status['lap_current'] = $liveStatus['lap_current'] ?? null;
    $status['lap_total'] = $liveStatus['lap_total'] ?? null;
    $status['lap_status_found'] = !empty($liveStatus['lap_status_found']);

    return $status;
}

function rr_monitor_build_ownership(array $raceStatus, string $latestUrl, string $raceId): array
{
    return [
        'updated_at' => date('c'),
        'source' => RR_MONITOR_SIGNATURE,
        'active_monitor_race_url' => !empty($raceStatus['monitor_owned']) ? (string)($raceStatus['race_url'] ?? '') : '',
        'active_monitor_race_id' => !empty($raceStatus['monitor_owned']) ? (string)($raceStatus['race_id'] ?? '') : '',
        'latest_results_url' => $latestUrl,
        'latest_results_race_id' => $raceId,
        'owned_by' => (string)($raceStatus['owned_by'] ?? ''),
        'phase' => (string)($raceStatus['mode'] ?? ''),
        'final_email_sent' => !empty($raceStatus['final_email_sent']),
        'monitor_owned' => !empty($raceStatus['monitor_owned']),
    ];
}

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

$yearIndex = [];
if (is_file($yearIndexFile)) {
    $yearIndex = rr_load_year_index($yearIndexFile);
}
if (!isset($yearIndex['races']) || !is_array($yearIndex['races'])) {
    $yearIndex['races'] = [];
}

$scheduleResult = rr_monitor_fetch_and_store_schedule($year, $timeoutSeconds, $scheduleFile, $logFile, $yearIndex);
$nextScheduledRace = (isset($scheduleResult['next_race']) && is_array($scheduleResult['next_race'])) ? $scheduleResult['next_race'] : [];

list($ok, $latestUrl, $err, $debug) = rr_find_latest_race_results_url($year, $timeoutSeconds);

$yearState['last_checked_at'] = date('c');
$yearState['schedule_status'] = [
    'ok' => !empty($scheduleResult['ok']),
    'message' => (string)($scheduleResult['message'] ?? ''),
    'next_race' => $nextScheduledRace,
    'debug' => isset($scheduleResult['debug']) && is_array($scheduleResult['debug']) ? $scheduleResult['debug'] : [],
];
$yearState['latest_debug'] = $debug;

if (!$ok) {
    $state['byYear'][$yKey] = $yearState;
    rr_save_json($stateFile, $state);
    rr_log_line($logFile, "ERROR latestUrl: {$err}");
    rr_monitor_out("ERROR: " . $err);
    exit(0);
}

$yearPageUrl = "https://www.espn.com/racing/results/_/year/" . $year;
list($okY, $statusY, $yearHtml, $errY) = rr_fetch_url($yearPageUrl, $timeoutSeconds);

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
rr_monitor_apply_known_race_corrections($year, $raceId, $raceName, $isExh, $raceNum);

$latestResultsArePriorToNext = rr_monitor_latest_results_are_prior_to_next($raceNum !== null ? (int)$raceNum : null, $nextScheduledRace);
$nextRaceStartTs = rr_monitor_schedule_start_ts($nextScheduledRace);

if (!empty($nextScheduledRace) && $latestResultsArePriorToNext) {
    $waitingMessage = 'Waiting for race start';
    if ($nextRaceStartTs > 0 && time() >= $nextRaceStartTs) {
        $waitingMessage = 'Waiting for current race results';
    }

    $yearState['race_status'] = rr_monitor_build_waiting_status($nextScheduledRace, $waitingMessage);
    $yearState['monitor_ownership'] = rr_monitor_build_ownership($yearState['race_status'], (string)($yearState['latest_url'] ?? ''), rr_extract_race_id_from_url((string)($yearState['latest_url'] ?? '')));
    $state['byYear'][$yKey] = $yearState;
    rr_save_json($stateFile, $state);

    rr_log_line($logFile, "WAITING for scheduled race " . (string)($nextScheduledRace['mrl_race_code'] ?? '') . ' ' . rr_monitor_short_schedule_name($nextScheduledRace) . " latest_results_prior_url={$latestUrl}");
    rr_monitor_out($waitingMessage . '.');
    if (!empty($nextScheduledRace['mrl_race_code']) || !empty($nextScheduledRace['short_name'])) {
        rr_monitor_out('Race: ' . trim((string)($nextScheduledRace['mrl_race_code'] ?? '') . ' ' . rr_monitor_short_schedule_name($nextScheduledRace)));
    }
    exit(0);
}

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

list($ok2, $status2, $html2, $err2) = rr_fetch_url($latestUrl, $timeoutSeconds);

if (!$ok2) {
    $state['byYear'][$yKey] = $yearState;
    rr_save_json($stateFile, $state);
    rr_log_line($logFile, "ERROR fetching race page (HTTP {$status2}): {$err2} url={$latestUrl}");
    rr_monitor_out("ERROR fetching race page: HTTP {$status2} {$err2}");
    exit(0);
}

list($isFinal, $reason, $details) = rr_detect_final_scoring_nonzero($html2);

$ledCheck = rr_monitor_led_check($html2);
$ledReady = ($ledCheck['has_led_column'] && (int)$ledCheck['led_non_zero'] > 0);

if ($isFinal && !$ledReady) {
    $isFinal = false;
    $reason = 'Scoring table has non-zero PTS, but LED column is still all zero.';
}

$liveRaceStatus = rr_monitor_extract_live_race_status($html2, $year, $latestUrl, $raceId, $raceName, $isFinal, $reason);
$finalEmailAlreadySentAtCheck = ((string)($yearState['final_sent_for_url'] ?? '') === $latestUrl);
$yearState['current_race_status'] = $liveRaceStatus;
$yearState['race_status'] = rr_monitor_build_race_status($liveRaceStatus, $nextScheduledRace, $isFinal, $finalEmailAlreadySentAtCheck, $latestUrl, $reason);
$yearState['monitor_ownership'] = rr_monitor_build_ownership($yearState['race_status'], $latestUrl, $raceId);

$yearState['final_check'] = [
    'is_final' => $isFinal,
    'reason' => $reason,
    'checked_at' => date('c'),
    'mode' => (string)($details['mode'] ?? ''),
    'hash' => (string)($details['tableHash'] ?? ''),
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

$finalHashNow = (string)($yearState['final_check']['hash'] ?? '');
$hashFilePath = $raceFolder . '/final_table_hash.txt';

if ($finalHashNow !== '' && is_file($hashFilePath)) {
    $existing = trim((string)@file_get_contents($hashFilePath));
    if ($existing !== '' && hash_equals($existing, $finalHashNow)) {
        $yearState['final_sent_for_url'] = $latestUrl;
        $yearState['final_table_hash'] = $finalHashNow;
        $yearState['race_status'] = rr_monitor_build_race_status($liveRaceStatus, $nextScheduledRace, true, true, $latestUrl, $reason);
        $yearState['monitor_ownership'] = rr_monitor_build_ownership($yearState['race_status'], $latestUrl, $raceId);
        $state['byYear'][$yKey] = $yearState;
        rr_save_json($stateFile, $state);

        rr_log_line($logFile, "FINAL detected but already captured by folder hash (no snapshot/email) url={$latestUrl}");
        rr_monitor_out("FINAL detected, already captured (no snapshot/email).");

        if ($raceNum !== null && $raceNum > 0 && !$isExh) {
            $publicHost = rr_monitor_public_host($docRoot, __DIR__);
            rr_monitor_run_rd_detection(
                $year,
                (int)$raceNum,
                $raceFolder,
                $raceFolderName,
                $yearIndex,
                $yearFolder,
                $dbo ?? null,
                $dbconnect ?? null,
                $notifyEmail,
                $user_home,
                $rdSubjectPrefix,
                $rdLogFile,
                $rdStatusFile,
                $publicHost
            );
        }

        exit(0);
    }
}

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
    $yearState['race_status'] = rr_monitor_build_race_status($liveRaceStatus, $nextScheduledRace, true, ((string)($yearState['final_sent_for_url'] ?? '') === $latestUrl), $latestUrl, $reason);
    $yearState['monitor_ownership'] = rr_monitor_build_ownership($yearState['race_status'], $latestUrl, $raceId);
    $state['byYear'][$yKey] = $yearState;
    rr_save_json($stateFile, $state);

    rr_log_line($logFile, "FINAL detected but no email needed (already notified) url={$latestUrl}");
    rr_monitor_out("FINAL detected, already notified (no email).");

    if ($raceNum !== null && $raceNum > 0 && !$isExh) {
        $publicHost = rr_monitor_public_host($docRoot, __DIR__);
        rr_monitor_run_rd_detection(
            $year,
            (int)$raceNum,
            $raceFolder,
            $raceFolderName,
            $yearIndex,
            $yearFolder,
            $dbo ?? null,
            $dbconnect ?? null,
            $notifyEmail,
            $user_home,
            $rdSubjectPrefix,
            $rdLogFile,
            $rdStatusFile,
            $publicHost
        );
    }

    exit(0);
}

$snapshotPath = '';
if ($snapshotsEnabled) {
    $tsFile = rr_preferred_timestamp(true);
    $snapshotPath = rr_save_snapshot_html($raceFolder, $tsFile, $html2, $snapshotMaxBytes);
    rr_save_snapshot_summary($raceFolder, $tsFile, $html2);
    rr_atomic_write($hashFilePath, $finalHashNow . "\n");
    touch($raceFolder . '/under_review.flag');
    rr_log_line($logFile, "SNAPSHOT SAVED in " . basename($raceFolder));
}

$yearState['final_sent_for_url'] = $latestUrl;
$yearState['final_table_hash'] = $finalHashNow;
$handoffAt = date('c');
$yearState['final_handoff'] = [
    'final_key' => $latestUrl,
    'final_url' => $latestUrl,
    'race_id' => $raceId,
    'race_name' => $raceName,
    'race_number' => $raceNum,
    'race_folder' => $raceFolderName,
    'anchor_at' => $handoffAt,
    'anchor_source' => 'race_results_monitor_snapshot_email',
    'snapshot_file' => $snapshotPath !== '' ? basename($snapshotPath) : '',
    'snapshot_path' => $snapshotPath,
    'email_subject' => '',
    'email_sent' => false,
    'email_sent_at' => '',
    'email_failed_at' => '',
    'updated_at' => $handoffAt,
];
$yearState['race_status'] = rr_monitor_build_race_status($liveRaceStatus, $nextScheduledRace, true, true, $latestUrl, $reason);
$yearState['monitor_ownership'] = rr_monitor_build_ownership($yearState['race_status'], $latestUrl, $raceId);

$state['byYear'][$yKey] = $yearState;
rr_save_json($stateFile, $state);

$publicHost = rr_monitor_public_host($docRoot, __DIR__);
$subjectToken = rr_monitor_subject_token($year, $raceFolderName, $raceName);
$subject = $subjectPrefix . $subjectToken;
$yearState['final_handoff']['email_subject'] = $subject;

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

rr_monitor_out($sentOk ? "EMAIL SENT (FINAL)." : "EMAIL FAILED (FINAL)." );

if (isset($yearState['final_handoff']) && is_array($yearState['final_handoff'])) {
    $emailStatusAt = date('c');
    $yearState['final_handoff']['email_sent'] = $sentOk;
    if ($sentOk) {
        $yearState['final_handoff']['email_sent_at'] = $emailStatusAt;
    } else {
        $yearState['final_handoff']['email_failed_at'] = $emailStatusAt;
    }
    $yearState['final_handoff']['updated_at'] = $emailStatusAt;
    $state['byYear'][$yKey] = $yearState;
    rr_save_json($stateFile, $state);
}

if ($raceNum !== null && $raceNum > 0 && !$isExh) {
    rr_monitor_run_rd_detection(
        $year,
        (int)$raceNum,
        $raceFolder,
        $raceFolderName,
        $yearIndex,
        $yearFolder,
        $dbo ?? null,
        $dbconnect ?? null,
        $notifyEmail,
        $user_home,
        $rdSubjectPrefix,
        $rdLogFile,
        $rdStatusFile,
        $publicHost
    );
}

exit(0);
