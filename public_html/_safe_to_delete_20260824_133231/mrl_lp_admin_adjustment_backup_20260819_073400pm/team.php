<?php
declare(strict_types=1);

/**
 * team.php
 *
 * VERSION: v019
 * LAST MODIFIED: 8/19/2026 4:51:53 am
 *
 * DESCRIPTION:
 * Main universal team landing page for MRL / testphp8.
 * Keeps team.php as the single controller / landing point while supporting
 * normal picks now and LP / RD form routing later.
 *
 * CHANGELOG:
 *
 * v019 (8/19/2026 4:51:53 am)
 * - NEW: Normal pick-window availability now follows the shared automatic pick-window state.
 * - NEW: Normal picks open 15 days before the first race in the pick segment and close at that race start.
 * - CHANGE: Uses config_mrl.php's backward-compatible pick-segment mapping instead of requiring manual admin segment/deadline changes.
 * - SAFETY: Before a future segment's normal window opens, direct normal-form display remains blocked.
 * - CHANGE: Preserved LP, SPECIAL_AUTH and RD routing after the active pick-segment deadline.
 *
 * v018 (8/18/2026 4:13:00 am)
 * - CHANGE: RD deadline lookup now uses shared race_schedule_helper.php.
 * - CHANGE: LP and RD timing now share /race_results/_race_results_schedule.json.
 * - CHANGE: Removed team.php dependency on legacy race_results/<year>/_schedule.json.
 * - CHANGE: RD deadline lookup now respects DB-defined segment boundaries through the shared helper.
 * - CHANGE: Preserved automatic LP, SPECIAL_AUTH, normal-pick and RD routing behavior.
 *
 * v017 (8/18/2026 3:08:27 am)
 * - CHANGE: LP eligibility is now automatic and no longer requires changeAuth.
 * - CHANGE: changeAuth remains available only through the existing SPECIAL_AUTH/admin override path.
 * - FIX: Existing SEG/ADJ picks no longer become LP merely because changeAuth is enabled.
 * - NEW: Existing LP picks remain editable only until their stored effective race starts.
 * - NEW: Users with no segment pick automatically roll to the next future same-segment LP race until they submit or the segment ends.
 * - CHANGE: Preserved existing normal-pick and RD routing behavior.
 *
 * v016 (4/7/2026)
 * - CHANGE: Sync version bump only so team.php stays aligned with the updated RD wrapper terminology and hidden effective-race value.
 * - CHANGE: No logic changes from v015.
 *
 * v015 (4/6/2026)
 * - FIX: RD dropdown now only preselects an existing RD choice and no longer falls back to the original replaced driver.
 * - FIX: Preserved base segment row for readonly display while separating latest RD selection logic for the editable group.
 * - CHANGE: Preserved repeated-change RD behavior before deadline.
 *
 * v014 (4/6/2026)
 * - CHANGE: RD form now remains available for repeated changes until the schedule-based deadline passes.
 * - FIX: Added latest-RD-row loading so the current replacement choice stays visible/editable before deadline.
 * - FIX: RD option filtering now excludes the original replaced driver plus the other current team drivers while preserving the latest selected RD choice.
 *
 * v013 (4/6/2026)
 * - CHANGE: Sync version bump only so team.php stays aligned with the latest RD wrapper update.
 * - CHANGE: No logic changes from v012.
 *
 * v012 (4/6/2026)
 * - FIX: RD form now auto-expires based on the actual next-race start time from race_results/<year>/_schedule.json.
 * - FIX: Added RD deadline metadata for display in the RD wrapper (deadline race + ESPN schedule time).
 * - CHANGE: Preserved existing RD pending detection, routing, and current-year replacement dropdown logic.
 *
 * v011 (4/6/2026)
 * - FIX: Restored RD pending-file detection and routing logic in team.php.
 * - FIX: Reconnected RD helper values, base pick row lookup, and current-year replacement dropdown generation.
 * - FIX: Restored post-deadline branch to load team_replacement_driver.php when a valid RD pending file exists and no RD has already been submitted for that segment.
 *
 * v010 (4/6/2026)
 * - FIX: RD replacement dropdown now uses the same current-year driver filtering logic as form-team-picks.php.
 * - FIX: Eliminates duplicate drivers and drivers from other years in RD dropdown.
 * - Preserves existing LP / normal / special-auth routing behavior.
 *
 * v009 (4/6/2026)
 * - Added RD pending-file detection for the logged-in user's team.
 * - Added RD helper values for team name, pending JSON payload, current pick row, and replacement dropdown options.
 * - Added automatic RD routing to team_replacement_driver.php when a valid pending RD exists and no RD has already been submitted for that segment.
 * - Preserved existing LP / special-auth / normal behavior otherwise.
 *
 * v008 (4/1/2026)
 * - Added /race_results/weekly_standings.php to admin menu.
 *
 * v007 (3/31/2026)
 * - LP eligibility now requires a future points race still remain in the same active segment.
 * - Integrated race_schedule_helper.php for LP effective-race availability checks.
 * - Prevents LP form from showing when the active segment is already effectively over.
 * - Preserved existing normal form, special-auth, and past-deadline behavior otherwise.
 *
 * v006 (3/30/2026)
 * - Restored current segment team chart in the normal past-deadline / no-auth path.
 * - Keeps LP / special-auth users on the special wrapper path after deadline.
 * - Preserved working LP timing logic and normal pre-deadline form behavior.
 *
 * v005 (3/30/2026)
 * - Fixed LP routing so it only applies after the normal deadline has passed.
 * - Kept normal form behavior before deadline.
 * - Preserved SPECIAL_AUTH / LP wrapper path for post-deadline special handling.
 * - Prepared clean branch points for future RD logic.
 *
 * v004 (3/30/2026)
 * - Added LP-first routing logic in team.php.
 * - Added form mode detection for LP / RD / NORMAL while keeping current live behavior stable.
 * - LP is now detected when changeAuth = Y and the user has no picks yet for the active segment.
 * - RD is reserved as the next step and currently falls back to the normal special wrapper path.
 *
 * v003 (3/30/2026)
 * - Renamed internal helper functions with team-page-specific prefixes to avoid collisions with included form files.
 * - Preserved current behavior: normal picks still use admin_setup.currentForm and changeAuth still routes through team-late-pick.php.
 * - Kept optional DB debug banner toggle and environment-safe admin links.
 */

