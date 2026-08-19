<?php
declare(strict_types=1);

/**
 * mrl_auto_pick_window_patch.php
 *
 * VERSION: v002
 * LAST MODIFIED: 8/19/2026 3:01:00 pm
 *
 * DESCRIPTION:
 * TestPHP8-only follow-up patch for the automatic pick-window system.
 *
 * This patch:
 * - Adds a persistent configurable DEFAULT pick-window lead time (default 15 days).
 * - Adds a one-segment lead-time adjustment that applies only to the selected year/segment.
 * - Keeps the existing temporary exact-date override as a separate, higher-priority override.
 * - Automatically returns to the default lead time when a segment-specific adjustment no longer matches.
 * - Redesigns admin_setup.php into a compact dashboard-style three-panel first row:
 *      1) Current Effective State
 *      2) Temporary Pick Window Override
 *      3) System Settings
 * - Fixes admin_setup.updatedAt so writes use explicit America/New_York PHP time
 *   rather than MySQL NOW().
 * - Preserves all existing TestPHP8 automatic pick-window behavior and legacy compatibility.
 *
 * TARGET FILE VERSIONS BEFORE INSTALL:
 * - pick_window_helper.php v001
 * - config_mrl.php         v001
 * - admin_setup.php        v001
 *
 * TARGET FILE VERSIONS AFTER INSTALL:
 * - pick_window_helper.php v002
 * - config_mrl.php         v002
 * - admin_setup.php        v002
 *
 * DB COLUMNS ADDED TO admin_setup:
 * - pickWindowDefaultDays   INT NOT NULL DEFAULT 15
 * - pickLeadAdjustYear      INT NULL
 * - pickLeadAdjustSegment   CHAR(2) NULL
 * - pickLeadAdjustDays      INT NULL
 *
 * SAFETY:
 * - Refuses to run anywhere except testphp8.manliusracingleague.com.
 * - Preflight validates current installed v001 files and existing v001 override columns.
 * - Preflight validates canonical schedule + segment_race_ranges.
 * - Prepares all replacement files before any write.
 * - Backs up all target files plus admin_setup schema/data before installation.
 * - Adds only the four new DB columns listed above.
 * - Attempts full file/DB-column rollback if installation fails.
 * - Live MRL is never touched.
 *
 * CHANGELOG:
 * v002 (8/19/2026 2:34:00 pm)
 * - Added configurable default lead time and one-segment adjustment.
 * - Added compact dashboard-style admin UI.
 * - Fixed admin updatedAt timezone writes to explicit New York time.
 */

date_default_timezone_set('America/New_York');

const INSTALLER_VERSION = 'v002';
const INSTALLER_TIMESTAMP = '20260819_030100pm';
const REQUIRED_HOST = 'testphp8.manliusracingleague.com';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function atomic_write(string $path, string $content): void
{
    $dir = dirname($path);
    $tmp = $dir . '/.' . basename($path) . '.autopick_v002_' . bin2hex(random_bytes(5)) . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary file: ' . $tmp);
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace target file: ' . $path);
    }
}

function file_has(string $path, string $needle): bool
{
    if (!is_file($path)) return false;
    $src = file_get_contents($path);
    return $src !== false && strpos($src, $needle) !== false;
}

function db_column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = mysqli_prepare($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'ss', $table, $column);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $count);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return ((int)$count > 0);
}

function dump_admin_setup_schema(mysqli $db): string
{
    $res = mysqli_query($db, "SHOW CREATE TABLE admin_setup");
    if (!$res) throw new RuntimeException('Unable to read admin_setup schema.');
    $row = mysqli_fetch_array($res, MYSQLI_NUM);
    return isset($row[1]) ? (string)$row[1] : '';
}

