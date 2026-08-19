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