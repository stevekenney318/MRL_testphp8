<?php
declare(strict_types=1);

/**
 * submit-team-picks.php
 *
 * VERSION: v004
 * LAST MODIFIED: 4/6/2026 10:26:26 pm
 *
 * DESCRIPTION:
 * Universal team pick submission handler for MRL / testphp8.
 * Supports normal SEG submissions and LP submissions using the same file.
 *
 * CHANGELOG:
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
$scriptVersion = 'v004';

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

function mrl_user_has_change_auth(mysqli $dbconnect, int $uid): bool
{
    $sql = "
        SELECT userID
        FROM users
        WHERE userID = ?
          AND changeAuth = 'Y'
        LIMIT 1
    ";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $uid);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $hasAuth = (mysqli_stmt_num_rows($stmt) === 1);
    mysqli_stmt_close($stmt);

    return $hasAuth;
}

function mrl_determine_pick_type_and_effective_race(
    mysqli $dbconnect,
    int $uid,
    string $raceYearStr,
    int $raceYearInt,
    string $activeSegment
): array {
    $hasChangeAuth = mrl_user_has_change_auth($dbconnect, $uid);

    if ($hasChangeAuth) {
        $lpEffectiveRace = mrl_get_effective_race_for_lp($raceYearInt, $activeSegment);

        if (is_array($lpEffectiveRace) && isset($lpEffectiveRace['race_number'])) {
            return [
                'pick_type' => 'LP',
                'effective_race' => (int)$lpEffectiveRace['race_number'],
            ];
        }
    }

    return [
        'pick_type' => 'SEG',
        'effective_race' => mrl_get_segment_start_race($dbconnect, $raceYearInt, $activeSegment),
    ];
}

$uid = isset($_SESSION['userSession']) ? (int)$_SESSION['userSession'] : 0;
if ($uid <= 0) {
    exit;
}

$raceYearStr = (string)$raceYear;
$raceYearInt = (int)$raceYear;
$activeSegment = ($pickTypeOverride === 'RD' && $rdSegmentPost !== '') ? $rdSegmentPost : (string)$segment;

$driverA = mrl_post_value('group-a-driver');
$driverB = mrl_post_value('group-b-driver');
$driverC = mrl_post_value('group-c-driver');
$driverD = mrl_post_value('group-d-driver');
$submissionId = mrl_post_value('submission_id');
$formID = mrl_post_value('form_id');
$formVersion = mrl_post_value('form_version');
$pickTypeOverride = strtoupper(mrl_post_value('pick_type_override'));
$rdSegmentPost = mrl_post_value('rd_segment');
$rdEffectiveRacePost = mrl_post_value('rd_effective_race');
$rdSupersedesPickIdPost = mrl_post_value('rd_supersedes_pick_id');

if ($submissionId === '') {
    $submissionId = 'sub_' . date('Ymd_His');
}

if ($formID === '') {
    $formID = 'form-team-picks.php';
}

if ($formVersion === '') {
    $formVersion = $scriptVersion;
}

if ($driverA === '' || $driverB === '' || $driverC === '' || $driverD === '') {
    header('Location: /team.php#current_user_team_chart');
    exit;
}

$teamName = mrl_get_team_name($dbconnect, $uid, $raceYearStr);
if ($teamName === '') {
    header('Location: /team.php#current_user_team_chart');
    exit;
}

$currentTime = date('Y-m-d H:i:s');
$ip = mrl_get_client_ip();

$pickType = 'SEG';
$effectiveRace = 0;
$supersedesPickID = null;
$existingPickID = null;
$exists = false;

if ($pickTypeOverride === 'RD') {
    $pickType = 'RD';
    $effectiveRace = mrl_parse_effective_race_value($rdEffectiveRacePost);

    if ($effectiveRace <= 0) {
        header('Location: /team.php#current_user_team_chart');
        exit;
    }

    if ($rdSupersedesPickIdPost !== '' && ctype_digit($rdSupersedesPickIdPost)) {
        $supersedesPickID = (int)$rdSupersedesPickIdPost;
    } else {
        $supersedesPickID = mrl_get_segment_base_pick_id($dbconnect, $uid, $raceYearStr, $activeSegment);
    }

    $existingPickID = mrl_get_existing_pick_id_by_type($dbconnect, $uid, $raceYearStr, $activeSegment, 'RD');
    $exists = ($existingPickID !== null);

    if ($formID === '' || $formID === 'form-team-picks.php') {
        $formID = 'team_replacement_driver.php';
    }
} else {
    $pickMeta = mrl_determine_pick_type_and_effective_race(
        $dbconnect,
        $uid,
        $raceYearStr,
        $raceYearInt,
        $activeSegment
    );

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
