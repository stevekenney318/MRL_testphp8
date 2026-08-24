<?php
declare(strict_types=1);

/**
 * race_schedule_helper.php
 *
 * VERSION: v003
 * LAST MODIFIED: 8/18/2026 4:13:00 am
 *
 * DESCRIPTION:
 * Shared MRL race-schedule helper for LP and RD timing.
 *
 * Canonical race schedule source:
 * /race_results/_race_results_schedule.json
 *
 * Segment boundaries remain DB-driven from segment_race_ranges.
 *
 * CHANGELOG:
 *
 * v003 (8/18/2026 4:13:00 am)
 * - CHANGE: Replaced legacy /race_results/{year}/_schedule.json dependency with
 *   /race_results/_race_results_schedule.json.
 * - CHANGE: Uses mrl_points_races as the preferred canonical points-race list.
 * - NEW: Resolves segment boundaries from segment_race_ranges.
 * - NEW: Adds race-by-number and next-race-in-segment helpers shared by LP/RD.
 * - CHANGE: Race timing now uses canonical start_at/start_ts fields.
 * - CHANGE: Preserves existing LP function names/signatures for compatibility.
 *
 * v002 (3/31/2026)
 * - LP effective race now requires same-segment match.
 * - Returns null when no future points race remains in the active segment.
 * - Adds optional helper for fetching points races by segment.
 *
 * v001 (3/31/2026)
 * - Initial helper file.
 * - Loads canonical schedule JSON for a given year.
 * - Returns the first points race whose scheduled start time has not yet passed.
 * - Intended for LP logic only at this stage.
 */

const MRL_SCHEDULE_HELPER_TIMEZONE = 'America/New_York';

if (!function_exists('mrl_schedule_helper_timezone')) {
    function mrl_schedule_helper_timezone(): DateTimeZone
    {
        return new DateTimeZone(MRL_SCHEDULE_HELPER_TIMEZONE);
    }
}

if (!function_exists('mrl_schedule_helper_file_path')) {
    function mrl_schedule_helper_file_path(int $year): string
    {
        return __DIR__ . '/_race_results_schedule.json';
    }
}

if (!function_exists('mrl_schedule_helper_load')) {
    function mrl_schedule_helper_load(int $year): array
    {
        $file = mrl_schedule_helper_file_path($year);

        if (!is_file($file)) {
            throw new RuntimeException('Canonical race schedule file not found: ' . $file);
        }

        $json = file_get_contents($file);
        if ($json === false || trim($json) === '') {
            throw new RuntimeException('Canonical race schedule file could not be read or was empty: ' . $file);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Canonical race schedule JSON is invalid: ' . $file);
        }

        if (isset($data['year']) && (int)$data['year'] !== $year) {
            throw new RuntimeException(
                'Canonical race schedule year mismatch. Expected ' . $year . ', found ' . (string)$data['year'] . '.'
            );
        }

        $hasMrlPoints = isset($data['mrl_points_races']) && is_array($data['mrl_points_races']);
        $hasRaces = isset($data['races']) && is_array($data['races']);

        if (!$hasMrlPoints && !$hasRaces) {
            throw new RuntimeException('Canonical race schedule JSON has no race list: ' . $file);
        }

        return $data;
    }
}

if (!function_exists('mrl_schedule_helper_race_number')) {
    function mrl_schedule_helper_race_number(array $race): int
    {
        $number = (int)($race['mrl_race_number'] ?? 0);
        if ($number <= 0) {
            $number = (int)($race['race_number'] ?? 0);
        }
        if ($number <= 0) {
            $number = (int)($race['schedule_sequence'] ?? 0);
        }

        return $number;
    }
}