session_start();

$_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/team.php';

require_once 'class.user.php';
$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

date_default_timezone_set('America/New_York');
require 'config.php';
require 'config_mrl.php';
require_once __DIR__ . '/race_results/race_schedule_helper.php';

$currentTimeIs = date('n/j/Y g:i a');

$showDbDebugBanner = true;

function teampage_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function teampage_current_host(): string
{
    return isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? (string)$_SERVER['HTTP_HOST']
        : 'manliusracingleague.com';
}

function teampage_absolute_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        $path = '/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    return 'https://' . teampage_current_host() . $path;
}

function teampage_hostinger_site_url(string $suffix = ''): string
{
    $host = teampage_current_host();
    $base = 'https://hpanel.hostinger.com/websites/' . rawurlencode($host);

    if ($suffix === '') {
        return $base;
    }
    if ($suffix[0] !== '/') {
        $suffix = '/' . $suffix;
    }

    return $base . $suffix;
}

function teampage_get_current_db_names(USER $user_home, $dbo, $dbconnect): array
{
    $userDbName = '';
    $pdoDbName  = '';
    $myDbName   = '';

    try {
        $stmtDb = $user_home->runQuery("SELECT DATABASE() AS db");
        $stmtDb->execute();
        $rowDb = $stmtDb->fetch(PDO::FETCH_ASSOC);
        $userDbName = isset($rowDb['db']) ? (string)$rowDb['db'] : '';
    } catch (Throwable $e) {
        $userDbName = '';
    }

    try {
        if (isset($dbo) && $dbo instanceof PDO) {
            $pdoDbName = (string)$dbo->query("SELECT DATABASE()")->fetchColumn();
        }
    } catch (Throwable $e) {
        $pdoDbName = '';
    }

    try {
        if (isset($dbconnect) && $dbconnect instanceof mysqli) {
            $res = mysqli_query($dbconnect, "SELECT DATABASE() AS db");
            if ($res) {
                $row = mysqli_fetch_assoc($res);
                $myDbName = isset($row['db']) ? (string)$row['db'] : '';
            }
        }
    } catch (Throwable $e) {
        $myDbName = '';
    }

    return [
        'userDbName' => $userDbName,
        'pdoDbName'  => $pdoDbName,
        'myDbName'   => $myDbName,
    ];
}

function teampage_render_db_debug_banner(array $dbNames): string
{
    $parts = [];
    $parts[] = 'USER(PDO): ' . teampage_h($dbNames['userDbName'] !== '' ? $dbNames['userDbName'] : '(unknown)');
    $parts[] = 'dbo(PDO): ' . teampage_h($dbNames['pdoDbName'] !== '' ? $dbNames['pdoDbName'] : '(unknown)');
    $parts[] = 'dbconnect(mysqli): ' . teampage_h($dbNames['myDbName'] !== '' ? $dbNames['myDbName'] : '(unknown)');
    $parts[] = 'HOST: ' . teampage_h(teampage_current_host());

    return '<div style="padding:8px 12px; color:#fff; background:#333; font-family:Arial, sans-serif; font-size:14px;">Connected DBs: '
        . implode(' | ', $parts)
        . '</div>';
}

