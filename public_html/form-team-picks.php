<?php
declare(strict_types=1);

/**
 * form-team-picks.php
 *
 * VERSION: v004
 * LAST MODIFIED: 3/30/2026 12:39:46 pm
 *
 *
 * CHANGELOG:
 *
 * v004 (3/30/2026)
 * - FIX: Active raceYear, segment, and lock values are now read directly from admin_setup.
 * - FIX: Segment labels and header text now always reflect the active admin_setup segment.
 * - FIX: Current active-segment picks are loaded directly and injected as selected defaults.
 * - FIX: Driver dropdown order now follows the legacy form behavior (database/default order).
 * - FIX: Current selected driver stays available while editing, without duplicate display rows.
 * - CHANGE: Existing picks can be edited by changing only the desired driver(s); unchanged picks remain selected.
 *
 * v003 (3/30/2026)
 * - FIX: Form now resolves the working user ID from $DBuserID first, then falls back to $_SESSION['userSession'].
 * - FIX: Current active segment label is built directly from the active segment code (S1/S2/S3/S4).
 * - FIX: Existing active-segment picks are loaded using userID + raceYear + segment + pick_type='SEG'.
 * - FIX: Existing selections remain selected when editing picks.
 * - FIX: Driver option order matches the original form / driver chart order.
 *
 * v002 (3/30/2026)
 * - FIX: Segment row label now always uses the active segment from config_mrl.php.
 * - FIX: Existing picks for the active segment now load as selected defaults in the dropdowns.
 * - FIX: Current selected drivers are explicitly included in the option lists so they remain valid selections.
 * - FIX: Driver dropdown order now matches the original form / driver chart order instead of alphabetical order.
 * - CHANGE: New segment entries still load blank dropdowns until picks are made.
 *
 * v001 (3/30/2026)
 * - Initial baseline version of new universal team pick form.
 * - Uses active raceYear and segment from config_mrl.php.
 * - Preloads existing picks for the active segment if they already exist.
 * - Keeps the current 4-driver required rule for initial submissions.
 * - Uses root-relative submit path so the same file works on any site.
 * - Adds internal version tracking for troubleshooting and audit support.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_mrl.php';

$formID = 'form-team-picks.php';
$formVersion = 'v004';
$formTrace = $formID . ' ' . $formVersion;

if (!isset($dbconnect) || !($dbconnect instanceof mysqli)) {
    echo "<div style='color:red; font-weight:bold; text-align:center;'>Database connection is not available.</div>";
    return;
}

$workingUserId = 0;
if (isset($uid) && is_numeric($uid)) {
    $workingUserId = (int)$uid;
} elseif (isset($DBuserID) && is_numeric($DBuserID)) {
    $workingUserId = (int)$DBuserID;
} elseif (isset($_SESSION['userSession'])) {
    $workingUserId = (int)$_SESSION['userSession'];
}

if ($workingUserId <= 0) {
    echo "<div style='color:red; font-weight:bold; text-align:center;'>Unable to determine current user session.</div>";
    return;
}

function mrl_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function mrl_generate_submission_id(): string
{
    try {
        return 'sub_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    } catch (Exception $e) {
        return 'sub_' . date('Ymd_His') . '_' . mt_rand(100000, 999999);
    }
}

function mrl_get_active_admin_setup(mysqli $dbconnect): array
{
    $sql = "
        SELECT raceYear, segment, formLockDate, formLockTime
        FROM admin_setup
        LIMIT 1
    ";

    $result = mysqli_query($dbconnect, $sql);
    if (!$result) {
        return [];
    }

    $row = mysqli_fetch_assoc($result);
    return is_array($row) ? $row : [];
}

function mrl_fetch_current_segment_pick(mysqli $dbconnect, int $uid, string $raceYear, string $segment): array
{
    $sql = "
        SELECT pickID, driverA, driverB, driverC, driverD, entryDate
        FROM user_picks
        WHERE userID = ?
          AND raceYear = ?
          AND segment = ?
        ORDER BY pickID DESC
        LIMIT 1
    ";

    $stmt = mysqli_prepare($dbconnect, $sql);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "iss", $uid, $raceYear, $segment);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : [];

    mysqli_stmt_close($stmt);

    return is_array($row) ? $row : [];
}

function mrl_fetch_driver_options(mysqli $dbconnect, string $group, int $raceYear, int $uid, string $segment): array
{
    $tableMap = [
        'A' => ['table' => 'A Drivers', 'column' => 'driverA'],
        'B' => ['table' => 'B Drivers', 'column' => 'driverB'],
        'C' => ['table' => 'C Drivers', 'column' => 'driverC'],
        'D' => ['table' => 'D Drivers', 'column' => 'driverD'],
    ];

    if (!isset($tableMap[$group])) {
        return [];
    }

    $tableName = $tableMap[$group]['table'];
    $columnName = $tableMap[$group]['column'];
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

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = [
                'driverName' => (string)($row['driverName'] ?? ''),
                'tag' => (string)($row['Tag'] ?? ''),
            ];
        }
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

$adminSetup = mrl_get_active_admin_setup($dbconnect);

$activeRaceYear = isset($adminSetup['raceYear']) ? (string)$adminSetup['raceYear'] : (string)$raceYear;
$activeRaceYearInt = (int)$activeRaceYear;
$activeSegment = isset($adminSetup['segment']) ? trim((string)$adminSetup['segment']) : trim((string)$segment);
$activeLockDate = isset($adminSetup['formLockDate']) ? (string)$adminSetup['formLockDate'] : '';
$activeLockTime = isset($adminSetup['formLockTime']) ? (string)$adminSetup['formLockTime'] : '';

$segmentNameMap = [
    'S1' => 'Segment #1',
    'S2' => 'Segment #2',
    'S3' => 'Segment #3',
    'S4' => 'Segment #4',
];
$activeSegmentLabel = $segmentNameMap[$activeSegment] ?? $activeSegment;

$headerLine1 = "** Dropdown will only show drivers available to add to your team. **";
$headerLine2 = "Picks for " . $activeRaceYear . " " . $activeSegmentLabel . " due by " . $activeLockDate . " " . $activeLockTime . ". When you click 'Submit Picks', they will be entered into our database, and appear in chart above.";

$currentPick = mrl_fetch_current_segment_pick($dbconnect, $workingUserId, $activeRaceYear, $activeSegment);

$currentDrivers = [
    'A' => (string)($currentPick['driverA'] ?? ''),
    'B' => (string)($currentPick['driverB'] ?? ''),
    'C' => (string)($currentPick['driverC'] ?? ''),
    'D' => (string)($currentPick['driverD'] ?? ''),
];

$driverOptions = [
    'A' => mrl_fetch_driver_options($dbconnect, 'A', $activeRaceYearInt, $workingUserId, $activeSegment),
    'B' => mrl_fetch_driver_options($dbconnect, 'B', $activeRaceYearInt, $workingUserId, $activeSegment),
    'C' => mrl_fetch_driver_options($dbconnect, 'C', $activeRaceYearInt, $workingUserId, $activeSegment),
    'D' => mrl_fetch_driver_options($dbconnect, 'D', $activeRaceYearInt, $workingUserId, $activeSegment),
];

$submissionId = mrl_generate_submission_id();

$selectStyles = [
    'A' => '#d9d9d9',
    'B' => '#c4bd97',
    'C' => '#b8cce4',
    'D' => '#d8e4bc',
];
?>

<form action="/submit-team-picks.php" method="post">
    <input type="hidden" name="submission_id" value="<?php echo mrl_h($submissionId); ?>">
    <input type="hidden" name="form_id" value="<?php echo mrl_h($formID); ?>">
    <input type="hidden" name="form_version" value="<?php echo mrl_h($formVersion); ?>">

    <table align="center" style="width:100%;">
        <tr style="background-color:#fabf8f;">
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif;">
                <?php echo mrl_h($headerLine1); ?>
            </th>
        </tr>
        <tr style="background-color:#b7dee8;">
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif;">
                <?php echo mrl_h($headerLine2); ?>
            </th>
        </tr>
    </table>

    <table align="center" style="width:100%;">
        <tr style="background-color:#fabf8f;">
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; width:14%;">
                <?php echo mrl_h($activeRaceYear); ?>
            </th>
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; width:18%;">A Driver</th>
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; width:18%;">B Driver</th>
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; width:18%;">C Driver</th>
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; width:18%;">D Driver</th>
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif; width:14%;" id="clockCell"></th>
        </tr>

        <tr style="background-color:#b7dee8;">
            <th style="color:black; text-align:center; font-family:Century Gothic, sans-serif;">
                <?php echo mrl_h($activeSegmentLabel); ?>
            </th>

            <?php foreach (['A', 'B', 'C', 'D'] as $group): ?>
                <td style="background-color:<?php echo mrl_h($selectStyles[$group]); ?>; width:18%; padding:2px;">
                    <select
                        name="group-<?php echo strtolower($group); ?>-driver"
                        required
                        style="width:100%; height:auto; border:1px solid black; font-size:18px; color:black; background-color:<?php echo mrl_h($selectStyles[$group]); ?>; border-radius:4px;"
                    >
                        <option value=""></option>

                        <?php if ($currentDrivers[$group] !== ''): ?>
                            <option value="<?php echo mrl_h($currentDrivers[$group]); ?>" selected>
                                <?php echo mrl_h($currentDrivers[$group]); ?>
                            </option>
                        <?php endif; ?>

                        <?php foreach ($driverOptions[$group] as $driverRow): ?>
                            <?php
                            $driverName = (string)($driverRow['driverName'] ?? '');
                            $driverTag = trim((string)($driverRow['tag'] ?? ''));
                            $displayText = trim($driverName . ' ' . $driverTag);

                            if ($currentDrivers[$group] !== '' && trim($currentDrivers[$group]) === trim($driverName)) {
                                continue;
                            }
                            ?>
                            <option value="<?php echo mrl_h($driverName); ?>">
                                <?php echo mrl_h($displayText); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            <?php endforeach; ?>

            <td style="text-align:center; background-color:#b7dee8; width:14%;">
                <input type="reset" value="Reset">
                <input type="submit" value="Submit Picks">
            </td>
        </tr>
    </table>

    <div style="font-size:10px; color:#999; text-align:right; margin:0; padding:0;">
        <?php echo mrl_h($formTrace); ?>
    </div>
</form>

<script>
function updateClock() {
    var now = new Date();
    var year = now.getFullYear();
    var month = String(now.getMonth() + 1).padStart(2, '0');
    var day = String(now.getDate()).padStart(2, '0');
    var hours = String(now.getHours()).padStart(2, '0');
    var minutes = String(now.getMinutes()).padStart(2, '0');
    var seconds = String(now.getSeconds()).padStart(2, '0');

    document.getElementById('clockCell').innerText =
        year + '-' + month + '-' + day + ' ' + hours + ':' + minutes + ':' + seconds;
}

setInterval(updateClock, 1000);
updateClock();
</script>