if (!function_exists('mrl_schedule_helper_race_datetime')) {
    function mrl_schedule_helper_race_datetime(array $race): DateTimeImmutable
    {
        $startAt = trim((string)($race['start_at'] ?? ''));

        if ($startAt !== '') {
            $dt = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $startAt,
                mrl_schedule_helper_timezone()
            );

            if ($dt instanceof DateTimeImmutable) {
                return $dt;
            }
        }

        $startTs = (int)($race['start_ts'] ?? 0);
        if ($startTs > 0) {
            return (new DateTimeImmutable('@' . $startTs))->setTimezone(mrl_schedule_helper_timezone());
        }

        throw new RuntimeException(
            'Race entry missing valid canonical start_at/start_ts for R'
            . str_pad((string)mrl_schedule_helper_race_number($race), 2, '0', STR_PAD_LEFT)
            . '.'
        );
    }
}

if (!function_exists('mrl_schedule_helper_points_races')) {
    function mrl_schedule_helper_points_races(int $year): array
    {
        $data = mrl_schedule_helper_load($year);

        if (isset($data['mrl_points_races']) && is_array($data['mrl_points_races'])) {
            $source = $data['mrl_points_races'];
        } else {
            $source = $data['races'];
        }

        $pointsRaces = [];

        foreach ($source as $race) {
            if (!is_array($race)) {
                continue;
            }

            if (!empty($race['is_exhibition'])) {
                continue;
            }

            if (array_key_exists('mrl_points_eligible', $race) && $race['mrl_points_eligible'] === false) {
                continue;
            }

            if ((int)($race['year'] ?? $year) !== $year) {
                continue;
            }

            $raceNumber = mrl_schedule_helper_race_number($race);
            if ($raceNumber <= 0) {
                continue;
            }

            $race['race_number'] = $raceNumber;
            if (!isset($race['mrl_race_number']) || (int)$race['mrl_race_number'] <= 0) {
                $race['mrl_race_number'] = $raceNumber;
            }

            $pointsRaces[] = $race;
        }

        usort($pointsRaces, function (array $a, array $b): int {
            return mrl_schedule_helper_race_number($a) <=> mrl_schedule_helper_race_number($b);
        });

        return $pointsRaces;
    }
}

if (!function_exists('mrl_schedule_helper_segment_bounds')) {
    function mrl_schedule_helper_segment_bounds(int $year, string $segment): array
    {
        $segment = strtoupper(trim($segment));
        if ($segment === '') {
            throw new RuntimeException('Segment is required for schedule lookup.');
        }

        global $dbo, $dbconnect;

        if (isset($dbo) && $dbo instanceof PDO) {
            $sql = "
                SELECT startRace, endRace
                FROM segment_race_ranges
                WHERE raceYear = :raceYear
                  AND segment = :segment
                LIMIT 1
            ";
            $stmt = $dbo->prepare($sql);
            $stmt->execute([
                ':raceYear' => $year,
                ':segment' => $segment,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (is_array($row)) {
                $start = (int)($row['startRace'] ?? 0);
                $end = (int)($row['endRace'] ?? 0);

                if ($start > 0 && $end >= $start) {
                    return ['start' => $start, 'end' => $end];
                }
            }
        }

        if (isset($dbconnect) && $dbconnect instanceof mysqli) {
            $sql = "
                SELECT startRace, endRace
                FROM segment_race_ranges
                WHERE raceYear = ?
                  AND segment = ?
                LIMIT 1
            ";
            $stmt = mysqli_prepare($dbconnect, $sql);

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'is', $year, $segment);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $startRace, $endRace);

                if (mysqli_stmt_fetch($stmt)) {
                    mysqli_stmt_close($stmt);

                    $start = (int)$startRace;
                    $end = (int)$endRace;
                    if ($start > 0 && $end >= $start) {
                        return ['start' => $start, 'end' => $end];
                    }
                } else {
                    mysqli_stmt_close($stmt);
                }
            }
        }

        throw new RuntimeException(
            'No segment_race_ranges row available for ' . $year . ' ' . $segment . '.'
        );
    }
}

