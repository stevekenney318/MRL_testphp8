<?php
declare(strict_types=1);

/**
 * mrl_rd_phase2_simulation_installer.php
 *
 * VERSION: v001
 * GENERATED: 8/20/2026 10:27:00 pm America/New_York
 *
 * TESTPHP8 ONLY
 *
 * INSTALLS:
 * - race_results/race_results_rd_helper.php v005
 * - race_results/admin_rd_simulation.php v001
 *
 * DOES NOT CHANGE:
 * - database schema or data
 * - normal race snapshots
 * - team.php
 * - submit-team-picks.php
 * - Live MRL
 *
 * USER SAFETY CHECKPOINT:
 * - Full website + DB backup: 20260821013354
 *
 * PHP 7.3 compatible.
 */

date_default_timezone_set('America/New_York');

const MRL_RD_INSTALLER_VERSION = 'v001';
const MRL_RD_REQUIRED_HOST = 'testphp8.manliusracingleague.com';

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$raceDir = $root . '/race_results';
$helperPath = $raceDir . '/race_results_rd_helper.php';
$simPath = $raceDir . '/admin_rd_simulation.php';
$backupDir = $root . '/mrl_rd_phase2_backup_20260820_102700pm';

$checks = [];
$errors = [];
$postflight = [];
$installed = false;

function ri_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ri_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

function ri_atomic_write(string $path, string $content): bool
{
    $tmp = $path . '.mrl_tmp_' . str_replace('.', '', uniqid('', true));

    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function ri_backup_file(string $source, string $root, string $backupDir): bool
{
    if (!is_file($source)) {
        return true;
    }

    $relative = ltrim(str_replace($root, '', $source), '/\\');
    $dest = $backupDir . '/' . $relative;
    $destDir = dirname($dest);

    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        return false;
    }

    return @copy($source, $dest);
}

$helperContent = <<<'HELPER'
<?php
declare(strict_types=1);

/**
 * race_results_rd_helper.php
 *
 * VERSION: v005
 * LAST MODIFIED: 8/20/2026 10:27:00 pm
 *
 * DESCRIPTION:
 * Shared Replacement Driver (RD) eligibility helper.
 *
 * RULES LOCKED FOR THIS PHASE:
 * - Eligibility is based on the TWO MOST RECENT COMPLETED CONSECUTIVE races
 *   in the segment.
 * - 0,0 => RD available for the next race in the same segment.
 * - 0,0,0 with no submitted RD => eligibility rolls to the next race.
 * - 0,0,positive => eligibility disappears automatically.
 * - A genuine LP becomes the team's active pick after its effective_race and
 *   those active LP drivers are then eligible for normal RD evaluation.
 * - Multiple qualifying drivers are a USER choice, not an admin/manual-review
 *   condition.
 * - Only one RD is allowed per team per season.
 * - Effective race must be the next canonical MRL points race in the same
 *   DB-defined segment.
 *
 * SCOPE:
 * - Detection/read helpers only.
 * - No pick writes.
 * - No DB writes.
 * - PHP 7.3 compatible.
 *
 * CHANGELOG:
 * v005 (8/20/2026 10:27:00 pm)
 * - FIX: user_picks_history RD check uses pickID, not non-existent historyID.
 * - CHANGE: Old earlier 0/0 pairs do not keep eligibility alive after a later
 *   positive result; only the trailing completed pair matters.
 * - NEW: Active-pick resolver supports SEG, ADJ, and effective LP rows.
 * - NEW: MULTIPLE_RD_AVAILABLE + user_selection_required for user-side choice.
 * - NEW: Candidate-team loader for RD evaluation without changing normal
 *   scoring/team base-pick loaders.
 *
 * v004 (8/18/2026 4:13:00 am)
 * - CHANGE: RD next-effective-race lookup now uses race_schedule_helper.php.
 * - CHANGE: RD now shares /race_results/_race_results_schedule.json with LP.
 * - CHANGE: Next RD race must be a canonical MRL points race inside the DB-defined segment.
 * - CHANGE: Preserved existing RD eligibility detection and one-RD-per-season behavior.
 */

const MRL_RD_TIMEZONE = 'America/New_York';

require_once __DIR__ . '/race_schedule_helper.php';

