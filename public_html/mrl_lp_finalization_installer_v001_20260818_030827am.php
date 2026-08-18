<?php
declare(strict_types=1);

/**
 * mrl_lp_finalization_installer.php
 *
 * VERSION: v001
 * LAST MODIFIED: 8/18/2026 3:08:27 am
 *
 * DESCRIPTION:
 * TestPHP8-only installer that finalizes the Late Pick (LP) lifecycle.
 *
 * This installer:
 * - Decouples automatic LP eligibility from users.changeAuth.
 * - Preserves changeAuth as the existing SPECIAL_AUTH/admin override path.
 * - Allows automatic LP only when no SEG/ADJ pick exists for the active segment.
 * - Keeps an already-submitted LP editable only until its effective race starts.
 * - Lets a user who still has no pick roll naturally to the next future race in
 *   the same segment because race_schedule_helper.php supplies the next race.
 * - Keeps LP submissions marked pick_type=LP with the correct effective_race.
 * - Prevents an expired LP row from being edited after its effective-race start.
 * - Improves LP/special-auth banner and form deadline wording.
 * - Creates timestamped backups before replacing any file.
 * - Makes no database schema or data changes.
 *
 * TARGET FILE VERSIONS BEFORE INSTALL:
 * - team.php              v016
 * - submit-team-picks.php v004
 * - team-late-pick.php    v004
 * - form-team-picks.php   v004
 *
 * TARGET FILE VERSIONS AFTER INSTALL:
 * - team.php              v017
 * - submit-team-picks.php v005
 * - team-late-pick.php    v005
 * - form-team-picks.php   v005
 *
 * SAFETY:
 * - Refuses to install unless running on testphp8.manliusracingleague.com.
 * - Refuses to install if expected source markers are not found exactly once.
 * - Writes all four modified files to temporary files first.
 * - Backs up all four originals before replacing any target.
 * - Uses rename-based replacement where possible.
 *
 * CHANGELOG:
 * v001 (8/18/2026 3:08:27 am)
 * - Initial TestPHP8 LP finalization installer.
 */

date_default_timezone_set('America/New_York');

const INSTALLER_VERSION = 'v001';
const INSTALLER_TIMESTAMP = '20260818_030827am';
const REQUIRED_HOST = 'testphp8.manliusracingleague.com';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function replace_once(string $content, string $old, string $new, string $label): string
{
    $count = substr_count($content, $old);
    if ($count !== 1) {
        throw new RuntimeException($label . ': expected source block exactly once, found ' . $count . '.');
    }
    return str_replace($old, $new, $content);
}

function atomic_write(string $path, string $content): void
{
    $dir = dirname($path);
    $tmp = $dir . '/.' . basename($path) . '.lp_install_' . bin2hex(random_bytes(5)) . '.tmp';

    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary file: ' . $tmp);
    }

    @chmod($tmp, 0644);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace target file: ' . $path);
    }
}

function patch_team_php(string $src): string
{
    $src = replace_once(
        $src,
        " * VERSION: v016\n * LAST MODIFIED: 4/7/2026 7:54:20 am\n",
        " * VERSION: v017\n * LAST MODIFIED: 8/18/2026 3:08:27 am\n",
        'team.php header'
    );

    $src = replace_once(
        $src,
        " * CHANGELOG:\n *\n * v016 (4/7/2026)\n",
        " * CHANGELOG:\n *\n * v017 (8/18/2026 3:08:27 am)\n * - CHANGE: LP eligibility is now automatic and no longer requires changeAuth.\n * - CHANGE: changeAuth remains available only through the existing SPECIAL_AUTH/admin override path.\n * - FIX: Existing SEG/ADJ picks no longer become LP merely because changeAuth is enabled.\n * - NEW: Existing LP picks remain editable only until their stored effective race starts.\n * - NEW: Users with no segment pick automatically roll to the next future same-segment LP race until they submit or the segment ends.\n * - CHANGE: Preserved existing normal-pick and RD routing behavior.\n *\n * v016 (4/7/2026)\n",
        'team.php changelog'
    );

    $old = <<<'OLD'
function teampage_lp_effective_race_exists(string $raceYear, string $segment): bool
{
    try {
        $lpEffectiveRace = mrl_get_effective_race_for_lp((int)$raceYear, $segment);

        return is_array($lpEffectiveRace) && !empty($lpEffectiveRace);
    } catch (Throwable $e) {
        return false;
    }
}

function teampage_determine_form_mode(USER $user_home, PDO $dbo, int $uid, string $raceYear, string $segment): string
{
    $hasChangeAuth = teampage_user_has_change_auth($user_home, $uid);

    if ($hasChangeAuth) {
        $hasActiveSegmentPick = teampage_user_has_active_segment_pick($dbo, $uid, $raceYear, $segment);

        if (!$hasActiveSegmentPick) {
            $lpEffectiveRaceExists = teampage_lp_effective_race_exists($raceYear, $segment);

            if ($lpEffectiveRaceExists) {
                return 'LP';
            }

            return 'NORMAL';
        }

        return 'SPECIAL_AUTH';
    }

    return 'NORMAL';
}
OLD;

    $new = <<<'NEW'
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
NEW;

    $src = replace_once($src, $old, $new, 'team.php LP routing block');
    return $src;
}

