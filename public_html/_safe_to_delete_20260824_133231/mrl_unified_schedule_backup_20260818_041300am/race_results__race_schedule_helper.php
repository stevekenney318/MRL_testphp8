<?php
declare(strict_types=1);

/**
 * race_schedule_helper.php
 *
 * VERSION: v002
 * LAST MODIFIED: 3/31/2026 10:20:00 pm
 *
 * DESCRIPTION:
 * Helper functions for reading canonical yearly schedule data from
 * /race_results/{year}/_schedule.json and determining LP effective race timing.
 *
 * CHANGELOG:
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
        return __DIR__ . '/' . $year . '/_schedule.json';
    }
}

if (!function_exists('mrl_schedule_helper_load')) {
    function mrl_schedule_helper_load(int $year): array
    {
        $file = mrl_schedule_helper_file_path($year);

        if (!is_file($file)) {
            throw new RuntimeException('Schedule file not found: ' . $file);
        }

        $json = file_get_contents($file);
        if ($json === false || trim($json) === '') {
            throw new RuntimeException('Schedule file could not be read or was empty: ' . $file);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Schedule JSON is invalid: ' . $file);
        }

        if (!isset($data['races']) || !is_array($data['races'])) {
            throw new RuntimeException('Schedule JSON missing races array: ' . $file);
        }

        return $data;
    }
}

if (!function_exists('mrl_schedule_helper_race_datetime')) {
    function mrl_schedule_helper_race_datetime(array $race): DateTimeImmutable
    {
        if (!isset($race['datetime_et']) || trim((string)$race['datetime_et']) === '') {
            throw new RuntimeException('Race entry missing datetime_et.');
        }

        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string)$race['datetime_et'],
            mrl_schedule_helper_timezone()
        );

        if (!($dt instanceof DateTimeImmutable)) {
            throw new RuntimeException('Invalid race datetime_et: ' . (string)$race['datetime_et']);
        }

        return $dt;
    }
}

if (!function_exists('mrl_schedule_helper_points_races')) {
    function mrl_schedule_helper_points_races(int $year): array
    {
        $data = mrl_schedule_helper_load($year);
        $races = $data['races'];

        $pointsRaces = array();

        foreach ($races as $race) {
            if (!is_array($race)) {
                continue;
            }

            if (!isset($race['race_number'])) {
                continue;
            }

            $pointsRaces[] = $race;
        }

        usort($pointsRaces, function (array $a, array $b): int {
            return ((int)$a['race_number']) <=> ((int)$b['race_number']);
        });

        return $pointsRaces;
    }
}

if (!function_exists('mrl_schedule_helper_points_races_by_segment')) {
    function mrl_schedule_helper_points_races_by_segment(int $year, string $segment): array
    {
        $segment = trim($segment);
        $all = mrl_schedule_helper_points_races($year);
        $filtered = array();

        foreach ($all as $race) {
            if (!isset($race['segment'])) {
                continue;
            }

            if ((string)$race['segment'] !== $segment) {
                continue;
            }

            $filtered[] = $race;
        }

        return $filtered;
    }
}

if (!function_exists('mrl_get_effective_race_for_lp')) {
    /**
     * Returns the first points race in the SAME segment whose scheduled start time
     * has not yet passed.
     *
     * This is the LP effective race. Any earlier race in that segment is already
     * missed and scores 0. If no future race remains in the active segment, null is returned.
     */
    function mrl_get_effective_race_for_lp(int $year, string $segment, ?DateTimeImmutable $now = null): ?array
    {
        $now = $now ?? new DateTimeImmutable('now', mrl_schedule_helper_timezone());

        $races = mrl_schedule_helper_points_races_by_segment($year, $segment);

        foreach ($races as $race) {
            $raceStart = mrl_schedule_helper_race_datetime($race);

            if ($raceStart >= $now) {
                return array(
                    'year' => $year,
                    'race_number' => (int)$race['race_number'],
                    'segment' => isset($race['segment']) ? (string)$race['segment'] : '',
                    'mrl_race_name' => isset($race['mrl_race_name']) ? (string)$race['mrl_race_name'] : '',
                    'track_name' => isset($race['track_name']) ? (string)$race['track_name'] : '',
                    'date' => isset($race['date']) ? (string)$race['date'] : '',
                    'time_et' => isset($race['time_et']) ? (string)$race['time_et'] : '',
                    'datetime_et' => isset($race['datetime_et']) ? (string)$race['datetime_et'] : '',
                    'race_start_dt' => $raceStart,
                );
            }
        }

        return null;
    }
}

if (!function_exists('mrl_is_race_missed_for_lp')) {
    /**
     * Returns true if the specified race's scheduled start time has already passed.
     */
    function mrl_is_race_missed_for_lp(int $year, int $raceNumber, ?DateTimeImmutable $now = null): bool
    {
        $now = $now ?? new DateTimeImmutable('now', mrl_schedule_helper_timezone());

        $races = mrl_schedule_helper_points_races($year);

        foreach ($races as $race) {
            if ((int)$race['race_number'] !== $raceNumber) {
                continue;
            }

            $raceStart = mrl_schedule_helper_race_datetime($race);
            return ($raceStart < $now);
        }

        throw new RuntimeException('Race number not found in schedule: ' . $raceNumber);
    }
}
