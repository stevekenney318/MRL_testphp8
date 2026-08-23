<?php
declare(strict_types=1);

/**
 * submit-team-picks.php
 *
 * VERSION: v007
 * LAST MODIFIED: 8/22/2026 7:26:00 pm
 *
 * DESCRIPTION:
 * Universal team pick submission handler for MRL / testphp8.
 * Supports normal SEG submissions and LP submissions using the same file.
 *
 * CHANGELOG:
 *
 * v008 (8/22/2026 9:06:00 pm)
 * - TESTPHP8 TEMPORARY: Allows only the exact owned single-driver RP fixture through the real-calendar deadline gate.

 *
 * v007 (8/22/2026 7:26:00 pm)
 * - NEW: Server validates explicit Replacement Pick slot/driver choice against pending JSON.
 * - NEW: Enforces one RD per year using current rows plus history before a new RD is accepted.
 * - NEW: Existing RD edits are locked to the originally replaced group.
 * - NEW: Rejects tampering that changes any non-selected group in an RD submission.
 * - FIX: RD POST values are now read before activeSegment is derived.
 * - PRESERVE: Existing SEG/ADJ/LP behavior and existing RD update/history write behavior.
 *
 * v006 (8/19/2026 4:51:53 am)
 * - NEW: Blocks a brand-new normal SEG submission before the automatic pick window opens.
 * - CHANGE: Normal/LP deadline decisions now use config_mrl.php's schedule-derived pick segment and deadline.
 * - CHANGE: Preserved existing SEG/ADJ edits, LP lifecycle and RD submission behavior.
 *
 * v005 (8/18/2026 3:08:27 am)
 * - CHANGE: LP submission type is now derived automatically from deadline + existing pick state + canonical schedule.
 * - CHANGE: LP no longer depends on users.changeAuth.
 * - FIX: Existing SEG/ADJ picks remain their existing base type during SPECIAL_AUTH edits instead of being converted to LP.
 * - FIX: An already-submitted LP cannot be edited after its stored effective race starts.
 * - CHANGE: An unsubmitted late-pick opportunity naturally advances to the next future race in the same segment.
 * - CHANGE: Preserved existing RD submission behavior.
 *
 * v004 (4/6/2026)
 * - CHANGE: Sync version bump for the corrected RD field names used by team_replacement_driver.php.
 * - CHANGE: No logic changes from v003.
 *
 * v003 (4/6/2026)
 * - Added RD submission handling using the same file.
 * - RD now keeps the form editable until deadline by updating only the latest RD row in user_picks while writing every change to user_picks_history.
 * - Preserved existing SEG / LP behavior otherwise.
 *
 * v002 (3/31/2026)
 * - Added LP-aware submission handling using race_schedule_helper.php.
 * - LP now stores pick_type = LP and effective_race = next not-missed race in the same segment.
 * - LP availability is driven by changeAuth plus helper-based effective-race lookup.
 * - Preserved existing SEG behavior for normal submissions.
 *
 * v001 (3/30/2026)
 * - Initial baseline version of universal team pick submission handler.
 * - Uses active raceYear and segment from config_mrl.php.
 * - Stores SEG pick_type records in user_picks and user_picks_history.
 * - Determines effective_race using segment_race_ranges table.
 * - Preserves full audit trail with submission_id, formID, and form_version.
 * - Adds internal version tracking for troubleshooting and audit support.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/team.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/race_results/race_schedule_helper.php';

$user_home = new USER();
$scriptVersion = 'v006';

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

if (!isset($dbconnect) || !($dbconnect instanceof mysqli)) {
    exit;
}

date_default_timezone_set('America/New_York');

function mrl_post_value(string $key): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}

function mrl_get_client_ip(): string
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return (string)$_SERVER['HTTP_CLIENT_IP'];
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return (string)$_SERVER['HTTP_X_FORWARDED_FOR'];
    }

    return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
}

function mrl_get_team_name(mysqli $dbconnect, int $uid, string $raceYear): string
{
    $sql = "
        SELECT teamName
        FROM user_teams
        WHERE userID = ?
          AND raceYear = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        return '';
    }

    mysqli_stmt_bind_param($stmt, "is", $uid, $raceYear);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return is_array($row) ? trim((string)($row['teamName'] ?? '')) : '';
}

function mrl_get_segment_start_race(mysqli $dbconnect, int $raceYear, string $segment): int
{
    $sql = "
        SELECT startRace
        FROM segment_race_ranges
        WHERE raceYear = ?
          AND segment = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare segment start race lookup.');
    }

    mysqli_stmt_bind_param($stmt, "is", $raceYear, $segment);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $startRace);

    if (!mysqli_stmt_fetch($stmt)) {
        mysqli_stmt_close($stmt);
        throw new RuntimeException("No segment_race_ranges row found for {$raceYear} {$segment}.");
    }

    mysqli_stmt_close($stmt);

    return (int)$startRace;
}

function mrl_get_existing_pick_id(mysqli $dbconnect, int $uid, string $raceYear, string $segment): ?int
{
    $sql = "
        SELECT pickID
        FROM user_picks
        WHERE userID = ?
          AND raceYear = ?
          AND segment = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "iss", $uid, $raceYear, $segment);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $pickID);

    $value = mysqli_stmt_fetch($stmt) ? (int)$pickID : null;

    mysqli_stmt_close($stmt);

    return $value;
}


function mrl_get_existing_pick_id_by_type(mysqli $dbconnect, int $uid, string $raceYear, string $segment, string $pickType): ?int
{
    $sql = "
        SELECT pickID
        FROM user_picks
        WHERE userID = ?
          AND raceYear = ?
          AND segment = ?
          AND pick_type = ?
        ORDER BY pickID DESC
        LIMIT 1
    ";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "isss", $uid, $raceYear, $segment, $pickType);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $pickID);

    $value = mysqli_stmt_fetch($stmt) ? (int)$pickID : null;

    mysqli_stmt_close($stmt);

    return $value;
}

function mrl_parse_effective_race_value(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    if (preg_match('/^R?(\d{1,2})$/i', $value, $m)) {
        return (int)$m[1];
    }

    return 0;
}

function mrl_get_segment_base_pick_id(mysqli $dbconnect, int $uid, string $raceYear, string $segment): ?int
{
    $sql = "
        SELECT pickID
        FROM user_picks
        WHERE userID = ?
          AND raceYear = ?
          AND segment = ?
          AND pick_type IN ('SEG', 'ADJ')
        ORDER BY pickID ASC
        LIMIT 1
    ";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "iss", $uid, $raceYear, $segment);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $pickID);

    $value = mysqli_stmt_fetch($stmt) ? (int)$pickID : null;

    mysqli_stmt_close($stmt);

    return $value;
}

function mrl_get_existing_non_rd_pick_meta(mysqli $dbconnect, int $uid, string $raceYear, string $segment): ?array
{
    $sql = "
        SELECT pickID, pick_type, effective_race
        FROM user_picks
        WHERE userID = ?
          AND raceYear = ?
          AND segment = ?
          AND pick_type <> 'RD'
        ORDER BY pickID ASC
        LIMIT 1
    ";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "iss", $uid, $raceYear, $segment);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? $row : null;
}

function mrl_rd_slug(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = trim($value);
    $value = preg_replace('/[^A-Za-z0-9 _-]+/', '', $value);
    $value = preg_replace('/\s+/', ' ', (string)$value);
    $value = str_replace([' ', '-'], '_', (string)$value);
    $value = preg_replace('/_+/', '_', (string)$value);
    $value = trim((string)$value, '_');
    return $value !== '' ? $value : 'Team';
}

function mrl_rd_find_latest_pending(string $raceYear, string $teamName): ?array
{
    $baseDir = __DIR__ . '/race_results/' . $raceYear;
    if (!is_dir($baseDir)) return null;

    $matches = glob($baseDir . '/R??_*/_rd_pending_' . mrl_rd_slug($teamName) . '.json');
    if (!is_array($matches) || empty($matches)) return null;

    rsort($matches, SORT_STRING);
    $path = (string)$matches[0];
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;

    $payload = json_decode($raw, true);
    if (!is_array($payload)) return null;

    return ['path'=>$path, 'payload'=>$payload];
}