function teampage_user_has_change_auth(USER $user_home, int $uid): bool
{
    $stmt = $user_home->runQuery("SELECT userID FROM users WHERE userID = :uid AND changeAuth = :changeAuth");
    $stmt->execute([
        ':uid' => $uid,
        ':changeAuth' => 'Y',
    ]);

    return ($stmt->rowCount() === 1);
}


function teampage_get_current_user_team_name(mysqli $dbconnect, int $uid, string $raceYear): string
{
    $sql = "SELECT teamName
            FROM user_teams
            WHERE userID = ?
              AND raceYear = ?
            LIMIT 1";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        return '';
    }

    mysqli_stmt_bind_param($stmt, 'is', $uid, $raceYear);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);
    $teamName = '';

    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $teamName = trim((string)($row['teamName'] ?? ''));
    }

    mysqli_stmt_close($stmt);
    return $teamName;
}

function teampage_rd_slug(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = trim($value);
    $value = preg_replace('/[^A-Za-z0-9 _-]+/', '', $value);
    $value = preg_replace('/\s+/', ' ', (string)$value);
    $value = str_replace([' ', '-'], '_', (string)$value);
    $value = preg_replace('/_+/', '_', (string)$value);
    $value = trim((string)$value, '_');

    return ($value !== '') ? $value : 'Team';
}

function teampage_find_latest_rd_pending(string $raceYear, string $teamName): ?array
{
    $teamSlug = teampage_rd_slug($teamName);
    $baseDir = __DIR__ . '/race_results/' . $raceYear;

    if (!is_dir($baseDir)) {
        return null;
    }

    $pattern = $baseDir . '/R??_*/_rd_pending_' . $teamSlug . '.json';
    $matches = glob($pattern);

    if (!is_array($matches) || empty($matches)) {
        return null;
    }

    rsort($matches, SORT_STRING);
    $jsonPath = (string)$matches[0];

    $payloadRaw = @file_get_contents($jsonPath);
    if ($payloadRaw === false || trim($payloadRaw) === '') {
        return null;
    }

    $payload = json_decode($payloadRaw, true);
    if (!is_array($payload)) {
        return null;
    }

    return [
        'jsonPath' => $jsonPath,
        'raceFolderName' => basename(dirname($jsonPath)),
        'payload' => $payload,
    ];
}

function teampage_user_has_rd_for_segment(PDO $dbo, int $uid, string $raceYear, string $segment): bool
{
    $sql = "SELECT pickID
            FROM user_picks
            WHERE userID = :uid
              AND raceYear = :raceYear
              AND segment = :segment
              AND pick_type = 'RD'
            LIMIT 1";

    $stmt = $dbo->prepare($sql);
    $stmt->execute([
        ':uid' => $uid,
        ':raceYear' => $raceYear,
        ':segment' => $segment,
    ]);

    return ($stmt->fetch(PDO::FETCH_ASSOC) !== false);
}