if (!function_exists('mrl_rd_try_get_segment_bounds')) {
    function mrl_rd_try_get_segment_bounds(PDO $dbo, int $raceYear, string $segment): ?array
    {
        $stmt = $dbo->prepare("
            SELECT startRace, endRace
            FROM segment_race_ranges
            WHERE raceYear = :raceYear
              AND segment = :segment
            LIMIT 1
        ");
        $stmt->execute([
            ':raceYear' => $raceYear,
            ':segment' => $segment,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $startRace = (int)($row['startRace'] ?? 0);
        $endRace = (int)($row['endRace'] ?? 0);

        if ($startRace <= 0 || $endRace <= 0 || $endRace < $startRace) {
            return null;
        }

        return [
            'start' => $startRace,
            'end' => $endRace,
        ];
    }
}

if (!function_exists('mrl_rd_get_segment_bounds')) {
    function mrl_rd_get_segment_bounds(PDO $dbo, int $raceYear, string $segment): array
    {
        $bounds = mrl_rd_try_get_segment_bounds($dbo, $raceYear, $segment);
        if ($bounds === null) {
            throw new RuntimeException('No segment_race_ranges row found for ' . $raceYear . ' ' . $segment . '.');
        }
        return $bounds;
    }
}

if (!function_exists('mrl_rd_segment_race_numbers')) {
    function mrl_rd_segment_race_numbers(PDO $dbo, int $raceYear, string $segment): array
    {
        $bounds = mrl_rd_get_segment_bounds($dbo, $raceYear, $segment);
        $races = [];

        for ($i = (int)$bounds['start']; $i <= (int)$bounds['end']; $i++) {
            $races[] = $i;
        }

        return $races;
    }
}

if (!function_exists('mrl_rd_next_race_in_segment')) {
    function mrl_rd_next_race_in_segment(PDO $dbo, int $raceYear, string $segment, int $raceNumber): ?int
    {
        try {
            $nextRace = mrl_schedule_helper_next_race_in_segment(
                $raceYear,
                $segment,
                $raceNumber
            );

            if (!is_array($nextRace)) {
                return null;
            }

            $nextRaceNumber = mrl_schedule_helper_race_number($nextRace);
            return ($nextRaceNumber > 0) ? $nextRaceNumber : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('mrl_rd_team_used_rd_this_year')) {
    function mrl_rd_team_used_rd_this_year(PDO $dbo, string $raceYear, string $teamName): bool
    {
        $stmt = $dbo->prepare("
            SELECT pickID
            FROM user_picks
            WHERE raceYear = :raceYear
              AND teamName = :teamName
              AND pick_type = 'RD'
            LIMIT 1
        ");
        $stmt->execute([
            ':raceYear' => $raceYear,
            ':teamName' => $teamName,
        ]);

        if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
            return true;
        }

        $stmtHistory = $dbo->prepare("
            SELECT pickID
            FROM user_picks_history
            WHERE raceYear = :raceYear
              AND teamName = :teamName
              AND pick_type = 'RD'
            LIMIT 1
        ");
        $stmtHistory->execute([
            ':raceYear' => $raceYear,
            ':teamName' => $teamName,
        ]);

        return ($stmtHistory->fetch(PDO::FETCH_ASSOC) !== false);
    }
}

if (!function_exists('mrl_rd_pick_rows_for_team_segment')) {
    function mrl_rd_pick_rows_for_team_segment(
        PDO $dbo,
        string $raceYear,
        string $segment,
        string $teamName
    ): array {
        $stmt = $dbo->prepare("
            SELECT
                pickID,
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
                formID,
                pick_type,
                effective_race,
                supersedes_pickID
            FROM user_picks
            WHERE raceYear = :raceYear
              AND segment = :segment
              AND teamName = :teamName
              AND pick_type IN ('SEG','ADJ','LP','RD')
            ORDER BY effective_race ASC, entryDate ASC, pickID ASC
        ");
        $stmt->execute([
            ':raceYear' => $raceYear,
            ':segment' => $segment,
            ':teamName' => $teamName,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('mrl_rd_completed_race_numbers')) {
    function mrl_rd_completed_race_numbers(
        PDO $dbo,
        int $raceYear,
        string $segment,
        array $raceDriverPoints
    ): array {
        $bounds = mrl_rd_try_get_segment_bounds($dbo, $raceYear, $segment);
        if ($bounds === null) {
            return [];
        }

        $races = [];
        foreach ($raceDriverPoints as $raceNumber => $driverRows) {
            $rn = (int)$raceNumber;

            if ($rn < (int)$bounds['start'] || $rn > (int)$bounds['end']) {
                continue;
            }

            if (!is_array($driverRows) || empty($driverRows)) {
                continue;
            }

            $races[] = $rn;
        }

        $races = array_values(array_unique($races));
        sort($races, SORT_NUMERIC);
        return $races;
    }
}

if (!function_exists('mrl_rd_latest_completed_race')) {
    function mrl_rd_latest_completed_race(
        PDO $dbo,
        int $raceYear,
        string $segment,
        array $raceDriverPoints
    ): int {
        $races = mrl_rd_completed_race_numbers($dbo, $raceYear, $segment, $raceDriverPoints);
        return empty($races) ? 0 : (int)$races[count($races) - 1];
    }
}

if (!function_exists('mrl_rd_active_pick_row')) {
    function mrl_rd_active_pick_row(
        PDO $dbo,
        string $raceYear,
        string $segment,
        string $teamName,
        array $raceDriverPoints
    ): ?array {
        $rows = mrl_rd_pick_rows_for_team_segment($dbo, $raceYear, $segment, $teamName);
        if (empty($rows)) {
            return null;
        }

        $latestCompleted = mrl_rd_latest_completed_race(
            $dbo,
            (int)$raceYear,
            $segment,
            $raceDriverPoints
        );

        $bounds = mrl_rd_try_get_segment_bounds($dbo, (int)$raceYear, $segment);
        if ($bounds === null) {
            return null;
        }

        $active = null;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $pickType = strtoupper(trim((string)($row['pick_type'] ?? '')));

            // A submitted RD is handled separately by the one-RD-per-season guard.
            if ($pickType === 'RD') {
                continue;
            }

            if ($pickType !== 'SEG' && $pickType !== 'ADJ' && $pickType !== 'LP') {
                continue;
            }

            $effectiveRace = (int)($row['effective_race'] ?? 0);

            if (($pickType === 'SEG' || $pickType === 'ADJ') && $effectiveRace <= 0) {
                $effectiveRace = (int)$bounds['start'];
            }

            // A genuine LP does not become an RD baseline until the LP itself
            // has taken effect in a completed race.
            if ($pickType === 'LP') {
                if ($latestCompleted <= 0 || $effectiveRace <= 0 || $effectiveRace > $latestCompleted) {
                    continue;
                }
            }

            if ($latestCompleted > 0 && $effectiveRace > $latestCompleted) {
                continue;
            }

            if ($active === null) {
                $active = $row;
                $active['_resolved_effective_race'] = $effectiveRace;
                continue;
            }

            $activeEffective = (int)($active['_resolved_effective_race'] ?? 0);
            $activePickID = (int)($active['pickID'] ?? 0);
            $rowPickID = (int)($row['pickID'] ?? 0);

            if (
                $effectiveRace > $activeEffective ||
                ($effectiveRace === $activeEffective && $rowPickID > $activePickID)
            ) {
                $active = $row;
                $active['_resolved_effective_race'] = $effectiveRace;
            }
        }

        return is_array($active) ? $active : null;
    }
}

if (!function_exists('mrl_rd_team_segment_pick_row')) {
    // Backward-compatible helper name retained for existing callers.
    function mrl_rd_team_segment_pick_row(PDO $dbo, string $raceYear, string $segment, string $teamName): ?array
    {
        $rows = mrl_rd_pick_rows_for_team_segment($dbo, $raceYear, $segment, $teamName);
        return (!empty($rows) && is_array($rows[0])) ? $rows[0] : null;
    }
}

if (!function_exists('mrl_rd_candidate_team_rows')) {
    function mrl_rd_candidate_team_rows(
        PDO $dbo,
        string $raceYear,
        string $segment,
        array $raceDriverPoints
    ): array {
        $stmt = $dbo->prepare("
            SELECT DISTINCT userID, teamName
            FROM user_picks
            WHERE raceYear = :raceYear
              AND segment = :segment
              AND pick_type IN ('SEG','ADJ','LP')
              AND COALESCE(teamName,'') <> ''
            ORDER BY teamName ASC, userID ASC
        ");
        $stmt->execute([
            ':raceYear' => $raceYear,
            ':segment' => $segment,
        ]);

        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($raw)) {
            return [];
        }

        $seen = [];
        $out = [];

        foreach ($raw as $team) {
            $teamName = trim((string)($team['teamName'] ?? ''));
            if ($teamName === '' || isset($seen[$teamName])) {
                continue;
            }
            $seen[$teamName] = true;

            $active = mrl_rd_active_pick_row(
                $dbo,
                $raceYear,
                $segment,
                $teamName,
                $raceDriverPoints
            );

            if (is_array($active)) {
                $out[] = $active;
            }
        }

        return $out;
    }
}

if (!function_exists('mrl_rd_driver_points_for_segment')) {
    function mrl_rd_driver_points_for_segment(
        PDO $dbo,
        int $raceYear,
        string $segment,
        string $driverName,
        array $raceDriverPoints
    ): array {
        $results = [];
        $driverName = trim($driverName);

        foreach (mrl_rd_completed_race_numbers($dbo, $raceYear, $segment, $raceDriverPoints) as $raceNumber) {
            $net = 0;

            if (
                $driverName !== '' &&
                isset($raceDriverPoints[$raceNumber]) &&
                is_array($raceDriverPoints[$raceNumber]) &&
                array_key_exists($driverName, $raceDriverPoints[$raceNumber])
            ) {
                $net = (int)$raceDriverPoints[$raceNumber][$driverName];
            }

            $results[] = [
                'race_number' => $raceNumber,
                'driver' => $driverName,
                'net' => $net,
            ];
        }

        return $results;
    }
}

if (!function_exists('mrl_rd_detect_slot_current_eligibility')) {
    function mrl_rd_detect_slot_current_eligibility(
        PDO $dbo,
        int $raceYear,
        string $slot,
        string $segment,
        string $driverName,
        array $raceDriverPoints
    ): array {
        $driverName = trim($driverName);
        $completed = mrl_rd_completed_race_numbers(
            $dbo,
            $raceYear,
            $segment,
            $raceDriverPoints
        );

        $result = [
            'qualified' => false,
            'slot' => $slot,
            'driver' => $driverName,
            'zero_races' => [],
            'checked_races' => [],
            'checked_points' => [],
            'effective_race' => null,
            'reason' => '',
        ];

        if ($driverName === '') {
            $result['reason'] = 'NO_DRIVER';
            return $result;
        }

        if (count($completed) < 2) {
            $result['reason'] = 'FEWER_THAN_TWO_COMPLETED_RACES';
            return $result;
        }

        $lastTwo = array_slice($completed, -2);
        $r1 = (int)$lastTwo[0];
        $r2 = (int)$lastTwo[1];

        $result['checked_races'] = [$r1, $r2];

        if ($r2 !== ($r1 + 1)) {
            $result['reason'] = 'LAST_TWO_COMPLETED_RACES_NOT_CONSECUTIVE';
            return $result;
        }

        // Missing from a completed result set is treated as a 0-point race,
        // matching existing MRL scoring/RD semantics for a selected driver
        // who did not produce a scoring row.
        $p1 = 0;
        $p2 = 0;

        if (
            isset($raceDriverPoints[$r1]) &&
            is_array($raceDriverPoints[$r1]) &&
            array_key_exists($driverName, $raceDriverPoints[$r1])
        ) {
            $p1 = (int)$raceDriverPoints[$r1][$driverName];
        }

        if (
            isset($raceDriverPoints[$r2]) &&
            is_array($raceDriverPoints[$r2]) &&
            array_key_exists($driverName, $raceDriverPoints[$r2])
        ) {
            $p2 = (int)$raceDriverPoints[$r2][$driverName];
        }

        $result['checked_points'] = [$p1, $p2];

        if ($p1 !== 0 || $p2 !== 0) {
            $result['reason'] = 'TRAILING_PAIR_NOT_BOTH_ZERO';
            return $result;
        }

        $result['zero_races'] = [$r1, $r2];

        $effectiveRace = mrl_rd_next_race_in_segment(
            $dbo,
            $raceYear,
            $segment,
            $r2
        );

        if ($effectiveRace === null) {
            $result['reason'] = 'NO_FUTURE_RACE_IN_SEGMENT';
            return $result;
        }

        $result['qualified'] = true;
        $result['effective_race'] = $effectiveRace;
        $result['reason'] = 'TRAILING_TWO_ZERO';

        return $result;
    }
}

if (!function_exists('mrl_rd_detect_slot_eligibility')) {
    // Backward-compatible name, now using trailing-pair semantics.
    function mrl_rd_detect_slot_eligibility(
        PDO $dbo,
        int $raceYear,
        string $slot,
        string $segment,
        string $driverName,
        array $raceDriverPoints
    ): array {
        return mrl_rd_detect_slot_current_eligibility(
            $dbo,
            $raceYear,
            $slot,
            $segment,
            $driverName,
            $raceDriverPoints
        );
    }
}

if (!function_exists('mrl_rd_detect_team_segment_eligibility')) {
    function mrl_rd_detect_team_segment_eligibility(
        PDO $dbo,
        string $raceYear,
        string $segment,
        string $teamName,
        array $raceDriverPoints
    ): array {
        $raceYearInt = (int)$raceYear;

        $result = [
            'teamName' => $teamName,
            'raceYear' => $raceYear,
            'segment' => $segment,
            'has_base_row' => false,
            'rd_already_used_this_year' => false,
            'qualifiers' => [],
            'qualifier_count' => 0,
            'status' => 'NO_RD',
            'auto_select_allowed' => false,
            'user_selection_required' => false,
            'completed_races' => [],
            'trailing_races' => [],
        ];

        $bounds = mrl_rd_try_get_segment_bounds($dbo, $raceYearInt, $segment);
        if ($bounds === null) {
            $result['status'] = 'NO_SEGMENT_CONFIG';
            return $result;
        }

        $completed = mrl_rd_completed_race_numbers(
            $dbo,
            $raceYearInt,
            $segment,
            $raceDriverPoints
        );

        $result['completed_races'] = $completed;
        $result['trailing_races'] = count($completed) >= 2
            ? array_slice($completed, -2)
            : $completed;

        $teamRow = mrl_rd_active_pick_row(
            $dbo,
            $raceYear,
            $segment,
            $teamName,
            $raceDriverPoints
        );

        if (!is_array($teamRow)) {
            $result['status'] = 'NO_ACTIVE_PICK_ROW';
            return $result;
        }

        $result['has_base_row'] = true;
        $result['base_pick_row'] = $teamRow;
        $result['active_pick_type'] = strtoupper((string)($teamRow['pick_type'] ?? ''));
        $result['active_effective_race'] = (int)($teamRow['_resolved_effective_race'] ?? $teamRow['effective_race'] ?? 0);

        $rdAlreadyUsed = mrl_rd_team_used_rd_this_year($dbo, $raceYear, $teamName);
        $result['rd_already_used_this_year'] = $rdAlreadyUsed;

        if ($rdAlreadyUsed) {
            $result['status'] = 'RD_ALREADY_USED';
            return $result;
        }

        if (count($completed) < 2) {
            $result['status'] = 'NO_COMPLETED_PAIR';
            return $result;
        }

        $slotResults = [];

        foreach (['A', 'B', 'C', 'D'] as $slot) {
            $field = 'driver' . $slot;
            $slotResults[] = mrl_rd_detect_slot_current_eligibility(
                $dbo,
                $raceYearInt,
                $slot,
                $segment,
                (string)($teamRow[$field] ?? ''),
                $raceDriverPoints
            );
        }

        $qualifiers = [];
        foreach ($slotResults as $slotResult) {
            if (!empty($slotResult['qualified'])) {
                $qualifiers[] = $slotResult;
            }
        }

        $result['slot_results'] = $slotResults;
        $result['qualifiers'] = $qualifiers;
        $result['qualifier_count'] = count($qualifiers);

        if (count($qualifiers) === 1) {
            $result['status'] = 'RD_AVAILABLE';
            $result['auto_select_allowed'] = true;
        } elseif (count($qualifiers) > 1) {
            $result['status'] = 'MULTIPLE_RD_AVAILABLE';
            $result['user_selection_required'] = true;
        } else {
            $result['status'] = 'NO_RD';
        }

        return $result;
    }
}

HELPER;

$simContent = <<<'SIM'
<?php
declare(strict_types=1);

/**
 * admin_rd_simulation.php
 *
 * VERSION: v001
 * LAST MODIFIED: 8/20/2026 10:27:00 pm
 *
 * PURPOSE:
 * TESTPHP8-only RD diagnostic/simulation harness.
 *
 * This page:
 * - Reads the same saved snapshot choice currently used by race_results_monitor
 *   (lexicographically latest snapshot_*.html in each completed race folder).
 * - Copies parsed point data into isolated _rd_simulation fixtures.
 * - Re-parses those fixtures with rrs_load_snapshot_driver_points().
 * - Allows ONE driver to be overridden across selected completed races.
 * - Runs the shared RD v005 eligibility engine.
 *
 * It NEVER:
 * - changes normal race snapshots;
 * - changes user_picks or user_picks_history;
 * - changes standings;
 * - submits an RD;
 * - writes to the DB.
 *
 * PHP: 7.3 compatible.
 */

session_start();
date_default_timezone_set('America/New_York');

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if ($host !== 'testphp8.manliusracingleague.com') {
    http_response_code(403);
    exit('REFUSED: RD Simulation is TESTPHP8-only.');
}

require_once dirname(__DIR__) . '/class.user.php';
$user_home = new USER();

if (!$user_home->is_logged_in()) {
    $user_home->redirect('../login.php');
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/config_mrl.php';
require_once __DIR__ . '/race_results_snapshot_helper.php';
require_once __DIR__ . '/race_results_rd_helper.php';

$uid = (int)($_SESSION['userSession'] ?? 0);
if (!function_exists('isAdmin') || !isAdmin($uid)) {
    http_response_code(403);
    exit('REFUSED: Admin access required.');
}

function rds_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rds_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function rds_point_races(array $yearIndex, string $yearFolder): array
{
    $out = [];
    $races = isset($yearIndex['races']) && is_array($yearIndex['races'])
        ? $yearIndex['races']
        : [];

    foreach ($races as $raceId => $row) {
        if (!is_array($row) || (string)($row['kind'] ?? '') !== 'R') {
            continue;
        }

        $number = (int)($row['number'] ?? 0);
        $folder = trim((string)($row['folder'] ?? ''));

        if ($number <= 0 || $folder === '') {
            continue;
        }

        $out[$number] = [
            'number' => $number,
            'raceCode' => 'R' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'folder' => $folder,
            'raceFolder' => $yearFolder . '/' . $folder,
            'raceName' => (string)($row['race_name'] ?? ''),
            'raceId' => (string)$raceId,
        ];
    }

    ksort($out, SORT_NUMERIC);
    return $out;
}

function rds_monitor_snapshot_choice(string $raceFolder): string
{
    if (!is_dir($raceFolder)) {
        return '';
    }

    // Deliberately mirrors race_results_monitor.php:
    // glob snapshot_*.html, sort, choose the last file.
    $files = glob(rtrim($raceFolder, '/\\') . '/snapshot_*.html');

    if (!is_array($files) || empty($files)) {
        return '';
    }

    sort($files, SORT_STRING);
    return (string)end($files);
}

function rds_build_source_points(array $races, int $startRace, int $throughRace): array
{
    $out = [];

    foreach ($races as $rn => $race) {
        $rn = (int)$rn;

        if ($rn < $startRace || $rn > $throughRace) {
            continue;
        }

        $snapshot = rds_monitor_snapshot_choice((string)$race['raceFolder']);
        if ($snapshot === '') {
            continue;
        }

        $drivers = rrs_load_snapshot_driver_points($snapshot);
        if (!is_array($drivers) || empty($drivers)) {
            continue;
        }

        $out[$rn] = [
            'snapshot' => $snapshot,
            'drivers' => $drivers,
        ];
    }

    ksort($out, SORT_NUMERIC);
    return $out;
}

function rds_fixture_html(int $raceNumber, array $driverRows): string
{
    $rows = '';
    $pos = 1;

    foreach ($driverRows as $driverName => $data) {
        if (!is_array($data)) {
            continue;
        }

        $pts = (int)($data['pts'] ?? 0);
        $bonus = (int)($data['bonus'] ?? 0);
        $penalty = (int)($data['penalty'] ?? 0);

        $rows .= '<tr>'
            . '<td>' . $pos . '</td>'
            . '<td>' . rds_h($driverName) . '</td>'
            . '<td>0</td><td>0</td><td>0</td><td>0</td>'
            . '<td>' . $pts . '</td>'
            . '<td>' . $bonus . '</td>'
            . '<td>' . $penalty . '</td>'
            . '</tr>' . "\n";

        $pos++;
    }

    return '<!doctype html><html><head><meta charset="utf-8">'
        . '<title>RD Simulation R' . str_pad((string)$raceNumber, 2, '0', STR_PAD_LEFT) . '</title>'
        . '</head><body><table>'
        . '<tr><th>POS</th><th>DRIVER</th><th>START</th><th>LAPS</th><th>STATUS</th><th>LEAD</th><th>PTS</th><th>BONUS</th><th>PENALTY</th></tr>'
        . $rows
        . '</table></body></html>';
}

function rds_clear_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        if (is_dir($path)) {
            rds_clear_dir($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

function rds_make_fixture_set(
    string $fixtureDir,
    array $sourcePoints,
    string $overrideDriver,
    array $overrideRaces,
    int $overrideNet
): array {
    if (!is_dir($fixtureDir) && !@mkdir($fixtureDir, 0775, true) && !is_dir($fixtureDir)) {
        throw new RuntimeException('Could not create fixture directory: ' . $fixtureDir);
    }

    rds_clear_dir($fixtureDir);

    $points = [];
    $meta = [];

    foreach ($sourcePoints as $rn => $raceData) {
        $rn = (int)$rn;
        $driverRows = isset($raceData['drivers']) && is_array($raceData['drivers'])
            ? $raceData['drivers']
            : [];

        $overrideApplied = false;

        if (
            $overrideDriver !== '' &&
            isset($overrideRaces[$rn]) &&
            isset($driverRows[$overrideDriver]) &&
            is_array($driverRows[$overrideDriver])
        ) {
            // NET = PTS - PENALTY. Use a clean simulated row.
            $driverRows[$overrideDriver]['pts'] = $overrideNet;
            $driverRows[$overrideDriver]['bonus'] = 0;
            $driverRows[$overrideDriver]['penalty'] = 0;
            $driverRows[$overrideDriver]['net'] = $overrideNet;
            $overrideApplied = true;
        }

        $fixturePath = $fixtureDir
            . '/R'
            . str_pad((string)$rn, 2, '0', STR_PAD_LEFT)
            . '_rd_fixture.html';

        if (@file_put_contents($fixturePath, rds_fixture_html($rn, $driverRows), LOCK_EX) === false) {
            throw new RuntimeException('Could not write fixture: ' . $fixturePath);
        }

        $reparsed = rrs_load_snapshot_driver_points($fixturePath);
        if (!is_array($reparsed) || empty($reparsed)) {
            throw new RuntimeException('Fixture re-parse failed for R' . str_pad((string)$rn, 2, '0', STR_PAD_LEFT) . '.');
        }

        $points[$rn] = [];
        foreach ($reparsed as $driverName => $data) {
            $points[$rn][$driverName] = (int)($data['net'] ?? 0);
        }

        $meta[$rn] = [
            'source' => (string)($raceData['snapshot'] ?? ''),
            'fixture' => $fixturePath,
            'override_applied' => $overrideApplied,
        ];
    }

    ksort($points, SORT_NUMERIC);

    return [
        'points' => $points,
        'meta' => $meta,
    ];
}

function rds_status_class(string $status): string
{
    if ($status === 'RD_AVAILABLE' || $status === 'MULTIPLE_RD_AVAILABLE') {
        return 'good';
    }

    if ($status === 'RD_ALREADY_USED') {
        return 'used';
    }

    return 'quiet';
}

$selectedYear = isset($_GET['year']) && preg_match('/^\d{4}$/', (string)$_GET['year'])
    ? (string)$_GET['year']
    : (string)($raceYear ?? date('Y'));

$selectedSegment = isset($_GET['segment']) && preg_match('/^S[1-9]\d*$/', (string)$_GET['segment'])
    ? (string)$_GET['segment']
    : 'S1';

$yearFolder = __DIR__ . '/' . $selectedYear;
$yearIndex = rds_json($yearFolder . '/_year_index.json');
$pointRaces = rds_point_races($yearIndex, $yearFolder);
$bounds = mrl_rd_try_get_segment_bounds($dbo, (int)$selectedYear, $selectedSegment);

$error = '';
$message = '';
$throughRace = 0;
$sourcePoints = [];
$fixtureResult = ['points' => [], 'meta' => []];

$overrideDriver = trim((string)($_POST['override_driver'] ?? ''));
$overrideNet = isset($_POST['override_net']) && preg_match('/^-?\d+$/', (string)$_POST['override_net'])
    ? (int)$_POST['override_net']
    : 0;

$overrideRaceMap = [];
if (isset($_POST['override_races']) && is_array($_POST['override_races'])) {
    foreach ($_POST['override_races'] as $rn) {
        $n = (int)$rn;
        if ($n > 0) {
            $overrideRaceMap[$n] = true;
        }
    }
}

if ($bounds === null) {
    $error = 'No segment_race_ranges row found for ' . $selectedYear . ' ' . $selectedSegment . '.';
} else {
    $availableRaces = [];

    foreach ($pointRaces as $rn => $race) {
        $rn = (int)$rn;

        if ($rn < (int)$bounds['start'] || $rn > (int)$bounds['end']) {
            continue;
        }

        if (rds_monitor_snapshot_choice((string)$race['raceFolder']) !== '') {
            $availableRaces[] = $rn;
        }
    }

    $requestedThrough = (int)($_GET['through'] ?? $_POST['through_race'] ?? 0);

    if ($requestedThrough > 0 && in_array($requestedThrough, $availableRaces, true)) {
        $throughRace = $requestedThrough;
    } elseif (!empty($availableRaces)) {
        $throughRace = (int)$availableRaces[count($availableRaces) - 1];
    }

    if ($throughRace > 0) {
        $sourcePoints = rds_build_source_points(
            $pointRaces,
            (int)$bounds['start'],
            $throughRace
        );
    }
}

$sourceMap = [];
foreach ($sourcePoints as $rn => $row) {
    $sourceMap[$rn] = [];

    foreach (($row['drivers'] ?? []) as $driverName => $data) {
        $sourceMap[$rn][$driverName] = (int)($data['net'] ?? 0);
    }
}

$driverNames = [];
if (!empty($sourceMap)) {
    foreach (mrl_rd_candidate_team_rows($dbo, $selectedYear, $selectedSegment, $sourceMap) as $row) {
        foreach (['driverA', 'driverB', 'driverC', 'driverD'] as $field) {
            $name = trim((string)($row[$field] ?? ''));
            if ($name !== '') {
                $driverNames[$name] = true;
            }
        }
    }
}

$driverNames = array_keys($driverNames);
natcasesort($driverNames);
$driverNames = array_values($driverNames);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ((string)$_POST['action'] === 'reset') {
            rds_clear_dir(__DIR__ . '/_rd_simulation');
            $message = 'Simulation fixtures cleared. DB and normal snapshots were untouched.';
        }

        if ((string)$_POST['action'] === 'run') {
            if ($bounds === null || $throughRace <= 0 || empty($sourcePoints)) {
                throw new RuntimeException('No completed snapshot state is available for this selection.');
            }

            if ($overrideDriver !== '' && !in_array($overrideDriver, $driverNames, true)) {
                throw new RuntimeException('Override driver is not an active driver for the selected team set.');
            }

            foreach (array_keys($overrideRaceMap) as $rn) {
                if ($rn < (int)$bounds['start'] || $rn > $throughRace) {
                    throw new RuntimeException('An override race is outside the loaded completed-race range.');
                }
            }

            $fixtureDir = __DIR__
                . '/_rd_simulation/current/'
                . $selectedYear
                . '/'
                . $selectedSegment;

            $fixtureResult = rds_make_fixture_set(
                $fixtureDir,
                $sourcePoints,
                $overrideDriver,
                $overrideRaceMap,
                $overrideNet
            );

            $message = 'Simulation fixtures generated and re-parsed. No DB writes; normal snapshots untouched.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$evaluationPoints = !empty($fixtureResult['points'])
    ? $fixtureResult['points']
    : $sourceMap;

$results = [];

if (!empty($evaluationPoints)) {
    $candidateRows = mrl_rd_candidate_team_rows(
        $dbo,
        $selectedYear,
        $selectedSegment,
        $evaluationPoints
    );

    foreach ($candidateRows as $row) {
        $teamName = trim((string)($row['teamName'] ?? ''));
        if ($teamName === '') {
            continue;
        }

        $results[] = mrl_rd_detect_team_segment_eligibility(
            $dbo,
            $selectedYear,
            $selectedSegment,
            $teamName,
            $evaluationPoints
        );
    }
}

$completed = !empty($evaluationPoints) && $bounds !== null
    ? mrl_rd_completed_race_numbers($dbo, (int)$selectedYear, $selectedSegment, $evaluationPoints)
    : [];

$trailing = count($completed) >= 2 ? array_slice($completed, -2) : $completed;

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL RD Simulation</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.35 Arial,Helvetica,sans-serif}
.wrap{max-width:1500px;margin:0 auto;padding:14px}
.banner{background:#3d3212;border:1px solid #9b7200;border-radius:10px;padding:11px 14px;font-size:20px;font-weight:700}
.sub{font-size:12px;color:#dbc783;margin-top:4px}
.card{background:#1b1b1b;border:1px solid #3e3e3e;border-radius:9px;margin-top:12px;padding:12px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:9px}
label{display:block;font-size:12px;color:#bbb;margin-bottom:4px}
select,input{width:100%;background:#0d0d0d;color:#eee;border:1px solid #555;border-radius:5px;padding:7px}
button{background:#2b669f;color:white;border:0;border-radius:6px;padding:8px 12px;font-weight:700;cursor:pointer}
button.secondary{background:#555}
.msg{background:#173b20;border:1px solid #2b7740;padding:9px;margin-top:10px}
.err{background:#4a1717;border:1px solid #a33;padding:9px;margin-top:10px}
table{width:100%;border-collapse:collapse;margin-top:9px}
th,td{border:1px solid #393939;padding:6px 7px;text-align:left;vertical-align:top}
th{background:#262626}
tr:nth-child(even) td{background:#171717}
.good{color:#67e89b;font-weight:700}
.used{color:#e6be74;font-weight:700}
.quiet{color:#aaa}
.small{font-size:12px;color:#aaa}
.races{display:flex;flex-wrap:wrap;gap:7px;margin-top:6px}
.racecheck{display:inline-flex;align-items:center;gap:5px;background:#222;border:1px solid #444;border-radius:5px;padding:5px 7px}
.racecheck input{width:auto}
.nowrap{white-space:nowrap}
</style>
</head>
<body>
<div class="wrap">
    <div class="banner">
        TESTPHP8 / RD SIMULATION
        <div class="sub">Read-only DB diagnostics + isolated snapshot fixtures. Nothing here submits or changes an RD.</div>
    </div>

    <div class="card">
        <form method="get">
            <div class="grid">
                <div>
                    <label>Year</label>
                    <input name="year" value="<?=rds_h($selectedYear)?>">
                </div>
                <div>
                    <label>Segment</label>
                    <select name="segment">
                        <?php foreach (['S1','S2','S3','S4'] as $seg): ?>
                            <option value="<?=rds_h($seg)?>" <?=$selectedSegment===$seg?'selected':''?>><?=rds_h($seg)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Through completed race</label>
                    <select name="through">
                        <?php if ($bounds !== null): ?>
                            <?php foreach ($pointRaces as $rn => $race): ?>
                                <?php
                                $rn = (int)$rn;
                                if ($rn < (int)$bounds['start'] || $rn > (int)$bounds['end']) continue;
                                if (rds_monitor_snapshot_choice((string)$race['raceFolder']) === '') continue;
                                ?>
                                <option value="<?=$rn?>" <?=$throughRace===$rn?'selected':''?>>
                                    R<?=str_pad((string)$rn,2,'0',STR_PAD_LEFT)?> <?=rds_h($race['raceName'])?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div style="align-self:end">
                    <button type="submit">Load Snapshot State</button>
                </div>
            </div>
        </form>

        <div class="small" style="margin-top:8px">
            First target: 2026 / S1 / through R05. With no override, the saved R04/R05 data should show the real Alex Bowman case if the snapshots contain those zeroes.
        </div>
    </div>

    <div class="card">
        <form method="post">
            <input type="hidden" name="through_race" value="<?=$throughRace?>">

            <div class="grid">
                <div>
                    <label>Optional driver override</label>
                    <select name="override_driver">
                        <option value="">No override — use saved snapshot values</option>
                        <?php foreach ($driverNames as $driver): ?>
                            <option value="<?=rds_h($driver)?>" <?=$overrideDriver===$driver?'selected':''?>><?=rds_h($driver)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Simulated NET points</label>
                    <input name="override_net" value="<?=rds_h((string)$overrideNet)?>">
                </div>
            </div>

            <label style="margin-top:9px">Apply override to completed race(s)</label>
            <div class="races">
                <?php foreach ($sourcePoints as $rn => $row): $rn=(int)$rn; ?>
                    <label class="racecheck">
                        <input type="checkbox" name="override_races[]" value="<?=$rn?>" <?=isset($overrideRaceMap[$rn])?'checked':''?>>
                        R<?=str_pad((string)$rn,2,'0',STR_PAD_LEFT)?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;gap:8px;margin-top:10px">
                <button type="submit" name="action" value="run">Generate / Run Simulation</button>
                <button type="submit" name="action" value="reset" class="secondary">Clear Simulation Fixtures</button>
            </div>
        </form>

        <?php if ($message !== ''): ?><div class="msg"><?=rds_h($message)?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="err"><?=rds_h($error)?></div><?php endif; ?>
    </div>

    <div class="card">
        <strong>Evaluation:</strong>
        <span class="small">
            <?=rds_h($selectedYear)?> <?=rds_h($selectedSegment)?>,
            through <?=$throughRace>0?'R'.str_pad((string)$throughRace,2,'0',STR_PAD_LEFT):'none'?>,
            trailing pair:
            <?=empty($trailing)
                ? 'none'
                : implode(' / ', array_map(function($n){return 'R'.str_pad((string)$n,2,'0',STR_PAD_LEFT);}, $trailing))?>
        </span>

        <table>
            <thead>
            <tr>
                <th>Team</th>
                <th>Active Pick</th>
                <th>A</th><th>B</th><th>C</th><th>D</th>
                <th>Status</th>
                <th>Qualifier(s)</th>
                <th>Effective</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($results)): ?>
                <tr><td colspan="9">No evaluable teams.</td></tr>
            <?php else: ?>
                <?php foreach ($results as $res): ?>
                    <?php
                    $base = isset($res['base_pick_row']) && is_array($res['base_pick_row'])
                        ? $res['base_pick_row']
                        : [];
                    $status = (string)($res['status'] ?? '');
                    $qualifiers = isset($res['qualifiers']) && is_array($res['qualifiers'])
                        ? $res['qualifiers']
                        : [];
                    $qText = [];
                    $effectiveText = [];

                    foreach ($qualifiers as $q) {
                        $zr = isset($q['zero_races']) && is_array($q['zero_races'])
                            ? $q['zero_races']
                            : [];
                        $zrText = implode('/', array_map(function($n){
                            return 'R' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
                        }, $zr));

                        $qText[] = (string)($q['slot'] ?? '')
                            . ': '
                            . (string)($q['driver'] ?? '')
                            . ($zrText !== '' ? ' [' . $zrText . ']' : '');

                        $er = (int)($q['effective_race'] ?? 0);
                        if ($er > 0) {
                            $effectiveText[] = 'R' . str_pad((string)$er, 2, '0', STR_PAD_LEFT);
                        }
                    }
                    ?>
                    <tr>
                        <td class="nowrap"><?=rds_h($res['teamName'] ?? '')?></td>
                        <td><?=rds_h($res['active_pick_type'] ?? '')?></td>
                        <td><?=rds_h($base['driverA'] ?? '')?></td>
                        <td><?=rds_h($base['driverB'] ?? '')?></td>
                        <td><?=rds_h($base['driverC'] ?? '')?></td>
                        <td><?=rds_h($base['driverD'] ?? '')?></td>
                        <td class="<?=rds_h(rds_status_class($status))?>"><?=rds_h($status)?></td>
                        <td><?=rds_h(implode(' | ', $qText))?></td>
                        <td><?=rds_h(implode(' / ', array_unique($effectiveText)))?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($fixtureResult['meta'])): ?>
        <div class="card">
            <strong>Fixture audit</strong>
            <table>
                <thead>
                <tr><th>Race</th><th>Source snapshot used</th><th>Simulation fixture</th><th>Override</th></tr>
                </thead>
                <tbody>
                <?php foreach ($fixtureResult['meta'] as $rn => $meta): ?>
                    <tr>
                        <td>R<?=str_pad((string)$rn,2,'0',STR_PAD_LEFT)?></td>
                        <td class="small"><?=rds_h(basename((string)$meta['source']))?></td>
                        <td class="small"><?=rds_h(str_replace(__DIR__ . '/', '', (string)$meta['fixture']))?></td>
                        <td><?=!empty($meta['override_applied'])?'YES':'—'?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>

SIM;

// ---------------- PRE-PREFLIGHT ----------------

ri_check($checks, 'Host is TESTPHP8', $host === MRL_RD_REQUIRED_HOST, $host !== '' ? $host : '(unknown)');
ri_check($checks, 'Document root available', $root !== '' && is_dir($root), $root);
ri_check($checks, 'race_results folder exists', is_dir($raceDir), $raceDir);
ri_check($checks, 'PHP is 7.3 compatible target', PHP_VERSION_ID >= 70300, PHP_VERSION);
ri_check($checks, 'Current RD helper exists', is_file($helperPath), $helperPath);

if ($host !== MRL_RD_REQUIRED_HOST) {
    $errors[] = 'REFUSED: installer is TestPHP8-only.';
}

if ($root === '' || !is_dir($root)) {
    $errors[] = 'Document root unavailable.';
}

if (!is_dir($raceDir)) {
    $errors[] = 'race_results folder unavailable.';
}

if (PHP_VERSION_ID < 70300) {
    $errors[] = 'PHP 7.3 or newer is required.';
}

if (!is_file($helperPath)) {
    $errors[] = 'Current RD helper is missing.';
}

$currentHelper = is_file($helperPath) ? (string)@file_get_contents($helperPath) : '';
$currentSim = is_file($simPath) ? (string)@file_get_contents($simPath) : '';

$currentHelperIsV004 = strpos($currentHelper, 'VERSION: v004') !== false;
$currentHelperIsV005 = strpos($currentHelper, 'VERSION: v005') !== false;

ri_check(
    $checks,
    'RD helper expected source version',
    $currentHelperIsV004 || $currentHelperIsV005,
    $currentHelperIsV005 ? 'VERSION: v005 (already installed)' : 'VERSION: v004'
);

if (!$currentHelperIsV004 && !$currentHelperIsV005) {
    $errors[] = 'REFUSED: RD helper is not expected v004/v005 source.';
}

if ($currentHelperIsV004) {
    $oldMarkers = [
        'SELECT historyID',
        'function mrl_rd_detect_slot_eligibility',
        'function mrl_rd_team_used_rd_this_year',
        "require_once __DIR__ . '/race_schedule_helper.php';",
    ];

    foreach ($oldMarkers as $marker) {
        $found = strpos($currentHelper, $marker) !== false;

        ri_check(
            $checks,
            'Expected v004 marker',
            $found,
            $marker
        );

        if (!$found) {
            $errors[] = 'REFUSED: current v004 helper does not match expected source marker: ' . $marker;
        }
    }
}

$simSafe = ($currentSim === '' || strpos($currentSim, 'VERSION: v001') !== false);

ri_check(
    $checks,
    'Simulator destination safe',
    $simSafe,
    $currentSim === '' ? 'new file' : 'VERSION: v001 already present'
);

if (!$simSafe) {
    $errors[] = 'REFUSED: admin_rd_simulation.php exists but is not recognized as v001.';
}

// ---------------- PACKAGE PREFLIGHT ----------------

$packageChecks = [
    ['Helper package is v005', strpos($helperContent, 'VERSION: v005') !== false],
    ['historyID bug removed', strpos($helperContent, 'SELECT historyID') === false],
    ['History query uses pickID', strpos($helperContent, 'FROM user_picks_history') !== false && strpos($helperContent, 'SELECT pickID') !== false],
    ['Trailing-pair rule packaged', strpos($helperContent, 'TRAILING_PAIR_NOT_BOTH_ZERO') !== false],
    ['Effective LP support packaged', strpos($helperContent, "pick_type IN ('SEG','ADJ','LP')") !== false],
    ['Multiple-user-choice state packaged', strpos($helperContent, 'MULTIPLE_RD_AVAILABLE') !== false],
    ['Simulator TESTPHP8 guard packaged', strpos($simContent, "testphp8.manliusracingleague.com") !== false],
    ['Simulator isolated fixture root packaged', strpos($simContent, "/_rd_simulation/current/") !== false],
    ['Simulator mirrors monitor snapshot selection', strpos($simContent, 'Deliberately mirrors race_results_monitor.php') !== false],
];

foreach ($packageChecks as $pc) {
    ri_check($checks, $pc[0], (bool)$pc[1], $pc[1] ? 'PASS' : 'missing');
    if (!$pc[1]) {
        $errors[] = 'Internal package preflight failed: ' . $pc[0];
    }
}

// ---------------- INSTALL ----------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['install']) &&
    empty($errors)
) {
    $helperAlready = $currentHelperIsV005;
    $simAlready = (strpos($currentSim, 'VERSION: v001') !== false);

    if ($helperAlready && $simAlready) {
        $installed = true;
    } else {
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            $errors[] = 'Could not create backup folder: ' . $backupDir;
        }

        if (empty($errors)) {
            foreach ([$helperPath, $simPath] as $path) {
                if (!ri_backup_file($path, $root, $backupDir)) {
                    $errors[] = 'Backup failed: ' . $path;
                    break;
                }
            }
        }

        if (empty($errors)) {
            $originalHelper = $currentHelper;
            $originalSim = $currentSim;
            $simExisted = is_file($simPath);

            $writeHelper = $helperAlready ? true : ri_atomic_write($helperPath, $helperContent);
            $writeSim = $writeHelper && ($simAlready ? true : ri_atomic_write($simPath, $simContent));

            if (!$writeHelper || !$writeSim) {
                if (!$helperAlready && $originalHelper !== '') {
                    @file_put_contents($helperPath, $originalHelper, LOCK_EX);
                }

                if (!$simAlready) {
                    if ($simExisted) {
                        @file_put_contents($simPath, $originalSim, LOCK_EX);
                    } else {
                        @unlink($simPath);
                    }
                }

                $errors[] = 'Install write failed; rollback attempted.';
            }
        }
    }

    if (empty($errors)) {
        $afterHelper = (string)@file_get_contents($helperPath);
        $afterSim = (string)@file_get_contents($simPath);

        $postflight = [
            ['RD helper v005 installed', strpos($afterHelper, 'VERSION: v005') !== false],
            ['historyID bug absent', strpos($afterHelper, 'SELECT historyID') === false],
            ['Trailing-pair engine installed', strpos($afterHelper, 'TRAILING_PAIR_NOT_BOTH_ZERO') !== false],
            ['Effective LP RD support installed', strpos($afterHelper, "pick_type IN ('SEG','ADJ','LP')") !== false],
            ['Multiple user-choice state installed', strpos($afterHelper, 'MULTIPLE_RD_AVAILABLE') !== false],
            ['RD simulator v001 installed', strpos($afterSim, 'VERSION: v001') !== false],
            ['Simulator isolation marker installed', strpos($afterSim, '_rd_simulation/current/') !== false],
        ];

        foreach ($postflight as $pf) {
            if (!$pf[1]) {
                $errors[] = 'Postflight failed: ' . $pf[0];
            }
        }

        if (empty($errors)) {
            $installed = true;
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MRL RD Phase 2 Installer</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#111;color:#eee;font:14px/1.4 Arial,Helvetica,sans-serif}
.wrap{max-width:1250px;margin:0 auto;padding:15px}
.banner{background:#3d3212;border:1px solid #9b7200;border-radius:12px;padding:13px 16px}
.banner h1{margin:0;color:#fff1cf;font-size:23px}
.sub{font-size:12px;color:#d8c78b;margin-top:4px}
.card{background:#1d1d1d;border:1px solid #444;border-radius:10px;padding:12px 14px;margin-top:11px}
h2{margin:0 0 8px;color:#ffd08a;font-size:18px}
table{width:100%;border-collapse:collapse}
td{padding:6px 7px;border-bottom:1px solid #333}
.ok{color:#5cf09a;font-weight:700}
.bad{color:#ff7d7d;font-weight:700}
code{color:#f4d98b}
button{background:#443419;color:#ffd08a;border:1px solid #b48636;border-radius:7px;padding:9px 14px;font-weight:700;cursor:pointer}
.success{background:#143b2b;border-color:#2c7754}
.note{color:#bbb;font-size:12px;line-height:1.45}
a{color:#8fc7ff}
</style>
</head>
<body>
<div class="wrap">

<div class="banner">
    <h1>MRL RD Phase 2 / Simulation Installer v001</h1>
    <div class="sub">TESTPHP8 ONLY • generated 8/20/2026 10:27:00 pm • PHP 7.3 compatible • DB changes: NONE</div>
</div>

<div class="card">
    <h2>Preflight</h2>
    <table>
        <?php foreach ($checks as $c): ?>
        <tr>
            <td style="width:42%"><?=ri_h($c['name'])?></td>
            <td style="width:8%" class="<?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'PASS':'FAIL'?></td>
            <td><?=ri_h($c['detail'])?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h2>Safety / Scope</h2>
    <div>Full website + DB backup reference: <code>20260821013354</code></div>
    <div>Installer backup folder: <code><?=ri_h($backupDir)?></code></div>
    <div class="note" style="margin-top:7px">
        This phase changes only the shared RD helper and adds the TESTPHP8 simulation page.
        It does not change the database, normal snapshots, team.php, submit-team-picks.php,
        standings, or Live MRL.
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="card">
    <h2 class="bad">STOPPED SAFELY</h2>
    <?php foreach ($errors as $e): ?><div class="bad">• <?=ri_h($e)?></div><?php endforeach; ?>
</div>
<?php elseif (!$installed): ?>
<div class="card">
    <h2>Ready to Install</h2>
    <div style="margin-bottom:9px">
        <code>race_results_rd_helper.php v004 → v005</code><br>
        <code>admin_rd_simulation.php → v001</code>
    </div>
    <form method="post">
        <button type="submit" name="install" value="1">INSTALL RD PHASE 2 / SIMULATION</button>
    </form>
</div>
<?php endif; ?>

<?php if ($installed): ?>
<div class="card success">
    <h2 class="ok">INSTALL COMPLETE</h2>
    <?php if (is_dir($backupDir)): ?>
        <div><strong>Backup folder:</strong><br><code><?=ri_h($backupDir)?></code></div>
    <?php endif; ?>

    <table style="margin-top:9px">
        <?php foreach ($postflight as $pf): ?>
        <tr>
            <td><?=ri_h($pf[0])?></td>
            <td class="<?=$pf[1]?'ok':'bad'?>"><?=$pf[1]?'PASS':'FAIL'?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div style="margin-top:10px">
        <a href="/race_results/admin_rd_simulation.php">Open RD Simulation</a>
    </div>

    <div class="note" style="margin-top:8px">
        First test: 2026 / S1 / through R05 / no override. We expect the real saved
        R04-R05 Alex Bowman zero-point case to be visible for the teams that had him.
        Do not sync to GitHub until we are satisfied with the simulation results.
    </div>
</div>
<?php endif; ?>

</div>
</body>
</html>