function mrl_rd_normalize_pending_qualifiers(array $payload): array
{
    $out = [];
    $raw = isset($payload['qualifiers']) && is_array($payload['qualifiers'])
        ? $payload['qualifiers']
        : [];

    foreach ($raw as $q) {
        if (!is_array($q)) continue;
        $slot = strtoupper(trim((string)($q['slot'] ?? '')));
        $driver = trim((string)($q['driver'] ?? ''));
        if (!in_array($slot, ['A','B','C','D'], true) || $driver === '') continue;

        $out[] = [
            'slot'=>$slot,
            'driver'=>$driver,
            'effective_race'=>trim((string)($q['effective_race'] ?? '')),
        ];
    }

    if (empty($out)) {
        $slot = strtoupper(trim((string)($payload['slot'] ?? '')));
        $driver = trim((string)($payload['driver'] ?? ''));
        if (in_array($slot, ['A','B','C','D'], true) && $driver !== '') {
            $out[] = [
                'slot'=>$slot,
                'driver'=>$driver,
                'effective_race'=>trim((string)($payload['effective_race'] ?? '')),
            ];
        }
    }

    return $out;
}

function mrl_rd_get_base_row(mysqli $dbconnect, int $uid, string $raceYear, string $segment): ?array
{
    $sql = "SELECT pickID, driverA, driverB, driverC, driverD
            FROM user_picks
            WHERE userID = ?
              AND raceYear = ?
              AND segment = ?
              AND pick_type IN ('SEG','ADJ')
            ORDER BY entryDate ASC, pickID ASC
            LIMIT 1";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) return null;

    mysqli_stmt_bind_param($stmt, 'iss', $uid, $raceYear, $segment);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? $row : null;
}