if (!function_exists('mrl_schedule_helper_points_races_by_segment')) {
    function mrl_schedule_helper_points_races_by_segment(int $year, string $segment): array
    {
        $bounds = mrl_schedule_helper_segment_bounds($year, $segment);
        $all = mrl_schedule_helper_points_races($year);
        $filtered = [];

        foreach ($all as $race) {
            $raceNumber = mrl_schedule_helper_race_number($race);

            if ($raceNumber < (int)$bounds['start'] || $raceNumber > (int)$bounds['end']) {
                continue;
            }

            $race['segment'] = strtoupper(trim($segment));
            $filtered[] = $race;
        }

        return $filtered;
    }
}

if (!function_exists('mrl_schedule_helper_race_by_number')) {
    function mrl_schedule_helper_race_by_number(int $year, int $raceNumber): ?array
    {
        foreach (mrl_schedule_helper_points_races($year) as $race) {
            if (mrl_schedule_helper_race_number($race) === $raceNumber) {
                return $race;
            }
        }

        return null;
    }
}

if (!function_exists('mrl_schedule_helper_next_race_in_segment')) {
    function mrl_schedule_helper_next_race_in_segment(
        int $year,
        string $segment,
        int $afterRaceNumber
    ): ?array {
        foreach (mrl_schedule_helper_points_races_by_segment($year, $segment) as $race) {
            if (mrl_schedule_helper_race_number($race) > $afterRaceNumber) {
                return $race;
            }
        }

        return null;
    }
}

if (!function_exists('mrl_get_effective_race_for_lp')) {
    /**
     * Returns the first canonical MRL points race in the SAME DB-defined segment
     * whose scheduled start time has not yet passed.
     *
     * This is the LP effective race. Earlier races in the segment are already
     * missed and score 0. If no future race remains in the segment, null is returned.
     */
    function mrl_get_effective_race_for_lp(
        int $year,
        string $segment,
        ?DateTimeImmutable $now = null
    ): ?array {
        $now = $now ?? new DateTimeImmutable('now', mrl_schedule_helper_timezone());

        foreach (mrl_schedule_helper_points_races_by_segment($year, $segment) as $race) {
            $raceStart = mrl_schedule_helper_race_datetime($race);

            if ($raceStart >= $now) {
                $raceNumber = mrl_schedule_helper_race_number($race);

                return [
                    'year' => $year,
                    'race_number' => $raceNumber,
                    'segment' => strtoupper(trim($segment)),
                    'mrl_race_code' => (string)($race['mrl_race_code'] ?? ('R' . str_pad((string)$raceNumber, 2, '0', STR_PAD_LEFT))),
                    'mrl_race_name' => (string)($race['mrl_race_name'] ?? $race['race_name'] ?? ''),
                    'track_name' => (string)($race['track_name'] ?? ''),
                    'date' => (string)($race['mrl_race_date'] ?? ''),
                    'time_et' => (string)($race['time_text'] ?? ''),
                    'datetime_et' => $raceStart->format('Y-m-d H:i:s'),
                    'start_at' => (string)($race['start_at'] ?? $raceStart->format('Y-m-d H:i:s')),
                    'start_ts' => $raceStart->getTimestamp(),
                    'race_start_dt' => $raceStart,
                ];
            }
        }

        return null;
    }
}

if (!function_exists('mrl_is_race_missed_for_lp')) {
    function mrl_is_race_missed_for_lp(
        int $year,
        int $raceNumber,
        ?DateTimeImmutable $now = null
    ): bool {
        $now = $now ?? new DateTimeImmutable('now', mrl_schedule_helper_timezone());
        $race = mrl_schedule_helper_race_by_number($year, $raceNumber);

        if (!is_array($race)) {
            throw new RuntimeException('Race number not found in canonical schedule: ' . $raceNumber);
        }

        return mrl_schedule_helper_race_datetime($race) < $now;
    }
}