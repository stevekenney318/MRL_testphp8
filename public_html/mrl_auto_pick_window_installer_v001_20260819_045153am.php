<?php
declare(strict_types=1);

/**
 * mrl_auto_pick_window_installer.php
 *
 * VERSION: v001
 * LAST MODIFIED: 8/19/2026 4:51:53 am
 *
 * DESCRIPTION:
 * TestPHP8-only installer for the first-pass automatic normal-pick window.
 *
 * Core behavior after install:
 * - Normal segment picks open automatically 15 days before the first MRL points
 *   race in that segment.
 * - Normal segment picks close at the scheduled start of that segment's first race.
 * - The canonical /race_results/_race_results_schedule.json supplies race times.
 * - segment_race_ranges supplies segment boundaries.
 * - Legacy $segment remains backward-compatible by representing the active PICK
 *   segment, just as the old manually-maintained admin_setup.segment effectively did.
 * - New explicit $scoringSegment and $pickSegment variables remove the ambiguity
 *   for new/future code without requiring a remembered migration later.
 * - admin_setup.php becomes an automatic-status dashboard with a temporary pick
 *   window override. formLocked remains the emergency/master lock.
 * - Existing legacy admin_setup segment/lock values are retained as fallbacks and
 *   are not deleted.
 *
 * TARGET FILE VERSIONS BEFORE INSTALL:
 * - team.php                         v018
 * - form-team-picks.php              v005
 * - submit-team-picks.php            v005
 * - current_user_team_chart.php       v002
 * - race_results/race_schedule_helper.php v003 (dependency only; unchanged)
 * - config_mrl.php                    legacy DB-driven file supplied by Steve
 * - admin_setup.php                   current legacy admin setup page
 *
 * TARGET FILE VERSIONS AFTER INSTALL:
 * - team.php                         v019
 * - form-team-picks.php              v006
 * - submit-team-picks.php            v006
 * - current_user_team_chart.php       v003
 * - pick_window_helper.php           v001 (new)
 * - config_mrl.php                   v001
 * - admin_setup.php                  v001
 *
 * DATABASE CHANGE:
 * Adds four nullable/override columns to admin_setup:
 * - pickOverrideEnabled
 * - pickOverrideSegment
 * - pickOverrideOpenAt
 * - pickOverrideDeadlineAt
 *
 * SAFETY:
 * - Refuses to run anywhere except testphp8.manliusracingleague.com.
 * - Preflights file versions/anchors, DB structure, segment ranges, and canonical
 *   schedule before offering INSTALL.
 * - Creates timestamped file + admin_setup schema/data backups before changes.
 * - Precomputes every file transformation before DB/file writes begin.
 * - Attempts rollback of both files and newly-added DB columns on install failure.
 * - Makes no changes to Live MRL.
 *
 * CHANGELOG:
 * v001 (8/19/2026 4:51:53 am)
 * - Initial TestPHP8 automatic normal-pick window installer.
 */

date_default_timezone_set('America/New_York');

const INSTALLER_VERSION = 'v001';
const INSTALLER_TIMESTAMP = '20260819_045153am';
const REQUIRED_HOST = 'testphp8.manliusracingleague.com';
const PICK_WINDOW_DAYS = 15;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function ih(string $value): string
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
    $tmp = $dir . '/.' . basename($path) . '.auto_pick_' . bin2hex(random_bytes(5)) . '.tmp';

    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary file: ' . $tmp);
    }
    @chmod($tmp, 0644);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace target file: ' . $path);
    }
}

function query_one(mysqli $db, string $sql): ?array
{
    $res = mysqli_query($db, $sql);
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return is_array($row) ? $row : null;
}

function table_columns(mysqli $db, string $table): array
{
    $safe = str_replace('`', '``', $table);
    $res = mysqli_query($db, "SHOW COLUMNS FROM `{$safe}`");
    if (!$res) {
        return [];
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $out[(string)$row['Field']] = $row;
    }
    return $out;
}

function file_has(string $path, string $needle): bool
{
    if (!is_file($path)) return false;
    $src = file_get_contents($path);
    return is_string($src) && strpos($src, $needle) !== false;
}