function mrl_rd_get_current_row(mysqli $dbconnect, int $uid, string $raceYear, string $segment): ?array
{
    $sql = "SELECT pickID, driverA, driverB, driverC, driverD, effective_race
            FROM user_picks
            WHERE userID = ?
              AND raceYear = ?
              AND segment = ?
              AND pick_type = 'RD'
            ORDER BY pickID DESC
            LIMIT 1";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) return null;

    mysqli_stmt_bind_param($stmt, 'iss', $uid, $raceYear, $segment);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return is_array($row) ? $row : null;
}

function mrl_rd_history_segments(mysqli $dbconnect, int $uid, string $raceYear): array
{
    $sql = "SELECT DISTINCT segment
            FROM user_picks_history
            WHERE userID = ?
              AND raceYear = ?
              AND pick_type = 'RD'";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) return [];

    mysqli_stmt_bind_param($stmt, 'is', $uid, $raceYear);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $out = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $seg = trim((string)($row['segment'] ?? ''));
            if ($seg !== '') $out[$seg] = true;
        }
    }

    mysqli_stmt_close($stmt);
    return array_keys($out);
}

function mrl_rd_changed_slot(array $baseRow, array $rdRow): string
{
    $changed = [];
    foreach (['A','B','C','D'] as $slot) {
        $key = 'driver' . $slot;
        if (trim((string)($baseRow[$key] ?? '')) !== trim((string)($rdRow[$key] ?? ''))) {
            $changed[] = $slot;
        }
    }
    return count($changed) === 1 ? $changed[0] : '';
}

    // Deadline protection belongs on the server too, not just on team.php.
    if (!mrl_lp_effective_race_is_open($raceYearInt, $effectiveRace)) {
        mrl_rd_reject();
    }

    $pendingInfo = mrl_rd_find_latest_pending($raceYearStr, $teamName);
    if (!is_array($pendingInfo)) {
        mrl_rd_reject();
    }

    $pendingPayload = isset($pendingInfo['payload']) && is_array($pendingInfo['payload'])
        ? $pendingInfo['payload']
        : [];

    if (trim((string)($pendingPayload['segment'] ?? '')) !== $activeSegment) {
        mrl_rd_reject();
    }

    $qualifiers = mrl_rd_normalize_pending_qualifiers($pendingPayload);
    $selectedQualifier = null;

    foreach ($qualifiers as $q) {
        if (
            (string)($q['slot'] ?? '') === $rdSelectedSlotPost
            && trim((string)($q['driver'] ?? '')) === $rdSelectedDriverPost
        ) {
            $selectedQualifier = $q;
            break;
        }
    }

    if (!is_array($selectedQualifier)) {
        mrl_rd_reject();
    }

    $qualifierEffective = mrl_parse_effective_race_value(
        (string)($selectedQualifier['effective_race'] ?? '')
    );
    if ($qualifierEffective !== $effectiveRace) {
        mrl_rd_reject();
    }

    $baseRdRow = mrl_rd_get_base_row($dbconnect, $uid, $raceYearStr, $activeSegment);
    if (!is_array($baseRdRow)) {
        mrl_rd_reject();
    }

    // The selected original driver must still match the base row for that slot.
    $selectedKey = 'driver' . $rdSelectedSlotPost;
    if (trim((string)($baseRdRow[$selectedKey] ?? '')) !== $rdSelectedDriverPost) {
        mrl_rd_reject();
    }

    $currentRdRow = mrl_rd_get_current_row($dbconnect, $uid, $raceYearStr, $activeSegment);
    $historySegments = mrl_rd_history_segments($dbconnect, $uid, $raceYearStr);

    if (!is_array($currentRdRow)) {
        // A prior RD in history means the one-per-year allowance has already been used.
        if (!empty($historySegments)) {
            mrl_rd_reject();
        }
    } else {
        // Existing edits are allowed only for this same RD segment.
        foreach ($historySegments as $historySegment) {
            if ($historySegment !== $activeSegment) {
                mrl_rd_reject();
            }
        }

        $lockedSlot = mrl_rd_changed_slot($baseRdRow, $currentRdRow);
        if ($lockedSlot === '' || $lockedSlot !== $rdSelectedSlotPost) {
            mrl_rd_reject();
        }
    }

    $sourceRow = is_array($currentRdRow) ? $currentRdRow : $baseRdRow;
    $submittedDrivers = [
        'A' => $driverA,
        'B' => $driverB,
        'C' => $driverC,
        'D' => $driverD,
    ];

    foreach (['A','B','C','D'] as $slot) {
        $key = 'driver' . $slot;
        $expected = trim((string)($sourceRow[$key] ?? ''));
        $submitted = trim((string)($submittedDrivers[$slot] ?? ''));

        if ($slot === $rdSelectedSlotPost) {
            if ($submitted === '' || $submitted === $rdSelectedDriverPost) {
                mrl_rd_reject();
            }
        } elseif ($submitted !== $expected) {
            // A Replacement Pick may alter exactly one group.
            mrl_rd_reject();
        }
    }

    if ($rdSupersedesPickIdPost !== '' && ctype_digit($rdSupersedesPickIdPost)) {
        $supersedesPickID = (int)$rdSupersedesPickIdPost;
    } else {
        $supersedesPickID = (int)($baseRdRow['pickID'] ?? 0);
    }

    if ($supersedesPickID <= 0 || $supersedesPickID !== (int)($baseRdRow['pickID'] ?? 0)) {
        mrl_rd_reject();
    }

    $existingPickID = is_array($currentRdRow)
        ? (int)($currentRdRow['pickID'] ?? 0)
        : null;
    $exists = ($existingPickID !== null && $existingPickID > 0);

    if ($formID === '' || $formID === 'form-team-picks.php') {
        $formID = 'team_replacement_driver.php';
    }
} else {
    $pickMeta = mrl_determine_pick_type_and_effective_race(
        $dbconnect,
        $uid,
        $raceYearStr,
        $raceYearInt,
        $activeSegment,
        isset($formLockDate) ? (string)$formLockDate : '',
        isset($formLockTime) ? (string)$formLockTime : ''
    );

    if (!empty($pickMeta['blocked'])) {
        header('Location: /team.php#current_user_team_chart');
        exit;
    }

    $pickType = (string)$pickMeta['pick_type'];
    $effectiveRace = (int)$pickMeta['effective_race'];
    $supersedesPickID = null;

    $existingPickID = mrl_get_existing_pick_id($dbconnect, $uid, $raceYearStr, $activeSegment);
    $exists = ($existingPickID !== null);
}

