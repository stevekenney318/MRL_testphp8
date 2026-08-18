<?php
declare(strict_types=1);

/**
 * race_results_rd_helper.php
 *
 * VERSION: v003
 * LAST MODIFIED: 4/3/2026 12:28:00 am
 *
 * DESCRIPTION:
 * Helper functions for detecting Replacement Driver (RD) eligibility.
 *
 * CURRENT RD RULES:
 * - A driver becomes RD-eligible after 2 consecutive races with 0 net points.
 * - Detection is segment-based.
 * - Detection is slot-based (A / B / C / D).
 * - Effective race is the next race in the same segment.
 * - If more than one driver qualifies at the same time, auto-selection is blocked.
 * - Only 1 RD is allowed per team per season (enforcement helper included).
 *
 * NOTES:
 * - This file only detects eligibility and summarizes results.
 * - It does not insert RD rows.
 * - It does not modify picks.
 * - It is intended as the first RD groundwork step.
 *
 * CHANGELOG:
 *
 * v003 (4/3/2026)
 * - Added graceful segment-config lookup helper.
 * - Added NO_SEGMENT_CONFIG status support for friendlier handling when segment_race_ranges is missing.
 * - Preserved DB-driven segment bounds for configured years/segments.
 *
 * v002 (4/2/2026)
 * - Replaced hardcoded segment bounds with DB-driven lookups from segment_race_ranges.
 * - Added race-year-aware segment bounds helper.
 * - Added DB-driven segment race list helper.
 * - Added DB-driven next-race-in-segment helper.
 *
 * v001 (4/2/2026)
 * - Initial RD helper file.
 * - Added segment race ordering helper.
 * - Added team/segment pick row loader.
 * - Added per-slot 2-consecutive-zero detection.
 * - Added helper to detect whether a team already used RD in the season.
 * - Added summary function for team + year + segment RD eligibility.
 */

const MRL_RD_TIMEZONE = 'America/New_York';

if (!function_exists('mrl_rd_try_get_segment_bounds')) {
    function mrl_rd_try_get_segment_bounds(PDO $dbo, int $raceYear, string $segment): ?array
    {
        $sql = "
            SELECT startRace, endRace
            FROM segment_race_ranges
            WHERE raceYear = :raceYear
              AND segment = :segment
            LIMIT 1
        ";

        $stmt = $dbo->prepare($sql);
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
        $bounds = mrl_rd_get_segment_bounds($dbo, $raceYear, $segment);
        $nextRace = $raceNumber + 1;

        if ($nextRace > (int)$bounds['end']) {
            return null;
        }

        return $nextRace;
    }
}