function patch_submit_team_picks(string $src): string
{
    $src = replace_once(
        $src,
        " * VERSION: v004\n * LAST MODIFIED: 4/6/2026 10:26:26 pm\n",
        " * VERSION: v005\n * LAST MODIFIED: 8/18/2026 3:08:27 am\n",
        'submit-team-picks.php header'
    );

    $src = replace_once(
        $src,
        " * CHANGELOG:\n *\n * v004 (4/6/2026)\n",
        " * CHANGELOG:\n *\n * v005 (8/18/2026 3:08:27 am)\n * - CHANGE: LP submission type is now derived automatically from deadline + existing pick state + canonical schedule.\n * - CHANGE: LP no longer depends on users.changeAuth.\n * - FIX: Existing SEG/ADJ picks remain their existing base type during SPECIAL_AUTH edits instead of being converted to LP.\n * - FIX: An already-submitted LP cannot be edited after its stored effective race starts.\n * - CHANGE: An unsubmitted late-pick opportunity naturally advances to the next future race in the same segment.\n * - CHANGE: Preserved existing RD submission behavior.\n *\n * v004 (4/6/2026)\n",
        'submit-team-picks.php changelog'
    );

    $src = replace_once($src, "$scriptVersion = 'v004';", "$scriptVersion = 'v005';", 'submit-team-picks.php script version');

    $old = <<<'OLD'
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
OLD;

    $new = <<<'NEW'
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

function mrl_original_pick_deadline_passed(string $formLockDate, string $formLockTime): bool
{
    $raw = trim($formLockDate . ' ' . $formLockTime);
    if ($raw === '') {
        return false;
    }

    $deadlineTs = strtotime($raw);
    if ($deadlineTs === false) {
        return false;
    }

    return time() >= $deadlineTs;
}

function mrl_lp_effective_race_is_open(int $raceYear, int $effectiveRace): bool
{
    if ($effectiveRace <= 0) {
        return false;
    }

    try {
        $now = new DateTimeImmutable('now', mrl_schedule_helper_timezone());
        $races = mrl_schedule_helper_points_races($raceYear);

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

function mrl_determine_pick_type_and_effective_race(
    mysqli $dbconnect,
    int $uid,
    string $raceYearStr,
    int $raceYearInt,
    string $activeSegment,
    string $formLockDate,
    string $formLockTime
): array {
    $existing = mrl_get_existing_non_rd_pick_meta($dbconnect, $uid, $raceYearStr, $activeSegment);

    if (is_array($existing)) {
        $existingType = strtoupper(trim((string)($existing['pick_type'] ?? '')));
        $existingEffectiveRace = (int)($existing['effective_race'] ?? 0);

        if ($existingType === 'LP') {
            if (mrl_lp_effective_race_is_open($raceYearInt, $existingEffectiveRace)) {
                return [
                    'pick_type' => 'LP',
                    'effective_race' => $existingEffectiveRace,
                    'blocked' => false,
                ];
            }

            return [
                'pick_type' => 'LP',
                'effective_race' => $existingEffectiveRace,
                'blocked' => true,
            ];
        }

        if ($existingType === 'SEG' || $existingType === 'ADJ') {
            return [
                'pick_type' => $existingType,
                'effective_race' => $existingEffectiveRace > 0
                    ? $existingEffectiveRace
                    : mrl_get_segment_start_race($dbconnect, $raceYearInt, $activeSegment),
                'blocked' => false,
            ];
        }
    }

    if (mrl_original_pick_deadline_passed($formLockDate, $formLockTime)) {
        $lpEffectiveRace = mrl_get_effective_race_for_lp($raceYearInt, $activeSegment);

        if (is_array($lpEffectiveRace) && isset($lpEffectiveRace['race_number'])) {
            return [
                'pick_type' => 'LP',
                'effective_race' => (int)$lpEffectiveRace['race_number'],
                'blocked' => false,
            ];
        }

        return [
            'pick_type' => 'LP',
            'effective_race' => 0,
            'blocked' => true,
        ];
    }

    return [
        'pick_type' => 'SEG',
        'effective_race' => mrl_get_segment_start_race($dbconnect, $raceYearInt, $activeSegment),
        'blocked' => false,
    ];
}
NEW;

    $src = replace_once($src, $old, $new, 'submit-team-picks.php LP decision block');

    $oldCall = <<<'OLD'
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
OLD;

    $newCall = <<<'NEW'
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
NEW;

    $src = replace_once($src, $oldCall, $newCall, 'submit-team-picks.php decision call');
    return $src;
}

function patch_team_late_pick(string $src): string
{
    $src = replace_once(
        $src,
        " * VERSION: v004\n * LAST MODIFIED: 3/30/2026 7:21:19 pm\n",
        " * VERSION: v005\n * LAST MODIFIED: 8/18/2026 3:08:27 am\n",
        'team-late-pick.php header'
    );

    $src = replace_once(
        $src,
        " * CHANGELOG:\n *\n * v004 (3/30/2026)\n",
        " * CHANGELOG:\n *\n * v005 (8/18/2026 3:08:27 am)\n * - CHANGE: LP banner now shows the automatic effective race and race-start deadline.\n * - CHANGE: SPECIAL_AUTH is displayed as a separate manual admin override instead of LP.\n * - CHANGE: Preserved active-form include behavior and layout.\n *\n * v004 (3/30/2026)\n",
        'team-late-pick.php changelog'
    );

    $src = replace_once($src, "$specialWrapperVersion = 'v004';", "$specialWrapperVersion = 'v005';", 'team-late-pick.php version variable');

    $old = <<<'OLD'
echo "<br>";
echo "<div style='color: red; font-size: 20px; background-color: #fabf8f; text-align: center; font-weight: bold; padding: 8px 10px;'>"
   . "You are currently making picks past the original deadline of "
   . htmlspecialchars($specialLockDate, ENT_QUOTES, 'UTF-8')
   . " "
   . htmlspecialchars($specialLockTime, ENT_QUOTES, 'UTF-8')
   . " for "
   . htmlspecialchars($specialRaceYear, ENT_QUOTES, 'UTF-8')
   . " "
   . htmlspecialchars($specialSegmentLabel, ENT_QUOTES, 'UTF-8')
   . "</div>";

include $currentFormPath;
OLD;

    $new = <<<'NEW'
echo "<br>";

if (isset($teamFormMode) && $teamFormMode === 'LP') {
    $lpRaceCode = '';
    $lpDeadlineDisplay = '';

    try {
        $lpInfo = mrl_get_effective_race_for_lp((int)$specialRaceYear, (string)($segment ?? ''));
        if (is_array($lpInfo)) {
            $lpRaceNumber = (int)($lpInfo['race_number'] ?? 0);
            if ($lpRaceNumber > 0) {
                $lpRaceCode = 'R' . str_pad((string)$lpRaceNumber, 2, '0', STR_PAD_LEFT);
            }

            if (isset($lpInfo['race_start_dt']) && $lpInfo['race_start_dt'] instanceof DateTimeImmutable) {
                $lpDeadlineDisplay = $lpInfo['race_start_dt']->format('n/j/Y g:i a') . ' ET';
            }
        }
    } catch (Throwable $e) {
        $lpRaceCode = '';
        $lpDeadlineDisplay = '';
    }

    $message = "Late Pick window is open for "
        . htmlspecialchars($specialRaceYear, ENT_QUOTES, 'UTF-8')
        . " "
        . htmlspecialchars($specialSegmentLabel, ENT_QUOTES, 'UTF-8')
        . ". Original deadline: "
        . htmlspecialchars($specialLockDate, ENT_QUOTES, 'UTF-8')
        . " "
        . htmlspecialchars($specialLockTime, ENT_QUOTES, 'UTF-8')
        . ".";

    if ($lpRaceCode !== '' && $lpDeadlineDisplay !== '') {
        $message .= " Picks submitted now become effective with "
            . htmlspecialchars($lpRaceCode, ENT_QUOTES, 'UTF-8')
            . " and may be changed until "
            . htmlspecialchars($lpDeadlineDisplay, ENT_QUOTES, 'UTF-8')
            . ".";
    }

    echo "<div style='color:red; font-size:20px; background-color:#fabf8f; text-align:center; font-weight:bold; padding:8px 10px;'>"
       . $message
       . "</div>";
} else {
    echo "<div style='color:red; font-size:20px; background-color:#fabf8f; text-align:center; font-weight:bold; padding:8px 10px;'>"
       . "SPECIAL ADMIN AUTHORIZATION is active for "
       . htmlspecialchars($specialRaceYear, ENT_QUOTES, 'UTF-8')
       . " "
       . htmlspecialchars($specialSegmentLabel, ENT_QUOTES, 'UTF-8')
       . ". Original deadline: "
       . htmlspecialchars($specialLockDate, ENT_QUOTES, 'UTF-8')
       . " "
       . htmlspecialchars($specialLockTime, ENT_QUOTES, 'UTF-8')
       . "."
       . "</div>";
}

include $currentFormPath;
NEW;

    $src = replace_once($src, $old, $new, 'team-late-pick.php banner block');
    return $src;
}

function patch_form_team_picks(string $src): string
{
    $src = replace_once(
        $src,
        " * VERSION: v004\n * LAST MODIFIED: 3/30/2026 12:39:46 pm\n",
        " * VERSION: v005\n * LAST MODIFIED: 8/18/2026 3:08:27 am\n",
        'form-team-picks.php header'
    );

    $src = replace_once(
        $src,
        " * CHANGELOG:\n *\n * v004 (3/30/2026)\n",
        " * CHANGELOG:\n *\n * v005 (8/18/2026 3:08:27 am)\n * - CHANGE: LP mode now displays the effective race and race-start deadline instead of repeating the original segment deadline as the active due date.\n * - CHANGE: Normal and SPECIAL_AUTH form behavior remains otherwise unchanged.\n *\n * v004 (3/30/2026)\n",
        'form-team-picks.php changelog'
    );

    $src = replace_once($src, "$formVersion = 'v004';", "$formVersion = 'v005';", 'form-team-picks.php version variable');

    $old = <<<'OLD'
$headerLine1 = "** Dropdown will only show drivers available to add to your team. **";
$headerLine2 = "Picks for " . $activeRaceYear . " " . $activeSegmentLabel . " due by " . $activeLockDate . " " . $activeLockTime . ". When you click 'Submit Picks', they will be entered into our database, and appear in chart above.";
OLD;

    $new = <<<'NEW'
$headerLine1 = "** Dropdown will only show drivers available to add to your team. **";
$headerLine2 = "Picks for " . $activeRaceYear . " " . $activeSegmentLabel . " due by " . $activeLockDate . " " . $activeLockTime . ". When you click 'Submit Picks', they will be entered into our database, and appear in chart above.";

if (isset($teamFormMode) && $teamFormMode === 'LP' && function_exists('mrl_get_effective_race_for_lp')) {
    try {
        $lpInfo = mrl_get_effective_race_for_lp($activeRaceYearInt, $activeSegment);
        if (is_array($lpInfo) && isset($lpInfo['race_number'])) {
            $lpRaceNumber = (int)$lpInfo['race_number'];
            $lpRaceCode = 'R' . str_pad((string)$lpRaceNumber, 2, '0', STR_PAD_LEFT);
            $lpDeadline = '';

            if (isset($lpInfo['race_start_dt']) && $lpInfo['race_start_dt'] instanceof DateTimeImmutable) {
                $lpDeadline = $lpInfo['race_start_dt']->format('n/j/Y g:i a') . ' ET';
            }

            $headerLine2 = "Late picks for " . $activeRaceYear . " " . $activeSegmentLabel
                . " become effective with " . $lpRaceCode
                . ($lpDeadline !== '' ? ". New deadline: " . $lpDeadline : '')
                . ". You may change these picks until that race starts.";
        }
    } catch (Throwable $e) {
        // Keep the normal header text if schedule data cannot be read.
    }
}
NEW;

    $src = replace_once($src, $old, $new, 'form-team-picks.php header text block');
    return $src;
}

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\d+$/', '', $host);
$hostOk = ($host === REQUIRED_HOST);

$baseDir = __DIR__;
$targets = [
    'team.php' => [
        'path' => $baseDir . '/team.php',
        'expected' => 'VERSION: v016',
        'new' => 'VERSION: v017',
        'patcher' => 'patch_team_php',
    ],
    'submit-team-picks.php' => [
        'path' => $baseDir . '/submit-team-picks.php',
        'expected' => 'VERSION: v004',
        'new' => 'VERSION: v005',
        'patcher' => 'patch_submit_team_picks',
    ],
    'team-late-pick.php' => [
        'path' => $baseDir . '/team-late-pick.php',
        'expected' => 'VERSION: v004',
        'new' => 'VERSION: v005',
        'patcher' => 'patch_team_late_pick',
    ],
    'form-team-picks.php' => [
        'path' => $baseDir . '/form-team-picks.php',
        'expected' => 'VERSION: v004',
        'new' => 'VERSION: v005',
        'patcher' => 'patch_form_team_picks',
    ],
];

$checks = [];
$allReady = $hostOk;

foreach ($targets as $name => $meta) {
    $exists = is_file($meta['path']);
    $readable = $exists && is_readable($meta['path']);
    $writable = $exists && is_writable($meta['path']);
    $content = $readable ? (string)file_get_contents($meta['path']) : '';
    $versionOk = $readable && strpos($content, $meta['expected']) !== false;
    $alreadyInstalled = $readable && strpos($content, $meta['new']) !== false;

    $checks[$name] = [
        'exists' => $exists,
        'readable' => $readable,
        'writable' => $writable,
        'versionOk' => $versionOk,
        'alreadyInstalled' => $alreadyInstalled,
    ];

    if (!$exists || !$readable || !$writable || (!$versionOk && !$alreadyInstalled)) {
        $allReady = false;
    }
}

$statusMessage = '';
$statusType = '';
$backupDirDisplay = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install_lp_finalization'])) {
    if (!$hostOk) {
        $statusType = 'error';
        $statusMessage = 'INSTALL ABORTED: This installer may only run on ' . REQUIRED_HOST . '.';
    } else {
        $alreadyAll = true;
        foreach ($checks as $row) {
            if (!$row['alreadyInstalled']) {
                $alreadyAll = false;
                break;
            }
        }

        if ($alreadyAll) {
            $statusType = 'ok';
            $statusMessage = 'No changes needed: all four LP finalization target versions are already installed.';
        } elseif (!$allReady) {
            $statusType = 'error';
            $statusMessage = 'INSTALL ABORTED: One or more preflight checks failed. No files were changed.';
        } else {
            try {
                $newContents = [];

                // Build and fully validate every new file BEFORE any target is touched.
                foreach ($targets as $name => $meta) {
                    $src = (string)file_get_contents($meta['path']);
                    $patcher = $meta['patcher'];
                    $patched = $patcher($src);

                    if (strpos($patched, $meta['new']) === false) {
                        throw new RuntimeException($name . ': new version marker was not produced.');
                    }

                    $newContents[$name] = $patched;
                }

                $backupDir = $baseDir . '/mrl_lp_finalization_backup_' . INSTALLER_TIMESTAMP;
                if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
                    throw new RuntimeException('Unable to create backup directory: ' . $backupDir);
                }

                foreach ($targets as $name => $meta) {
                    $backupPath = $backupDir . '/' . $name;
                    if (!copy($meta['path'], $backupPath)) {
                        throw new RuntimeException('Unable to back up ' . $name . '.');
                    }
                }

                // All backups now exist. Replace targets.
                foreach ($targets as $name => $meta) {
                    atomic_write($meta['path'], $newContents[$name]);
                }

                $backupDirDisplay = basename($backupDir);
                $statusType = 'ok';
                $statusMessage = 'INSTALL COMPLETE: LP finalization files installed successfully. Backups saved in ' . $backupDirDisplay . '.';

                // Refresh checks for display.
                foreach ($targets as $name => $meta) {
                    $content = is_file($meta['path']) ? (string)file_get_contents($meta['path']) : '';
                    $checks[$name]['alreadyInstalled'] = (strpos($content, $meta['new']) !== false);
                    $checks[$name]['versionOk'] = (strpos($content, $meta['expected']) !== false);
                }
            } catch (Throwable $e) {
                $statusType = 'error';
                $statusMessage = 'INSTALL ABORTED/FAILED: ' . $e->getMessage() . ' Check the backup directory before making further changes.';
            }
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MRL LP Finalization Installer</title>
<style>
body{margin:0;background:#111820;color:#e8eef5;font-family:Arial,Helvetica,sans-serif;font-size:18px}
.wrap{max-width:1180px;margin:30px auto;padding:0 20px}
.card{background:#1b2632;border:1px solid #415165;border-radius:12px;padding:20px;margin:18px 0}
h1{font-size:34px;margin:0 0 8px}.sub{color:#b8c7d9}.ok{color:#58df75;font-weight:bold}.bad{color:#ff6b6b;font-weight:bold}.warn{color:#ffd166;font-weight:bold}
table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:10px;border-bottom:1px solid #3b4a5d;text-align:left}th{color:#b9d5ff}
button{font-size:20px;padding:12px 22px;border:0;border-radius:8px;background:#2775d8;color:white;cursor:pointer}button:disabled{background:#555;cursor:not-allowed}
code{background:#0e141b;padding:2px 6px;border-radius:4px}.msg{font-size:21px;padding:14px;border-radius:8px;margin:16px 0}.msg.okbox{background:#14371f;border:1px solid #2f8b4a}.msg.errbox{background:#45191d;border:1px solid #b6414b}
.small{font-size:14px;color:#b8c7d9}.list li{margin:8px 0}
</style>
</head>
<body><div class="wrap">
<h1>MRL LP Finalization Installer</h1>
<div class="sub">v001 · TESTPHP8 ONLY · READS/WRITES PHP FILES ONLY · NO DATABASE CHANGES</div>

<?php if ($statusMessage !== ''): ?>
<div class="msg <?php echo $statusType === 'ok' ? 'okbox' : 'errbox'; ?>"><?php echo h($statusMessage); ?></div>
<?php endif; ?>

<div class="card">
<h2>Purpose</h2>
<ul class="list">
<li>Automatic LP no longer requires <code>changeAuth</code>.</li>
<li><code>changeAuth</code> remains available as the existing manual SPECIAL_AUTH/admin override.</li>
<li>A normal SEG/ADJ pick blocks automatic LP.</li>
<li>An LP may be edited until its effective race starts, then it locks.</li>
<li>If no LP was submitted, the next future same-segment race becomes the next LP opportunity automatically.</li>
<li>LP ends when there is no future race left in the segment.</li>
</ul>
</div>

<div class="card">
<h2>Preflight</h2>
<p>Host: <strong><?php echo h($host !== '' ? $host : '(unknown)'); ?></strong> — <span class="<?php echo $hostOk ? 'ok' : 'bad'; ?>"><?php echo $hostOk ? 'PASS' : 'FAIL'; ?></span></p>
<table>
<tr><th>File</th><th>Expected</th><th>After</th><th>Exists</th><th>Writable</th><th>Status</th></tr>
<?php foreach ($targets as $name => $meta): $row = $checks[$name]; ?>
<tr>
<td><?php echo h($name); ?></td>
<td><?php echo h($meta['expected']); ?></td>
<td><?php echo h($meta['new']); ?></td>
<td><?php echo $row['exists'] ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'; ?></td>
<td><?php echo $row['writable'] ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'; ?></td>
<td>
<?php
if ($row['alreadyInstalled']) echo '<span class="ok">ALREADY INSTALLED</span>';
elseif ($row['versionOk']) echo '<span class="ok">READY</span>';
else echo '<span class="bad">VERSION/CHECK FAILED</span>';
?>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h2>Install</h2>
<p>The installer validates all four transformations first, then backs up all four originals, then replaces the files.</p>
<p class="small">Backup folder: <code>mrl_lp_finalization_backup_<?php echo h(INSTALLER_TIMESTAMP); ?></code></p>
<form method="post">
<button type="submit" name="install_lp_finalization" value="1" <?php echo !$hostOk ? 'disabled' : ''; ?>>Install LP Finalization</button>
</form>
</div>

<div class="card small">
Installer generated 8/18/2026 3:08:27 am America/New_York. Delete this installer from the server after testing is complete.
</div>
</div></body></html>