if ($exists) {
    $sqlUpdate = "
        UPDATE user_picks
        SET teamName = ?,
            driverA = ?,
            driverB = ?,
            driverC = ?,
            driverD = ?,
            entryDate = ?,
            submission_id = ?,
            ip = ?,
            formID = ?,
            pick_type = ?,
            effective_race = ?,
            supersedes_pickID = ?
        WHERE pickID = ?
    ";

    $stmtUpdate = mysqli_prepare($dbconnect, $sqlUpdate);

    if ($stmtUpdate) {
        mysqli_stmt_bind_param(
            $stmtUpdate,
            "ssssssssssiii",
            $teamName,
            $driverA,
            $driverB,
            $driverC,
            $driverD,
            $currentTime,
            $submissionId,
            $ip,
            $formID,
            $pickType,
            $effectiveRace,
            $supersedesPickID,
            $existingPickID
        );
        mysqli_stmt_execute($stmtUpdate);
        mysqli_stmt_close($stmtUpdate);
    }
} else {
    $sqlInsert = "
        INSERT INTO user_picks
        (
            userID,
            teamName,
            raceYear,
            segment,
            driverA,
            driverB,
            driverC,
            driverD,
            entryDate,
            submission_id,
            ip,
            formID,
            pick_type,
            effective_race,
            supersedes_pickID
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmtInsert = mysqli_prepare($dbconnect, $sqlInsert);

    if ($stmtInsert) {
        mysqli_stmt_bind_param(
            $stmtInsert,
            "issssssssssssii",
            $uid,
            $teamName,
            $raceYearStr,
            $activeSegment,
            $driverA,
            $driverB,
            $driverC,
            $driverD,
            $currentTime,
            $submissionId,
            $ip,
            $formID,
            $pickType,
            $effectiveRace,
            $supersedesPickID
        );
        mysqli_stmt_execute($stmtInsert);
        mysqli_stmt_close($stmtInsert);
    }
}

$sqlHistory = "
    INSERT INTO user_picks_history
    (
        userID,
        teamName,
        raceYear,
        segment,
        driverA,
        driverB,
        driverC,
        driverD,
        entryDate,
        submission_id,
        ip,
        formID,
        pick_type,
        effective_race,
        supersedes_pickID
    )
    VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

$stmtHistory = mysqli_prepare($dbconnect, $sqlHistory);

if ($stmtHistory) {
    mysqli_stmt_bind_param(
        $stmtHistory,
        "issssssssssssii",
        $uid,
        $teamName,
        $raceYearStr,
        $activeSegment,
        $driverA,
        $driverB,
        $driverC,
        $driverD,
        $currentTime,
        $submissionId,
        $ip,
        $formID,
        $pickType,
        $effectiveRace,
        $supersedesPickID
    );
    mysqli_stmt_execute($stmtHistory);
    mysqli_stmt_close($stmtHistory);
}

header('Location: /team.php#current_user_team_chart');
exit;