if (!function_exists('mrl_rd_team_segment_pick_row')) {
    function mrl_rd_team_segment_pick_row(PDO $dbo, string $raceYear, string $segment, string $teamName): ?array
    {
        $sql = "
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
            ORDER BY effective_race ASC, entryDate ASC, pickID ASC
            LIMIT 1
        ";

        $stmt = $dbo->prepare($sql);
        $stmt->execute([
            ':raceYear' => $raceYear,
            ':segment' => $segment,
            ':teamName' => $teamName,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('mrl_rd_team_used_rd_this_year')) {
    function mrl_rd_team_used_rd_this_year(PDO $dbo, string $raceYear, string $teamName): bool
    {
        $sql = "
            SELECT pickID
            FROM user_picks
            WHERE raceYear = :raceYear
              AND teamName = :teamName
              AND pick_type = 'RD'
            LIMIT 1
        ";

        $stmt = $dbo->prepare($sql);
        $stmt->execute([
            ':raceYear' => $raceYear,
            ':teamName' => $teamName,
        ]);

        if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
            return true;
        }

        $sqlHistory = "
            SELECT historyID
            FROM user_picks_history
            WHERE raceYear = :raceYear
              AND teamName = :teamName
              AND pick_type = 'RD'
            LIMIT 1
        ";

        $stmtHistory = $dbo->prepare($sqlHistory);
        $stmtHistory->execute([
            ':raceYear' => $raceYear,
            ':teamName' => $teamName,
        ]);

        return ($stmtHistory->fetch(PDO::FETCH_ASSOC) !== false);
    }
}

if (!function_exists('mrl_rd_driver_points_for_segment')) {
    function mrl_rd_driver_points_for_segment(PDO $dbo, int $raceYear, string $segment, string $driverName, array $raceDriverPoints): array
    {
        $results = [];
        $driverName = trim($driverName);

        foreach (mrl_rd_segment_race_numbers($dbo, $raceYear, $segment) as $raceNumber) {
            $net = 0;

            if ($driverName !== '' && isset($raceDriverPoints[$raceNumber]) && is_array($raceDriverPoints[$raceNumber])) {
                if (array_key_exists($driverName, $raceDriverPoints[$raceNumber])) {
                    $net = (int)$raceDriverPoints[$raceNumber][$driverName];
                }
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

if (!function_exists('mrl_rd_detect_slot_eligibility')) {
    function mrl_rd_detect_slot_eligibility(PDO $dbo, int $raceYear, string $slot, string $segment, string $driverName, array $raceDriverPoints): array
    {
        $pointsRows = mrl_rd_driver_points_for_segment($dbo, $raceYear, $segment, $driverName, $raceDriverPoints);
        $zeroStreak = 0;
        $zeroRaces = [];

        foreach ($pointsRows as $row) {
            $raceNumber = (int)$row['race_number'];
            $net = (int)$row['net'];

            if ($driverName === '') {
                return [
                    'qualified' => false,
                    'slot' => $slot,
                    'driver' => '',
                    'zero_races' => [],
                    'effective_race' => null,
                ];
            }

            if ($net === 0) {
                $zeroStreak++;
                $zeroRaces[] = $raceNumber;

                if ($zeroStreak >= 2) {
                    $lastTwo = array_slice($zeroRaces, -2);
                    $effectiveRace = mrl_rd_next_race_in_segment($dbo, $raceYear, $segment, $raceNumber);

                    return [
                        'qualified' => ($effectiveRace !== null),
                        'slot' => $slot,
                        'driver' => $driverName,
                        'zero_races' => $lastTwo,
                        'effective_race' => $effectiveRace,
                    ];
                }
            } else {
                $zeroStreak = 0;
                $zeroRaces = [];
            }
        }

        return [
            'qualified' => false,
            'slot' => $slot,
            'driver' => $driverName,
            'zero_races' => [],
            'effective_race' => null,
        ];
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
        $bounds = mrl_rd_try_get_segment_bounds($dbo, $raceYearInt, $segment);

        if ($bounds === null) {
            return [
                'teamName' => $teamName,
                'raceYear' => $raceYear,
                'segment' => $segment,
                'has_base_row' => false,
                'rd_already_used_this_year' => false,
                'qualifiers' => [],
                'qualifier_count' => 0,
                'status' => 'NO_SEGMENT_CONFIG',
                'auto_select_allowed' => false,
            ];
        }

        $teamRow = mrl_rd_team_segment_pick_row($dbo, $raceYear, $segment, $teamName);

        if (!is_array($teamRow)) {
            return [
                'teamName' => $teamName,
                'raceYear' => $raceYear,
                'segment' => $segment,
                'has_base_row' => false,
                'rd_already_used_this_year' => false,
                'qualifiers' => [],
                'qualifier_count' => 0,
                'status' => 'NO_BASE_ROW',
                'auto_select_allowed' => false,
            ];
        }

        $rdAlreadyUsed = mrl_rd_team_used_rd_this_year($dbo, $raceYear, $teamName);

        $slotResults = [];
        $slotResults[] = mrl_rd_detect_slot_eligibility($dbo, $raceYearInt, 'A', $segment, (string)($teamRow['driverA'] ?? ''), $raceDriverPoints);
        $slotResults[] = mrl_rd_detect_slot_eligibility($dbo, $raceYearInt, 'B', $segment, (string)($teamRow['driverB'] ?? ''), $raceDriverPoints);
        $slotResults[] = mrl_rd_detect_slot_eligibility($dbo, $raceYearInt, 'C', $segment, (string)($teamRow['driverC'] ?? ''), $raceDriverPoints);
        $slotResults[] = mrl_rd_detect_slot_eligibility($dbo, $raceYearInt, 'D', $segment, (string)($teamRow['driverD'] ?? ''), $raceDriverPoints);

        $qualifiers = [];
        foreach ($slotResults as $slotResult) {
            if (!empty($slotResult['qualified'])) {
                $qualifiers[] = $slotResult;
            }
        }

        $qualifierCount = count($qualifiers);

        $status = 'NO_RD';
        $autoSelectAllowed = false;

        if ($rdAlreadyUsed) {
            $status = 'RD_ALREADY_USED';
        } elseif ($qualifierCount === 1) {
            $status = 'RD_AVAILABLE';
            $autoSelectAllowed = true;
        } elseif ($qualifierCount > 1) {
            $status = 'MANUAL_SELECTION_REQUIRED';
        }

        return [
            'teamName' => $teamName,
            'raceYear' => $raceYear,
            'segment' => $segment,
            'has_base_row' => true,
            'rd_already_used_this_year' => $rdAlreadyUsed,
            'base_pick_row' => $teamRow,
            'qualifiers' => $qualifiers,
            'qualifier_count' => $qualifierCount,
            'status' => $status,
            'auto_select_allowed' => $autoSelectAllowed,
        ];
    }
}