function patch_team_php(string $src): string
{
    $src = replace_once(
        $src,
        " * VERSION: v018\n * LAST MODIFIED: 8/18/2026 4:13:00 am\n",
        " * VERSION: v019\n * LAST MODIFIED: 8/19/2026 4:51:53 am\n",
        'team.php header'
    );

    $src = replace_once(
        $src,
        " * CHANGELOG:\n *\n * v018 (8/18/2026 4:13:00 am)\n",
        " * CHANGELOG:\n *\n * v019 (8/19/2026 4:51:53 am)\n * - NEW: Normal pick-window availability now follows the shared automatic pick-window state.\n * - NEW: Normal picks open 15 days before the first race in the pick segment and close at that race start.\n * - CHANGE: Uses config_mrl.php's backward-compatible pick-segment mapping instead of requiring manual admin segment/deadline changes.\n * - SAFETY: Before a future segment's normal window opens, direct normal-form display remains blocked.\n * - CHANGE: Preserved LP, SPECIAL_AUTH and RD routing after the active pick-segment deadline.\n *\n * v018 (8/18/2026 4:13:00 am)\n",
        'team.php changelog'
    );

    $old = <<<'OLD'
        $end_ts = strtotime((string)$formLockDate);
        $user_ts = strtotime((string)$currentTimeIs);

        if ($formLocked === 'no') {
            if ($end_ts > $user_ts) {
OLD;

    $new = <<<'NEW'
        $end_ts = strtotime((string)$formLockDate);
        $user_ts = strtotime((string)$currentTimeIs);
        $normalPickWindowOpen = isset($pickWindowIsOpen)
            ? (bool)$pickWindowIsOpen
            : ($end_ts !== false && $end_ts > $user_ts);
        $pickWindowOpenTs = isset($pickWindowOpenAt) ? strtotime((string)$pickWindowOpenAt) : false;

        if ($formLocked === 'no') {
            if ($normalPickWindowOpen) {
NEW;
    $src = replace_once($src, $old, $new, 'team.php normal-window branch');

    $old = <<<'OLD'
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
OLD;

    $new = <<<'NEW'
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
NEW;
    $src = replace_once($src, $old, $new, 'team.php closed-window routing');

    return $src;
}

function patch_form_team_picks(string $src): string
{
    $src = replace_once(
        $src,
        " * VERSION: v005\n * LAST MODIFIED: 8/18/2026 3:08:27 am\n",
        " * VERSION: v006\n * LAST MODIFIED: 8/19/2026 4:51:53 am\n",
        'form-team-picks.php header'
    );

    $src = replace_once(
        $src,
        " * CHANGELOG:\n *\n * v005 (8/18/2026 3:08:27 am)\n",
        " * CHANGELOG:\n *\n * v006 (8/19/2026 4:51:53 am)\n * - CHANGE: Active year/segment/deadline now come from config_mrl.php's automatic pick-window state.\n * - CHANGE: Removes the form's direct dependency on the legacy admin_setup segment/lock fields for normal operation.\n * - CHANGE: Preserved LP effective-race header behavior and existing dropdown/edit behavior.\n *\n * v005 (8/18/2026 3:08:27 am)\n",
        'form-team-picks.php changelog'
    );

    $src = replace_once($src, "$formVersion = 'v005';", "$formVersion = 'v006';", 'form-team-picks.php version variable');

    $old = <<<'OLD'
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
OLD;

    $new = <<<'NEW'
// config_mrl.php is now the single compatibility layer for active PICK state.
// Legacy pages still receive $segment/$formLockDate, but those values are
// automatically derived from the canonical schedule + segment_race_ranges.
$activeRaceYear = (string)$raceYear;
$activeRaceYearInt = (int)$activeRaceYear;
$activeSegment = trim((string)$segment);
$activeLockDate = trim((string)$formLockDate);
$activeLockTime = '';
$activeSegmentLabel = isset($segmentName) && trim((string)$segmentName) !== ''
    ? (string)$segmentName
    : $activeSegment;

$headerLine1 = "** Dropdown will only show drivers available to add to your team. **";
$headerLine2 = "Picks for " . $activeRaceYear . " " . $activeSegmentLabel . " due by " . $activeLockDate . ". When you click 'Submit Picks', they will be entered into our database, and appear in chart above.";
NEW;

    $src = replace_once($src, $old, $new, 'form-team-picks.php active setup block');
    return $src;
}

function patch_submit_team_picks(string $src): string
{
    $src = replace_once(
        $src,
        " * VERSION: v005\n * LAST MODIFIED: 8/18/2026 3:08:27 am\n",
        " * VERSION: v006\n * LAST MODIFIED: 8/19/2026 4:51:53 am\n",
        'submit-team-picks.php header'
    );

    $src = replace_once(
        $src,
        " * CHANGELOG:\n *\n * v005 (8/18/2026 3:08:27 am)\n",
        " * CHANGELOG:\n *\n * v006 (8/19/2026 4:51:53 am)\n * - NEW: Blocks a brand-new normal SEG submission before the automatic pick window opens.\n * - CHANGE: Normal/LP deadline decisions now use config_mrl.php's schedule-derived pick segment and deadline.\n * - CHANGE: Preserved existing SEG/ADJ edits, LP lifecycle and RD submission behavior.\n *\n * v005 (8/18/2026 3:08:27 am)\n",
        'submit-team-picks.php changelog'
    );

    $src = replace_once($src, "$scriptVersion = 'v005';", "$scriptVersion = 'v006';", 'submit-team-picks.php script version');

    $old = <<<'OLD'
    return [
        'pick_type' => 'SEG',
        'effective_race' => mrl_get_segment_start_race($dbconnect, $raceYearInt, $activeSegment),
        'blocked' => false,
    ];
}
OLD;

    $new = <<<'NEW'
    // A brand-new normal segment pick may only be created while the automatic
    // normal-pick window is actually open. Existing SEG/ADJ edits were handled
    // above and retain their established behavior.
    if (isset($GLOBALS['pickWindowIsOpen']) && !$GLOBALS['pickWindowIsOpen']) {
        return [
            'pick_type' => 'SEG',
            'effective_race' => mrl_get_segment_start_race($dbconnect, $raceYearInt, $activeSegment),
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

    $src = replace_once($src, $old, $new, 'submit-team-picks.php normal-open enforcement');
    return $src;
}

function patch_current_user_team_chart(string $src): string
{
    $src = replace_once(
        $src,
        " * VERSION: v002\n * LAST MODIFIED: 4/13/2026 9:08:59 pm\n",
        " * VERSION: v003\n * LAST MODIFIED: 8/19/2026 4:51:53 am\n",
        'current_user_team_chart.php header'
    );

    $src = replace_once(
        $src,
        " * CHANGELOG:\n *\n * v002 (4/13/2026)\n",
        " * CHANGELOG:\n *\n * v003 (8/19/2026 4:51:53 am)\n * - FIX: Segment-history rendering no longer overwrites the shared legacy $segment variable while included inside team.php.\n * - CHANGE: Preserved all current-user chart, LP and RD display behavior.\n *\n * v002 (4/13/2026)\n",
        'current_user_team_chart.php changelog'
    );

    $old = <<<'OLD'
$segments = ['S1', 'S2', 'S3', 'S4'];
$allRows = [];

foreach ($segments as $segment) {
    $sql = "SELECT `pickID`, `pick_type`, `supersedes_pickID`, `effective_race`, `segment`, `driverA`, `driverB`, `driverC`, `driverD`, `entryDate`
            FROM `user_picks`
            WHERE `userID` = $uid
              AND `raceYear` = $raceYear
              AND `segment` = '$segment'
            ORDER BY `entryDate` ASC, `pickID` ASC";
OLD;

    $new = <<<'NEW'
$chartSegments = ['S1', 'S2', 'S3', 'S4'];
$allRows = [];

foreach ($chartSegments as $chartSegment) {
    $sql = "SELECT `pickID`, `pick_type`, `supersedes_pickID`, `effective_race`, `segment`, `driverA`, `driverB`, `driverC`, `driverD`, `entryDate`
            FROM `user_picks`
            WHERE `userID` = $uid
              AND `raceYear` = $raceYear
              AND `segment` = '$chartSegment'
            ORDER BY `entryDate` ASC, `pickID` ASC";
NEW;

    $src = replace_once($src, $old, $new, 'current_user_team_chart.php segment loop');
    return $src;
}

function new_pick_window_helper(): string
{
    return <<<'PHPFILE'
<?php
declare(strict_types=1);

/**
 * pick_window_helper.php
 *
 * VERSION: v001
 * LAST MODIFIED: 8/19/2026 4:51:53 am
 *
 * DESCRIPTION:
 * Shared automatic normal-pick window resolver.
 *
 * Sources:
 * - /race_results/_race_results_schedule.json for canonical race start times.
 * - segment_race_ranges for segment start/end race numbers.
 *
 * Rules:
 * - Normal picks open 15 days before the first points race in a segment.
 * - Normal picks close at the scheduled start of that first segment race.
 * - scoringSegment is the latest segment whose first race has started.
 * - pickSegment becomes the next segment during its 15-day normal-pick window;
 *   otherwise it remains the scoring segment so LP/current-segment behavior works.
 * - Optional admin override changes PICK state only; scoringSegment remains automatic.
 *
 * CHANGELOG:
 * v001 (8/19/2026 4:51:53 am)
 * - Initial automatic pick-window helper.
 */

const MRL_PICK_WINDOW_DAYS = 15;
const MRL_PICK_WINDOW_TIMEZONE = 'America/New_York';

require_once __DIR__ . '/race_results/race_schedule_helper.php';

if (!function_exists('mrl_pick_window_timezone')) {
    function mrl_pick_window_timezone(): DateTimeZone
    {
        return new DateTimeZone(MRL_PICK_WINDOW_TIMEZONE);
    }
}

if (!function_exists('mrl_pick_window_segment_rows')) {
    function mrl_pick_window_segment_rows(int $year): array
    {
        global $dbo, $dbconnect;
        $rows = [];

        if (isset($dbo) && $dbo instanceof PDO) {
            $stmt = $dbo->prepare(
                "SELECT segment, startRace, endRace
                 FROM segment_race_ranges
                 WHERE raceYear = :raceYear
                 ORDER BY startRace ASC"
            );
            $stmt->execute([':raceYear' => $year]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (isset($dbconnect) && $dbconnect instanceof mysqli) {
            $stmt = mysqli_prepare(
                $dbconnect,
                "SELECT segment, startRace, endRace
                 FROM segment_race_ranges
                 WHERE raceYear = ?
                 ORDER BY startRace ASC"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $year);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($row = mysqli_fetch_assoc($res))) {
                    $rows[] = $row;
                }
                mysqli_stmt_close($stmt);
            }
        }

        if (empty($rows)) {
            throw new RuntimeException('No segment_race_ranges rows found for ' . $year . '.');
        }

        $out = [];
        foreach ($rows as $row) {
            $segment = strtoupper(trim((string)($row['segment'] ?? '')));
            $startRace = (int)($row['startRace'] ?? 0);
            $endRace = (int)($row['endRace'] ?? 0);
            if ($segment === '' || $startRace <= 0 || $endRace < $startRace) {
                continue;
            }

            $race = mrl_schedule_helper_race_by_number($year, $startRace);
            if (!is_array($race)) {
                throw new RuntimeException('Segment ' . $segment . ' start race R' . $startRace . ' is missing from canonical schedule.');
            }

            $startDt = mrl_schedule_helper_race_datetime($race);
            $openDt = $startDt->sub(new DateInterval('P' . MRL_PICK_WINDOW_DAYS . 'D'));

            $out[] = [
                'segment' => $segment,
                'start_race' => $startRace,
                'end_race' => $endRace,
                'start_dt' => $startDt,
                'open_dt' => $openDt,
                'deadline_dt' => $startDt,
                'race' => $race,
            ];
        }

        if (empty($out)) {
            throw new RuntimeException('No usable segment window rows found for ' . $year . '.');
        }

        return $out;
    }
}

if (!function_exists('mrl_pick_window_segment_label')) {
    function mrl_pick_window_segment_label(int $year, string $segment): string
    {
        $segment = strtoupper(trim($segment));
        if ($segment === 'S1') return 'Segment #1';
        if ($segment === 'S2') return 'Segment #2';
        if ($segment === 'S3') return 'Segment #3';
        if ($segment === 'S4') return $year >= 2026 ? 'The Chase' : 'Playoffs';
        return $segment;
    }
}

if (!function_exists('mrl_pick_window_parse_db_datetime')) {
    function mrl_pick_window_parse_db_datetime(?string $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') return null;

        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, mrl_pick_window_timezone());
        return $dt instanceof DateTimeImmutable ? $dt : null;
    }
}

if (!function_exists('mrl_pick_window_format_display')) {
    function mrl_pick_window_format_display(DateTimeImmutable $dt): string
    {
        return $dt->format('n/j/Y g:i a') . ' ET';
    }
}

if (!function_exists('mrl_pick_window_state')) {
    function mrl_pick_window_state(
        int $year,
        array $override = [],
        ?DateTimeImmutable $now = null
    ): array {
        $now = $now ?? new DateTimeImmutable('now', mrl_pick_window_timezone());
        $segments = mrl_pick_window_segment_rows($year);

        // Before the first race, S1 is the scoring-context fallback. Thereafter,
        // scoringSegment advances only when a segment's first scheduled race starts.
        $scoringRow = $segments[0];
        foreach ($segments as $row) {
            if ($row['start_dt'] <= $now) {
                $scoringRow = $row;
            } else {
                break;
            }
        }

        // Outside an overlap window, pickSegment remains scoringSegment so LP and
        // legacy current-segment pages keep operating on the segment being raced.
        $pickRow = $scoringRow;
        foreach ($segments as $row) {
            if ($now >= $row['open_dt'] && $now < $row['deadline_dt']) {
                $pickRow = $row;
                break;
            }
        }

        $source = 'AUTO';
        $overrideError = '';
        $openDt = $pickRow['open_dt'];
        $deadlineDt = $pickRow['deadline_dt'];

        $overrideEnabled = strtolower(trim((string)($override['enabled'] ?? 'no'))) === 'yes';
        if ($overrideEnabled) {
            $requestedSegment = strtoupper(trim((string)($override['segment'] ?? '')));
            $requestedOpen = mrl_pick_window_parse_db_datetime($override['open_at'] ?? null);
            $requestedDeadline = mrl_pick_window_parse_db_datetime($override['deadline_at'] ?? null);
            $requestedRow = null;

            foreach ($segments as $row) {
                if ($row['segment'] === $requestedSegment) {
                    $requestedRow = $row;
                    break;
                }
            }

            if ($requestedRow === null) {
                $overrideError = 'Override segment is not valid for ' . $year . '.';
            } elseif (!$requestedOpen || !$requestedDeadline) {
                $overrideError = 'Override requires both opening and deadline date/time.';
            } elseif ($requestedOpen >= $requestedDeadline) {
                $overrideError = 'Override opening must be earlier than override deadline.';
            } else {
                $pickRow = $requestedRow;
                $openDt = $requestedOpen;
                $deadlineDt = $requestedDeadline;
                $source = 'OVERRIDE';
            }
        }

        $isOpen = ($now >= $openDt && $now < $deadlineDt);
        $status = $isOpen ? 'OPEN' : ($now < $openDt ? 'CLOSED_BEFORE_OPEN' : 'CLOSED_AFTER_DEADLINE');

        return [
            'year' => $year,
            'now' => $now,
            'source' => $source,
            'override_error' => $overrideError,
            'scoring_segment' => (string)$scoringRow['segment'],
            'scoring_segment_label' => mrl_pick_window_segment_label($year, (string)$scoringRow['segment']),
            'pick_segment' => (string)$pickRow['segment'],
            'pick_segment_label' => mrl_pick_window_segment_label($year, (string)$pickRow['segment']),
            'pick_start_race' => (int)$pickRow['start_race'],
            'pick_end_race' => (int)$pickRow['end_race'],
            'window_open_dt' => $openDt,
            'deadline_dt' => $deadlineDt,
            'window_open_display' => mrl_pick_window_format_display($openDt),
            'deadline_display' => mrl_pick_window_format_display($deadlineDt),
            'window_is_open' => $isOpen,
            'status' => $status,
            'segments' => $segments,
        ];
    }
}
PHPFILE;
}

function new_config_mrl(): string
{
    return <<<'PHPFILE'
<?php
/**
 * config_mrl.php
 *
 * VERSION: v001
 * LAST MODIFIED: 8/19/2026 4:51:53 am
 *
 * DESCRIPTION:
 * Backward-compatible MRL configuration layer.
 *
 * The admin_setup table still supplies active race year, emergency formLocked,
 * currentForm, and temporary pick-window override fields. Normal pick segment and
 * normal deadline are now calculated automatically from the canonical race schedule
 * + segment_race_ranges through pick_window_helper.php.
 *
 * IMPORTANT COMPATIBILITY RULE:
 * - $segment intentionally continues to mean the PICK segment for legacy pages.
 *   This mirrors the old manual workflow where admin_setup.segment was advanced
 *   early so team/submission/chart pages worked during the next segment's pick window.
 * - $scoringSegment is the explicit segment currently being raced/scored.
 * - $pickSegment is the explicit segment currently accepting/eligible for picks.
 *
 * Existing code does not need a remembered migration: legacy $segment remains usable,
 * while new code can use the explicit variables immediately.
 *
 * CHANGELOG:
 * v001 (8/19/2026 4:51:53 am)
 * - NEW: Automatic 15-day normal-pick window support.
 * - NEW: Explicit $scoringSegment / $pickSegment state.
 * - NEW: Temporary admin pick-window override support.
 * - CHANGE: Legacy $segment/$formLockDate are automatically mapped to pick state.
 * - SAFETY: Falls back to stored legacy admin_setup values if automatic resolution fails.
 */

// ---------------------------------------------------------------------
// FALLBACK VALUES (used only if DB/automatic schedule resolution fails)
// ---------------------------------------------------------------------
$formLocked = 'no';
$raceYear = '2026';
$previousRaceYear = (int)$raceYear - 1;
$segment = 'S1';
$formLockDate = '2/15/2026 2:30 pm';
$currentForm = 'form-team-picks.php';
$formLockedMessage = '**** Message - Submission form is currently offline ****';
$formHeaderMessage = '** Dropdown will only show drivers available to add to your team. **';

$scoringSegment = $segment;
$pickSegment = $segment;
$pickWindowIsOpen = false;
$pickWindowStatus = 'FALLBACK';
$pickWindowSource = 'FALLBACK';
$pickWindowOpenAt = '';
$pickDeadlineAt = $formLockDate;
$pickWindowError = '';

$adminConfiguredSegment = $segment;
$adminConfiguredLockDate = $formLockDate;
$adminConfiguredFormLocked = $formLocked;

$adminSetupRow = null;

// ---------------------------------------------------------------------
// READ ADMIN_SETUP
// ---------------------------------------------------------------------
if (isset($dbconnect) && $dbconnect instanceof mysqli) {
    $sql = "
        SELECT
            raceYear,
            segment,
            formLocked,
            formLockDate,
            formLockTime,
            currentForm,
            pickOverrideEnabled,
            pickOverrideSegment,
            pickOverrideOpenAt,
            pickOverrideDeadlineAt
        FROM admin_setup
        ORDER BY updatedAt DESC
        LIMIT 1
    ";

    $result = mysqli_query($dbconnect, $sql);
    if ($result && mysqli_num_rows($result) === 1) {
        $adminSetupRow = mysqli_fetch_assoc($result);
    }
}

if (is_array($adminSetupRow)) {
    $raceYear = (string)$adminSetupRow['raceYear'];
    $previousRaceYear = (int)$raceYear - 1;
    $formLocked = strtolower((string)$adminSetupRow['formLocked']) === 'yes' ? 'yes' : 'no';
    $currentForm = trim((string)$adminSetupRow['currentForm']) !== ''
        ? (string)$adminSetupRow['currentForm']
        : $currentForm;

    $adminConfiguredSegment = strtoupper(trim((string)$adminSetupRow['segment']));
    $adminConfiguredFormLocked = $formLocked;
    $adminConfiguredLockDate = trim((string)$adminSetupRow['formLockDate'] . ' ' . (string)$adminSetupRow['formLockTime']);

    // Preserve legacy DB values as fallback until automatic state is confirmed.
    if ($adminConfiguredSegment !== '') {
        $segment = $adminConfiguredSegment;
        $scoringSegment = $segment;
        $pickSegment = $segment;
    }
    if ($adminConfiguredLockDate !== '') {
        $formLockDate = $adminConfiguredLockDate;
        $pickDeadlineAt = $formLockDate;
    }
}

// ---------------------------------------------------------------------
// AUTOMATIC PICK WINDOW
// ---------------------------------------------------------------------
$pickWindowHelper = __DIR__ . '/pick_window_helper.php';
if (is_file($pickWindowHelper)) {
    require_once $pickWindowHelper;

    try {
        $override = [
            'enabled' => is_array($adminSetupRow) ? (string)($adminSetupRow['pickOverrideEnabled'] ?? 'no') : 'no',
            'segment' => is_array($adminSetupRow) ? (string)($adminSetupRow['pickOverrideSegment'] ?? '') : '',
            'open_at' => is_array($adminSetupRow) ? (string)($adminSetupRow['pickOverrideOpenAt'] ?? '') : '',
            'deadline_at' => is_array($adminSetupRow) ? (string)($adminSetupRow['pickOverrideDeadlineAt'] ?? '') : '',
        ];

        $pickWindowState = mrl_pick_window_state((int)$raceYear, $override);

        $scoringSegment = (string)$pickWindowState['scoring_segment'];
        $pickSegment = (string)$pickWindowState['pick_segment'];

        // BACKWARD COMPATIBILITY: old pages keep using $segment, but now it is
        // automatically the correct pick segment instead of a manually advanced DB value.
        $segment = $pickSegment;

        $pickWindowIsOpen = (bool)$pickWindowState['window_is_open'];
        $pickWindowStatus = (string)$pickWindowState['status'];
        $pickWindowSource = (string)$pickWindowState['source'];
        $pickWindowOpenAt = (string)$pickWindowState['window_open_display'];
        $pickDeadlineAt = (string)$pickWindowState['deadline_display'];
        $pickWindowError = (string)($pickWindowState['override_error'] ?? '');

        // BACKWARD COMPATIBILITY: old pages keep using the familiar combined
        // $formLockDate string, now sourced from the automatic/override deadline.
        $formLockDate = $pickDeadlineAt;
    } catch (Throwable $e) {
        $pickWindowError = $e->getMessage();
        $pickWindowStatus = 'FALLBACK';
        $pickWindowSource = 'FALLBACK';
        $pickWindowIsOpen = false;
    }
}

// ---------------------------------------------------------------------
// SEGMENT NAMES
// ---------------------------------------------------------------------
if (!function_exists('mrl_config_segment_name')) {
    function mrl_config_segment_name(int $year, string $segment): string
    {
        $segment = strtoupper(trim($segment));
        if ($segment === 'S1') return 'Segment #1';
        if ($segment === 'S2') return 'Segment #2';
        if ($segment === 'S3') return 'Segment #3';
        if ($segment === 'S4') return $year >= 2026 ? 'The Chase' : 'Playoffs';
        return $segment;
    }
}

$segmentName = mrl_config_segment_name((int)$raceYear, (string)$segment);
$pickSegmentName = mrl_config_segment_name((int)$raceYear, (string)$pickSegment);
$scoringSegmentName = mrl_config_segment_name((int)$raceYear, (string)$scoringSegment);

// ---------------------------------------------------------------------
// HEADER MESSAGES
// ---------------------------------------------------------------------
$formHeaderMessage2 = "Picks for $raceYear $segmentName due by $formLockDate. When you click 'Submit Picks', they will be entered into our database, and appear in chart above.";

// ---------------------------------------------------------------------
// PREVIOUS SUBMISSION SEGMENT (legacy behavior preserved)
// ---------------------------------------------------------------------
if ($segment === 'S1') $prevSegment = 'S4';
if ($segment === 'S2') $prevSegment = 'S1';
if ($segment === 'S3') $prevSegment = 'S2';
if ($segment === 'S4') $prevSegment = 'S3';

// ---------------------------------------------------------------------
// COMPARE SUBMISSION SEGMENT (legacy behavior preserved exactly)
// ---------------------------------------------------------------------
if ($segment === 'S1') $compareSegment = 'S4';
if ($segment === 'S2') $compareSegment = 'S1';
if ($segment === 'S3') $compareSegment = 'S1';
if ($segment === 'S4') $compareSegment = 'S1';
?>
PHPFILE;
}

function new_admin_setup(): string
{
    return <<<'PHPFILE'
<?php
declare(strict_types=1);

/**
 * admin_setup.php
 *
 * VERSION: v001
 * LAST MODIFIED: 8/19/2026 4:51:53 am
 *
 * DESCRIPTION:
 * MRL automatic pick-window status and temporary override admin page.
 *
 * Normal operation requires no manual segment or deadline maintenance.
 * The pick segment/deadline come from the canonical race schedule and
 * segment_race_ranges. This page retains race-year setup, emergency formLocked,
 * Add Year, and a temporary pick-window override for unusual situations/testing.
 *
 * CHANGELOG:
 * v001 (8/19/2026 4:51:53 am)
 * - Replaced manual normal segment/deadline workflow with automatic status.
 * - Added temporary pick-segment/open/deadline override controls.
 * - Preserved legacy stored admin_setup values for fallback/diagnostics.
 * - Preserved formLocked as emergency/master lock and Add Year function.
 */

session_start();
date_default_timezone_set('America/New_York');
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/admin_setup.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config_mrl.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/class.user.php';

$user_home = new USER();
if (!$user_home->is_logged_in()) {
    $user_home->redirect('login.php');
    exit;
}

$uid = (int)($_SESSION['userSession'] ?? 0);
$isAdmin = isAdmin($uid);
if (!$isAdmin) {
    echo '<div style="color:#ff6666;background:#222;padding:20px;font:18px Arial">You are NOT authorized to view/use this page.</div>';
    exit;
}

function ah($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function adt($v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    $ts = strtotime($v);
    return $ts === false ? '' : date('n/j/Y g:i A', $ts);
}
function adtlocal($v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    $ts = strtotime($v);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

$years = [];
$res = mysqli_query($dbconnect, "SELECT year FROM years ORDER BY year DESC");
while ($res && ($r = mysqli_fetch_assoc($res))) $years[] = (int)$r['year'];
$maxYear = $years ? max($years) : (int)date('Y');
$nextYear = $maxYear + 1;

$segments = [];
$res = mysqli_query($dbconnect, "SELECT segment FROM segment_race_ranges WHERE raceYear=" . (int)$raceYear . " ORDER BY startRace");
while ($res && ($r = mysqli_fetch_assoc($res))) $segments[] = strtoupper((string)$r['segment']);
if (!$segments) $segments = ['S1','S2','S3','S4'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_year') {
        mysqli_query($dbconnect, "INSERT INTO years (yearID,year) VALUES ($nextYear,$nextYear)");
        header('Location: /admin_setup.php?msg=' . urlencode("Added year $nextYear."));
        exit;
    }

    if ($action === 'save_system') {
        $newYear = (int)($_POST['raceYear'] ?? 0);
        $locked = strtolower((string)($_POST['formLocked'] ?? 'no')) === 'yes' ? 'yes' : 'no';
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET raceYear=?, formLocked=?, updatedBy=?, updatedAt=NOW() WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'isi', $newYear, $locked, $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('System settings updated.'));
        exit;
    }

    if ($action === 'save_override') {
        $enabled = strtolower((string)($_POST['pickOverrideEnabled'] ?? 'no')) === 'yes' ? 'yes' : 'no';
        $ovSeg = strtoupper(trim((string)($_POST['pickOverrideSegment'] ?? '')));
        $openRaw = trim((string)($_POST['pickOverrideOpenAt'] ?? ''));
        $deadlineRaw = trim((string)($_POST['pickOverrideDeadlineAt'] ?? ''));

        if (!in_array($ovSeg, $segments, true)) {
            header('Location: /admin_setup.php?msg=' . urlencode('Override not saved: invalid segment.'));
            exit;
        }

        $openTs = $openRaw !== '' ? strtotime($openRaw) : false;
        $deadlineTs = $deadlineRaw !== '' ? strtotime($deadlineRaw) : false;
        if ($openTs === false || $deadlineTs === false || $openTs >= $deadlineTs) {
            header('Location: /admin_setup.php?msg=' . urlencode('Override not saved: opening and deadline are required, and opening must be earlier.'));
            exit;
        }

        $openDb = date('Y-m-d H:i:s', $openTs);
        $deadlineDb = date('Y-m-d H:i:s', $deadlineTs);
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickOverrideEnabled=?, pickOverrideSegment=?, pickOverrideOpenAt=?, pickOverrideDeadlineAt=?, updatedBy=?, updatedAt=NOW() WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'ssssi', $enabled, $ovSeg, $openDb, $deadlineDb, $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('Temporary pick-window override updated.'));
        exit;
    }

    if ($action === 'disable_override') {
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickOverrideEnabled='no', updatedBy=?, updatedAt=NOW() WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'i', $uid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('Override disabled; automatic mode restored.'));
        exit;
    }
}

$res = mysqli_query($dbconnect, "SELECT a.*,u.userName FROM admin_setup a LEFT JOIN users u ON a.updatedBy=u.userID WHERE a.id=1");
$current = $res ? mysqli_fetch_assoc($res) : [];
$msg = (string)($_GET['msg'] ?? '');

$autoState = null;
$autoError = '';
try {
    if (function_exists('mrl_pick_window_state')) {
        $autoState = mrl_pick_window_state((int)$raceYear, ['enabled' => 'no']);
    }
} catch (Throwable $e) {
    $autoError = $e->getMessage();
}

$overrideEnabled = strtolower((string)($current['pickOverrideEnabled'] ?? 'no')) === 'yes';
$defaultOverrideSegment = (string)($current['pickOverrideSegment'] ?? ($autoState['pick_segment'] ?? $pickSegment));
$defaultOverrideOpen = (string)($current['pickOverrideOpenAt'] ?? '');
$defaultOverrideDeadline = (string)($current['pickOverrideDeadlineAt'] ?? '');
if ($defaultOverrideOpen === '' && is_array($autoState) && isset($autoState['window_open_dt'])) {
    $defaultOverrideOpen = $autoState['window_open_dt']->format('Y-m-d H:i:s');
}
if ($defaultOverrideDeadline === '' && is_array($autoState) && isset($autoState['deadline_dt'])) {
    $defaultOverrideDeadline = $autoState['deadline_dt']->format('Y-m-d H:i:s');
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>MRL Admin Setup - Automatic Pick Window</title>
<link rel="stylesheet" href="/mrl-styles.css">
<style>
body{background:#222;color:#dfcca8;font-family:Tahoma,Verdana,Segoe,sans-serif;font-size:16px}
.wrap{width:92%;max-width:1200px;margin:20px auto}
h1,h2{text-align:center}.card{border:1px solid #666;border-radius:8px;padding:16px;margin:16px 0;background:#292929}
table{width:100%;border-collapse:collapse}th,td{padding:8px 10px;border-bottom:1px solid #555;text-align:left}th{color:#bbb}
.good{color:#7CFC8A;font-weight:bold}.warn{color:#ffcc66;font-weight:bold}.bad{color:#ff6666;font-weight:bold}
.control{font:16px Tahoma;background:#fff;color:#000;border:2px solid #666;border-radius:3px;padding:5px 8px;box-sizing:border-box}
button{font:16px Tahoma;background:#fff;color:#000;border:2px solid #666;border-radius:3px;padding:5px 14px;cursor:pointer}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field label{display:block;color:#bbb;margin-bottom:5px}.field .control{width:100%}
.note{font-size:13px;color:#bbb;line-height:1.4}.flash{position:fixed;top:8px;left:50%;transform:translateX(-50%);background:#111;padding:8px 16px;border-radius:6px;z-index:99}
@media(max-width:760px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
<h1>MRL Automatic Pick Window</h1>
<p style="text-align:center">Normal picks open automatically <b>15 days before</b> the first race of a segment and close at that race's scheduled start.</p>
<?php if($msg!==''): ?><div class="flash"><?php echo ah($msg); ?></div><?php endif; ?>

<div class="card">
<h2>Current Effective State</h2>
<table>
<tr><th>Race Year</th><td><?php echo ah($raceYear); ?></td><th>Source</th><td class="<?php echo $pickWindowSource==='AUTO'?'good':'warn'; ?>"><?php echo ah($pickWindowSource); ?></td></tr>
<tr><th>Scoring Segment</th><td><?php echo ah($scoringSegment . ' — ' . $scoringSegmentName); ?></td><th>Pick Segment</th><td><?php echo ah($pickSegment . ' — ' . $pickSegmentName); ?></td></tr>
<tr><th>Normal Picks Open</th><td><?php echo ah($pickWindowOpenAt); ?></td><th>Normal Deadline</th><td><?php echo ah($pickDeadlineAt); ?></td></tr>
<tr><th>Window Status</th><td class="<?php echo $pickWindowIsOpen?'good':'warn'; ?>"><?php echo ah($pickWindowStatus); ?></td><th>Master Lock</th><td class="<?php echo $formLocked==='yes'?'bad':'good'; ?>"><?php echo ah($formLocked); ?></td></tr>
</table>
<?php if($pickWindowError!==''): ?><p class="bad">Pick-window warning: <?php echo ah($pickWindowError); ?></p><?php endif; ?>
</div>

<div class="card">
<h2>System Settings</h2>
<form method="post">
<div class="grid">
<div class="field"><label>Race Year</label><select name="raceYear" class="control"><?php foreach($years as $y): ?><option value="<?php echo $y; ?>" <?php echo ((int)$raceYear===$y)?'selected':''; ?>><?php echo $y; ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Emergency / Master Form Lock</label><select name="formLocked" class="control"><option value="no" <?php echo $formLocked!=='yes'?'selected':''; ?>>No</option><option value="yes" <?php echo $formLocked==='yes'?'selected':''; ?>>Yes</option></select></div>
</div>
<p style="text-align:center"><button name="action" value="save_system">Save System Settings</button> &nbsp; <button name="action" value="add_year">Add <?php echo $nextYear; ?></button></p>
</form>
<p class="note">The master lock still shuts off forms immediately. Normal segment and deadline values no longer need manual maintenance.</p>
</div>

<div class="card">
<h2>Temporary Pick Window Override</h2>
<form method="post">
<div class="grid">
<div class="field"><label>Override Enabled</label><select name="pickOverrideEnabled" class="control"><option value="no" <?php echo !$overrideEnabled?'selected':''; ?>>No</option><option value="yes" <?php echo $overrideEnabled?'selected':''; ?>>Yes</option></select></div>
<div class="field"><label>Pick Segment</label><select name="pickOverrideSegment" class="control"><?php foreach($segments as $s): ?><option value="<?php echo ah($s); ?>" <?php echo $defaultOverrideSegment===$s?'selected':''; ?>><?php echo ah($s); ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Override Opens</label><input type="datetime-local" name="pickOverrideOpenAt" class="control" value="<?php echo ah(adtlocal($defaultOverrideOpen)); ?>"></div>
<div class="field"><label>Override Deadline</label><input type="datetime-local" name="pickOverrideDeadlineAt" class="control" value="<?php echo ah(adtlocal($defaultOverrideDeadline)); ?>"></div>
</div>
<p style="text-align:center"><button name="action" value="save_override">Save Override</button> &nbsp; <button name="action" value="disable_override">Disable Override / Return to AUTO</button></p>
</form>
<p class="note">An override affects the <b>pick segment/window only</b>. The scoring segment remains schedule-derived. Use this for testing, unusual deadline exceptions, postponed setup, etc. Disable it when finished and AUTO immediately resumes.</p>
</div>

<div class="card">
<h2>Automatic Reference</h2>
<?php if(is_array($autoState)): ?>
<table>
<tr><th>Auto Scoring Segment</th><td><?php echo ah($autoState['scoring_segment']); ?></td><th>Auto Pick Segment</th><td><?php echo ah($autoState['pick_segment']); ?></td></tr>
<tr><th>Auto Opens</th><td><?php echo ah($autoState['window_open_display']); ?></td><th>Auto Deadline</th><td><?php echo ah($autoState['deadline_display']); ?></td></tr>
</table>
<?php else: ?><p class="bad">Unable to calculate AUTO reference: <?php echo ah($autoError); ?></p><?php endif; ?>
</div>

<div class="card">
<h2>Legacy Stored Values — Fallback / Diagnostic Only</h2>
<table>
<tr><th>Stored Segment</th><td><?php echo ah($current['segment'] ?? ''); ?></td><th>Stored Lock Date/Time</th><td><?php echo ah(adt(($current['formLockDate'] ?? '') . ' ' . ($current['formLockTime'] ?? ''))); ?></td></tr>
<tr><th>Current Form</th><td><?php echo ah($current['currentForm'] ?? ''); ?></td><th>Last Updated</th><td><?php echo ah(adt($current['updatedAt'] ?? '')); ?><?php if(!empty($current['userName'])) echo ' by ' . ah($current['userName']); ?></td></tr>
</table>
<p class="note">These legacy segment/lock fields are intentionally retained so older/fallback behavior remains recoverable. You do not need to update them during normal operation.</p>
</div>
</div>
<script>setTimeout(function(){var e=document.querySelector('.flash');if(e)e.style.display='none';},2500);</script>
</body>
</html>
PHPFILE;
}

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$action = (string)($_POST['action'] ?? '');
$messages = [];
$errors = [];
$warnings = [];
$preflight = [];
$installComplete = false;
$backupDir = '';

if ($host !== REQUIRED_HOST) {
    $errors[] = 'REFUSED: This installer may run only on ' . REQUIRED_HOST . '. Current host: ' . ($host ?: '(unknown)');
}
if ($root === '' || stripos($root, 'testphp8') === false) {
    $errors[] = 'REFUSED: DOCUMENT_ROOT does not look like the TestPHP8 site: ' . ($root ?: '(unknown)');
}

$paths = [
    'team.php' => $root . '/team.php',
    'form-team-picks.php' => $root . '/form-team-picks.php',
    'submit-team-picks.php' => $root . '/submit-team-picks.php',
    'current_user_team_chart.php' => $root . '/current_user_team_chart.php',
    'config_mrl.php' => $root . '/config_mrl.php',
    'admin_setup.php' => $root . '/admin_setup.php',
    'race_schedule_helper.php' => $root . '/race_results/race_schedule_helper.php',
    'schedule_json' => $root . '/race_results/_race_results_schedule.json',
    'pick_window_helper.php' => $root . '/pick_window_helper.php',
];

$prepared = [];
$db = null;
$activeYear = 0;
$existingOverrideColumns = [];

if (empty($errors)) {
    try {
        require_once $root . '/config.php';
        if (!isset($dbconnect) || !($dbconnect instanceof mysqli)) {
            throw new RuntimeException('config.php did not provide mysqli $dbconnect.');
        }
        $db = $dbconnect;

        foreach (['team.php','form-team-picks.php','submit-team-picks.php','current_user_team_chart.php','config_mrl.php','admin_setup.php','race_schedule_helper.php','schedule_json'] as $label) {
            if (!is_file($paths[$label])) {
                throw new RuntimeException('Required file missing: ' . $paths[$label]);
            }
        }

        $checks = [
            ['team.php v018', file_has($paths['team.php'], 'VERSION: v018')],
            ['form-team-picks.php v005', file_has($paths['form-team-picks.php'], 'VERSION: v005')],
            ['submit-team-picks.php v005', file_has($paths['submit-team-picks.php'], 'VERSION: v005')],
            ['current_user_team_chart.php v002', file_has($paths['current_user_team_chart.php'], 'VERSION: v002')],
            ['race_schedule_helper.php v003', file_has($paths['race_schedule_helper.php'], 'VERSION: v003')],
            ['config_mrl.php legacy DB-driven marker', file_has($paths['config_mrl.php'], 'MRL Configuration File (Database Driven)')],
            ['admin_setup.php legacy save_changes marker', file_has($paths['admin_setup.php'], "if(\$action==='save_changes')")],
        ];
        foreach ($checks as [$label,$ok]) {
            $preflight[] = [$label, $ok ? 'PASS' : 'FAIL'];
            if (!$ok) $errors[] = 'Preflight failed: ' . $label;
        }

        if (is_file($paths['pick_window_helper.php'])) {
            $errors[] = 'pick_window_helper.php already exists. Refusing to overwrite an unknown/previous install.';
            $preflight[] = ['pick_window_helper.php absent before first install', 'FAIL'];
        } else {
            $preflight[] = ['pick_window_helper.php absent before first install', 'PASS'];
        }

        $row = query_one($db, "SELECT * FROM admin_setup WHERE id=1 LIMIT 1");
        if (!$row) {
            $errors[] = 'admin_setup id=1 row not found.';
            $preflight[] = ['admin_setup id=1 row', 'FAIL'];
        } else {
            $activeYear = (int)$row['raceYear'];
            $preflight[] = ['admin_setup id=1 row', 'PASS'];
        }

        $columns = table_columns($db, 'admin_setup');
        foreach (['pickOverrideEnabled','pickOverrideSegment','pickOverrideOpenAt','pickOverrideDeadlineAt'] as $col) {
            if (isset($columns[$col])) $existingOverrideColumns[] = $col;
        }
        if (!empty($existingOverrideColumns)) {
            $errors[] = 'One or more auto-pick override columns already exist (' . implode(', ', $existingOverrideColumns) . '). Refusing a partial/repeat first install.';
            $preflight[] = ['admin_setup override columns absent before first install', 'FAIL'];
        } else {
            $preflight[] = ['admin_setup override columns absent before first install', 'PASS'];
        }

        $segmentRows = [];
        if ($activeYear > 0) {
            $res = mysqli_query($db, "SELECT segment,startRace,endRace FROM segment_race_ranges WHERE raceYear={$activeYear} ORDER BY startRace");
            while ($res && ($r = mysqli_fetch_assoc($res))) $segmentRows[] = $r;
        }
        $segmentOk = count($segmentRows) >= 4;
        foreach ($segmentRows as $r) {
            if ((int)$r['startRace'] <= 0 || (int)$r['endRace'] < (int)$r['startRace']) $segmentOk = false;
        }
        $preflight[] = ['segment_race_ranges for ' . $activeYear, $segmentOk ? 'PASS (' . count($segmentRows) . ' rows)' : 'FAIL'];
        if (!$segmentOk) $errors[] = 'segment_race_ranges is incomplete/invalid for active year ' . $activeYear . '.';

        $scheduleRaw = file_get_contents($paths['schedule_json']);
        $schedule = is_string($scheduleRaw) ? json_decode($scheduleRaw, true) : null;
        $scheduleOk = is_array($schedule)
            && (int)($schedule['year'] ?? 0) === $activeYear
            && ((isset($schedule['mrl_points_races']) && is_array($schedule['mrl_points_races'])) || (isset($schedule['races']) && is_array($schedule['races'])));
        $preflight[] = ['canonical schedule year/list', $scheduleOk ? 'PASS' : 'FAIL'];
        if (!$scheduleOk) $errors[] = 'Canonical _race_results_schedule.json is missing/invalid or year does not match admin_setup.';

        // Verify every segment start race exists and has a usable start time in the canonical points list.
        if ($scheduleOk && $segmentOk) {
            $list = isset($schedule['mrl_points_races']) && is_array($schedule['mrl_points_races']) ? $schedule['mrl_points_races'] : $schedule['races'];
            $raceMap = [];
            foreach ($list as $race) {
                if (!is_array($race)) continue;
                $n = (int)($race['mrl_race_number'] ?? $race['race_number'] ?? $race['schedule_sequence'] ?? 0);
                if ($n > 0) $raceMap[$n] = $race;
            }
            foreach ($segmentRows as $r) {
                $n = (int)$r['startRace'];
                $race = $raceMap[$n] ?? null;
                $startOk = is_array($race) && (trim((string)($race['start_at'] ?? '')) !== '' || (int)($race['start_ts'] ?? 0) > 0);
                $preflight[] = [(string)$r['segment'] . ' start race R' . $n . ' schedule time', $startOk ? 'PASS' : 'FAIL'];
                if (!$startOk) $errors[] = $r['segment'] . ' start race R' . $n . ' has no usable canonical start time.';
            }
        }

        // Precompute transformations now, before any write or DB schema change.
        if (empty($errors)) {
            $prepared['team.php'] = patch_team_php((string)file_get_contents($paths['team.php']));
            $prepared['form-team-picks.php'] = patch_form_team_picks((string)file_get_contents($paths['form-team-picks.php']));
            $prepared['submit-team-picks.php'] = patch_submit_team_picks((string)file_get_contents($paths['submit-team-picks.php']));
            $prepared['current_user_team_chart.php'] = patch_current_user_team_chart((string)file_get_contents($paths['current_user_team_chart.php']));
            $prepared['pick_window_helper.php'] = new_pick_window_helper();
            $prepared['config_mrl.php'] = new_config_mrl();
            $prepared['admin_setup.php'] = new_admin_setup();
            $preflight[] = ['all file transforms prepared exactly once', 'PASS'];
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

if ($action === 'install' && empty($errors) && $db instanceof mysqli) {
    $addedColumns = [];
    $originalFiles = [];
    try {
        $backupDir = $root . '/mrl_auto_pick_window_backup_' . INSTALLER_TIMESTAMP;
        if (file_exists($backupDir)) {
            throw new RuntimeException('Backup directory already exists: ' . $backupDir);
        }
        if (!mkdir($backupDir, 0755, true)) {
            throw new RuntimeException('Unable to create backup directory: ' . $backupDir);
        }

        foreach (['team.php','form-team-picks.php','submit-team-picks.php','current_user_team_chart.php','config_mrl.php','admin_setup.php','race_schedule_helper.php','schedule_json'] as $label) {
            $srcPath = $paths[$label];
            $originalFiles[$srcPath] = (string)file_get_contents($srcPath);
            $backupName = str_replace(['/', '\\'], '_', $label);
            if (!copy($srcPath, $backupDir . '/' . $backupName)) {
                throw new RuntimeException('Unable to back up ' . $label . '.');
            }
        }

        $createRow = query_one($db, 'SHOW CREATE TABLE admin_setup');
        if ($createRow) {
            $createSql = (string)array_values($createRow)[1];
            file_put_contents($backupDir . '/admin_setup_schema_before.sql', $createSql . ";\n");
        }
        $adminRow = query_one($db, 'SELECT * FROM admin_setup WHERE id=1 LIMIT 1');
        file_put_contents($backupDir . '/admin_setup_row_before.json', json_encode($adminRow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $alterStatements = [
            'pickOverrideEnabled' => "ALTER TABLE admin_setup ADD COLUMN pickOverrideEnabled enum('yes','no') NOT NULL DEFAULT 'no' AFTER formLockTime",
            'pickOverrideSegment' => "ALTER TABLE admin_setup ADD COLUMN pickOverrideSegment char(2) NULL DEFAULT NULL AFTER pickOverrideEnabled",
            'pickOverrideOpenAt' => "ALTER TABLE admin_setup ADD COLUMN pickOverrideOpenAt datetime NULL DEFAULT NULL AFTER pickOverrideSegment",
            'pickOverrideDeadlineAt' => "ALTER TABLE admin_setup ADD COLUMN pickOverrideDeadlineAt datetime NULL DEFAULT NULL AFTER pickOverrideOpenAt",
        ];

        foreach ($alterStatements as $column => $sql) {
            if (!mysqli_query($db, $sql)) {
                throw new RuntimeException('DB schema change failed for ' . $column . ': ' . mysqli_error($db));
            }
            $addedColumns[] = $column;
        }

        atomic_write($paths['pick_window_helper.php'], $prepared['pick_window_helper.php']);
        atomic_write($paths['config_mrl.php'], $prepared['config_mrl.php']);
        atomic_write($paths['admin_setup.php'], $prepared['admin_setup.php']);
        atomic_write($paths['form-team-picks.php'], $prepared['form-team-picks.php']);
        atomic_write($paths['submit-team-picks.php'], $prepared['submit-team-picks.php']);
        atomic_write($paths['current_user_team_chart.php'], $prepared['current_user_team_chart.php']);
        atomic_write($paths['team.php'], $prepared['team.php']);

        // Static postflight markers.
        $postChecks = [
            file_has($paths['pick_window_helper.php'], 'VERSION: v001'),
            file_has($paths['config_mrl.php'], 'VERSION: v001'),
            file_has($paths['admin_setup.php'], 'VERSION: v001'),
            file_has($paths['form-team-picks.php'], 'VERSION: v006'),
            file_has($paths['submit-team-picks.php'], 'VERSION: v006'),
            file_has($paths['current_user_team_chart.php'], 'VERSION: v003'),
            file_has($paths['team.php'], 'VERSION: v019'),
        ];
        if (in_array(false, $postChecks, true)) {
            throw new RuntimeException('Postflight file marker verification failed.');
        }

        $colsAfter = table_columns($db, 'admin_setup');
        foreach (array_keys($alterStatements) as $column) {
            if (!isset($colsAfter[$column])) {
                throw new RuntimeException('Postflight DB column missing: ' . $column);
            }
        }

        $installComplete = true;
        $messages[] = 'INSTALL COMPLETE.';
        $messages[] = 'Backup folder: ' . $backupDir;
        $messages[] = 'Automatic normal-pick window is now active on TestPHP8.';
        $messages[] = 'As of this install date, S4 should remain closed until 15 days before its first race unless you use the temporary override.';
    } catch (Throwable $e) {
        $errors[] = 'INSTALL FAILED: ' . $e->getMessage();

        // Attempt file rollback.
        foreach ($originalFiles as $path => $content) {
            try { atomic_write($path, $content); } catch (Throwable $ignored) {}
        }
        if (is_file($paths['pick_window_helper.php'])) {
            @unlink($paths['pick_window_helper.php']);
        }

        // Drop only columns added by this failed run, in reverse order.
        foreach (array_reverse($addedColumns) as $column) {
            @mysqli_query($db, "ALTER TABLE admin_setup DROP COLUMN `" . str_replace('`','``',$column) . "`");
        }
        $warnings[] = 'Rollback was attempted automatically. The backup folder, if created, was retained for inspection.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>MRL Auto Pick Window Installer <?php echo ih(INSTALLER_VERSION); ?></title>
<style>
body{background:#1f1f1f;color:#ddd;font:15px Arial,sans-serif;margin:0;padding:24px}.wrap{max-width:1050px;margin:0 auto}.card{background:#292929;border:1px solid #555;border-radius:8px;padding:16px;margin:14px 0}h1,h2{color:#dfcca8}table{width:100%;border-collapse:collapse}th,td{padding:7px 10px;border-bottom:1px solid #444;text-align:left}.pass{color:#78e08f;font-weight:bold}.fail{color:#ff6b6b;font-weight:bold}.warn{color:#ffd166}button{font-size:17px;padding:8px 18px;cursor:pointer}.mono{font-family:Consolas,monospace;white-space:pre-wrap}.big{font-size:18px;font-weight:bold}</style>
</head>
<body><div class="wrap">
<h1>MRL Automatic Pick Window Installer <?php echo ih(INSTALLER_VERSION); ?></h1>
<div class="card"><b>Target:</b> <?php echo ih($host ?: '(unknown)'); ?><br><b>Root:</b> <span class="mono"><?php echo ih($root ?: '(unknown)'); ?></span><br><b>Build:</b> 8/19/2026 4:51:53 am ET</div>

<?php if(!empty($preflight)): ?><div class="card"><h2>Preflight</h2><table><?php foreach($preflight as [$label,$status]): $ok=strpos($status,'PASS')===0; ?><tr><td><?php echo ih($label); ?></td><td class="<?php echo $ok?'pass':'fail'; ?>"><?php echo ih($status); ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>

<?php if(!empty($messages)): ?><div class="card pass big"><?php foreach($messages as $m): ?><div><?php echo ih($m); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if(!empty($warnings)): ?><div class="card warn"><?php foreach($warnings as $m): ?><div><?php echo ih($m); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if(!empty($errors)): ?><div class="card fail"><h2>STOPPED</h2><?php foreach($errors as $e): ?><div><?php echo ih($e); ?></div><?php endforeach; ?></div><?php endif; ?>

<?php if(empty($errors) && !$installComplete): ?>
<div class="card">
<h2>Ready</h2>
<p>Preflight passed. INSTALL will:</p>
<ul>
<li>Back up current TestPHP8 pick/config/admin files and admin_setup schema/data.</li>
<li>Add four temporary-override columns to admin_setup.</li>
<li>Add pick_window_helper.php v001.</li>
<li>Install config_mrl.php v001, admin_setup.php v001, form-team-picks.php v006, submit-team-picks.php v006, current_user_team_chart.php v003, and team.php v019.</li>
<li>Leave Live MRL untouched.</li>
</ul>
<form method="post"><button name="action" value="install">INSTALL AUTOMATIC PICK WINDOW</button></form>
</div>
<?php elseif($installComplete): ?>
<div class="card"><h2>Suggested immediate checks</h2><ol><li>Open <span class="mono">/admin_setup.php</span> and confirm AUTO state.</li><li>Open <span class="mono">/team.php</span> and confirm the current segment behavior.</li><li>If you want to test S4 early, use the new temporary override rather than changing raw DB values.</li></ol></div>
<?php endif; ?>
</div></body></html>