function teampage_get_segment_base_pick_row(PDO $dbo, int $uid, string $raceYear, string $segment): ?array
{
    $sql = "SELECT pickID, driverA, driverB, driverC, driverD
            FROM user_picks
            WHERE userID = :uid
              AND raceYear = :raceYear
              AND segment = :segment
              AND pick_type IN ('SEG', 'ADJ')
            ORDER BY entryDate ASC, pickID ASC
            LIMIT 1";

    $stmt = $dbo->prepare($sql);
    $stmt->execute([
        ':uid' => $uid,
        ':raceYear' => $raceYear,
        ':segment' => $segment,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}


function teampage_get_latest_rd_pick_row(PDO $dbo, int $uid, string $raceYear, string $segment): ?array
{
    $sql = "SELECT pickID, driverA, driverB, driverC, driverD, effective_race, entryDate
            FROM user_picks
            WHERE userID = :uid
              AND raceYear = :raceYear
              AND segment = :segment
              AND pick_type = 'RD'
            ORDER BY pickID DESC
            LIMIT 1";

    $stmt = $dbo->prepare($sql);
    $stmt->execute([
        ':uid' => $uid,
        ':raceYear' => $raceYear,
        ':segment' => $segment,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function teampage_rd_driver_options(mysqli $dbconnect, string $slot, int $raceYear, int $uid, string $segment, array $excludeDrivers): array
{
    $tableMap = [
        'A' => ['table' => 'A Drivers', 'column' => 'driverA'],
        'B' => ['table' => 'B Drivers', 'column' => 'driverB'],
        'C' => ['table' => 'C Drivers', 'column' => 'driverC'],
        'D' => ['table' => 'D Drivers', 'column' => 'driverD'],
    ];

    $slot = strtoupper(trim($slot));
    if (!isset($tableMap[$slot])) {
        return [];
    }

    $tableName = $tableMap[$slot]['table'];
    $columnName = $tableMap[$slot]['column'];
    $raceYearStr = (string)$raceYear;

    $sql = "
        SELECT driverName, Tag
        FROM `$tableName`
        WHERE driverYear = ?
          AND Available = 'Y'
          AND driverName NOT IN (
              SELECT `$columnName`
              FROM user_picks
              WHERE userID = ?
                AND raceYear = ?
                AND segment != ?
          )
    ";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "iiss", $raceYear, $uid, $raceYearStr, $segment);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    $excludeMap = [];

    foreach ($excludeDrivers as $driverName) {
        $driverName = trim((string)$driverName);
        if ($driverName !== '') {
            $excludeMap[$driverName] = true;
        }
    }

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $driverName = trim((string)($row['driverName'] ?? ''));
            $driverTag = trim((string)($row['Tag'] ?? ''));

            if ($driverName === '' || isset($excludeMap[$driverName])) {
                continue;
            }

            $rows[] = [
                'driverName' => $driverName,
                'tag' => $driverTag,
            ];
        }
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function teampage_rd_segment_label(string $segment): string
{
    $segment = strtoupper(trim($segment));

    if ($segment === 'S1') return 'Segment #1';
    if ($segment === 'S2') return 'Segment #2';
    if ($segment === 'S3') return 'Segment #3';
    if ($segment === 'S4') return 'Segment #4';

    return $segment;
}


function teampage_rd_folder_race_number(string $raceFolderName): int
{
    if (preg_match('/^R(\d{2})_/', $raceFolderName, $m)) {
        return (int)$m[1];
    }

    return 0;
}

function teampage_schedule_deadline_info(
    string $raceYear,
    string $segment,
    string $raceFolderName
): array {
    $currentRaceNumber = teampage_rd_folder_race_number($raceFolderName);
    if ($currentRaceNumber <= 0) {
        return [];
    }

    try {
        $row = mrl_schedule_helper_next_race_in_segment(
            (int)$raceYear,
            $segment,
            $currentRaceNumber
        );

        if (!is_array($row)) {
            return [];
        }

        $deadlineRaceNumber = mrl_schedule_helper_race_number($row);
        $dt = mrl_schedule_helper_race_datetime($row);

        return [
            'deadline_race_number' => $deadlineRaceNumber,
            'deadline_race_code' => (string)($row['mrl_race_code'] ?? ('R' . str_pad((string)$deadlineRaceNumber, 2, '0', STR_PAD_LEFT))),
            'deadline_timestamp' => $dt->getTimestamp(),
            'deadline_display' => $dt->format('n/j/Y g:i a') . ' ET',
            'deadline_datetime_et' => $dt->format('Y-m-d H:i:s'),
        ];
    } catch (Throwable $e) {
        return [];
    }
}

function teampage_rd_should_show(?array $rdPending): bool
{
    return ($rdPending !== null);
}

function teampage_user_has_active_segment_pick(PDO $dbo, int $uid, string $raceYear, string $segment): bool
{
    $sql = "SELECT pickID
            FROM user_picks
            WHERE userID = :uid
              AND raceYear = :raceYear
              AND segment = :segment
            LIMIT 1";

    $stmt = $dbo->prepare($sql);
    $stmt->execute([
        ':uid' => $uid,
        ':raceYear' => $raceYear,
        ':segment' => $segment,
    ]);

    return ($stmt->fetch(PDO::FETCH_ASSOC) !== false);
}

function teampage_lp_effective_race_exists(string $raceYear, string $segment): bool
{
    try {
        $lpEffectiveRace = mrl_get_effective_race_for_lp((int)$raceYear, $segment);

        return is_array($lpEffectiveRace) && !empty($lpEffectiveRace);
    } catch (Throwable $e) {
        return false;
    }
}

function teampage_get_lp_pick_row(PDO $dbo, int $uid, string $raceYear, string $segment): ?array
{
    $sql = "SELECT pickID, effective_race
            FROM user_picks
            WHERE userID = :uid
              AND raceYear = :raceYear
              AND segment = :segment
              AND pick_type = 'LP'
            ORDER BY pickID DESC
            LIMIT 1";

    $stmt = $dbo->prepare($sql);
    $stmt->execute([
        ':uid' => $uid,
        ':raceYear' => $raceYear,
        ':segment' => $segment,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function teampage_lp_pick_window_open(string $raceYear, array $lpPickRow): bool
{
    $effectiveRace = (int)($lpPickRow['effective_race'] ?? 0);
    if ($effectiveRace <= 0) {
        return false;
    }

    try {
        $now = new DateTimeImmutable('now', mrl_schedule_helper_timezone());
        $races = mrl_schedule_helper_points_races((int)$raceYear);

        foreach ($races as $race) {
            if ((int)($race['race_number'] ?? 0) !== $effectiveRace) {
                continue;
            }

            $raceStart = mrl_schedule_helper_race_datetime($race);
            return ($now < $raceStart);
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

function teampage_determine_form_mode(USER $user_home, PDO $dbo, int $uid, string $raceYear, string $segment): string
{
    // A legitimate base segment pick blocks automatic LP.  changeAuth may still
    // deliberately open the existing SPECIAL_AUTH/admin override path.
    $basePickRow = teampage_get_segment_base_pick_row($dbo, $uid, $raceYear, $segment);
    if (is_array($basePickRow)) {
        return teampage_user_has_change_auth($user_home, $uid) ? 'SPECIAL_AUTH' : 'NORMAL';
    }

    // If an LP was already submitted, it may be edited only until that LP's
    // stored effective race starts.  It does NOT roll forward after submission.
    $lpPickRow = teampage_get_lp_pick_row($dbo, $uid, $raceYear, $segment);
    if (is_array($lpPickRow)) {
        if (teampage_lp_pick_window_open($raceYear, $lpPickRow)) {
            return 'LP';
        }

        return teampage_user_has_change_auth($user_home, $uid) ? 'SPECIAL_AUTH' : 'NORMAL';
    }

    // No SEG/ADJ/LP pick exists.  Automatic LP is available whenever the
    // canonical schedule still contains a future points race in this segment.
    // team.php only uses this mode after the original segment deadline passes.
    if (teampage_lp_effective_race_exists($raceYear, $segment)) {
        return 'LP';
    }

    // Keep the existing manual admin override available for unforeseen cases,
    // but it is no longer part of normal LP eligibility.
    if (teampage_user_has_change_auth($user_home, $uid)) {
        return 'SPECIAL_AUTH';
    }

    return 'NORMAL';
}

$dbNames = teampage_get_current_db_names($user_home, $dbo, $dbconnect);

$stmt = $user_home->runQuery("SELECT * FROM users WHERE userID = :uid");
$stmt->execute([':uid' => $_SESSION['userSession']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$name_parts = explode(' ', (string)($row['userName'] ?? ''));
$first_name = $name_parts[0] ?? '';

$uid = (int)($_SESSION['userSession'] ?? 0);
$isAdmin = isAdmin($uid);

require_once 'team_name.php';

if (isset($dbconnect)) {
    mrl_teamname_handle_ajax($dbconnect);
}

$teamNameMessage = '';
if (isset($dbconnect)) {
    $teamNameMessage = mrl_teamname_handle_save($dbconnect, (string)$raceYear, $uid);
}

$teamFormMode = teampage_determine_form_mode($user_home, $dbo, $uid, (string)$raceYear, (string)$segment);

$currentUserTeamName = '';
$rdPendingInfo = null;
$showRdWrapper = false;
$rdPendingPayload = [];
$rdPendingSegment = '';
$rdPendingSegmentLabel = '';
$rdPendingGroup = '';
$rdPendingCurrentDriver = '';
$rdPendingTriggerRaces = '';
$rdPendingEffectiveRace = '';
$rdBasePickRow = null;
$rdLatestPickRow = null;
$rdActivePickRow = null;
$rdReplacementOptions = [];
$rdDeadlineRaceCode = '';
$rdDeadlineDisplay = '';
$rdDeadlineTimestamp = 0;
$rdSelectedDriver = '';

if (isset($dbconnect) && $dbconnect instanceof mysqli) {
    $currentUserTeamName = teampage_get_current_user_team_name($dbconnect, $uid, (string)$raceYear);
}

if ($currentUserTeamName !== '') {
    $rdPendingInfo = teampage_find_latest_rd_pending((string)$raceYear, $currentUserTeamName);
}

if ($rdPendingInfo !== null) {
    $rdPendingPayload = isset($rdPendingInfo['payload']) && is_array($rdPendingInfo['payload'])
        ? $rdPendingInfo['payload']
        : [];

    $rdPendingSegment = trim((string)($rdPendingPayload['segment'] ?? ''));
    $showRdWrapper = teampage_rd_should_show($rdPendingInfo);

    if ($showRdWrapper) {
        $rdPendingSegmentLabel = teampage_rd_segment_label($rdPendingSegment);
        $rdPendingGroup = strtoupper(trim((string)($rdPendingPayload['slot'] ?? '')));
        $rdPendingCurrentDriver = trim((string)($rdPendingPayload['driver'] ?? ''));
        $rdPendingEffectiveRace = trim((string)($rdPendingPayload['effective_race'] ?? ''));

        $triggerRaceList = isset($rdPendingPayload['trigger_races']) && is_array($rdPendingPayload['trigger_races'])
            ? $rdPendingPayload['trigger_races']
            : [];
        $rdPendingTriggerRaces = implode(', ', $triggerRaceList);

        $rdBasePickRow = teampage_get_segment_base_pick_row($dbo, $uid, (string)$raceYear, $rdPendingSegment);
        $rdLatestPickRow = teampage_get_latest_rd_pick_row($dbo, $uid, (string)$raceYear, $rdPendingSegment);
        $rdActivePickRow = is_array($rdLatestPickRow) ? $rdLatestPickRow : $rdBasePickRow;

        if (is_array($rdLatestPickRow)) {
            $slotKey = 'driver' . $rdPendingGroup;
            $rdSelectedDriver = trim((string)($rdLatestPickRow[$slotKey] ?? ''));
        }

        if (is_array($rdActivePickRow) && isset($dbconnect) && $dbconnect instanceof mysqli) {
            $excludeDrivers = [];

            foreach (['A', 'B', 'C', 'D'] as $groupCode) {
                $driverKey = 'driver' . $groupCode;
                $driverValue = trim((string)($rdActivePickRow[$driverKey] ?? ''));

                if ($groupCode !== $rdPendingGroup && $driverValue !== '') {
                    $excludeDrivers[] = $driverValue;
                }
            }

            if ($rdPendingCurrentDriver !== '' && $rdPendingCurrentDriver !== $rdSelectedDriver) {
                $excludeDrivers[] = $rdPendingCurrentDriver;
            }

            $rdReplacementOptions = teampage_rd_driver_options($dbconnect, $rdPendingGroup, (int)$raceYear, $uid, $rdPendingSegment, $excludeDrivers);
        }

        $deadlineInfo = teampage_schedule_deadline_info((string)$raceYear, $rdPendingSegment, (string)($rdPendingInfo['raceFolderName'] ?? ''));
        if (!empty($deadlineInfo)) {
            $rdDeadlineRaceCode = (string)($deadlineInfo['deadline_race_code'] ?? '');
            $rdDeadlineDisplay = (string)($deadlineInfo['deadline_display'] ?? '');
            $rdDeadlineTimestamp = (int)($deadlineInfo['deadline_timestamp'] ?? 0);

            if ($rdDeadlineTimestamp > 0 && time() >= $rdDeadlineTimestamp) {
                $showRdWrapper = false;
            }
        }
    }
}

$wpAdminUrl = teampage_absolute_url('/wp-login.php');
$hostingerBackupsUrl = teampage_hostinger_site_url('/files/backups');
$hostingerPanelUrl = teampage_hostinger_site_url();
$phpMyAdminDb = $dbNames['myDbName'] !== '' ? $dbNames['myDbName'] : ($dbNames['pdoDbName'] !== '' ? $dbNames['pdoDbName'] : '');
$phpMyAdminUrl = $phpMyAdminDb !== ''
    ? 'https://auth-db1928.hstgr.io/index.php?db=' . rawurlencode($phpMyAdminDb)
    : 'https://auth-db1928.hstgr.io/';
?>
<!DOCTYPE html>
<html class="no-js">
<head>
    <title><?php echo teampage_h($first_name); ?>'s Team Page</title>
    <link href="bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
    <link href="bootstrap/css/bootstrap-responsive.min.css" rel="stylesheet" media="screen">
    <link href="assets/styles.css" rel="stylesheet" media="screen">
    <style>
        body {
            background-color: #222222;
            padding-top: 60px;
        }
    </style>
</head>

<body>

<?php if ($showDbDebugBanner): ?>
    <?php echo teampage_render_db_debug_banner($dbNames); ?>
<?php endif; ?>

<div class="navbar navbar-fixed-top">
    <div class="navbar-inner">
        <div class="container-fluid">
            <a class="btn btn-navbar" data-toggle="collapse" data-target=".nav-collapse">
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
            </a>

            <ul class="nav pull-left">
                <li class="dropdown">
                    <a href="#" role="button" class="dropdown-toggle" data-toggle="dropdown">
                        <i class="icon-user"></i>
                        <?php echo teampage_h($first_name); ?> <i class="caret"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a tabindex="-1" href="<?php echo teampage_h((string)$mrl); ?>">MRL Home</a>
                            <a tabindex="-2" href="<?php echo teampage_h((string)$mrl); ?>profile.php">Profile Page</a>
                            <a tabindex="-3" href="<?php echo teampage_h((string)$mrl); ?>logout.php">Logout</a>
                        </li>
                    </ul>
                </li>
            </ul>

            <a class="brand">
                <ol align='center'><?php echo teampage_h((string)$sitename); ?> - My Team Page</ol>
            </a>

            <iframe src="https://freesecure.timeanddate.com/clock/i7eqrnfz/n777/fn16/fs18/bas/bat0/pd2/tt0/tw1/tm2" frameborder="1px" width="330" height="28"></iframe>
        </div>
    </div>
</div>

<div style="width:80%; margin:0 auto; text-align:left;">
    <div style="color:#dfcca8; font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif;">
        Hi <?php echo teampage_h($first_name); ?> ... <br>

        <?php if ($isAdmin): ?>
            <br>
            *********************** Admin Menu ****************************
            <br>
            *******************************************************************
            <br>
            <a href="/race_results/weekly_standings.php" target="_blank">- Weekly Standings / scoring - Beta</a>
            <br>
            <a href="/admin_setup.php" target="_blank">- Setup Year/Segment & Submission Date</a>
            <br>
            <a href="/Paid_Status_Year.php" target="_blank">- See Paid Status for selectable year</a>
            <br>
            <a href="/team_view_as.php" target="_blank">- View Team page as alternate user</a>
            <br>
            <a href="/email.php" target="_blank">- List all email addresses - active & inactive</a>
            <br>
            <a href="/change_user_auth.php" target="_blank">- Toggle user status to make late picks or change driver</a>
            <br>
            <a href="/addDrivers.php" target="_blank">- Add drivers for a new year.</a>
            <br>
            <a href="/current_segment_chart_by_entry_time.php" target="_blank">- Show current segment team chart sorted by Entry Time.</a>
            <br>
            *******************************************************************
            <br>
            <a href="<?php echo teampage_h($phpMyAdminUrl); ?>" target="_blank">- phpMyAdmin (Hostinger)</a>
            <br>
            <a href="<?php echo teampage_h($wpAdminUrl); ?>" target="_blank">- WP Admin (Hostinger)</a>
            <br>
            <a href="<?php echo teampage_h($hostingerBackupsUrl); ?>" target="_blank">- Backups (Hostinger)</a>
            <br>
            <a href="<?php echo teampage_h($hostingerPanelUrl); ?>" target="_blank">- hPanel (Hostinger)</a>
            <br>
            *******************************************************************
            <br>
        <?php endif; ?>

        <br>
        Welcome to your team page.<br>
        <br>
        <a style="color:red;">Update 2025-12-11 23:18:31 - See note below regarding previous years picks</a><br>
        <br>
        <br>
        <u style="color:red;">League Info as of 2026-02-03 11:09:24</u><br><br>
        2026 Fees & Payment info is <a href="/2026_Fees.php" target="_blank" rel="noopener noreferrer">here</a><br>
        2026 Rules are <a href="/2026_Rules.php" target="_blank" rel="noopener noreferrer">here</a><br>
        2026 Race Schedule - PDF (on MRL) is <a href="/wp-content/uploads/2026/01/2026_Schedule_MRL.pdf" target="_blank" rel="noopener noreferrer">here</a><br>
        2026 Race Schedule - Spreadsheet (on MRL) is <a href="/wp-content/uploads/2026/01/2026_Schedule_MRL.xlsx" target="_blank" rel="noopener noreferrer">here</a><br>
        2026 Race Schedule (on NASCAR) is <a href="https://www.nascar.com/nascar-cup-series/2026/schedule/" target="_blank" rel="noopener noreferrer">here</a><br>
        <br>

        ************************ Team Menu ******************************
        *******************************************************************
        <br>
        <a href="/showDrivers.php" target="_blank" rel="noopener noreferrer">- Driver Chart(s) - view, print for any year.</a><br>
        <a href="/team_chart.php" target="_blank" rel="noopener noreferrer">- Team Chart(s) - view, pdf, spreadsheet for any year/segment.</a><br>
        <a href="/submitted_teams.php" target="_blank" rel="noopener noreferrer">- Submitted Teams for Current Segment</a><br>
        <a href="/profile.php" target="_blank" rel="noopener noreferrer">- Your Profile page (change your email addresses, etc)</a> - Or use dropdown menu - upper left at your name.<br>
        <br>
        *******************************************************************
        <br>
    </div>
</div>

<a name="current_user_team_chart"></a>
<?php include 'current_user_team_chart.php'; ?>

<div style="width:80%; margin:0 auto; text-align:left;">
    <div style="color:#dfcca8; font-size:16.0pt; line-height:120%; font-family:'Century Gothic',sans-serif;">
        <br>
        <?php
        $end_ts = strtotime((string)$formLockDate);
        $user_ts = strtotime((string)$currentTimeIs);
        $normalPickWindowOpen = isset($pickWindowIsOpen)
            ? (bool)$pickWindowIsOpen
            : ($end_ts !== false && $end_ts > $user_ts);
        $pickWindowOpenTs = isset($pickWindowOpenAt) ? strtotime((string)$pickWindowOpenAt) : false;

        if ($formLocked === 'no') {
            if ($normalPickWindowOpen) {

                $teamName = '';

                if (isset($dbconnect)) {
                    $teamCheck = mysqli_query(
                        $dbconnect,
                        "SELECT teamName
                         FROM user_teams
                         WHERE userID = $uid
                           AND raceYear = $raceYear
                         LIMIT 1"
                    );
                    if ($teamCheck) {
                        $teamRow = mysqli_fetch_assoc($teamCheck);
                        $teamName = trim((string)($teamRow['teamName'] ?? ''));
                    }
                }

                if ($teamName === '') {

                    if (!isset($dbconnect)) {
                        echo "<div style='color:red; font-weight:bold; font-size:14pt; text-align:center;'>Database connection not available.</div>";
                    } else {
                        mrl_teamname_render_form($dbconnect, (string)$raceYear, $uid, (string)$teamNameMessage);
                    }

                } else {

                    include $currentForm;
                    include 'submitted_teams_count.php';

                }

            } else {

                // This only occurs before the first segment's automatic window opens,
                // or when an admin override deliberately schedules a future opening.
                // For an in-progress segment the deadline is already past, so LP/RD
                // routing below remains unchanged.
                if ($end_ts !== false && $user_ts < $end_ts && !$normalPickWindowOpen) {
                    $openText = isset($pickWindowOpenAt) && trim((string)$pickWindowOpenAt) !== ''
                        ? (string)$pickWindowOpenAt
                        : 'the scheduled opening time';
                    echo "Normal picks for " . teampage_h((string)$raceYear) . " " . teampage_h((string)$segmentName)
                        . " open on " . teampage_h($openText) . ".";
                } else {
                    if ($showRdWrapper) {
                        include 'team_replacement_driver.php';
                    } elseif ($teamFormMode === 'LP' || $teamFormMode === 'SPECIAL_AUTH') {
                        include 'team-late-pick.php';
                    } else {
                        echo teampage_h((string)$formLockedMessage) . " - past Lock date of " . teampage_h((string)$formLockDate);
                        echo "<br><br>";
                        include 'current_segment_chart.php';
                    }
                }

            }
        } else {
            echo teampage_h((string)$formLockedMessage);
        }
        ?>
        <br>
        <br>

        <p style='font-size:18.0pt;line-height:120%;font-family:"Century Gothic",sans-serif;color:#dfcca8'>
            <span style="font-size:20.0pt; text-decoration:underline; display:inline;">Previous Years Picks</span>
            <br><br>
            FYI: Great news — With the help of my friend Chad from ChatGPT, the user picks data has now been fully restored. As of 2025-12-11 23:18:31, all data is now being pulled from the final team picks table instead of the historical backup table. You should not see any gaps in your previous years picks. Please let us know if you see anything that doesn't look right to you. Thanks for your patience through all of this.<br>
        </p>
    </div>
</div>
<br>

<?php
$sqlYears = "SELECT * FROM years WHERE year < :raceYear AND year > 0 ORDER BY year DESC";
$stmtYears = $dbo->prepare($sqlYears);
$stmtYears->execute([':raceYear' => $raceYear]);

while ($yearRow = $stmtYears->fetch(PDO::FETCH_ASSOC)) {
    $prevRaceYear = $yearRow['year'];
    include 'prior_year_user_team_chart.php';
}
?>

<br>

<div style="width:80%; margin:0 auto; border:none; text-align:left;">
    <p style='font-size:12.0pt; line-height:120%; font-family:"Century Gothic",sans-serif; color:#dfcca8;'>
        Copyright &copy; 2017-<script>document.write(new Date().getFullYear())</script> Manlius Racing League
    </p>
</div>

<script src="bootstrap/js/jquery-1.9.1.min.js"></script>
<script src="bootstrap/js/bootstrap.min.js"></script>
<script src="assets/scripts.js"></script>
</body>
</html>