function dump_admin_setup_row(mysqli $db): string
{
    $res = mysqli_query($db, "SELECT * FROM admin_setup WHERE id=1");
    if (!$res) throw new RuntimeException('Unable to read admin_setup row.');
    $row = mysqli_fetch_assoc($res);
    return json_encode($row ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function new_pick_window_helper(): string
{
    return <<<'PHPFILE'
<?php
declare(strict_types=1);

/**
 * pick_window_helper.php
 *
 * VERSION: v002
 * LAST MODIFIED: 8/19/2026 3:01:00 pm
 *
 * DESCRIPTION:
 * Shared automatic normal-pick window resolver.
 *
 * Sources:
 * - /race_results/_race_results_schedule.json for canonical race start times.
 * - segment_race_ranges for segment start/end race numbers.
 * - admin_setup pickWindowDefaultDays for the normal global lead-time rule.
 * - admin_setup pickLeadAdjust* for an optional one-segment lead-time adjustment.
 *
 * Rules:
 * - Default normal-pick lead time is configurable in admin_setup (15 days initially).
 * - A segment-specific adjustment applies ONLY to its matching year + segment.
 * - When the system moves to another segment, the unmatched adjustment is ignored and
 *   the global default automatically resumes.
 * - The temporary exact-date override remains a separate, higher-priority override.
 * - Normal picks close at the scheduled start of the first race in the segment.
 * - scoringSegment is the latest segment whose first race has started.
 * - pickSegment becomes the next segment during its calculated normal-pick window;
 *   otherwise it remains the scoring segment so LP/current-segment behavior works.
 * - Temporary admin override changes PICK state only; scoringSegment remains automatic.
 *
 * CHANGELOG:
 * v002 (8/19/2026 2:34:00 pm)
 * - NEW: Configurable global default lead time.
 * - NEW: One-segment lead-time adjustment keyed by year + segment.
 * - NEW: Window state reports effective lead-time source/default/adjustment details.
 * - CHANGE: Replaced hardcoded 15-day calculation with supplied settings.
 *
 * v001 (8/19/2026 4:51:53 am)
 * - Initial automatic pick-window helper.
 */

const MRL_PICK_WINDOW_DEFAULT_DAYS = 15;
const MRL_PICK_WINDOW_TIMEZONE = 'America/New_York';

require_once __DIR__ . '/race_results/race_schedule_helper.php';

if (!function_exists('mrl_pick_window_timezone')) {
    function mrl_pick_window_timezone(): DateTimeZone
    {
        return new DateTimeZone(MRL_PICK_WINDOW_TIMEZONE);
    }
}

if (!function_exists('mrl_pick_window_normalize_days')) {
    function mrl_pick_window_normalize_days($value, int $fallback = MRL_PICK_WINDOW_DEFAULT_DAYS): int
    {
        $days = filter_var($value, FILTER_VALIDATE_INT);
        if ($days === false || $days < 0 || $days > 90) {
            return $fallback;
        }
        return (int)$days;
    }
}

if (!function_exists('mrl_pick_window_segment_rows')) {
    function mrl_pick_window_segment_rows(int $year, array $settings = []): array
    {
        global $dbo, $dbconnect;
        $rows = [];

        $defaultDays = mrl_pick_window_normalize_days(
            $settings['default_days'] ?? MRL_PICK_WINDOW_DEFAULT_DAYS,
            MRL_PICK_WINDOW_DEFAULT_DAYS
        );
        $adjustYear = (int)($settings['adjust_year'] ?? 0);
        $adjustSegment = strtoupper(trim((string)($settings['adjust_segment'] ?? '')));
        $adjustDaysRaw = $settings['adjust_days'] ?? null;
        $adjustDays = ($adjustDaysRaw === null || $adjustDaysRaw === '')
            ? null
            : mrl_pick_window_normalize_days($adjustDaysRaw, $defaultDays);

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

            $leadDays = $defaultDays;
            $leadSource = 'DEFAULT';
            if ($adjustYear === $year && $adjustSegment === $segment && $adjustDays !== null) {
                $leadDays = $adjustDays;
                $leadSource = 'SEGMENT_ADJUSTMENT';
            }

            $startDt = mrl_schedule_helper_race_datetime($race);
            $openDt = $startDt->sub(new DateInterval('P' . $leadDays . 'D'));

            $out[] = [
                'segment' => $segment,
                'start_race' => $startRace,
                'end_race' => $endRace,
                'start_dt' => $startDt,
                'open_dt' => $openDt,
                'deadline_dt' => $startDt,
                'lead_days' => $leadDays,
                'lead_source' => $leadSource,
                'default_days' => $defaultDays,
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
        ?DateTimeImmutable $now = null,
        array $settings = []
    ): array {
        $now = $now ?? new DateTimeImmutable('now', mrl_pick_window_timezone());
        $segments = mrl_pick_window_segment_rows($year, $settings);

        $scoringRow = $segments[0];
        foreach ($segments as $row) {
            if ($row['start_dt'] <= $now) {
                $scoringRow = $row;
            } else {
                break;
            }
        }

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
        $effectiveLeadDays = (int)$pickRow['lead_days'];
        $leadSource = (string)$pickRow['lead_source'];

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
                $effectiveLeadDays = (int)$requestedRow['lead_days'];
                $leadSource = (string)$requestedRow['lead_source'];
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
            'default_lead_days' => (int)$pickRow['default_days'],
            'effective_lead_days' => $effectiveLeadDays,
            'lead_source' => $leadSource,
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
 * VERSION: v002
 * LAST MODIFIED: 8/19/2026 3:01:00 pm
 *
 * DESCRIPTION:
 * Backward-compatible MRL configuration layer with automatic pick-window support.
 *
 * CHANGELOG:
 * v002 (8/19/2026 2:34:00 pm)
 * - NEW: Reads configurable global default pick-window lead time from admin_setup.
 * - NEW: Reads optional one-segment lead-time adjustment from admin_setup.
 * - NEW: Exposes effective/default lead-time variables for admin/UI use.
 * - CHANGE: Temporary exact-date override remains higher priority than AUTO lead-time rules.
 *
 * v001 (8/19/2026 4:51:53 am)
 * - Initial automatic pick-window configuration layer.
 */

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
$pickWindowDefaultDays = 15;
$pickWindowEffectiveDays = 15;
$pickWindowLeadSource = 'DEFAULT';
$pickLeadAdjustYear = null;
$pickLeadAdjustSegment = '';
$pickLeadAdjustDays = null;

$adminConfiguredSegment = $segment;
$adminConfiguredLockDate = $formLockDate;
$adminConfiguredFormLocked = $formLocked;
$adminSetupRow = null;

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
            pickOverrideDeadlineAt,
            pickWindowDefaultDays,
            pickLeadAdjustYear,
            pickLeadAdjustSegment,
            pickLeadAdjustDays
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

    $defaultDaysCandidate = filter_var($adminSetupRow['pickWindowDefaultDays'] ?? 15, FILTER_VALIDATE_INT);
    if ($defaultDaysCandidate !== false && $defaultDaysCandidate >= 0 && $defaultDaysCandidate <= 90) {
        $pickWindowDefaultDays = (int)$defaultDaysCandidate;
    }
    $pickLeadAdjustYear = isset($adminSetupRow['pickLeadAdjustYear']) && $adminSetupRow['pickLeadAdjustYear'] !== null
        ? (int)$adminSetupRow['pickLeadAdjustYear']
        : null;
    $pickLeadAdjustSegment = strtoupper(trim((string)($adminSetupRow['pickLeadAdjustSegment'] ?? '')));
    $pickLeadAdjustDays = isset($adminSetupRow['pickLeadAdjustDays']) && $adminSetupRow['pickLeadAdjustDays'] !== null
        ? (int)$adminSetupRow['pickLeadAdjustDays']
        : null;

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
        $settings = [
            'default_days' => $pickWindowDefaultDays,
            'adjust_year' => $pickLeadAdjustYear,
            'adjust_segment' => $pickLeadAdjustSegment,
            'adjust_days' => $pickLeadAdjustDays,
        ];

        $pickWindowState = mrl_pick_window_state((int)$raceYear, $override, null, $settings);

        $scoringSegment = (string)$pickWindowState['scoring_segment'];
        $pickSegment = (string)$pickWindowState['pick_segment'];
        $segment = $pickSegment;

        $pickWindowIsOpen = (bool)$pickWindowState['window_is_open'];
        $pickWindowStatus = (string)$pickWindowState['status'];
        $pickWindowSource = (string)$pickWindowState['source'];
        $pickWindowOpenAt = (string)$pickWindowState['window_open_display'];
        $pickDeadlineAt = (string)$pickWindowState['deadline_display'];
        $pickWindowError = (string)($pickWindowState['override_error'] ?? '');
        $pickWindowDefaultDays = (int)($pickWindowState['default_lead_days'] ?? $pickWindowDefaultDays);
        $pickWindowEffectiveDays = (int)($pickWindowState['effective_lead_days'] ?? $pickWindowDefaultDays);
        $pickWindowLeadSource = (string)($pickWindowState['lead_source'] ?? 'DEFAULT');
        $formLockDate = $pickDeadlineAt;
    } catch (Throwable $e) {
        $pickWindowError = $e->getMessage();
        $pickWindowStatus = 'FALLBACK';
        $pickWindowSource = 'FALLBACK';
        $pickWindowIsOpen = false;
    }
}

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

$formHeaderMessage2 = "Picks for $raceYear $segmentName due by $formLockDate. When you click 'Submit Picks', they will be entered into our database, and appear in chart above.";

if ($segment === 'S1') $prevSegment = 'S4';
if ($segment === 'S2') $prevSegment = 'S1';
if ($segment === 'S3') $prevSegment = 'S2';
if ($segment === 'S4') $prevSegment = 'S3';

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
 * VERSION: v002
 * LAST MODIFIED: 8/19/2026 3:01:00 pm
 *
 * DESCRIPTION:
 * Compact dashboard-style MRL automatic pick-window admin page.
 *
 * CHANGELOG:
 * v002 (8/19/2026 2:34:00 pm)
 * - UI: Rebuilt first data row as three compact panels in requested order:
 *       Current Effective State / Temporary Pick Window Override / System Settings.
 * - UI: Adopted race_results_dashboard-style dark cards, gold headings, status pills,
 *       tighter spacing, and vertically grouped label/value rows to reduce eye travel.
 * - NEW: Configurable global default pick-window lead time.
 * - NEW: One-segment lead-time adjustment that automatically stops applying outside
 *        its selected year/segment, returning future segments to the global default.
 * - FIX: updatedAt writes now use explicit PHP America/New_York timestamps instead of MySQL NOW().
 * - PRESERVE: Existing temporary exact-date override, master lock, Add Year, and diagnostics.
 *
 * v001 (8/19/2026 4:51:53 am)
 * - Initial automatic pick-window admin page.
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
if (!isAdmin($uid)) {
    echo '<div style="color:#ff7373;background:#141414;padding:20px;font:18px Arial">You are NOT authorized to view/use this page.</div>';
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
function ny_now_db(): string { return date('Y-m-d H:i:s'); }
function valid_days($value): ?int {
    $v = filter_var($value, FILTER_VALIDATE_INT);
    if ($v === false || $v < 0 || $v > 90) return null;
    return (int)$v;
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
    $updatedAt = ny_now_db();

    if ($action === 'add_year') {
        mysqli_query($dbconnect, "INSERT INTO years (yearID,year) VALUES ($nextYear,$nextYear)");
        header('Location: /admin_setup.php?msg=' . urlencode("Added year $nextYear."));
        exit;
    }

    if ($action === 'save_system') {
        $newYear = (int)($_POST['raceYear'] ?? 0);
        $locked = strtolower((string)($_POST['formLocked'] ?? 'no')) === 'yes' ? 'yes' : 'no';
        $defaultDays = valid_days($_POST['pickWindowDefaultDays'] ?? null);
        if ($defaultDays === null) {
            header('Location: /admin_setup.php?msg=' . urlencode('System settings not saved: default lead time must be 0-90 days.'));
            exit;
        }
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET raceYear=?, formLocked=?, pickWindowDefaultDays=?, updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'isiis', $newYear, $locked, $defaultDays, $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('System settings updated.'));
        exit;
    }

    if ($action === 'save_lead_adjustment') {
        $adjustYear = (int)($_POST['pickLeadAdjustYear'] ?? $raceYear);
        $adjustSegment = strtoupper(trim((string)($_POST['pickLeadAdjustSegment'] ?? '')));
        $adjustDays = valid_days($_POST['pickLeadAdjustDays'] ?? null);
        if (!in_array($adjustSegment, $segments, true) || $adjustDays === null) {
            header('Location: /admin_setup.php?msg=' . urlencode('Segment adjustment not saved: choose a valid segment and 0-90 days.'));
            exit;
        }
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickLeadAdjustYear=?, pickLeadAdjustSegment=?, pickLeadAdjustDays=?, updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'isiis', $adjustYear, $adjustSegment, $adjustDays, $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('One-segment lead-time adjustment saved.'));
        exit;
    }

    if ($action === 'clear_lead_adjustment') {
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickLeadAdjustYear=NULL, pickLeadAdjustSegment=NULL, pickLeadAdjustDays=NULL, updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'is', $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('Segment lead-time adjustment cleared; default lead time applies.'));
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
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickOverrideEnabled=?, pickOverrideSegment=?, pickOverrideOpenAt=?, pickOverrideDeadlineAt=?, updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'ssssis', $enabled, $ovSeg, $openDb, $deadlineDb, $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('Temporary pick-window override updated.'));
        exit;
    }

    if ($action === 'disable_override') {
        $stmt = mysqli_prepare($dbconnect, "UPDATE admin_setup SET pickOverrideEnabled='no', updatedBy=?, updatedAt=? WHERE id=1");
        mysqli_stmt_bind_param($stmt, 'is', $uid, $updatedAt);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        header('Location: /admin_setup.php?msg=' . urlencode('Override disabled; automatic mode restored.'));
        exit;
    }
}

$res = mysqli_query($dbconnect, "SELECT a.*,u.userName FROM admin_setup a LEFT JOIN users u ON a.updatedBy=u.userID WHERE a.id=1");
$current = $res ? mysqli_fetch_assoc($res) : [];
$msg = (string)($_GET['msg'] ?? '');

$settings = [
    'default_days' => (int)($current['pickWindowDefaultDays'] ?? 15),
    'adjust_year' => $current['pickLeadAdjustYear'] ?? null,
    'adjust_segment' => $current['pickLeadAdjustSegment'] ?? '',
    'adjust_days' => $current['pickLeadAdjustDays'] ?? null,
];
$autoState = null;
$autoError = '';
try {
    if (function_exists('mrl_pick_window_state')) {
        $autoState = mrl_pick_window_state((int)$raceYear, ['enabled' => 'no'], null, $settings);
    }
} catch (Throwable $e) {
    $autoError = $e->getMessage();
}

$overrideEnabled = strtolower((string)($current['pickOverrideEnabled'] ?? 'no')) === 'yes';
$defaultOverrideSegment = (string)($current['pickOverrideSegment'] ?? ($autoState['pick_segment'] ?? $pickSegment));
$defaultOverrideOpen = (string)($current['pickOverrideOpenAt'] ?? '');
$defaultOverrideDeadline = (string)($current['pickOverrideDeadlineAt'] ?? '');
if ($defaultOverrideOpen === '' && is_array($autoState) && isset($autoState['window_open_dt'])) $defaultOverrideOpen = $autoState['window_open_dt']->format('Y-m-d H:i:s');
if ($defaultOverrideDeadline === '' && is_array($autoState) && isset($autoState['deadline_dt'])) $defaultOverrideDeadline = $autoState['deadline_dt']->format('Y-m-d H:i:s');

$adjustYearDisplay = isset($current['pickLeadAdjustYear']) && $current['pickLeadAdjustYear'] !== null ? (int)$current['pickLeadAdjustYear'] : (int)$raceYear;
$adjustSegmentDisplay = trim((string)($current['pickLeadAdjustSegment'] ?? '')) !== '' ? (string)$current['pickLeadAdjustSegment'] : (string)($autoState['pick_segment'] ?? $pickSegment);
$adjustDaysDisplay = isset($current['pickLeadAdjustDays']) && $current['pickLeadAdjustDays'] !== null ? (string)$current['pickLeadAdjustDays'] : '';
$adjustActive = isset($current['pickLeadAdjustYear'], $current['pickLeadAdjustSegment'], $current['pickLeadAdjustDays'])
    && $current['pickLeadAdjustYear'] !== null && trim((string)$current['pickLeadAdjustSegment']) !== '' && $current['pickLeadAdjustDays'] !== null;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>MRL Admin Setup - Automatic Pick Window</title>
<link rel="stylesheet" href="/mrl-styles.css">
<style>
*{box-sizing:border-box}
body{margin:0;background:#151515;color:#f2f2f2;font-family:Tahoma,Verdana,Segoe,sans-serif;font-size:15px}
.wrap{width:96%;max-width:1500px;margin:12px auto 24px}
.page-title{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border:1px solid #4b4233;border-radius:14px;background:linear-gradient(180deg,#22211e,#191919);margin-bottom:10px}
.page-title h1{margin:0;color:#ffd08a;font-size:24px;letter-spacing:.2px}.subtitle{color:#aaa;font-size:13px;margin-top:3px}
.pill{display:inline-block;border:1px solid #6b5a3b;border-radius:999px;padding:5px 10px;background:#25231f;color:#eee;white-space:nowrap}.pill.good{border-color:#286c48;background:#173526;color:#62e89b}.pill.warn{border-color:#7a6330;background:#3b311b;color:#ffd166}.pill.bad{border-color:#7f3434;background:#3b1c1c;color:#ff7d7d}
.top-grid{display:grid;grid-template-columns:1.04fr 1.18fr .95fr;gap:10px;align-items:start}.card{border:1px solid #4d473f;border-radius:14px;background:linear-gradient(180deg,#222,#1b1b1b);padding:12px 14px;box-shadow:0 2px 8px rgba(0,0,0,.22)}
.card h2{margin:0 0 9px;color:#ffd08a;font-size:18px}.rows{display:grid;gap:4px}.kv{display:grid;grid-template-columns:minmax(108px,.9fr) minmax(0,1.25fr);align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #333}.kv:last-child{border-bottom:0}.k{color:#cdbd9e;font-size:13px;font-weight:bold}.v{color:#fff;min-width:0;overflow-wrap:anywhere}.v.strong{font-size:16px;font-weight:bold}.muted{color:#9d9d9d}.goodtxt{color:#62e89b}.warntxt{color:#ffd166}.badtxt{color:#ff7d7d}
.control{width:100%;font:14px Tahoma;background:#f4f4f4;color:#111;border:1px solid #777;border-radius:7px;padding:6px 8px;min-height:32px}.inline2{display:grid;grid-template-columns:1fr 1fr;gap:7px}.field{margin:0 0 7px}.field label{display:block;color:#cdbd9e;font-size:12px;font-weight:bold;margin-bottom:3px}.actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.btn{font:14px Tahoma;border:1px solid #7a6641;border-radius:9px;background:#2a2721;color:#ffe0ad;padding:6px 10px;cursor:pointer}.btn.primary{background:#3a2f1b;border-color:#b18745;color:#ffd08a}.btn.good{background:#173526;border-color:#286c48;color:#62e89b}.btn.danger{background:#3b1c1c;border-color:#7f3434;color:#ff8a8a}
.section-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}.note{font-size:12px;line-height:1.35;color:#9f9f9f;margin:7px 0 0}.lead-adjust{margin-top:9px;padding-top:9px;border-top:1px solid #3b3b3b}.lead-adjust h3{margin:0 0 7px;color:#e8c58b;font-size:14px}.status-line{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:7px}.flash{position:fixed;top:8px;left:50%;transform:translateX(-50%);z-index:100;background:#111;border:1px solid #6b5a3b;color:#ffd08a;padding:8px 14px;border-radius:9px;box-shadow:0 3px 14px #000}.compact-table{width:100%;border-collapse:collapse}.compact-table th,.compact-table td{text-align:left;padding:6px 8px;border-bottom:1px solid #333}.compact-table th{color:#cdbd9e;font-size:12px}.compact-table td{color:#eee}.diag{font-size:12px}
@media(max-width:1050px){.top-grid{grid-template-columns:1fr}.section-grid{grid-template-columns:1fr}}@media(max-width:600px){.inline2{grid-template-columns:1fr}.wrap{width:98%}}
</style>
</head>
<body>
<?php if($msg!==''): ?><div class="flash"><?php echo ah($msg); ?></div><?php endif; ?>
<div class="wrap">
<div class="page-title"><div><h1>MRL Automatic Pick Window</h1><div class="subtitle">Compact control center — schedule-derived by default, exceptions explicit.</div></div><div class="status-line"><span class="pill <?php echo $pickWindowIsOpen?'good':'warn'; ?>"><?php echo ah($pickWindowStatus); ?></span><span class="pill"><?php echo ah($raceYear); ?></span></div></div>

<div class="top-grid">
<section class="card">
<h2>Current Effective State</h2>
<div class="rows">
<div class="kv"><div class="k">Source</div><div class="v strong <?php echo $pickWindowSource==='AUTO'?'goodtxt':'warntxt'; ?>"><?php echo ah($pickWindowSource); ?></div></div>
<div class="kv"><div class="k">Scoring</div><div class="v"><?php echo ah($scoringSegment . ' — ' . $scoringSegmentName); ?></div></div>
<div class="kv"><div class="k">Pick Segment</div><div class="v strong"><?php echo ah($pickSegment . ' — ' . $pickSegmentName); ?></div></div>
<div class="kv"><div class="k">Lead Time</div><div class="v"><?php echo ah((string)$pickWindowEffectiveDays); ?> days <span class="muted">(<?php echo ah($pickWindowLeadSource); ?>)</span></div></div>
<div class="kv"><div class="k">Opens</div><div class="v"><?php echo ah($pickWindowOpenAt); ?></div></div>
<div class="kv"><div class="k">Deadline</div><div class="v"><?php echo ah($pickDeadlineAt); ?></div></div>
<div class="kv"><div class="k">Master Lock</div><div class="v <?php echo $formLocked==='yes'?'badtxt':'goodtxt'; ?>"><?php echo ah(strtoupper($formLocked)); ?></div></div>
</div>
<?php if($pickWindowError!==''): ?><p class="note badtxt">Pick-window warning: <?php echo ah($pickWindowError); ?></p><?php endif; ?>
</section>

<section class="card">
<h2>Temporary Pick Window Override</h2>
<form method="post">
<div class="inline2"><div class="field"><label>Enabled</label><select name="pickOverrideEnabled" class="control"><option value="no" <?php echo !$overrideEnabled?'selected':''; ?>>No</option><option value="yes" <?php echo $overrideEnabled?'selected':''; ?>>Yes</option></select></div><div class="field"><label>Pick Segment</label><select name="pickOverrideSegment" class="control"><?php foreach($segments as $s): ?><option value="<?php echo ah($s); ?>" <?php echo $defaultOverrideSegment===$s?'selected':''; ?>><?php echo ah($s); ?></option><?php endforeach; ?></select></div></div>
<div class="field"><label>Override Opens</label><input type="datetime-local" name="pickOverrideOpenAt" class="control" value="<?php echo ah(adtlocal($defaultOverrideOpen)); ?>"></div>
<div class="field"><label>Override Deadline</label><input type="datetime-local" name="pickOverrideDeadlineAt" class="control" value="<?php echo ah(adtlocal($defaultOverrideDeadline)); ?>"></div>
<div class="actions"><button class="btn primary" name="action" value="save_override">Save Override</button><button class="btn danger" name="action" value="disable_override">Disable / Return to AUTO</button></div>
</form>
<p class="note">Exact-date override has highest priority and affects pick state only. Scoring stays schedule-derived.</p>
</section>

<section class="card">
<h2>System Settings</h2>
<form method="post">
<div class="inline2"><div class="field"><label>Race Year</label><select name="raceYear" class="control"><?php foreach($years as $y): ?><option value="<?php echo $y; ?>" <?php echo ((int)$raceYear===$y)?'selected':''; ?>><?php echo $y; ?></option><?php endforeach; ?></select></div><div class="field"><label>Master Form Lock</label><select name="formLocked" class="control"><option value="no" <?php echo $formLocked!=='yes'?'selected':''; ?>>No</option><option value="yes" <?php echo $formLocked==='yes'?'selected':''; ?>>Yes</option></select></div></div>
<div class="field"><label>Default Pick Window Lead Time</label><input type="number" min="0" max="90" name="pickWindowDefaultDays" class="control" value="<?php echo ah((string)($current['pickWindowDefaultDays'] ?? 15)); ?>"></div>
<div class="actions"><button class="btn primary" name="action" value="save_system">Save System Settings</button><button class="btn" name="action" value="add_year">Add <?php echo $nextYear; ?></button></div>
</form>

<div class="lead-adjust">
<h3>One-Segment Lead-Time Adjustment</h3>
<div class="status-line"><?php if($adjustActive): ?><span class="pill warn"><?php echo ah((string)$current['pickLeadAdjustYear'] . ' ' . (string)$current['pickLeadAdjustSegment'] . ': ' . (string)$current['pickLeadAdjustDays'] . ' days'); ?></span><?php else: ?><span class="pill good">None — default applies</span><?php endif; ?></div>
<form method="post">
<input type="hidden" name="pickLeadAdjustYear" value="<?php echo ah((string)$adjustYearDisplay); ?>">
<div class="inline2"><div class="field"><label>Segment</label><select name="pickLeadAdjustSegment" class="control"><?php foreach($segments as $s): ?><option value="<?php echo ah($s); ?>" <?php echo $adjustSegmentDisplay===$s?'selected':''; ?>><?php echo ah($s); ?></option><?php endforeach; ?></select></div><div class="field"><label>Lead Time (days)</label><input type="number" min="0" max="90" name="pickLeadAdjustDays" class="control" value="<?php echo ah($adjustDaysDisplay); ?>" placeholder="e.g. 10"></div></div>
<div class="actions"><button class="btn primary" name="action" value="save_lead_adjustment">Save Segment Adjustment</button><button class="btn" name="action" value="clear_lead_adjustment">Clear Adjustment</button></div>
</form>
<p class="note">Applies only to the selected year/segment. The next segment automatically returns to the global default unless it has its own adjustment.</p>
</div>
</section>
</div>

<div class="section-grid">
<section class="card"><h2>Automatic Reference</h2><?php if(is_array($autoState)): ?><table class="compact-table"><tr><th>Scoring</th><td><?php echo ah($autoState['scoring_segment']); ?></td><th>Pick</th><td><?php echo ah($autoState['pick_segment']); ?></td></tr><tr><th>Lead</th><td><?php echo ah((string)$autoState['effective_lead_days']); ?> days (<?php echo ah((string)$autoState['lead_source']); ?>)</td><th>Default</th><td><?php echo ah((string)$autoState['default_lead_days']); ?> days</td></tr><tr><th>Opens</th><td><?php echo ah($autoState['window_open_display']); ?></td><th>Deadline</th><td><?php echo ah($autoState['deadline_display']); ?></td></tr></table><?php else: ?><p class="note badtxt">Unable to calculate AUTO reference: <?php echo ah($autoError); ?></p><?php endif; ?></section>
<section class="card diag"><h2>Legacy / Audit</h2><table class="compact-table"><tr><th>Stored Segment</th><td><?php echo ah($current['segment'] ?? ''); ?></td><th>Stored Lock</th><td><?php echo ah(adt(($current['formLockDate'] ?? '') . ' ' . ($current['formLockTime'] ?? ''))); ?></td></tr><tr><th>Current Form</th><td><?php echo ah($current['currentForm'] ?? ''); ?></td><th>Last Updated</th><td><?php echo ah(adt($current['updatedAt'] ?? '')); ?><?php if(!empty($current['userName'])) echo ' by ' . ah($current['userName']); ?></td></tr></table><p class="note">Legacy values remain for fallback/diagnostics; normal operation does not require manual maintenance.</p></section>
</div>
</div>
<script>setTimeout(function(){var e=document.querySelector('.flash');if(e)e.style.display='none';},2800);</script>
</body>
</html>
PHPFILE;
}

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$action = (string)($_POST['action'] ?? '');
$errors = [];
$warnings = [];
$preflight = [];
$installComplete = false;
$backupDir = '';
$db = null;

if ($host !== REQUIRED_HOST) $errors[] = 'REFUSED: This patch may run only on ' . REQUIRED_HOST . '. Current host: ' . ($host ?: '(unknown)');
if ($root === '' || stripos($root, 'testphp8') === false) $errors[] = 'REFUSED: DOCUMENT_ROOT does not look like TestPHP8: ' . ($root ?: '(unknown)');

$paths = [
    'pick_window_helper.php' => $root . '/pick_window_helper.php',
    'config_mrl.php' => $root . '/config_mrl.php',
    'admin_setup.php' => $root . '/admin_setup.php',
    'race_schedule_helper.php' => $root . '/race_results/race_schedule_helper.php',
    'schedule_json' => $root . '/race_results/_race_results_schedule.json',
];

$newColumns = [
    'pickWindowDefaultDays' => "ALTER TABLE admin_setup ADD COLUMN pickWindowDefaultDays INT NOT NULL DEFAULT 15 AFTER pickOverrideDeadlineAt",
    'pickLeadAdjustYear' => "ALTER TABLE admin_setup ADD COLUMN pickLeadAdjustYear INT NULL AFTER pickWindowDefaultDays",
    'pickLeadAdjustSegment' => "ALTER TABLE admin_setup ADD COLUMN pickLeadAdjustSegment CHAR(2) NULL AFTER pickLeadAdjustYear",
    'pickLeadAdjustDays' => "ALTER TABLE admin_setup ADD COLUMN pickLeadAdjustDays INT NULL AFTER pickLeadAdjustSegment",
];

$prepared = [
    'pick_window_helper.php' => new_pick_window_helper(),
    'config_mrl.php' => new_config_mrl(),
    'admin_setup.php' => new_admin_setup(),
];

if (empty($errors)) {
    try {
        require_once $root . '/config.php';
        if (!isset($dbconnect) || !($dbconnect instanceof mysqli)) throw new RuntimeException('config.php did not provide mysqli $dbconnect.');
        $db = $dbconnect;

        foreach ($paths as $label => $path) {
            if (!is_file($path)) throw new RuntimeException('Required file missing: ' . $path);
        }

        $checks = [
            ['pick_window_helper.php v001', file_has($paths['pick_window_helper.php'], 'VERSION: v001') && file_has($paths['pick_window_helper.php'], 'MRL_PICK_WINDOW_DAYS = 15')],
            ['config_mrl.php v001', file_has($paths['config_mrl.php'], 'VERSION: v001') && file_has($paths['config_mrl.php'], 'Automatic 15-day normal-pick window support')],
            ['admin_setup.php v001', file_has($paths['admin_setup.php'], 'VERSION: v001') && file_has($paths['admin_setup.php'], "updatedAt=NOW()")],
            ['race_schedule_helper.php v003', file_has($paths['race_schedule_helper.php'], 'VERSION: v003')],
        ];
        foreach ($checks as [$label,$ok]) {
            $preflight[] = [$label, $ok ? 'PASS' : 'FAIL'];
            if (!$ok) $errors[] = 'Preflight failed: ' . $label;
        }

        $requiredExisting = ['pickOverrideEnabled','pickOverrideSegment','pickOverrideOpenAt','pickOverrideDeadlineAt'];
        foreach ($requiredExisting as $col) {
            $ok = db_column_exists($db, 'admin_setup', $col);
            $preflight[] = ['admin_setup.' . $col . ' exists', $ok ? 'PASS' : 'FAIL'];
            if (!$ok) $errors[] = 'Missing expected v001 column: ' . $col;
        }

        foreach (array_keys($newColumns) as $col) {
            $ok = !db_column_exists($db, 'admin_setup', $col);
            $preflight[] = ['admin_setup.' . $col . ' absent before patch', $ok ? 'PASS' : 'FAIL'];
            if (!$ok) $errors[] = 'New v002 column already exists; refusing unknown/partial prior patch: ' . $col;
        }

        $rowRes = mysqli_query($db, "SELECT raceYear FROM admin_setup WHERE id=1");
        $row = $rowRes ? mysqli_fetch_assoc($rowRes) : null;
        $activeYear = is_array($row) ? (int)$row['raceYear'] : 0;
        $ok = $activeYear > 0;
        $preflight[] = ['admin_setup id=1 active year', $ok ? 'PASS (' . $activeYear . ')' : 'FAIL'];
        if (!$ok) $errors[] = 'Unable to determine active race year.';

        if ($activeYear > 0) {
            $stmt = mysqli_prepare($db, "SELECT segment,startRace,endRace FROM segment_race_ranges WHERE raceYear=? ORDER BY startRace");
            mysqli_stmt_bind_param($stmt, 'i', $activeYear);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $ranges = [];
            while ($res && ($r = mysqli_fetch_assoc($res))) $ranges[] = $r;
            mysqli_stmt_close($stmt);
            $ok = count($ranges) === 4;
            $preflight[] = ['segment_race_ranges for ' . $activeYear, $ok ? 'PASS (4 rows)' : 'FAIL (' . count($ranges) . ' rows)'];
            if (!$ok) $errors[] = 'Expected four segment_race_ranges rows for active year.';

            $jsonRaw = file_get_contents($paths['schedule_json']);
            $json = $jsonRaw !== false ? json_decode($jsonRaw, true) : null;
            $list = is_array($json) ? ($json['mrl_points_races'] ?? $json['races'] ?? null) : null;
            $ok = is_array($json) && (int)($json['year'] ?? 0) === $activeYear && is_array($list);
            $preflight[] = ['canonical schedule year/list', $ok ? 'PASS' : 'FAIL'];
            if (!$ok) $errors[] = 'Canonical schedule validation failed.';

            if ($ok && count($ranges) === 4) {
                $raceNums = [];
                foreach ($list as $race) {
                    if (!is_array($race)) continue;
                    $n = (int)($race['mrl_race_number'] ?? $race['race_number'] ?? $race['schedule_sequence'] ?? 0);
                    if ($n > 0) $raceNums[$n] = $race;
                }
                foreach ($ranges as $range) {
                    $n = (int)$range['startRace'];
                    $exists = isset($raceNums[$n]);
                    $preflight[] = [$range['segment'] . ' start race R' . $n . ' schedule time', $exists ? 'PASS' : 'FAIL'];
                    if (!$exists) $errors[] = 'Canonical schedule missing segment start R' . $n;
                }
            }
        }

        foreach ($prepared as $label => $content) {
            $tmp = tempnam(sys_get_temp_dir(), 'mrlv002_');
            file_put_contents($tmp, $content);
            $cmd = 'php -l ' . escapeshellarg($tmp) . ' 2>&1';
            exec($cmd, $out, $rc);
            @unlink($tmp);
            $ok = ($rc === 0);
            $preflight[] = [$label . ' generated PHP syntax', $ok ? 'PASS' : 'FAIL'];
            if (!$ok) $errors[] = $label . ' generated syntax failed: ' . implode(' ', $out);
        }

        if (empty($errors) && $action === 'install') {
            $backupDir = $root . '/mrl_auto_pick_window_patch_backup_' . INSTALLER_TIMESTAMP;
            if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) throw new RuntimeException('Unable to create backup folder: ' . $backupDir);

            foreach (['pick_window_helper.php','config_mrl.php','admin_setup.php'] as $label) {
                if (!copy($paths[$label], $backupDir . '/' . $label)) throw new RuntimeException('Unable to back up ' . $label);
            }
            file_put_contents($backupDir . '/admin_setup_schema_before.sql', dump_admin_setup_schema($db) . ";\n");
            file_put_contents($backupDir . '/admin_setup_row_before.json', dump_admin_setup_row($db) . "\n");

            $added = [];
            try {
                foreach ($newColumns as $col => $sql) {
                    if (!mysqli_query($db, $sql)) throw new RuntimeException('Unable to add ' . $col . ': ' . mysqli_error($db));
                    $added[] = $col;
                }

                foreach ($prepared as $label => $content) atomic_write($paths[$label], $content);

                if (!file_has($paths['pick_window_helper.php'], 'VERSION: v002') || !file_has($paths['config_mrl.php'], 'VERSION: v002') || !file_has($paths['admin_setup.php'], 'VERSION: v002')) {
                    throw new RuntimeException('Post-install version verification failed.');
                }

                foreach (array_keys($newColumns) as $col) {
                    if (!db_column_exists($db, 'admin_setup', $col)) throw new RuntimeException('Post-install DB verification failed for ' . $col);
                }

                $installComplete = true;
            } catch (Throwable $inner) {
                foreach (['pick_window_helper.php','config_mrl.php','admin_setup.php'] as $label) {
                    $bak = $backupDir . '/' . $label;
                    if (is_file($bak)) @copy($bak, $paths[$label]);
                }
                foreach (array_reverse($added) as $col) {
                    @mysqli_query($db, "ALTER TABLE admin_setup DROP COLUMN `" . str_replace('`','',$col) . "`");
                }
                throw $inner;
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>MRL Auto Pick Window Patch v002</title><style>
body{background:#161616;color:#eee;font-family:Arial,sans-serif;margin:0}.wrap{width:92%;max-width:1000px;margin:24px auto}.card{background:#222;border:1px solid #555;border-radius:12px;padding:18px;margin:14px 0}h1,h2{color:#ffd08a}.pass{color:#65e79a}.fail{color:#ff7777}.warn{color:#ffd166}table{width:100%;border-collapse:collapse}td{padding:7px;border-bottom:1px solid #3b3b3b}.btn{font:16px Arial;padding:9px 15px;border-radius:8px;border:1px solid #b28b4d;background:#3a2f1b;color:#ffd08a;cursor:pointer}.small{font-size:13px;color:#aaa;line-height:1.45}.done{color:#65e79a;font-size:20px;font-weight:bold}
</style></head><body><div class="wrap">
<h1>MRL Automatic Pick Window Patch v002</h1>
<div class="card"><div><b>Target:</b> <?php echo h($host ?: '(unknown)'); ?></div><div><b>Root:</b> <?php echo h($root ?: '(unknown)'); ?></div><div><b>Build:</b> 8/19/2026 2:34:00 pm ET</div></div>
<div class="card"><h2>Preflight</h2><table><?php foreach($preflight as [$label,$status]): ?><tr><td><?php echo h((string)$label); ?></td><td class="<?php echo strpos((string)$status, 'PASS') === 0?'pass':'fail'; ?>"><?php echo h((string)$status); ?></td></tr><?php endforeach; ?></table></div>
<?php if($errors): ?><div class="card"><h2 class="fail">STOPPED</h2><?php foreach($errors as $e): ?><div class="fail">• <?php echo h($e); ?></div><?php endforeach; ?><p class="small">No installation changes were intentionally left in place if rollback was possible.</p></div><?php endif; ?>
<?php if(!$errors && !$installComplete): ?><div class="card"><h2>Ready</h2><p>Preflight passed. INSTALL will:</p><ul><li>Back up the three current v001 automatic-pick-window files and admin_setup schema/data.</li><li>Add four lead-time configuration columns to admin_setup.</li><li>Install pick_window_helper.php v002, config_mrl.php v002, and admin_setup.php v002.</li><li>Keep the existing temporary exact-date override intact.</li><li>Leave team.php, form-team-picks.php, submit-team-picks.php, scoring logic, and Live MRL untouched.</li></ul><form method="post"><button class="btn" name="action" value="install">INSTALL AUTO PICK WINDOW PATCH v002</button></form></div><?php endif; ?>
<?php if($installComplete): ?><div class="card"><div class="done">INSTALL COMPLETE.</div><p><b>Backup folder:</b><br><?php echo h($backupDir); ?></p><p>New behavior is active on TestPHP8:</p><ul><li>Global default lead time is editable (starts at 15 days).</li><li>A one-segment lead-time adjustment can be saved without affecting later segments.</li><li>Temporary exact-date override remains available separately.</li><li>Admin timestamps now write in explicit America/New_York time.</li><li>admin_setup.php uses the new compact dashboard layout.</li></ul><p><b>Suggested immediate checks</b></p><ol><li>Open /admin_setup.php and confirm the three compact top panels.</li><li>Change the default lead time (for example 15 → 12), save, and confirm AUTO open date moves accordingly.</li><li>Add a one-segment adjustment (for example S4 → 10 days), confirm only S4 changes, then clear it.</li><li>Confirm Last Updated matches New York local time.</li><li>Verify /team.php still behaves normally.</li></ol></div><?php endif; ?>
</div></body></html>
