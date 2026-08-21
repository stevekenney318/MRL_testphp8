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
